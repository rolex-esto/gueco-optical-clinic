<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Appointments';
$breadcrumb = ['Saleslady', 'Appointment Queue & Calendar'];
$activeNav  = 'appointments';
$db = getDB();
ensureAppointmentsSchema($db);
$today = date('Y-m-d');

// Handle status updates / notes / confirmation
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'schedule_claim') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $claimDate = sanitize($_POST['claim_date'] ?? '');
        $claimTime = sanitize($_POST['claim_time'] ?? '14:00:00');
        $joNo      = sanitize($_POST['job_order_no'] ?? '');
        $invNo     = sanitize($_POST['invoice_no'] ?? '');
        $balDue    = (float)($_POST['balance_due'] ?? 0);
        $notes     = sanitize($_POST['notes'] ?? '');

        if ($patientId > 0 && !empty($claimDate)) {
            $ptStmt = $db->prepare("SELECT full_name FROM patients WHERE id=?");
            $ptStmt->execute([$patientId]);
            $ptName = $ptStmt->fetchColumn() ?: ('Patient #' . $patientId);

            $fullNotes = "Eyeglass Claim & Fitting";
            if (!empty($joNo)) $fullNotes .= " for Job Order #$joNo";
            if (!empty($invNo)) $fullNotes .= " (Invoice: $invNo)";
            if ($balDue > 0) {
                $fullNotes .= " | Balance Due: ₱" . number_format($balDue, 2);
            } else {
                $fullNotes .= " | Paid in Full";
            }
            if (!empty($notes)) $fullNotes .= " | Note: " . $notes;

            $insStmt = $db->prepare("
                INSERT INTO appointments (patient_id, appointment_date, appointment_time, appointment_type, purpose, status, notes, verified_by, created_at)
                VALUES (?, ?, ?, 'SCHEDULED', 'eyeglass_claim', 'confirmed', ?, ?, NOW())
            ");
            $insStmt->execute([$patientId, $claimDate, $claimTime ?: '14:00:00', $fullNotes, $_SESSION['user_id']]);
            
            $_SESSION['flash_msg'] = "Eyeglass Claim scheduled for $ptName on " . date('M d, Y', strtotime($claimDate)) . ".";
            $_SESSION['flash_type'] = "success";
            logActivity("Scheduled Eyeglass Claim for $ptName on $claimDate ($fullNotes)", "Appointments", $_SESSION['user_id'], 'staff');
        } else {
            $_SESSION['flash_msg'] = "Please select a patient and valid claim date.";
            $_SESSION['flash_type'] = "danger";
        }
        $redirectDate = !empty($claimDate) ? $claimDate : $today;
        header('Location: appointments.php?date=' . urlencode($redirectDate));
        exit;
    }

    if ($apptId > 0) {
        $ptStmt = $db->prepare("SELECT p.full_name, a.purpose, a.appointment_date, a.patient_id FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
        $ptStmt->execute([$apptId]);
        $ptRow = $ptStmt->fetch();
        $ptName = $ptRow['full_name'] ?? ('Appointment #' . $apptId);
        $patientId = (int)($ptRow['patient_id'] ?? 0);
        $isClaim = ($ptRow['purpose'] ?? '') === 'eyeglass_claim';

        if ($action === 'confirm') {
            $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
            $_SESSION['flash_msg'] = $isClaim ? 'Eyeglass claim appointment accepted & confirmed.' : 'Appointment confirmed successfully.';
            $_SESSION['flash_type'] = 'success';
            logActivity("Confirmed " . ($isClaim ? 'Eyeglass Claim / Fitting' : 'appointment') . " #$apptId for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'ready_claim') {
            if ($patientId > 0) {
                $db->prepare("UPDATE sales SET order_status='ready_for_pickup' WHERE appointment_id=? OR patient_id=?")->execute([$apptId, $patientId]);
            }
            $_SESSION['flash_msg'] = 'Eyeglasses marked as Ready for Fitting & Pickup.';
            $_SESSION['flash_type'] = 'success';
            logActivity("Marked Eyeglasses Ready for Pickup for appointment #$apptId ($ptName)", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'complete_claim') {
            $db->prepare("UPDATE appointments SET status='completed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
            if ($patientId > 0) {
                $db->prepare("UPDATE sales SET order_status='claimed' WHERE appointment_id=? OR patient_id=?")->execute([$apptId, $patientId]);
            }
            $_SESSION['flash_msg'] = 'Eyeglass claim marked as completed & handed over to patient.';
            $_SESSION['flash_type'] = 'success';
            logActivity("Completed Eyeglass Claim / Fitting for appointment #$apptId ($ptName)", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'cancel') {
            $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]);
            $_SESSION['flash_msg'] = 'Appointment booking cancelled.';
            $_SESSION['flash_type'] = 'danger';
            logActivity("Cancelled appointment #$apptId for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'update_notes') {
            $notes = sanitize($_POST['notes'] ?? '');
            $db->prepare("UPDATE appointments SET notes=? WHERE id=?")->execute([$notes, $apptId]);
            $_SESSION['flash_msg'] = 'Appointment notes updated.';
            $_SESSION['flash_type'] = 'info';
            logActivity("Updated notes for appointment #$apptId ($ptName)", "Appointments", $_SESSION['user_id'], 'staff');
        }
    }
    
    $redirectDate = !empty($_POST['current_view_date']) ? sanitize($_POST['current_view_date']) : $today;
    header('Location: appointments.php?date=' . urlencode($redirectDate));
    exit;
}

// Fetch all appointments with Job Order and sales context
$apptsStmt = $db->query("
    SELECT a.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone, 
           p.email as patient_email, 
           p.gender as patient_gender,
           p.birthdate as patient_birthdate,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as rx_count,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.appointment_id = a.id OR (rx.patient_id = a.patient_id AND DATE(rx.created_at) = a.appointment_date)) as today_rx_count,
           (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed') as completed_visits,
           COALESCE(
               (SELECT s.id FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.id FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as sale_id,
           COALESCE(
               (SELECT s.invoice_no FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.invoice_no FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as invoice_no,
           COALESCE(
               (SELECT s.job_order_no FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.job_order_no FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as job_order_no,
           COALESCE(
               (SELECT s.order_status FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.order_status FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as order_status,
           COALESCE(
               (SELECT s.balance_due FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.balance_due FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as balance_due,
           COALESCE(
               (SELECT s.payment_type FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.payment_type FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as payment_type
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$allAppointments = $apptsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent patients for "+ Schedule Eyeglass Claim" modal
$recentPatients = $db->query("
    SELECT p.id, p.full_name, p.phone,
           (SELECT s.invoice_no FROM sales s WHERE s.patient_id = p.id ORDER BY s.id DESC LIMIT 1) as latest_invoice,
           (SELECT s.job_order_no FROM sales s WHERE s.patient_id = p.id ORDER BY s.id DESC LIMIT 1) as latest_job_order,
           (SELECT s.balance_due FROM sales s WHERE s.patient_id = p.id ORDER BY s.id DESC LIMIT 1) as latest_balance
    FROM patients p
    ORDER BY p.id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Counts for stat cards
$todayCountStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no_show')");
$todayCountStmt->execute([$today]);
$todayActiveCount = $todayCountStmt->fetch()['c'];

$pendingCount = count(array_filter($allAppointments, fn($a) => $a['status'] === 'pending'));
$confirmedCount = count(array_filter($allAppointments, fn($a) => $a['status'] === 'confirmed'));
$totalCount = count($allAppointments);
$activePurposes = getActiveConsultationPurposes($db);
$allSlots = explode(',', getSetting('appointment_slots') ?? '09:00,09:30,10:00,10:30,11:00,11:30,13:00,13:30,14:00,14:30,15:00,15:30,16:00,16:30');

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/calendar.css?v=' . time() . '">';
$extraHead .= '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
$extraHead .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
$extraHead .= '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Walk-in and Eyeglass Claim Highlight Styling */
.cal-event-card.is-walkin,
.cal-week-card.is-walkin {
  border-left: 3px solid #f59e0b !important;
}
.cal-event-card.is-claim,
.cal-week-card.is-claim {
  border-left: 3px solid #10b981 !important;
  background: rgba(16, 185, 129, 0.07) !important;
}
.cal-walkin-badge {
  background: rgba(245, 158, 11, 0.2);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.45);
  font-size: 0.65rem;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  display: inline-block;
  line-height: 1.2;
}
.cal-claim-badge {
  background: rgba(16, 185, 129, 0.2);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.45);
  font-size: 0.65rem;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  display: inline-block;
  line-height: 1.2;
}
.cal-booking-badge {
  background: rgba(14, 165, 233, 0.2);
  color: #0ea5e9;
  border: 1px solid rgba(14, 165, 233, 0.45);
  font-size: 0.65rem;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  display: inline-block;
  line-height: 1.2;
}
.cal-type-btn {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  color: var(--text-muted, #64748b);
  font-size: 0.78rem;
  font-weight: 600;
  padding: 5px 14px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.cal-type-btn:hover {
  background: var(--bg-hover, #f1f5f9);
  color: var(--text-primary, #0f172a);
}
.cal-type-btn.active {
  background: var(--clr-primary, #00ADEF) !important;
  border-color: var(--clr-primary, #00ADEF) !important;
  color: #ffffff !important;
  box-shadow: 0 2px 8px rgba(0, 173, 239, 0.3);
}
.rx-ready-row {
  background: rgba(16, 185, 129, 0.08) !important;
  border-left: 4px solid #10b981 !important;
}
.rx-ready-row td {
  background: transparent !important;
}

/* ─────────────────────────────────────────────────────────────
   TIME SLOT MONITOR STYLES (Copied from patient system format)
   ───────────────────────────────────────────────────────────── */
.slot-section-title {
  font-size: .8rem; font-weight: 800; text-transform: uppercase;
  letter-spacing: .06em; color: var(--clr-primary, #00ADEF); margin: 18px 0 10px;
  display: flex; align-items: center; gap: 8px;
}
.slot-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border-color, #e2e8f0); }

.slot-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px;
}

.slot-btn {
  padding: 10px 8px; border-radius: 12px;
  border: 1.5px solid #CBD5E1;
  background: #FFFFFF; color: #0F172A;
  font-family: inherit; font-size: .9rem; font-weight: 800;
  cursor: pointer; transition: all .15s ease; text-align: center; line-height: 1.2;
  box-shadow: 0 3px 0 #CBD5E1, 0 3px 8px rgba(15, 23, 42, 0.04);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  position: relative; text-decoration: none; width: 100%;
}
.slot-btn .slot-time-text { font-size: 0.95rem; font-weight: 800; }
.slot-btn .slot-period { font-size: .68rem; color: #64748B; display: block; margin-top: 2px; font-weight: 700; }

.slot-btn:hover:not(.is-taken) {
  border-color: #00ADEF; color: #00ADEF;
  background: #EFF6FF; transform: translateY(-2px);
  box-shadow: 0 5px 0 #CBD5E1, 0 6px 14px rgba(0, 173, 239, 0.2);
}
.slot-btn:active:not(.is-taken) {
  transform: translateY(2px); box-shadow: 0 1px 0 #CBD5E1;
}

/* Taken Slot Styling */
.slot-btn.is-taken {
  background: #F8FAFC !important;
  color: #94A3B8 !important;
  border: 1.5px dashed #CBD5E1 !important;
  box-shadow: none !important;
  transform: none !important;
  cursor: pointer;
}
.slot-btn.is-taken .slot-time-text {
  text-decoration: line-through;
  opacity: 0.75;
}
.slot-btn.is-taken .slot-period.booked {
  color: #DC2626 !important;
  background: rgba(239, 68, 68, 0.1);
  padding: 2px 6px; border-radius: 6px;
  margin-top: 4px; display: inline-flex; align-items: center;
  font-size: 0.65rem; font-weight: 800;
}
.slot-btn .slot-patient-tag {
  font-size: 0.72rem;
  font-weight: 600;
  color: #1e293b;
  margin-top: 4px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Dark Mode Overrides */
[data-theme="dark"] .slot-btn {
  background: #1E2D4A; color: #FFFFFF;
  border: 1.5px solid rgba(56, 189, 248, 0.28);
  box-shadow: 0 3px 0 #0D1626, 0 4px 10px rgba(0, 0, 0, 0.3);
}
[data-theme="dark"] .slot-btn .slot-period { color: #7DD3FC; }
[data-theme="dark"] .slot-btn:hover:not(.is-taken) {
  border-color: #38BDF8; color: #38BDF8;
  background: rgba(56, 189, 248, 0.16);
  box-shadow: 0 5px 0 #0D1626, 0 8px 18px rgba(56, 189, 248, 0.25);
}
[data-theme="dark"] .slot-btn.is-taken {
  background: #0B1324 !important;
  color: #64748B !important;
  border: 1.5px dashed rgba(255, 255, 255, 0.14) !important;
}
[data-theme="dark"] .slot-btn.is-taken .slot-period.booked {
  color: #FCA5A5 !important;
  background: rgba(239, 68, 68, 0.2) !important;
}
[data-theme="dark"] .slot-btn .slot-patient-tag {
  color: #94A3B8;
}

.slot-avail-bar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 12px; font-size: .84rem; font-weight: 700;
  padding: 10px 16px; border-radius: 10px;
  background: rgba(0, 173, 239, 0.06); border: 1px solid var(--border-color, #e2e8f0);
}
.slot-avail-bar .badge-avail {
  color: #10B981; display: inline-flex; align-items: center; gap: 6px;
}
.slot-avail-bar .badge-taken {
  color: #EF4444; display: inline-flex; align-items: center; gap: 6px;
}
</style>

<!-- Quick Stats Summary Header (Side by Side Colored Indicators) -->
<div class="row g-3 mb-4">
  <!-- Card 1: Today's Appointments -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Today's Appointments</div>
        <div class="bento-value"><?= number_format($todayActiveCount) ?></div>
        <div class="bento-badge green">
          <i class="fas fa-calendar-day"></i> Scheduled today
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 2: Pending Confirmation -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat" style="--stat-color:#F59E0B; --stat-rgb:245, 158, 11;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Pending Confirmation</div>
        <div class="bento-value"><?= number_format($pendingCount) ?></div>
        <div class="bento-badge warning">
          <i class="fas fa-hourglass-half"></i> Needs action
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-clock"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 3: Confirmed Upcoming -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat" style="--stat-color:#0EA5E9; --stat-rgb:14, 165, 233;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Confirmed Upcoming</div>
        <div class="bento-value"><?= number_format($confirmedCount) ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-check-circle"></i> Ready
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-user-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 4: Total Appointments -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat" style="--stat-color:#8B5CF6; --stat-rgb:139, 92, 246;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Appointments</div>
        <div class="bento-value"><?= number_format($totalCount) ?></div>
        <div class="bento-badge purple">
          <i class="fas fa-calendar-alt"></i> All time records
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-list-ul"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- CALENDAR COMPONENT CONTAINER                                 -->
<!-- ============================================================ -->
<div class="cal-wrapper">
  
  <!-- 1. Top Header Bar -->
  <div class="cal-header">
    <div class="cal-header-left">
      <button type="button" class="cal-btn-today" id="btnToday">Today</button>
      <button type="button" class="cal-nav-btn" id="btnPrev" title="Previous"><i class="fas fa-chevron-left"></i></button>
      <button type="button" class="cal-nav-btn" id="btnNext" title="Next"><i class="fas fa-chevron-right"></i></button>
      
      <div class="cal-title-wrap">
        <h4 class="cal-title-heading" id="calTitle">August 2026</h4>
        <div class="cal-title-sub" id="calSubtitle">Front Desk Queue & Patient Check-in</div>
      </div>
    </div>

    <!-- Quick Actions & View Switcher -->
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-success btn-sm d-flex align-items-center gap-1 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#scheduleClaimModal" style="border:none;border-radius:8px;padding:6px 14px;font-size:0.8rem;background:#10B981;">
        <i class="fas fa-glasses"></i> + Schedule Eyeglass Claim
      </button>
      <button type="button" class="btn btn-warning btn-sm d-flex align-items-center gap-1 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#registerWalkinModal" style="border:none;border-radius:8px;padding:6px 14px;font-size:0.8rem;">
        <i class="fas fa-user-plus"></i> + Walk-in Patient
      </button>
      <a href="pos.php?mode=retail" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1 fw-bold shadow-sm" style="border-radius:8px;padding:6px 14px;font-size:0.8rem;text-decoration:none;">
        <i class="fas fa-bolt"></i> Quick Sale (POS)
      </a>
      <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-2 fw-bold shadow-sm" id="btnSlotsToday" data-bs-toggle="modal" data-bs-target="#slotsTodayModal" style="border:none;border-radius:8px;padding:6px 14px;font-size:0.8rem;background:linear-gradient(135deg, #0284c7 0%, #38bdf8 100%);color:#ffffff;">
        <i class="fas fa-clock"></i> Slots Today <span class="badge bg-white text-primary rounded-pill px-2 py-0" id="slotsTodayHeaderBadge" style="font-size:0.72rem;font-weight:800;">--/--</span>
      </button>
      <div class="cal-view-switcher">
        <button type="button" class="cal-view-btn" data-view="week" id="viewBtnWeek">
          <i class="fas fa-calendar-week"></i> Week
        </button>
        <button type="button" class="cal-view-btn active" data-view="month" id="viewBtnMonth">
          <i class="fas fa-calendar-alt"></i> Month
        </button>
        <button type="button" class="cal-view-btn" data-view="agenda" id="viewBtnAgenda">
          <i class="fas fa-list-ul"></i> Agenda
        </button>
        <button type="button" class="cal-view-btn" data-view="table" id="viewBtnTable">
          <i class="fas fa-users-cog"></i> Queue
        </button>
      </div>
    </div>
  </div>

  <!-- 2. Clean, Streamlined Single-Row Toolbar -->
  <div class="cal-toolbar d-flex align-items-center justify-content-between flex-wrap gap-3 p-3">
    <!-- Left: Purpose Filter Buttons (All Bookings, Eyeglass Claims, Scheduled Checkups) -->
    <div class="d-flex align-items-center gap-2 flex-wrap" id="typeFilterContainer">
      <button type="button" class="cal-type-btn active" data-type="all" id="btnFilterAll">
        <i class="fas fa-calendar-check"></i> All Bookings (<span id="countTypeAll">0</span>)
      </button>
      <button type="button" class="cal-type-btn" data-type="claims" id="btnFilterClaims">
        <i class="fas fa-glasses text-success"></i> Eyeglass Claims (<span id="countTypeClaims">0</span>)
      </button>
      <button type="button" class="cal-type-btn" data-type="consultations" id="btnFilterConsults">
        <i class="fas fa-user-md text-info"></i> Scheduled Checkups (<span id="countTypeConsults">0</span>)
      </button>
    </div>

    <!-- Right: Status Dropdown & Search Box -->
    <div class="d-flex align-items-center gap-2 flex-grow-1 justify-content-end" style="max-width: 480px;">
      <select id="calStatusSelect" class="form-select form-select-sm shadow-none" style="font-size: 0.82rem; border-radius: 8px; font-weight: 500; width: 145px; padding: 6px 12px; cursor: pointer; border-color: var(--border-color, #e2e8f0);">
        <option value="all">All Statuses</option>
        <option value="confirmed">Confirmed</option>
        <option value="in_progress">In-Progress</option>
        <option value="pending">Pending</option>
        <option value="completed">Done</option>
        <option value="no_show">No-Show</option>
        <option value="cancelled">Cancelled</option>
      </select>

      <div class="cal-search-box flex-grow-1" style="margin: 0;">
        <i class="fas fa-search"></i>
        <input type="text" id="calSearchInput" placeholder="Search patient, phone...">
      </div>
    </div>
  </div>

  <!-- 3. Month View -->
  <div id="monthViewContainer" class="cal-month-view">
    <div class="cal-weekdays-header">
      <div class="cal-weekday">Sun</div>
      <div class="cal-weekday">Mon</div>
      <div class="cal-weekday">Tue</div>
      <div class="cal-weekday">Wed</div>
      <div class="cal-weekday">Thu</div>
      <div class="cal-weekday">Fri</div>
      <div class="cal-weekday">Sat</div>
    </div>
    <div class="cal-grid" id="monthGrid">
      <!-- Generated dynamically by JavaScript -->
    </div>
  </div>

  <!-- 4. Week View -->
  <div id="weekViewContainer" class="cal-week-view" style="display:none;">
    <!-- Generated dynamically by JavaScript -->
  </div>

  <!-- 5. Agenda View -->
  <div id="agendaViewContainer" class="cal-agenda-view" style="display:none;">
    <!-- Generated dynamically by JavaScript -->
  </div>

  <!-- 6. Queue / Table View -->
  <div id="tableViewContainer" style="display:none; padding:16px 24px 24px;">
    <!-- Queue Segment Navigation Tabs -->
    <div class="cal-queue-nav-wrap">
      <div class="cal-queue-tabs" role="tablist">
        <button type="button" class="cal-queue-tab-btn active" data-segment="walkin" id="queueTabWalkin">
          <i class="fas fa-walking text-warning"></i>
          <span>Today's Walk-in Queue</span>
          <span class="cal-queue-tab-badge" id="countSegmentWalkin">0</span>
        </button>
        <button type="button" class="cal-queue-tab-btn" data-segment="claims" id="queueTabClaims">
          <i class="fas fa-glasses text-success"></i>
          <span>Eyeglass Claims Queue</span>
          <span class="cal-queue-tab-badge" id="countSegmentClaims">0</span>
        </button>
        <button type="button" class="cal-queue-tab-btn" data-segment="upcoming" id="queueTabUpcoming">
          <i class="fas fa-calendar-alt text-primary"></i>
          <span>Upcoming Bookings</span>
          <span class="cal-queue-tab-badge" id="countSegmentUpcoming">0</span>
        </button>
        <button type="button" class="cal-queue-tab-btn" data-segment="history" id="queueTabHistory">
          <i class="fas fa-history text-muted"></i>
          <span>Past Due &amp; Completed</span>
          <span class="cal-queue-tab-badge" id="countSegmentHistory">0</span>
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table" id="masterApptTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Patient Details</th>
            <th>Type / Purpose</th>
            <th>Schedule</th>
            <th>Job Order &amp; Invoice</th>
            <th>Payment Status</th>
            <th>Queue Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <!-- Injected dynamically -->
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ============================================================ -->
<!-- ============================================================ -->
<!-- APPOINTMENT DETAILS & SALESLADY ACTION MODAL                 -->
<!-- ============================================================ -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px; height:44px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:1.1rem;" id="modalAvatar">
            S
          </div>
          <div>
            <h5 class="modal-title fw-bold cal-modal-title mb-0" id="modalPatientName">Patient Name</h5>
            <small class="text-muted" id="modalPatientMeta">Patient ID: #0 &middot; 0900-000-0000</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span id="modalWalkinBadge"></span>
          <span id="modalStatusBadge"></span>
          <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>

      <div class="modal-body p-4">
        <!-- Appointment Details Cards -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-calendar me-1"></i> Scheduled Date</div>
              <div class="cal-info-val" id="modalDate">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-clock me-1"></i> Time Slot</div>
              <div class="cal-info-val text-primary" id="modalTime">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-tag me-1"></i> Purpose</div>
              <div class="cal-info-val" id="modalPurpose">—</div>
            </div>
          </div>
        </div>

        <!-- Patient Quick Info Banner -->
        <div class="cal-modal-banner mb-4">
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Contact:</span>
              <div class="cal-modal-banner-val small" id="modalPhone">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Email:</span>
              <div class="cal-modal-banner-val small text-truncate" id="modalEmail">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Gender:</span>
              <div class="cal-modal-banner-val small" id="modalGenderAge">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Prescription Count:</span>
              <div class="cal-modal-banner-val small" id="modalRxCount">—</div>
            </div>
          </div>
        </div>

        <!-- Appointment Notes -->
        <div class="mb-4">
          <label class="form-label text-muted small fw-bold text-uppercase"><i class="fas fa-sticky-note me-1"></i> Notes & Customer Requests</label>
          <div class="cal-modal-notes" id="modalNotes">
            No special notes provided.
          </div>
        </div>

        <!-- Eyeglass Claim & Fitting Dedicated Front Desk Action Card -->
        <div id="modalClaimActionBox" class="p-3 mb-3 d-none" style="background:rgba(16,185,129,0.06); border:1.5px solid rgba(16,185,129,0.25); border-radius:12px;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <i class="fas fa-glasses text-success fa-lg"></i>
              <strong class="small text-uppercase" style="letter-spacing:0.4px; color:#10b981;">Eyeglass Claim &amp; Fitting Workflow</strong>
            </div>
            <span class="badge" id="modalClaimStatusBadge" style="font-size:0.75rem;">Claim Pending</span>
          </div>

          <!-- Order & Balance Quick Info Bar -->
          <div class="p-2 mb-2 rounded bg-light border d-flex justify-content-between align-items-center flex-wrap gap-2" id="modalClaimOrderSummary" style="font-size:0.8rem;">
            <div>
              <span class="text-muted">Job Order:</span> <strong id="modalClaimJoNo">—</strong> &middot;
              <span class="text-muted">Invoice:</span> <strong id="modalClaimInvNo">—</strong>
            </div>
            <div id="modalClaimBalanceDueWrap">
              <!-- Injected dynamically -->
            </div>
          </div>

          <!-- Availed Products for Claiming Box -->
          <div class="p-2 mb-2 rounded border" id="modalClaimProductsBox" style="background:#fff; font-size:0.82rem; display:none;">
            <div class="fw-bold mb-1" style="color:#059669;">
              <i class="fas fa-box-open me-1"></i> Availed Products to Claim:
            </div>
            <div id="modalClaimProductsList" class="ps-1 text-dark" style="line-height:1.5;"></div>
          </div>

          <p class="text-muted small mb-3" id="modalClaimNoticeText" style="line-height:1.45;">
            Optical dispensing service managed directly by front-desk Saleslady. No doctor examination or optometrist confirmation required.
          </p>
          <div class="d-flex flex-wrap gap-2 align-items-center" id="modalClaimButtons">
            <!-- Form: Confirm Eyeglass Claim Appointment -->
            <form method="POST" id="formConfirmClaim" class="m-0" style="display:none;">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="confirm">
              <input type="hidden" name="appt_id" id="confirmClaimApptId" value="">
              <input type="hidden" name="current_view_date" id="confirmClaimCurrentDate" value="">
              <button type="submit" class="btn btn-info btn-sm text-white px-3 py-2 shadow-sm fw-bold text-nowrap" id="modalBtnConfirmClaim">
                <i class="fas fa-check-circle me-1"></i> Accept &amp; Confirm Booking
              </button>
            </form>

            <!-- Form: Mark Ready for Pickup -->
            <form method="POST" id="formReadyClaim" class="m-0" style="display:none;">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="ready_claim">
              <input type="hidden" name="appt_id" id="readyClaimApptId" value="">
              <input type="hidden" name="current_view_date" id="readyClaimCurrentDate" value="">
              <button type="submit" class="btn btn-outline-success btn-sm px-3 py-2 shadow-sm fw-bold text-nowrap" id="modalBtnReadyClaim">
                <i class="fas fa-box-open me-1"></i> Mark Ready for Pickup
              </button>
            </form>

            <!-- Form: Mark Claim Completed & Handed Over -->
            <form method="POST" id="formCompleteClaim" class="m-0" style="display:none;">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="complete_claim">
              <input type="hidden" name="appt_id" id="completeClaimApptId" value="">
              <input type="hidden" name="current_view_date" id="completeClaimCurrentDate" value="">
              <button type="submit" class="btn btn-success btn-sm px-3 py-2 shadow-sm fw-bold text-nowrap" id="modalBtnCompleteClaim">
                <i class="fas fa-check-double me-1"></i> Mark as Claimed / Done
              </button>
            </form>

            <!-- Form: Cancel Appointment -->
            <form method="POST" id="formCancelClaim" class="m-0" style="display:none;" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="appt_id" id="cancelClaimApptId" value="">
              <input type="hidden" name="current_view_date" id="cancelClaimCurrentDate" value="">
              <button type="submit" class="btn btn-outline-danger btn-sm px-3 py-2 text-nowrap" id="modalBtnCancelClaim">
                <i class="fas fa-times me-1"></i> Cancel Booking
              </button>
            </form>
          </div>
        </div>

        <!-- Dynamic Lock/Status Alert for Consultation Flow -->
        <div id="modalConsultationLockAlert" class="alert alert-warning py-2 px-3 mb-3 d-none align-items-center gap-2" style="font-size:0.82rem;border-radius:10px;border:1.5px solid #f59e0b;background:rgba(245,158,11,0.08);">
          <i class="fas fa-lock fa-lg text-warning flex-shrink-0"></i>
          <div>
            <strong>Awaiting Doctor Examination:</strong> POS checkout unlocks automatically once the Optometrist inputs the prescription and completes the medical consultation.
          </div>
        </div>

        <!-- Saleslady & Cashier Shortcuts (Clean 2-Column Grid) -->
        <div class="cal-modal-shortcuts p-3">
          <h6 class="cal-modal-shortcuts-heading mb-2"><i class="fas fa-cash-register text-primary me-2"></i>Service &amp; POS Shortcuts</h6>
          <div class="row g-2">
            <div class="col-sm-6">
              <a href="#" id="modalBtnPos" class="btn btn-primary btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center">
                <i class="fas fa-shopping-cart me-1"></i> Proceed to POS Checkout
              </a>
            </div>
            <div class="col-sm-6">
              <a href="#" id="modalBtnPatient" class="btn btn-outline-primary btn-sm w-100 py-2 text-nowrap text-center d-inline-flex align-items-center justify-content-center">
                <i class="fas fa-user me-1"></i> View Patient Profile
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small" id="modalFooterHint">
          <!-- Subtle context helper -->
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- DAY SCHEDULE DRAWER / MODAL                                  -->
<!-- ============================================================ -->
<div class="modal fade" id="dayQueueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold cal-modal-title mb-0" id="dayModalTitle">Appointments for Date</h5>
          <small class="text-muted" id="dayModalSubtitle">Day Queue Overview</small>
        </div>
        <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4" id="dayModalBody">
        <!-- Injected dynamically -->
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SCHEDULE EYEGLASS CLAIM MODAL                                -->
<!-- ============================================================ -->
<div class="modal fade" id="scheduleClaimModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content walkin-modal">
      <div class="modal-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
        <div class="d-flex align-items-center gap-3">
          <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
            <i class="fas fa-glasses"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white">Schedule Eyeglass Claim &amp; Fitting</h5>
            <small style="opacity: 0.9;">Set patient pickup date for fabricated custom spectacles</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="action" value="schedule_claim">
        <input type="hidden" name="current_view_date" value="<?= htmlspecialchars($_GET['date'] ?? $today) ?>">

        <div class="modal-body p-4" style="max-height: calc(85vh - 140px); overflow-y: auto;">
          <!-- 1. Patient Selector -->
          <div class="walkin-card-box">
            <div class="walkin-section-title">
              <i class="fas fa-user"></i> 1. Select Patient
            </div>
            <div class="row g-3">
              <div class="col-12">
                <label class="walkin-field-label">Patient <span class="text-danger">*</span></label>
                <select name="patient_id" id="claimSelectPatient" class="form-select" required onchange="onClaimPatientSelect(this)">
                  <option value="">-- Choose Existing Patient --</option>
                  <?php foreach ($recentPatients as $rp): ?>
                    <option value="<?= $rp['id'] ?>"
                            data-invoice="<?= htmlspecialchars($rp['latest_invoice'] ?? '') ?>"
                            data-jo="<?= htmlspecialchars($rp['latest_job_order'] ?? '') ?>"
                            data-balance="<?= (float)($rp['latest_balance'] ?? 0) ?>">
                      <?= htmlspecialchars($rp['full_name']) ?> (<?= htmlspecialchars($rp['phone'] ?: 'No phone recorded') ?>)
                      <?= !empty($rp['latest_job_order']) ? ' — JO #' . htmlspecialchars($rp['latest_job_order']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- 2. Target Claim Schedule -->
          <div class="walkin-card-box">
            <div class="walkin-section-title title-contact">
              <i class="fas fa-calendar-alt"></i> 2. Target Pickup Date &amp; Time
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="walkin-field-label">Scheduled Claim Date <span class="text-danger">*</span></label>
                <input type="date" name="claim_date" id="claimInputDate" class="form-control" required min="<?= $today ?>" value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
              </div>
              <div class="col-md-6">
                <label class="walkin-field-label">Preferred Pickup Time</label>
                <input type="time" name="claim_time" id="claimInputTime" class="form-control" value="14:00">
              </div>
            </div>
          </div>

          <!-- 3. Transaction & Job Order Linkage -->
          <div class="walkin-card-box mb-0">
            <div class="walkin-section-title title-clinical">
              <i class="fas fa-receipt"></i> 3. Order Details &amp; Balance
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="walkin-field-label">Job Order # <small class="text-muted">(Optional)</small></label>
                <input type="text" name="job_order_no" id="claimInputJobOrder" class="form-control" placeholder="e.g. JO-20260930-0001">
              </div>
              <div class="col-md-4">
                <label class="walkin-field-label">Invoice # <small class="text-muted">(Optional)</small></label>
                <input type="text" name="invoice_no" id="claimInputInvoice" class="form-control" placeholder="e.g. GOC-20260930-0001">
              </div>
              <div class="col-md-4">
                <label class="walkin-field-label">Balance Due (₱)</label>
                <input type="number" step="0.01" min="0" name="balance_due" id="claimInputBalance" class="form-control" placeholder="0.00" value="0.00">
              </div>
              <div class="col-12">
                <label class="walkin-field-label">Fabrication / Fitting Notes <small class="text-muted">(Optional)</small></label>
                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Multicoated progressive lens, frame adjustment requested..."></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-walkin-cancel" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="submit" class="btn btn-success fw-bold px-4 py-2" style="background:#10b981; border:none; border-radius:8px;">
            <i class="fas fa-calendar-check me-1"></i> Schedule Eyeglass Claim
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- ============================================================ -->
<!-- REGISTER WALK-IN PATIENT MODAL (MODERN REDESIGN)             -->
<!-- ============================================================ -->
<div class="modal fade" id="registerWalkinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width: 860px;">
    <div class="modal-content walkin-modal">
      
      <!-- Modern Modal Header -->
      <div class="modal-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <div class="walkin-header-icon shadow-sm">
            <i class="fas fa-user-plus"></i>
          </div>
          <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <h5 class="walkin-modal-title">Register Walk-in Patient</h5>
              <span class="walkin-badge-date">
                <i class="fas fa-calendar-day"></i> Today: <?= date('M d, Y') ?>
              </span>
              <span class="walkin-badge-direct">
                <i class="fas fa-bolt"></i> Direct Check-in
              </span>
            </div>
            <small class="walkin-modal-subtitle">Create patient record and place directly into today's queue</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="formRegisterWalkin" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="modal-body p-4" style="max-height: calc(85vh - 140px); overflow-y: auto;">
          <div id="walkinAlert" class="alert alert-danger py-2 px-3 mb-3 d-none" style="font-size:0.85rem; border-radius:10px;"></div>

          <!-- SECTION 1: Patient Identity & Demographics -->
          <div class="walkin-card-box">
            <div class="walkin-section-title">
              <i class="fas fa-id-card"></i> 1. Patient Demographics &amp; Identity
            </div>
            
            <div class="row g-3">
              <div class="col-md-4">
                <label class="walkin-field-label">Last Name <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-user walkin-input-icon"></i>
                  <input type="text" name="last_name" id="walkinInputLastName" class="form-control alpha-only" placeholder="e.g. Dela Cruz" required maxlength="50" autocomplete="off">
                </div>
              </div>

              <div class="col-md-4">
                <label class="walkin-field-label">First Name <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-user walkin-input-icon"></i>
                  <input type="text" name="first_name" id="walkinInputFirstName" class="form-control alpha-only" placeholder="e.g. Juan" required maxlength="50" autocomplete="off">
                </div>
              </div>

              <div class="col-md-4">
                <label class="walkin-field-label">Middle Name <small class="text-muted fw-normal">(Optional)</small></label>
                <div class="walkin-input-group">
                  <i class="fas fa-user-tag walkin-input-icon"></i>
                  <input type="text" name="middle_name" id="walkinInputMiddleName" class="form-control alpha-only" placeholder="e.g. Santos" maxlength="50" autocomplete="off">
                </div>
              </div>

              <div class="col-md-4">
                <label class="walkin-field-label">Sex / Gender <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-venus-mars walkin-input-icon"></i>
                  <select name="gender" id="walkinInputGender" class="form-select" required>
                    <option value="">Select Sex</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                </div>
              </div>

              <div class="col-md-5">
                <label class="walkin-field-label">Birthdate <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-calendar-alt walkin-input-icon"></i>
                  <input type="text" name="birthdate" id="walkinInputBirthdate" class="form-control modern-birthdate-picker" placeholder="Select birthdate" required autocomplete="off">
                </div>
              </div>

              <div class="col-md-3">
                <label class="walkin-field-label">Calculated Age</label>
                <div class="walkin-input-group">
                  <i class="fas fa-hourglass-half walkin-input-icon"></i>
                  <input type="text" id="walkinInputAge" class="form-control text-center fw-bold walkin-input-readonly" placeholder="—" readonly style="letter-spacing: 0.5px;">
                </div>
              </div>
            </div>
          </div>

          <!-- SECTION 2: Contact & Address -->
          <div class="walkin-card-box">
            <div class="walkin-section-title title-contact">
              <i class="fas fa-address-book"></i> 2. Contact Details &amp; Location
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="walkin-field-label">Mobile Number <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-mobile-alt walkin-input-icon"></i>
                  <input type="tel" name="phone" id="walkinInputPhone" class="form-control numeric-only" placeholder="09XXXXXXXXX (11 digits)" required maxlength="11" inputmode="numeric" autocomplete="tel">
                </div>
              </div>

              <div class="col-md-6">
                <label class="walkin-field-label">Email Address <small class="text-muted fw-normal">(Optional)</small></label>
                <div class="walkin-input-group">
                  <i class="fas fa-envelope walkin-input-icon"></i>
                  <input type="email" name="email" id="walkinInputEmail" class="form-control" placeholder="patient@example.com (or leave blank)">
                </div>
              </div>

              <div class="col-12">
                <label class="walkin-field-label">Complete Home Address <span class="text-danger">*</span></label>
                <div class="walkin-input-group">
                  <i class="fas fa-map-marker-alt walkin-input-icon"></i>
                  <input type="text" name="address" id="walkinInputAddress" class="form-control" placeholder="House/Unit #, Street, Barangay, City / Municipality, Province" required maxlength="255">
                </div>
              </div>
            </div>
          </div>

          <!-- SECTION 3: Visit Purpose & Queue Assignment -->
          <div class="walkin-card-box mb-0">
            <div class="walkin-section-title title-clinical">
              <i class="fas fa-stethoscope"></i> 3. Clinical Service &amp; Queue Routing
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="walkin-field-label">Consultation Purpose</label>
                <div class="walkin-input-group">
                  <i class="fas fa-glasses walkin-input-icon"></i>
                  <select name="purpose" id="walkinInputPurpose" class="form-select">
                    <?php foreach ($activePurposes as $idx => $p): ?>
                      <option value="<?= htmlspecialchars($p['category_key']) ?>" <?= ($p['category_key'] === 'consultation' || $idx === 0) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="col-md-6">
                <label class="walkin-field-label">Initial Queue Status</label>
                <div class="walkin-input-group">
                  <i class="fas fa-clock walkin-input-icon"></i>
                  <select name="initial_status" id="walkinInputInitialStatus" class="form-select">
                    <option value="confirmed" selected>Waiting in Clinic (Confirmed)</option>
                    <option value="in_progress">Direct to Doctor (Examining Now / In-Progress)</option>
                  </select>
                </div>
              </div>

              <div class="col-12" id="walkinPurposeNoticeWrap" style="display:none;">
                <div class="alert walkin-purpose-alert py-2 px-3 mb-0 d-flex align-items-center gap-2">
                  <i class="fas fa-glasses fa-lg flex-shrink-0"></i>
                  <div><strong>Front Desk Service:</strong> Eyeglass Claim &amp; Fitting is handled directly by Saleslady. No Optometrist checkup required.</div>
                </div>
              </div>

              <div class="col-12">
                <label class="walkin-field-label">Staff Notes / Chief Complaints <small class="text-muted fw-normal">(Optional)</small></label>
                <div class="walkin-input-group">
                  <i class="fas fa-notes-medical walkin-input-icon" style="top: 18px;"></i>
                  <textarea name="notes" id="walkinInputNotes" class="form-control" rows="2" placeholder="e.g. Frame styling request, blurred vision, urgent replacement..."></textarea>
                </div>
              </div>
            </div>
          </div>

        </div>

        <!-- Modern Modal Footer -->
        <div class="modal-footer d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-walkin-cancel" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="submit" class="btn btn-walkin-submit shadow-sm" id="btnSubmitWalkin">
            <i class="fas fa-check-circle me-1"></i> Register &amp; Add to Queue
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SLOTS TODAY MONITOR MODAL (Matches Patient System Format)    -->
<!-- ============================================================ -->
<div class="modal fade" id="slotsTodayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.18); overflow: hidden;">
      <div class="modal-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0284c7, #38bdf8); color: white; padding: 18px 24px;">
        <div class="d-flex align-items-center gap-3">
          <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.22); display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
            <i class="fas fa-clock text-white"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white" id="slotsModalHeading">Slots Today · <?= date('F j, Y') ?></h5>
            <small style="opacity: 0.92; font-size: 0.8rem;">Monitor available and booked doctor consultation slots for today</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4" style="max-height: calc(85vh - 120px); overflow-y: auto;">
        <!-- Date Selector & Quick Jump -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted fw-bold small text-uppercase" style="letter-spacing: 0.5px;">Monitoring Date:</span>
            <input type="date" id="slotsFilterDateInput" class="form-control form-control-sm" value="<?= $today ?>" style="width: 160px; font-weight: 600; border-radius: 8px;">
            <button type="button" class="btn btn-outline-primary btn-sm px-3 fw-bold" id="btnSlotsJumpToday" style="border-radius: 8px;">
              <i class="fas fa-calendar-day me-1"></i> Today
            </button>
          </div>
          <div class="text-muted small" id="slotsLiveNotice">
            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-circle text-success me-1" style="font-size:0.5rem;"></i>Live Sync Active</span>
          </div>
        </div>

        <!-- Availability Status Bar (Copy from patient system format) -->
        <div class="slot-avail-bar" id="slotsAvailSummaryBar">
          <span class="badge-avail"><i class="fas fa-circle-check"></i> <strong id="slotsAvailText">-- of -- slots available</strong></span>
          <span class="badge-taken"><i class="fas fa-circle-xmark"></i> <strong id="slotsTakenText">-- slots taken</strong></span>
        </div>

        <!-- Fully Booked Notice (Hidden by default, shown if 0 available) -->
        <div class="slot-fully-booked-box" id="slotsFullyBookedBox" style="display:none;">
          <div class="slot-fully-booked-icon"><i class="fas fa-calendar-xmark"></i></div>
          <div>
            <div class="slot-fully-booked-title">Fully Booked for This Date</div>
            <p class="slot-fully-booked-desc">All appointment time slots for this day have already been scheduled or occupied.</p>
          </div>
        </div>

        <!-- Morning Section -->
        <div class="slot-section-title">
          <i class="fas fa-sun text-warning"></i> Morning Schedule (9:00 AM – 11:30 AM)
        </div>
        <div class="slot-grid" id="slotsGridMorning">
          <!-- Populated by JS -->
        </div>

        <!-- Afternoon Section -->
        <div class="slot-section-title" style="margin-top: 24px;">
          <i class="fas fa-cloud-moon text-info"></i> Afternoon Schedule (1:00 PM – 4:30 PM)
        </div>
        <div class="slot-grid" id="slotsGridAfternoon">
          <!-- Populated by JS -->
        </div>

        <!-- Explanatory Help Footnote -->
        <div class="alert alert-light border mt-4 mb-0 py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="font-size: 0.78rem;">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span><span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fas fa-check me-1"></i>Available</span> = Open for walk-in or booking</span>
            <span><span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 ms-2"><i class="fas fa-lock me-1"></i>Slot Taken</span> = Booked via patient system / front desk</span>
          </div>
          <span class="text-muted"><i class="fas fa-info-circle me-1"></i>Click any taken slot to view appointment record</span>
        </div>
      </div>

      <div class="modal-footer d-flex justify-content-between align-items-center py-2 px-4 bg-light">
        <span class="text-muted small">Standard 30-minute consultation intervals</span>
        <button type="button" class="btn btn-secondary btn-sm px-4 fw-bold" data-bs-dismiss="modal" style="border-radius: 8px;">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- CALENDAR JAVASCRIPT LOGIC ENGINE                             -->
<!-- ============================================================ -->
<script>
// Expose onClaimPatientSelect globally for the claim modal dropdown
window.onClaimPatientSelect = function(selectEl) {
  const selectedOpt = selectEl.options[selectEl.selectedIndex];
  if (!selectedOpt) return;
  const jo = selectedOpt.getAttribute('data-jo') || '';
  const inv = selectedOpt.getAttribute('data-invoice') || '';
  const bal = selectedOpt.getAttribute('data-balance') || '0.00';
  
  const joInput = document.getElementById('claimInputJobOrder');
  const invInput = document.getElementById('claimInputInvoice');
  const balInput = document.getElementById('claimInputBalance');
  if (joInput && jo) joInput.value = jo;
  if (invInput && inv) invInput.value = inv;
  if (balInput && bal) balInput.value = parseFloat(bal).toFixed(2);
};

document.addEventListener('DOMContentLoaded', function() {
  const rawAppointments = <?= json_encode($allAppointments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const allSlots = <?= json_encode($allSlots) ?>;
  const initialDateStr = '<?= htmlspecialchars($_GET['date'] ?? $today) ?>';
  
  // App State
  let currentDate = new Date(initialDateStr + 'T00:00:00');
  if (isNaN(currentDate.getTime())) currentDate = new Date();
  
  let currentView = 'month'; // 'month', 'week', 'agenda', 'table'
  let currentFilter = 'all';  // 'all', 'confirmed', 'in_progress', 'pending', 'completed', 'no_show', 'cancelled'
  let currentBookingType = 'all'; // 'all', 'claims', 'consultations'
  let includeWalkinsInCalendar = false; // Exclude 15-20 daily walk-in checkups from calendar grid by default
  let queueSegment = 'walkin'; // 'walkin', 'claims', 'upcoming', 'history'
  let searchQuery = '';
  let selectedDateStr = initialDateStr || formatDateIso(new Date());
  let highlightApptId = parseInt(new URLSearchParams(window.location.search).get('highlight') || '0', 10);

  // DOM Elements
  const calTitle = document.getElementById('calTitle');
  const calSubtitle = document.getElementById('calSubtitle');
  const btnToday = document.getElementById('btnToday');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const viewBtnMonth = document.getElementById('viewBtnMonth');
  const viewBtnWeek = document.getElementById('viewBtnWeek');
  const viewBtnAgenda = document.getElementById('viewBtnAgenda');
  const viewBtnTable = document.getElementById('viewBtnTable');
  const monthViewContainer = document.getElementById('monthViewContainer');
  const weekViewContainer = document.getElementById('weekViewContainer');
  const agendaViewContainer = document.getElementById('agendaViewContainer');
  const tableViewContainer = document.getElementById('tableViewContainer');
  const monthGrid = document.getElementById('monthGrid');
  const tableBody = document.getElementById('tableBody');
  const searchInput = document.getElementById('calSearchInput');
  const calStatusSelect = document.getElementById('calStatusSelect');
  const typeFilterBtns = document.querySelectorAll('.cal-type-btn');

  // Modals
  const appointmentModalEl = document.getElementById('appointmentModal');
  const appointmentModal = new bootstrap.Modal(appointmentModalEl);
  const dayQueueModal = new bootstrap.Modal(document.getElementById('dayQueueModal'));

  // Utility helpers
  function formatDateIso(d) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function formatTime12(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    let hours = parseInt(parts[0], 10);
    const mins = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${hours}:${mins} ${ampm}`;
  }

  function formatDisplayDate(dateObj) {
    return dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function isApptClaim(appt) {
    const p = (appt.purpose || '').toLowerCase();
    return p === 'eyeglass_claim';
  }

  function parseApptNotes(rawNotes) {
    if (!rawNotes || typeof rawNotes !== 'string') {
      return { service: '', userNotes: '', hasUserNotes: false, availedProducts: [] };
    }
    let txt = rawNotes.trim();
    txt = txt.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#039;/g, "'");

    let service = '';
    let userNotes = txt;
    const match = txt.match(/^Service:\s*([^\r\n]+)/m);
    if (match) {
      service = match[1].trim();
      userNotes = txt.replace(/^Service:\s*[^\r\n]+/m, '').trim();
    }

    // Extract Availed Products if present in claim notes
    const availedProducts = [];
    const prodMatch = txt.match(/Availed Products:\s*([\s\S]*?)(?=(Invoice:|Job Order:|Balance|Paid in Full|$))/i);
    if (prodMatch && prodMatch[1]) {
      const lines = prodMatch[1].split(/\r?\n/).map(l => l.trim()).filter(l => l.length > 0);
      lines.forEach(l => {
        const clean = l.replace(/^[•\-\*]\s*/, '').trim();
        if (clean) availedProducts.push(clean);
      });
    }

    return {
      service: service,
      userNotes: userNotes,
      hasUserNotes: userNotes.length > 0,
      availedProducts: availedProducts
    };
  }

  function normalizeSlotTime(t) {
    if (!t) return '';
    const p = String(t).trim().split(':');
    return p.length >= 2 ? (p[0].padStart(2, '0') + ':' + p[1].padStart(2, '0')) : String(t).substring(0, 5);
  }

  function getApptsForSlot(slot, dateStr) {
    const normSlot = normalizeSlotTime(slot);
    return rawAppointments.filter(a => {
      if (a.appointment_date !== dateStr) return false;
      if (a.status === 'cancelled' || a.status === 'no_show') return false;
      return normalizeSlotTime(a.appointment_time) === normSlot;
    });
  }

  function formatSlotDisplay(slot) {
    const [h, m] = slot.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return {
      time: `${h12}:${String(m).padStart(2, '0')}`,
      period: ampm
    };
  }

  function getStatusBadgeHtml(status) {
    const map = {
      'confirmed':   '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fas fa-check-circle me-1"></i>Confirmed</span>',
      'in_progress': '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="fas fa-stethoscope me-1"></i>In-Progress</span>',
      'pending':     '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fas fa-clock me-1"></i>Pending</span>',
      'completed':   '<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1"><i class="fas fa-check-double me-1"></i>Done</span>',
      'cancelled':   '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fas fa-times-circle me-1"></i>Cancelled</span>',
      'no_show':     '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="fas fa-user-slash me-1"></i>No-Show</span>'
    };
    return map[status] || `<span class="badge bg-secondary">${status}</span>`;
  }

  // Filter appointments specifically for Calendar Grid (Month, Week, Agenda)
  function getCalendarAppointments() {
    return rawAppointments.filter(appt => {
      const isClaim = isApptClaim(appt);
      const isWalkin = (appt.appointment_type === 'WALK_IN');

      // Exclude same-day walk-in checkups by default so calendar isn't cluttered
      // But if user typed a search query, include all matching patients (including walk-ins) so search always finds them
      if (searchQuery.trim() === '' && isWalkin && !isClaim) {
        return false;
      }

      // Booking Type Filter (All / Eyeglass Claims / Scheduled Checkups)
      if (currentBookingType === 'claims' && !isClaim) return false;
      if (currentBookingType === 'consultations' && isClaim) return false;

      // Status filter
      if (currentFilter !== 'all' && appt.status !== currentFilter) return false;

      // Live search query
      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase();
        const patientName = (appt.patient_name || '').toLowerCase();
        const phone = (appt.patient_phone || '').toLowerCase();
        const purpose = (appt.purpose || '').toLowerCase();
        const notes = (appt.notes || '').toLowerCase();
        const jo = (appt.job_order_no || '').toLowerCase();
        const inv = (appt.invoice_no || '').toLowerCase();
        if (!patientName.includes(q) && !phone.includes(q) && !purpose.includes(q) && !notes.includes(q) && !jo.includes(q) && !inv.includes(q)) {
          return false;
        }
      }
      return true;
    });
  }

  function updateCounts() {
    const todayIso = formatDateIso(new Date());

    // 1. Calendar View Type button counters (All Bookings, Eyeglass Claims, Scheduled Checkups)
    const countAllBookings = rawAppointments.filter(a => {
      const isClaim = isApptClaim(a);
      const isWalkin = (a.appointment_type === 'WALK_IN');
      return !isWalkin || isClaim;
    }).length;

    const countAllClaims = rawAppointments.filter(a => isApptClaim(a)).length;
    const countAllConsults = rawAppointments.filter(a => !isApptClaim(a) && a.appointment_type !== 'WALK_IN').length;

    const cTypeAll = document.getElementById('countTypeAll');
    const cTypeClaims = document.getElementById('countTypeClaims');
    const cTypeConsults = document.getElementById('countTypeConsults');
    if (cTypeAll) cTypeAll.textContent = countAllBookings;
    if (cTypeClaims) cTypeClaims.textContent = countAllClaims;
    if (cTypeConsults) cTypeConsults.textContent = countAllConsults;

    // 2. Queue Segment tab counters
    // Today's Walk-in Queue: All active walk-in patients today who have NOT had a sale completed and are not cancelled/no-show
    const countWalkin = rawAppointments.filter(a => {
      const isWalkin = (a.appointment_type === 'WALK_IN');
      const isFinished = !!a.sale_id || (a.status === 'cancelled' || a.status === 'no_show');
      return a.appointment_date === todayIso && isWalkin && !isApptClaim(a) && !isFinished;
    }).length;

    // Eyeglass claims queue: All claims that are pending or confirmed (not finished with handover)
    const countClaims = rawAppointments.filter(a => {
      const isFinished = (a.status === 'completed' || a.status === 'cancelled' || a.status === 'no_show');
      return isApptClaim(a) && !isFinished;
    }).length;

    // Upcoming: Future bookings (non-walkin or future claims) that are not finished
    const countUpcoming = rawAppointments.filter(a => {
      const isFinished = (a.status === 'completed' || a.status === 'cancelled' || a.status === 'no_show');
      return a.appointment_date > todayIso && !isFinished;
    }).length;

    // History: Past dates or finished transactions
    const countHistory = rawAppointments.filter(a => {
      const isPastDate = (a.appointment_date < todayIso);
      const isFinished = (a.status === 'completed' || a.status === 'cancelled' || a.status === 'no_show' || !!a.sale_id);
      return isPastDate || isFinished;
    }).length;

    const segWalkinEl = document.getElementById('countSegmentWalkin');
    const segClaimsEl = document.getElementById('countSegmentClaims');
    const segUpcomingEl = document.getElementById('countSegmentUpcoming');
    const segHistoryEl = document.getElementById('countSegmentHistory');
    if (segWalkinEl) segWalkinEl.textContent = countWalkin;
    if (segClaimsEl) segClaimsEl.textContent = countClaims;
    if (segUpcomingEl) segUpcomingEl.textContent = countUpcoming;
    if (segHistoryEl) segHistoryEl.textContent = countHistory;

    // 3. Slots Today Header Badge
    let takenTodayCount = 0;
    allSlots.forEach(slot => {
      if (getApptsForSlot(slot, todayIso).length > 0) takenTodayCount++;
    });
    const freeTodayCount = Math.max(0, allSlots.length - takenTodayCount);
    const slotsHeaderBadge = document.getElementById('slotsTodayHeaderBadge');
    if (slotsHeaderBadge) {
      if (freeTodayCount === 0) {
        slotsHeaderBadge.className = 'badge bg-danger text-white rounded-pill px-2 py-0';
        slotsHeaderBadge.textContent = 'Full';
      } else {
        slotsHeaderBadge.className = 'badge bg-white text-primary rounded-pill px-2 py-0';
        slotsHeaderBadge.textContent = `${freeTodayCount}/${allSlots.length} Free`;
      }
    }
  }

  // ── 1. RENDER MONTH VIEW ───────────────────────────────────────
  function renderMonth() {
    monthGrid.innerHTML = '';
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    calTitle.textContent = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    calSubtitle.textContent = `Monthly View &middot; ${currentDate.toLocaleDateString('en-US', { month: 'long' })}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const lastDayOfMonth = new Date(year, month + 1, 0).getDate();
    const prevLastDay = new Date(year, month, 0).getDate();

    const todayIso = formatDateIso(new Date());
    const filteredAppts = getCalendarAppointments();

    const apptsByDate = {};
    filteredAppts.forEach(appt => {
      if (!apptsByDate[appt.appointment_date]) apptsByDate[appt.appointment_date] = [];
      apptsByDate[appt.appointment_date].push(appt);
    });

    // Previous month filler days
    for (let x = firstDayIndex; x > 0; x--) {
      const dayNum = prevLastDay - x + 1;
      const prevDate = new Date(year, month - 1, dayNum);
      const dateIso = formatDateIso(prevDate);
      const cell = createDayCell(dayNum, dateIso, true, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }

    // Current month days
    for (let i = 1; i <= lastDayOfMonth; i++) {
      const thisDate = new Date(year, month, i);
      const dateIso = formatDateIso(thisDate);
      const cell = createDayCell(i, dateIso, false, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }

    // Next month filler days
    const totalCellsSoFar = firstDayIndex + lastDayOfMonth;
    const remainingCells = totalCellsSoFar > 35 ? (42 - totalCellsSoFar) : (35 - totalCellsSoFar);

    for (let j = 1; j <= remainingCells; j++) {
      const nextDate = new Date(year, month + 1, j);
      const dateIso = formatDateIso(nextDate);
      const cell = createDayCell(j, dateIso, true, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }
  }

  function createDayCell(dayNum, dateIso, isOtherMonth, appts, todayIso) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell';
    if (isOtherMonth) cell.classList.add('other-month');
    if (dateIso === todayIso) cell.classList.add('is-today');
    if (dateIso === selectedDateStr) cell.classList.add('selected-day');

    cell.dataset.date = dateIso;

    // Top Header in Cell with claim counters & status dots
    const topWrap = document.createElement('div');
    topWrap.className = 'cal-day-cell-top';

    const numWrap = document.createElement('div');
    numWrap.className = 'cal-day-number-wrap';

    const numSpan = document.createElement('span');
    numSpan.className = 'cal-day-number';
    numSpan.textContent = dayNum;
    numWrap.appendChild(numSpan);

    const claimCount = appts.filter(a => isApptClaim(a)).length;
    if (claimCount > 0) {
      const claimPill = document.createElement('span');
      claimPill.className = 'badge bg-success-subtle text-success border border-success-subtle px-1 py-0';
      claimPill.style.fontSize = '0.62rem';
      claimPill.title = `${claimCount} Eyeglass Claim(s)`;
      claimPill.innerHTML = `<i class="fas fa-glasses me-1"></i>${claimCount}`;
      numWrap.appendChild(claimPill);
    }

    if (dateIso === todayIso) {
      const todayWalkinsCount = rawAppointments.filter(a => a.appointment_date === todayIso && a.appointment_type === 'WALK_IN' && !isApptClaim(a) && !a.sale_id && a.status !== 'cancelled' && a.status !== 'no_show').length;
      if (todayWalkinsCount > 0) {
        const walkinPill = document.createElement('span');
        walkinPill.className = 'badge bg-warning-subtle text-dark border border-warning-subtle px-1 py-0';
        walkinPill.style.fontSize = '0.62rem';
        walkinPill.style.cursor = 'pointer';
        walkinPill.title = `${todayWalkinsCount} Walk-in Patient(s) in Queue Today - Click to view`;
        walkinPill.innerHTML = `<i class="fas fa-walking me-1"></i>${todayWalkinsCount}`;
        walkinPill.addEventListener('click', (e) => {
          e.stopPropagation();
          currentView = 'table';
          allViewBtns.forEach(b => b.classList.toggle('active', b.dataset.view === 'table'));
          queueSegment = 'walkin';
          const queueTabBtns = document.querySelectorAll('.cal-queue-tab-btn');
          queueTabBtns.forEach(b => b.classList.toggle('active', b.dataset.segment === 'walkin'));
          currentFilter = 'all';
          if (calStatusSelect) calStatusSelect.value = 'all';
          render();
        });
        numWrap.appendChild(walkinPill);
      }
    }

    if (appts.length > 0) {
      const dotsRow = document.createElement('span');
      dotsRow.className = 'cal-day-dots-row';
      appts.slice(0, 3).forEach(a => {
        const dot = document.createElement('span');
        const isClaim = isApptClaim(a);
        dot.className = isClaim ? 'cal-indicator-dot' : `cal-indicator-dot dot-${a.status}`;
        if (isClaim) dot.style.background = '#10B981';
        dotsRow.appendChild(dot);
      });
      numWrap.appendChild(dotsRow);
    }

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cal-add-btn';
    addBtn.title = 'View Appointments on ' + dateIso;
    addBtn.innerHTML = '<i class="fas fa-plus"></i>';
    addBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      openDayQueueModal(dateIso);
    });

    topWrap.appendChild(numWrap);
    topWrap.appendChild(addBtn);
    cell.appendChild(topWrap);

    // Events list
    const eventsList = document.createElement('div');
    eventsList.className = 'cal-events-list';

    const maxVisible = 3;
    const visibleAppts = appts.slice(0, maxVisible);
    const overflowCount = appts.length - maxVisible;

    visibleAppts.forEach(appt => {
      const isClaim = isApptClaim(appt);
      const isWalkin = (appt.appointment_type === 'WALK_IN');
      const card = document.createElement('div');
      card.className = `cal-event-card status-${appt.status}${isClaim ? ' is-claim' : ''}${isWalkin ? ' is-walkin' : ''}`;
      const timeStr = formatTime12(appt.appointment_time);

      let subMeta = '';
      if (isClaim && parseFloat(appt.balance_due) > 0) {
        subMeta = `<div class="text-warning fw-bold" style="font-size:0.65rem;"><i class="fas fa-coins me-1"></i>Bal: ₱${parseFloat(appt.balance_due).toLocaleString()}</div>`;
      }

      card.innerHTML = `
        <div class="cal-card-chips-row">
          <span class="cal-event-time"><i class="far fa-clock"></i> ${timeStr}</span>
          ${isClaim ? '<span class="cal-claim-badge"><i class="fas fa-glasses me-1"></i>CLAIM</span>' : (isWalkin ? '<span class="cal-walkin-badge">Walk-in</span>' : '<span class="cal-booking-badge">Book</span>')}
          <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
        </div>
        <div class="cal-event-title">${escapeHtml(appt.patient_name)}</div>
        ${subMeta}
      `;

      card.addEventListener('click', (e) => {
        e.stopPropagation();
        openAppointmentModal(appt);
      });

      eventsList.appendChild(card);
    });

    if (overflowCount > 0) {
      const moreBadge = document.createElement('div');
      moreBadge.className = 'cal-more-badge';
      moreBadge.textContent = `+ ${overflowCount} more`;
      moreBadge.addEventListener('click', (e) => {
        e.stopPropagation();
        openDayQueueModal(dateIso);
      });
      eventsList.appendChild(moreBadge);
    }

    cell.appendChild(eventsList);

    cell.addEventListener('click', () => {
      document.querySelectorAll('.cal-day-cell').forEach(c => c.classList.remove('selected-day'));
      cell.classList.add('selected-day');
      selectedDateStr = dateIso;
    });

    cell.addEventListener('dblclick', () => {
      openDayQueueModal(dateIso);
    });

    return cell;
  }

  // ── 2. RENDER WEEK VIEW ────────────────────────────────────────
  function renderWeek() {
    weekViewContainer.innerHTML = '';
    const todayIso = formatDateIso(new Date());
    const filteredAppts = getCalendarAppointments();

    const curr = new Date(currentDate);
    const dayOfWeek = curr.getDay();
    const sunday = new Date(curr);
    sunday.setDate(curr.getDate() - dayOfWeek);

    const weekDays = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(sunday);
      d.setDate(sunday.getDate() + i);
      weekDays.push(d);
    }

    const startStr = weekDays[0].toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const endStr = weekDays[6].toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    calTitle.textContent = `${startStr} – ${endStr}`;
    calSubtitle.textContent = 'Weekly Front Desk Schedule Overview';

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    weekDays.forEach((dayObj, index) => {
      const dateIso = formatDateIso(dayObj);
      const isToday = (dateIso === todayIso);
      const dayAppts = filteredAppts.filter(a => a.appointment_date === dateIso);

      const col = document.createElement('div');
      col.className = 'cal-week-col';
      if (isToday) col.classList.add('is-today');

      col.innerHTML = `
        <div class="cal-week-col-header">
          <div class="cal-week-col-day">${dayNames[index]}</div>
          <div class="cal-week-col-num">${dayObj.getDate()}</div>
        </div>
      `;

      const eventsList = document.createElement('div');
      eventsList.className = 'cal-week-events-list';

      if (dayAppts.length === 0) {
        eventsList.innerHTML = `<div class="text-center text-muted small py-4" style="opacity:0.5;">No appts</div>`;
      } else {
        dayAppts.forEach(appt => {
          const isClaim = isApptClaim(appt);
          const isWalkin = (appt.appointment_type === 'WALK_IN');
          const card = document.createElement('div');
          card.className = `cal-week-card status-${appt.status}${isClaim ? ' is-claim' : ''}${isWalkin ? ' is-walkin' : ''}`;
          
          let chipTypeBadge = isClaim 
            ? '<span class="cal-claim-badge"><i class="fas fa-glasses me-1"></i>CLAIM</span>'
            : (isWalkin ? '<span class="cal-walkin-badge">Walk-in</span>' : '<span class="cal-booking-badge">Book</span>');

          let extraMeta = `<span class="badge bg-dark-subtle text-muted small" style="font-size:0.68rem;"><i class="fas fa-tag me-1"></i>${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</span>`;

          card.innerHTML = `
            <div class="cal-card-chips-row">
              <span class="cal-event-time"><i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}</span>
              ${chipTypeBadge}
              <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
            </div>
            <div class="cal-week-card-name">${escapeHtml(appt.patient_name)}</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              ${extraMeta}
              ${isClaim && parseFloat(appt.balance_due) > 0 ? `<span class="badge bg-warning-subtle text-warning small" style="font-size:0.68rem;">Bal: ₱${parseFloat(appt.balance_due).toLocaleString()}</span>` : `<span class="badge bg-dark-subtle text-muted small" style="font-size:0.68rem;">Rx: ${appt.rx_count || 0}</span>`}
            </div>
          `;

          card.addEventListener('click', () => openAppointmentModal(appt));
          eventsList.appendChild(card);
        });
      }

      col.appendChild(eventsList);
      weekViewContainer.appendChild(col);
    });
  }

  // ── 3. RENDER AGENDA VIEW ──────────────────────────────────────
  function renderAgenda() {
    agendaViewContainer.innerHTML = '';
    const filteredAppts = getCalendarAppointments();

    calTitle.textContent = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    calSubtitle.textContent = 'Agenda Timeline &middot; Chronological Patient List';

    if (filteredAppts.length === 0) {
      agendaViewContainer.innerHTML = `
        <div class="text-center py-5">
          <i class="fas fa-calendar-times fa-3x text-muted mb-3" style="opacity:0.4;"></i>
          <h6 class="text-white">No Appointments Found</h6>
          <p class="text-muted small">Try switching filters or toggling walk-ins.</p>
        </div>
      `;
      return;
    }

    const grouped = {};
    filteredAppts.forEach(appt => {
      if (!grouped[appt.appointment_date]) grouped[appt.appointment_date] = [];
      grouped[appt.appointment_date].push(appt);
    });

    const dates = Object.keys(grouped).sort();

    dates.forEach(dateStr => {
      const appts = grouped[dateStr];
      const dateObj = new Date(dateStr + 'T00:00:00');
      const isToday = (dateStr === formatDateIso(new Date()));

      const groupDiv = document.createElement('div');
      groupDiv.className = 'cal-agenda-group';

      groupDiv.innerHTML = `
        <div class="cal-agenda-group-header">
          <h6 class="cal-agenda-date-title">
            <i class="fas fa-calendar-day text-primary"></i>
            ${formatDisplayDate(dateObj)}
            ${isToday ? '<span class="badge bg-primary text-white ms-2">Today</span>' : ''}
          </h6>
          <span class="badge bg-secondary-subtle border border-secondary-subtle text-secondary">${appts.length} patient${appts.length>1?'s':''}</span>
        </div>
      `;

      const itemsWrap = document.createElement('div');
      itemsWrap.className = 'cal-agenda-items';

      appts.forEach(appt => {
        const isClaim = isApptClaim(appt);
        const isWalkin = (appt.appointment_type === 'WALK_IN');
        const item = document.createElement('div');
        item.className = 'cal-agenda-item';

        let badgeTypeHtml = isClaim
          ? '<span class="cal-claim-badge ms-1"><i class="fas fa-glasses me-1"></i>CLAIM</span>'
          : (isWalkin ? '<span class="cal-walkin-badge ms-1">Walk-in</span>' : '<span class="cal-booking-badge ms-1">Booking</span>');

        item.innerHTML = `
          <div class="cal-agenda-left">
            <div class="cal-agenda-time-badge">
              <i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}
            </div>
            <div class="cal-agenda-patient-info">
              <h6>${escapeHtml(appt.patient_name)} ${badgeTypeHtml}</h6>
              <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><i class="fas fa-tag me-1"></i>${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</span>
                ${isClaim && parseFloat(appt.balance_due) > 0 ? `<span class="badge bg-warning-subtle text-warning border border-warning-subtle small">Bal: ₱${parseFloat(appt.balance_due).toLocaleString()}</span>` : ''}
                <span class="text-muted small"><i class="fas fa-phone-alt me-1"></i>${escapeHtml(appt.patient_phone || 'No phone')}</span>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${getStatusBadgeHtml(appt.status)}
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-1 btn-view-appt">
              <i class="fas fa-eye me-1"></i> Manage
            </button>
          </div>
        `;

        item.querySelector('.btn-view-appt').addEventListener('click', (e) => {
          e.stopPropagation();
          openAppointmentModal(appt);
        });

        item.addEventListener('click', () => openAppointmentModal(appt));
        itemsWrap.appendChild(item);
      });

      groupDiv.appendChild(itemsWrap);
      agendaViewContainer.appendChild(groupDiv);
    });
  }

  // ── 4. RENDER QUEUE / TABLE VIEW ───────────────────────────────
  function renderTable() {
    tableBody.innerHTML = '';
    const todayIso = formatDateIso(new Date());

    // 1. Filter by Queue Segment
    let segmentAppts = rawAppointments.filter(appt => {
      const isClaim = isApptClaim(appt);
      const isWalkin = (appt.appointment_type === 'WALK_IN');
      const isPastDate = (appt.appointment_date < todayIso);
      const isToday = (appt.appointment_date === todayIso);
      const isFutureDate = (appt.appointment_date > todayIso);
      const isFinished = (appt.status === 'completed' || appt.status === 'cancelled' || appt.status === 'no_show');

      if (queueSegment === 'walkin') {
        // Today's Walk-in checkup queue
        // A walk-in patient is active until POS checkout is completed (appt.sale_id exists) or cancelled/no-show
        if (currentFilter !== 'all') {
          return isToday && isWalkin && !isClaim && (appt.status === currentFilter);
        }
        const isBilled = !!appt.sale_id;
        const isDropped = (appt.status === 'cancelled' || appt.status === 'no_show');
        return isToday && isWalkin && !isClaim && !isBilled && !isDropped;
      } else if (queueSegment === 'claims') {
        // Eyeglass claims queue (all active or pending pickup)
        if (currentFilter !== 'all') {
          return isClaim && (appt.status === currentFilter);
        }
        return isClaim && !isFinished;
      } else if (queueSegment === 'upcoming') {
        // Future scheduled bookings & pickups
        if (currentFilter !== 'all') {
          return isFutureDate && (appt.status === currentFilter);
        }
        return isFutureDate && !isFinished;
      } else if (queueSegment === 'history') {
        // Past due & completed records
        if (currentFilter !== 'all') {
          return (isPastDate || isFinished || !!appt.sale_id) && (appt.status === currentFilter);
        }
        return isPastDate || isFinished || !!appt.sale_id;
      }
      return true;
    });

    // 2. Filter by search query
    if (searchQuery.trim() !== '') {
      const q = searchQuery.toLowerCase();
      segmentAppts = segmentAppts.filter(appt => {
        const patientName = (appt.patient_name || '').toLowerCase();
        const phone = (appt.patient_phone || '').toLowerCase();
        const purpose = (appt.purpose || '').toLowerCase();
        const notes = (appt.notes || '').toLowerCase();
        const jo = (appt.job_order_no || '').toLowerCase();
        const inv = (appt.invoice_no || '').toLowerCase();
        return patientName.includes(q) || phone.includes(q) || purpose.includes(q) || notes.includes(q) || jo.includes(q) || inv.includes(q);
      });
    }

    // 3. Dynamic Title & Subtitle based on Queue Segment
    if (queueSegment === 'walkin') {
      calTitle.textContent = "Today's Walk-in Queue";
      calSubtitle.innerHTML = `Live Walk-in Checkup Queue for Dr. Exam &amp; Fitting &middot; ${segmentAppts.length} patient(s)`;
    } else if (queueSegment === 'claims') {
      calTitle.textContent = "Eyeglass Claims & Fitting Queue";
      calSubtitle.innerHTML = `Patients scheduled to claim fabricated eyeglasses &middot; ${segmentAppts.length} record(s)`;
    } else if (queueSegment === 'upcoming') {
      calTitle.textContent = "Upcoming Advance Bookings";
      calSubtitle.innerHTML = `Future Scheduled Appointments &middot; ${segmentAppts.length} record(s)`;
    } else if (queueSegment === 'history') {
      calTitle.textContent = "Past Due & Completed Records";
      calSubtitle.innerHTML = `Fulfilled claims, finished checkups, and past records &middot; ${segmentAppts.length} record(s)`;
    }

    // 4. Sort order tailored to segment
    segmentAppts.sort((a, b) => {
      if (queueSegment === 'walkin') {
        const aRxDone = (a.status === 'completed' || parseInt(a.today_rx_count || a.rx_count || 0, 10) > 0) && !a.sale_id;
        const bRxDone = (b.status === 'completed' || parseInt(b.today_rx_count || b.rx_count || 0, 10) > 0) && !b.sale_id;
        if (aRxDone && !bRxDone) return -1;
        if (!aRxDone && bRxDone) return 1;
        return (a.appointment_time || '').localeCompare(b.appointment_time || '');
      } else if (queueSegment === 'history') {
        if (a.appointment_date !== b.appointment_date) {
          return b.appointment_date.localeCompare(a.appointment_date);
        }
        return (b.appointment_time || '').localeCompare(a.appointment_time || '');
      } else {
        if (a.appointment_date !== b.appointment_date) {
          return a.appointment_date.localeCompare(b.appointment_date);
        }
        return (a.appointment_time || '').localeCompare(b.appointment_time || '');
      }
    });

    // 5. Empty State
    if (segmentAppts.length === 0) {
      let emptyMsg = 'No matching appointments in this queue.';
      let emptyIcon = 'fa-search';
      if (queueSegment === 'walkin') {
        emptyMsg = 'No active walk-in patients in queue for today.';
        emptyIcon = 'fa-walking';
      } else if (queueSegment === 'claims') {
        emptyMsg = 'No pending eyeglass claims in queue.';
        emptyIcon = 'fa-glasses';
      } else if (queueSegment === 'upcoming') {
        emptyMsg = 'No upcoming bookings scheduled.';
        emptyIcon = 'fa-calendar-alt';
      } else if (queueSegment === 'history') {
        emptyMsg = 'No past due or completed appointments found.';
        emptyIcon = 'fa-history';
      }

      tableBody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5 text-muted">
            <div class="mb-2"><i class="fas ${emptyIcon} fa-2x opacity-50"></i></div>
            <div class="fw-semibold">${emptyMsg}</div>
          </td>
        </tr>
      `;
      return;
    }

    // 6. Render Rows
    segmentAppts.forEach((appt, idx) => {
      const isClaim = isApptClaim(appt);
      const isWalkin = (appt.appointment_type === 'WALK_IN');
      const isPastDate = (appt.appointment_date < todayIso);
      const isUnfinishedPast = isPastDate && (appt.status !== 'completed' && appt.status !== 'cancelled' && appt.status !== 'no_show');
      const isRxReadyForPos = !isClaim && !appt.sale_id && (appt.status === 'completed' || parseInt(appt.today_rx_count || appt.rx_count || 0, 10) > 0) && appt.status !== 'cancelled' && appt.status !== 'no_show';

      const tr = document.createElement('tr');
      tr.id = 'appt-row-' + appt.id;
      tr.dataset.apptId = appt.id;
      if (highlightApptId && parseInt(appt.id, 10) === highlightApptId) {
        tr.classList.add('appt-highlight-pulse');
      }
      if (isRxReadyForPos) {
        tr.classList.add('rx-ready-row');
      }
      const apptDateObj = new Date(appt.appointment_date + 'T00:00:00');

      // Type Badge
      let typeBadge = '';
      if (isClaim) {
        typeBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fas fa-glasses me-1"></i>Eyeglass Claim</span>';
      } else if (isWalkin) {
        typeBadge = '<span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1"><i class="fas fa-walking me-1"></i>Walk-in Checkup</span>';
      } else {
        typeBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="fas fa-calendar-check me-1"></i>Scheduled Exam</span>';
      }

      // Job Order / Invoice info
      let joInvHtml = '<span class="text-muted small">—</span>';
      if (appt.job_order_no || appt.invoice_no) {
        joInvHtml = `
          <div>${appt.job_order_no ? `<span class="badge bg-light text-dark border"><i class="fas fa-barcode me-1"></i>${escapeHtml(appt.job_order_no)}</span>` : ''}</div>
          ${appt.invoice_no ? `<small class="text-muted"><i class="fas fa-receipt me-1"></i>${escapeHtml(appt.invoice_no)}</small>` : ''}
        `;
      }

      // Payment Status
      let paymentHtml = '<span class="text-muted small">—</span>';
      const bal = parseFloat(appt.balance_due || 0);
      if (bal > 0) {
        paymentHtml = `<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fas fa-exclamation-circle me-1"></i>Bal: ₱${bal.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>`;
      } else if (appt.order_status === 'paid' || appt.sale_id) {
        paymentHtml = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fas fa-check-circle me-1"></i>Paid</span>';
      } else if (appt.payment_type) {
        paymentHtml = `<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">${escapeHtml(appt.payment_type.replace('_', ' '))}</span>`;
      }

      // Status column with Rx Ready highlight
      let statusBadgeHtml = getStatusBadgeHtml(appt.status);
      if (isRxReadyForPos) {
        statusBadgeHtml = '<span class="badge bg-success text-white py-1 px-2 shadow-sm"><i class="fas fa-check-circle me-1"></i>Prescription Ready · Ready for POS</span>';
      }

      // Action buttons
      let actionButtons = `
        <div class="d-flex gap-1 flex-wrap align-items-center">
          <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1 btn-open-table-modal" title="Manage / View Details">
            <i class="fas fa-eye"></i>
          </button>
      `;

      if (isClaim) {
        if (appt.status !== 'completed' && appt.status !== 'cancelled' && appt.status !== 'no_show') {
          actionButtons += `
            <button type="button" class="btn btn-success btn-sm px-2 py-1 btn-quick-claim" data-id="${appt.id}" title="Complete Claim & Handover Eyeglasses">
              <i class="fas fa-check-double me-1"></i> Claim Done
            </button>
          `;
        }
        if (bal > 0 || !appt.sale_id) {
          actionButtons += `
            <a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-primary btn-sm px-2 py-1" title="POS Collect / Billing">
              <i class="fas fa-cash-register"></i>
            </a>
          `;
        } else if (appt.sale_id) {
          actionButtons += `
            <a href="receipt.php?id=${appt.sale_id}" target="_blank" class="btn btn-outline-secondary btn-sm px-2 py-1" title="View Receipt">
              <i class="fas fa-file-invoice"></i>
            </a>
          `;
        }
      } else {
        if (appt.sale_id) {
          actionButtons += `
            <a href="receipt.php?id=${appt.sale_id}" target="_blank" class="btn btn-outline-secondary btn-sm px-2 py-1" title="View Receipt">
              <i class="fas fa-file-invoice me-1"></i> Receipt
            </a>
          `;
        } else if (isRxReadyForPos) {
          actionButtons += `
            <a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-success btn-sm px-2 py-1 fw-bold shadow-sm" title="Doctor Exam Complete: Proceed to Frame Selection & POS Checkout">
              <i class="fas fa-shopping-cart me-1"></i> POS Checkout
            </a>
          `;
        } else if (appt.status === 'in_progress') {
          actionButtons += `
            <button type="button" class="btn btn-secondary btn-sm px-2 py-1 disabled" style="opacity:0.75;" title="Exam in progress with Doctor">
              <i class="fas fa-stethoscope me-1"></i> In Exam
            </button>
          `;
        } else if (appt.status === 'cancelled' || appt.status === 'no_show') {
          // No action
        } else {
          actionButtons += `
            <a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-primary btn-sm px-2 py-1" title="POS Checkout">
              <i class="fas fa-shopping-cart me-1"></i> POS
            </a>
          `;
        }
      }
      const pNotesRow = parseApptNotes(appt.notes);
      let claimProdsSnippet = '';
      if (isClaim && pNotesRow.availedProducts && pNotesRow.availedProducts.length > 0) {
        claimProdsSnippet = `
          <div class="mt-1" style="font-size:0.75rem; line-height:1.3; color:#059669;">
            <i class="fas fa-box-open me-1"></i><strong>Claim Items:</strong> ${escapeHtml(pNotesRow.availedProducts.slice(0, 2).join(', '))}${pNotesRow.availedProducts.length > 2 ? ' ...' : ''}
          </div>
        `;
      }

      tr.innerHTML = `
        <td class="text-muted fw-bold">${idx + 1}</td>
        <td>
          <div class="fw-bold cal-modal-title">${escapeHtml(appt.patient_name)}</div>
          <small class="text-muted">${escapeHtml(appt.patient_phone || 'No phone')}</small>
          ${claimProdsSnippet}
        </td>
        <td>${typeBadge}</td>
        <td>
          <div>${formatDisplayDate(apptDateObj)}</div>
          <small class="fw-bold text-primary">${formatTime12(appt.appointment_time)}</small>
          ${isUnfinishedPast ? '<span class="cal-past-due-badge ms-1"><i class="fas fa-exclamation-circle"></i> Past Due</span>' : ''}
        </td>
        <td>${joInvHtml}</td>
        <td>${paymentHtml}</td>
        <td>${statusBadgeHtml}</td>
        <td>${actionButtons}</td>
      `;

      tr.querySelector('.btn-open-table-modal').addEventListener('click', () => {
        openAppointmentModal(appt);
      });

      const quickClaimBtn = tr.querySelector('.btn-quick-claim');
      if (quickClaimBtn) {
        quickClaimBtn.addEventListener('click', () => {
          if (confirm(`Confirm handover and complete eyeglass claim for ${appt.patient_name}?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="complete_claim">
              <input type="hidden" name="appt_id" value="${appt.id}">
              <input type="hidden" name="current_view_date" value="${appt.appointment_date || ''}">
            `;
            document.body.appendChild(form);
            form.submit();
          }
        });
      }

      tableBody.appendChild(tr);
    });
  }

  // ── Slots Today Monitor Renderer ─────────────────────────────
  function renderSlotsModal(dateStr) {
    if (!dateStr) dateStr = formatDateIso(new Date());
    const filterInput = document.getElementById('slotsFilterDateInput');
    if (filterInput && filterInput.value !== dateStr) {
      filterInput.value = dateStr;
    }

    const dateObj = new Date(dateStr + 'T00:00:00');
    const dateFormatted = !isNaN(dateObj.getTime())
      ? dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
      : dateStr;

    const todayIso = formatDateIso(new Date());
    const headingEl = document.getElementById('slotsModalHeading');
    if (headingEl) {
      headingEl.textContent = (dateStr === todayIso)
        ? `Slots Today · ${dateFormatted}`
        : `Slots Schedule · ${dateFormatted}`;
    }

    let takenCount = 0;
    const slotStatusList = allSlots.map(slot => {
      const matching = getApptsForSlot(slot, dateStr);
      const isTaken = matching.length > 0;
      if (isTaken) takenCount++;
      return { slot, isTaken, appts: matching };
    });

    const availCount = Math.max(0, allSlots.length - takenCount);
    const availText = document.getElementById('slotsAvailText');
    const takenText = document.getElementById('slotsTakenText');
    const fullyBookedBox = document.getElementById('slotsFullyBookedBox');

    if (availText) availText.textContent = `${availCount} of ${allSlots.length} slots available`;
    if (takenText) takenText.textContent = `${takenCount} slots taken`;
    if (fullyBookedBox) {
      fullyBookedBox.style.display = (availCount === 0) ? 'flex' : 'none';
    }

    const amList = slotStatusList.filter(s => parseInt(s.slot, 10) < 12);
    const pmList = slotStatusList.filter(s => parseInt(s.slot, 10) >= 12);

    const renderGroup = (containerId, list) => {
      const container = document.getElementById(containerId);
      if (!container) return;
      container.innerHTML = '';

      list.forEach(item => {
        const { slot, isTaken, appts } = item;
        const { time, period } = formatSlotDisplay(slot);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn' + (isTaken ? ' is-taken' : '');

        if (isTaken) {
          const appt = appts[0];
          const patName = appt ? (appt.patient_name || 'Booked Patient') : 'Booked';
          const isClaim = appt ? isApptClaim(appt) : false;
          const isWalkin = (appt && appt.appointment_type === 'WALK_IN');
          const typeBadge = isClaim ? 'Claim' : (isWalkin ? 'Walk-in' : 'Checkup');

          btn.title = `Slot Taken: ${patName} (${typeBadge}) · Click to view appointment details`;
          btn.innerHTML = `
            <span class="slot-time-text">${time}</span>
            <span class="slot-period booked"><i class="fas fa-lock me-1"></i>Slot Taken</span>
            <span class="slot-patient-tag" title="${escapeHtml(patName)}">${escapeHtml(patName)}</span>
            <span class="badge ${isClaim ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle'}" style="font-size:0.6rem;padding:2px 5px;border-radius:4px;margin-top:2px;">
              ${typeBadge}
            </span>
          `;

          btn.addEventListener('click', () => {
            const modalEl = document.getElementById('slotsTodayModal');
            const instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) instance.hide();
            setTimeout(() => {
              openAppointmentModal(appt);
            }, 300);
          });
        } else {
          btn.title = `Available slot at ${time} ${period}`;
          btn.innerHTML = `
            <span class="slot-time-text">${time}</span>
            <span class="slot-period" style="color:#10B981;font-weight:700;"><i class="fas fa-check-circle me-1"></i>Available</span>
            <span class="text-muted" style="font-size:0.68rem;margin-top:2px;">${period} Slot</span>
          `;
        }

        container.appendChild(btn);
      });
    };

    renderGroup('slotsGridMorning', amList);
    renderGroup('slotsGridAfternoon', pmList);
  }

  // ── Master Render Trigger ──────────────────────────────────────
  function render() {
    updateCounts();
    if (document.getElementById('slotsTodayModal')?.classList.contains('show')) {
      const activeFilterDate = document.getElementById('slotsFilterDateInput')?.value || formatDateIso(new Date());
      renderSlotsModal(activeFilterDate);
    }
    monthViewContainer.style.display = (currentView === 'month') ? 'block' : 'none';
    weekViewContainer.style.display = (currentView === 'week') ? 'grid' : 'none';
    agendaViewContainer.style.display = (currentView === 'agenda') ? 'flex' : 'none';
    tableViewContainer.style.display = (currentView === 'table') ? 'block' : 'none';

    if (currentView === 'month') renderMonth();
    else if (currentView === 'week') renderWeek();
    else if (currentView === 'agenda') renderAgenda();
    else if (currentView === 'table') renderTable();
  }

  // ── Type Filter Buttons (All / Eyeglass Claims / Scheduled Checkups) ──
  typeFilterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      typeFilterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentBookingType = btn.dataset.type || 'all';
      render();
    });
  });

  // ── Queue Segment Tabs ─────────────────────────────────────────
  const queueTabBtns = document.querySelectorAll('.cal-queue-tab-btn');
  queueTabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      queueTabBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      queueSegment = btn.dataset.segment;
      // Reset status filter to 'all' so user sees full segment list
      currentFilter = 'all';
      if (calStatusSelect) calStatusSelect.value = 'all';
      render();
    });
  });

  // ── Navigation Buttons ─────────────────────────────────────────
  btnToday.addEventListener('click', () => {
    currentDate = new Date();
    selectedDateStr = formatDateIso(new Date());
    if (currentView === 'table') {
      queueSegment = 'walkin';
      queueTabBtns.forEach(b => b.classList.toggle('active', b.dataset.segment === 'walkin'));
      currentFilter = 'all';
      if (calStatusSelect) calStatusSelect.value = 'all';
    }
    render();
  });

  btnPrev.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda' || currentView === 'table') {
      currentDate.setMonth(currentDate.getMonth() - 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() - 7);
    }
    render();
  });

  btnNext.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda' || currentView === 'table') {
      currentDate.setMonth(currentDate.getMonth() + 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() + 7);
    }
    render();
  });

  // View Switchers
  const allViewBtns = [viewBtnMonth, viewBtnWeek, viewBtnAgenda, viewBtnTable];
  allViewBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      allViewBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentView = btn.dataset.view;
      if (currentView === 'table') {
        currentFilter = 'all';
        if (calStatusSelect) calStatusSelect.value = 'all';
      }
      render();
    });
  });

  // Status Filter Dropdown
  if (calStatusSelect) {
    calStatusSelect.value = currentFilter;
    calStatusSelect.addEventListener('change', () => {
      currentFilter = calStatusSelect.value;
      render();
    });
  }

  // Search Input
  searchInput.addEventListener('input', (e) => {
    searchQuery = e.target.value;
    render();
  });

  // ── Appointment Details Modal Logic ────────────────────────────
  function openAppointmentModal(appt) {
    document.getElementById('modalAvatar').textContent = (appt.patient_name || 'U').charAt(0).toUpperCase();
    document.getElementById('modalPatientName').textContent = appt.patient_name;
    document.getElementById('modalPatientMeta').textContent = `Patient ID: #${appt.patient_id} · ${appt.patient_phone || 'No phone recorded'}`;
    
    document.getElementById('modalStatusBadge').innerHTML = getStatusBadgeHtml(appt.status);

    const isClaim = isApptClaim(appt);
    const isWalkin = (appt.appointment_type === 'WALK_IN');
    const walkinBadge = document.getElementById('modalWalkinBadge');
    if (walkinBadge) {
      if (isClaim) {
        walkinBadge.innerHTML = '<span class="badge bg-success text-white px-2 py-1"><i class="fas fa-glasses me-1"></i>Eyeglass Claim</span>';
      } else if (isWalkin) {
        walkinBadge.innerHTML = '<span class="badge bg-warning text-dark px-2 py-1"><i class="fas fa-walking me-1"></i>Walk-in</span>';
      } else {
        walkinBadge.innerHTML = '<span class="badge bg-primary text-white px-2 py-1"><i class="fas fa-calendar-check me-1"></i>Scheduled Booking</span>';
      }
    }

    const apptDateObj = new Date(appt.appointment_date + 'T00:00:00');
    document.getElementById('modalDate').textContent = formatDisplayDate(apptDateObj);
    document.getElementById('modalTime').textContent = formatTime12(appt.appointment_time);

    document.getElementById('modalPhone').textContent = appt.patient_phone || '—';
    document.getElementById('modalEmail').textContent = appt.patient_email || '—';
    document.getElementById('modalGenderAge').textContent = (appt.patient_gender ? (appt.patient_gender.charAt(0).toUpperCase() + appt.patient_gender.slice(1)) : '—');
    document.getElementById('modalRxCount').textContent = `${appt.rx_count || 0} Prescription(s)`;

    const pNotes = parseApptNotes(appt.notes);
    // Purpose: show high-level purpose + specific service subtitle
    let purposeHtml = `<span>${escapeHtml((appt.purpose || '').replace(/_/g, ' ').toUpperCase())}</span>`;
    if (pNotes.service) {
      purposeHtml += `<br><small class="text-muted fst-italic fw-normal">${escapeHtml(pNotes.service)}</small>`;
    }
    document.getElementById('modalPurpose').innerHTML = purposeHtml;

    let modalNotesHtml = '';
    if (pNotes.hasUserNotes) {
      modalNotesHtml += `<div>${escapeHtml(pNotes.userNotes)}</div>`;
    } else {
      modalNotesHtml += `<span class="text-muted fst-italic"><i class="fas fa-info-circle me-1"></i> No special notes entered for this appointment.</span>`;
    }
    document.getElementById('modalNotes').innerHTML = modalNotesHtml;

    // Eyeglass Claim Quick Order Summary Bar
    const claimOrderSummary = document.getElementById('modalClaimOrderSummary');
    const modalClaimJoNo = document.getElementById('modalClaimJoNo');
    const modalClaimInvNo = document.getElementById('modalClaimInvNo');
    const modalClaimBalanceDueWrap = document.getElementById('modalClaimBalanceDueWrap');

    // Claim Workflow Buttons & Actions
    const btnPos = document.getElementById('modalBtnPos');
    const lockAlert = document.getElementById('modalConsultationLockAlert');
    const claimActionBox = document.getElementById('modalClaimActionBox');
    const claimStatusBadge = document.getElementById('modalClaimStatusBadge');
    const claimNoticeText = document.getElementById('modalClaimNoticeText');
    const formConfirmClaim = document.getElementById('formConfirmClaim');
    const confirmClaimApptId = document.getElementById('confirmClaimApptId');
    const confirmClaimCurrentDate = document.getElementById('confirmClaimCurrentDate');
    const formReadyClaim = document.getElementById('formReadyClaim');
    const readyClaimApptId = document.getElementById('readyClaimApptId');
    const readyClaimCurrentDate = document.getElementById('readyClaimCurrentDate');
    const formCompleteClaim = document.getElementById('formCompleteClaim');
    const completeClaimApptId = document.getElementById('completeClaimApptId');
    const completeClaimCurrentDate = document.getElementById('completeClaimCurrentDate');
    const formCancelClaim = document.getElementById('formCancelClaim');
    const cancelClaimApptId = document.getElementById('cancelClaimApptId');
    const cancelClaimCurrentDate = document.getElementById('cancelClaimCurrentDate');

    const isConsultation = !isClaim && ((appt.purpose||'').toLowerCase().includes('consultation') || (appt.purpose||'').toLowerCase().includes('eye_exam') || (appt.purpose||'').toLowerCase().includes('checkup'));
    const isExamCompleted = appt.status === 'completed' || (parseInt(appt.today_rx_count || appt.rx_count || 0, 10) > 0 && appt.status !== 'pending' && appt.status !== 'in_progress');
    const isCancelledOrNoShow = appt.status === 'cancelled' || appt.status === 'no_show';
    const hasSale = !!appt.sale_id;

    // Eyeglass Claim Front Desk Actions Management
    if (claimActionBox) {
      if (isClaim) {
        claimActionBox.classList.remove('d-none');
        claimActionBox.classList.add('d-block');

        if (claimOrderSummary) claimOrderSummary.style.display = 'flex';
        if (modalClaimJoNo) modalClaimJoNo.textContent = appt.job_order_no || '—';
        if (modalClaimInvNo) modalClaimInvNo.textContent = appt.invoice_no || '—';
        if (modalClaimBalanceDueWrap) {
          const bal = parseFloat(appt.balance_due || 0);
          if (bal > 0) {
            modalClaimBalanceDueWrap.innerHTML = `<span class="badge bg-warning text-dark px-2 py-1"><i class="fas fa-exclamation-circle me-1"></i> Balance Due: ₱${bal.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>`;
          } else if (appt.order_status === 'paid') {
            modalClaimBalanceDueWrap.innerHTML = `<span class="badge bg-success text-white px-2 py-1"><i class="fas fa-check-circle me-1"></i> Fully Paid</span>`;
          } else {
            modalClaimBalanceDueWrap.innerHTML = `<span class="text-muted small">No balance due</span>`;
          }
        }

        // Populate Availed Products List for this claim
        const prodBox = document.getElementById('modalClaimProductsBox');
        const prodList = document.getElementById('modalClaimProductsList');
        if (prodBox && prodList) {
          if (pNotes.availedProducts && pNotes.availedProducts.length > 0) {
            prodBox.style.display = 'block';
            prodList.innerHTML = pNotes.availedProducts.map(p => `
              <div class="d-flex align-items-center gap-2 py-1 border-bottom border-light">
                <i class="fas fa-check-circle text-success small flex-shrink-0"></i>
                <span class="fw-semibold">${escapeHtml(p)}</span>
              </div>
            `).join('');
          } else {
            prodBox.style.display = 'none';
            prodList.innerHTML = '';
          }
        }

        // Populate Form IDs and current dates
        if (confirmClaimApptId) confirmClaimApptId.value = appt.id;
        if (confirmClaimCurrentDate) confirmClaimCurrentDate.value = appt.appointment_date || '';
        if (readyClaimApptId) readyClaimApptId.value = appt.id;
        if (readyClaimCurrentDate) readyClaimCurrentDate.value = appt.appointment_date || '';
        if (completeClaimApptId) completeClaimApptId.value = appt.id;
        if (completeClaimCurrentDate) completeClaimCurrentDate.value = appt.appointment_date || '';
        if (cancelClaimApptId) cancelClaimApptId.value = appt.id;
        if (cancelClaimCurrentDate) cancelClaimCurrentDate.value = appt.appointment_date || '';

        if (appt.status === 'pending') {
          claimStatusBadge.className = 'badge bg-warning text-dark px-2 py-1';
          claimStatusBadge.innerHTML = '<i class="fas fa-clock me-1"></i> Waiting Confirmation';
          claimNoticeText.innerHTML = 'This booking was scheduled for an eyeglass claim or fitting. No doctor checkup required. You can <strong>Accept &amp; Confirm</strong> this booking right now.';
          if (formConfirmClaim) formConfirmClaim.style.display = 'inline-block';
          if (formReadyClaim) formReadyClaim.style.display = 'none';
          if (formCompleteClaim) formCompleteClaim.style.display = 'inline-block';
          if (formCancelClaim) formCancelClaim.style.display = 'inline-block';
        } else if (appt.status === 'confirmed') {
          claimStatusBadge.className = 'badge bg-info text-white px-2 py-1';
          claimStatusBadge.innerHTML = '<i class="fas fa-check-circle me-1"></i> Ready for Fitting / Pickup';
          claimNoticeText.innerHTML = 'Eyeglasses are scheduled for pickup &amp; fitting. If fabricated glasses are now available in the cabinet, mark <strong>Ready for Pickup</strong>. Once handed over, click <strong>Mark as Claimed / Done</strong>.';
          if (formConfirmClaim) formConfirmClaim.style.display = 'none';
          if (formReadyClaim) formReadyClaim.style.display = (appt.order_status !== 'ready_for_pickup') ? 'inline-block' : 'none';
          if (formCompleteClaim) formCompleteClaim.style.display = 'inline-block';
          if (formCancelClaim) formCancelClaim.style.display = 'inline-block';
        } else if (appt.status === 'completed') {
          claimStatusBadge.className = 'badge bg-success text-white px-2 py-1';
          claimStatusBadge.innerHTML = '<i class="fas fa-check-double me-1"></i> Claim Completed';
          claimNoticeText.innerHTML = '<span class="text-success fw-bold"><i class="fas fa-check-circle me-1"></i> Eyeglasses have been handed over to the patient and claim is marked completed.</span>';
          if (formConfirmClaim) formConfirmClaim.style.display = 'none';
          if (formReadyClaim) formReadyClaim.style.display = 'none';
          if (formCompleteClaim) formCompleteClaim.style.display = 'none';
          if (formCancelClaim) formCancelClaim.style.display = 'none';
        } else {
          claimStatusBadge.className = 'badge bg-secondary text-white px-2 py-1';
          claimStatusBadge.innerHTML = appt.status === 'cancelled' ? 'Booking Cancelled' : 'No-Show Recorded';
          claimNoticeText.innerHTML = `This eyeglass claim appointment was marked as ${appt.status === 'cancelled' ? 'cancelled' : 'no-show'}.`;
          if (formConfirmClaim) formConfirmClaim.style.display = 'none';
          if (formReadyClaim) formReadyClaim.style.display = 'none';
          if (formCompleteClaim) formCompleteClaim.style.display = 'none';
          if (formCancelClaim) formCancelClaim.style.display = 'none';
        }
      } else {
        claimActionBox.classList.remove('d-block');
        claimActionBox.classList.add('d-none');
        if (claimOrderSummary) claimOrderSummary.style.display = 'none';
        const prodBox = document.getElementById('modalClaimProductsBox');
        if (prodBox) prodBox.style.display = 'none';
        if (formConfirmClaim) formConfirmClaim.style.display = 'none';
        if (formReadyClaim) formReadyClaim.style.display = 'none';
        if (formCompleteClaim) formCompleteClaim.style.display = 'none';
        if (formCancelClaim) formCancelClaim.style.display = 'none';
      }
    }

    // POS & Consultation Lock Management
    if (isClaim) {
      if (lockAlert) {
        lockAlert.classList.remove('d-flex');
        lockAlert.classList.add('d-none');
        lockAlert.style.display = 'none';
      }

      if (hasSale) {
        btnPos.href = `receipt.php?id=${appt.sale_id}`;
        btnPos.target = '_blank';
        btnPos.className = 'btn btn-success btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = '';
        btnPos.style.opacity = '1';
        btnPos.innerHTML = `<i class="fas fa-file-invoice me-1"></i> View Receipt (${escapeHtml(appt.invoice_no || '#' + appt.sale_id)})`;
      } else if (appt.status === 'completed') {
        btnPos.removeAttribute('href');
        btnPos.target = '_self';
        btnPos.className = 'btn btn-success btn-sm w-100 py-2 text-nowrap disabled text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = 'none';
        btnPos.style.opacity = '0.95';
        btnPos.innerHTML = `<i class="fas fa-check-double me-1"></i> Claim &amp; Handover Completed`;
      } else if (isCancelledOrNoShow) {
        btnPos.removeAttribute('href');
        btnPos.target = '_self';
        btnPos.className = 'btn btn-secondary btn-sm w-100 py-2 text-nowrap disabled text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = 'none';
        btnPos.style.opacity = '0.65';
        btnPos.innerHTML = `<i class="fas fa-ban me-1"></i> ${appt.status === 'cancelled' ? 'Appointment Cancelled' : 'No-Show Recorded'}`;
      } else {
        btnPos.href = `pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}`;
        btnPos.target = '_self';
        btnPos.className = 'btn btn-primary btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = '';
        btnPos.style.opacity = '1';
        btnPos.innerHTML = `<i class="fas fa-cash-register me-1"></i> Proceed to POS (Eyewear Billing)`;
      }
    } else {
      // Clinical Consultations flow
      if (hasSale) {
        if (lockAlert) {
          lockAlert.classList.remove('d-flex');
          lockAlert.classList.add('d-none');
          lockAlert.style.display = 'none';
        }
        btnPos.href = `receipt.php?id=${appt.sale_id}`;
        btnPos.target = '_blank';
        btnPos.className = 'btn btn-success btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = '';
        btnPos.style.opacity = '1';
        btnPos.innerHTML = `<i class="fas fa-file-invoice me-1"></i> View Receipt (${escapeHtml(appt.invoice_no || '#' + appt.sale_id)})`;
      } else if (isCancelledOrNoShow) {
        if (lockAlert) {
          lockAlert.classList.remove('d-flex');
          lockAlert.classList.add('d-none');
          lockAlert.style.display = 'none';
        }
        btnPos.removeAttribute('href');
        btnPos.target = '_self';
        btnPos.className = 'btn btn-secondary btn-sm w-100 py-2 text-nowrap disabled text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = 'none';
        btnPos.style.opacity = '0.65';
        btnPos.innerHTML = `<i class="fas fa-ban me-1"></i> ${appt.status === 'cancelled' ? 'Appointment Cancelled' : 'No-Show Recorded'}`;
      } else if (appt.status === 'completed' || isExamCompleted) {
        // Doctor Exam Completed! Prescription ready, awaiting frame/lens selection & POS checkout!
        if (lockAlert) {
          lockAlert.className = 'alert alert-success py-2 px-3 mb-3 d-flex align-items-center gap-2';
          lockAlert.style.border = '1.5px solid #10b981';
          lockAlert.style.background = 'rgba(16, 185, 129, 0.08)';
          lockAlert.innerHTML = '<i class="fas fa-check-circle fa-lg text-success flex-shrink-0"></i><div><strong>Doctor Exam Completed:</strong> Prescription is ready. Patient is ready for Frame &amp; Lens Selection and POS Checkout.</div>';
          lockAlert.classList.remove('d-none');
          lockAlert.classList.add('d-flex');
          lockAlert.style.display = 'flex';
        }
        btnPos.href = `pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}`;
        btnPos.target = '_self';
        btnPos.className = 'btn btn-success btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center fw-bold';
        btnPos.style.pointerEvents = '';
        btnPos.style.opacity = '1';
        btnPos.innerHTML = `<i class="fas fa-shopping-cart me-1"></i> Proceed to POS Checkout (Prescription Ready)`;
      } else if (appt.status === 'in_progress') {
        if (lockAlert) {
          lockAlert.className = 'alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2';
          lockAlert.style.border = '1.5px solid #0ea5e9';
          lockAlert.style.background = 'rgba(14, 165, 233, 0.08)';
          lockAlert.innerHTML = '<i class="fas fa-stethoscope fa-lg text-info flex-shrink-0"></i><div><strong>With Doctor (Examining):</strong> Optometrist is currently seeing the patient. POS unlocks once consultation is marked completed.</div>';
          lockAlert.classList.remove('d-none');
          lockAlert.classList.add('d-flex');
          lockAlert.style.display = 'flex';
        }
        btnPos.removeAttribute('href');
        btnPos.target = '_self';
        btnPos.className = 'btn btn-secondary btn-sm w-100 py-2 text-nowrap disabled text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = 'none';
        btnPos.style.opacity = '0.85';
        btnPos.innerHTML = `<i class="fas fa-stethoscope me-1"></i> In Consultation with Doctor`;
      } else {
        if (lockAlert) {
          lockAlert.className = 'alert alert-warning py-2 px-3 mb-3 d-flex align-items-center gap-2';
          lockAlert.style.border = '1.5px solid #f59e0b';
          lockAlert.style.background = 'rgba(245, 158, 11, 0.08)';
          lockAlert.innerHTML = '<i class="fas fa-clock fa-lg text-warning flex-shrink-0"></i><div><strong>Awaiting Doctor Examination:</strong> Patient is in queue for doctor checkup. You can still open POS if availing accessories/services directly.</div>';
          lockAlert.classList.remove('d-none');
          lockAlert.classList.add('d-flex');
          lockAlert.style.display = 'flex';
        }
        btnPos.href = `pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}`;
        btnPos.target = '_self';
        btnPos.className = 'btn btn-primary btn-sm w-100 py-2 text-nowrap shadow-sm text-center d-inline-flex align-items-center justify-content-center';
        btnPos.style.pointerEvents = '';
        btnPos.style.opacity = '1';
        btnPos.innerHTML = `<i class="fas fa-shopping-cart me-1"></i> Proceed to POS Checkout`;
      }
    }

    document.getElementById('modalBtnPatient').href = `patients.php?view=${appt.patient_id}`;

    appointmentModal.show();
  }

  // ── Day Schedule Queue Modal Logic ─────────────────────────────
  function openDayQueueModal(dateIso) {
    const dateObj = new Date(dateIso + 'T00:00:00');
    document.getElementById('dayModalTitle').textContent = `Appointments on ${formatDisplayDate(dateObj)}`;
    
    const dayAppts = rawAppointments.filter(a => a.appointment_date === dateIso);
    document.getElementById('dayModalSubtitle').textContent = `${dayAppts.length} total scheduled patient(s)`;

    const body = document.getElementById('dayModalBody');
    if (dayAppts.length === 0) {
      body.innerHTML = `
        <div class="text-center py-5">
          <i class="fas fa-calendar-check fa-3x text-muted mb-3" style="opacity:0.4;"></i>
          <h6 class="cal-modal-title fw-bold mb-1">No Appointments Scheduled</h6>
          <p class="text-muted small">There are no patient bookings recorded for this date.</p>
        </div>
      `;
    } else {
      let html = '<div class="d-flex flex-column gap-3">';
      dayAppts.forEach(appt => {
        const isClaimAppt = isApptClaim(appt);
        const isConsult = !isClaimAppt && ((appt.purpose||'').toLowerCase().includes('consultation') || (appt.purpose||'').toLowerCase().includes('eye_exam') || (appt.purpose||'').toLowerCase().includes('checkup'));
        const isDone = appt.status === 'completed' || (parseInt(appt.today_rx_count || appt.rx_count || 0, 10) > 0 && appt.status !== 'pending');

        let actionBtn = '';
        if (appt.sale_id) {
          actionBtn = `<a href="receipt.php?id=${appt.sale_id}" target="_blank" class="btn btn-success btn-sm px-3 shadow-sm" title="View Receipt"><i class="fas fa-file-invoice me-1"></i> Receipt</a>`;
        } else if (appt.status === 'completed' || isDone) {
          actionBtn = `<a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-success btn-sm px-3 shadow-sm fw-bold" title="Doctor Exam Complete: Proceed to POS"><i class="fas fa-shopping-cart me-1"></i> POS Checkout</a>`;
        } else if (appt.status === 'cancelled' || appt.status === 'no_show') {
          actionBtn = `<span class="badge bg-secondary py-2 px-3">${appt.status === 'cancelled' ? 'Cancelled' : 'No-Show'}</span>`;
        } else if (isConsult && appt.status === 'in_progress') {
          actionBtn = `<button type="button" class="btn btn-secondary btn-sm px-3 disabled" style="opacity:0.75;cursor:not-allowed;" title="In exam with Doctor"><i class="fas fa-stethoscope me-1"></i> In Exam</button>`;
        } else {
          actionBtn = `<a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-primary btn-sm px-3 shadow-sm" title="Proceed to Checkout"><i class="fas fa-shopping-cart me-1"></i> Checkout</a>`;
        }

        html += `
          <div class="cal-info-card p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="cal-agenda-time-badge" style="min-width:85px;">
                ${formatTime12(appt.appointment_time)}
              </div>
              <div>
                <h6 class="cal-modal-title fw-bold mb-1">
                  ${escapeHtml(appt.patient_name)}
                  ${isClaimAppt ? '<span class="cal-claim-badge ms-1"><i class="fas fa-glasses me-1"></i>CLAIM</span>' : ''}
                </h6>
                <small class="text-muted">
                  ${escapeHtml((appt.purpose||'').replace(/_/g, ' '))} &middot; 
                  ${escapeHtml(appt.patient_phone || 'No phone')}
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              ${getStatusBadgeHtml(appt.status)}
              <button type="button" class="btn btn-outline-primary btn-sm px-3 btn-open-single" data-id="${appt.id}">
                <i class="fas fa-eye me-1"></i> Manage
              </button>
              ${actionBtn}
            </div>
          </div>
        `;
      });
      html += '</div>';
      body.innerHTML = html;

      body.querySelectorAll('.btn-open-single').forEach(btn => {
        btn.addEventListener('click', () => {
          const apptId = parseInt(btn.dataset.id, 10);
          const found = rawAppointments.find(a => a.id === apptId);
          if (found) {
            dayQueueModal.hide();
            setTimeout(() => openAppointmentModal(found), 250);
          }
        });
      });
    }

    dayQueueModal.show();
  }

  // ── Register Walk-in Form Submission Handler ──────────────────
  const formRegisterWalkin = document.getElementById('formRegisterWalkin');
  if (formRegisterWalkin) {
    const walkinAlert = document.getElementById('walkinAlert');
    const btnSubmitWalkin = document.getElementById('btnSubmitWalkin');

    // Flatpickr Modern Birthdate Picker with Year Dropdown & Auto Age Calculation
    let walkinBirthPicker = null;
    const birthEl = document.getElementById('walkinInputBirthdate');
    const ageEl = document.getElementById('walkinInputAge');
    const genderEl = document.getElementById('walkinInputGender');

    function calculateAge(dateStr) {
      if (!dateStr) return '';
      const birth = new Date(dateStr);
      if (isNaN(birth.getTime())) return '';
      const today = new Date();
      let age = today.getFullYear() - birth.getFullYear();
      const m = today.getMonth() - birth.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--;
      }
      return (age >= 0 && age <= 130) ? age : '';
    }

    if (birthEl && typeof flatpickr !== 'undefined') {
      walkinBirthPicker = flatpickr(birthEl, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'F j, Y',
        altInputClass: 'form-control modern-birthdate-picker',
        minDate: '1900-01-01',
        maxDate: 'today',
        monthSelectorType: 'dropdown',
        disableMobile: true,
        placeholder: 'Select birthdate (Month / Day / Year)',
        onReady: function(selectedDates, dateStr, fp) {
          const monthElem = fp.calendarContainer.querySelector('.flatpickr-current-month');
          if (monthElem && !monthElem.querySelector('.flatpickr-year-custom-wrap')) {
            const wrap = document.createElement('div');
            wrap.className = 'flatpickr-year-custom-wrap';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'flatpickr-year-custom-btn';
            btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;

            const menu = document.createElement('div');
            menu.className = 'flatpickr-year-custom-menu d-none';

            const curYear = new Date().getFullYear();
            for (let y = curYear; y >= 1900; y--) {
              const item = document.createElement('div');
              item.className = 'flatpickr-year-custom-item' + (y === fp.currentYear ? ' active' : '');
              item.dataset.year = y;
              item.textContent = y;
              item.addEventListener('click', function(ev) {
                ev.stopPropagation();
                const chosen = parseInt(this.dataset.year, 10);
                fp.jumpToDate(new Date(chosen, fp.currentMonth, 1));
                btn.innerHTML = `${chosen} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
                menu.classList.add('d-none');
              });
              menu.appendChild(item);
            }

            btn.addEventListener('click', function(ev) {
              ev.stopPropagation();
              const isClosed = menu.classList.contains('d-none');
              menu.classList.toggle('d-none');
              if (isClosed) {
                const active = menu.querySelector('.flatpickr-year-custom-item.active');
                if (active) active.scrollIntoView({ block: 'center' });
              }
            });

            document.addEventListener('click', function(ev) {
              if (!wrap.contains(ev.target)) {
                menu.classList.add('d-none');
              }
            });

            wrap.appendChild(btn);
            wrap.appendChild(menu);
            monthElem.appendChild(wrap);

            const numWrap = monthElem.querySelector('.numInputWrapper');
            if (numWrap) numWrap.remove();
          }
        },
        onMonthChange: function(selectedDates, dateStr, fp) {
          const btn = fp.calendarContainer.querySelector('.flatpickr-year-custom-btn');
          if (btn) btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
          const items = fp.calendarContainer.querySelectorAll('.flatpickr-year-custom-item');
          items.forEach(el => el.classList.toggle('active', parseInt(el.dataset.year, 10) === fp.currentYear));
        },
        onYearChange: function(selectedDates, dateStr, fp) {
          const btn = fp.calendarContainer.querySelector('.flatpickr-year-custom-btn');
          if (btn) btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
          const items = fp.calendarContainer.querySelectorAll('.flatpickr-year-custom-item');
          items.forEach(el => el.classList.toggle('active', parseInt(el.dataset.year, 10) === fp.currentYear));
        },
        onChange: function(selectedDates, dateStr) {
          if (ageEl) ageEl.value = calculateAge(dateStr);
        }
      });

      birthEl.addEventListener('change', function() {
        if (ageEl) ageEl.value = calculateAge(this.value);
      });
    }

    // Strict Alphabetical-Only restriction for Name fields
    const alphaInputs = formRegisterWalkin.querySelectorAll('.alpha-only');
    alphaInputs.forEach(input => {
      input.addEventListener('input', function() {
        this.value = this.value.replace(/[^a-zA-Z\sñÑáéíóúÁÉÍÓÚ]/g, '');
      });
      input.addEventListener('keypress', function(e) {
        if (!/^[a-zA-Z\sñÑáéíóúÁÉÍÓÚ]$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
          e.preventDefault();
        }
      });
    });

    // Strict Numeric-Only restriction for Mobile Number
    const numericInputs = formRegisterWalkin.querySelectorAll('.numeric-only');
    numericInputs.forEach(input => {
      input.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
      });
      input.addEventListener('keypress', function(e) {
        if (!/^[0-9]$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
          e.preventDefault();
        }
      });
    });

    // Dynamic Consultation Purpose adaptation for Eyeglass Claim / Fitting
    const walkinPurposeEl = document.getElementById('walkinInputPurpose');
    const walkinStatusEl = document.getElementById('walkinInputInitialStatus');
    const walkinNoticeWrap = document.getElementById('walkinPurposeNoticeWrap');

    if (walkinPurposeEl && walkinStatusEl) {
      walkinPurposeEl.addEventListener('change', function() {
        if (this.value === 'eyeglass_claim') {
          if (walkinNoticeWrap) walkinNoticeWrap.style.display = 'block';
          walkinStatusEl.innerHTML = `
            <option value="confirmed" selected>Ready for Fitting / Pickup (Confirmed)</option>
            <option value="completed">Claim Completed &amp; Handed Over (Done)</option>
          `;
        } else {
          if (walkinNoticeWrap) walkinNoticeWrap.style.display = 'none';
          walkinStatusEl.innerHTML = `
            <option value="confirmed" selected>Waiting in Clinic (Confirmed)</option>
            <option value="in_progress">Direct to Doctor (Examining Now / In-Progress)</option>
          `;
        }
      });
    }

    formRegisterWalkin.addEventListener('submit', function(e) {
      if (walkinAlert) {
        walkinAlert.textContent = '';
        walkinAlert.classList.add('d-none');
      }

      // Sex Validation (Required)
      const sexVal = (genderEl ? genderEl.value : '').trim();
      if (!sexVal) {
        if (walkinAlert) {
          walkinAlert.textContent = 'Sex is required. Please select Male, Female, or Other.';
          walkinAlert.classList.remove('d-none');
        }
        if (genderEl) {
          genderEl.classList.add('is-invalid');
          genderEl.focus();
        }
        e.preventDefault();
        return;
      }

      // Birthdate Validation (Required)
      const bdateVal = (birthEl ? birthEl.value : '').trim();
      if (!bdateVal) {
        if (walkinAlert) {
          walkinAlert.textContent = 'Birthdate is required. Please select patient birthdate.';
          walkinAlert.classList.remove('d-none');
        }
        if (birthEl) {
          birthEl.classList.add('is-invalid');
          birthEl.focus();
        }
        e.preventDefault();
        return;
      }

      e.preventDefault();

      btnSubmitWalkin.disabled = true;
      btnSubmitWalkin.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Registering...';

      const formData = new FormData(this);

      fetch('../api/register_walkin.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        btnSubmitWalkin.disabled = false;
        btnSubmitWalkin.innerHTML = '<i class="fas fa-check-circle me-1"></i> Register & Add to Queue';

        if (!data.success) {
          if (walkinAlert) {
            walkinAlert.textContent = data.error || 'Failed to register walk-in patient.';
            walkinAlert.classList.remove('d-none');
          }
          return;
        }

        // 1. Add new appointment to local calendar cache
        if (data.appointment) {
          rawAppointments.push(data.appointment);
          render();
        }

        // 2. Hide register modal & reset form
        const registerModalEl = document.getElementById('registerWalkinModal');
        const modalInstance = bootstrap.Modal.getInstance(registerModalEl);
        if (modalInstance) modalInstance.hide();
        formRegisterWalkin.reset();
        if (walkinBirthPicker) walkinBirthPicker.clear();
        if (ageEl) ageEl.value = '';

        // 3. Open details modal for confirmation
        if (data.appointment) {
          setTimeout(() => {
            openAppointmentModal(data.appointment);
          }, 350);
        }
      })
      .catch(err => {
        btnSubmitWalkin.disabled = false;
        btnSubmitWalkin.innerHTML = '<i class="fas fa-check-circle me-1"></i> Register & Add to Queue';
        if (walkinAlert) {
          walkinAlert.textContent = 'Network or server error. Please try again.';
          walkinAlert.classList.remove('d-none');
        }
      });
    });
  }

  // ── Background Poller (every 12 seconds) ──────────────────────
  setInterval(function() {
    fetch('../api/get_calendar_events.php')
      .then(res => res.json())
      .then(data => {
        if (data && data.success && Array.isArray(data.raw)) {
          let changed = (data.raw.length !== rawAppointments.length);
          if (!changed) {
            for (let i = 0; i < data.raw.length; i++) {
              const fresh = data.raw[i];
              const existing = rawAppointments.find(a => a.id === fresh.id);
              if (!existing || existing.status !== fresh.status || existing.notes !== fresh.notes) {
                changed = true;
                break;
              }
            }
          }
          if (changed) {
            rawAppointments.length = 0;
            data.raw.forEach(a => rawAppointments.push(a));
            render();
          }
        }
      })
      .catch(() => {});
  }, 12000);

  // Check URL params for highlight or auto-open
  const urlParams = new URLSearchParams(window.location.search);
  const highlightParam = parseInt(urlParams.get('highlight') || '0', 10);
  if (highlightParam > 0) {
    const target = rawAppointments.find(a => parseInt(a.id, 10) === highlightParam);
    if (target) {
      // Switch view to table queue
      currentView = 'table';
      allViewBtns.forEach(b => b.classList.toggle('active', b.dataset.view === 'table'));

      const todayIso = formatDateIso(new Date());
      const isPastDate = (target.appointment_date < todayIso);
      const isToday = (target.appointment_date === todayIso);
      const isBilled = !!target.sale_id;
      const isDropped = (target.status === 'cancelled' || target.status === 'no_show');

      if (isApptClaim(target)) {
        queueSegment = (target.status === 'completed' || isDropped) ? 'history' : 'claims';
      } else if (isToday && !isBilled && !isDropped) {
        queueSegment = (target.appointment_type === 'WALK_IN') ? 'walkin' : 'upcoming';
      } else if (!isPastDate && !isBilled && !isDropped) {
        queueSegment = 'upcoming';
      } else {
        queueSegment = 'history';
      }
      queueTabBtns.forEach(b => b.classList.toggle('active', b.dataset.segment === queueSegment));
      currentFilter = 'all';
      if (calStatusSelect) calStatusSelect.value = 'all';
    }
  }

  // Slots Today Modal Event Listeners
  const slotsFilterDateInput = document.getElementById('slotsFilterDateInput');
  if (slotsFilterDateInput) {
    slotsFilterDateInput.addEventListener('change', function() {
      renderSlotsModal(this.value);
    });
  }

  const btnSlotsJumpToday = document.getElementById('btnSlotsJumpToday');
  if (btnSlotsJumpToday) {
    btnSlotsJumpToday.addEventListener('click', function() {
      const todayIso = formatDateIso(new Date());
      if (slotsFilterDateInput) slotsFilterDateInput.value = todayIso;
      renderSlotsModal(todayIso);
    });
  }

  const slotsTodayModalEl = document.getElementById('slotsTodayModal');
  if (slotsTodayModalEl) {
    slotsTodayModalEl.addEventListener('show.bs.modal', function() {
      const targetDate = (slotsFilterDateInput && slotsFilterDateInput.value) ? slotsFilterDateInput.value : formatDateIso(new Date());
      renderSlotsModal(targetDate);
    });
  }

  // Initial render
  render();

  if (highlightParam > 0) {
    setTimeout(() => {
      const row = document.getElementById('appt-row-' + highlightParam);
      if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 350);
  }

  const autoApptId = parseInt(urlParams.get('manage') || urlParams.get('appt_id') || '0', 10);
  if (autoApptId > 0) {
    const targetAppt = rawAppointments.find(a => a.id === autoApptId);
    if (targetAppt) {
      setTimeout(() => openAppointmentModal(targetAppt), 300);
    }
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
