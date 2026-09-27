<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requirePatientLogin();

$db        = getDB();
ensurePrescriptionsSchema($db);
$patientId = $_SESSION['patient_id'];
$today     = date('Y-m-d');

// Flash message
$flashMsg = $flashType = $flashTitle = '';
if (isset($_SESSION['flash_msg'])) {
    $flashMsg   = $_SESSION['flash_msg'];
    $flashType  = $_SESSION['flash_type'] ?? 'success';
    $flashTitle = $_SESSION['flash_title'] ?? null;
    unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['flash_title']);
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // Book appointment
    if ($action === 'book') {
        $apptDate = $_POST['appointment_date'] ?? '';
        $apptTime = $_POST['appointment_time'] ?? '';
        $purpose  = $_POST['purpose'] ?? '';
        $service  = html_entity_decode(strip_tags(trim($_POST['service'] ?? '')), ENT_QUOTES, 'UTF-8');
        $rawNotes = html_entity_decode(strip_tags(trim($_POST['notes'] ?? '')), ENT_QUOTES, 'UTF-8');

        // Compose notes to cleanly preserve the selected optical service
        $notes = '';
        if (!empty($service)) {
            $notes = "Service: " . $service;
        }
        if (!empty($rawNotes)) {
            $notes = $notes ? ($notes . "\n" . $rawNotes) : $rawNotes;
        }

        if (empty($apptDate) || empty($apptTime) || empty($purpose)) {
            $_SESSION['flash_msg']   = 'Please complete all required fields.';
            $_SESSION['flash_type']  = 'error';
            $_SESSION['flash_title'] = 'Booking Notice';
        } elseif ($apptDate <= $today) {
            $_SESSION['flash_msg']   = 'Please select a future date (tomorrow or later).';
            $_SESSION['flash_type']  = 'error';
            $_SESSION['flash_title'] = 'Booking Notice';
        } else {
            $dayOfWeek = date('N', strtotime($apptDate)); // 6 = Saturday, 7 = Sunday
            if ($dayOfWeek >= 6) {
                $_SESSION['flash_msg']   = 'We are closed on weekends (Saturday & Sunday). Please choose a weekday.';
                $_SESSION['flash_type']  = 'error';
                $_SESSION['flash_title'] = 'Booking Notice';
            } else {
                $check = $db->prepare("SELECT id FROM appointments WHERE appointment_date=? AND appointment_time=? AND status NOT IN ('cancelled','no_show')");
                $check->execute([$apptDate, $apptTime]);
                if ($check->fetch()) {
                    $_SESSION['flash_msg']   = 'This time slot is already taken. Please choose another.';
                    $_SESSION['flash_type']  = 'error';
                    $_SESSION['flash_title'] = 'Slot Unavailable';
                } else {
                    $db->prepare("INSERT INTO appointments (patient_id,appointment_date,appointment_time,purpose,notes,status) VALUES (?,?,?,?,?,'pending')")->execute([$patientId,$apptDate,$apptTime,$purpose,$notes]);
                    $_SESSION['flash_msg']   = 'Appointment booked successfully! Our staff will confirm it shortly.';
                    $_SESSION['flash_type']  = 'success';
                    $_SESSION['flash_title'] = 'Success!';
                    $_SESSION['open_tab']    = 'history';
                }
            }
        }
        header('Location: dashboard.php');
        exit;
    } elseif ($action === 'cancel') {
        // Cancel appointment
        $apptId = (int)($_POST['appt_id'] ?? 0);
        $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=? AND patient_id=? AND status IN ('pending','confirmed')")->execute([$apptId, $patientId]);
        $_SESSION['flash_msg']   = 'Appointment cancelled.';
        $_SESSION['flash_type']  = 'success';
        $_SESSION['flash_title'] = 'Cancelled';
        $_SESSION['open_tab']    = 'history';
        header('Location: dashboard.php');
        exit;
    } elseif ($action === 'edit') {
        // Edit appointment — PENDING ONLY
        $apptId   = (int)($_POST['appt_id'] ?? 0);
        $apptDate = $_POST['appointment_date'] ?? '';
        $apptTime = $_POST['appointment_time'] ?? '';
        $purpose  = $_POST['purpose'] ?? '';
        $notes    = sanitize($_POST['notes'] ?? '');

        // Verify appointment is still pending and belongs to this patient
        $chk = $db->prepare("SELECT id, appointment_date, DATE_FORMAT(appointment_time, '%H:%i') as appt_time, purpose, notes FROM appointments WHERE id=? AND patient_id=? AND status='pending'");
        $chk->execute([$apptId, $patientId]);
        $curr = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$curr) {
            $_SESSION['flash_msg']   = 'This appointment can no longer be edited. The clinic may have already confirmed it.';
            $_SESSION['flash_type']  = 'error';
            $_SESSION['flash_title'] = 'Notice';
        } elseif (empty($apptDate) || empty($apptTime) || empty($purpose)) {
            $_SESSION['flash_msg']   = 'Please fill in all required fields.';
            $_SESSION['flash_type']  = 'error';
            $_SESSION['flash_title'] = 'Notice';
        } elseif ($apptDate <= $today) {
            $_SESSION['flash_msg']   = 'Please select a future date.';
            $_SESSION['flash_type']  = 'error';
            $_SESSION['flash_title'] = 'Notice';
        } else {
            // Check if any changes were actually made
            $isSameDate    = ($curr['appointment_date'] === $apptDate);
            $isSameTime    = ($curr['appt_time'] === substr($apptTime, 0, 5));
            $isSamePurpose = ($curr['purpose'] === $purpose);
            $isSameNotes   = (trim((string)$curr['notes']) === trim((string)$notes));

            if ($isSameDate && $isSameTime && $isSamePurpose && $isSameNotes) {
                $_SESSION['flash_msg']   = 'No changes were made to your appointment.';
                $_SESSION['flash_type']  = 'info';
                $_SESSION['flash_title'] = 'No Changes Detected';
            } else {
                // Check slot (exclude this appointment's existing slot)
                $slotChk = $db->prepare("SELECT id FROM appointments WHERE appointment_date=? AND appointment_time=? AND id!=? AND status NOT IN ('cancelled','no_show')");
                $slotChk->execute([$apptDate, $apptTime, $apptId]);
                if ($slotChk->fetch()) {
                    $_SESSION['flash_msg']   = 'That time slot is already taken. Please pick another.';
                    $_SESSION['flash_type']  = 'error';
                    $_SESSION['flash_title'] = 'Slot Unavailable';
                } else {
                    $db->prepare("UPDATE appointments SET appointment_date=?,appointment_time=?,purpose=?,notes=? WHERE id=? AND patient_id=? AND status='pending'")
                       ->execute([$apptDate, $apptTime, $purpose, $notes, $apptId, $patientId]);
                    $_SESSION['flash_msg']   = 'Appointment updated successfully!';
                    $_SESSION['flash_type']  = 'success';
                    $_SESSION['flash_title'] = 'Updated!';
                }
            }
        }
        // Re-open history tab after edit
        $_SESSION['open_tab'] = 'history';
        header('Location: dashboard.php');
        exit;
    }
}

// AJAX: check taken slots
if (isset($_GET['check_date'])) {
    $check = $db->prepare("SELECT DATE_FORMAT(appointment_time, '%H:%i') AS appt_time FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show')");
    $check->execute([$_GET['check_date']]);
    header('Content-Type: application/json');
    echo json_encode($check->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

// Data
$allSlots = explode(',', getSetting('appointment_slots') ?? '09:00,09:30,10:00,10:30,11:00,11:30,13:00,13:30,14:00,14:30,15:00,15:30,16:00,16:30');
$slotCount = count($allSlots);

// Pre-fetch dates where all slots are already taken
$fullDatesStmt = $db->prepare("SELECT appointment_date FROM appointments WHERE appointment_date >= CURDATE() AND status NOT IN ('cancelled','no_show') GROUP BY appointment_date HAVING COUNT(*) >= ?");
$fullDatesStmt->execute([$slotCount]);
$fullyBookedDates = $fullDatesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$myAppts = $db->prepare("
    SELECT a.*,
           (
               SELECT rx.id 
               FROM prescriptions rx 
               WHERE rx.appointment_id = a.id 
                  OR (rx.patient_id = a.patient_id AND DATE(rx.created_at) = a.appointment_date)
               ORDER BY (rx.appointment_id = a.id) DESC, rx.id DESC 
               LIMIT 1
           ) AS rx_id
    FROM appointments a 
    WHERE a.patient_id = ? 
    ORDER BY a.appointment_date DESC, a.appointment_time DESC 
    LIMIT 30
");
$myAppts->execute([$patientId]);
$myAppts = $myAppts->fetchAll(PDO::FETCH_ASSOC);

// Filter upcoming and past appointments
$upcoming = array_filter($myAppts, fn($a) => $a['appointment_date'] >= $today && !in_array($a['status'], ['cancelled', 'no_show', 'completed']));
// Sort upcoming chronologically ASC (closest/earliest upcoming first)
usort($upcoming, function($a, $b) {
    if ($a['appointment_date'] === $b['appointment_date']) {
        return strcmp($a['appointment_time'], $b['appointment_time']);
    }
    return strcmp($a['appointment_date'], $b['appointment_date']);
});

// Past & completed appointments remain DESC (most recent past visit first)
$past = array_values(array_filter($myAppts, fn($a) => $a['appointment_date'] < $today || in_array($a['status'], ['cancelled', 'no_show', 'completed'])));

// The closest/soonest upcoming appointment for the ticket card
$nextAppt = !empty($upcoming) ? $upcoming[0] : null;

// Parse service from appointment notes if present & compute countdown
$nextService = '';
$countdownText = '';
$countdownBadge = '';
if ($nextAppt) {
    if (!empty($nextAppt['notes']) && preg_match('/^Service:\s*(.+)$/m', $nextAppt['notes'], $m)) {
        $nextService = trim($m[1]);
    }
    $daysDiff = (int)round((strtotime($nextAppt['appointment_date']) - strtotime($today)) / 86400);
    if ($daysDiff === 0) {
        $countdownText = "Today at " . formatTime($nextAppt['appointment_time']);
        $countdownBadge = "today";
    } elseif ($daysDiff === 1) {
        $countdownText = "Tomorrow at " . formatTime($nextAppt['appointment_time']);
        $countdownBadge = "tomorrow";
    } elseif ($daysDiff > 1) {
        $countdownText = "In " . $daysDiff . " days (" . date('D, M j', strtotime($nextAppt['appointment_date'])) . ")";
        $countdownBadge = "upcoming";
    } else {
        $countdownText = formatTime($nextAppt['appointment_time']);
        $countdownBadge = "upcoming";
    }
}

// Fetch active booking categories for appointment wizard & filters
$bookingCategories = [];
try {
    $catQuery = $db->query("SELECT category_key, icon, name, description FROM clinic_booking_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    if ($catQuery) {
        $bookingCategories = $catQuery->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

if (empty($bookingCategories)) {
    $bookingCategories = [
        ['category_key' => 'consultation', 'icon' => 'fa-user-doctor', 'name' => 'Eye Consultation & Check-up', 'description' => 'Comprehensive examination, visual acuity test, and licensed doctor consultation.'],
        ['category_key' => 'eyeglass_claim', 'icon' => 'fa-glasses', 'name' => 'Eyeglasses & Frames', 'description' => 'Prescription frame selection, lens upgrades, claiming ready spectacles.'],
        ['category_key' => 'contact_lens_fitting', 'icon' => 'fa-circle-dot', 'name' => 'Contact Lens Care', 'description' => 'Cornea curvature measurement, trial lens fitting, and supply orders.'],
        ['category_key' => 'follow_up', 'icon' => 'fa-rotate-right', 'name' => 'Follow-up Visit', 'description' => 'Post-examination check, lens adaptation review, and progress evaluation.'],
        ['category_key' => 'other', 'icon' => 'fa-screwdriver-wrench', 'name' => 'General Optical Services', 'description' => 'Frame repairs, ultrasonic bath cleaning, screw adjustments, or inquiries.'],
    ];
}

// Compute Clinic Open / Closed status (PST: Mon-Fri 9:00 AM - 5:00 PM)
$currentDayOfWeek = (int)date('N'); // 1=Mon ... 7=Sun
$currentHour = (int)date('G');
$currentMinute = (int)date('i');
$currentTimeNum = $currentHour * 100 + $currentMinute;

$isOpenNow = ($currentDayOfWeek >= 1 && $currentDayOfWeek <= 5 && $currentTimeNum >= 900 && $currentTimeNum < 1700);
if ($isOpenNow) {
    $clinicStatusText = 'Open Now · Closes 5:00 PM';
    $clinicStatusClass = 'open';
} else {
    if ($currentDayOfWeek >= 1 && $currentDayOfWeek < 5 && $currentTimeNum < 900) {
        $clinicStatusText = 'Closed Now · Opens 9:00 AM';
    } elseif ($currentDayOfWeek >= 1 && $currentDayOfWeek < 5 && $currentTimeNum >= 1700) {
        $clinicStatusText = 'Closed Now · Opens Tomorrow 9:00 AM';
    } else {
        $clinicStatusText = 'Closed Now · Opens Mon 9:00 AM';
    }
    $clinicStatusClass = 'closed';
}

$totalAppts     = count($myAppts);
$upcomingCount  = count($upcoming);
$completedCount = count(array_filter($myAppts, fn($a) => $a['status']==='completed'));
$pendingCount   = count(array_filter($myAppts, fn($a) => $a['status']==='pending'));

// Patient info
$patient = $db->prepare("SELECT * FROM patients WHERE id=?"); $patient->execute([$patientId]); $patient = $patient->fetch();
$patientFullName  = $patient ? getPatientDisplayName($patient) : ($_SESSION['patient_name'] ?? 'Patient');
$patientAge       = !empty($patient['birthdate']) ? (new DateTime($patient['birthdate']))->diff(new DateTime())->y : null;

// Prescriptions for this patient
$patientRxStmt = $db->prepare("
    SELECT rx.*, u.full_name as doctor_name, u.role as doctor_role 
    FROM prescriptions rx 
    LEFT JOIN users u ON u.id = rx.doctor_id 
    WHERE rx.patient_id = ? 
    ORDER BY rx.created_at DESC
");
$patientRxStmt->execute([$patientId]);
$patientRxList = $patientRxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$patientRxStmt->closeCursor();
$latestRx      = !empty($patientRxList) ? $patientRxList[0] : null;
$totalRxCount  = count($patientRxList);

if (($_GET['tab'] ?? '') === 'prescriptions') {
    $_SESSION['open_tab'] = 'prescriptions';
}

$patientLoginCount = (int)($patient['login_count'] ?? 1);
$isFirstLogin      = ($patientLoginCount <= 1);
$greetingPrefix    = $isFirstLogin ? 'Welcome' : 'Welcome back';

$patientFirstName  = !empty($patient['first_name']) 
    ? trim($patient['first_name']) 
    : sanitize(explode(' ', $_SESSION['patient_name'] ?? ($patient['full_name'] ?? 'Patient'))[0]);
if (empty($patientFirstName)) {
    $patientFirstName = 'Patient';
}
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patient Portal — Gueco Optical Clinic</title>
  <meta name="description" content="Book and manage your eye care appointments at Gueco Optical Clinic, Capas, Tarlac.">
  
  <!-- Immediate Theme Initialization & Caret Browsing Prevention -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem("gueco_theme") || localStorage.getItem("gueco-theme") || localStorage.getItem("theme") || localStorage.getItem("guecoTheme");
        if (!theme) {
          var m = document.cookie.match(/(?:^|;\s*)gueco_theme=([^;]+)/);
          theme = m ? m[1] : "<?= $currentTheme ?>";
        }
        if (theme !== "light" && theme !== "dark") theme = "dark";
        document.documentElement.setAttribute("data-theme", theme);
      } catch (e) {
        document.documentElement.setAttribute("data-theme", "<?= $currentTheme ?>");
      }
    })();

    // Prevent accidental browser Caret Browsing (F7) activation
    window.addEventListener('keydown', function(e) {
      if (e.key === 'F7' || e.keyCode === 118) {
        e.preventDefault();
      }
    });
  </script>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <!-- SweetAlert2 (Modal Popups) -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

    /* ─── Universal Caret & Text-Selection Prevention (Patient Portal) ─── */
    *, *::before, *::after {
      caret-color: transparent;
    }

    body, h1, h2, h3, h4, h5, h6, p, span, div, a, label, li, ul, ol, section, main, header, footer, nav, table, tr, th, td,
    button,
    [type="button"],
    [type="reset"],
    [type="submit"],
    .tab-btn,
    .step-node,
    .slot-btn,
    .qdate-btn,
    .btn-wizard-next,
    .btn-wizard-back,
    .btn-book,
    .btn-ticket-action,
    .btn-close-modal,
    .btn-modal-cancel,
    .btn-modal-save,
    .edit-btn,
    .cancel-btn,
    .theme-btn,
    .user-chip,
    .dropdown-item,
    .badge,
    .welcome-date-badge,
    .ticket-stub-left,
    .ticket-tag,
    .ticket-countdown,
    .form-card-header,
    .patient-ticket-card,
    .card {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }

    h1, h2, h3, h4, h5, h6, p, label, .card, table {
      cursor: default;
    }

    button,
    [type="button"],
    [type="reset"],
    [type="submit"],
    a,
    .tab-btn,
    .step-node,
    .slot-btn,
    .qdate-btn,
    .btn-wizard-next,
    .btn-wizard-back,
    .btn-book,
    .btn-ticket-action,
    .btn-close-modal,
    .btn-modal-cancel,
    .btn-modal-save,
    .edit-btn,
    .cancel-btn,
    .theme-btn,
    .user-chip,
    .dropdown-item {
      cursor: pointer;
    }

    input,
    textarea,
    [contenteditable="true"],
    .allow-select {
      -webkit-user-select: text !important;
      -moz-user-select: text !important;
      -ms-user-select: text !important;
      user-select: text !important;
      cursor: text !important;
      caret-color: auto !important;
    }

    select {
      -webkit-user-select: auto !important;
      -moz-user-select: auto !important;
      -ms-user-select: auto !important;
      user-select: auto !important;
      cursor: pointer !important;
    }

    :root { 
      /* Brand Color Tokens */
      --clr-primary:       #235EAE;
      --clr-primary-light: #00ADEF;
      --clr-primary-dark:  #183B75;
      --clr-secondary:     #00ADEF;
      
      --clr-success:       #10B981;
      --clr-danger:        #EF4444;
      --clr-warning:       #F59E0B;
      --clr-info:          #00ADEF;
      --clr-purple:        #8B5CF6;
    }

    /* ============================================================
       HIGH VISIBILITY DARK THEME (Luminous Sky Blue & 3D Midnight Slate)
       ============================================================ */
    [data-theme="dark"] {
      /* In Dark Mode: Blues are luminous, vibrant Light Blue (Sky / Electric Cyan) */
      --clr-primary:       #38BDF8; /* Electric light sky blue */
      --clr-primary-light: #7DD3FC;
      --clr-primary-dark:  #0284C7;
      --clr-secondary:     #00ADEF;
      --clr-brand-gradient: linear-gradient(135deg, #0284C7 0%, #38BDF8 100%);
      --clr-blue-glow:     rgba(56, 189, 248, 0.35);

      --bg-body:           #0B1120; /* Deep midnight canvas */
      --bg-card:           #162238; /* Elevated crisp navy slate surface */
      --bg-card-solid:     #162238;
      --bg-card-glass:     rgba(22, 34, 58, 0.96);
      --bg-card-elevated:  #1C2C48;
      --bg-topbar:         rgba(13, 21, 38, 0.94);
      --bg-hover:          rgba(56, 189, 248, 0.12);
      --bg-input:          #142034;
      --bg-input-focus:    #1A2844;

      --text-primary:      #FFFFFF;
      --text-secondary:    #F1F5F9;
      --text-muted:        #CBD5E1; /* High readability, no fading */
      --text-subtle:       #94A3B8;

      --border-color:      rgba(255, 255, 255, 0.16);
      --border-light:      rgba(255, 255, 255, 0.09);
      --border-glow:       rgba(56, 189, 248, 0.40);
      --border-subtle:     rgba(56, 189, 248, 0.28);

      --shadow-md:         0 16px 36px -4px rgba(0, 0, 0, 0.55);
      --shadow-card:       0 10px 30px -4px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.12), 0 0 0 1px rgba(255, 255, 255, 0.10);
      --shadow-glow:       0 4px 14px rgba(56, 189, 248, 0.25);

      /* 3D Push-Button Shadows for Dark Mode */
      --btn-3d-shadow:        0 4px 0 #0369A1, 0 8px 20px rgba(56, 189, 248, 0.38);
      --btn-3d-shadow-hover:  0 6px 0 #0369A1, 0 12px 26px rgba(56, 189, 248, 0.48);
      --btn-3d-shadow-active: 0 1px 0 #0369A1, 0 3px 8px rgba(56, 189, 248, 0.3);
    }

    /* ============================================================
       BALANCED HIGH-CONTRAST LIGHT THEME (Crisp Porcelain)
       ============================================================ */
    [data-theme="light"] {
      --clr-primary:       #235EAE; /* Brand royal sapphire */
      --clr-primary-light: #00ADEF;
      --clr-primary-dark:  #183B75;
      --clr-secondary:     #00ADEF;
      --clr-brand-gradient: linear-gradient(135deg, #235EAE 0%, #00ADEF 100%);
      --clr-blue-glow:     rgba(35, 94, 174, 0.25);

      --bg-body:           #F8FAFC; /* Clean crisp slate-50 */
      --bg-card:           #FFFFFF; /* Pure white cards */
      --bg-card-solid:     #FFFFFF;
      --bg-card-glass:     #FFFFFF;
      --bg-card-elevated:  #FFFFFF;
      --bg-topbar:         rgba(255, 255, 255, 0.96);
      --bg-hover:          rgba(35, 94, 174, 0.06);
      --bg-input:          #F8FAFC;
      --bg-input-focus:    #FFFFFF;

      --text-primary:      #0F172A; /* Slate-900: high contrast */
      --text-secondary:    #334155; /* Slate-700 */
      --text-muted:        #64748B; /* Slate-500 */
      --text-subtle:       #94A3B8; /* Slate-400 */

      --border-color:      #E2E8F0; /* Clean distinct card borders */
      --border-light:      #F1F5F9;
      --border-glow:       rgba(35, 94, 174, 0.35);
      --border-subtle:     #CBD5E1;

      --shadow-md:         0 12px 28px -4px rgba(15, 23, 42, 0.08);
      --shadow-card:       0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 0 0 1px #E2E8F0;
      --shadow-glow:       0 4px 14px rgba(35, 94, 174, 0.15);

      /* 3D Push-Button Shadows for Light Mode */
      --btn-3d-shadow:        0 4px 0 #183B75, 0 8px 18px rgba(35, 94, 174, 0.25);
      --btn-3d-shadow-hover:  0 6px 0 #183B75, 0 12px 24px rgba(35, 94, 174, 0.35);
      --btn-3d-shadow-active: 0 1px 0 #183B75, 0 3px 8px rgba(35, 94, 174, 0.2);
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      font-size: 15px;
      line-height: 1.6;
      transition: background-color 0.25s ease, color 0.25s ease;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* Ambient Subtle Shading (No smeared colored puddles) */
    .bg-mesh {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
    }
    [data-theme="dark"] .bg-mesh {
      background:
        radial-gradient(ellipse 80% 60% at 5% 0%, rgba(35, 94, 174, 0.12) 0%, transparent 60%),
        radial-gradient(ellipse 70% 55% at 95% 100%, rgba(0, 173, 239, 0.08) 0%, transparent 60%);
    }
    [data-theme="light"] .bg-mesh {
      background: none; /* Keep light mode clean, bright and pure */
    }

    /* TOPBAR */
    .topbar {
      position: sticky; top: 0; z-index: 200;
      background: var(--bg-topbar);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px) saturate(180%);
      border-bottom: 1.5px solid var(--border-color);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 32px; height: 72px;
      transition: all 0.25s ease;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    [data-theme="dark"] .topbar {
      box-shadow: 0 4px 20px rgba(0,0,0,0.35);
    }
    .topbar-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
    .topbar-logo {
      width: 42px; height: 42px; object-fit: contain; border-radius: 12px;
      border: 1.5px solid var(--border-color);
    }
    .topbar-name { font-weight: 900; font-size: 1.12rem; color: var(--text-primary); letter-spacing: -0.015em; }
    .topbar-sub  { font-size: .78rem; color: var(--text-muted); font-weight: 600; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }

    .theme-btn {
      width: 42px; height: 42px; border-radius: 50%;
      border: 1.5px solid var(--border-color);
      background: var(--bg-card); color: var(--text-muted); cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: 1rem;
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .theme-btn:hover {
      border-color: var(--clr-primary); color: var(--clr-primary);
      transform: scale(1.06);
    }
    [data-theme="dark"] .theme-btn:hover {
      border-color: var(--clr-primary-light); color: var(--clr-primary-light);
    }

    .user-chip {
      display: flex; align-items: center; gap: 10px;
      background: var(--bg-card); border: 1.5px solid var(--border-color);
      border-radius: 100px; padding: 6px 18px 6px 7px; cursor: pointer;
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    }
    .user-chip:hover { border-color: var(--clr-primary); }
    [data-theme="dark"] .user-chip:hover { border-color: var(--border-glow); }
    .user-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-weight: 800; font-size: .84rem;
      box-shadow: 0 2px 6px rgba(35, 94, 174, 0.25);
      overflow: hidden;
      flex-shrink: 0;
    }
    .user-avatar img {
      width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%;
    }
    .user-name { font-size: .92rem; font-weight: 800; color: var(--text-primary); }

    .user-dropdown { position: relative; }
    .user-dropdown-menu {
      position: absolute; top: calc(100% + 10px); right: 0;
      background: var(--bg-card); border: 1.5px solid var(--border-color);
      border-radius: 16px; box-shadow: var(--shadow-md);
      width: 230px; padding: 8px;
      display: flex; flex-direction: column; gap: 4px;
      opacity: 0; visibility: hidden; transform: translateY(-8px);
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1); z-index: 100;
    }
    .user-dropdown.open .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-item {
      padding: 10px 14px; border-radius: 10px; display: flex; align-items: center; gap: 12px;
      color: var(--text-primary); text-decoration: none; font-size: .88rem; font-weight: 700;
      background: none; border: none; width: 100%; text-align: left; cursor: pointer;
      transition: all .15s;
    }
    .dropdown-item i { font-size: 1.05rem; color: var(--clr-primary); width: 20px; text-align: center; }
    [data-theme="dark"] .dropdown-item i { color: var(--clr-primary-light); }
    .dropdown-item:hover { background: var(--bg-hover); color: var(--clr-primary); transform: translateX(3px); }
    [data-theme="dark"] .dropdown-item:hover { color: var(--clr-primary-light); }
    .dropdown-item.danger { color: var(--clr-danger); }
    .dropdown-item.danger i { color: var(--clr-danger); }
    .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.12); color: var(--clr-danger); }

    /* PAGE WRAPPER */
    .page-wrap { position: relative; z-index: 1; max-width: 1140px; margin: 0 auto; padding: 34px 24px 85px; }

    /* WELCOME HEADER */
    .welcome-header {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 16px; margin-bottom: 26px;
    }
    .welcome-title {
      font-size: 1.7rem; font-weight: 900; color: var(--text-primary);
      letter-spacing: -0.025em; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;
    }
    .welcome-subtitle {
      font-size: .92rem; color: var(--text-muted); font-weight: 600; margin: 0;
    }
    .welcome-date-badge {
      display: inline-flex; align-items: center; gap: 10px;
      background: var(--bg-card); border: 1.5px solid var(--border-color);
      border-radius: 100px; padding: 9px 20px; font-size: .86rem; font-weight: 700;
      color: var(--text-primary); box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .welcome-date-badge i { color: var(--clr-primary); font-size: 1rem; }
    [data-theme="dark"] .welcome-date-badge i { color: var(--clr-primary-light); }

    /* ============================================================
       NEXT APPOINTMENT TICKET (BOARDING-PASS ARCHITECTURE)
       ============================================================ */
    .ticket-card {
      display: flex;
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 22px;
      overflow: hidden;
      margin-bottom: 28px;
      position: relative;
      box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.08);
      transition: all .25s ease;
    }
    [data-theme="dark"] .ticket-card {
      border-color: rgba(255, 255, 255, 0.18);
      box-shadow: 0 16px 44px -8px rgba(0, 0, 0, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.12);
    }
    .ticket-stub-left {
      padding: 24px 28px;
      background: #F8FAFC;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      text-align: center; min-width: 155px; position: relative;
      border-right: 2px dashed #CBD5E1; flex-shrink: 0;
    }
    [data-theme="dark"] .ticket-stub-left {
      background: #1C2C4A;
      border-right: 2px dashed rgba(255, 255, 255, 0.22);
    }
    .ticket-day {
      font-size: 2.7rem; font-weight: 900; color: var(--text-primary);
      line-height: 1; letter-spacing: -0.04em;
    }
    .ticket-month {
      font-size: .88rem; font-weight: 900; text-transform: uppercase;
      letter-spacing: .12em; color: var(--clr-primary); margin-top: 4px;
    }
    [data-theme="dark"] .ticket-month { color: #38BDF8; }
    .ticket-weekday {
      font-size: .82rem; font-weight: 700; color: var(--text-secondary); margin-top: 2px;
    }
    .ticket-time-chip {
      display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;
      padding: 5px 12px; border-radius: 100px;
      font-size: .8rem; font-weight: 800;
      color: #1E40AF; background: #DBEAFE; border: 1.5px solid #BFDBFE;
    }
    [data-theme="dark"] .ticket-time-chip {
      color: #38BDF8; background: rgba(0, 173, 239, 0.22); border-color: rgba(0, 173, 239, 0.45);
    }
    .ticket-notch-top, .ticket-notch-bottom {
      position: absolute; right: -11px; width: 22px; height: 22px;
      border-radius: 50%; background: var(--bg-body); z-index: 2;
    }
    .ticket-notch-top { top: -11px; }
    .ticket-notch-bottom { bottom: -11px; }

    .ticket-body {
      flex: 1; padding: 22px 30px; display: flex; flex-direction: column;
      justify-content: center; gap: 10px; position: relative;
    }
    .ticket-header-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .ticket-tag {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: .75rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .08em;
      color: #0369A1; background: #E0F2FE; border: 1.5px solid #BAE6FD;
      padding: 4px 12px; border-radius: 100px;
    }
    [data-theme="dark"] .ticket-tag {
      color: #38BDF8; background: rgba(0, 173, 239, 0.2); border-color: rgba(0, 173, 239, 0.4);
    }
    .ticket-countdown {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: .78rem; font-weight: 800; padding: 4px 14px; border-radius: 100px;
    }
    .ticket-countdown.today {
      background: #FEE2E2; color: #991B1B; border: 1.5px solid #FECACA;
    }
    [data-theme="dark"] .ticket-countdown.today {
      background: rgba(239, 68, 68, 0.22); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.5);
    }
    .ticket-countdown.tomorrow {
      background: #E0F2FE; color: #0369A1; border: 1.5px solid #BAE6FD;
    }
    [data-theme="dark"] .ticket-countdown.tomorrow {
      background: rgba(0, 173, 239, 0.22); color: #38BDF8; border-color: rgba(0, 173, 239, 0.5);
    }
    .ticket-countdown.upcoming {
      background: #D1FAE5; color: #065F46; border: 1.5px solid #A7F3D0;
    }
    [data-theme="dark"] .ticket-countdown.upcoming {
      background: rgba(16, 185, 129, 0.2); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.45);
    }

    .ticket-title { font-size: 1.35rem; font-weight: 900; color: var(--text-primary); line-height: 1.3; }
    .ticket-service-badge {
      display: inline-flex; align-items: center; gap: 7px;
      font-size: .84rem; font-weight: 700;
      color: #1E3A8A; background: #EFF6FF; border: 1.5px solid #DBEAFE;
      padding: 5px 14px; border-radius: 10px; width: fit-content;
    }
    [data-theme="dark"] .ticket-service-badge {
      color: #F1F5F9; background: rgba(35, 94, 174, 0.35); border-color: rgba(56, 189, 248, 0.35);
    }
    .ticket-meta {
      display: flex; align-items: center; gap: 20px; font-size: .84rem;
      color: var(--text-muted); flex-wrap: wrap; margin-top: 2px;
    }
    .ticket-meta-item { display: flex; align-items: center; gap: 7px; font-weight: 600; color: var(--text-secondary); }
    .ticket-meta-item i { color: var(--clr-primary); font-size: .9rem; }
    [data-theme="dark"] .ticket-meta-item i { color: #38BDF8; }

    .ticket-stub-right {
      padding: 24px 28px; display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 14px;
      min-width: 185px; border-left: 2px dashed #CBD5E1;
      background: #F8FAFC; position: relative; flex-shrink: 0;
    }
    [data-theme="dark"] .ticket-stub-right {
      background: #141F35;
      border-left: 2px dashed rgba(255, 255, 255, 0.22);
    }

    .ticket-status-pill {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 8px 18px; border-radius: 100px;
      font-size: .8rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
    }
    .ticket-status-pill.status-confirmed {
      background: #ECFDF5; color: #065F46; border: 1.5px solid #A7F3D0;
    }
    [data-theme="dark"] .ticket-status-pill.status-confirmed {
      background: rgba(16, 185, 129, 0.25); color: #A7F3D0; border-color: rgba(16, 185, 129, 0.5);
    }
    .ticket-status-pill.status-pending {
      background: #FFF7ED; color: #C2410C; border: 1.5px solid #FDBA74;
      box-shadow: 0 2px 6px rgba(234, 88, 12, 0.12);
    }
    [data-theme="dark"] .ticket-status-pill.status-pending {
      background: rgba(249, 115, 22, 0.2); color: #FDBA74; border-color: rgba(251, 146, 60, 0.5);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .status-dot.dot-green { background: #10B981; }
    .status-dot.dot-amber, .status-dot.dot-purple { background: #EA580C; }
    [data-theme="dark"] .status-dot.dot-amber, [data-theme="dark"] .status-dot.dot-purple { background: #FB923C; }
    .status-dot.dot-red { background: #EF4444; }
    .status-badge-icon { font-size: .8rem; display: inline-flex; align-items: center; }

    .btn-ticket-action {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 20px; border-radius: 12px; font-size: .84rem; font-weight: 800;
      color: #fff; background: var(--clr-brand-gradient);
      border: none; cursor: pointer; box-shadow: var(--btn-3d-shadow);
      transition: all .15s ease; text-decoration: none;
    }
    .btn-ticket-action:hover {
      transform: translateY(-2px); box-shadow: var(--btn-3d-shadow-hover); color: #fff;
    }
    .btn-ticket-action:active {
      transform: translateY(2px); box-shadow: var(--btn-3d-shadow-active);
    }

    /* ============================================================
       MODERN BENTO METRICS GRID (Ultra-Modern Glassmorphic Cards)
       ============================================================ */
    .bento-stats-grid {
      display: grid !important;
      grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
      gap: 16px !important;
      margin-bottom: 28px !important;
    }

    @media (max-width: 1200px) and (min-width: 768px) {
      .bento-stats-grid {
        grid-template-columns: repeat(6, 1fr) !important;
      }
      .bento-stats-grid .modern-stat-card:nth-child(1),
      .bento-stats-grid .modern-stat-card:nth-child(2),
      .bento-stats-grid .modern-stat-card:nth-child(3) {
        grid-column: span 2 !important;
      }
      .bento-stats-grid .modern-stat-card:nth-child(4),
      .bento-stats-grid .modern-stat-card:nth-child(5) {
        grid-column: span 3 !important;
      }
    }

    .modern-stat-card {
      position: relative;
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 22px;
      padding: 20px 20px 18px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 14px;
      transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
      cursor: pointer;
      user-select: none;
    }

    [data-theme="dark"] .modern-stat-card {
      background: linear-gradient(145deg, rgba(26, 38, 64, 0.85) 0%, rgba(15, 23, 42, 0.95) 100%);
      border: 1.5px solid rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.08);
    }

    /* Ambient Glow Orb */
    .stat-card-glow {
      position: absolute;
      top: -24px;
      right: -24px;
      width: 110px;
      height: 110px;
      border-radius: 50%;
      filter: blur(36px);
      opacity: 0.35;
      pointer-events: none;
      transition: opacity 0.3s ease, transform 0.3s ease;
    }
    .theme-sapphire .stat-card-glow { background: #2563EB; }
    .theme-cyan .stat-card-glow { background: #00ADEF; }
    .theme-emerald .stat-card-glow { background: #10B981; }
    .theme-amber .stat-card-glow { background: #F59E0B; }
    .theme-indigo .stat-card-glow { background: #8B5CF6; }

    /* Hover State */
    .modern-stat-card:hover {
      transform: translateY(-4px);
    }
    .theme-sapphire:hover {
      border-color: rgba(37, 99, 235, 0.55) !important;
      box-shadow: 0 16px 36px -6px rgba(37, 99, 235, 0.25);
    }
    .theme-cyan:hover {
      border-color: rgba(0, 173, 239, 0.55) !important;
      box-shadow: 0 16px 36px -6px rgba(0, 173, 239, 0.25);
    }
    .theme-emerald:hover {
      border-color: rgba(16, 185, 129, 0.55) !important;
      box-shadow: 0 16px 36px -6px rgba(16, 185, 129, 0.25);
    }
    .theme-amber:hover {
      border-color: rgba(245, 158, 11, 0.55) !important;
      box-shadow: 0 16px 36px -6px rgba(245, 158, 11, 0.25);
    }
    .theme-indigo:hover {
      border-color: rgba(139, 92, 246, 0.55) !important;
      box-shadow: 0 16px 36px -6px rgba(139, 92, 246, 0.25);
    }
    .modern-stat-card:hover .stat-card-glow {
      opacity: 0.75;
      transform: scale(1.25);
    }
    .modern-stat-card:active {
      transform: translateY(-1px) scale(0.99);
    }

    /* Card Header */
    .stat-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .stat-card-icon {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      flex-shrink: 0;
      transition: transform 0.25s ease;
    }
    .modern-stat-card:hover .stat-card-icon {
      transform: scale(1.1) rotate(4deg);
    }

    /* Light Theme Icons */
    .stat-card-icon.sapphire {
      background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
      color: #1D4ED8;
      border: 1px solid #BFDBFE;
      box-shadow: 0 3px 8px rgba(37, 99, 235, 0.12);
    }
    .stat-card-icon.cyan {
      background: linear-gradient(135deg, #F0F9FF, #E0F2FE);
      color: #0284C7;
      border: 1px solid #BAE6FD;
      box-shadow: 0 3px 8px rgba(2, 132, 199, 0.12);
    }
    .stat-card-icon.emerald {
      background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
      color: #059669;
      border: 1px solid #A7F3D0;
      box-shadow: 0 3px 8px rgba(5, 150, 105, 0.12);
    }
    .stat-card-icon.amber {
      background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
      color: #D97706;
      border: 1px solid #FDE68A;
      box-shadow: 0 3px 8px rgba(217, 119, 6, 0.12);
    }
    .stat-card-icon.indigo {
      background: linear-gradient(135deg, #F5F3FF, #EDE9FE);
      color: #7C3AED;
      border: 1px solid #DDD6FE;
      box-shadow: 0 3px 8px rgba(124, 58, 237, 0.12);
    }

    /* Dark Theme Icons */
    [data-theme="dark"] .stat-card-icon.sapphire {
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.32), rgba(29, 78, 216, 0.18));
      color: #60A5FA;
      border: 1px solid rgba(96, 165, 250, 0.38);
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    }
    [data-theme="dark"] .stat-card-icon.cyan {
      background: linear-gradient(135deg, rgba(0, 173, 239, 0.32), rgba(2, 132, 199, 0.18));
      color: #38BDF8;
      border: 1px solid rgba(56, 189, 248, 0.38);
      box-shadow: 0 4px 14px rgba(0, 173, 239, 0.35);
    }
    [data-theme="dark"] .stat-card-icon.emerald {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.32), rgba(5, 150, 105, 0.18));
      color: #34D399;
      border: 1px solid rgba(52, 211, 153, 0.38);
      box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
    }
    [data-theme="dark"] .stat-card-icon.amber {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.32), rgba(217, 119, 6, 0.18));
      color: #FBBF24;
      border: 1px solid rgba(251, 191, 36, 0.38);
      box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
    }
    [data-theme="dark"] .stat-card-icon.indigo {
      background: linear-gradient(135deg, rgba(139, 92, 246, 0.32), rgba(124, 58, 237, 0.18));
      color: #C4B5FD;
      border: 1px solid rgba(196, 181, 253, 0.38);
      box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
    }

    /* Micro Pills */
    .stat-micro-pill {
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      padding: 3px 9px;
      border-radius: 100px;
      line-height: 1.3;
    }
    .stat-micro-pill.sapphire {
      background: rgba(37, 99, 235, 0.08); color: #2563EB; border: 1px solid rgba(37, 99, 235, 0.2);
    }
    .stat-micro-pill.cyan {
      background: rgba(0, 173, 239, 0.08); color: #00ADEF; border: 1px solid rgba(0, 173, 239, 0.2);
    }
    .stat-micro-pill.emerald {
      background: rgba(16, 185, 129, 0.08); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .stat-micro-pill.amber {
      background: rgba(245, 158, 11, 0.08); color: #D97706; border: 1px solid rgba(245, 158, 11, 0.2);
    }
    .stat-micro-pill.indigo {
      background: rgba(124, 58, 237, 0.08); color: #7C3AED; border: 1px solid rgba(124, 58, 237, 0.2);
    }
    [data-theme="dark"] .stat-micro-pill.sapphire {
      background: rgba(56, 189, 248, 0.14); color: #60A5FA; border-color: rgba(56, 189, 248, 0.3);
    }
    [data-theme="dark"] .stat-micro-pill.cyan {
      background: rgba(0, 173, 239, 0.16); color: #38BDF8; border-color: rgba(0, 173, 239, 0.3);
    }
    [data-theme="dark"] .stat-micro-pill.emerald {
      background: rgba(16, 185, 129, 0.16); color: #34D399; border-color: rgba(16, 185, 129, 0.3);
    }
    [data-theme="dark"] .stat-micro-pill.amber {
      background: rgba(245, 158, 11, 0.16); color: #FBBF24; border-color: rgba(245, 158, 11, 0.3);
    }
    [data-theme="dark"] .stat-micro-pill.indigo {
      background: rgba(139, 92, 246, 0.18); color: #C4B5FD; border-color: rgba(139, 92, 246, 0.3);
    }

    /* Content & Numbers */
    .stat-card-content {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .stat-card-number {
      font-size: 2.2rem;
      font-weight: 900;
      letter-spacing: -0.04em;
      line-height: 1;
      color: var(--text-primary);
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    }
    [data-theme="dark"] .stat-card-number {
      color: #FFFFFF;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    }
    .stat-card-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-secondary);
      letter-spacing: 0.2px;
      line-height: 1.35;
    }
    [data-theme="dark"] .stat-card-label {
      color: #94A3B8;
    }

    /* Bottom Line Accent */
    .stat-card-bar {
      position: absolute;
      bottom: 0;
      left: 18px;
      right: 18px;
      height: 3px;
      border-radius: 3px 3px 0 0;
      opacity: 0.4;
      transition: opacity 0.25s ease, height 0.25s ease;
    }
    .stat-card-bar.sapphire { background: linear-gradient(90deg, #2563EB, #60A5FA); }
    .stat-card-bar.cyan     { background: linear-gradient(90deg, #00ADEF, #38BDF8); }
    .stat-card-bar.emerald  { background: linear-gradient(90deg, #059669, #34D399); }
    .stat-card-bar.amber    { background: linear-gradient(90deg, #D97706, #FBBF24); }
    .stat-card-bar.indigo   { background: linear-gradient(90deg, #7C3AED, #A78BFA); }

    .modern-stat-card:hover .stat-card-bar {
      opacity: 1;
      height: 4px;
    }

    /* FLOATING SEGMENTED CAPSULE TABS */
    .tab-bar {
      display: inline-flex; gap: 8px;
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 100px; padding: 6px; margin-bottom: 28px;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    [data-theme="dark"] .tab-bar {
      box-shadow: var(--shadow-card);
    }
    .tab-btn {
      padding: 10px 24px; border-radius: 100px; border: none;
      font-family: inherit; font-size: .9rem; font-weight: 700;
      color: var(--text-muted); background: transparent; cursor: pointer;
      transition: all .2s ease;
      display: flex; align-items: center; gap: 8px; white-space: nowrap;
    }
    .tab-btn.active {
      background: var(--clr-brand-gradient);
      color: #fff; box-shadow: var(--btn-3d-shadow);
    }
    .tab-btn.active:hover {
      transform: translateY(-1px); box-shadow: var(--btn-3d-shadow-hover);
    }
    .tab-btn:hover:not(.active) { color: var(--text-primary); background: var(--bg-hover); }

    /* PANELS */
    .tab-panel { display: none; }
    .tab-panel.active { display: block; animation: panelIn .22s ease; }
    @keyframes panelIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

    /* BOOKING FORM CARD */
    .form-card {
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 22px; overflow: hidden;
      box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    }
    [data-theme="dark"] .form-card {
      box-shadow: var(--shadow-card);
    }
    .form-card-header {
      padding: 22px 28px; border-bottom: 1.5px solid var(--border-color);
      background: #F8FAFC;
      display: flex; align-items: center; gap: 16px;
    }
    [data-theme="dark"] .form-card-header {
      background: #1C2C48; border-bottom-color: rgba(255, 255, 255, 0.1);
    }
    .form-card-icon {
      width: 46px; height: 46px; border-radius: 14px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1.2rem;
      box-shadow: 0 3px 10px rgba(35, 94, 174, 0.25);
    }
    .form-card-title { font-size: 1.25rem; font-weight: 900; color: var(--text-primary); letter-spacing: -0.015em; }
    .form-card-sub   { font-size: .88rem; color: var(--text-muted); font-weight: 600; }
    .form-card-body  { padding: 28px; }

    /* ============================================================
       CONNECTED TIMELINE STEPPER (Balanced Light & Dark Mode)
       ============================================================ */
    .stepper-wrap {
      position: relative; margin-bottom: 32px; padding: 6px 14px 0;
    }
    .stepper-track-bg {
      position: absolute; top: 22px; left: 45px; right: 45px;
      height: 4px; background: #CBD5E1; border-radius: 4px; z-index: 1;
    }
    [data-theme="dark"] .stepper-track-bg { background: rgba(255, 255, 255, 0.22); }
    .stepper-track-fill {
      position: absolute; top: 22px; left: 45px; height: 4px;
      background: linear-gradient(90deg, #235EAE, #00ADEF);
      border-radius: 4px; z-index: 2;
      transition: width .32s ease;
      width: 0%;
    }
    [data-theme="dark"] .stepper-track-fill {
      background: linear-gradient(90deg, #0284C7, #38BDF8);
    }
    .steps-container {
      display: flex; justify-content: space-between; position: relative; z-index: 3;
    }
    .step-node {
      display: flex; flex-direction: column; align-items: center;
      cursor: pointer; user-select: none; background: none; border: none; padding: 0;
      transition: all .2s ease;
    }
    .step-circle {
      width: 40px; height: 40px; border-radius: 50%;
      background: #FFFFFF; border: 2px solid #94A3B8;
      color: #334155; font-size: .88rem; font-weight: 800;
      display: flex; align-items: center; justify-content: center;
      transition: all .25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 3px 0 #CBD5E1, 0 2px 5px rgba(0,0,0,0.05);
    }
    [data-theme="dark"] .step-circle {
      background: #1A2844; border: 2px solid rgba(56, 189, 248, 0.35);
      color: #E2E8F0; box-shadow: 0 3px 0 #0D1626, 0 4px 10px rgba(0,0,0,0.3);
    }
    .step-node:hover:not(.active):not(.done) .step-circle {
      border-color: var(--clr-primary); color: var(--clr-primary);
    }
    [data-theme="dark"] .step-node:hover:not(.active):not(.done) .step-circle {
      border-color: #38BDF8; color: #FFFFFF;
    }
    .step-node.active .step-circle {
      background: var(--clr-brand-gradient);
      border: 2px solid var(--clr-primary); color: #fff; transform: scale(1.1);
      box-shadow: 0 0 0 4px rgba(35, 94, 174, 0.16), 0 3px 8px rgba(35, 94, 174, 0.25);
    }
    [data-theme="dark"] .step-node.active .step-circle {
      background: linear-gradient(135deg, #0284C7, #38BDF8);
      border-color: #7DD3FC;
      box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.3), 0 4px 14px rgba(0, 0, 0, 0.4);
    }
    .step-node.done .step-circle {
      background: #10B981; border: 2px solid #10B981; color: #fff;
      box-shadow: 0 3px 0 #059669, 0 2px 6px rgba(16, 185, 129, 0.25);
    }
    .step-text {
      margin-top: 8px; font-size: .82rem; font-weight: 700;
      color: var(--text-muted); transition: color .2s;
    }
    .step-node.active .step-text { color: var(--clr-primary); font-weight: 800; }
    [data-theme="dark"] .step-node.active .step-text { color: #38BDF8; }
    .step-node.done .step-text { color: var(--text-primary); font-weight: 700; }

    /* Form fields */
    .field-group { margin-bottom: 24px; }
    .field-label {
      display: block; font-size: .82rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: .06em;
      color: var(--text-muted); margin-bottom: 8px;
    }
    .field-control {
      width: 100%; padding: 14px 18px;
      background: var(--bg-input) !important; border: 1.5px solid var(--border-color);
      border-radius: 14px; font-family: inherit; font-size: .94rem;
      color: var(--text-primary) !important; outline: none;
      transition: border-color .15s, box-shadow .15s;
      box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.04);
    }
    .field-control:focus {
      border-color: var(--clr-primary); background: var(--bg-input-focus) !important;
      box-shadow: 0 0 0 3px rgba(35, 94, 174, 0.18);
    }
    [data-theme="dark"] .field-control {
      background: #142034 !important;
      border-color: rgba(56, 189, 248, 0.28);
      box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.4), 0 1px 0 rgba(255, 255, 255, 0.08);
    }
    [data-theme="dark"] .field-control:focus {
      border-color: #38BDF8;
      background: #1A2844 !important;
      box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.3), 0 0 0 3.5px rgba(56, 189, 248, 0.35);
    }
    .field-control::placeholder { color: var(--text-subtle); }
    select.field-control { cursor: pointer; }
    textarea.field-control { resize: vertical; min-height: 85px; }

    /* Date input wrap with modern calendar icon */
    .date-input-wrap {
      position: relative; width: 100%;
    }
    .date-input-wrap .field-control {
      padding-right: 46px; cursor: pointer;
    }
    .date-picker-icon {
      position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
      pointer-events: none; font-size: 1.1rem; color: var(--clr-primary);
      z-index: 5; transition: color .2s;
    }
    [data-theme="dark"] .date-picker-icon {
      color: #38BDF8;
    }

    /* Quick Date Buttons - 3D Tactile Styling */
    .quick-dates { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .qdate-btn {
      padding: 9px 18px; border-radius: 100px; border: 1.5px solid #CBD5E1;
      background: #FFFFFF; color: #334155;
      font-family: inherit; font-size: .84rem; font-weight: 700;
      cursor: pointer; transition: all .15s ease; white-space: nowrap;
      box-shadow: 0 3px 0 #CBD5E1, 0 2px 6px rgba(15, 23, 42, 0.04);
    }
    .qdate-btn:hover {
      border-color: var(--clr-primary); color: var(--clr-primary); background: #EFF6FF;
      transform: translateY(-2px); box-shadow: 0 5px 0 #CBD5E1;
    }
    .qdate-btn:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #CBD5E1;
    }
    .qdate-btn.active {
      border-color: transparent; background: linear-gradient(135deg, #235EAE, #00ADEF);
      color: #FFFFFF; box-shadow: 0 3px 0 #183B75, 0 6px 14px rgba(35, 94, 174, 0.25);
      transform: translateY(-1px);
    }

    [data-theme="dark"] .qdate-btn {
      background: #1A2844; color: #F1F5F9; border-color: rgba(56, 189, 248, 0.25);
      box-shadow: 0 3px 0 #0D1626, 0 4px 10px rgba(0, 0, 0, 0.25);
    }
    [data-theme="dark"] .qdate-btn:hover {
      border-color: #38BDF8; color: #38BDF8; background: rgba(56, 189, 248, 0.16);
      transform: translateY(-2px); box-shadow: 0 5px 0 #0D1626;
    }
    [data-theme="dark"] .qdate-btn:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #0D1626;
    }
    [data-theme="dark"] .qdate-btn.active {
      background: linear-gradient(135deg, #0284C7, #38BDF8);
      border-color: transparent; color: #FFFFFF;
      box-shadow: 0 3px 0 #0369A1, 0 6px 16px rgba(56, 189, 248, 0.4);
      transform: translateY(-1px);
    }

    /* Time Slot Grid */
    .slot-section-title {
      font-size: .78rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .08em; color: var(--clr-primary); margin: 20px 0 12px;
      display: flex; align-items: center; gap: 8px;
    }
    [data-theme="dark"] .slot-section-title { color: #38BDF8; }
    .slot-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }
    .slot-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(95px, 1fr)); gap: 10px;
    }

    /* 3D Push-Button Time Slots */
    .slot-btn {
      padding: 12px 6px; border-radius: 12px;
      border: 1.5px solid #CBD5E1;
      background: #FFFFFF; color: #0F172A;
      font-family: inherit; font-size: .88rem; font-weight: 800;
      cursor: pointer; transition: all .15s ease; text-align: center; line-height: 1.2;
      box-shadow: 0 3px 0 #CBD5E1, 0 3px 8px rgba(15, 23, 42, 0.04);
    }
    .slot-btn .slot-period { font-size: .68rem; color: var(--text-muted); display: block; margin-top: 3px; font-weight: 700; }
    .slot-btn:hover:not(:disabled) {
      border-color: var(--clr-primary); color: var(--clr-primary);
      background: #EFF6FF; transform: translateY(-2px);
      box-shadow: 0 5px 0 #CBD5E1, 0 6px 14px rgba(35, 94, 174, 0.15);
    }
    .slot-btn:active:not(:disabled) {
      transform: translateY(2px); box-shadow: 0 1px 0 #CBD5E1;
    }
    .slot-btn.selected {
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #fff; border-color: transparent;
      box-shadow: 0 4px 0 #183B75, 0 8px 18px rgba(35, 94, 174, 0.28); transform: translateY(-2px);
    }
    .slot-btn.selected:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #183B75;
    }
    .slot-btn.selected .slot-period { color: #FFFFFF; }
    
    /* Disabled / Taken Slot Button Styling */
    .slot-btn:disabled,
    .slot-btn.is-taken {
      opacity: .52 !important;
      cursor: not-allowed !important;
      pointer-events: none !important;
      background: #F8FAFC !important;
      color: #94A3B8 !important;
      border: 1.5px dashed #CBD5E1 !important;
      box-shadow: none !important;
      transform: none !important;
      user-select: none !important;
      text-decoration: line-through;
    }
    .slot-btn:disabled .slot-period,
    .slot-btn.is-taken .slot-period {
      color: #EF4444 !important;
      font-weight: 800 !important;
      text-decoration: none !important;
    }
    .slot-btn:disabled .slot-period.booked,
    .slot-btn.is-taken .slot-period.booked {
      color: #DC2626 !important;
      background: rgba(239, 68, 68, 0.1);
      padding: 1px 6px; border-radius: 6px;
      margin-top: 4px; display: inline-block;
    }

    /* Dark Mode: Light Blue Luminous 3D Time Slots */
    [data-theme="dark"] .slot-btn {
      background: #1E2D4A; color: #FFFFFF;
      border: 1.5px solid rgba(56, 189, 248, 0.28);
      box-shadow: 0 4px 0 #0D1626, 0 6px 14px rgba(0, 0, 0, 0.3);
    }
    [data-theme="dark"] .slot-btn .slot-period { color: #7DD3FC; }
    [data-theme="dark"] .slot-btn:hover:not(:disabled) {
      border-color: #38BDF8; color: #38BDF8;
      background: rgba(56, 189, 248, 0.16);
      transform: translateY(-2px);
      box-shadow: 0 6px 0 #0D1626, 0 10px 20px rgba(56, 189, 248, 0.22);
    }
    [data-theme="dark"] .slot-btn:active:not(:disabled) {
      transform: translateY(2px);
      box-shadow: 0 1px 0 #0D1626, 0 3px 8px rgba(0, 0, 0, 0.2);
    }
    [data-theme="dark"] .slot-btn.selected {
      background: linear-gradient(135deg, #0284C7 0%, #38BDF8 100%);
      color: #FFFFFF; border-color: #7DD3FC;
      box-shadow: 0 4px 0 #0369A1, 0 8px 22px rgba(56, 189, 248, 0.45);
      transform: translateY(-2px);
    }
    [data-theme="dark"] .slot-btn.selected:active {
      transform: translateY(2px);
      box-shadow: 0 1px 0 #0369A1, 0 3px 8px rgba(56, 189, 248, 0.3);
    }
    [data-theme="dark"] .slot-btn.selected .slot-period { color: #FFFFFF; }

    /* Dark Mode Disabled / Taken Slots */
    [data-theme="dark"] .slot-btn:disabled,
    [data-theme="dark"] .slot-btn.is-taken {
      background: #0B1324 !important;
      color: #64748B !important;
      border: 1.5px dashed rgba(255, 255, 255, 0.14) !important;
      box-shadow: none !important;
      transform: none !important;
      opacity: .48 !important;
    }
    [data-theme="dark"] .slot-btn:disabled .slot-period,
    [data-theme="dark"] .slot-btn.is-taken .slot-period {
      color: #F87171 !important;
    }
    [data-theme="dark"] .slot-btn:disabled .slot-period.booked,
    [data-theme="dark"] .slot-btn.is-taken .slot-period.booked {
      color: #FCA5A5 !important;
      background: rgba(239, 68, 68, 0.2) !important;
    }

    /* Slot Availability Status Bar */
    .slot-avail-bar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 10px; margin-bottom: 12px; font-size: .8rem; font-weight: 700;
      padding: 8px 14px; border-radius: 10px;
      background: var(--bg-hover); border: 1px solid var(--border-color);
    }
    .slot-avail-bar .badge-avail {
      color: #10B981; display: inline-flex; align-items: center; gap: 6px;
    }
    [data-theme="dark"] .slot-avail-bar .badge-avail { color: #34D399; }
    .slot-avail-bar .badge-taken {
      color: #EF4444; display: inline-flex; align-items: center; gap: 6px;
    }
    [data-theme="dark"] .slot-avail-bar .badge-taken { color: #F87171; }

    /* Fully Booked Banner */
    .slot-fully-booked-box {
      background: rgba(239, 68, 68, 0.08);
      border: 1.5px solid rgba(239, 68, 68, 0.28);
      border-radius: 14px; padding: 18px 16px;
      display: flex; align-items: center; gap: 14px;
      margin: 10px 0 16px;
    }
    [data-theme="dark"] .slot-fully-booked-box {
      background: rgba(239, 68, 68, 0.15);
      border-color: rgba(248, 113, 113, 0.35);
    }
    .slot-fully-booked-icon {
      width: 44px; height: 44px; border-radius: 12px;
      background: rgba(239, 68, 68, 0.15); color: #EF4444;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; flex-shrink: 0;
    }
    [data-theme="dark"] .slot-fully-booked-icon { color: #FCA5A5; }
    .slot-fully-booked-title {
      font-size: .95rem; font-weight: 800; color: #DC2626; margin-bottom: 3px;
    }
    [data-theme="dark"] .slot-fully-booked-title { color: #FCA5A5; }
    .slot-fully-booked-desc {
      font-size: .83rem; color: var(--text-secondary); line-height: 1.4; margin: 0;
    }

    /* Quick Date Full Button Styling */
    .qdate-btn.is-full {
      opacity: .5 !important;
      cursor: not-allowed !important;
      pointer-events: none !important;
      border-style: dashed !important;
      box-shadow: none !important;
      transform: none !important;
    }
    [data-theme="dark"] .qdate-btn.is-full {
      background: #0B1324 !important;
      border-color: rgba(255, 255, 255, 0.1) !important;
      color: #64748B !important;
    }

    /* ============================================================
       MODERN 3D FLATPICKR CALENDAR THEME
       ============================================================ */
    .flatpickr-calendar {
      background: var(--bg-card) !important;
      border: 1.5px solid var(--border-color) !important;
      border-radius: 20px !important;
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif !important;
      padding: 14px !important;
      width: 320px !important;
      transition: all .2s ease !important;
      z-index: 100000 !important;
    }
    [data-theme="dark"] .flatpickr-calendar {
      background: #142036 !important;
      border: 1.5px solid rgba(56, 189, 248, 0.3) !important;
      box-shadow: 0 25px 60px -8px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(56, 189, 248, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15) !important;
    }
    [data-theme="light"] .flatpickr-calendar {
      background: #FFFFFF !important;
      border: 1.5px solid #CBD5E1 !important;
      box-shadow: 0 20px 45px -8px rgba(15, 23, 42, 0.18), 0 4px 12px rgba(15, 23, 42, 0.06) !important;
    }

    .flatpickr-months {
      align-items: center !important;
      padding-bottom: 8px !important;
      border-bottom: 1px solid var(--border-color) !important;
      margin-bottom: 8px !important;
    }
    .flatpickr-months .flatpickr-month {
      color: var(--text-primary) !important;
      height: 38px !important;
    }
    .flatpickr-current-month {
      font-size: 1rem !important;
      font-weight: 800 !important;
      color: var(--text-primary) !important;
      padding-top: 4px !important;
    }
    .flatpickr-current-month .cur-month {
      font-weight: 800 !important;
    }
    .flatpickr-current-month input.cur-year {
      font-weight: 800 !important;
      color: var(--text-primary) !important;
    }
    .flatpickr-months .flatpickr-prev-month,
    .flatpickr-months .flatpickr-next-month {
      width: 32px !important; height: 32px !important;
      border-radius: 10px !important;
      display: flex !important; align-items: center !important; justify-content: center !important;
      padding: 0 !important;
      transition: all .2s ease !important;
    }
    [data-theme="dark"] .flatpickr-months .flatpickr-prev-month,
    [data-theme="dark"] .flatpickr-months .flatpickr-next-month {
      color: #38BDF8 !important; fill: #38BDF8 !important;
      background: #1E2D4A !important;
      box-shadow: 0 2px 0 #0D1626 !important;
    }
    [data-theme="dark"] .flatpickr-months .flatpickr-prev-month:hover,
    [data-theme="dark"] .flatpickr-months .flatpickr-next-month:hover {
      background: rgba(56, 189, 248, 0.25) !important;
      transform: scale(1.08) !important;
      color: #FFFFFF !important; fill: #FFFFFF !important;
    }
    [data-theme="light"] .flatpickr-months .flatpickr-prev-month,
    [data-theme="light"] .flatpickr-months .flatpickr-next-month {
      color: var(--clr-primary) !important; fill: var(--clr-primary) !important;
      background: #F1F5F9 !important;
      box-shadow: 0 2px 0 #CBD5E1 !important;
    }
    [data-theme="light"] .flatpickr-months .flatpickr-prev-month:hover,
    [data-theme="light"] .flatpickr-months .flatpickr-next-month:hover {
      background: #EFF6FF !important;
      transform: scale(1.08) !important;
    }

    span.flatpickr-weekday {
      font-weight: 800 !important;
      font-size: .76rem !important;
      text-transform: uppercase !important;
      letter-spacing: .05em !important;
      color: var(--text-muted) !important;
    }
    span.flatpickr-weekday:first-child,
    span.flatpickr-weekday:last-child {
      color: #EF4444 !important; opacity: .7 !important;
    }

    .dayContainer {
      width: 292px !important;
      min-width: 292px !important;
      max-width: 292px !important;
    }
    .flatpickr-day {
      width: 38px !important; height: 38px !important;
      line-height: 38px !important;
      border-radius: 10px !important;
      font-size: .88rem !important;
      font-weight: 700 !important;
      margin: 1px !important;
      transition: all .15s ease !important;
      border: 1px solid transparent !important;
    }
    [data-theme="dark"] .flatpickr-day {
      color: #FFFFFF !important;
    }
    [data-theme="light"] .flatpickr-day {
      color: #0F172A !important;
    }
    [data-theme="dark"] .flatpickr-day:hover:not(.flatpickr-disabled):not(.selected) {
      background: rgba(56, 189, 248, 0.2) !important;
      color: #38BDF8 !important;
      border-color: rgba(56, 189, 248, 0.4) !important;
      transform: translateY(-2px) !important;
      box-shadow: 0 4px 10px rgba(0,0,0,0.3) !important;
    }
    [data-theme="light"] .flatpickr-day:hover:not(.flatpickr-disabled):not(.selected) {
      background: #EFF6FF !important;
      color: var(--clr-primary) !important;
      border-color: #BFDBFE !important;
      transform: translateY(-2px) !important;
      box-shadow: 0 4px 8px rgba(15,23,42,0.08) !important;
    }
    [data-theme="dark"] .flatpickr-day.selected,
    [data-theme="dark"] .flatpickr-day.startRange,
    [data-theme="dark"] .flatpickr-day.endRange {
      background: linear-gradient(135deg, #0284C7 0%, #38BDF8 100%) !important;
      color: #FFFFFF !important;
      font-weight: 900 !important;
      border-color: #7DD3FC !important;
      box-shadow: 0 3px 0 #0369A1, 0 6px 16px rgba(56, 189, 248, 0.45) !important;
      transform: translateY(-2px) !important;
    }
    [data-theme="light"] .flatpickr-day.selected,
    [data-theme="light"] .flatpickr-day.startRange,
    [data-theme="light"] .flatpickr-day.endRange {
      background: linear-gradient(135deg, #235EAE 0%, #00ADEF 100%) !important;
      color: #FFFFFF !important;
      font-weight: 900 !important;
      border-color: transparent !important;
      box-shadow: 0 3px 0 #183B75, 0 6px 14px rgba(35, 94, 174, 0.3) !important;
      transform: translateY(-2px) !important;
    }
    [data-theme="dark"] .flatpickr-day.today:not(.selected) {
      border: 1.5px solid #38BDF8 !important;
      color: #38BDF8 !important;
      background: rgba(56, 189, 248, 0.1) !important;
    }
    [data-theme="light"] .flatpickr-day.today:not(.selected) {
      border: 1.5px solid var(--clr-primary) !important;
      color: var(--clr-primary) !important;
      background: #EFF6FF !important;
    }
    .flatpickr-day.flatpickr-disabled,
    .flatpickr-day.flatpickr-disabled:hover {
      color: var(--text-subtle) !important;
      opacity: .28 !important;
      cursor: not-allowed !important;
      background: transparent !important;
      border-color: transparent !important;
      transform: none !important;
      box-shadow: none !important;
      text-decoration: line-through !important;
    }
    .flatpickr-calendar.arrowTop::before,
    .flatpickr-calendar.arrowTop::after {
      border-bottom-color: var(--bg-card) !important;
    }
    [data-theme="dark"] .flatpickr-calendar.arrowTop::before {
      border-bottom-color: rgba(56, 189, 248, 0.3) !important;
    }
    [data-theme="dark"] .flatpickr-calendar.arrowTop::after {
      border-bottom-color: #142036 !important;
    }
    .flatpickr-calendar.arrowBottom::before,
    .flatpickr-calendar.arrowBottom::after {
      border-top-color: var(--bg-card) !important;
    }
    [data-theme="dark"] .flatpickr-calendar.arrowBottom::before {
      border-top-color: rgba(56, 189, 248, 0.3) !important;
    }
    [data-theme="dark"] .flatpickr-calendar.arrowBottom::after {
      border-top-color: #142036 !important;
    }

    /* ============================================================
       SWEETALERT2 MODAL OVERRIDES (MATCHING ADMIN & LUXURY GLASS)
       ============================================================ */
    .swal2-container {
      z-index: 200000 !important;
      backdrop-filter: blur(8px) !important;
      -webkit-backdrop-filter: blur(8px) !important;
    }
    .swal2-popup.patient-swal-popup {
      border-radius: 22px !important;
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif !important;
      padding: 28px 24px 24px !important;
      border: 1.5px solid var(--border-color) !important;
      background: var(--bg-card) !important;
      color: var(--text-primary) !important;
      box-shadow: 0 25px 60px -8px rgba(0, 0, 0, 0.4) !important;
    }
    [data-theme="dark"] .swal2-popup.patient-swal-popup {
      background: #162238 !important;
      border: 1.5px solid rgba(56, 189, 248, 0.3) !important;
      box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.9), 0 0 35px rgba(56, 189, 248, 0.15) !important;
    }
    .patient-swal-popup .swal2-title {
      font-size: 1.35rem !important;
      font-weight: 900 !important;
      letter-spacing: -0.02em !important;
      color: var(--text-primary) !important;
      padding: 0 0 8px !important;
    }
    [data-theme="dark"] .patient-swal-popup .swal2-title {
      color: #FFFFFF !important;
    }
    .patient-swal-popup .swal2-html-container {
      font-size: .92rem !important;
      color: var(--text-secondary) !important;
      line-height: 1.55 !important;
      margin: 6px 0 18px !important;
    }
    [data-theme="dark"] .patient-swal-popup .swal2-html-container {
      color: #CBD5E1 !important;
    }
    .patient-swal-popup .swal2-actions {
      gap: 12px !important;
      margin-top: 14px !important;
    }
    .patient-swal-popup .swal2-confirm {
      border-radius: 12px !important;
      padding: 12px 28px !important;
      font-size: .88rem !important;
      font-weight: 800 !important;
      letter-spacing: .02em !important;
      border: none !important;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)) !important;
      color: #FFFFFF !important;
      box-shadow: 0 4px 0 #0369A1, 0 6px 16px rgba(56, 189, 248, 0.35) !important;
      transition: all .15s ease !important;
    }
    .patient-swal-popup .swal2-confirm:hover {
      transform: translateY(-2px) !important;
      box-shadow: 0 6px 0 #0369A1, 0 10px 22px rgba(56, 189, 248, 0.45) !important;
    }
    .patient-swal-popup .swal2-confirm:active {
      transform: translateY(2px) !important;
      box-shadow: 0 1px 0 #0369A1 !important;
    }
    .patient-swal-popup .swal2-cancel {
      border-radius: 12px !important;
      padding: 12px 22px !important;
      font-size: .88rem !important;
      font-weight: 800 !important;
      border: 1.5px solid var(--border-color) !important;
      background: var(--bg-hover) !important;
      color: var(--text-muted) !important;
      box-shadow: 0 3px 0 var(--border-color) !important;
      transition: all .15s ease !important;
    }
    .patient-swal-popup .swal2-cancel:hover {
      color: var(--text-primary) !important;
      transform: translateY(-2px) !important;
    }
    .patient-swal-popup .swal2-cancel:active {
      transform: translateY(2px) !important;
    }
    .patient-swal-popup .swal2-deny {
      border-radius: 12px !important;
      padding: 12px 20px !important;
      font-size: .88rem !important;
      font-weight: 800 !important;
      border: 1.5px solid var(--border-color) !important;
      background: var(--bg-hover) !important;
      color: var(--clr-primary-light) !important;
      box-shadow: 0 3px 0 var(--border-color) !important;
      transition: all .15s ease !important;
    }
    .patient-swal-popup .swal2-deny:hover {
      background: rgba(0, 173, 239, 0.15) !important;
      border-color: var(--clr-primary-light) !important;
      transform: translateY(-2px) !important;
    }
    .patient-swal-popup .swal2-deny:active {
      transform: translateY(1px) !important;
    }

    /* ─── CUTE WELCOME MODAL DESIGN ────────────────────────── */
    .swal2-popup.cute-welcome-swal-popup {
      border-radius: 32px !important;
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif !important;
      padding: 34px 26px 28px !important;
      border: 2px solid rgba(0, 173, 239, 0.28) !important;
      background: var(--bg-card) !important;
      color: var(--text-primary) !important;
      box-shadow: 0 25px 65px -10px rgba(35, 94, 174, 0.35), 0 0 35px rgba(0, 173, 239, 0.15) !important;
      max-width: 440px !important;
      width: 92% !important;
      overflow: visible !important;
      text-align: center !important;
    }
    [data-theme="dark"] .swal2-popup.cute-welcome-swal-popup {
      background: linear-gradient(180deg, #18253F 0%, #111A2E 100%) !important;
      border: 2px solid rgba(56, 189, 248, 0.35) !important;
      box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.85), 0 0 45px rgba(56, 189, 248, 0.2) !important;
    }

    .cute-modal-wrapper {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    /* Cute Avatar / Mascot Icon */
    .cute-avatar-container {
      position: relative;
      width: 100px;
      height: 100px;
      margin: 0 auto 16px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .cute-avatar-circle {
      width: 86px;
      height: 86px;
      border-radius: 50%;
      background: linear-gradient(135deg, rgba(0, 173, 239, 0.15), rgba(245, 158, 11, 0.18));
      border: 2.5px solid rgba(0, 173, 239, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      z-index: 2;
      box-shadow: 0 10px 25px rgba(0, 173, 239, 0.25);
    }
    [data-theme="dark"] .cute-avatar-circle {
      background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(251, 191, 36, 0.2));
      border-color: rgba(56, 189, 248, 0.5);
    }
    .cute-wave-emoji {
      font-size: 2.7rem;
      display: inline-block;
      transform-origin: 70% 70%;
      animation: cuteWave 2.2s infinite ease-in-out;
      filter: drop-shadow(0 4px 8px rgba(0,0,0,0.15));
    }
    .cute-sparkle-1 {
      position: absolute;
      top: -4px;
      right: -2px;
      font-size: 1.35rem;
      animation: cuteFloat 2.6s infinite ease-in-out;
    }
    .cute-sparkle-2 {
      position: absolute;
      bottom: 2px;
      left: -4px;
      font-size: 1.25rem;
      animation: cuteBob 2.8s infinite ease-in-out;
    }
    .cute-avatar-glow {
      position: absolute;
      inset: -10px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(0, 173, 239, 0.3) 0%, rgba(245, 158, 11, 0.15) 50%, transparent 70%);
      z-index: 1;
      animation: cutePulseGlow 3s infinite alternate ease-in-out;
      pointer-events: none;
    }

    /* Cute Greeting Tag / Pill */
    .cute-greeting-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 15px;
      border-radius: 100px;
      background: rgba(0, 173, 239, 0.12);
      border: 1px solid rgba(0, 173, 239, 0.25);
      color: var(--clr-primary);
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      margin-bottom: 10px;
    }
    [data-theme="dark"] .cute-greeting-pill {
      background: rgba(56, 189, 248, 0.14);
      border-color: rgba(56, 189, 248, 0.3);
      color: #38BDF8;
    }

    /* Cute Title & Message */
    .cute-modal-title {
      font-size: 1.5rem !important;
      font-weight: 900 !important;
      letter-spacing: -0.025em !important;
      color: var(--text-primary) !important;
      margin: 0 0 6px !important;
      line-height: 1.25 !important;
    }
    .cute-modal-text {
      font-size: 0.94rem !important;
      font-weight: 600 !important;
      color: var(--text-secondary) !important;
      line-height: 1.55 !important;
      margin: 0 0 16px !important;
    }

    /* Cute Tip Card */
    .cute-modal-tip {
      background: var(--bg-hover);
      border: 1.5px dashed rgba(0, 173, 239, 0.32);
      border-radius: 18px;
      padding: 12px 16px;
      font-size: 0.82rem;
      color: var(--text-secondary);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
      text-align: left;
      margin-bottom: 22px;
      width: 100%;
    }
    [data-theme="dark"] .cute-modal-tip {
      background: rgba(255, 255, 255, 0.04);
      border-color: rgba(56, 189, 248, 0.3);
    }
    .cute-modal-tip i {
      color: #EC4899;
      font-size: 1.15rem;
      flex-shrink: 0;
      animation: cuteHeartbeat 1.8s infinite;
    }

    /* Cute Button */
    .cute-welcome-confirm-btn {
      border-radius: 100px !important;
      padding: 13px 38px !important;
      font-size: 0.94rem !important;
      font-weight: 800 !important;
      letter-spacing: 0.3px !important;
      border: none !important;
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%) !important;
      color: #FFFFFF !important;
      box-shadow: 0 8px 24px rgba(0, 173, 239, 0.4), 0 2px 4px rgba(0, 0, 0, 0.1) !important;
      cursor: pointer !important;
      transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1) !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 8px !important;
    }
    .cute-welcome-confirm-btn:hover {
      transform: translateY(-2px) scale(1.03) !important;
      box-shadow: 0 12px 30px rgba(0, 173, 239, 0.55) !important;
      background: linear-gradient(135deg, #38BDF8 0%, #235EAE 100%) !important;
    }
    .cute-welcome-confirm-btn:active {
      transform: translateY(1px) scale(0.98) !important;
    }

    /* Keyframe Animations */
    @keyframes cuteWave {
      0%, 100% { transform: rotate(0deg); }
      15% { transform: rotate(16deg); }
      30% { transform: rotate(-10deg); }
      45% { transform: rotate(14deg); }
      60% { transform: rotate(-6deg); }
      75% { transform: rotate(8deg); }
    }
    @keyframes cuteFloat {
      0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
      50% { transform: translateY(-6px) rotate(10deg) scale(1.15); }
    }
    @keyframes cuteBob {
      0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
      50% { transform: translateY(5px) rotate(-8deg) scale(1.1); }
    }
    @keyframes cutePulseGlow {
      0% { opacity: 0.5; transform: scale(0.95); }
      100% { opacity: 0.9; transform: scale(1.1); }
    }
    @keyframes cuteHeartbeat {
      0%, 100% { transform: scale(1); }
      14% { transform: scale(1.25); }
      28% { transform: scale(1); }
      42% { transform: scale(1.25); }
      70% { transform: scale(1); }
    }

    @keyframes cardPulseGlow {
      0% { box-shadow: 0 0 0 0 rgba(0, 173, 239, 0.85); border-color: var(--clr-primary-light); transform: scale(1.015); }
      50% { box-shadow: 0 0 25px 6px rgba(0, 173, 239, 0.45); border-color: var(--clr-primary); transform: scale(1.015); }
      100% { box-shadow: none; border-color: var(--border-color); transform: scale(1); }
    }
    .appt-item.card-highlight {
      animation: cardPulseGlow 2.5s cubic-bezier(0.2, 0.8, 0.2, 1) !important;
      position: relative;
      z-index: 10;
    }

    .slot-placeholder {
      grid-column: 1/-1; text-align: center; padding: 34px 16px;
      color: var(--text-muted); font-size: .9rem; font-weight: 600;
    }
    .slot-placeholder i { font-size: 2.2rem; display: block; margin-bottom: 12px; opacity: .4; color: var(--clr-primary); }
    [data-theme="dark"] .slot-placeholder i { color: var(--clr-primary-light); }

    /* WIZARD MULTI-STEP SYSTEM */
    .wizard-step { display: none; }
    .wizard-step.active { display: block; animation: wizardStepIn .22s ease; }
    @keyframes wizardStepIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .wizard-header { margin-bottom: 24px; }
    .wizard-header-title {
      font-size: 1.2rem; font-weight: 900; color: var(--text-primary);
      display: flex; align-items: center; gap: 8px; margin-bottom: 4px;
    }
    .wizard-header-sub { font-size: .88rem; color: var(--text-muted); font-weight: 600; }

    /* Purpose cards */
    .purpose-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
      gap: 14px; margin-bottom: 24px;
    }
    .purpose-card {
      padding: 20px 18px; border-radius: 18px; border: 1.5px solid #CBD5E1;
      background: #FFFFFF; cursor: pointer; transition: all .15s ease;
      text-align: left; display: flex; flex-direction: column; gap: 10px; position: relative;
      box-shadow: 0 3px 0 #CBD5E1, 0 3px 8px rgba(15, 23, 42, 0.04);
    }
    [data-theme="dark"] .purpose-card {
      background: #1A2844; border-color: rgba(56, 189, 248, 0.22);
      box-shadow: 0 4px 0 #0D1626, 0 6px 14px rgba(0, 0, 0, 0.3);
    }
    .purpose-card:hover {
      border-color: var(--clr-primary); background: #F8FAFC;
      transform: translateY(-2px); box-shadow: 0 5px 0 #CBD5E1, 0 8px 18px rgba(15, 23, 42, 0.08);
    }
    [data-theme="dark"] .purpose-card:hover {
      border-color: #38BDF8; background: rgba(56, 189, 248, 0.14);
      transform: translateY(-2px); box-shadow: 0 6px 0 #0D1626, 0 10px 22px rgba(56, 189, 248, 0.22);
    }
    .purpose-card:active, [data-theme="dark"] .purpose-card:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #0D1626;
    }
    .purpose-card.selected {
      border: 2px solid var(--clr-primary); background: #EFF6FF;
      box-shadow: 0 4px 0 #183B75, 0 8px 20px rgba(35, 94, 174, 0.22); transform: translateY(-2px);
    }
    [data-theme="dark"] .purpose-card.selected {
      border: 2px solid #38BDF8; background: rgba(56, 189, 248, 0.18);
      box-shadow: 0 4px 0 #0369A1, 0 8px 24px rgba(56, 189, 248, 0.4);
    }
    .purpose-card-icon {
      width: 48px; height: 48px; border-radius: 14px;
      background: #EFF6FF; color: var(--clr-primary);
      display: flex; align-items: center; justify-content: center; font-size: 1.35rem;
      transition: all .2s;
    }
    [data-theme="dark"] .purpose-card-icon {
      background: rgba(56, 189, 248, 0.2); color: #38BDF8;
    }
    .purpose-card.selected .purpose-card-icon {
      background: var(--clr-brand-gradient); color: #fff;
      box-shadow: 0 3px 8px rgba(35, 94, 174, 0.25);
    }
    [data-theme="dark"] .purpose-card.selected .purpose-card-icon {
      background: linear-gradient(135deg, #0284C7, #38BDF8);
      color: #fff;
    }
    .purpose-card-title { font-size: 1rem; font-weight: 800; color: var(--text-primary); line-height: 1.3; }
    .purpose-card-desc  { font-size: .82rem; color: var(--text-muted); line-height: 1.45; font-weight: 500; }

    /* Service cards */
    .service-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
      gap: 14px; margin-bottom: 24px;
    }
    .service-card {
      padding: 18px; border-radius: 16px; border: 1.5px solid #CBD5E1;
      background: #FFFFFF; cursor: pointer; transition: all .15s ease;
      display: flex; flex-direction: column; justify-content: space-between; position: relative;
      box-shadow: 0 3px 0 #CBD5E1, 0 3px 8px rgba(15, 23, 42, 0.04);
    }
    [data-theme="dark"] .service-card {
      background: #1A2844; border-color: rgba(56, 189, 248, 0.22);
      box-shadow: 0 4px 0 #0D1626, 0 6px 14px rgba(0, 0, 0, 0.3);
    }
    .service-card:hover {
      border-color: var(--clr-primary); background: #F8FAFC;
      transform: translateY(-2px); box-shadow: 0 5px 0 #CBD5E1, 0 8px 18px rgba(15, 23, 42, 0.08);
    }
    [data-theme="dark"] .service-card:hover {
      border-color: #38BDF8; background: rgba(56, 189, 248, 0.14);
      transform: translateY(-2px); box-shadow: 0 6px 0 #0D1626, 0 10px 22px rgba(56, 189, 248, 0.22);
    }
    .service-card:active, [data-theme="dark"] .service-card:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #0D1626;
    }
    .service-card.selected {
      border: 2px solid var(--clr-primary); background: #EFF6FF;
      box-shadow: 0 4px 0 #183B75, 0 8px 20px rgba(35, 94, 174, 0.22); transform: translateY(-2px);
    }
    [data-theme="dark"] .service-card.selected {
      border: 2px solid #38BDF8; background: rgba(56, 189, 248, 0.18);
      box-shadow: 0 4px 0 #0369A1, 0 8px 24px rgba(56, 189, 248, 0.4);
    }
    .service-top {
      display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;
    }
    .service-badge {
      font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
      padding: 3px 10px; border-radius: 6px;
      background: #EFF6FF; color: var(--clr-primary);
    }
    [data-theme="dark"] .service-badge {
      background: rgba(56, 189, 248, 0.2); color: #38BDF8;
    }
    .service-check {
      width: 24px; height: 24px; border-radius: 50%; border: 1.5px solid #CBD5E1;
      display: flex; align-items: center; justify-content: center; font-size: .74rem; color: transparent;
      transition: all .2s;
    }
    [data-theme="dark"] .service-check { border-color: rgba(255, 255, 255, 0.25); }
    .service-card.selected .service-check {
      background: var(--clr-primary); border-color: var(--clr-primary); color: #fff;
    }
    [data-theme="dark"] .service-card.selected .service-check {
      background: #38BDF8; border-color: #38BDF8; color: #0F172A; font-weight: 900;
    }
    .service-title { font-size: .95rem; font-weight: 800; color: var(--text-primary); margin-bottom: 4px; line-height: 1.3; }
    .service-desc  { font-size: .8rem; color: var(--text-muted); line-height: 1.45; margin-bottom: 12px; font-weight: 500; }
    .service-meta  { font-size: .76rem; color: var(--clr-primary); font-weight: 700; display: flex; align-items: center; gap: 6px; }
    [data-theme="dark"] .service-meta { color: #38BDF8; }

    /* Wizard Navigation Controls */
    .wizard-nav-bar {
      display: flex; justify-content: space-between; align-items: center; gap: 12px;
      margin-top: 26px; padding-top: 20px; border-top: 1.5px solid var(--border-color);
    }
    .btn-wizard-back {
      padding: 12px 24px; border-radius: 12px; border: 1.5px solid #CBD5E1;
      background: #FFFFFF; color: #475569; font-family: inherit;
      font-size: .9rem; font-weight: 700; cursor: pointer; transition: all .15s;
      display: inline-flex; align-items: center; gap: 8px;
      box-shadow: 0 3px 0 #CBD5E1, 0 3px 8px rgba(15, 23, 42, 0.04);
    }
    [data-theme="dark"] .btn-wizard-back {
      background: #1E2D4A; color: #CBD5E1; border-color: rgba(255, 255, 255, 0.18);
      box-shadow: 0 3px 0 #0D1626, 0 4px 10px rgba(0, 0, 0, 0.25);
    }
    .btn-wizard-back:hover {
      border-color: var(--clr-primary); color: var(--clr-primary); background: #EFF6FF;
      transform: translateY(-2px); box-shadow: 0 5px 0 #CBD5E1;
    }
    [data-theme="dark"] .btn-wizard-back:hover {
      border-color: #38BDF8; color: #FFFFFF; background: rgba(56, 189, 248, 0.16);
      transform: translateY(-2px); box-shadow: 0 5px 0 #0D1626;
    }
    .btn-wizard-back:active, [data-theme="dark"] .btn-wizard-back:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #0D1626;
    }
    .btn-wizard-next {
      padding: 13px 30px; border-radius: 12px; border: none;
      background: var(--clr-brand-gradient);
      color: #fff; font-family: inherit; font-size: .94rem; font-weight: 800;
      cursor: pointer; transition: all .15s; display: inline-flex; align-items: center; gap: 8px;
      box-shadow: var(--btn-3d-shadow); margin-left: auto;
    }
    .btn-wizard-next:hover:not(:disabled) {
      transform: translateY(-2px); box-shadow: var(--btn-3d-shadow-hover);
    }
    .btn-wizard-next:active:not(:disabled) {
      transform: translateY(2px); box-shadow: var(--btn-3d-shadow-active);
    }
    .btn-wizard-next:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Step 4 Review Summary Box */
    .review-summary-card {
      background: #F8FAFC; border: 1.5px solid #E2E8F0;
      border-radius: 18px; padding: 22px; margin-bottom: 20px;
    }
    [data-theme="dark"] .review-summary-card {
      background: #182642; border-color: rgba(255, 255, 255, 0.16);
    }
    .review-row {
      display: flex; align-items: center; gap: 16px; padding: 14px 0;
      border-bottom: 1px solid var(--border-color);
    }
    .review-row:last-child { border-bottom: none; }
    .review-row-icon {
      width: 44px; height: 44px; border-radius: 12px;
      background: #EFF6FF; color: var(--clr-primary);
      display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0;
    }
    [data-theme="dark"] .review-row-icon {
      background: rgba(0, 173, 239, 0.18); color: #38BDF8;
    }
    .review-row-content { flex: 1; }
    .review-row-label {
      font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
      color: var(--text-muted); margin-bottom: 2px;
    }
    .review-row-val { font-size: .98rem; font-weight: 800; color: var(--text-primary); }

    /* Clinic info */
    .info-box {
      background: #F0F9FF; border: 1.5px solid #BAE6FD;
      border-radius: 14px; padding: 16px 20px; font-size: .86rem;
      color: #0369A1; line-height: 1.6; margin-bottom: 22px;
      display: flex; gap: 14px; align-items: flex-start;
    }
    [data-theme="dark"] .info-box {
      color: #F1F5F9; background: rgba(0, 173, 239, 0.12); border-color: rgba(0, 173, 239, 0.35);
    }
    .info-box i { color: var(--clr-primary); font-size: 1.2rem; margin-top: 2px; flex-shrink: 0; }
    [data-theme="dark"] .info-box i { color: #38BDF8; }

    /* Clinic Sidebar Styles */
    .clinic-sidebar-card {
      background: var(--bg-card); border: 1.5px solid var(--border-color);
      border-radius: 22px; padding: 24px; margin-bottom: 18px;
      box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    }
    [data-theme="dark"] .clinic-sidebar-card {
      box-shadow: var(--shadow-card);
    }
    .clinic-avatar-box {
      width: 42px; height: 42px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      border-radius: 12px; display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1.05rem; box-shadow: 0 3px 10px rgba(35,94,174,0.25);
    }
    .clinic-name-title { font-weight: 900; font-size: 1rem; color: var(--text-primary); letter-spacing: -0.01em; }
    .clinic-name-sub   { font-size: .74rem; color: var(--text-muted); font-weight: 600; }
    .clinic-status-chip {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 5px 12px; border-radius: 100px;
      font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    }
    .clinic-status-chip.open {
      background: #ECFDF5; color: #065F46; border: 1.5px solid #A7F3D0;
    }
    [data-theme="dark"] .clinic-status-chip.open {
      background: rgba(16, 185, 129, 0.22); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.5);
    }
    .clinic-status-chip.closed {
      background: #FEE2E2; color: #B91C1C; border: 1.5px solid #FECACA;
    }
    [data-theme="dark"] .clinic-status-chip.closed {
      background: rgba(239, 68, 68, 0.22); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.5);
    }

    .clinic-row {
      display: flex; gap: 14px; align-items: flex-start; padding: 12px 0;
      border-bottom: 1px solid var(--border-color);
    }
    .clinic-icon-box {
      width: 34px; height: 34px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: .82rem; flex-shrink: 0;
    }
    .clinic-icon-box.clock, .clinic-icon-box.pin {
      background: #EFF6FF; color: var(--clr-primary); border: 1px solid #DBEAFE;
    }
    [data-theme="dark"] .clinic-icon-box.clock, [data-theme="dark"] .clinic-icon-box.pin {
      background: rgba(0, 173, 239, 0.15); color: #38BDF8; border-color: rgba(0, 173, 239, 0.3);
    }
    .clinic-icon-box.rest {
      background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA;
    }
    [data-theme="dark"] .clinic-icon-box.rest {
      background: rgba(239, 68, 68, 0.15); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.3);
    }
    .clinic-icon-box.phone {
      background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0;
    }
    [data-theme="dark"] .clinic-icon-box.phone {
      background: rgba(16, 185, 129, 0.15); color: #6EE7B7; border-color: rgba(16, 185, 129, 0.3);
    }
    .clinic-row-lbl {
      font-size: .7rem; color: var(--text-muted); font-weight: 800;
      text-transform: uppercase; letter-spacing: .06em;
    }
    .clinic-row-val {
      font-size: .86rem; color: var(--text-secondary); font-weight: 700;
    }

    .reminders-card {
      background: #F0FDF4; border: 1.5px solid #BBF7D0;
      border-radius: 18px; padding: 20px; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
    }
    [data-theme="dark"] .reminders-card {
      background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.25);
    }
    .reminders-title {
      font-size: .82rem; font-weight: 800; color: #15803D; margin-bottom: 12px;
      display: flex; align-items: center; gap: 8px;
    }
    [data-theme="dark"] .reminders-title { color: #34D399; }
    .reminder-item {
      display: flex; gap: 10px; align-items: flex-start; margin-bottom: 8px;
      font-size: .78rem; color: #166534; font-weight: 600;
    }
    [data-theme="dark"] .reminder-item { color: #A7F3D0; font-weight: 500; }
    .reminder-item i { color: #15803D; margin-top: 3px; flex-shrink: 0; font-size: .7rem; }
    [data-theme="dark"] .reminder-item i { color: #34D399; }

    /* Submit Button */
    .btn-book {
      width: 100%; padding: 15px; border: none; border-radius: 14px;
      background: var(--clr-brand-gradient);
      color: #fff; font-family: inherit; font-size: .98rem; font-weight: 800;
      cursor: pointer; transition: all .15s ease; display: flex; align-items: center; justify-content: center; gap: 9px;
      box-shadow: var(--btn-3d-shadow);
    }
    .btn-book:hover:not(:disabled) { transform: translateY(-2px); box-shadow: var(--btn-3d-shadow-hover); }
    .btn-book:active:not(:disabled) { transform: translateY(2px); box-shadow: var(--btn-3d-shadow-active); }
    .btn-book:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Alerts */
    .alert {
      border-radius: 16px; padding: 16px 20px; margin-bottom: 24px;
      display: flex; align-items: center; gap: 14px; font-size: .94rem; font-weight: 600;
      animation: alertIn .25s ease; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    @keyframes alertIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
    .alert-success { background: #ECFDF5; border: 1.5px solid #A7F3D0; color: #065F46; }
    [data-theme="dark"] .alert-success { background: rgba(16, 185, 129, 0.18); border-color: rgba(16, 185, 129, 0.45); color: #6EE7B7; }
    .alert-danger  { background: #FEF2F2; border: 1.5px solid #FECACA; color: #991B1B; }
    [data-theme="dark"] .alert-danger  { background: rgba(239, 68, 68, 0.18);  border-color: rgba(239, 68, 68, 0.45);  color: #FCA5A5; }

    /* Appointment list items */
    .appt-item {
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 20px; padding: 20px 24px; margin-bottom: 14px;
      display: flex; align-items: center; gap: 20px; transition: all .2s ease;
      position: relative; overflow: hidden; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    [data-theme="dark"] .appt-item {
      box-shadow: var(--shadow-card);
    }
    .appt-item:hover {
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08); border-color: #93C5FD;
      transform: translateY(-1px);
    }
    [data-theme="dark"] .appt-item:hover {
      box-shadow: 0 12px 28px rgba(0, 0, 0, 0.4); border-color: #38BDF8;
    }
    .appt-item.upcoming { border-left: 5px solid var(--clr-primary); }
    [data-theme="dark"] .appt-item.upcoming { border-left-color: var(--clr-primary-light); }
    .appt-item.cancelled { opacity: .65; border-left: 5px solid var(--text-muted); }

    .appt-date-box {
      text-align: center; min-width: 62px; flex-shrink: 0;
      background: #EFF6FF; padding: 10px 14px; border-radius: 14px;
      border: 1.5px solid #DBEAFE;
    }
    [data-theme="dark"] .appt-date-box {
      background: rgba(0, 173, 239, 0.16); border-color: rgba(0, 173, 239, 0.35);
    }
    .appt-day   { font-size: 1.8rem; font-weight: 900; color: var(--text-primary); line-height: 1; }
    .appt-month { font-size: .78rem; text-transform: uppercase; font-weight: 800; color: var(--clr-primary); letter-spacing: .08em; margin-top: 3px; }
    [data-theme="dark"] .appt-month { color: #38BDF8; }
    .appt-divider { width: 1px; height: 50px; background: var(--border-color); flex-shrink: 0; }
    .appt-info  { flex: 1; overflow: hidden; }
    .appt-time  { font-size: .98rem; font-weight: 800; color: var(--text-primary); margin-bottom: 3px; }
    .appt-purpose { font-size: .9rem; color: var(--text-secondary); font-weight: 700; }
    .appt-notes { font-size: .82rem; color: var(--text-muted); font-style: italic; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Status Badges */
    .status-badge {
      padding: 6px 16px; border-radius: 100px; font-size: .78rem; font-weight: 800; flex-shrink: 0;
      text-transform: uppercase; letter-spacing: .05em; display: inline-flex; align-items: center; gap: 6px;
    }
    .status-pending {
      background: #FFF7ED;
      color: #C2410C;
      border: 1.5px solid #FDBA74;
      box-shadow: 0 2px 6px rgba(234, 88, 12, 0.12);
    }
    [data-theme="dark"] .status-pending {
      background: rgba(249, 115, 22, 0.2);
      color: #FDBA74;
      border-color: rgba(251, 146, 60, 0.5);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
    .status-badge.status-pending i,
    .ticket-status-pill.status-pending i {
      color: #EA580C;
    }
    [data-theme="dark"] .status-badge.status-pending i,
    [data-theme="dark"] .ticket-status-pill.status-pending i {
      color: #FB923C;
    }
    .status-confirmed { background: #ECFDF5; color: #047857; border: 1.5px solid #A7F3D0; }
    [data-theme="dark"] .status-confirmed { background: rgba(16, 185, 129, 0.25); color: #A7F3D0; border-color: rgba(16, 185, 129, 0.5); }
    .status-completed { background: #E0F2FE; color: #0284C7; border: 1.5px solid #BAE6FD; }
    [data-theme="dark"] .status-completed { background: rgba(0, 173, 239, 0.25); color: #7DD3FC; border-color: rgba(0, 173, 239, 0.5); }
    .status-cancelled { background: #F1F5F9; color: #475569; border: 1.5px solid #CBD5E1; }
    [data-theme="dark"] .status-cancelled { background: rgba(148, 163, 184, 0.2); color: #CBD5E1; border-color: rgba(148, 163, 184, 0.35); }
    .status-no_show   { background: #FEE2E2; color: #B91C1C; border: 1.5px solid #FECACA; }
    [data-theme="dark"] .status-no_show { background: rgba(239, 68, 68, 0.25); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.45); }

    /* Action Buttons in Appointments */
    .edit-btn {
      background: #EFF6FF; border: 1.5px solid #BFDBFE; color: #0284C7;
      border-radius: 10px; padding: 7px 15px; font-family: inherit;
      font-size: .8rem; font-weight: 800; cursor: pointer; transition: all .2s; flex-shrink: 0;
      white-space: nowrap; display: inline-flex; align-items: center;
      box-shadow: 0 2px 0 #CBD5E1;
    }
    [data-theme="dark"] .edit-btn {
      background: rgba(56, 189, 248, 0.16); border-color: rgba(56, 189, 248, 0.45); color: #7DD3FC;
      box-shadow: 0 2px 0 #0D1626;
    }
    .edit-btn:hover:not(:disabled) {
      background: #0284C7; color: #FFFFFF; border-color: #0284C7;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3); transform: translateY(-1px);
    }
    [data-theme="dark"] .edit-btn:hover:not(:disabled) {
      background: #38BDF8; color: #0B1324; border-color: #38BDF8;
      box-shadow: 0 4px 14px rgba(56, 189, 248, 0.4);
    }
    .edit-btn:active:not(:disabled) {
      transform: translateY(1px); box-shadow: none;
    }
    .edit-btn:disabled { opacity: .45; cursor: not-allowed; }

    .cancel-btn {
      background: #FEF2F2; border: 1.5px solid #FECACA; color: #DC2626;
      border-radius: 10px; padding: 7px 15px; font-family: inherit;
      font-size: .8rem; font-weight: 800; cursor: pointer; transition: all .2s; flex-shrink: 0;
      display: inline-flex; align-items: center;
      box-shadow: 0 2px 0 #CBD5E1;
    }
    [data-theme="dark"] .cancel-btn {
      background: rgba(239, 68, 68, 0.18); border-color: rgba(239, 68, 68, 0.45); color: #FCA5A5;
      box-shadow: 0 2px 0 #0D1626;
    }
    .cancel-btn:hover {
      background: #EF4444; color: #FFFFFF; border-color: #EF4444;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35); transform: translateY(-1px);
    }
    .cancel-btn:active {
      transform: translateY(1px); box-shadow: none;
    }

    .empty-state {
      text-align: center; padding: 55px 20px;
      background: var(--bg-card); border: 1.5px solid var(--border-color); border-radius: 22px;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    .empty-state > i:first-child,
    .empty-state-icon {
      font-size: 3rem; opacity: .4; display: block; margin-bottom: 14px; color: var(--clr-primary);
    }
    [data-theme="dark"] .empty-state > i:first-child,
    [data-theme="dark"] .empty-state-icon {
      color: var(--clr-primary-light);
    }
    .empty-state p { font-size: .98rem; color: var(--text-muted); line-height: 1.6; margin: 0; }

    /* Universal Button Contrast & Styling (Guaranteed 100% Contrast & Visibility in Light & Dark Mode) */
    .btn-primary,
    .btn-book-cta,
    [data-theme="light"] .btn-primary,
    [data-theme="dark"] .btn-primary {
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%) !important;
      border: 1px solid rgba(255, 255, 255, 0.2) !important;
      color: #FFFFFF !important;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25) !important;
      box-shadow: 0 4px 14px rgba(0, 173, 239, 0.35) !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 8px !important;
      text-decoration: none !important;
      cursor: pointer !important;
    }
    .btn-primary:hover,
    .btn-book-cta:hover,
    [data-theme="light"] .btn-primary:hover,
    [data-theme="dark"] .btn-primary:hover {
      background: linear-gradient(135deg, #0284C7 0%, #1D4ED8 100%) !important;
      color: #FFFFFF !important;
      box-shadow: 0 6px 20px rgba(0, 173, 239, 0.45) !important;
      transform: translateY(-2px);
    }
    .btn-primary:active,
    .btn-book-cta:active,
    [data-theme="light"] .btn-primary:active,
    [data-theme="dark"] .btn-primary:active {
      transform: translateY(1px);
      box-shadow: 0 2px 8px rgba(0, 173, 239, 0.25) !important;
    }
    .btn-primary i,
    .btn-book-cta i,
    .btn-book i,
    .btn-wizard-next i,
    .btn-ticket-action i,
    .btn-modal-save i,
    [data-theme="light"] .btn-primary i,
    [data-theme="dark"] .btn-primary i {
      font-size: 1rem !important;
      display: inline-block !important;
      margin: 0 !important;
      opacity: 1 !important;
      color: #FFFFFF !important;
      line-height: 1 !important;
    }

    /* Guard against nested elements inside empty-state */
    .empty-state .btn,
    .empty-state button,
    .empty-state a.btn {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 8px !important;
      line-height: normal !important;
    }
    .empty-state .btn i,
    .empty-state button i,
    .empty-state a.btn i {
      font-size: 1rem !important;
      display: inline-block !important;
      margin: 0 !important;
      opacity: 1 !important;
      color: #FFFFFF !important;
      line-height: 1 !important;
    }

    /* MODAL SYSTEM */
    .modal-overlay {
      display: none !important; position: fixed; inset: 0; z-index: 9999;
      align-items: center; justify-content: center; padding: 20px; overflow-y: auto;
    }
    .modal-overlay.open, .modal-overlay.active { display: flex !important; }

    .modal-backdrop-custom {
      position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 1;
    }
    [data-theme="dark"] .modal-backdrop-custom {
      background: rgba(0, 0, 0, 0.78);
    }

    .modal-dialog-box {
      position: relative; z-index: 2; width: 100%;
      background: var(--bg-card); border: 1.5px solid var(--border-color);
      border-radius: 22px; overflow: hidden;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      animation: modalIn 0.22s ease; margin: auto;
    }
    [data-theme="dark"] .modal-dialog-box {
      border-color: rgba(255, 255, 255, 0.18);
      box-shadow: 0 32px 80px rgba(0, 0, 0, 0.75);
    }
    @keyframes modalIn {
      from { opacity: 0; transform: scale(0.96) translateY(12px); }
      to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    /* EDIT MODAL MODERN REDESIGN */
    .edit-modal-dialog {
      max-width: 600px;
      max-height: 88vh;
      display: flex;
      flex-direction: column;
      border-radius: 22px;
      overflow: hidden;
      box-shadow: 0 25px 60px -8px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.08);
    }
    [data-theme="dark"] .edit-modal-dialog {
      border: 1.5px solid rgba(56, 189, 248, 0.28);
      box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(56, 189, 248, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    }
    .edit-modal-header {
      background: var(--clr-brand-gradient);
      padding: 18px 24px;
      display: flex;
      align-items: center;
      gap: 14px;
      flex-shrink: 0;
      color: #fff;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }
    .edit-modal-header-icon {
      width: 42px; height: 42px; border-radius: 12px;
      background: rgba(255, 255, 255, 0.2);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.15rem; color: #fff; flex-shrink: 0;
    }
    .edit-modal-title { font-size: 1.15rem; font-weight: 900; color: #fff; letter-spacing: -0.015em; }
    .edit-modal-sub { font-size: .78rem; color: rgba(255, 255, 255, 0.88); font-weight: 500; margin-top: 2px; }
    .btn-close-modal {
      margin-left: auto; width: 34px; height: 34px; border-radius: 50%;
      border: none; background: rgba(255, 255, 255, 0.18); color: #fff;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      font-size: .95rem; transition: all .2s;
    }
    .btn-close-modal:hover { background: rgba(255, 255, 255, 0.3); transform: scale(1.08); }

    .edit-modal-form {
      display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;
    }
    .edit-modal-body {
      flex: 1; overflow-y: auto; padding: 22px 24px;
      scrollbar-width: thin;
      scrollbar-color: rgba(148, 163, 184, 0.4) transparent;
    }
    .edit-modal-body::-webkit-scrollbar { width: 6px; }
    .edit-modal-body::-webkit-scrollbar-track { background: transparent; }
    .edit-modal-body::-webkit-scrollbar-thumb {
      background-color: rgba(148, 163, 184, 0.4);
      border-radius: 10px;
    }
    [data-theme="dark"] .edit-modal-body::-webkit-scrollbar-thumb {
      background-color: rgba(255, 255, 255, 0.2);
    }

    .edit-modal-notice {
      background: #EFF6FF; border: 1.5px solid #DBEAFE; border-radius: 14px;
      padding: 12px 16px; margin-bottom: 20px; font-size: .82rem; line-height: 1.5;
      color: #1E40AF; display: flex; gap: 12px; align-items: flex-start;
    }
    [data-theme="dark"] .edit-modal-notice {
      background: rgba(35, 94, 174, 0.16); border-color: rgba(56, 189, 248, 0.3);
      color: #BAE6FD;
    }
    .edit-modal-notice i { font-size: 1.1rem; color: var(--clr-primary); margin-top: 2px; flex-shrink: 0; }
    [data-theme="dark"] .edit-modal-notice i { color: #38BDF8; }

    .edit-form-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    }
    @media (max-width: 540px) {
      .edit-form-grid { grid-template-columns: 1fr; }
    }
    .field-hint { font-size: .75rem; color: var(--text-muted); margin-top: 6px; font-weight: 500; }

    .edit-selected-chip {
      font-size: .78rem; font-weight: 800; padding: 5px 14px; border-radius: 100px;
      background: #F1F5F9; color: var(--text-muted); border: 1.5px solid #E2E8F0;
      transition: all .2s; box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    }
    [data-theme="dark"] .edit-selected-chip {
      background: #1A2844; color: #CBD5E1; border-color: rgba(56, 189, 248, 0.25);
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    }
    .edit-selected-chip.active {
      background: #EFF6FF; color: var(--clr-primary); border-color: #BFDBFE;
      box-shadow: 0 2px 8px rgba(35, 94, 174, 0.15);
    }
    [data-theme="dark"] .edit-selected-chip.active {
      background: rgba(56, 189, 248, 0.2); color: #38BDF8; border-color: rgba(56, 189, 248, 0.5);
      box-shadow: 0 2px 10px rgba(56, 189, 248, 0.3);
    }

    .edit-slots-container {
      background: var(--bg-input); border: 1.5px solid var(--border-color);
      border-radius: 16px; padding: 16px; min-height: 120px;
      display: flex; flex-direction: column; gap: 14px;
      box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    [data-theme="dark"] .edit-slots-container {
      background: #10192A; border-color: rgba(56, 189, 248, 0.22);
      box-shadow: inset 0 3px 8px rgba(0, 0, 0, 0.45);
    }
    .edit-slots-container .slot-section-title {
      margin: 4px 0 8px; font-size: .76rem;
    }
    .edit-slots-container .slot-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px;
    }
    .edit-slots-container .slot-btn {
      padding: 10px 4px; font-size: .84rem;
    }

    .edit-modal-footer {
      padding: 16px 24px; border-top: 1.5px solid var(--border-color);
      background: var(--bg-card); display: flex; gap: 12px; align-items: center;
      flex-shrink: 0;
    }
    .btn-modal-cancel {
      padding: 11px 22px; border-radius: 12px; border: 1.5px solid #CBD5E1;
      background: #FFFFFF; color: var(--text-muted); font-family: inherit;
      font-size: .88rem; font-weight: 700; cursor: pointer; transition: all .15s;
      box-shadow: 0 3px 0 #CBD5E1;
    }
    [data-theme="dark"] .btn-modal-cancel {
      background: #1E2D4A; border-color: rgba(255, 255, 255, 0.18); color: #CBD5E1;
      box-shadow: 0 3px 0 #0D1626;
    }
    .btn-modal-cancel:hover {
      border-color: var(--clr-primary); color: var(--text-primary);
      transform: translateY(-2px); box-shadow: 0 5px 0 #CBD5E1;
    }
    [data-theme="dark"] .btn-modal-cancel:hover {
      border-color: #38BDF8; color: #FFFFFF; background: rgba(56, 189, 248, 0.16);
      transform: translateY(-2px); box-shadow: 0 5px 0 #0D1626;
    }
    .btn-modal-cancel:active, [data-theme="dark"] .btn-modal-cancel:active {
      transform: translateY(2px); box-shadow: 0 1px 0 #0D1626;
    }

    .btn-modal-save {
      margin-left: auto; padding: 11px 26px; border-radius: 12px; border: none;
      background: var(--clr-brand-gradient);
      color: #fff; font-family: inherit; font-size: .9rem; font-weight: 800;
      cursor: pointer; box-shadow: var(--btn-3d-shadow);
      transition: all .15s ease; display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-modal-save:hover:not(:disabled) {
      transform: translateY(-2px); box-shadow: var(--btn-3d-shadow-hover);
    }
    .btn-modal-save:active:not(:disabled) {
      transform: translateY(2px); box-shadow: var(--btn-3d-shadow-active);
    }
    .btn-modal-save:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }

    .appt-actions-wrap {
      display: flex; flex-direction: column; align-items: flex-end; gap: 10px; flex-shrink: 0;
    }

    @media (max-width: 992px) {
      .book-grid-layout { grid-template-columns: 1fr !important; }
      .ticket-card { flex-direction: column; }
      .ticket-stub-left {
        border-right: none; border-bottom: 2px dashed #CBD5E1;
        width: 100%; flex-direction: row; gap: 16px; padding: 18px 22px;
        justify-content: flex-start;
      }
      [data-theme="dark"] .ticket-stub-left {
        border-bottom-color: rgba(255, 255, 255, 0.22);
      }
      .ticket-stub-right {
        border-left: none; border-top: 2px dashed #CBD5E1;
        width: 100%; flex-direction: row; justify-content: space-between; padding: 16px 22px;
        align-items: center;
      }
      [data-theme="dark"] .ticket-stub-right {
        border-top-color: rgba(255, 255, 255, 0.22);
      }
      .ticket-notch-top, .ticket-notch-bottom { display: none; }
    }

    @media (max-width: 640px) {
      /* Page wrap & Topbar */
      .page-wrap { padding: 16px 12px 60px; }
      .topbar { padding: 0 14px; height: 60px; }
      .topbar-brand { gap: 10px; }
      .topbar-logo { width: 36px; height: 36px; border-radius: 8px; }
      .topbar-name { font-size: .92rem; }
      .topbar-sub { display: none; }
      .user-chip { padding: 3px 10px 3px 3px; }
      .user-name { font-size: .82rem; }

      /* Welcome Header */
      .welcome-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
      }
      .welcome-title { font-size: 1.35rem; letter-spacing: -0.02em; }
      .welcome-subtitle { font-size: .82rem; line-height: 1.45; }
      .welcome-date-badge {
        align-self: flex-start;
        padding: 6px 14px;
        font-size: .78rem;
        border-radius: 9999px;
      }

      /* Stats Row: Balanced Modern Bento Grid */
      .bento-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
      }
      .bento-stats-grid .modern-stat-card {
        padding: 14px 14px 12px !important;
        border-radius: 18px !important;
        gap: 10px !important;
      }
      .bento-stats-grid .modern-stat-card:nth-child(5) {
        grid-column: 1 / -1 !important;
      }
      .stat-card-icon {
        width: 38px !important;
        height: 38px !important;
        font-size: 1.05rem !important;
        border-radius: 11px !important;
      }
      .stat-micro-pill {
        font-size: 0.62rem !important;
        padding: 2px 7px !important;
      }
      .stat-card-number {
        font-size: 1.6rem !important;
        line-height: 1.1 !important;
      }
      .stat-card-label {
        font-size: .72rem !important;
        margin-top: 2px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
      }
      .stat-card-bar {
        left: 14px !important;
        right: 14px !important;
      }

      /* Boarding Pass Ticket Card */
      .ticket-card {
        border-radius: 18px !important;
        margin-bottom: 20px !important;
      }
      .ticket-stub-left {
        padding: 12px 16px !important;
        gap: 10px !important;
        flex-wrap: wrap !important;
      }
      .ticket-day { font-size: 1.8rem !important; }
      .ticket-month { font-size: .76rem !important; margin-top: 0 !important; }
      .ticket-weekday { font-size: .72rem !important; }
      .ticket-time-chip { font-size: .72rem !important; padding: 4px 8px !important; margin-top: 0 !important; }
      .ticket-body { padding: 14px 16px !important; }
      .ticket-header-row { flex-wrap: wrap !important; gap: 6px !important; margin-bottom: 8px !important; }
      .ticket-tag { font-size: .68rem !important; padding: 3px 8px !important; }
      .ticket-countdown { font-size: .68rem !important; padding: 3px 8px !important; }
      .ticket-title { font-size: 1.05rem !important; margin-bottom: 8px !important; }
      .ticket-service-badge { font-size: .72rem !important; padding: 4px 10px !important; margin-bottom: 8px !important; }
      .ticket-meta { gap: 4px !important; }
      .ticket-meta-item { font-size: .72rem !important; }
      .ticket-stub-right {
        padding: 12px 16px !important;
        gap: 8px !important;
      }
      .ticket-status-pill { padding: 5px 12px !important; font-size: .72rem !important; }
      .btn-ticket-action { padding: 8px 14px !important; font-size: .78rem !important; }

      /* Tab Bar: 50/50 Equal Segmented Control */
      .tab-bar {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        margin-bottom: 18px !important;
      }
      .tab-btn {
        width: 100% !important;
        justify-content: center !important;
        padding: 11px 8px !important;
        font-size: .82rem !important;
        border-radius: 14px !important;
        gap: 6px !important;
      }

      /* Booking Form Card */
      .form-card { border-radius: 18px !important; }
      .form-card-header { padding: 16px 14px !important; gap: 10px !important; }
      .form-card-icon { width: 38px !important; height: 38px !important; font-size: 1rem !important; border-radius: 10px !important; }
      .form-card-title { font-size: 1.05rem !important; }
      .form-card-sub { font-size: .76rem !important; line-height: 1.35 !important; }
      .form-card-body { padding: 16px 12px !important; }

      /* Stepper */
      .stepper-wrap {
        padding: 0 4px !important;
        margin-bottom: 22px !important;
      }
      .stepper-track-bg, .stepper-track-fill {
        top: 17px !important;
        left: 20px !important;
        right: 20px !important;
        height: 3px !important;
      }
      .step-circle {
        width: 34px !important;
        height: 34px !important;
        font-size: .76rem !important;
      }
      .step-text {
        font-size: .68rem !important;
        margin-top: 5px !important;
      }

      /* Step 1: Purpose Cards -> Native-like Horizontal Option Tiles */
      .purpose-grid {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
        margin-bottom: 18px !important;
      }
      .purpose-card {
        flex-direction: row !important;
        align-items: center !important;
        gap: 12px !important;
        padding: 12px 14px !important;
        border-radius: 16px !important;
      }
      .purpose-card-icon {
        width: 42px !important;
        height: 42px !important;
        font-size: 1.15rem !important;
        border-radius: 12px !important;
        flex-shrink: 0 !important;
      }
      .purpose-card-title { font-size: .9rem !important; margin-bottom: 2px !important; }
      .purpose-card-desc { font-size: .74rem !important; line-height: 1.35 !important; }

      /* Step 2: Services Grid */
      .service-grid {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
        margin-bottom: 18px !important;
      }
      .service-card {
        padding: 14px 14px !important;
        border-radius: 16px !important;
      }
      .service-title { font-size: .9rem !important; }
      .service-desc { font-size: .75rem !important; line-height: 1.35 !important; }

      /* Step 3: Quick Dates Horizontal Carousel */
      .quick-dates {
        display: flex !important;
        gap: 8px !important;
        overflow-x: auto !important;
        flex-wrap: nowrap !important;
        padding-bottom: 8px !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
      }
      .quick-dates::-webkit-scrollbar { display: none !important; }
      .qdate-btn {
        flex-shrink: 0 !important;
        padding: 8px 14px !important;
        font-size: .78rem !important;
      }

      /* Time Slots Grid: 3 Even Columns on Mobile */
      .slots-grid, #slotGrid .slot-grid, .edit-slots-container .slot-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8px !important;
      }
      .slot-btn {
        padding: 8px 4px !important;
        font-size: .8rem !important;
        border-radius: 10px !important;
      }
      .slot-btn .slot-time { font-size: .78rem !important; }
      .slot-btn .slot-period { font-size: .62rem !important; }

      /* Wizard Navigation Buttons */
      .wizard-nav-bar {
        display: flex !important;
        gap: 8px !important;
        margin-top: 18px !important;
        padding-top: 16px !important;
      }
      .btn-wizard-back, .btn-wizard-next {
        flex: 1 !important;
        justify-content: center !important;
        padding: 12px 14px !important;
        font-size: .84rem !important;
        border-radius: 12px !important;
        margin-left: 0 !important;
      }

      /* History Appointments Cards */
      .appt-item {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 14px !important;
        border-radius: 18px !important;
      }
      .appt-divider { display: none !important; }
      .appt-date-box {
        width: 100% !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 10px !important;
        padding: 8px 12px !important;
        border-radius: 12px !important;
      }
      .appt-day { font-size: 1.35rem !important; }
      .appt-month { font-size: .76rem !important; }
      .appt-info { width: 100% !important; }
      .appt-actions-wrap {
        width: 100% !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding-top: 10px !important;
        border-top: 1px dashed var(--border-color) !important;
      }
      .appt-item > .status-badge {
        align-self: flex-start !important;
        margin-top: 4px !important;
      }

      /* Modals */
      .modal-overlay { padding: 10px !important; }
      .modal-dialog-box { border-radius: 18px !important; }
      .edit-modal-dialog { max-height: 94vh !important; border-radius: 18px !important; }
      .edit-modal-header { padding: 14px 16px !important; }
      .edit-modal-body { padding: 14px 14px !important; }
      .edit-modal-footer { padding: 12px 14px !important; }

      /* Mobile Prescription Tweaks */
      .rx-card-topbar { flex-direction: column !important; align-items: flex-start !important; gap: 8px !important; }
      .rx-topbar-actions { width: 100% !important; justify-content: flex-start !important; }
      .rx-patient-ribbon { grid-template-columns: 1fr !important; gap: 8px !important; }
      .rx-pad-details-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
      .rx-pad-slip { padding: 20px 16px !important; }
      .rx-slip-header { flex-direction: column !important; align-items: flex-start !important; gap: 10px !important; }
      .rx-slip-contacts { text-align: left !important; }
      .rx-doctor-signature-section { flex-direction: column !important; align-items: flex-start !important; gap: 16px !important; }
      .rx-signature-block { width: 100% !important; text-align: left !important; }
    }

    /* ============================================================
       OPTICAL PRESCRIPTION STYLES & HIGHLIGHT CARD
       ============================================================ */
    .stat-pill-icon.indigo {
      background: #F5F3FF; color: #7C3AED; border: 1.5px solid #DDD6FE;
      box-shadow: 0 2px 5px rgba(124, 58, 237, 0.08);
    }
    [data-theme="dark"] .stat-pill-icon.indigo {
      background: rgba(139, 92, 246, 0.22); color: #A78BFA; border: 1.5px solid rgba(167, 139, 250, 0.45);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
    }

    .rx-highlight-card {
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-left: 5px solid #7C3AED;
      border-radius: 22px;
      padding: 22px 24px;
      margin-bottom: 24px;
      box-shadow: 0 4px 16px rgba(124, 58, 237, 0.08);
      position: relative;
      transition: all .25s ease;
    }
    [data-theme="dark"] .rx-highlight-card {
      border-color: rgba(139, 92, 246, 0.3);
      border-left-color: #A78BFA;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
    }
    .rx-highlight-card:hover {
      box-shadow: 0 10px 28px rgba(124, 58, 237, 0.14);
      transform: translateY(-2px);
    }

    .rx-card-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 16px;
      padding-bottom: 14px;
      border-bottom: 1px dashed var(--border-color);
    }
    .rx-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: linear-gradient(135deg, #7C3AED, #6366F1);
      color: #FFFFFF;
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      padding: 6px 14px;
      border-radius: 100px;
      box-shadow: 0 3px 10px rgba(124, 58, 237, 0.35);
    }
    .rx-topbar-meta {
      display: flex;
      align-items: center;
      gap: 16px;
      font-size: 0.82rem;
      color: var(--text-secondary);
      font-weight: 600;
    }
    .rx-topbar-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-rx-action {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 700;
      cursor: pointer;
      transition: all .18s ease;
      border: 1.5px solid transparent;
      text-decoration: none;
    }
    .btn-rx-action.primary {
      background: rgba(124, 58, 237, 0.1);
      color: #7C3AED;
      border-color: rgba(124, 58, 237, 0.28);
    }
    [data-theme="dark"] .btn-rx-action.primary {
      background: rgba(167, 139, 250, 0.16);
      color: #C4B5FD;
      border-color: rgba(167, 139, 250, 0.35);
    }
    .btn-rx-action.primary:hover {
      background: #7C3AED;
      color: #FFFFFF;
      border-color: #7C3AED;
      transform: translateY(-1px);
    }
    .btn-rx-action.print {
      background: var(--bg-hover);
      color: var(--text-primary);
      border-color: var(--border-color);
    }
    .btn-rx-action.print:hover {
      background: var(--border-color);
      transform: translateY(-1px);
    }

    /* Specs Table */
    .rx-specs-table-wrapper {
      overflow-x: auto;
      margin-bottom: 14px;
      border-radius: 14px;
      border: 1px solid var(--border-color);
      background: var(--bg-card);
    }
    .rx-specs-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.84rem;
      text-align: center;
      min-width: 480px;
    }
    .rx-specs-table th {
      background: var(--bg-hover);
      color: var(--text-muted);
      font-weight: 700;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      padding: 10px 12px;
      border-bottom: 1px solid var(--border-color);
    }
    .rx-specs-table th.th-eye {
      text-align: left;
      padding-left: 18px;
    }
    .rx-th-hint {
      font-weight: 500;
      font-size: 0.65rem;
      color: var(--text-subtle);
      text-transform: none;
      display: block;
    }
    .rx-specs-table td {
      padding: 11px 12px;
      border-bottom: 1px solid var(--border-light);
      color: var(--text-primary);
    }
    .rx-specs-table tr:last-child td {
      border-bottom: none;
    }
    .rx-specs-table td.td-eye {
      text-align: left;
      font-weight: 700;
      font-size: 0.85rem;
      padding-left: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .eye-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 24px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 900;
      color: #fff;
    }
    .eye-badge.od { background: #00ADEF; }
    .eye-badge.os { background: #7C3AED; }
    .font-mono {
      font-family: 'SF Mono', 'Fira Code', 'Roboto Mono', Menlo, monospace;
      font-weight: 600;
    }

    /* Rx Card Footer */
    .rx-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.82rem;
      padding-top: 10px;
    }
    .rx-footer-pills {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .rx-info-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      padding: 4px 12px;
      border-radius: 100px;
      color: var(--text-secondary);
      font-size: 0.78rem;
    }
    .rx-info-pill i {
      color: var(--clr-primary);
    }
    .rx-notes-snippet {
      color: var(--text-secondary);
      font-size: 0.8rem;
      background: rgba(0, 173, 239, 0.08);
      border-left: 3px solid var(--clr-primary);
      padding: 6px 12px;
      border-radius: 8px;
      max-width: 520px;
      line-height: 1.4;
    }
    [data-theme="dark"] .rx-notes-snippet {
      background: rgba(56, 189, 248, 0.1);
      border-left-color: #38BDF8;
    }
    .rx-all-link {
      color: #7C3AED;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      transition: all .15s ease;
      margin-left: auto;
    }
    [data-theme="dark"] .rx-all-link {
      color: #A78BFA;
    }
    .rx-all-link:hover {
      color: #6D28D9;
      transform: translateX(3px);
    }

    /* PRESCRIPTIONS TAB LIST */
    .rx-card-list {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .rx-record-card {
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 20px;
      padding: 22px 24px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
      position: relative;
      transition: all .2s ease;
    }
    [data-theme="dark"] .rx-record-card {
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
    }
    .rx-record-card:hover {
      border-color: #7C3AED;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(124, 58, 237, 0.1);
    }
    [data-theme="dark"] .rx-record-card:hover {
      border-color: #A78BFA;
    }
    .rx-record-card.latest-rx {
      border-color: #7C3AED;
      border-left: 5px solid #7C3AED;
    }
    [data-theme="dark"] .rx-record-card.latest-rx {
      border-color: rgba(139, 92, 246, 0.4);
      border-left-color: #A78BFA;
    }

    /* Rx Prescription Slip Modal (Authentic Clinic Pad Design) */
    .rx-slip-dialog {
      max-width: 680px;
      background: #FFFFFF !important;
      color: #0F172A !important;
      border-radius: 20px;
      box-shadow: 0 30px 90px rgba(0, 0, 0, 0.45);
      border: none;
    }
    .rx-pad-slip {
      padding: 34px 38px 28px;
      position: relative;
      background: #FFFFFF;
      color: #0F172A;
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    }
    .rx-watermark-symbol {
      position: absolute;
      right: 40px;
      top: 140px;
      font-size: 160px;
      font-family: Georgia, 'Times New Roman', serif;
      font-style: italic;
      color: rgba(35, 94, 174, 0.04);
      pointer-events: none;
      user-select: none;
      line-height: 1;
      font-weight: 900;
    }
    .rx-slip-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 2.5px solid #00ADEF;
      padding-bottom: 18px;
      margin-bottom: 18px;
    }
    .rx-slip-brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .rx-slip-logo {
      width: 52px;
      height: 52px;
      object-fit: contain;
    }
    .rx-slip-clinic-name {
      font-size: 1.35rem;
      font-weight: 900;
      color: #0F172A;
      line-height: 1.15;
      letter-spacing: -0.02em;
    }
    .rx-slip-clinic-sub {
      font-size: 0.78rem;
      color: #64748B;
      font-weight: 600;
      margin-top: 2px;
    }
    .rx-slip-contacts {
      text-align: right;
      font-size: 0.73rem;
      color: #64748B;
      line-height: 1.45;
    }
    .rx-patient-ribbon {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr;
      gap: 14px;
      background: #F8FAFC;
      border: 1px solid #E2E8F0;
      border-radius: 12px;
      padding: 12px 18px;
      margin-bottom: 22px;
      font-size: 0.82rem;
    }
    .rx-ribbon-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: #64748B;
      font-weight: 700;
      margin-bottom: 2px;
    }
    .rx-ribbon-val {
      font-weight: 700;
      color: #0F172A;
      font-size: 0.88rem;
    }
    .rx-symbol-banner {
      display: flex;
      align-items: baseline;
      gap: 12px;
      margin-bottom: 10px;
    }
    .rx-big-symbol {
      font-size: 2.2rem;
      font-family: Georgia, 'Times New Roman', serif;
      font-weight: 900;
      font-style: italic;
      color: #00ADEF;
      line-height: 1;
    }
    .rx-symbol-text {
      font-size: 0.78rem;
      text-transform: uppercase;
      font-weight: 800;
      letter-spacing: 1px;
      color: #475569;
    }

    .rx-pad-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      border: 1.5px solid #CBD5E1;
      border-radius: 10px;
      overflow: hidden;
    }
    .rx-pad-table th {
      background: #F1F5F9;
      color: #334155;
      font-size: 0.74rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 8px 10px;
      border: 1px solid #CBD5E1;
      text-align: center;
    }
    .rx-pad-table td {
      border: 1px solid #CBD5E1;
      padding: 10px 10px;
      text-align: center;
      font-size: 0.86rem;
      font-family: 'SF Mono', 'Fira Code', 'Roboto Mono', monospace;
      color: #0F172A;
      font-weight: 600;
    }
    .rx-pad-table td.td-eye-name {
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
      font-weight: 800;
      text-align: left;
      padding-left: 14px;
    }

    .rx-pad-details-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 22px;
      font-size: 0.84rem;
    }
    .rx-pad-box {
      background: #F8FAFC;
      border: 1px dashed #CBD5E1;
      border-radius: 10px;
      padding: 10px 14px;
    }
    .rx-pad-box-lbl {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 800;
      color: #64748B;
      margin-bottom: 4px;
    }
    .rx-pad-box-val {
      font-weight: 600;
      color: #0F172A;
      line-height: 1.45;
    }

    .rx-doctor-signature-section {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-top: 28px;
      padding-top: 14px;
      border-top: 1px solid #E2E8F0;
    }
    .rx-digital-seal {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.72rem;
      color: #64748B;
    }
    .rx-seal-badge {
      width: 40px;
      height: 40px;
      border: 2px dashed #00ADEF;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #00ADEF;
      font-size: 1.1rem;
    }
    .rx-signature-block {
      text-align: center;
      width: 220px;
    }
    .rx-signature-line {
      border-top: 1.5px solid #0F172A;
      padding-top: 6px;
      font-weight: 800;
      font-size: 0.85rem;
      color: #0F172A;
    }
    .rx-signature-sub {
      font-size: 0.7rem;
      color: #64748B;
      font-weight: 600;
    }

    .rx-modal-toolbar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      padding: 14px 24px;
      background: #F1F5F9;
      border-top: 1px solid #E2E8F0;
    }

    /* Print Stylesheet */
    @media print {
      body * {
        visibility: hidden !important;
      }
      #rxSlipModal, #rxSlipModal * {
        visibility: visible !important;
      }
      #rxSlipModal {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
        background: #fff !important;
      }
      #rxSlipModal .modal-backdrop-custom,
      #rxSlipModal .rx-modal-toolbar,
      #rxSlipModal .btn-close-modal {
        display: none !important;
      }
      #rxSlipModal .modal-dialog-box {
        border: none !important;
        box-shadow: none !important;
        max-width: 100% !important;
        width: 100% !important;
      }
      #rxSlipModal .rx-pad-slip {
        padding: 20px !important;
      }
    }
  </style>
</head>
<body>
<div class="bg-mesh"></div>

<!-- TOPBAR -->
<nav class="topbar">
  <a href="dashboard.php" class="topbar-brand">
    <img src="<?= htmlspecialchars(getClinicLogoUrl('../')) ?>" alt="Logo" class="topbar-logo">
    <div>
      <div class="topbar-name">Gueco Optical Clinic</div>
      <div class="topbar-sub">Patient Portal · Capas, Tarlac</div>
    </div>
  </a>
  <div class="topbar-right">
    <button class="theme-btn" id="themeToggle" title="Toggle Light/Dark Theme">
      <i class="<?= $currentTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon' ?>" id="themeIcon"></i>
    </button>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-chip" onclick="toggleDropdown()">
        <div class="user-avatar">
          <?php 
            $navAvatar = $patient['avatar'] ?? ($_SESSION['patient_avatar'] ?? '');
            if (!empty($navAvatar)): 
              $navSrc = str_starts_with($navAvatar, 'http') ? $navAvatar : (BASE_URL . ltrim($navAvatar, '/'));
          ?>
            <img src="<?= htmlspecialchars($navSrc) ?>" alt="Avatar">
          <?php else: ?>
            <?= strtoupper(substr($patient['full_name'] ?? ($_SESSION['patient_name'] ?? 'P'), 0, 1)) ?>
          <?php endif; ?>
        </div>
        <span class="user-name"><?= sanitize(explode(' ', $_SESSION['patient_name'] ?? 'Patient')[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:.7rem;color:var(--text-muted);margin-left:4px;"></i>
      </div>
      <div class="user-dropdown-menu">
        <a href="javascript:void(0)" onclick="switchTab('prescriptions', document.getElementById('tab-prescriptions'))" class="dropdown-item"><i class="fas fa-glasses"></i> My Prescriptions</a>
        <a href="settings.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Profile Settings</a>
        <a href="change_password.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
        <div style="height:1px;background:var(--border-color);margin:4px 0;"></div>
        <a href="logout.php" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">

  <!-- Welcome Header -->
  <div class="welcome-header">
    <div class="welcome-text">
      <h1 class="welcome-title"><?= $greetingPrefix ?>, <?= htmlspecialchars($patientFirstName) ?> <span>👋</span></h1>
      <p class="welcome-subtitle">Manage your eye care appointments, schedules, and optical consultation history.</p>
    </div>
    <div class="welcome-date-badge">
      <i class="fas fa-calendar-alt"></i>
      <span><?= date('l, F j, Y') ?></span>
    </div>
  </div>

  <!-- Notifications Popup Carrier (SweetAlert2 Modal Popup) -->
  <?php if ($flashMsg): ?>
  <div id="patientFlashMsg"
       data-msg="<?= htmlspecialchars((string)$flashMsg, ENT_QUOTES) ?>"
       data-type="<?= htmlspecialchars((string)$flashType, ENT_QUOTES) ?>"
       data-title="<?= htmlspecialchars((string)$flashTitle, ENT_QUOTES) ?>"
       style="display:none"></div>
  <?php endif; ?>

  <!-- Next Appointment Ticket (Boarding Pass Layout) -->
  <?php if ($nextAppt): 
    $dNext = new DateTime($nextAppt['appointment_date']);
  ?>
  <div class="ticket-card">
    <div class="ticket-stub-left">
      <div class="ticket-notch-top"></div>
      <div class="ticket-day"><?= $dNext->format('d') ?></div>
      <div class="ticket-month"><?= $dNext->format('M') ?></div>
      <div class="ticket-weekday"><?= $dNext->format('l') ?></div>
      <div class="ticket-time-chip">
        <i class="fas fa-clock"></i> <?= formatTime($nextAppt['appointment_time']) ?>
      </div>
      <div class="ticket-notch-bottom"></div>
    </div>

    <div class="ticket-body">
      <div class="ticket-header-row">
        <span class="ticket-tag"><i class="fas fa-ticket-alt"></i> Next Appointment</span>
        <?php if ($countdownText): ?>
        <span class="ticket-countdown <?= $countdownBadge ?>">
          <i class="fas fa-bolt"></i> <?= $countdownText ?>
        </span>
        <?php endif; ?>
      </div>

      <div class="ticket-title"><?= ucwords(str_replace('_',' ',$nextAppt['purpose'])) ?></div>

      <?php if ($nextService): ?>
      <div class="ticket-service-badge">
        <i class="fas fa-hand-holding-medical" style="color:var(--clr-primary-light);"></i>
        <span><?= htmlspecialchars(htmlspecialchars_decode((string)$nextService, ENT_QUOTES), ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php endif; ?>

      <div class="ticket-meta">
        <div class="ticket-meta-item"><i class="fas fa-clinic-medical"></i> Gueco Optical Clinic · Capas, Tarlac</div>
        <div class="ticket-meta-item"><i class="fas fa-user-doctor"></i> Licensed Optometrist Examination</div>
      </div>
    </div>

    <div class="ticket-stub-right">
      <div class="ticket-status-pill status-<?= $nextAppt['status'] ?>">
        <?php if ($nextAppt['status'] === 'pending'): ?>
          <i class="fas fa-hourglass-half status-badge-icon me-1"></i>
        <?php elseif ($nextAppt['status'] === 'confirmed'): ?>
          <span class="status-dot dot-green pulse"></span>
        <?php else: ?>
          <span class="status-dot dot-amber"></span>
        <?php endif; ?>
        <?= ucfirst($nextAppt['status']) ?>
      </div>
      <?php
      $cleanNextNotes = trim(preg_replace('/^Service:\s*.+$/m', '', $nextAppt['notes'] ?? ''));
      ?>
      <button type="button" class="btn-ticket-action" id="btnTicketDetails"
              data-id="<?= $nextAppt['id'] ?>"
              data-date="<?= $nextAppt['appointment_date'] ?>"
              data-time="<?= $nextAppt['appointment_time'] ?>"
              data-status="<?= $nextAppt['status'] ?>"
              data-purpose="<?= htmlspecialchars((string)$nextAppt['purpose'], ENT_QUOTES) ?>"
              data-service="<?= htmlspecialchars((string)$nextService, ENT_QUOTES) ?>"
              data-notes="<?= htmlspecialchars((string)$cleanNextNotes, ENT_QUOTES) ?>"
              data-raw-notes="<?= htmlspecialchars((string)($nextAppt['notes'] ?? ''), ENT_QUOTES) ?>"
              data-countdown="<?= htmlspecialchars((string)$countdownText, ENT_QUOTES) ?>"
              onclick="showAppointmentDetails(this)">
        <span>View Details</span> <i class="fas fa-arrow-right"></i>
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Active Optical Prescription Highlight Card (When available) -->
  <?php if ($latestRx): 
    $rxDate = new DateTime($latestRx['created_at']);
    $rxDoc = !empty($latestRx['doctor_name']) ? ('Dr. ' . sanitize($latestRx['doctor_name'])) : 'Attending Optometrist';
    $rxOdAdd = $latestRx['od_add'] ?? $latestRx['add_power'] ?? '';
    $rxOsAdd = $latestRx['os_add'] ?? $latestRx['add_power'] ?? '';
    $rxNotes = $latestRx['notes'] ?? $latestRx['recommendations'] ?? '';
  ?>
  <div class="rx-highlight-card">
    <div class="rx-card-topbar">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div class="rx-badge-pill">
          <i class="fas fa-glasses"></i>
          <span>Active Optical Prescription</span>
        </div>
        <div class="rx-topbar-meta">
          <span><i class="fas fa-calendar-check me-1"></i> Exam Date: <strong><?= $rxDate->format('M j, Y') ?></strong></span>
          <span><i class="fas fa-user-doctor me-1"></i> <?= $rxDoc ?></span>
        </div>
      </div>
      <div class="rx-topbar-actions">
        <button type="button" class="btn-rx-action primary" onclick="openRxModal(<?= (int)$latestRx['id'] ?>)">
          <i class="fas fa-file-prescription"></i> <span>View Official Copy</span>
        </button>
        <button type="button" class="btn-rx-action print" onclick="printRxDirect(<?= (int)$latestRx['id'] ?>)">
          <i class="fas fa-print"></i> <span>Print Copy</span>
        </button>
      </div>
    </div>

    <!-- Rx Quick Specs Grid -->
    <div class="rx-specs-table-wrapper">
      <table class="rx-specs-table">
        <thead>
          <tr>
            <th class="th-eye">Eye</th>
            <th>SPH <span class="rx-th-hint">(Sphere)</span></th>
            <th>CYL <span class="rx-th-hint">(Cylinder)</span></th>
            <th>AXIS <span class="rx-th-hint">(Orientation)</span></th>
            <th>ADD <span class="rx-th-hint">(Near Reading)</span></th>
            <?php if (!empty($latestRx['od_va']) || !empty($latestRx['os_va'])): ?>
            <th>VA <span class="rx-th-hint">(Acuity)</span></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="td-eye eye-od"><span class="eye-badge od">OD</span> Right Eye</td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['od_sphere'] ?? '—'), ENT_QUOTES) ?></td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['od_cylinder'] ?? '—'), ENT_QUOTES) ?></td>
            <td class="td-val font-mono"><?= !empty($latestRx['od_axis']) ? htmlspecialchars((string)$latestRx['od_axis'], ENT_QUOTES) . '°' : '—' ?></td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($rxOdAdd ?: '—'), ENT_QUOTES) ?></td>
            <?php if (!empty($latestRx['od_va']) || !empty($latestRx['os_va'])): ?>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['od_va'] ?? '—'), ENT_QUOTES) ?></td>
            <?php endif; ?>
          </tr>
          <tr>
            <td class="td-eye eye-os"><span class="eye-badge os">OS</span> Left Eye</td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['os_sphere'] ?? '—'), ENT_QUOTES) ?></td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['os_cylinder'] ?? '—'), ENT_QUOTES) ?></td>
            <td class="td-val font-mono"><?= !empty($latestRx['os_axis']) ? htmlspecialchars((string)$latestRx['os_axis'], ENT_QUOTES) . '°' : '—' ?></td>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($rxOsAdd ?: '—'), ENT_QUOTES) ?></td>
            <?php if (!empty($latestRx['od_va']) || !empty($latestRx['os_va'])): ?>
            <td class="td-val font-mono"><?= htmlspecialchars((string)($latestRx['os_va'] ?? '—'), ENT_QUOTES) ?></td>
            <?php endif; ?>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Rx Card Footer -->
    <div class="rx-card-footer">
      <div class="rx-footer-pills">
        <?php if (!empty($latestRx['pd'])): ?>
        <div class="rx-info-pill">
          <i class="fas fa-arrows-left-right"></i>
          <span><strong>PD:</strong> <?= htmlspecialchars((string)$latestRx['pd'], ENT_QUOTES) ?> mm</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($latestRx['lens_type'])): ?>
        <div class="rx-info-pill">
          <i class="fas fa-layer-group"></i>
          <span><strong>Lens:</strong> <?= htmlspecialchars((string)$latestRx['lens_type'], ENT_QUOTES) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($rxNotes)): ?>
      <div class="rx-notes-snippet">
        <i class="fas fa-info-circle me-1"></i>
        <span><strong>Advice:</strong> <?= htmlspecialchars((string)$rxNotes, ENT_QUOTES) ?></span>
      </div>
      <?php endif; ?>
      <a href="javascript:void(0)" onclick="switchTab('prescriptions', document.getElementById('tab-prescriptions'))" class="rx-all-link">
        <span>Prescription History (<?= $totalRxCount ?>)</span> <i class="fas fa-chevron-right ms-1"></i>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modern Bento Stats Section -->
  <div class="stats-row bento-stats-grid">
    <!-- Card 1: Total Bookings -->
    <div class="modern-stat-card theme-sapphire" onclick="switchTab('history', document.getElementById('tab-history'))" style="cursor:pointer;" title="View Appointment History">
      <div class="stat-card-glow"></div>
      <div class="stat-card-header">
        <div class="stat-card-icon sapphire">
          <i class="fas fa-calendar-check"></i>
        </div>
        <span class="stat-micro-pill sapphire">Bookings</span>
      </div>
      <div class="stat-card-content">
        <div class="stat-card-number"><?= $totalAppts ?></div>
        <div class="stat-card-label">Total Appointments</div>
      </div>
      <div class="stat-card-bar sapphire"></div>
    </div>

    <!-- Card 2: Upcoming Visits -->
    <div class="modern-stat-card theme-cyan <?= $upcomingCount > 0 ? 'has-active' : '' ?>" onclick="switchTab('history', document.getElementById('tab-history'))" style="cursor:pointer;" title="View Upcoming Appointments">
      <div class="stat-card-glow"></div>
      <div class="stat-card-header">
        <div class="stat-card-icon cyan">
          <i class="fas fa-clock-rotate-left"></i>
        </div>
        <span class="stat-micro-pill cyan <?= $upcomingCount > 0 ? 'pulse' : '' ?>">
          <?= $upcomingCount > 0 ? 'Upcoming' : 'None' ?>
        </span>
      </div>
      <div class="stat-card-content">
        <div class="stat-card-number"><?= $upcomingCount ?></div>
        <div class="stat-card-label">Upcoming Visits</div>
      </div>
      <div class="stat-card-bar cyan"></div>
    </div>

    <!-- Card 3: Completed Consultations -->
    <div class="modern-stat-card theme-emerald" onclick="switchTab('history', document.getElementById('tab-history'))" style="cursor:pointer;" title="View Completed Appointments">
      <div class="stat-card-glow"></div>
      <div class="stat-card-header">
        <div class="stat-card-icon emerald">
          <i class="fas fa-circle-check"></i>
        </div>
        <span class="stat-micro-pill emerald">Finished</span>
      </div>
      <div class="stat-card-content">
        <div class="stat-card-number"><?= $completedCount ?></div>
        <div class="stat-card-label">Completed Consults</div>
      </div>
      <div class="stat-card-bar emerald"></div>
    </div>

    <!-- Card 4: Pending Review -->
    <div class="modern-stat-card theme-amber <?= $pendingCount > 0 ? 'has-pending' : '' ?>" onclick="switchTab('history', document.getElementById('tab-history'))" style="cursor:pointer;" title="View Pending Requests">
      <div class="stat-card-glow"></div>
      <div class="stat-card-header">
        <div class="stat-card-icon amber">
          <i class="fas fa-hourglass-start"></i>
        </div>
        <span class="stat-micro-pill amber <?= $pendingCount > 0 ? 'pulse' : '' ?>">
          <?= $pendingCount > 0 ? 'In Review' : 'Clear' ?>
        </span>
      </div>
      <div class="stat-card-content">
        <div class="stat-card-number"><?= $pendingCount ?></div>
        <div class="stat-card-label">Pending Review</div>
      </div>
      <div class="stat-card-bar amber"></div>
    </div>

    <!-- Card 5: Optical Prescriptions -->
    <div class="modern-stat-card theme-indigo <?= $totalRxCount > 0 ? 'has-rx' : '' ?>" onclick="switchTab('prescriptions', document.getElementById('tab-prescriptions'))" style="cursor:pointer;" title="View Optical Prescriptions">
      <div class="stat-card-glow"></div>
      <div class="stat-card-header">
        <div class="stat-card-icon indigo">
          <i class="fas fa-glasses"></i>
        </div>
        <span class="stat-micro-pill indigo">
          <?= $totalRxCount > 0 ? 'Active Rx' : 'Records' ?>
        </span>
      </div>
      <div class="stat-card-content">
        <div class="stat-card-number"><?= $totalRxCount ?></div>
        <div class="stat-card-label">Eye Prescriptions</div>
      </div>
      <div class="stat-card-bar indigo"></div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="switchTab('book',this)" id="tab-book">
      <i class="fas fa-calendar-plus"></i> Book Appointment
    </button>
    <button class="tab-btn" onclick="switchTab('history',this)" id="tab-history">
      <i class="fas fa-history"></i> My Appointments
      <?php if ($upcomingCount > 0): ?>
      <span style="background:var(--clr-primary-light);color:#fff;border-radius:100px;padding:2px 8px;font-size:.68rem;margin-left:4px;font-weight:800;"><?= $upcomingCount ?></span>
      <?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('prescriptions',this)" id="tab-prescriptions">
      <i class="fas fa-glasses"></i> My Prescriptions
      <?php if ($totalRxCount > 0): ?>
      <span style="background:linear-gradient(135deg,#7C3AED,#6D28D9);color:#fff;border-radius:100px;padding:2px 8px;font-size:.68rem;margin-left:4px;font-weight:800;"><?= $totalRxCount ?></span>
      <?php endif; ?>
    </button>
  </div>

  <!-- TAB: BOOK -->
  <div class="tab-panel active" id="panel-book">
    <div class="book-grid-layout" style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">

      <!-- Booking Form -->
      <div class="form-card">
        <div class="form-card-header">
          <div class="form-card-icon"><i class="fas fa-calendar-plus"></i></div>
          <div>
            <div class="form-card-title">Book an Appointment</div>
            <div class="form-card-sub">Select your visit purpose, specific optical service, and preferred schedule</div>
          </div>
        </div>
        <div class="form-card-body">

          <!-- Connected Timeline Stepper -->
          <div class="stepper-wrap" id="stepperWrap">
            <div class="stepper-track-bg"></div>
            <div class="stepper-track-fill" id="stepperTrackFill"></div>
            <div class="steps-container">
              <button type="button" class="step-node active" id="step1" onclick="jumpToStep(1)">
                <div class="step-circle"><span class="step-num">1</span></div>
                <span class="step-text">Purpose</span>
              </button>
              <button type="button" class="step-node" id="step2" onclick="jumpToStep(2)">
                <div class="step-circle"><span class="step-num">2</span></div>
                <span class="step-text">Services</span>
              </button>
              <button type="button" class="step-node" id="step3" onclick="jumpToStep(3)">
                <div class="step-circle"><span class="step-num">3</span></div>
                <span class="step-text">Schedule</span>
              </button>
              <button type="button" class="step-node" id="step4" onclick="jumpToStep(4)">
                <div class="step-circle"><span class="step-num">4</span></div>
                <span class="step-text">Confirm</span>
              </button>
            </div>
          </div>

          <form method="POST" id="bookingForm">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="purpose" id="purposeInput" required>
            <input type="hidden" name="service" id="serviceInput" required>
            <input type="hidden" name="appointment_time" id="selectedTime" required>

            <!-- ============================================================
                 STEP 1: PURPOSE OF APPOINTMENT
                 ============================================================ -->
            <div class="wizard-step active" id="wizard-step-1">
              <div class="wizard-header">
                <div class="wizard-header-title"><i class="fas fa-bullseye" style="color:var(--clr-primary)"></i> 1. Choose Purpose of Appointment</div>
                <div class="wizard-header-sub">Select the primary reason for visiting Gueco Optical Clinic</div>
              </div>

              <div class="purpose-grid" id="purposeGrid">
                <?php foreach ($bookingCategories as $bCat): ?>
                <div class="purpose-card" data-val="<?= htmlspecialchars($bCat['category_key']) ?>" onclick="selectPurpose(this)">
                  <div class="purpose-card-icon"><i class="fas <?= htmlspecialchars($bCat['icon']) ?>"></i></div>
                  <div class="purpose-card-title"><?= htmlspecialchars($bCat['name']) ?></div>
                  <div class="purpose-card-desc"><?= htmlspecialchars($bCat['description']) ?></div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="wizard-nav-bar">
                <button type="button" class="btn-wizard-next" id="btnStep1Next" disabled onclick="goToStep(2)">
                  Continue to Services <i class="fas fa-arrow-right"></i>
                </button>
              </div>
            </div>

            <!-- ============================================================
                 STEP 2: SERVICES OFFERED
                 ============================================================ -->
            <div class="wizard-step" id="wizard-step-2">
              <div class="wizard-header">
                <div class="wizard-header-title"><i class="fas fa-hand-holding-medical" style="color:var(--clr-primary)"></i> 2. Services Offered</div>
                <div class="wizard-header-sub" id="serviceHeaderSub">Select the specific optical care service you need</div>
              </div>

              <!-- Filter tabs for services -->
              <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
                <button type="button" class="qdate-btn active" id="filterAllServices" data-filter="all" onclick="filterServices('all', this)">All Services</button>
                <?php foreach ($bookingCategories as $bCat): ?>
                <button type="button" class="qdate-btn" data-filter="<?= htmlspecialchars($bCat['category_key']) ?>" onclick="filterServices('<?= htmlspecialchars($bCat['category_key']) ?>', this)"><?= htmlspecialchars($bCat['name']) ?></button>
                <?php endforeach; ?>
              </div>

              <div class="service-grid" id="serviceGrid">
                <?php
                $services = [];
                try {
                    $svcRows = $db->query("SELECT name, purpose_category, badge, description, duration FROM clinic_services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_NUM);
                    if (!empty($svcRows)) {
                        $services = $svcRows;
                    }
                } catch (Throwable $e) {}

                if (empty($services)) {
                    $services = [
                        ['Comprehensive Eye Examination','consultation','Examination','Full eye health check, visual acuity test, and digital refraction test.','30–45 mins'],
                        ['Prescription & Visual Acuity Test','consultation','Examination','Precise sphere, cylinder & axis measurement for reading or distance glasses.','20–30 mins'],
                        ['Pediatric & Student Vision Screening','consultation','Specialized','Gentle eye exam designed for children, students, and early myopia detection.','25–35 mins'],
                        ['Senior Vision & Cataract Screening','consultation','Specialized','Assessment for presbyopia, cataracts, and age-related visual changes.','30–45 mins'],
                        ['Eyeglass Frame Selection & Styling','eyeglass_claim','Eyewear','Bridge sizing, facial ergonomics, and personalized frame styling assistance.','20–30 mins'],
                        ['Lens Upgrade (Blue Light / Transitions)','eyeglass_claim','Lenses','Anti-radiation computer lenses, photochromic transitions, or progressive lenses.','15–20 mins'],
                        ['Eyeglass Pick-up & Final Alignment','eyeglass_claim','Eyewear','Claim completed prescription glasses with custom temple & nosepad fitting.','15 mins'],
                        ['Frame Repair & Ultrasonic Cleaning','other','Care','Nosepad replacement, frame realignment, screw tightening, and deep ultrasonic bath.','15–20 mins'],
                        ['Contact Lens Fitting & Insertion Training','contact_lens_fitting','Contacts','Corneal measurement, comfort trial fitting, and contact lens handling training.','30–40 mins'],
                        ['Contact Lens Replenishment / Pick-up','contact_lens_fitting','Contacts','Claim monthly, bi-weekly, or daily disposable contact lens supplies.','10–15 mins'],
                        ['Post-Consultation Prescription Check','follow_up','Follow-up','Re-evaluating vision adaptation and visual comfort with newly acquired glasses.','15–20 mins'],
                        ['General Optical Inquiries & Consultation','other','General','Discuss specific vision concerns, eye symptoms, referrals, or clinic services.','15–20 mins'],
                    ];
                }
                foreach ($services as [$name,$pCat,$badge,$desc,$meta]):
                ?>
                <div class="service-card" data-purpose="<?= htmlspecialchars($pCat) ?>" data-name="<?= htmlspecialchars($name) ?>" onclick="selectService(this)">
                  <div class="service-top">
                    <span class="service-badge"><?= htmlspecialchars($badge) ?></span>
                    <div class="service-check"><i class="fas fa-check"></i></div>
                  </div>
                  <div>
                    <div class="service-title"><?= htmlspecialchars($name) ?></div>
                    <div class="service-desc"><?= htmlspecialchars($desc) ?></div>
                  </div>
                  <div class="service-meta"><i class="fas fa-clock"></i> <?= htmlspecialchars($meta) ?></div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="wizard-nav-bar">
                <button type="button" class="btn-wizard-back" onclick="goToStep(1)">
                  <i class="fas fa-arrow-left"></i> Back to Purpose
                </button>
                <button type="button" class="btn-wizard-next" id="btnStep2Next" disabled onclick="goToStep(3)">
                  Continue to Schedule <i class="fas fa-arrow-right"></i>
                </button>
              </div>
            </div>

            <!-- ============================================================
                 STEP 3: TIME & SCHEDULE
                 ============================================================ -->
            <div class="wizard-step" id="wizard-step-3">
              <div class="wizard-header">
                <div class="wizard-header-title"><i class="fas fa-calendar-check" style="color:var(--clr-primary)"></i> 3. Choose Date &amp; Time</div>
                <div class="wizard-header-sub">Select your preferred weekday schedule and add any specific notes</div>
              </div>

              <!-- Date Picker -->
              <div class="field-group">
                <label class="field-label"><i class="fas fa-calendar me-1"></i>Appointment Date <span style="color:var(--clr-danger)">*</span></label>
                <!-- Quick picks -->
                <div class="quick-dates" id="quickDates">
                  <?php
                  for ($d = 1; $d <= 10; $d++) {
                      $ts = strtotime("+$d day");
                      $dow = date('N', $ts);
                      if ($dow >= 6) continue; // skip saturday & sunday
                      $qDateStr = date('Y-m-d', $ts);
                      $isFullDay = in_array($qDateStr, $fullyBookedDates);
                      if ($isFullDay) {
                          echo '<button type="button" class="qdate-btn is-full" data-date="'.$qDateStr.'" disabled title="All time slots are fully booked for this day">'.date('D, M j',$ts).' <span style="font-size:.65rem;color:#EF4444;font-weight:800;display:block;">Full</span></button>';
                      } else {
                          echo '<button type="button" class="qdate-btn" data-date="'.$qDateStr.'">'.date('D, M j',$ts).'</button>';
                      }
                      if (count(array_filter(range(1,$d), fn($i) => date('N',strtotime("+$i day")) < 6)) >= 5) break;
                  }
                  ?>
                </div>
                <div class="date-input-wrap">
                  <input type="text" id="apptDate" name="appointment_date"
                         class="field-control"
                         min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                         placeholder="Select appointment date..."
                         autocomplete="off"
                         required>
                  <i class="fas fa-calendar-alt date-picker-icon"></i>
                </div>
                <div id="weekendWarning" style="display:none;"></div>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:7px;display:flex;align-items:center;gap:6px;">
                  <i class="fas fa-info-circle" style="color:var(--clr-primary)"></i>Clinic is open Monday to Friday, 9:00 AM – 5:00 PM (Closed on Weekends)
                </div>
              </div>

              <!-- Time Slot Grid -->
              <div class="field-group">
                <label class="field-label"><i class="fas fa-clock me-1"></i>Time Slot <span style="color:var(--clr-danger)">*</span></label>
                <div id="slotGrid">
                  <div class="slot-placeholder">
                    <i class="fas fa-calendar-day"></i>
                    Select an appointment date above to view available time slots
                  </div>
                </div>
              </div>

              <!-- Additional Notes -->
              <div class="field-group">
                <label class="field-label"><i class="fas fa-sticky-note me-1"></i>Additional Notes <span style="color:var(--text-muted);font-weight:400;text-transform:none;font-size:.75rem;">(optional)</span></label>
                <textarea name="notes" id="apptNotes" class="field-control" rows="3" placeholder="Any specific symptoms, previous eye conditions, or requests..."></textarea>
              </div>

              <div class="wizard-nav-bar">
                <button type="button" class="btn-wizard-back" onclick="goToStep(2)">
                  <i class="fas fa-arrow-left"></i> Back to Services
                </button>
                <button type="button" class="btn-wizard-next" id="btnStep3Next" disabled onclick="goToStep(4)">
                  Review Booking <i class="fas fa-arrow-right"></i>
                </button>
              </div>
            </div>

            <!-- ============================================================
                 STEP 4: REVIEW & CONFIRM
                 ============================================================ -->
            <div class="wizard-step" id="wizard-step-4">
              <div class="wizard-header">
                <div class="wizard-header-title"><i class="fas fa-clipboard-check" style="color:var(--clr-primary)"></i> 4. Review &amp; Confirm Booking</div>
                <div class="wizard-header-sub">Please verify your appointment information before final submission</div>
              </div>

              <div class="review-summary-card">
                <div class="review-row">
                  <div class="review-row-icon"><i class="fas fa-bullseye"></i></div>
                  <div class="review-row-content">
                    <div class="review-row-label">Purpose of Visit</div>
                    <div class="review-row-val" id="rev-purpose">—</div>
                  </div>
                </div>

                <div class="review-row">
                  <div class="review-row-icon"><i class="fas fa-hand-holding-medical"></i></div>
                  <div class="review-row-content">
                    <div class="review-row-label">Selected Optical Service</div>
                    <div class="review-row-val" id="rev-service">—</div>
                  </div>
                </div>

                <div class="review-row">
                  <div class="review-row-icon"><i class="fas fa-calendar"></i></div>
                  <div class="review-row-content">
                    <div class="review-row-label">Appointment Date</div>
                    <div class="review-row-val" id="rev-date">—</div>
                  </div>
                </div>

                <div class="review-row">
                  <div class="review-row-icon"><i class="fas fa-clock"></i></div>
                  <div class="review-row-content">
                    <div class="review-row-label">Scheduled Time Slot</div>
                    <div class="review-row-val" id="rev-time">—</div>
                  </div>
                </div>

                <div class="review-row">
                  <div class="review-row-icon"><i class="fas fa-sticky-note"></i></div>
                  <div class="review-row-content">
                    <div class="review-row-label">Additional Notes</div>
                    <div class="review-row-val" id="rev-notes" style="font-weight:500;color:var(--text-secondary);font-size:.88rem;">None</div>
                  </div>
                </div>
              </div>

              <div class="info-box">
                <i class="fas fa-shield-alt"></i>
                <div>Your booking will be <strong>reviewed and confirmed</strong> by clinic staff. You can track its status under <em>My Appointments</em>. Please arrive <strong>10 minutes early</strong> on your scheduled date.</div>
              </div>

              <div class="wizard-nav-bar">
                <button type="button" class="btn-wizard-back" onclick="goToStep(3)">
                  <i class="fas fa-arrow-left"></i> Back to Schedule
                </button>
                <button type="submit" class="btn-book" id="finalBookBtn" style="width:auto;padding:14px 32px;margin-left:auto;">
                  <i class="fas fa-calendar-check me-1"></i> Confirm &amp; Book Appointment
                </button>
              </div>
            </div>

          </form>
        </div>
      </div>

      <!-- Sidebar Info -->
      <div>
        <!-- Clinic Info -->
        <div class="clinic-sidebar-card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div class="clinic-avatar-box"><i class="fas fa-clinic-medical"></i></div>
              <div>
                <div class="clinic-name-title">Gueco Optical</div>
                <div class="clinic-name-sub">Capas, Tarlac</div>
              </div>
            </div>
            <div class="clinic-status-chip <?= $clinicStatusClass ?>">
              <span class="status-dot <?= $isOpenNow ? 'dot-green pulse' : 'dot-red' ?>"></span>
              <?= $clinicStatusText ?>
            </div>
          </div>
          <div class="clinic-row">
            <div class="clinic-icon-box clock"><i class="fas fa-clock"></i></div>
            <div>
              <div class="clinic-row-lbl">Clinic Hours</div>
              <div class="clinic-row-val">Mon–Fri: 9:00 AM – 5:00 PM</div>
            </div>
          </div>
          <div class="clinic-row">
            <div class="clinic-icon-box rest"><i class="fas fa-calendar-times"></i></div>
            <div>
              <div class="clinic-row-lbl">Rest Days</div>
              <div class="clinic-row-val">Closed Saturday &amp; Sunday</div>
            </div>
          </div>
          <div class="clinic-row">
            <div class="clinic-icon-box pin"><i class="fas fa-map-marker-alt"></i></div>
            <div>
              <div class="clinic-row-lbl">Location</div>
              <div class="clinic-row-val">Capas, Tarlac, Philippines</div>
            </div>
          </div>
          <div class="clinic-row" style="border-bottom:none;">
            <div class="clinic-icon-box phone"><i class="fas fa-phone"></i></div>
            <div>
              <div class="clinic-row-lbl">Front Desk Inquiries</div>
              <div class="clinic-row-val">Available during open hours</div>
            </div>
          </div>
        </div>

        <!-- Reminders -->
        <div class="reminders-card">
          <div class="reminders-title">
            <i class="fas fa-lightbulb"></i> Important Reminders
          </div>
          <?php 
          $tips = [
            'Arrive 10 minutes before your scheduled time',
            'Bring any previous prescriptions or eyeglass frames',
            'Appointments are subject to staff confirmation',
            'Walk-ins are accommodated on weekdays depending on queue'
          ]; 
          foreach ($tips as $tip): 
          ?>
          <div class="reminder-item">
            <i class="fas fa-check"></i>
            <span><?= $tip ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB: HISTORY -->
  <div class="tab-panel" id="panel-history">
    <div style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;">
      <h6 style="font-weight:800;font-size:1.1rem;color:var(--text-primary);margin:0">
        <i class="fas fa-history me-2" style="color:var(--clr-primary)"></i>All Appointments (<?= count($myAppts) ?>)
      </h6>
    </div>

    <?php if (empty($myAppts)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-plus"></i>
      <p>No appointments found.<br>Book your first appointment using the <strong>Book Appointment</strong> tab.</p>
    </div>
    <?php else: ?>

    <!-- Upcoming -->
    <?php if (!empty($upcoming)): ?>
    <div style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--clr-primary);margin-bottom:12px;padding:0 4px;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-clock"></i> Upcoming Appointments
    </div>
    <?php foreach ($upcoming as $a):
      $d = new DateTime($a['appointment_date']);
      $isPending = $a['status'] === 'pending';
      $serviceInNotes = '';
      $userNotes = $a['notes'] ?? '';
      if (!empty($a['notes']) && preg_match('/^Service:\s*(.+)$/m', $a['notes'], $sm)) {
          $serviceInNotes = trim($sm[1]);
          $userNotes = trim(preg_replace('/^Service:\s*.+$/m', '', $a['notes']));
      }
    ?>
    <div class="appt-item upcoming" id="appt-card-<?= $a['id'] ?>">
      <div class="appt-date-box">
        <div class="appt-day"><?= $d->format('d') ?></div>
        <div class="appt-month"><?= $d->format('M') ?></div>
      </div>
      <div class="appt-divider"></div>
      <div class="appt-info">
        <div class="appt-time"><i class="fas fa-clock me-1" style="font-size:.75rem;opacity:.8;color:var(--clr-primary-light)"></i><?= formatTime($a['appointment_time']) ?> &nbsp;·&nbsp; <?= $d->format('l') ?></div>
        <div class="appt-purpose"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></div>
        <?php if ($serviceInNotes): ?>
        <div style="display:inline-flex;align-items:center;gap:6px;background:var(--bg-hover);border:1px solid var(--border-color);padding:3px 10px;border-radius:7px;font-size:.74rem;font-weight:700;color:var(--text-secondary);margin-top:5px;">
          <i class="fas fa-hand-holding-medical" style="color:var(--clr-primary-light);font-size:.72rem;"></i> <?= htmlspecialchars(htmlspecialchars_decode((string)$serviceInNotes, ENT_QUOTES), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        <?php if ($userNotes): ?><div class="appt-notes"><i class="fas fa-sticky-note me-1"></i><?= sanitize($userNotes) ?></div><?php endif; ?>
        <?php if (!$isPending): ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:.72rem;color:var(--clr-success);font-weight:700;">
          <i class="fas fa-shield-check"></i> Confirmed &amp; locked by clinic staff
        </div>
        <?php endif; ?>
      </div>
      <div class="appt-actions-wrap">
        <span class="status-badge status-<?= $a['status'] ?>">
          <?php if ($a['status'] === 'pending'): ?>
            <i class="fas fa-hourglass-half status-badge-icon me-1"></i>
          <?php elseif ($a['status'] === 'confirmed'): ?>
            <span class="status-dot dot-green pulse"></span>
          <?php elseif ($a['status'] === 'completed'): ?>
            <span class="status-dot" style="background:#00ADEF;"></span>
          <?php else: ?>
            <span class="status-dot" style="background:#94A3B8;"></span>
          <?php endif; ?>
          <?= str_replace('_',' ',ucfirst($a['status'])) ?>
        </span>
        <?php if (!empty($a['rx_id'])): ?>
        <button type="button" class="btn btn-sm" onclick="openRxModal(<?= (int)$a['rx_id'] ?>)" style="display:inline-flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:700;padding:6px 14px;border-radius:100px;border:1.5px solid rgba(124,58,237,0.35);color:#7C3AED;background:rgba(124,58,237,0.08);cursor:pointer;transition:all 0.2s ease;" title="View official prescription slip copy">
          <i class="fas fa-file-prescription"></i> <span>View Prescription Copy</span>
        </button>
        <?php endif; ?>
        <div style="display:flex;gap:6px;">
          <?php if ($isPending): ?>
          <!-- Edit: only available while pending -->
          <button type="button" class="edit-btn js-edit-btn"
            data-id="<?= $a['id'] ?>"
            data-date="<?= $a['appointment_date'] ?>"
            data-time="<?= substr($a['appointment_time'],0,5) ?>"
            data-purpose="<?= $a['purpose'] ?>"
            data-notes="<?= htmlspecialchars((string)($a['notes'] ?? ''), ENT_QUOTES) ?>">
            <i class="fas fa-pen me-1"></i>Edit
          </button>
          <?php else: ?>
          <!-- Edit locked once confirmed -->
          <button type="button" class="edit-btn" disabled title="Cannot edit — appointment has been confirmed by staff">
            <i class="fas fa-lock me-1"></i>Locked
          </button>
          <?php endif; ?>
          <?php if (in_array($a['status'],['pending','confirmed'])): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
            <button type="button" class="cancel-btn" onclick="confirmCancelAppt(this.closest('form'))">
              <i class="fas fa-times me-1"></i>Cancel
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Past & Completed Appointments -->
    <?php if (!empty($past)): ?>
    <div style="font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin:26px 0 12px;padding:0 4px;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-history"></i> Past &amp; Completed Appointments
    </div>
    <?php foreach ($past as $a):
      $d = new DateTime($a['appointment_date']);
      $pastService = '';
      if (!empty($a['notes']) && preg_match('/^Service:\s*(.+)$/m', $a['notes'], $psm)) {
          $pastService = trim($psm[1]);
      }
      $targetRxId = !empty($a['rx_id']) ? (int)$a['rx_id'] : (($a['status'] === 'completed' && !empty($latestRx)) ? (int)$latestRx['id'] : 0);
    ?>
    <div class="appt-item <?= in_array($a['status'],['cancelled','no_show'])?'cancelled':'' ?>" id="appt-card-<?= $a['id'] ?>">
      <div class="appt-date-box">
        <div class="appt-day" style="color:var(--text-muted)"><?= $d->format('d') ?></div>
        <div class="appt-month"><?= $d->format('M') ?></div>
      </div>
      <div class="appt-divider"></div>
      <div class="appt-info">
        <div class="appt-time"><?= formatTime($a['appointment_time']) ?> &nbsp;·&nbsp; <?= $d->format('D, M j Y') ?></div>
        <div class="appt-purpose"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></div>
        <?php if ($pastService): ?>
        <div style="font-size:.74rem;color:var(--text-subtle);margin-top:3px;"><i class="fas fa-tag me-1"></i><?= htmlspecialchars(htmlspecialchars_decode((string)$pastService, ENT_QUOTES), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
      <div class="appt-actions-wrap" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="status-badge status-<?= $a['status'] ?>">
          <?php if ($a['status'] === 'pending'): ?>
            <i class="fas fa-hourglass-half status-badge-icon me-1"></i>
          <?php elseif ($a['status'] === 'confirmed'): ?>
            <span class="status-dot dot-green pulse"></span>
          <?php elseif ($a['status'] === 'completed'): ?>
            <span class="status-dot" style="background:#00ADEF;"></span>
          <?php else: ?>
            <span class="status-dot" style="background:#94A3B8;"></span>
          <?php endif; ?>
          <?= str_replace('_',' ',ucfirst($a['status'])) ?>
        </span>

        <?php if ($targetRxId > 0): ?>
        <button type="button" class="btn btn-sm" onclick="openRxModal(<?= $targetRxId ?>)" style="display:inline-flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:700;padding:6px 14px;border-radius:100px;border:1.5px solid rgba(124,58,237,0.35);color:#7C3AED;background:rgba(124,58,237,0.08);cursor:pointer;transition:all 0.2s ease;" title="View official prescription slip copy">
          <i class="fas fa-file-prescription"></i> <span>View Prescription Copy</span>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <!-- TAB: PRESCRIPTIONS -->
  <div class="tab-panel" id="panel-prescriptions">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
      <div>
        <h4 style="font-size:1.25rem;font-weight:900;color:var(--text-primary);margin:0 0 4px;letter-spacing:-0.02em;">
          <i class="fas fa-glasses me-2" style="color:#7C3AED;"></i>My Optical Prescriptions
        </h4>
        <p style="font-size:.84rem;color:var(--text-muted);margin:0;font-weight:600;">
          Your verified eye refraction measurements, pupillary distance, and optical records issued by Gueco Optical Clinic.
        </p>
      </div>
      <?php if (!empty($patientRxList)): ?>
      <span class="badge" style="background:rgba(124,58,237,0.12);color:#7C3AED;border:1px solid rgba(124,58,237,0.25);font-size:.82rem;font-weight:800;padding:6px 14px;border-radius:100px;">
        <i class="fas fa-file-medical me-1"></i> <?= $totalRxCount ?> <?= $totalRxCount === 1 ? 'Record' : 'Records' ?> On File
      </span>
      <?php endif; ?>
    </div>

    <?php if (empty($patientRxList)): ?>
    <div class="empty-state" style="padding:60px 20px;text-align:center;">
      <div style="width:84px;height:84px;border-radius:50%;background:rgba(124,58,237,0.12);color:#7C3AED;font-size:2.2rem;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;border:2px dashed rgba(124,58,237,0.3);">
        <i class="fas fa-glasses"></i>
      </div>
      <h5 style="font-size:1.15rem;font-weight:800;color:var(--text-primary);margin-bottom:8px;">No Prescriptions on File Yet</h5>
      <p style="max-width:440px;margin:0 auto 20px;color:var(--text-muted);font-size:.88rem;line-height:1.6;">
        Once your licensed optometrist completes your eye examination at Gueco Optical Clinic, your verified optical prescription copy will appear here 24/7.
      </p>
      <button type="button" class="btn btn-primary btn-book-cta" onclick="switchTab('book', document.getElementById('tab-book'))" style="border-radius:100px;padding:12px 28px;font-weight:800;font-size:.92rem;color:#ffffff !important;display:inline-flex;align-items:center;justify-content:center;gap:9px;">
        <i class="fas fa-calendar-plus" style="font-size:1.05rem !important;color:#ffffff !important;display:inline-block !important;margin:0 !important;opacity:1 !important;line-height:1 !important;"></i>
        <span style="color:#ffffff !important;font-weight:800;">Book an Eye Consultation</span>
      </button>
    </div>
    <?php else: ?>
    <div class="rx-card-list">
      <?php foreach ($patientRxList as $idx => $rx): 
        $dRx = new DateTime($rx['created_at']);
        $doc = !empty($rx['doctor_name']) ? ('Dr. ' . sanitize($rx['doctor_name'])) : 'Attending Optometrist';
        $isLatest = ($idx === 0);
        $odAdd = $rx['od_add'] ?? $rx['add_power'] ?? '';
        $osAdd = $rx['os_add'] ?? $rx['add_power'] ?? '';
        $notes = $rx['notes'] ?? $rx['recommendations'] ?? '';
      ?>
      <div class="rx-record-card <?= $isLatest ? 'latest-rx' : '' ?>">
        <!-- Top row -->
        <div class="rx-card-topbar">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span class="badge" style="background:var(--bg-hover);color:var(--text-primary);border:1px solid var(--border-color);font-family:monospace;font-size:.82rem;font-weight:700;padding:5px 10px;border-radius:8px;">
              Rx #<?= $rx['id'] ?>
            </span>
            <?php if ($isLatest): ?>
            <span class="badge" style="background:rgba(16,185,129,0.15);color:#059669;border:1px solid rgba(16,185,129,0.3);font-size:.76rem;font-weight:800;padding:4px 10px;border-radius:100px;">
              <span class="status-dot dot-green pulse me-1"></span> Current / Active
            </span>
            <?php endif; ?>
            <div class="rx-topbar-meta">
              <span><i class="fas fa-calendar-alt me-1"></i> <?= $dRx->format('F j, Y · h:i A') ?></span>
              <span><i class="fas fa-user-doctor me-1"></i> <?= $doc ?></span>
            </div>
          </div>
          <div class="rx-topbar-actions">
            <button type="button" class="btn-rx-action primary" onclick="openRxModal(<?= (int)$rx['id'] ?>)">
              <i class="fas fa-file-prescription"></i> <span>View Official Slip</span>
            </button>
            <button type="button" class="btn-rx-action print" onclick="printRxDirect(<?= (int)$rx['id'] ?>)">
              <i class="fas fa-print"></i> <span>Print</span>
            </button>
            <button type="button" class="btn-rx-action print" onclick="copyRxValues(<?= (int)$rx['id'] ?>)" title="Copy prescription grades to clipboard">
              <i class="fas fa-copy"></i> <span>Copy</span>
            </button>
          </div>
        </div>

        <!-- Optical Specs Grid -->
        <div class="rx-specs-table-wrapper">
          <table class="rx-specs-table">
            <thead>
              <tr>
                <th class="th-eye">Eye</th>
                <th>SPH <span class="rx-th-hint">(Sphere)</span></th>
                <th>CYL <span class="rx-th-hint">(Cylinder)</span></th>
                <th>AXIS <span class="rx-th-hint">(Orientation)</span></th>
                <th>ADD <span class="rx-th-hint">(Near Reading)</span></th>
                <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
                <th>VA <span class="rx-th-hint">(Acuity)</span></th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="td-eye eye-od"><span class="eye-badge od">OD</span> Right Eye</td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['od_sphere'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['od_cylinder'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="td-val font-mono"><?= !empty($rx['od_axis']) ? htmlspecialchars((string)$rx['od_axis'], ENT_QUOTES) . '°' : '—' ?></td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($odAdd ?: '—'), ENT_QUOTES) ?></td>
                <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['od_va'] ?? '—'), ENT_QUOTES) ?></td>
                <?php endif; ?>
              </tr>
              <tr>
                <td class="td-eye eye-os"><span class="eye-badge os">OS</span> Left Eye</td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['os_sphere'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['os_cylinder'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="td-val font-mono"><?= !empty($rx['os_axis']) ? htmlspecialchars((string)$rx['os_axis'], ENT_QUOTES) . '°' : '—' ?></td>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($osAdd ?: '—'), ENT_QUOTES) ?></td>
                <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
                <td class="td-val font-mono"><?= htmlspecialchars((string)($rx['os_va'] ?? '—'), ENT_QUOTES) ?></td>
                <?php endif; ?>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Details Pills & Notes -->
        <div class="rx-card-footer">
          <div class="rx-footer-pills">
            <?php if (!empty($rx['pd'])): ?>
            <div class="rx-info-pill">
              <i class="fas fa-arrows-left-right"></i>
              <span><strong>PD (Pupillary Distance):</strong> <?= htmlspecialchars((string)$rx['pd'], ENT_QUOTES) ?> mm</span>
            </div>
            <?php endif; ?>
            <?php if (!empty($rx['lens_type'])): ?>
            <div class="rx-info-pill">
              <i class="fas fa-layer-group"></i>
              <span><strong>Lens Type:</strong> <?= htmlspecialchars((string)$rx['lens_type'], ENT_QUOTES) ?></span>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($notes)): ?>
          <div class="rx-notes-snippet" style="max-width:100%;margin-top:6px;">
            <i class="fas fa-notes-medical me-1"></i>
            <span><strong>Clinical Instructions / Notes:</strong> <?= htmlspecialchars((string)$notes, ENT_QUOTES) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /page-wrap -->

<!-- ============================================================
     BOOKING CONFIRMATION MODAL
     ============================================================ -->
<div id="confirmModal" class="modal-overlay">
  <div class="modal-backdrop-custom" onclick="closeConfirmModal()"></div>
  <div class="modal-dialog-box" style="max-width:480px;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));padding:22px 24px;display:flex;align-items:center;gap:14px;">
      <div style="width:46px;height:46px;background:rgba(255,255,255,.2);border-radius:13px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.15rem;flex-shrink:0;"><i class="fas fa-calendar-check"></i></div>
      <div>
        <div style="font-weight:800;font-size:1.1rem;color:#fff;letter-spacing:-0.01em;">Review Your Booking</div>
        <div style="font-size:.76rem;color:rgba(255,255,255,.85);">Please confirm all details before submitting</div>
      </div>
      <button onclick="closeConfirmModal()" style="margin-left:auto;background:rgba(255,255,255,.18);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:.85rem;"><i class="fas fa-times"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:24px;">
      <!-- Detail rows -->
      <?php
      $confirmRows = [
        ['fas fa-calendar','Date',        'confirm-date'],
        ['fas fa-clock',   'Time',        'confirm-time'],
        ['fas fa-tag',     'Purpose',     'confirm-purpose'],
        ['fas fa-sticky-note','Notes',    'confirm-notes'],
      ];
      foreach ($confirmRows as [$icon,$label,$id]):
      ?>
      <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--border-light);">
        <div style="width:34px;height:34px;border-radius:10px;background:rgba(0,173,239,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--clr-primary);font-size:.85rem;"><i class="<?= $icon ?>"></i></div>
        <div style="flex:1;">
          <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;font-weight:800;color:var(--text-muted);margin-bottom:2px;"><?= $label ?></div>
          <div id="<?= $id ?>" style="font-size:.9rem;font-weight:700;color:var(--text-primary);">—</div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Warning -->
      <div style="background:rgba(38,143,200,0.12);border:1px solid rgba(38,143,200,0.3);border-radius:12px;padding:12px 14px;margin-top:18px;margin-bottom:20px;font-size:.78rem;color:var(--clr-warning);display:flex;gap:10px;align-items:flex-start;">
        <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;"></i>
        <span>Your appointment will be <strong>pending confirmation</strong> by our staff. You can edit or cancel it anytime while pending.</span>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;">
        <button onclick="closeConfirmModal()" style="flex:1;padding:13px;border-radius:12px;border:1.5px solid var(--border-color);background:none;font-family:inherit;font-size:.88rem;font-weight:700;color:var(--text-muted);cursor:pointer;transition:all .2s;">
          <i class="fas fa-arrow-left me-1"></i> Go Back
        </button>
        <button onclick="submitBooking()" style="flex:2;padding:13px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));color:#fff;font-family:inherit;font-size:.9rem;font-weight:800;cursor:pointer;box-shadow:0 4px 18px rgba(35,94,174,0.35);transition:all .2s;" id="finalSubmitBtn">
          <i class="fas fa-calendar-check me-1"></i> Yes, Book Now
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     EDIT APPOINTMENT MODAL (MODERN REDESIGN)
     ============================================================ -->
<div id="editModal" class="modal-overlay">
  <div class="modal-backdrop-custom" onclick="closeEditModal()"></div>
  <div class="modal-dialog-box edit-modal-dialog">
    <!-- Header (Fixed Top) -->
    <div class="edit-modal-header">
      <div class="edit-modal-header-icon"><i class="fas fa-pen-to-square"></i></div>
      <div>
        <div class="edit-modal-title">Edit Appointment</div>
        <div class="edit-modal-sub">Reschedule date, time, or purpose while status is <strong>Pending</strong></div>
      </div>
      <button type="button" class="btn-close-modal" onclick="closeEditModal()" aria-label="Close">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Form with scrollable body and fixed footer -->
    <form method="POST" id="editForm" class="edit-modal-form">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="appt_id" id="editApptId">
      <input type="hidden" name="appointment_time" id="editSelTime">

      <!-- Scrollable Body -->
      <div class="edit-modal-body">
        <!-- Notice Pill -->
        <div class="edit-modal-notice">
          <i class="fas fa-info-circle"></i>
          <div>You can only edit this appointment while it is <strong>Pending</strong>. Once confirmed by clinic staff, edits will be locked.</div>
        </div>

        <!-- 2-Column Grid: Date and Purpose -->
        <div class="edit-form-grid">
          <div class="field-group" style="margin-bottom:0;">
            <label class="field-label" for="editDate"><i class="fas fa-calendar-day me-1"></i> New Date</label>
            <div class="date-input-wrap">
              <input type="text" name="appointment_date" id="editDate" class="field-control"
                     min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                     placeholder="Select new date..."
                     autocomplete="off"
                     required>
              <i class="fas fa-calendar-alt date-picker-icon"></i>
            </div>
            <div class="field-hint"><i class="fas fa-info-circle me-1"></i> Weekdays only (Mon–Fri)</div>
          </div>

          <div class="field-group" style="margin-bottom:0;">
            <label class="field-label" for="editPurpose"><i class="fas fa-clipboard-list me-1"></i> Purpose of Visit</label>
            <select name="purpose" id="editPurpose" class="field-control" required>
              <option value="">Choose purpose...</option>
              <?php foreach ($bookingCategories as $bCat): ?>
              <option value="<?= htmlspecialchars($bCat['category_key']) ?>"><?= htmlspecialchars($bCat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Time Slot Section -->
        <div class="field-group" style="margin-top:20px;margin-bottom:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <label class="field-label" style="margin-bottom:0;"><i class="fas fa-clock me-1"></i> Select Time Slot</label>
            <span id="editSelectedSlotText" class="edit-selected-chip">No slot selected</span>
          </div>
          <div id="editSlotGrid" class="edit-slots-container">
            <div class="slot-placeholder">
              <i class="fas fa-calendar-day"></i>
              <span>Select a weekday to view available slots</span>
            </div>
          </div>
        </div>

        <!-- Additional Notes -->
        <div class="field-group" style="margin-top:20px;margin-bottom:0;">
          <label class="field-label" for="editNotes"><i class="fas fa-sticky-note me-1"></i> Additional Notes <span style="font-weight:500;text-transform:none;opacity:.7;">(optional)</span></label>
          <textarea name="notes" id="editNotes" class="field-control" rows="2" placeholder="Any specific symptoms, frame notes, or requests..."></textarea>
        </div>
      </div>

      <!-- Sticky Footer (Always Visible at Bottom) -->
      <div class="edit-modal-footer">
        <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">
          <i class="fas fa-times me-1"></i> Cancel
        </button>
        <button type="submit" id="editSubmitBtn" class="btn-modal-save" disabled>
          <i class="fas fa-save me-1"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     OFFICIAL CLINIC PRESCRIPTION SLIP MODAL (PRINT READY)
     ============================================================ -->
<div id="rxSlipModal" class="modal-overlay">
  <div class="modal-backdrop-custom" onclick="closeRxModal()"></div>
  <div class="modal-dialog-box rx-slip-dialog">
    <!-- Close X button in top right of modal -->
    <button type="button" class="btn-close-modal" onclick="closeRxModal()" aria-label="Close" style="position:absolute;top:16px;right:16px;z-index:10;background:rgba(15,23,42,0.08);border:none;border-radius:50%;width:34px;height:34px;cursor:pointer;color:#0F172A;display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-times"></i>
    </button>

    <!-- Prescription Sheet Pad -->
    <div class="rx-pad-slip" id="rxPrintPad">
      <div class="rx-watermark-symbol">℞</div>

      <!-- Header -->
      <div class="rx-slip-header">
        <div class="rx-slip-brand">
          <img src="<?= htmlspecialchars(getClinicLogoUrl('../')) ?>" alt="Logo" class="rx-slip-logo">
          <div>
            <div class="rx-slip-clinic-name">GUECO OPTICAL CLINIC</div>
            <div class="rx-slip-clinic-sub">Professional Eye Care &amp; Optical Services</div>
          </div>
        </div>
        <div class="rx-slip-contacts">
          <div>Capas, Tarlac &middot; Angeles City, Pampanga</div>
          <div>Tel: (045) 123-4567 &middot; Mobile: 0917-123-4567</div>
          <div style="color:#00ADEF;font-weight:700;">guecooptical@gmail.com</div>
        </div>
      </div>

      <!-- Patient & Exam Meta Ribbon -->
      <div class="rx-patient-ribbon">
        <div>
          <div class="rx-ribbon-label">Patient Name</div>
          <div class="rx-ribbon-val" id="modalRxPatientName"><?= htmlspecialchars((string)$patientFullName, ENT_QUOTES) ?></div>
        </div>
        <div>
          <div class="rx-ribbon-label">Age / Gender</div>
          <div class="rx-ribbon-val" id="modalRxPatientDemographics">
            <?= $patientAge ? ($patientAge . ' yrs') : '—' ?> / <?= !empty($patient['gender']) ? ucfirst($patient['gender']) : '—' ?>
          </div>
        </div>
        <div>
          <div class="rx-ribbon-label">Date of Exam</div>
          <div class="rx-ribbon-val" id="modalRxDate">—</div>
        </div>
      </div>

      <!-- Rx Symbol Banner -->
      <div class="rx-symbol-banner">
        <div class="rx-big-symbol">℞</div>
        <div class="rx-symbol-text">Optical Prescription &middot; Refraction Record <span id="modalRxIdBadge" style="margin-left:8px;font-family:monospace;color:#00ADEF;"></span></div>
      </div>

      <!-- Optical Refraction Table -->
      <table class="rx-pad-table">
        <thead>
          <tr>
            <th style="width:130px;text-align:left;padding-left:14px;">EYE</th>
            <th>SPH (Sphere)</th>
            <th>CYL (Cylinder)</th>
            <th>AXIS</th>
            <th>ADD (Near)</th>
            <th>VA (Acuity)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="td-eye-name" style="color:#00ADEF;"><span class="eye-badge od" style="width:24px;height:20px;font-size:.65rem;margin-right:6px;">OD</span> Right Eye</td>
            <td id="modalRxOdSph">—</td>
            <td id="modalRxOdCyl">—</td>
            <td id="modalRxOdAxis">—</td>
            <td id="modalRxOdAdd">—</td>
            <td id="modalRxOdVa">—</td>
          </tr>
          <tr>
            <td class="td-eye-name" style="color:#7C3AED;"><span class="eye-badge os" style="width:24px;height:20px;font-size:.65rem;margin-right:6px;">OS</span> Left Eye</td>
            <td id="modalRxOsSph">—</td>
            <td id="modalRxOsCyl">—</td>
            <td id="modalRxOsAxis">—</td>
            <td id="modalRxOsAdd">—</td>
            <td id="modalRxOsVa">—</td>
          </tr>
        </tbody>
      </table>

      <!-- PD & Instructions Grid -->
      <div class="rx-pad-details-grid">
        <div class="rx-pad-box">
          <div class="rx-pad-box-lbl">Pupillary Distance (PD)</div>
          <div class="rx-pad-box-val" id="modalRxPd">—</div>
        </div>
        <div class="rx-pad-box">
          <div class="rx-pad-box-lbl">Recommended Lens Type</div>
          <div class="rx-pad-box-val" id="modalRxLens">—</div>
        </div>
      </div>

      <!-- Clinical Remarks Box -->
      <div class="rx-pad-box" style="margin-bottom:16px;">
        <div class="rx-pad-box-lbl">Doctor's Clinical Notes / Remarks</div>
        <div class="rx-pad-box-val" id="modalRxNotes" style="font-style:italic;">No special remarks.</div>
      </div>

      <!-- Doctor Signature & Stamp Footer -->
      <div class="rx-doctor-signature-section">
        <div class="rx-digital-seal">
          <div class="rx-seal-badge"><i class="fas fa-certificate"></i></div>
          <div>
            <div style="font-weight:800;color:#0F172A;font-size:.76rem;">AUTHENTIC OPTICAL RECORD</div>
            <div style="color:#64748B;font-size:.68rem;">Gueco Optical Clinic Patient Portal Verified</div>
          </div>
        </div>
        <div class="rx-signature-block">
          <div class="rx-signature-line" id="modalRxDoctorName">Dr. Clinic Optometrist</div>
          <div class="rx-signature-sub">Licensed Optometrist</div>
          <div class="rx-signature-sub" style="font-size:.65rem;color:#94A3B8;">PRC Reg. Optometry Specialist</div>
        </div>
      </div>
    </div>

    <!-- Modal Footer Actions (Hidden on Print) -->
    <div class="rx-modal-toolbar">
      <button type="button" class="btn btn-secondary" onclick="closeRxModal()" style="border-radius:10px;padding:9px 18px;font-size:.85rem;font-weight:700;">
        <i class="fas fa-times me-1"></i> Close
      </button>
      <button type="button" class="btn btn-primary" onclick="window.print()" style="border-radius:10px;padding:9px 24px;font-size:.85rem;font-weight:800;background:linear-gradient(135deg,#00ADEF,#235EAE);border:none;box-shadow:0 4px 14px rgba(0,173,239,0.35);color:#ffffff !important;display:inline-flex;align-items:center;gap:7px;">
        <i class="fas fa-print" style="color:#ffffff !important;font-size:.9rem !important;opacity:1 !important;margin:0 !important;display:inline-block !important;"></i>
        <span style="color:#ffffff !important;font-weight:800;">Print Prescription Copy</span>
      </button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Theme Management
const html = document.documentElement;
const themeBtn  = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');

function applyPatientTheme(theme) {
  if (theme !== 'light' && theme !== 'dark') theme = 'dark';
  html.setAttribute('data-theme', theme);
  try {
    localStorage.setItem('gueco_theme', theme);
    localStorage.setItem('gueco-theme', theme);
    localStorage.setItem('guecoTheme', theme);
    localStorage.setItem('theme', theme);
    document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
    document.cookie = "theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
  } catch(e) {}
  if (themeIcon) {
    themeIcon.className = (theme === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  }
}

const savedTheme = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || '<?= $currentTheme ?>';
applyPatientTheme(savedTheme);

if (themeBtn) {
  themeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    const current = html.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    applyPatientTheme(next);
  });
}

// Tab switch
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const target = document.getElementById('panel-' + id);
  if (target) target.classList.add('active');
  if (btn) btn.classList.add('active');
}

// ── WIZARD STATE & NAVIGATION ────────────────────────────────
let currentStep = 1;

const purposeMap = {
  consultation:         'Eye Consultation & Check-up',
  eyeglass_claim:       'Eyeglasses & Frames',
  contact_lens_fitting: 'Contact Lens Care',
  follow_up:            'Follow-up Visit',
  other:                'General Optical Services'
};

function goToStep(step) {
  // Validate prerequisites before advancing
  if (step > currentStep) {
    if (currentStep === 1 && !document.getElementById('purposeInput').value) {
      return;
    }
    if (currentStep === 2 && !document.getElementById('serviceInput').value) {
      return;
    }
    if (currentStep === 3 && (!dateInput.value || !selTime.value)) {
      return;
    }
  }

  currentStep = step;

  // Toggle wizard step visibility
  document.querySelectorAll('.wizard-step').forEach((el, idx) => {
    el.classList.toggle('active', (idx + 1) === step);
  });

  // Populate Review summary on Step 4
  if (step === 4) {
    populateReviewSummary();
  }

  updateStepsBar();

  // Scroll smoothly to form card
  const formCard = document.querySelector('.form-card');
  if (formCard) {
    formCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

function jumpToStep(step) {
  if (step < currentStep) {
    goToStep(step);
    return;
  }
  const p = document.getElementById('purposeInput').value;
  const s = document.getElementById('serviceInput').value;
  const d = dateInput.value;
  const t = selTime.value;

  if (step === 2 && p) goToStep(2);
  else if (step === 3 && p && s) goToStep(3);
  else if (step === 4 && p && s && d && t) goToStep(4);
}

// Purpose cards
function selectPurpose(el) {
  document.querySelectorAll('.purpose-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  const val = el.dataset.val;
  document.getElementById('purposeInput').value = val;

  const btnNext = document.getElementById('btnStep1Next');
  if (btnNext) btnNext.disabled = false;

  // Pre-filter services for this category in Step 2
  filterServices(val);

  updateStepsBar();
}

// Service Filter Tabs
function filterServices(category, btnEl) {
  if (btnEl) {
    document.querySelectorAll('#wizard-step-2 .qdate-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
  } else {
    document.querySelectorAll('#wizard-step-2 .qdate-btn').forEach(b => {
      b.classList.toggle('active', (b.dataset.filter === category));
    });
  }

  const cards = document.querySelectorAll('#serviceGrid .service-card');
  cards.forEach(card => {
    if (category === 'all' || card.dataset.purpose === category) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}

// Service Selection
function selectService(el) {
  document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  const name = el.dataset.name;
  document.getElementById('serviceInput').value = name;

  const btnNext = document.getElementById('btnStep2Next');
  if (btnNext) btnNext.disabled = false;

  updateStepsBar();
}

// Modern 3D Flatpickr Integration
let apptPicker = null;
let editDatePicker = null;
const fullyBookedDates = <?= json_encode($fullyBookedDates) ?>;

function initFlatpickrPickers() {
  if (typeof flatpickr === 'undefined') return;

  const minTomorrow = new Date();
  minTomorrow.setDate(minTomorrow.getDate() + 1);

  const commonFpOpts = {
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "D, M j, Y",
    minDate: minTomorrow,
    disable: [
      function(date) {
        // Disable Weekends (Sat & Sun)
        if (date.getDay() === 0 || date.getDay() === 6) return true;
        // Disable fully booked dates
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return fullyBookedDates.includes(`${y}-${m}-${d}`);
      }
    ],
    onDayCreate: function(dObj, dStr, fp, dayElem) {
      const date = dayElem.dateObj;
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      const checkStr = `${y}-${m}-${d}`;
      if (fullyBookedDates.includes(checkStr)) {
        dayElem.title = "Fully Booked (All slots taken)";
        dayElem.classList.add("flatpickr-day-fully-booked");
      }
    },
    animate: true,
    monthSelectorType: "static"
  };

  const apptEl = document.getElementById('apptDate');
  if (apptEl) {
    apptPicker = flatpickr(apptEl, {
      ...commonFpOpts,
      onChange: function(selectedDates, dateStr) {
        quickBtns.forEach(b => {
          b.classList.toggle('active', b.dataset.date === dateStr);
        });
        loadSlots(dateStr);
        updateStepsBar();
      }
    });
  }

  const editEl = document.getElementById('editDate');
  if (editEl) {
    editDatePicker = flatpickr(editEl, {
      ...commonFpOpts,
      onChange: function(selectedDates, dateStr) {
        document.getElementById('editSelTime').value = '';
        updateEditSlotChip(null);
        checkEditReady();
        loadEditSlots(dateStr, null);
      }
    });
  }
}

// Quick date buttons
const quickBtns = document.querySelectorAll('#wizard-step-3 .qdate-btn');
const dateInput = document.getElementById('apptDate');

quickBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    quickBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (apptPicker) {
      apptPicker.setDate(btn.dataset.date, true);
    } else {
      dateInput.value = btn.dataset.date;
      dateInput.dispatchEvent(new Event('change'));
    }
  });
});

dateInput.addEventListener('change', () => {
  const val = dateInput.value;

  // Check if selected date is a weekend
  if (val) {
    const dt = new Date(val + 'T00:00:00');
    const dow = dt.getDay(); // 0=Sun, 6=Sat
    if (dow === 0 || dow === 6) {
      showWeekendToast();
      if (apptPicker) apptPicker.clear();
      else dateInput.value = '';
      slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-calendar-day"></i>Select a weekday to see available time slots</div>';
      selTime.value = '';
      const btnNext3 = document.getElementById('btnStep3Next');
      if (btnNext3) btnNext3.disabled = true;
      updateStepsBar();
      return;
    }
  }

  quickBtns.forEach(b => {
    b.classList.toggle('active', b.dataset.date === val);
  });
  loadSlots(val);
  updateStepsBar();
});

// Initialize Flatpickr instances
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFlatpickrPickers);
} else {
  initFlatpickrPickers();
}

// Load slots
const allSlots = <?= json_encode($allSlots) ?>;
const slotGrid = document.getElementById('slotGrid');
const selTime  = document.getElementById('selectedTime');

async function loadSlots(date) {
  if (!date) return;
  slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-spinner fa-spin" style="font-size:1.2rem;opacity:.5;"></i><span>Loading available slots...</span></div>';
  selTime.value = '';
  const btnNext3 = document.getElementById('btnStep3Next');
  if (btnNext3) btnNext3.disabled = true;

  try {
    const res = await fetch('dashboard.php?check_date=' + encodeURIComponent(date));
    const rawTaken = await res.json();
    // Normalize taken array to 'HH:MM' (e.g. '09:00:00' -> '09:00')
    const taken = (Array.isArray(rawTaken) ? rawTaken : []).map(t => {
      if (!t) return '';
      const p = String(t).trim().split(':');
      return p.length >= 2 ? (p[0].padStart(2, '0') + ':' + p[1].padStart(2, '0')) : String(t).substring(0, 5);
    });

    slotGrid.innerHTML = '';

    const availableSlots = allSlots.filter(s => !taken.includes(s));

    // If completely full on this day
    if (availableSlots.length === 0) {
      slotGrid.innerHTML = `
        <div class="slot-fully-booked-box">
          <div class="slot-fully-booked-icon"><i class="fas fa-calendar-xmark"></i></div>
          <div>
            <div class="slot-fully-booked-title">Fully Booked for This Date</div>
            <p class="slot-fully-booked-desc">All appointment time slots for this day have already been booked. Please pick another date on the calendar.</p>
          </div>
        </div>
      `;
      selTime.value = '';
      if (btnNext3) btnNext3.disabled = true;
      updateStepsBar();
      return;
    }

    // Availability summary banner
    const availBar = document.createElement('div');
    availBar.className = 'slot-avail-bar';
    availBar.innerHTML = `
      <span class="badge-avail"><i class="fas fa-circle-check"></i> ${availableSlots.length} of ${allSlots.length} slots available</span>
      ${taken.length > 0 ? `<span class="badge-taken"><i class="fas fa-lock"></i> ${taken.length} booked / unavailable</span>` : ''}
    `;
    slotGrid.appendChild(availBar);

    const amSlots = allSlots.filter(s => parseInt(s) < 12);
    const pmSlots = allSlots.filter(s => parseInt(s) >= 12);

    const renderSection = (label, slots) => {
      if (!slots.length) return;
      const title = document.createElement('div');
      title.className = 'slot-section-title';
      title.innerHTML = `<i class="fas fa-${label==='Morning'?'sun':'cloud-moon'}"></i> ${label}`;
      slotGrid.appendChild(title);
      const grid = document.createElement('div');
      grid.className = 'slot-grid';
      slots.forEach(slot => {
        const isTaken = taken.includes(slot);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn' + (isTaken ? ' is-taken' : '');
        btn.disabled = isTaken;
        btn.title = isTaken ? 'This time slot is already booked by another patient' : 'Click to select this time slot';
        if (isTaken) {
          btn.setAttribute('aria-disabled', 'true');
          btn.setAttribute('tabindex', '-1');
        }
        const [h,m] = slot.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12  = h % 12 || 12;
        btn.innerHTML = `${h12}:${String(m).padStart(2,'0')}<span class="slot-period ${isTaken ? 'booked' : ''}">${isTaken ? '<i class="fas fa-lock me-1"></i>Booked' : ampm}</span>`;
        if (!isTaken) {
          btn.addEventListener('click', () => {
            document.querySelectorAll('#slotGrid .slot-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            selTime.value = slot;
            const b3 = document.getElementById('btnStep3Next');
            if (b3) b3.disabled = false;
            updateStepsBar();
          });
        }
        grid.appendChild(btn);
      });
      slotGrid.appendChild(grid);
    };

    renderSection('Morning', amSlots);
    renderSection('Afternoon', pmSlots);
  } catch(e) {
    slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-exclamation-circle" style="color:var(--clr-danger)"></i>Could not load slots. Please try again.</div>';
  }
}

// Progress Steps Indicator
function updateStepsBar() {
  const p = document.getElementById('purposeInput').value;
  const s = document.getElementById('serviceInput').value;
  const d = dateInput.value;
  const t = selTime.value;

  const s1 = document.getElementById('step1');
  const s2 = document.getElementById('step2');
  const s3 = document.getElementById('step3');
  const s4 = document.getElementById('step4');
  const trackFill = document.getElementById('stepperTrackFill');

  if (!s1 || !s2 || !s3 || !s4) return;

  // Animate track fill percentage
  if (trackFill) {
    const percentages = { 1: '0%', 2: '33.33%', 3: '66.66%', 4: '100%' };
    trackFill.style.width = percentages[currentStep] || '0%';
  }

  // Step 1 status
  if (currentStep === 1) {
    s1.className = 'step-node active';
    s1.querySelector('.step-num').textContent = '1';
  } else if (p) {
    s1.className = 'step-node done';
    s1.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
  } else {
    s1.className = 'step-node';
    s1.querySelector('.step-num').textContent = '1';
  }

  // Step 2 status
  if (currentStep === 2) {
    s2.className = 'step-node active';
    s2.querySelector('.step-num').textContent = '2';
  } else if (s) {
    s2.className = 'step-node done';
    s2.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
  } else {
    s2.className = 'step-node';
    s2.querySelector('.step-num').textContent = '2';
  }

  // Step 3 status
  if (currentStep === 3) {
    s3.className = 'step-node active';
    s3.querySelector('.step-num').textContent = '3';
  } else if (d && t) {
    s3.className = 'step-node done';
    s3.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
  } else {
    s3.className = 'step-node';
    s3.querySelector('.step-num').textContent = '3';
  }

  // Step 4 status
  if (currentStep === 4) {
    s4.className = 'step-node active';
    s4.querySelector('.step-num').textContent = '4';
  } else {
    s4.className = 'step-node';
    s4.querySelector('.step-num').textContent = '4';
  }
}

// Populate Step 4 review card
function populateReviewSummary() {
  const p = document.getElementById('purposeInput').value;
  const s = document.getElementById('serviceInput').value;
  const d = dateInput.value;
  const t = selTime.value;
  const notesEl = document.getElementById('apptNotes');
  const n = notesEl ? notesEl.value.trim() : '';

  document.getElementById('rev-purpose').textContent = purposeMap[p] || p || '—';
  document.getElementById('rev-service').textContent = s || '—';
  document.getElementById('rev-date').textContent    = d ? fmtDate(d) : '—';
  document.getElementById('rev-time').textContent    = t ? fmtTime(t) : '—';
  document.getElementById('rev-notes').textContent   = n || 'None';
}

// Form submit handler
document.getElementById('bookingForm').addEventListener('submit', function(e) {
  const p = document.getElementById('purposeInput').value;
  const s = document.getElementById('serviceInput').value;
  const d = dateInput.value;
  const t = selTime.value;

  if (!p || !s || !d || !t) {
    e.preventDefault();
    alert('Please complete all steps before confirming your appointment.');
    return;
  }

  const btn = document.getElementById('finalBookBtn');
  if (btn) {
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Booking Appointment...';
    btn.disabled = true;
  }
});

// ── CONFIRMATION MODAL ──────────────────────────────────────
const purposeLabels = <?= json_encode(array_merge([
  'consultation'         => 'Eye Consultation / Check-up',
  'eyeglass_claim'       => 'Eyeglass Claim / Pickup',
  'contact_lens_fitting' => 'Contact Lens Fitting',
  'follow_up'            => 'Follow-up Visit',
  'prescription_check'   => 'Prescription Check',
  'other'                => 'Other / General',
], array_column($bookingCategories, 'name', 'category_key'))) ?>;

function fmtTime(slot) {
  const [h,m] = slot.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  return (h % 12 || 12) + ':' + String(m).padStart(2,'0') + ' ' + ampm;
}
function fmtDate(ymd) {
  const dt = new Date(ymd + 'T00:00:00');
  return dt.toLocaleDateString('en-PH',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}

function openConfirmModal() {
  goToStep(4);
}
function closeConfirmModal() {
  const m = document.getElementById('confirmModal');
  if (m) m.classList.remove('open');
  document.body.style.overflow = '';
}
function submitBooking() {
  const form = document.getElementById('bookingForm');
  if (form) form.requestSubmit();
}

// ── EDIT MODAL ───────────────────────────────────────────────
function updateEditSlotChip(slot) {
  const chip = document.getElementById('editSelectedSlotText');
  if (!chip) return;
  if (!slot) {
    chip.className = 'edit-selected-chip';
    chip.innerHTML = 'No slot selected';
    return;
  }
  const [h, m] = slot.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  const h12  = h % 12 || 12;
  chip.className = 'edit-selected-chip active';
  chip.innerHTML = `<i class="fas fa-check-circle me-1"></i> ${h12}:${String(m).padStart(2,'0')} ${ampm}`;
}

function openEditModal(id, date, time, purpose, notes) {
  const cleanTime = time ? time.substring(0, 5) : '';
  isEditUpdateConfirmed = false;

  // Store original values for change detection
  window._originalEditData = {
    id: id,
    date: (date || '').trim(),
    time: cleanTime,
    purpose: (purpose || '').trim(),
    notes: (notes || '').trim()
  };

  document.getElementById('editApptId').value  = id;
  if (editDatePicker) {
    editDatePicker.setDate(date, false);
  } else {
    document.getElementById('editDate').value = date;
  }
  document.getElementById('editNotes').value   = notes || '';
  document.getElementById('editSelTime').value = '';
  updateEditSlotChip(null);

  const purposeSel = document.getElementById('editPurpose');
  purposeSel.value = purpose;

  document.getElementById('editSubmitBtn').disabled = true;

  document.getElementById('editModal').classList.add('open');
  document.body.style.overflow = 'hidden';

  loadEditSlots(date, cleanTime);

  document.getElementById('editDate').onchange = () => {
    const editDateVal = document.getElementById('editDate').value;
    if (editDateVal) {
      const dt = new Date(editDateVal + 'T00:00:00');
      const dow = dt.getDay(); // 0=Sun, 6=Sat
      if (dow === 0 || dow === 6) {
        showWeekendToast();
        if (editDatePicker) editDatePicker.clear();
        else document.getElementById('editDate').value = '';
        document.getElementById('editSelTime').value = '';
        updateEditSlotChip(null);
        document.getElementById('editSlotGrid').innerHTML = '<div class="slot-placeholder"><i class="fas fa-calendar-day"></i><span>Select a weekday to view available slots</span></div>';
        checkEditReady();
        return;
      }
    }
    document.getElementById('editSelTime').value = '';
    updateEditSlotChip(null);
    checkEditReady();
    loadEditSlots(editDateVal, null);
  };
}
function closeEditModal() {
  document.getElementById('editModal').classList.remove('open');
  document.body.style.overflow = '';
}

async function loadEditSlots(date, preselect) {
  const grid = document.getElementById('editSlotGrid');
  grid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-spinner fa-spin" style="font-size:1.2rem;opacity:.5;"></i><span>Loading available slots...</span></div>';
  if (!date) return;

  try {
    const res = await fetch('dashboard.php?check_date=' + encodeURIComponent(date));
    const rawTaken = await res.json();
    // Normalize taken array to 'HH:MM'
    const taken = (Array.isArray(rawTaken) ? rawTaken : []).map(t => {
      if (!t) return '';
      const p = String(t).trim().split(':');
      return p.length >= 2 ? (p[0].padStart(2, '0') + ':' + p[1].padStart(2, '0')) : String(t).substring(0, 5);
    });
    grid.innerHTML = '';

    const availableSlots = allSlots.filter(s => !taken.includes(s) || s === preselect);
    if (availableSlots.length === 0) {
      grid.innerHTML = `
        <div class="slot-fully-booked-box" style="margin: 4px 0 10px; width: 100%;">
          <div class="slot-fully-booked-icon"><i class="fas fa-calendar-xmark"></i></div>
          <div>
            <div class="slot-fully-booked-title">Fully Booked for This Date</div>
            <p class="slot-fully-booked-desc">All appointment time slots for this date have been taken. Please select another date.</p>
          </div>
        </div>
      `;
      document.getElementById('editSelTime').value = '';
      updateEditSlotChip(null);
      checkEditReady();
      return;
    }

    // Availability summary banner
    const availBar = document.createElement('div');
    availBar.className = 'slot-avail-bar';
    availBar.style.width = '100%';
    availBar.innerHTML = `
      <span class="badge-avail"><i class="fas fa-circle-check"></i> ${availableSlots.length} of ${allSlots.length} slots available</span>
      ${taken.length > 0 ? `<span class="badge-taken"><i class="fas fa-lock"></i> ${taken.filter(s => s !== preselect).length} booked</span>` : ''}
    `;
    grid.appendChild(availBar);

    const amSlots = allSlots.filter(s => parseInt(s) < 12);
    const pmSlots = allSlots.filter(s => parseInt(s) >= 12);

    let hasMatchedPreselect = false;

    const renderSection = (label, slots) => {
      if (!slots.length) return;
      const sectionWrap = document.createElement('div');
      sectionWrap.style.width = '100%';

      const title = document.createElement('div');
      title.className = 'slot-section-title';
      title.innerHTML = `<i class="fas fa-${label==='Morning'?'sun':'cloud-moon'}"></i> ${label}`;
      sectionWrap.appendChild(title);

      const sGrid = document.createElement('div');
      sGrid.className = 'slot-grid';

      slots.forEach(slot => {
        const isTaken = taken.includes(slot) && slot !== preselect;
        const [h, m] = slot.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12  = h % 12 || 12;
        const isPreselect = (slot === preselect);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn' + (isPreselect ? ' selected' : '') + (isTaken ? ' is-taken' : '');
        btn.disabled = isTaken;
        btn.title = isTaken ? 'Already booked by another patient' : (isPreselect ? 'Current time slot' : 'Click to select this time slot');
        if (isTaken) {
          btn.setAttribute('aria-disabled', 'true');
          btn.setAttribute('tabindex', '-1');
        }
        btn.innerHTML = `${h12}:${String(m).padStart(2,'0')}<span class="slot-period ${isTaken ? 'booked' : (isPreselect ? 'current' : '')}">${isTaken ? '<i class="fas fa-lock me-1"></i>Booked' : (isPreselect ? '<i class="fas fa-check me-1"></i>Current' : ampm)}</span>`;

        if (isPreselect) {
          document.getElementById('editSelTime').value = slot;
          updateEditSlotChip(slot);
          hasMatchedPreselect = true;
        }

        if (!isTaken) {
          btn.addEventListener('click', () => {
            grid.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            document.getElementById('editSelTime').value = slot;
            updateEditSlotChip(slot);
            checkEditReady();
          });
        }

        sGrid.appendChild(btn);
      });
      sectionWrap.appendChild(sGrid);
      grid.appendChild(sectionWrap);
    };

    renderSection('Morning', amSlots);
    renderSection('Afternoon', pmSlots);

    if (!hasMatchedPreselect && preselect) {
      updateEditSlotChip(null);
    }

    checkEditReady();
  } catch(e) {
    grid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-exclamation-circle" style="color:var(--clr-danger)"></i><span>Failed to load slots. Please try again.</span></div>';
  }
}

function checkEditReady() {
  const d = document.getElementById('editDate').value;
  const t = document.getElementById('editSelTime').value;
  const p = document.getElementById('editPurpose').value;
  const ok = Boolean(d && t && p);
  const btn = document.getElementById('editSubmitBtn');
  if (btn) {
    btn.disabled = !ok;
  }
}
document.getElementById('editPurpose').addEventListener('change', checkEditReady);

let isEditUpdateConfirmed = false;

// Intercept editForm submission to validate changes and confirm update
const editForm = document.getElementById('editForm');
if (editForm) {
  editForm.addEventListener('submit', function(e) {
    if (isEditUpdateConfirmed) {
      return true;
    }

    e.preventDefault();

    const newDate    = (document.getElementById('editDate').value || '').trim();
    const newTime    = (document.getElementById('editSelTime').value || '').trim();
    const newPurpose = (document.getElementById('editPurpose').value || '').trim();
    const newNotes   = (document.getElementById('editNotes').value || '').trim();

    // Check if no changes were made
    if (window._originalEditData) {
      const orig = window._originalEditData;
      const isSameDate    = (newDate === orig.date);
      const isSameTime    = (newTime === orig.time);
      const isSamePurpose = (newPurpose === orig.purpose);
      const isSameNotes   = (newNotes === orig.notes);

      if (isSameDate && isSameTime && isSamePurpose && isSameNotes) {
        showPopupModal('No changes were made to your appointment details.', 'info', 'No Changes Detected');
        return false;
      }
    }

    // Format time slot for confirmation message
    let formattedTime = newTime;
    if (newTime && newTime.includes(':')) {
      const [h, m] = newTime.split(':').map(Number);
      const ampm = h >= 12 ? 'PM' : 'AM';
      const h12  = h % 12 || 12;
      formattedTime = `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
    }

    // Format date for confirmation message
    let formattedDate = newDate;
    try {
      const dObj = new Date(newDate + 'T00:00:00');
      if (!isNaN(dObj.getTime())) {
        formattedDate = dObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
      }
    } catch(err) {}

    if (typeof Swal === 'undefined') {
      if (confirm(`Are you sure you want to update this appointment to ${formattedDate} at ${formattedTime}?`)) {
        isEditUpdateConfirmed = true;
        editForm.submit();
      }
      return false;
    }

    Swal.fire({
      title: 'Update Appointment?',
      html: `Are you sure you want to save the changes to this appointment?<br><div style="margin-top:12px;padding:10px 14px;border-radius:12px;background:var(--bg-hover);border:1px solid var(--border-color);font-size:0.95rem;text-align:center;"><i class="fas fa-calendar-check me-2" style="color:var(--clr-primary);"></i><strong>${formattedDate}</strong> at <strong>${formattedTime}</strong></div>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: 'var(--clr-primary)',
      cancelButtonColor: '#64748B',
      confirmButtonText: '<i class="fas fa-save me-1"></i> Yes, Save Changes',
      cancelButtonText: '<i class="fas fa-times me-1"></i> Keep Editing',
      background: 'var(--bg-card)',
      color: 'var(--text-primary)',
      customClass: {
        popup: 'patient-swal-popup'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        isEditUpdateConfirmed = true;
        editForm.submit();
      }
    });

    return false;
  });
}

// Attach edit button listeners via event delegation
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.js-edit-btn');
  if (!btn) return;
  openEditModal(
    parseInt(btn.dataset.id),
    btn.dataset.date,
    btn.dataset.time,
    btn.dataset.purpose,
    btn.dataset.notes || ''
  );
});

// Auto open tab if returned from an edit or direct navigation
<?php if (($_SESSION['open_tab'] ?? '') === 'history'): unset($_SESSION['open_tab']); ?>
window.addEventListener('DOMContentLoaded', () => {
  switchTab('history', document.getElementById('tab-history'));
});
<?php elseif (($_SESSION['open_tab'] ?? '') === 'prescriptions'): unset($_SESSION['open_tab']); ?>
window.addEventListener('DOMContentLoaded', () => {
  switchTab('prescriptions', document.getElementById('tab-prescriptions'));
});
<?php endif; ?>

// ── APPOINTMENT DETAILS MODAL ────────────────────────────────
function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function showAppointmentDetails(btn) {
  if (!btn) return;
  const id = btn.dataset.id;
  const dateStr = btn.dataset.date || '';
  const timeStr = btn.dataset.time || '';
  const status = btn.dataset.status || 'pending';
  const purpose = btn.dataset.purpose || '';
  const service = btn.dataset.service || '';
  const notes = btn.dataset.notes || '';
  const rawNotes = btn.dataset.rawNotes || '';
  const countdown = btn.dataset.countdown || '';

  const isPending = (status === 'pending');
  const paddedId = String(id).padStart(5, '0');
  const statusLabel = status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');

  // Format Time
  let formattedTime = timeStr;
  if (timeStr && timeStr.includes(':')) {
    const [h, m] = timeStr.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    formattedTime = `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
  }

  // Format Date & Day
  let formattedDate = dateStr;
  let weekday = '';
  try {
    const dObj = new Date(dateStr + 'T00:00:00');
    if (!isNaN(dObj.getTime())) {
      formattedDate = dObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
      weekday = dObj.toLocaleDateString('en-US', { weekday: 'long' });
    }
  } catch(e) {}

  const purposeLabel = purposeLabels[purpose] || purpose.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || 'Consultation';

  if (typeof Swal === 'undefined') {
    switchTab('history', document.getElementById('tab-history'));
    return;
  }

  const contentHtml = `
    <div class="appt-modal-details" style="font-family:'Plus Jakarta Sans','Poppins',sans-serif;text-align:left;">
      <!-- Ticket & Status Badge -->
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
        <div style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:100px;background:rgba(0,173,239,0.12);border:1.5px solid rgba(0,173,239,0.35);font-size:0.8rem;font-weight:800;color:var(--clr-primary-light);">
          <i class="fas fa-ticket-alt"></i> Ticket #APT-${paddedId}
        </div>
        <div class="ticket-status-pill status-${status}" style="padding:4px 14px;font-size:0.75rem;">
          ${status === 'pending' ? '<i class="fas fa-hourglass-half status-badge-icon me-1"></i>' : (status === 'confirmed' ? '<span class="status-dot dot-green pulse"></span>' : '<span class="status-dot dot-amber"></span>')}
          ${statusLabel}
        </div>
      </div>

      ${countdown ? `
      <div style="margin-bottom:14px;padding:10px 14px;border-radius:12px;background:var(--bg-hover);border:1px solid var(--border-color);display:flex;align-items:center;gap:8px;font-size:0.88rem;font-weight:700;color:var(--text-primary);">
        <i class="fas fa-bolt" style="color:var(--clr-primary-light);"></i>
        <span>${escapeHtml(countdown)}</span>
      </div>` : ''}

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-calendar-day me-1" style="color:var(--clr-primary-light)"></i> Date
          </div>
          <div style="font-size:0.92rem;font-weight:800;color:var(--text-primary);">${formattedDate}</div>
          <div style="font-size:0.78rem;font-weight:600;color:var(--text-secondary);">${weekday}</div>
        </div>

        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-clock me-1" style="color:var(--clr-primary-light)"></i> Time Slot
          </div>
          <div style="font-size:0.92rem;font-weight:800;color:var(--text-primary);">${formattedTime}</div>
          <div style="font-size:0.78rem;font-weight:600;color:var(--text-secondary);">Clinic Hours (9AM–5PM)</div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-eye me-1" style="color:var(--clr-primary-light)"></i> Purpose of Visit
          </div>
          <div style="font-size:0.92rem;font-weight:800;color:var(--text-primary);">${escapeHtml(purposeLabel)}</div>
        </div>

        ${service ? `
        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-hand-holding-medical me-1" style="color:var(--clr-primary-light)"></i> Optical Service
          </div>
          <div style="font-size:0.9rem;font-weight:800;color:var(--clr-primary-light);">${escapeHtml(service)}</div>
        </div>` : ''}

        ${notes ? `
        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-notes-medical me-1" style="color:var(--clr-primary-light)"></i> Notes & Instructions
          </div>
          <div style="font-size:0.86rem;font-weight:500;color:var(--text-secondary);line-height:1.45;word-break:break-word;">${escapeHtml(notes)}</div>
        </div>` : ''}

        <div style="background:var(--bg-hover);border:1px solid var(--border-color);border-radius:14px;padding:12px 14px;">
          <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:4px;">
            <i class="fas fa-location-dot me-1" style="color:var(--clr-primary-light)"></i> Location & Specialist
          </div>
          <div style="font-size:0.86rem;font-weight:700;color:var(--text-primary);">Gueco Optical Clinic · Capas, Tarlac</div>
          <div style="font-size:0.78rem;font-weight:600;color:var(--text-secondary);margin-top:2px;">Licensed Optometrist Examination</div>
        </div>
      </div>

      ${isPending ? `
      <div style="font-size:0.78rem;color:var(--text-muted);text-align:center;">
        <i class="fas fa-info-circle me-1"></i> You can edit or reschedule anytime while pending.
      </div>` : `
      <div style="display:flex;align-items:center;justify-content:center;gap:6px;font-size:0.8rem;color:var(--clr-success);font-weight:700;">
        <i class="fas fa-shield-check"></i> Confirmed &amp; locked by clinic staff
      </div>`}
    </div>
  `;

  Swal.fire({
    title: 'Appointment Details',
    html: contentHtml,
    showCancelButton: isPending,
    cancelButtonText: '<i class="fas fa-pen me-1"></i> Edit Schedule',
    showDenyButton: true,
    denyButtonText: '<i class="fas fa-list-check me-1"></i> View in List',
    confirmButtonText: '<i class="fas fa-check me-1"></i> Close',
    confirmButtonColor: 'var(--clr-primary)',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)',
    customClass: {
      popup: 'patient-swal-popup'
    }
  }).then((result) => {
    if (result.isDismissed && result.dismiss === Swal.DismissReason.cancel) {
      openEditModal(
        parseInt(id),
        dateStr,
        timeStr.substring(0, 5),
        purpose,
        rawNotes
      );
    } else if (result.isDenied) {
      switchTab('history', document.getElementById('tab-history'));
      setTimeout(() => {
        const card = document.getElementById('appt-card-' + id);
        if (card) {
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          card.classList.add('card-highlight');
          setTimeout(() => card.classList.remove('card-highlight'), 3000);
        }
      }, 200);
    }
  });
}

// ── CUTE WELCOME POPUP MODAL (REDESIGNED GREETING) ───────────
function showCuteWelcomeModal(msg, title = 'Welcome Back!') {
  if (typeof Swal === 'undefined') {
    alert(msg || 'Welcome back!');
    return;
  }
  
  let cleanMsg = msg ? msg.replace(/^Security verification passed!\s*/i, '').trim() : 'Great to see you again!';
  let displayTitle = title ? title.replace(/[!✨👋]/g, '').trim() : 'Welcome Back!';
  
  Swal.fire({
    html: `
      <div class="cute-modal-wrapper">
        <div class="cute-avatar-container">
          <div class="cute-avatar-glow"></div>
          <div class="cute-avatar-circle">
            <span class="cute-wave-emoji">👋</span>
            <span class="cute-sparkle-1">✨</span>
            <span class="cute-sparkle-2">👓</span>
          </div>
        </div>
        <div class="cute-greeting-pill">
          <i class="fas fa-sparkles"></i> Happy to see you!
        </div>
        <h2 class="cute-modal-title">${displayTitle} ✨</h2>
        <p class="cute-modal-text">${cleanMsg}</p>
        <div class="cute-modal-tip">
          <i class="fas fa-heart"></i>
          <span><strong>Doctor's Tip:</strong> Take regular screen breaks to keep your eyesight refreshed!</span>
        </div>
      </div>
    `,
    showConfirmButton: true,
    confirmButtonText: "Let's Go! ✨",
    buttonsStyling: false,
    customClass: {
      popup: 'cute-welcome-swal-popup',
      confirmButton: 'cute-welcome-confirm-btn'
    },
    background: 'var(--bg-card)'
  });
}

// ── SWEETALERT2 MODAL NOTIFICATIONS (MATCHING ADMIN) ──────────
function showPopupModal(msg, type = 'info', title = null) {
  if (!msg) return;
  if (typeof Swal === 'undefined') {
    alert(msg);
    return;
  }
  const isError = (type === 'danger' || type === 'error');
  const isSuccess = (type === 'success');
  const isWarning = (type === 'warning');
  const iconType = isSuccess ? 'success' : (isError ? 'error' : (isWarning ? 'warning' : 'info'));
  const titleText = title || (isSuccess ? 'Success!' : (isError ? 'Notice' : 'Information'));
  
  Swal.fire({
    title: titleText,
    text: msg,
    icon: iconType,
    confirmButtonText: 'OK',
    confirmButtonColor: 'var(--clr-primary)',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)',
    customClass: {
      popup: 'patient-swal-popup'
    }
  });
}

// Custom confirmation popup for cancelling appointments
function confirmCancelAppt(form) {
  if (!form) return;
  if (typeof Swal === 'undefined') {
    if (confirm('Are you sure you want to cancel this appointment?')) form.submit();
    return;
  }
  Swal.fire({
    title: 'Cancel Appointment?',
    text: 'Are you sure you want to cancel this appointment? This action cannot be reversed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#EF4444',
    cancelButtonColor: '#64748B',
    confirmButtonText: 'Yes, Cancel Appointment',
    cancelButtonText: 'Keep Appointment',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)',
    customClass: {
      popup: 'patient-swal-popup'
    }
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit();
    }
  });
}

// Weekend Warning Modal
function showWeekendToast() {
  showPopupModal('Gueco Optical Clinic is open Monday to Friday, 9:00 AM – 5:00 PM. We are closed on Saturdays and Sundays. Please select a weekday schedule.', 'info', 'Clinic Closed on Weekends');
}

// Show PHP-passed notifications as SweetAlert2 Popup Modal
document.addEventListener('DOMContentLoaded', function() {
  const pFlash = document.getElementById('patientFlashMsg');
  if (pFlash) {
    const msg = pFlash.dataset.msg;
    const type = pFlash.dataset.type || 'info';
    const title = pFlash.dataset.title;
    pFlash.remove();
    if (msg) {
      const isWelcome = (title && title.toLowerCase().includes('welcome')) || 
                        (msg && msg.toLowerCase().includes('welcome'));
      if (isWelcome) {
        showCuteWelcomeModal(msg, title);
      } else {
        showPopupModal(msg, type, title);
      }
    }
  }
  if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
  }
});

// User Dropdown Toggle
function toggleDropdown() {
  document.getElementById('userDropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.user-dropdown')) {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) dropdown.classList.remove('open');
  }
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeConfirmModal();
    closeEditModal();
    closeRxModal();
  }
});

// ── OPTICAL PRESCRIPTIONS DATA & MODAL LOGIC ──────────────────
const patientPrescriptionsData = <?= json_encode($patientRxList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const patientProfileData = {
  name: <?= json_encode($patientFullName) ?>,
  age: <?= json_encode($patientAge) ?>,
  gender: <?= json_encode(!empty($patient['gender']) ? ucfirst($patient['gender']) : '') ?>
};

function openRxModal(rxId) {
  const rx = patientPrescriptionsData.find(item => Number(item.id) === Number(rxId));
  if (!rx) return;

  const d = new Date(rx.created_at);
  const formattedDate = !isNaN(d.getTime()) 
    ? d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
    : rx.created_at;
  
  const docName = rx.doctor_name ? ('Dr. ' + rx.doctor_name) : 'Attending Optometrist';
  const odAdd = rx.od_add || rx.add_power || '—';
  const osAdd = rx.os_add || rx.add_power || '—';
  const notes = rx.notes || rx.recommendations || 'No special clinical remarks.';
  const pdVal = rx.pd ? (rx.pd + ' mm') : 'Standard';
  const lensVal = rx.lens_type || 'Single Vision / As Advised';

  document.getElementById('modalRxIdBadge').textContent = 'Rx #' + rx.id;
  document.getElementById('modalRxDate').textContent = formattedDate;
  document.getElementById('modalRxDoctorName').textContent = docName;
  
  document.getElementById('modalRxOdSph').textContent = rx.od_sphere || '—';
  document.getElementById('modalRxOdCyl').textContent = rx.od_cylinder || '—';
  document.getElementById('modalRxOdAxis').textContent = rx.od_axis ? (rx.od_axis + '°') : '—';
  document.getElementById('modalRxOdAdd').textContent = odAdd;
  document.getElementById('modalRxOdVa').textContent = rx.od_va || '—';

  document.getElementById('modalRxOsSph').textContent = rx.os_sphere || '—';
  document.getElementById('modalRxOsCyl').textContent = rx.os_cylinder || '—';
  document.getElementById('modalRxOsAxis').textContent = rx.os_axis ? (rx.os_axis + '°') : '—';
  document.getElementById('modalRxOsAdd').textContent = osAdd;
  document.getElementById('modalRxOsVa').textContent = rx.os_va || '—';

  document.getElementById('modalRxPd').textContent = pdVal;
  document.getElementById('modalRxLens').textContent = lensVal;
  document.getElementById('modalRxNotes').textContent = notes;

  const modal = document.getElementById('rxSlipModal');
  if (modal) {
    modal.classList.add('open');
    modal.classList.add('active');
  }
}

function closeRxModal() {
  const modal = document.getElementById('rxSlipModal');
  if (modal) {
    modal.classList.remove('open');
    modal.classList.remove('active');
  }
}

function printRxDirect(rxId) {
  openRxModal(rxId);
  setTimeout(() => {
    window.print();
  }, 250);
}

function copyRxValues(rxId) {
  const rx = patientPrescriptionsData.find(item => Number(item.id) === Number(rxId));
  if (!rx) return;

  const odAdd = rx.od_add || rx.add_power || '—';
  const osAdd = rx.os_add || rx.add_power || '—';
  const text = `Gueco Optical Clinic Prescription (Rx #${rx.id})\nOD (Right Eye): SPH ${rx.od_sphere || '0.00'} | CYL ${rx.od_cylinder || '0.00'} | AXIS ${rx.od_axis ? rx.od_axis + '°' : '0°'} | ADD ${odAdd}\nOS (Left Eye): SPH ${rx.os_sphere || '0.00'} | CYL ${rx.os_cylinder || '0.00'} | AXIS ${rx.os_axis ? rx.os_axis + '°' : '0°'} | ADD ${osAdd}\nPD: ${rx.pd ? rx.pd + 'mm' : 'N/A'}`;

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'success',
          title: 'Prescription copied to clipboard!',
          showConfirmButton: false,
          timer: 2500,
          background: 'var(--bg-card)',
          color: 'var(--text-primary)'
        });
      } else {
        alert('Prescription values copied to clipboard!');
      }
    });
  } else {
    alert(text);
  }
}

// Auto-open prescription modal if directed from a notification, email, or URL link
document.addEventListener('DOMContentLoaded', function() {
  try {
    const urlParams = new URLSearchParams(window.location.search);
    const openRxId = urlParams.get('open_rx') || urlParams.get('rx_id');
    if (openRxId) {
      setTimeout(() => {
        openRxModal(openRxId);
      }, 350);
    }
  } catch (e) {}
});
</script>
</body>
</html>
