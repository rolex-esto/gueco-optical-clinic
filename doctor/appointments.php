<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Appointments';
$breadcrumb = ['Doctor', 'Appointments Calendar'];
$activeNav  = 'appointments';
$db = getDB();
ensureAppointmentsSchema($db);
$today = date('Y-m-d');

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($apptId > 0) {
        $ptStmt = $db->prepare("SELECT p.full_name, a.appointment_date, a.appointment_time, a.status, a.appointment_type FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
        $ptStmt->execute([$apptId]);
        $ptData = $ptStmt->fetch();
        $ptName = $ptData['full_name'] ?? ('Appointment #' . $apptId);
        $isWalkin = ($ptData['appointment_type'] ?? '') === 'WALK_IN';

        if ($action === 'start_consultation') {
            $db->prepare("UPDATE appointments SET status='in_progress', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
            $_SESSION['flash_msg'] = 'Consultation started with patient: ' . $ptName . '.';
            $_SESSION['flash_type'] = 'info';
            logActivity("Started clinical consultation for patient: $ptName (Appointment #$apptId)", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'complete') {
            $apptDateTimeStr = ($ptData['appointment_date'] ?? '') . ' ' . ($ptData['appointment_time'] ?? '');
            $apptTimestamp = strtotime($apptDateTimeStr);

            if ($ptData && ($ptData['status'] ?? '') === 'pending') {
                $_SESSION['flash_msg'] = 'A consultation cannot be finished before it has actually taken place. Please confirm the appointment first.';
                $_SESSION['flash_type'] = 'warning';
            } elseif ($ptData && ($ptData['status'] ?? '') === 'no_show') {
                $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
                $_SESSION['flash_msg'] = 'Appointment marked as completed (Delayed charting recorded). Ready for Optical Dispensing & Checkout.';
                $_SESSION['flash_type'] = 'success';
                logActivity("Marked appointment #$apptId as completed from No-Show for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
            } elseif (!$isWalkin && $apptTimestamp && time() < $apptTimestamp) {
                $_SESSION['flash_msg'] = 'A consultation cannot be marked as completed before the scheduled appointment time (' . date('h:i A', $apptTimestamp) . ').';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
                $_SESSION['flash_msg'] = 'Consultation marked as completed. Patient is ready for Optical Dispensing & Checkout.';
                $_SESSION['flash_type'] = 'success';
                logActivity("Marked appointment #$apptId as completed for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
            }
        } elseif ($action === 'no_show') {
            if ($isWalkin) {
                $_SESSION['flash_msg'] = 'Marking No-Show is disabled for walk-in patients as they are physically present in the clinic.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $apptDateTimeStr = ($ptData['appointment_date'] ?? '') . ' ' . ($ptData['appointment_time'] ?? '');
                $apptTimestamp = strtotime($apptDateTimeStr);
                $graceTimestamp = $apptTimestamp ? ($apptTimestamp + (15 * 60)) : 0;

                if ($ptData && ($ptData['status'] ?? '') === 'pending') {
                    $_SESSION['flash_msg'] = 'Cannot mark a Pending appointment as No-Show. Please confirm the booking first.';
                    $_SESSION['flash_type'] = 'warning';
                } elseif ($apptTimestamp && time() < $graceTimestamp) {
                    $_SESSION['flash_msg'] = 'Marking a patient as No-Show is premature until the scheduled appointment time and 15-minute grace period have elapsed.';
                    $_SESSION['flash_type'] = 'warning';
                } else {
                    $db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$apptId]);
                    $_SESSION['flash_msg'] = 'Appointment marked as No-Show.';
                    $_SESSION['flash_type'] = 'warning';
                    logActivity("Marked appointment #$apptId as No-Show for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
                }
            }
        } elseif ($action === 'revert_confirmed') {
            if ($ptData && ($ptData['status'] ?? '') === 'no_show') {
                $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
                $_SESSION['flash_msg'] = 'Appointment reverted back to Confirmed.';
                $_SESSION['flash_type'] = 'success';
                logActivity("Reverted appointment #$apptId from No-Show back to Confirmed for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
            } else {
                $_SESSION['flash_msg'] = 'Only No-Show appointments can be reverted to Confirmed.';
                $_SESSION['flash_type'] = 'warning';
            }
        } elseif ($action === 'confirm') {
            $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
            $_SESSION['flash_msg'] = 'Appointment confirmed.';
            $_SESSION['flash_type'] = 'success';
            logActivity("Confirmed appointment #$apptId for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'cancel') {
            $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]);
            $_SESSION['flash_msg'] = 'Appointment cancelled.';
            $_SESSION['flash_type'] = 'danger';
            logActivity("Cancelled appointment #$apptId for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
        } elseif ($action === 'update_notes') {
            $notes = sanitize($_POST['notes'] ?? '');
            $db->prepare("UPDATE appointments SET notes=? WHERE id=?")->execute([$notes, $apptId]);
            $_SESSION['flash_msg'] = 'Appointment notes updated.';
            $_SESSION['flash_type'] = 'info';
            logActivity("Updated clinical notes on appointment #$apptId for patient: $ptName", "Appointments", $_SESSION['user_id'], 'staff');
        }
    }
    
    $redirectDate = !empty($_POST['current_view_date']) ? sanitize($_POST['current_view_date']) : $today;
    header('Location: appointments.php?date=' . urlencode($redirectDate));
    exit;
}

// Fetch all appointments for the calendar (spanning +/- 6 months for seamless viewing)
$apptsStmt = $db->query("
    SELECT a.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone, 
           p.email as patient_email, 
           p.gender as patient_gender,
           p.birthdate as patient_birthdate,
           p.address as patient_address,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as rx_count,
           (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed') as completed_visits,
           COALESCE(
               (SELECT s.id FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.id FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as sale_id,
           COALESCE(
               (SELECT s.invoice_no FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.invoice_no FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as invoice_no
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$allAppointments = $apptsStmt->fetchAll(PDO::FETCH_ASSOC);

// Today stats
$todayCountStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no_show')");
$todayCountStmt->execute([$today]);
$todayActiveCount = $todayCountStmt->fetch()['c'];

$pendingCountStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending'");
$pendingCountStmt->execute();
$pendingCount = $pendingCountStmt->fetch()['c'];

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/calendar.css?v=' . time() . '">';
$extraHead .= '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
$extraHead .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
$extraHead .= '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
include __DIR__ . '/../includes/header.php';
?>

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
        <div class="bento-value"><?= count(array_filter($allAppointments, fn($a) => $a['status'] === 'confirmed')) ?></div>
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
        <div class="bento-value"><?= count($allAppointments) ?></div>
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
        <div class="cal-title-sub" id="calSubtitle">Doctor Schedule & Clinical Consultations</div>
      </div>
    </div>

    <!-- View Switcher (Week / Month / Agenda) -->
    <div class="cal-view-switcher d-flex align-items-center">
      <button type="button" class="btn btn-warning btn-sm fw-bold shadow-sm me-2" id="btnCalWalkin" data-bs-toggle="modal" data-bs-target="#registerWalkinModal">
        <i class="fas fa-user-plus me-1"></i> + Walk-in Patient
      </button>
      <button type="button" class="cal-view-btn" data-view="week" id="viewBtnWeek">
        <i class="fas fa-calendar-week"></i> Week
      </button>
      <button type="button" class="cal-view-btn active" data-view="month" id="viewBtnMonth">
        <i class="fas fa-calendar-alt"></i> Month
      </button>
      <button type="button" class="cal-view-btn" data-view="agenda" id="viewBtnAgenda">
        <i class="fas fa-list-ul"></i> Agenda
      </button>
    </div>
  </div>

  <!-- 2. Filter & Live Search Toolbar -->
  <div class="cal-toolbar">
    <div class="cal-status-filters" id="statusFilterContainer">
      <button type="button" class="cal-filter-pill active" data-status="all">
        <i class="fas fa-layer-group"></i> All (<span id="countAll">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="confirmed">
        <i class="fas fa-check-circle text-success"></i> Confirmed (<span id="countConfirmed">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="in_progress">
        <i class="fas fa-stethoscope text-primary"></i> In-Progress (<span id="countInProgress">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="pending">
        <i class="fas fa-clock text-warning"></i> Pending (<span id="countPending">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="completed">
        <i class="fas fa-check-double text-info"></i> Done (<span id="countCompleted">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="no_show">
        <i class="fas fa-user-slash text-secondary"></i> No-Show (<span id="countNoShow">0</span>)
      </button>
    </div>

    <div class="cal-search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="calSearchInput" placeholder="Search patient name, phone...">
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

</div>

<style>
/* Clinical Workflow - Disabled state styling with visible tooltips */
.cal-modal .btn:disabled,
.cal-modal .btn.disabled,
.cal-modal a.btn.is-disabled {
  opacity: 0.45 !important;
  cursor: not-allowed !important;
  pointer-events: auto !important; /* allows browser title tooltip */
  box-shadow: none !important;
  transform: none !important;
}
.cal-modal a.btn.is-disabled {
  filter: grayscale(40%) !important;
}
.cal-modal .btn:disabled:hover,
.cal-modal a.btn.is-disabled:hover {
  filter: grayscale(50%) !important;
}

/* Walk-in highlight styling */
.cal-event-card.is-walkin,
.cal-week-card.is-walkin {
  border-left: 3px solid #f59e0b !important;
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
</style>

<!-- ============================================================ -->
<!-- APPOINTMENT DETAILS & DOCTOR ACTION MODAL                    -->
<!-- ============================================================ -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px; height:44px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:1.1rem;" id="modalAvatar">
            J
          </div>
          <div>
            <h5 class="modal-title fw-bold cal-modal-title mb-0" id="modalPatientName">Juan Dela Cruz</h5>
            <small class="text-muted" id="modalPatientMeta">Patient ID: #10 &middot; 0917-123-4567</small>
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
              <div class="cal-info-label"><i class="fas fa-calendar me-1"></i> Date</div>
              <div class="cal-info-val" id="modalDate">August 20, 2026</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-clock me-1"></i> Scheduled Time</div>
              <div class="cal-info-val text-primary" id="modalTime">09:00 AM</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-stethoscope me-1"></i> Consultation Purpose</div>
              <div class="cal-info-val" id="modalPurpose">Eye Examination</div>
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
              <span class="cal-info-label">Gender / Age:</span>
              <div class="cal-modal-banner-val small" id="modalGenderAge">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Prescription History:</span>
              <div class="cal-modal-banner-val small" id="modalRxCount">0 Prescriptions</div>
            </div>
          </div>
        </div>

        <!-- Appointment Notes -->
        <div class="mb-4">
          <label class="form-label text-muted small fw-bold text-uppercase"><i class="fas fa-sticky-note me-1"></i> Patient / Staff Notes</label>
          <div class="cal-modal-notes" id="modalNotes">
            No additional notes provided.
          </div>
        </div>

        <!-- Clinical Workflow Context Notice -->
        <div id="modalWorkflowNotice" class="alert py-2 px-3 mb-3 d-none align-items-center gap-2 small" style="border-radius:10px; font-size:0.82rem;">
          <i class="fas fa-info-circle flex-shrink-0"></i>
          <span id="modalWorkflowNoticeText"></span>
        </div>

        <!-- Doctor Clinical Actions -->
        <div class="cal-modal-shortcuts p-3">
          <h6 class="cal-modal-shortcuts-heading"><i class="fas fa-user-md text-primary me-2"></i>Clinical Shortcuts for Doctor</h6>
          <div class="d-flex flex-wrap gap-2">
            <a href="#" id="modalBtnRecord" class="btn btn-outline-primary btn-sm flex-fill py-2" title="Review patient medical record">
              <i class="fas fa-folder-open me-1"></i> Open Patient Medical Record
            </a>
            <a href="#" id="modalBtnRx" class="btn btn-secondary btn-sm flex-fill py-2" title="Write New Prescription">
              <i class="fas fa-glasses me-1"></i> Write New Prescription
            </a>
            <button type="button" id="modalBtnCert" class="btn btn-outline-info btn-sm flex-fill py-2" title="Issue Certificate of Examination">
              <i class="fas fa-file-contract me-1"></i> Issue Certificate of Examination
            </button>
            <a href="#" id="modalBtnReceipt" class="btn btn-outline-success btn-sm flex-fill py-2" style="display:none;" target="_blank">
              <i class="fas fa-file-invoice me-1"></i> View Receipt
            </a>
          </div>
        </div>
      </div>

      <div class="modal-footer d-flex justify-content-between align-items-center">
        <!-- Quick Status Update Forms -->
        <div class="d-flex gap-2" id="modalStatusButtons">
          <form method="POST" id="formStartConsultationAppt" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdStartConsultation">
            <input type="hidden" name="action" value="start_consultation">
            <input type="hidden" name="current_view_date" id="postDateStartConsultation">
            <button type="submit" id="btnSubmitStartConsultation" class="btn btn-primary btn-sm px-3"><i class="fas fa-stethoscope me-1"></i> Start Consultation</button>
          </form>

          <form method="POST" id="formCompleteAppt" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdComplete">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="current_view_date" id="postDateComplete">
            <button type="submit" id="btnSubmitComplete" class="btn btn-success btn-sm px-3"><i class="fas fa-check me-1"></i> Mark as Completed</button>
          </form>

          <form method="POST" id="formConfirmAppt" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdConfirm">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="current_view_date" id="postDateConfirm">
            <button type="submit" id="btnSubmitConfirm" class="btn btn-info btn-sm px-3 text-white"><i class="fas fa-check-circle me-1"></i> Confirm</button>
          </form>

          <form method="POST" id="formNoShowAppt" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdNoShow">
            <input type="hidden" name="action" value="no_show">
            <input type="hidden" name="current_view_date" id="postDateNoShow">
            <button type="submit" id="btnSubmitNoShow" class="btn btn-outline-warning btn-sm px-3"><i class="fas fa-user-times me-1"></i> Mark No-Show</button>
          </form>

          <form method="POST" id="formRevertConfirmedAppt" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdRevertConfirmed">
            <input type="hidden" name="action" value="revert_confirmed">
            <input type="hidden" name="current_view_date" id="postDateRevertConfirmed">
            <button type="submit" id="btnSubmitRevertConfirmed" class="btn btn-outline-info btn-sm px-3"><i class="fas fa-undo me-1"></i> Revert to Confirmed</button>
          </form>

          <form method="POST" id="formCancelAppt" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this appointment?');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="appt_id" id="postApptIdCancel">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="current_view_date" id="postDateCancel">
            <button type="submit" id="btnSubmitCancel" class="btn btn-outline-danger btn-sm px-3"><i class="fas fa-times me-1"></i> Cancel</button>
          </form>
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
          <small class="text-muted" id="dayModalSubtitle">Day Schedule Overview</small>
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
<!-- REGISTER WALK-IN PATIENT MODAL                               -->
<!-- ============================================================ -->
<div class="modal fade" id="registerWalkinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
          <div style="width:38px;height:38px;background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#f59e0b;">
            <i class="fas fa-walking"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold cal-modal-title mb-0">Register Walk-in Patient</h5>
            <small class="text-muted">Direct Check-in &bull; Scheduled for Today</small>
          </div>
        </div>
        <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="formRegisterWalkin" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="modal-body p-4">
          <div id="walkinAlert" class="alert alert-danger py-2 px-3 mb-3 d-none" style="font-size:0.85rem;"></div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-bold text-muted text-uppercase">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" id="walkinInputLastName" class="form-control alpha-only" placeholder="e.g. Dela Cruz" required maxlength="50" autocomplete="off">
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-bold text-muted text-uppercase">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" id="walkinInputFirstName" class="form-control alpha-only" placeholder="e.g. Juan" required maxlength="50" autocomplete="off">
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-bold text-muted text-uppercase">Middle Name</label>
              <input type="text" name="middle_name" id="walkinInputMiddleName" class="form-control alpha-only" placeholder="e.g. Santos" maxlength="50" autocomplete="off">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold text-muted text-uppercase">Mobile Number (09XXXXXXXXX) <span class="text-danger">*</span></label>
              <input type="tel" name="phone" id="walkinInputPhone" class="form-control numeric-only" placeholder="09XXXXXXXXX" required maxlength="11" inputmode="numeric" autocomplete="tel">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold text-muted text-uppercase">Email (Optional)</label>
              <input type="email" name="email" id="walkinInputEmail" class="form-control" placeholder="Leave blank if none">
            </div>

            <div class="col-md-5">
              <label class="form-label small fw-bold text-muted text-uppercase">Sex</label>
              <select name="gender" id="walkinInputGender" class="form-select">
                <option value="">Select Sex</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div class="col-md-7">
              <label class="form-label small fw-bold text-muted text-uppercase">Birthdate</label>
              <input type="text" name="birthdate" id="walkinInputBirthdate" class="form-control modern-birthdate-picker" placeholder="Select birthdate (Month / Day / Year)">
            </div>

            <div class="col-12">
              <label class="form-label small fw-bold text-muted text-uppercase">Address <span class="text-danger">*</span></label>
              <input type="text" name="address" id="walkinInputAddress" class="form-control" placeholder="Barangay, City / Municipality, Province" required maxlength="255">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold text-muted text-uppercase">Consultation Purpose</label>
              <select name="purpose" id="walkinInputPurpose" class="form-select">
                <option value="consultation" selected>Comprehensive Eye Examination / Refraction</option>
                <option value="eyeglass_claim">Eyeglass Claim / Fitting</option>
                <option value="follow_up">Follow-up Check</option>
                <option value="contact_lens_fitting">Contact Lens Assessment</option>
                <option value="other">Other Optical Concerns</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold text-muted text-uppercase">Initial Queue Status</label>
              <select name="initial_status" id="walkinInputInitialStatus" class="form-select">
                <option value="confirmed" selected>Waiting in Clinic (Confirmed)</option>
                <option value="in_progress">Direct to Doctor (Examining Now / In-Progress)</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label small fw-bold text-muted text-uppercase">Staff / Clinical Notes (Optional)</label>
              <textarea name="notes" id="walkinInputNotes" class="form-control" rows="2" placeholder="e.g. Chief complaint, blurry vision, frame adjustment..."></textarea>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning btn-sm px-4 fw-bold" id="btnSubmitWalkin">
            <i class="fas fa-check-circle me-1"></i> Register & Add to Queue
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/modal_issue_certificate.php'; ?>

<!-- ============================================================ -->
<!-- CALENDAR JAVASCRIPT LOGIC ENGINE                             -->
<!-- ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const rawAppointments = <?= json_encode($allAppointments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const initialDateStr = '<?= htmlspecialchars($_GET['date'] ?? $today) ?>';
  
  // App State
  let currentDate = new Date(initialDateStr + 'T00:00:00');
  if (isNaN(currentDate.getTime())) currentDate = new Date();
  
  let currentView = 'month'; // 'month', 'week', 'agenda'
  let currentFilter = 'all';  // 'all', 'confirmed', 'pending', 'completed', 'no_show', 'cancelled'
  let searchQuery = '';
  let selectedDateStr = initialDateStr || formatDateIso(new Date());

  // DOM Elements
  const calTitle = document.getElementById('calTitle');
  const calSubtitle = document.getElementById('calSubtitle');
  const btnToday = document.getElementById('btnToday');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const viewBtnMonth = document.getElementById('viewBtnMonth');
  const viewBtnWeek = document.getElementById('viewBtnWeek');
  const viewBtnAgenda = document.getElementById('viewBtnAgenda');
  const monthViewContainer = document.getElementById('monthViewContainer');
  const weekViewContainer = document.getElementById('weekViewContainer');
  const agendaViewContainer = document.getElementById('agendaViewContainer');
  const monthGrid = document.getElementById('monthGrid');
  const searchInput = document.getElementById('calSearchInput');
  const filterPills = document.querySelectorAll('.cal-filter-pill');

  // Modals
  const appointmentModalEl = document.getElementById('appointmentModal');
  const appointmentModal = new bootstrap.Modal(appointmentModalEl);
  const dayQueueModal = new bootstrap.Modal(document.getElementById('dayQueueModal'));

  // Guard against clicks when Clinical Shortcut buttons are disabled
  const modalBtnRxEl = document.getElementById('modalBtnRx');
  if (modalBtnRxEl) {
    modalBtnRxEl.addEventListener('click', function(e) {
      if (this.dataset.disabled === 'true' || this.classList.contains('is-disabled') || this.classList.contains('disabled')) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    });
  }

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

  // Filter Appointments by status and search
  function getFilteredAppointments() {
    return rawAppointments.filter(appt => {
      // Status Filter
      if (currentFilter !== 'all' && appt.status !== currentFilter) {
        return false;
      }
      // Search query filter
      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase();
        const patientName = (appt.patient_name || '').toLowerCase();
        const phone = (appt.patient_phone || '').toLowerCase();
        const purpose = (appt.purpose || '').toLowerCase();
        const notes = (appt.notes || '').toLowerCase();
        if (!patientName.includes(q) && !phone.includes(q) && !purpose.includes(q) && !notes.includes(q)) {
          return false;
        }
      }
      return true;
    });
  }

  function updateCounts() {
    const total = rawAppointments.length;
    const confirmed = rawAppointments.filter(a => a.status === 'confirmed').length;
    const inProgress = rawAppointments.filter(a => a.status === 'in_progress').length;
    const pending = rawAppointments.filter(a => a.status === 'pending').length;
    const completed = rawAppointments.filter(a => a.status === 'completed').length;
    const noShow = rawAppointments.filter(a => a.status === 'no_show').length;

    document.getElementById('countAll').textContent = total;
    document.getElementById('countConfirmed').textContent = confirmed;
    const inProgEl = document.getElementById('countInProgress');
    if (inProgEl) inProgEl.textContent = inProgress;
    document.getElementById('countPending').textContent = pending;
    document.getElementById('countCompleted').textContent = completed;
    document.getElementById('countNoShow').textContent = noShow;
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
    const filteredAppts = getFilteredAppointments();

    // Group appointments by date
    const apptsByDate = {};
    filteredAppts.forEach(appt => {
      if (!apptsByDate[appt.appointment_date]) {
        apptsByDate[appt.appointment_date] = [];
      }
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

    // Next month filler days (to fill 35 or 42 grid slots)
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

    // Top Header in Cell
    const topWrap = document.createElement('div');
    topWrap.className = 'cal-day-cell-top';

    const numSpan = document.createElement('span');
    numSpan.className = 'cal-day-number';
    numSpan.textContent = dayNum;

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cal-add-btn';
    addBtn.title = 'View Appointments on ' + dateIso;
    addBtn.innerHTML = '<i class="fas fa-plus"></i>';
    addBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      openDayQueueModal(dateIso);
    });

    topWrap.appendChild(numSpan);
    topWrap.appendChild(addBtn);
    cell.appendChild(topWrap);

    // Event List Container inside cell
    const eventsList = document.createElement('div');
    eventsList.className = 'cal-events-list';

    // Show up to 3 cards, then +X more
    const maxVisible = 3;
    const visibleAppts = appts.slice(0, maxVisible);
    const overflowCount = appts.length - maxVisible;

    visibleAppts.forEach(appt => {
      const isWalkin = (appt.appointment_type === 'WALK_IN');
      const card = document.createElement('div');
      card.className = `cal-event-card status-${appt.status}${isWalkin ? ' is-walkin' : ''}`;
      
      const timeStr = formatTime12(appt.appointment_time);
      const purposeStr = (appt.purpose || '').replace(/_/g, ' ');

      card.innerHTML = `
        <span class="cal-event-time">
          <i class="far fa-clock" style="font-size:0.6rem;"></i> ${timeStr}
          ${isWalkin ? '<span class="cal-walkin-badge ms-1">Walk-in</span>' : ''}
        </span>
        <div class="cal-event-title">${escapeHtml(appt.patient_name)}</div>
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

    // Cell click to select
    cell.addEventListener('click', () => {
      document.querySelectorAll('.cal-day-cell').forEach(c => c.classList.remove('selected-day'));
      cell.classList.add('selected-day');
      selectedDateStr = dateIso;
    });

    // Double click to open day view
    cell.addEventListener('dblclick', () => {
      openDayQueueModal(dateIso);
    });

    return cell;
  }

  // ── 2. RENDER WEEK VIEW ────────────────────────────────────────
  function renderWeek() {
    weekViewContainer.innerHTML = '';
    const todayIso = formatDateIso(new Date());
    const filteredAppts = getFilteredAppointments();

    // Find Sunday of the current week
    const curr = new Date(currentDate);
    const dayOfWeek = curr.getDay(); // 0 = Sun, 1 = Mon...
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
    calSubtitle.textContent = 'Weekly Schedule Overview';

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
          const isWalkin = (appt.appointment_type === 'WALK_IN');
          const card = document.createElement('div');
          card.className = `cal-week-card status-${appt.status}${isWalkin ? ' is-walkin' : ''}`;
          
          card.innerHTML = `
            <div class="cal-week-card-time d-flex justify-content-between align-items-center">
              <span><i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}</span>
              ${isWalkin ? '<span class="cal-walkin-badge">Walk-in</span>' : ''}
            </div>
            <div class="cal-week-card-name">${escapeHtml(appt.patient_name)}</div>
            <div class="cal-week-card-purpose">${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              ${getStatusBadgeHtml(appt.status)}
              <span class="badge bg-dark-subtle text-muted small">Rx: ${appt.rx_count || 0}</span>
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
    const filteredAppts = getFilteredAppointments();

    calTitle.textContent = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    calSubtitle.textContent = 'Agenda Timeline &middot; Chronological Patient List';

    if (filteredAppts.length === 0) {
      agendaViewContainer.innerHTML = `
        <div class="text-center py-5">
          <i class="fas fa-calendar-times fa-3x text-muted mb-3" style="opacity:0.4;"></i>
          <h6 class="text-white">No Appointments Found</h6>
          <p class="text-muted small">Try switching filters or searching with different terms.</p>
        </div>
      `;
      return;
    }

    // Group by Date
    const grouped = {};
    filteredAppts.forEach(appt => {
      if (!grouped[appt.appointment_date]) grouped[appt.appointment_date] = [];
      grouped[appt.appointment_date].push(appt);
    });

    // Sort dates
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
          <span class="badge bg-dark border border-secondary text-muted">${appts.length} patient${appts.length>1?'s':''}</span>
        </div>
      `;

      const itemsWrap = document.createElement('div');
      itemsWrap.className = 'cal-agenda-items';

      appts.forEach(appt => {
        const isWalkin = (appt.appointment_type === 'WALK_IN');
        const item = document.createElement('div');
        item.className = 'cal-agenda-item';

        item.innerHTML = `
          <div class="cal-agenda-left">
            <div class="cal-agenda-time-badge">
              <i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}
            </div>
            <div class="cal-agenda-patient-info">
              <h6>${escapeHtml(appt.patient_name)}${isWalkin ? ' <span class="cal-walkin-badge ms-1">Walk-in</span>' : ''}</h6>
              <p>
                <i class="fas fa-phone-alt me-1" style="font-size:0.68rem;"></i>${escapeHtml(appt.patient_phone || 'No phone')} &middot;
                <span class="text-info">${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</span>
                ${appt.notes ? ` &middot; <span class="text-muted fst-italic">"${escapeHtml(appt.notes)}"</span>` : ''}
              </p>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${getStatusBadgeHtml(appt.status)}
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-1 btn-view-appt">
              <i class="fas fa-eye me-1"></i> View
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

  // ── Master Render Trigger ──────────────────────────────────────
  function render() {
    updateCounts();
    if (currentView === 'month') {
      monthViewContainer.style.display = 'block';
      weekViewContainer.style.display = 'none';
      agendaViewContainer.style.display = 'none';
      renderMonth();
    } else if (currentView === 'week') {
      monthViewContainer.style.display = 'none';
      weekViewContainer.style.display = 'grid';
      agendaViewContainer.style.display = 'none';
      renderWeek();
    } else if (currentView === 'agenda') {
      monthViewContainer.style.display = 'none';
      weekViewContainer.style.display = 'none';
      agendaViewContainer.style.display = 'flex';
      renderAgenda();
    }
  }

  // ── Navigation Buttons ─────────────────────────────────────────
  btnToday.addEventListener('click', () => {
    currentDate = new Date();
    selectedDateStr = formatDateIso(new Date());
    render();
  });

  btnPrev.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda') {
      currentDate.setMonth(currentDate.getMonth() - 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() - 7);
    }
    render();
  });

  btnNext.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda') {
      currentDate.setMonth(currentDate.getMonth() + 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() + 7);
    }
    render();
  });

  // View Switchers
  viewBtnMonth.addEventListener('click', () => {
    currentView = 'month';
    viewBtnMonth.classList.add('active');
    viewBtnWeek.classList.remove('active');
    viewBtnAgenda.classList.remove('active');
    render();
  });

  viewBtnWeek.addEventListener('click', () => {
    currentView = 'week';
    viewBtnWeek.classList.add('active');
    viewBtnMonth.classList.remove('active');
    viewBtnAgenda.classList.remove('active');
    render();
  });

  viewBtnAgenda.addEventListener('click', () => {
    currentView = 'agenda';
    viewBtnAgenda.classList.add('active');
    viewBtnMonth.classList.remove('active');
    viewBtnWeek.classList.remove('active');
    render();
  });

  // Filter Buttons
  filterPills.forEach(pill => {
    pill.addEventListener('click', () => {
      filterPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      currentFilter = pill.dataset.status;
      render();
    });
  });

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

    const isWalkin = (appt.appointment_type === 'WALK_IN');
    const walkinBadge = document.getElementById('modalWalkinBadge');
    if (walkinBadge) {
      if (isWalkin) {
        walkinBadge.innerHTML = '<span class="badge bg-warning text-dark px-2 py-1"><i class="fas fa-walking me-1"></i>Walk-in</span>';
      } else {
        walkinBadge.innerHTML = '';
      }
    }

    const apptDateObj = new Date(appt.appointment_date + 'T00:00:00');
    document.getElementById('modalDate').textContent = formatDisplayDate(apptDateObj);
    document.getElementById('modalTime').textContent = formatTime12(appt.appointment_time);
    document.getElementById('modalPurpose').textContent = (appt.purpose || '').replace(/_/g, ' ').toUpperCase();

    document.getElementById('modalPhone').textContent = appt.patient_phone || '—';
    document.getElementById('modalEmail').textContent = appt.patient_email || '—';
    document.getElementById('modalGenderAge').textContent = (appt.patient_gender ? (appt.patient_gender.charAt(0).toUpperCase() + appt.patient_gender.slice(1)) : '—');
    document.getElementById('modalRxCount').textContent = `${appt.rx_count || 0} Prescription(s) / ${appt.completed_visits || 0} Visits`;

    document.getElementById('modalNotes').textContent = appt.notes && appt.notes.trim() !== '' ? appt.notes : 'No special notes entered for this appointment.';

    // ── Clinical Workflow & Timing Logic ─────────────────────────
    // Calculate appointment scheduled datetime & 15-minute clinic grace period
    const apptTimeStr = appt.appointment_time ? (appt.appointment_time.length === 5 ? appt.appointment_time + ':00' : appt.appointment_time) : '00:00:00';
    const scheduledDateTime = new Date(`${appt.appointment_date}T${apptTimeStr}`);
    const graceMinutes = 15;
    const gracePeriodEnd = !isNaN(scheduledDateTime.getTime()) ? new Date(scheduledDateTime.getTime() + graceMinutes * 60 * 1000) : null;
    const now = new Date();
    const isAppointmentTimeReached = !isNaN(scheduledDateTime.getTime()) ? (now >= scheduledDateTime) : false;
    const isPastGrace = gracePeriodEnd ? (now >= gracePeriodEnd) : false;
    const unlockTimeStr = gracePeriodEnd ? formatTime12(gracePeriodEnd.toTimeString().substring(0, 5)) : '15 mins after scheduled time';

    // Clinical Shortcuts: Patient Medical Record & Prescription
    const modalBtnRecord = document.getElementById('modalBtnRecord');
    const modalBtnRx = document.getElementById('modalBtnRx');

    // 1. Open Patient Medical Record is ALWAYS ACTIVE
    modalBtnRecord.href = `patients.php?view=${appt.patient_id}`;
    modalBtnRecord.classList.remove('is-disabled', 'disabled');
    modalBtnRecord.removeAttribute('aria-disabled');
    modalBtnRecord.title = 'Review patient history, past diagnoses, and medical charts';

    // 2. Write New Prescription
    if (appt.status === 'pending') {
      // Disabled while Pending
      modalBtnRx.classList.add('is-disabled', 'disabled');
      modalBtnRx.dataset.disabled = 'true';
      modalBtnRx.href = 'javascript:void(0)';
      modalBtnRx.setAttribute('aria-disabled', 'true');
      modalBtnRx.title = 'Writing and issuing prescriptions is locked until the patient is actively being seen (appointment confirmed).';
      modalBtnRx.innerHTML = '<i class="fas fa-lock me-1"></i> Write New Prescription';
    } else if (appt.status === 'confirmed' || appt.status === 'in_progress' || appt.status === 'completed') {
      // Active once confirmed, in-progress, or completed
      modalBtnRx.classList.remove('is-disabled', 'disabled');
      modalBtnRx.dataset.disabled = 'false';
      modalBtnRx.href = `prescriptions.php?patient_id=${appt.patient_id}&appt_id=${appt.id}`;
      modalBtnRx.removeAttribute('aria-disabled');
      modalBtnRx.title = 'Write optical prescription for this consultation';
      modalBtnRx.innerHTML = '<i class="fas fa-glasses me-1"></i> Write New Prescription';
    } else {
      // Cancelled / No-show
      modalBtnRx.classList.add('is-disabled', 'disabled');
      modalBtnRx.dataset.disabled = 'true';
      modalBtnRx.href = 'javascript:void(0)';
      modalBtnRx.setAttribute('aria-disabled', 'true');
      modalBtnRx.title = `Prescription locked: Cannot prescribe for ${appt.status.replace(/_/g, ' ')} appointments.`;
      modalBtnRx.innerHTML = '<i class="fas fa-ban me-1"></i> Write New Prescription';
    }

    // 3. Issue Certificate of Examination
    const modalBtnCert = document.getElementById('modalBtnCert');
    if (modalBtnCert) {
      if (appt.status === 'in_progress' || appt.status === 'completed') {
        modalBtnCert.disabled = false;
        modalBtnCert.classList.remove('is-disabled', 'disabled');
        modalBtnCert.removeAttribute('aria-disabled');
        modalBtnCert.title = 'Issue formal Certificate of Examination for this consultation';
        modalBtnCert.innerHTML = '<i class="fas fa-file-contract me-1"></i> Issue Certificate of Examination';
        modalBtnCert.onclick = function() {
          openCertificateModal({
            patient_id: appt.patient_id,
            appointment_id: appt.id,
            patient_name: appt.patient_name,
            patient_birthdate: appt.patient_birthdate,
            patient_address: appt.patient_address,
            purpose: appt.purpose
          });
        };
      } else if (appt.status === 'pending' || appt.status === 'confirmed') {
        modalBtnCert.disabled = true;
        modalBtnCert.classList.add('is-disabled', 'disabled');
        modalBtnCert.setAttribute('aria-disabled', 'true');
        modalBtnCert.title = 'Certificate of Examination can only be issued once consultation is In-Progress or Completed.';
        modalBtnCert.innerHTML = '<i class="fas fa-lock me-1"></i> Issue Certificate of Examination';
        modalBtnCert.onclick = null;
      } else {
        modalBtnCert.disabled = true;
        modalBtnCert.classList.add('is-disabled', 'disabled');
        modalBtnCert.setAttribute('aria-disabled', 'true');
        modalBtnCert.title = `Cannot issue certificate for ${appt.status.replace(/_/g, ' ')} appointments.`;
        modalBtnCert.innerHTML = '<i class="fas fa-ban me-1"></i> Issue Certificate of Examination';
        modalBtnCert.onclick = null;
      }
    }

    const btnReceipt = document.getElementById('modalBtnReceipt');
    if (btnReceipt) {
      if (appt.sale_id) {
        btnReceipt.href = `../saleslady/receipt.php?id=${appt.sale_id}`;
        btnReceipt.style.display = 'inline-flex';
        btnReceipt.innerHTML = `<i class="fas fa-file-invoice me-1"></i> View Receipt (${escapeHtml(appt.invoice_no || '#' + appt.sale_id)})`;
      } else {
        btnReceipt.style.display = 'none';
      }
    }

    // Fill IDs into status forms
    const currDateIso = formatDateIso(currentDate);
    ['Complete', 'Confirm', 'NoShow', 'Cancel', 'RevertConfirmed', 'StartConsultation'].forEach(action => {
      const idEl = document.getElementById(`postApptId${action}`);
      const dateEl = document.getElementById(`postDate${action}`);
      if (idEl) idEl.value = appt.id;
      if (dateEl) dateEl.value = currDateIso;
    });

    // Control Status Buttons
    const formStartConsultation = document.getElementById('formStartConsultationAppt');
    const formComplete = document.getElementById('formCompleteAppt');
    const formConfirm = document.getElementById('formConfirmAppt');
    const formNoShow = document.getElementById('formNoShowAppt');
    const formCancel = document.getElementById('formCancelAppt');
    const formRevertConfirmed = document.getElementById('formRevertConfirmedAppt');

    const btnSubmitStartConsultation = document.getElementById('btnSubmitStartConsultation');
    const btnSubmitComplete = document.getElementById('btnSubmitComplete');
    const btnSubmitConfirm = document.getElementById('btnSubmitConfirm');
    const btnSubmitNoShow = document.getElementById('btnSubmitNoShow');
    const btnSubmitCancel = document.getElementById('btnSubmitCancel');
    const btnSubmitRevertConfirmed = document.getElementById('btnSubmitRevertConfirmed');

    const noticeBox = document.getElementById('modalWorkflowNotice');
    const noticeText = document.getElementById('modalWorkflowNoticeText');

    const setNotice = (html, border, bg, color) => {
      if (!noticeBox || !noticeText) return;
      if (html) {
        noticeBox.className = 'alert py-2 px-3 mb-3 d-flex align-items-center gap-2 small';
        noticeBox.style.display = 'flex';
        noticeBox.style.border = border;
        noticeBox.style.background = bg;
        noticeBox.style.color = color;
        noticeText.innerHTML = html;
      } else {
        noticeBox.className = 'alert py-2 px-3 mb-3 d-none align-items-center gap-2 small';
        noticeBox.style.display = 'none';
        noticeText.innerHTML = '';
      }
    };
    setNotice(null); // Reset notice banner to hidden on each modal open

    if (formStartConsultation) formStartConsultation.style.display = 'none';
    if (formRevertConfirmed) formRevertConfirmed.style.display = 'none';

    if (appt.status === 'pending') {
      // ── PENDING STATUS ──────────────────────────────────────────
      // 1. Mark as Completed: DISABLED (both pending and before start)
      formComplete.style.display = 'inline';
      btnSubmitComplete.disabled = true;
      btnSubmitComplete.classList.add('disabled');
      if (!isAppointmentTimeReached) {
        btnSubmitComplete.title = `A consultation cannot be finished before it has actually taken place. Disabled until scheduled appointment time (${formatTime12(appt.appointment_time)}) and confirmation.`;
      } else {
        btnSubmitComplete.title = 'A consultation cannot be finished before it has actually taken place. Confirm the appointment first.';
      }

      // 2. Confirm: ACTIVE
      formConfirm.style.display = 'inline';
      btnSubmitConfirm.disabled = false;
      btnSubmitConfirm.classList.remove('disabled');
      btnSubmitConfirm.title = 'Confirm this booking';

      // 3. Mark No-Show: DISABLED (due to pending + grace period)
      formNoShow.style.display = 'inline';
      btnSubmitNoShow.disabled = true;
      btnSubmitNoShow.classList.add('disabled');
      if (!isPastGrace) {
        btnSubmitNoShow.title = `Marking a patient as a no-show prior to appointment time is premature. Disabled until ${formatTime12(appt.appointment_time)} plus 15-minute grace period has elapsed.`;
      } else {
        btnSubmitNoShow.title = 'Appointment is currently Pending. Booking must be confirmed first before marking as No-Show.';
      }

      // 4. Cancel: ACTIVE
      formCancel.style.display = 'inline';
      btnSubmitCancel.disabled = false;
      btnSubmitCancel.classList.remove('disabled');
      btnSubmitCancel.title = 'Cancel or reschedule this appointment';

      // Workflow notice banner
      setNotice(
        '<strong>Pending Appointment:</strong> Confirming the booking is the primary valid action. <em>Mark as Completed</em>, <em>Write Prescription</em>, and <em>No-Show</em> are locked until consultation workflow progresses.',
        '1px solid rgba(245, 158, 11, 0.35)',
        'rgba(245, 158, 11, 0.08)',
        '#d97706'
      );

    } else if (appt.status === 'in_progress') {
      // ── IN-PROGRESS STATUS (ACTIVELY WITH DOCTOR) ───────────────
      // 1. Mark as Completed: ACTIVE
      formComplete.style.display = 'inline';
      btnSubmitComplete.disabled = false;
      btnSubmitComplete.classList.remove('disabled');
      btnSubmitComplete.title = 'Mark consultation as completed. Patient is ready for Optical Dispensing & Checkout.';

      // 2. Start Consultation & Confirm: HIDDEN
      if (formStartConsultation) formStartConsultation.style.display = 'none';
      formConfirm.style.display = 'none';

      // 3. Mark No-Show: DISABLED (patient is currently inside consultation room)
      formNoShow.style.display = 'inline';
      btnSubmitNoShow.disabled = true;
      btnSubmitNoShow.classList.add('disabled');
      btnSubmitNoShow.title = 'Patient is actively being examined in the consultation room.';

      // 4. Cancel: HIDDEN
      formCancel.style.display = 'none';

      // Workflow notice banner
      setNotice(
        '<strong>Consultation In-Progress:</strong> Patient is actively with Doctor. Prescriptions can be charted, and click <em>Mark as Completed</em> once examination concludes.',
        '1px solid rgba(14, 165, 233, 0.35)',
        'rgba(14, 165, 233, 0.1)',
        '#0284c7'
      );

    } else if (appt.status === 'confirmed') {
      // ── CONFIRMED STATUS ────────────────────────────────────────
      if (isWalkin) {
        // Walk-in patient physically in clinic
        if (formStartConsultation) {
          formStartConsultation.style.display = 'inline';
          btnSubmitStartConsultation.disabled = false;
          btnSubmitStartConsultation.classList.remove('disabled');
          btnSubmitStartConsultation.title = 'Start examining this walk-in patient';
        }

        formComplete.style.display = 'inline';
        btnSubmitComplete.disabled = false;
        btnSubmitComplete.classList.remove('disabled');
        btnSubmitComplete.title = 'Mark walk-in consultation as completed';

        formConfirm.style.display = 'none';

        // Walk-in patients are physically present; disable No-Show
        formNoShow.style.display = 'inline';
        btnSubmitNoShow.disabled = true;
        btnSubmitNoShow.classList.add('disabled');
        btnSubmitNoShow.title = 'Marking No-Show is disabled for walk-in patients as they are physically present in the clinic.';

        formCancel.style.display = 'inline';
        btnSubmitCancel.disabled = false;
        btnSubmitCancel.classList.remove('disabled');
        btnSubmitCancel.title = 'Cancel this walk-in encounter';

        setNotice(
          '<strong>Walk-in Patient Waiting:</strong> Patient is present in the clinic. Click <em>Start Consultation</em> when beginning examination, or <em>Mark as Completed</em> when done.',
          '1px solid rgba(245, 158, 11, 0.35)',
          'rgba(245, 158, 11, 0.08)',
          '#d97706'
        );
      } else {
        // Scheduled / Online patient
        if (isAppointmentTimeReached) {
          if (formStartConsultation) {
            formStartConsultation.style.display = 'inline';
            btnSubmitStartConsultation.disabled = false;
            btnSubmitStartConsultation.classList.remove('disabled');
          }
          formComplete.style.display = 'inline';
          btnSubmitComplete.disabled = false;
          btnSubmitComplete.classList.remove('disabled');
          btnSubmitComplete.title = 'Mark consultation as completed';
        } else {
          if (formStartConsultation) formStartConsultation.style.display = 'none';
          formComplete.style.display = 'inline';
          btnSubmitComplete.disabled = true;
          btnSubmitComplete.classList.add('disabled');
          btnSubmitComplete.title = `A consultation cannot be finished before it has actually taken place. Disabled until scheduled appointment time (${formatTime12(appt.appointment_time)}).`;
        }

        formConfirm.style.display = 'none';

        formNoShow.style.display = 'inline';
        if (isPastGrace) {
          btnSubmitNoShow.disabled = false;
          btnSubmitNoShow.classList.remove('disabled');
          btnSubmitNoShow.title = 'Mark patient as No-Show';
        } else {
          btnSubmitNoShow.disabled = true;
          btnSubmitNoShow.classList.add('disabled');
          btnSubmitNoShow.title = `Marking a patient as a no-show prior to appointment time is premature. Available after ${unlockTimeStr} (15-min clinic grace period).`;
        }

        formCancel.style.display = 'inline';
        btnSubmitCancel.disabled = false;
        btnSubmitCancel.classList.remove('disabled');
        btnSubmitCancel.title = 'Cancel this appointment';

        if (!isAppointmentTimeReached) {
          setNotice(
            `<strong>Appointment Confirmed:</strong> Scheduled for <strong>${formatTime12(appt.appointment_time)}</strong>. <em>Mark as Completed</em> unlocks once the scheduled time arrives, and <em>No-Show</em> unlocks after <strong>${unlockTimeStr}</strong> (15-min grace period).`,
            '1px solid rgba(14, 165, 233, 0.3)',
            'rgba(14, 165, 233, 0.08)',
            '#0284c7'
          );
        } else if (!isPastGrace) {
          setNotice(
            `<strong>Consultation Ready:</strong> Scheduled appointment time has arrived. You can now conduct the consultation and mark it as completed. <em>No-Show</em> unlocks after <strong>${unlockTimeStr}</strong>.`,
            '1px solid rgba(16, 185, 129, 0.3)',
            'rgba(16, 185, 129, 0.08)',
            '#059669'
          );
        } else {
          setNotice(null);
        }
      }

    } else if (appt.status === 'no_show') {
      // ── NO-SHOW STATUS (SAFETY / REVERSAL WORKFLOW) ─────────────
      // 1. Revert to Confirmed: ACTIVE
      if (formRevertConfirmed) {
        formRevertConfirmed.style.display = 'inline';
        btnSubmitRevertConfirmed.disabled = false;
        btnSubmitRevertConfirmed.classList.remove('disabled');
        btnSubmitRevertConfirmed.title = 'Revert this No-Show appointment back to Confirmed to resume clinical charting';
      }

      // 2. Mark as Completed: ACTIVE (for delayed charting)
      formComplete.style.display = 'inline';
      btnSubmitComplete.disabled = false;
      btnSubmitComplete.classList.remove('disabled');
      btnSubmitComplete.title = 'Mark consultation as completed if clinical encounter took place but charting was delayed';

      // 3. Confirm, NoShow, Cancel: HIDDEN
      formConfirm.style.display = 'none';
      formNoShow.style.display = 'none';
      formCancel.style.display = 'none';

      // Workflow notice banner
      const isAuto = (appt.notes && appt.notes.includes('[AUTO_NOSHOW]'));
      setNotice(
        `<strong>Appointment Marked as No-Show${isAuto ? ' (by System Automation)' : ''}:</strong> If the patient attended or clinical documentation was delayed, you may <em>Revert to Confirmed</em> or directly <em>Mark as Completed</em>.`,
        '1px solid rgba(139, 92, 246, 0.4)',
        'rgba(139, 92, 246, 0.1)',
        '#c084fc'
      );

    } else {
      // ── COMPLETED, CANCELLED ────────────────────────────────────
      formComplete.style.display = 'none';
      formConfirm.style.display = 'none';
      formNoShow.style.display = 'none';
      formCancel.style.display = 'none';
      if (formRevertConfirmed) formRevertConfirmed.style.display = 'none';

      setNotice(null);
    }

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
          <h6 class="text-white">No Appointments Scheduled</h6>
          <p class="text-muted small">There are no patient bookings recorded for this date.</p>
        </div>
      `;
    } else {
      let html = '<div class="d-flex flex-column gap-3">';
      dayAppts.forEach(appt => {
        html += `
          <div class="cal-info-card p-3 d-flex justify-content-between align-items-center flex-wrap gap-3" style="background:#172033;">
            <div class="d-flex align-items-center gap-3">
              <div class="cal-agenda-time-badge" style="min-width:85px;">
                ${formatTime12(appt.appointment_time)}
              </div>
              <div>
                <h6 class="text-white fw-bold mb-1">${escapeHtml(appt.patient_name)}</h6>
                <small class="text-muted">
                  ${escapeHtml((appt.purpose||'').replace(/_/g, ' '))} &middot; 
                  ${escapeHtml(appt.patient_phone || 'No phone')}
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              ${getStatusBadgeHtml(appt.status)}
              <button type="button" class="btn btn-outline-primary btn-sm px-3 btn-open-single" data-id="${appt.id}">
                <i class="fas fa-eye me-1"></i> Details
              </button>
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

    // Flatpickr Modern Birthdate Picker
    let walkinBirthPicker = null;
    const birthEl = document.getElementById('walkinInputBirthdate');
    if (birthEl && typeof flatpickr !== 'undefined') {
      walkinBirthPicker = flatpickr(birthEl, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'F j, Y',
        altInputClass: 'form-control modern-birthdate-picker',
        maxDate: 'today',
        monthSelectorType: 'dropdown',
        disableMobile: true,
        placeholder: 'Select birthdate (Month / Day / Year)'
      });
    }

    // Strict Alphabetical-Only restriction for Name fields (no numbers or special chars)
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

    formRegisterWalkin.addEventListener('submit', function(e) {
      e.preventDefault();
      if (walkinAlert) {
        walkinAlert.textContent = '';
        walkinAlert.classList.add('d-none');
      }

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

        // Successfully registered!
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

        // 3. If direct to doctor, open consultation modal immediately
        if (data.appointment && data.appointment.status === 'in_progress') {
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

  // ── Certificate of Examination Logic ─────────────────────────
  const issueCertModalEl = document.getElementById('issueCertificateModal');
  let issueCertModalInstance = null;
  if (issueCertModalEl) {
    issueCertModalInstance = new bootstrap.Modal(issueCertModalEl);
  }

  window.openCertificateModal = function(data) {
    if (!issueCertModalEl) return;
    const form = document.getElementById('formIssueCertificate');
    if (form) form.reset();

    const alertBox = document.getElementById('certAlert');
    if (alertBox) alertBox.classList.add('d-none');

    document.getElementById('certPatientId').value = data.patient_id || '';
    document.getElementById('certApptId').value = data.appointment_id || '';
    document.getElementById('certDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('certPatientName').value = data.patient_name || '';

    let age = '';
    if (data.patient_birthdate && data.patient_birthdate !== '0000-00-00') {
      const bdate = new Date(data.patient_birthdate);
      if (!isNaN(bdate.getTime())) {
        const diff = Date.now() - bdate.getTime();
        age = Math.abs(new Date(diff).getUTCFullYear() - 1970);
      }
    }
    document.getElementById('certPatientAge').value = (age !== '' && !isNaN(age)) ? age : (data.patient_age || '');
    document.getElementById('certPatientAddress').value = data.patient_address || '';

    let defaultReason = 'Comprehensive Eye Examination & Refraction';
    const p = (data.purpose || '').toLowerCase();
    if (p.includes('consult')) defaultReason = 'Comprehensive Eye Examination & Refraction';
    else if (p.includes('claim')) defaultReason = 'Eyeglass Prescription Evaluation & Fitting';
    else if (p.includes('lens')) defaultReason = 'Contact Lens Assessment & Fitting';
    else if (p.includes('follow')) defaultReason = 'Follow-up Visual Assessment';
    document.getElementById('certReasonForExam').value = defaultReason;

    document.getElementById('certRequestedBy').value = data.patient_name || '';
    document.getElementById('certPurpose').value = 'Employment / Pre-Employment';

    document.getElementById('certDoctorName').value = 'MARIA LUZ S. GUECO, O.D.';
    document.getElementById('certDoctorTitle').value = 'OPTOMETRIST';
    document.getElementById('certDoctorLicenseNo').value = 'LIC. NO. 4385';

    if (appointmentModal) appointmentModal.hide();
    setTimeout(() => {
      if (issueCertModalInstance) issueCertModalInstance.show();
    }, 250);
  };

  const btnCertUsePatientName = document.getElementById('btnCertUsePatientName');
  if (btnCertUsePatientName) {
    btnCertUsePatientName.addEventListener('click', function() {
      const ptName = document.getElementById('certPatientName').value;
      if (ptName) {
        document.getElementById('certRequestedBy').value = ptName;
      }
    });
  }

  const formIssueCertificate = document.getElementById('formIssueCertificate');
  if (formIssueCertificate) {
    formIssueCertificate.addEventListener('submit', function(e) {
      e.preventDefault();
      const certAlert = document.getElementById('certAlert');
      if (certAlert) certAlert.classList.add('d-none');

      const btnSubmit = document.getElementById('btnSubmitIssueCert');
      btnSubmit.disabled = true;
      btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Issuing Certificate...';

      const formData = new FormData(formIssueCertificate);
      fetch('../api/issue_certificate.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="fas fa-print me-1"></i> Issue & Print Certificate';

        if (!data.success) {
          if (certAlert) {
            certAlert.textContent = data.error || 'Failed to issue certificate.';
            certAlert.classList.remove('d-none');
          }
          return;
        }

        if (issueCertModalInstance) issueCertModalInstance.hide();
        formIssueCertificate.reset();

        window.open(data.print_url, '_blank');

        Swal.fire({
          title: 'Certificate Issued!',
          text: `Certificate ${data.certificate_no} has been recorded and print preview opened.`,
          icon: 'success',
          confirmButtonColor: 'var(--clr-primary)',
          timer: 3000
        });
      })
      .catch(err => {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = '<i class="fas fa-print me-1"></i> Issue & Print Certificate';
        if (certAlert) {
          certAlert.textContent = 'Network or server error while generating certificate.';
          certAlert.classList.remove('d-none');
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

  // Initial render
  render();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
