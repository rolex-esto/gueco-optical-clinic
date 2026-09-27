<?php
// Shared header config — included at the top of every staff page
if (!defined('BASE_URL')) {
    define('BASE_URL', str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/') - 2));
}
require_once __DIR__ . '/../config/functions.php';
startSession();

// Each page sets $pageTitle, $breadcrumb[], $activeNav before including this
$pageTitle   = $pageTitle   ?? 'Dashboard';
$breadcrumb  = $breadcrumb  ?? [];
$activeNav   = $activeNav   ?? '';
$user        = getCurrentUser();

$fullName    = $user['full_name'] ?? 'Admin';
$initials    = strtoupper(substr($fullName, 0, 1));
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize($pageTitle) ?> — Gueco Optical</title>
  <meta name="description" content="Gueco Optical Clinic Management System">

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

    window.toggleSidebarMobile = function(forceState) {
      var sb = document.getElementById('sidebar');
      var bd = document.getElementById('sidebarBackdrop');
      if (!sb) return;
      var willOpen = (typeof forceState === 'boolean') ? forceState : !sb.classList.contains('open');
      if (willOpen) {
        sb.classList.add('open');
        if (bd) bd.classList.add('show');
        document.body.classList.add('sidebar-open');
      } else {
        sb.classList.remove('open');
        if (bd) bd.classList.remove('show');
        document.body.classList.remove('sidebar-open');
      }
    };

    // Close mobile sidebar on Escape key
    window.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') window.toggleSidebarMobile(false);
    });
  </script>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Custom CSS with Cache Buster -->
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= time() ?>">

  <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>

<?php if (isset($_SESSION['flash_msg'])): ?>
<div id="flashMsg"
     data-msg="<?= htmlspecialchars($_SESSION['flash_msg']) ?>"
     data-type="<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>"
     style="display:none"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); endif; ?>

<div class="app-wrapper">

  <!-- Mobile Sidebar Backdrop Overlay -->
  <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="window.toggleSidebarMobile(false)"></div>

  <!-- SIDEBAR (Floating Modern Dock) -->
  <aside class="sidebar" id="sidebar">
    <!-- Logo -->
    <div class="sidebar-logo">
      <div class="logo-icon">
        <img src="<?= htmlspecialchars(getClinicLogoUrl(BASE_URL)) ?>" alt="Logo" style="width:100%; height:100%; object-fit:contain;">
      </div>
      <div class="logo-text">
        <h6>Gueco Optical</h6>
        <span>Clinic Management</span>
      </div>
    </div>

    <!-- User Info -->
    <div class="sidebar-user">
      <div class="user-avatar">
        <span><?= $initials ?></span>
      </div>
      <div class="user-info">
        <div class="user-name"><?= sanitize($fullName) ?></div>
        <div class="user-role"><?= getRoleLabel($user['role'] ?? '') ?></div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
      <?php include __DIR__ . '/sidebar.php'; ?>
    </nav>

    <!-- Logout -->
    <div class="sidebar-bottom">
      <a href="<?= BASE_URL ?>logout.php" class="nav-link">
        <div class="nav-icon" style="color:var(--clr-danger)"><i class="fas fa-sign-out-alt"></i></div>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- HEADER -->
    <header class="app-header">
      <div class="header-left">
        <!-- Mobile menu toggle -->
        <button id="sidebarToggle" type="button" class="header-icon-btn d-lg-none me-2" aria-label="Toggle Navigation Menu">
          <i class="fas fa-bars"></i>
        </button>
        <div class="header-greeting">
          <h4>Hello, <?= sanitize($fullName) ?>!</h4>
          <p>Explore information and activity about your clinic</p>
        </div>
      </div>

      <div class="header-right">
        <!-- Search Pill -->
        <div class="header-search-wrap">
          <input type="text" placeholder="Search..." aria-label="Search">
          <button type="button" class="header-search-btn" title="Search">
            <i class="fas fa-search"></i>
          </button>
        </div>

        <!-- Theme Toggle Button -->
        <button class="header-icon-btn" id="themeToggle" title="Toggle Theme">
          <i class="fas fa-moon" id="themeIcon"></i>
        </button>

        <!-- Notification Bell -->
        <div class="dropdown" id="notifDropdownWrap">
          <button class="header-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php
            try {
              $db = getDB();
              $currentUserRole = $user['role'] ?? ($_SESSION['role'] ?? '');
              $purposeWhere = ($currentUserRole === 'doctor') ? " AND purpose != 'eyeglass_claim'" : "";
              $purposeWhereJoin = ($currentUserRole === 'doctor') ? " AND a.purpose != 'eyeglass_claim'" : "";
              $headerApptsLink = BASE_URL . ($currentUserRole === 'doctor' ? 'doctor' : ($currentUserRole === 'saleslady' ? 'saleslady' : 'admin')) . '/appointments.php';
              
              $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()" . $purposeWhere);
              $stmtCount->execute();
              $notifCount = $stmtCount->fetch()['c'];
              
              $stmtRecent = $db->prepare("SELECT p.full_name as patient_name, a.appointment_date, a.appointment_time FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.status = 'pending' AND a.appointment_date >= CURDATE()" . $purposeWhereJoin . " ORDER BY a.created_at DESC LIMIT 5");
              $stmtRecent->execute();
              $recentAppts = $stmtRecent->fetchAll();
            } catch(Exception $e) { 
              $notifCount = 0; 
              $recentAppts = []; 
              $headerApptsLink = BASE_URL . 'admin/appointments.php';
            }
            ?>
            <?php if ($notifCount > 0): ?>
            <span class="notif-badge" id="notifBadgeEl"><?= min($notifCount, 99) ?></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width: 300px; border-radius: 16px; border: 1px solid var(--border-color); background: var(--bg-card);">
            <li><h6 class="dropdown-header fw-bold">Notifications</h6></li>
            <?php if (empty($recentAppts)): ?>
              <li><span class="dropdown-item text-muted">No new notifications</span></li>
            <?php else: ?>
              <?php foreach($recentAppts as $appt): ?>
                <li>
                  <a class="dropdown-item py-2" href="<?= $headerApptsLink ?>">
                    <div class="fw-bold text-truncate" style="max-width: 260px;">
                      <?= sanitize($appt['patient_name']) ?>
                    </div>
                    <small class="text-muted">
                      Requested for <?= date('M d, Y', strtotime($appt['appointment_date'])) ?> 
                      at <?= date('h:i A', strtotime($appt['appointment_time'])) ?>
                    </small>
                  </a>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-center fw-bold" style="color:var(--clr-bronze)" href="<?= $headerApptsLink ?>">View All Appointments</a></li>
          </ul>
        </div>

        <!-- User Initials / Profile Avatar -->
        <div class="header-avatar" title="<?= sanitize($fullName) ?>">
          <?= $initials ?>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT starts here -->
    <div class="page-content">