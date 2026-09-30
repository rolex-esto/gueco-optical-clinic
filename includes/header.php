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
          <button class="header-icon-btn notif-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php
            try {
              $db = getDB();
              $currentUserRole = $user['role'] ?? ($_SESSION['user_role'] ?? ($_SESSION['role'] ?? ''));
              if (empty($currentUserRole)) {
                if (strpos($_SERVER['REQUEST_URI'] ?? '', '/saleslady/') !== false) {
                  $currentUserRole = 'saleslady';
                } elseif (strpos($_SERVER['REQUEST_URI'] ?? '', '/doctor/') !== false) {
                  $currentUserRole = 'doctor';
                } else {
                  $currentUserRole = 'admin';
                }
              }
              $headerApptsLink = BASE_URL . ($currentUserRole === 'doctor' ? 'doctor' : ($currentUserRole === 'saleslady' ? 'saleslady' : 'admin')) . '/appointments.php';
              $headerInventoryLink = BASE_URL . ($currentUserRole === 'saleslady' ? 'saleslady' : 'admin') . '/inventory.php';
              
              $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()" . $purposeWhere);
              $stmtCount->execute();
              $notifCount = (int)$stmtCount->fetch()['c'];
              
              $stmtRecent = $db->prepare("SELECT a.id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.status = 'pending' AND a.appointment_date >= CURDATE()" . $purposeWhereJoin . " ORDER BY a.created_at DESC LIMIT 5");
              $stmtRecent->execute();
              $recentAppts = $stmtRecent->fetchAll();

              // Low Stock Inventory Notifications for Admin and Saleslady
              $lowStockCount = 0;
              $recentLowStock = [];
              if (in_array($currentUserRole, ['admin', 'saleslady'])) {
                $stmtLowCount = $db->query("SELECT COUNT(*) as c FROM products WHERE stock_quantity <= low_stock_alert AND status = 'active'");
                $lowStockCount = (int)($stmtLowCount ? $stmtLowCount->fetch()['c'] : 0);

                $stmtLowRecent = $db->query("
                  SELECT p.id, p.name, p.base_model, p.variant_name, p.stock_quantity, p.low_stock_alert 
                  FROM products p 
                  WHERE p.stock_quantity <= p.low_stock_alert AND p.status = 'active' 
                  ORDER BY p.stock_quantity ASC, p.id DESC 
                  LIMIT 5
                ");
                $recentLowStock = $stmtLowRecent ? $stmtLowRecent->fetchAll(PDO::FETCH_ASSOC) : [];
              }

              // 1. Doctor Notification: Active Walk-in patients waiting today
              $walkinCount = 0;
              $recentWalkin = [];
              if (in_array($currentUserRole, ['doctor', 'admin'])) {
                $stmtWalkin = $db->prepare("
                  SELECT a.id, a.patient_id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  WHERE a.appointment_date = CURDATE()
                    AND a.appointment_type = 'WALK_IN'
                    AND a.status IN ('confirmed', 'in_progress')
                    AND a.id NOT IN (SELECT COALESCE(appointment_id, 0) FROM prescriptions WHERE appointment_id IS NOT NULL)
                  ORDER BY a.created_at DESC
                  LIMIT 5
                ");
                $stmtWalkin->execute();
                $recentWalkin = $stmtWalkin->fetchAll(PDO::FETCH_ASSOC);
                $walkinCount = count($recentWalkin);
              }

              // 2. Saleslady Notification: Patients finished with exam & prescription written, ready for POS
              $readyPosCount = 0;
              $recentReadyPos = [];
              if (in_array($currentUserRole, ['saleslady', 'admin'])) {
                $stmtReadyPos = $db->prepare("
                  SELECT a.id, a.patient_id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.id
                  WHERE a.appointment_date = CURDATE()
                    AND a.status NOT IN ('cancelled', 'no_show')
                    AND NOT EXISTS (
                        SELECT 1 FROM sales s 
                        WHERE s.appointment_id = a.id 
                           OR (s.patient_id = a.patient_id AND DATE(s.created_at) = a.appointment_date)
                    )
                    AND (
                        a.status = 'completed'
                        OR EXISTS (
                            SELECT 1 FROM prescriptions rx 
                            WHERE rx.appointment_id = a.id 
                               OR (rx.patient_id = a.patient_id AND DATE(rx.created_at) = a.appointment_date)
                        )
                    )
                  ORDER BY a.id DESC
                  LIMIT 5
                ");
                $stmtReadyPos->execute();
                $recentReadyPos = $stmtReadyPos->fetchAll(PDO::FETCH_ASSOC);
                $readyPosCount = count($recentReadyPos);
              }

              $totalNotifCount = $notifCount + $lowStockCount + $walkinCount + $readyPosCount;
            } catch(Exception $e) { 
              $notifCount = 0; 
              $recentAppts = []; 
              $lowStockCount = 0;
              $recentLowStock = [];
              $walkinCount = 0;
              $recentWalkin = [];
              $readyPosCount = 0;
              $recentReadyPos = [];
              $totalNotifCount = 0;
              $headerApptsLink = BASE_URL . 'admin/appointments.php';
              $headerInventoryLink = BASE_URL . 'admin/inventory.php';
            }
            ?>
            <?php if ($totalNotifCount > 0): ?>
            <span class="notif-badge" id="notifBadgeEl"><?= min($totalNotifCount, 99) ?></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 320px; max-width: 360px; border-radius: 16px; border: 1px solid var(--border-color) !important; background: var(--bg-card); padding: 0; overflow: hidden;">
            <li class="px-3 py-2 d-flex align-items-center justify-content-between border-bottom" style="background: var(--bg-table-head);">
              <span class="fw-bold" style="font-size: 0.85rem; color: var(--text-primary);"><i class="fas fa-bell me-2" style="color: var(--clr-primary);"></i>Notifications</span>
              <?php if ($totalNotifCount > 0): ?>
                <span class="badge rounded-pill bg-danger" style="font-size: 0.68rem; font-weight: 700;"><?= $totalNotifCount ?> New</span>
              <?php endif; ?>
            </li>
            
            <div style="max-height: 380px; overflow-y: auto;" class="custom-scroll">
              <?php if (empty($recentAppts) && empty($recentLowStock) && empty($recentWalkin) && empty($recentReadyPos)): ?>
                <li class="p-4 text-center text-muted" style="font-size: 0.86rem;">
                  <i class="fas fa-bell-slash d-block mb-2 text-muted" style="font-size: 1.8rem; opacity: 0.4;"></i>
                  <span>No new notifications</span>
                </li>
              <?php else: ?>
                <?php if (!empty($recentWalkin)): ?>
                  <li class="dropdown-header text-uppercase text-warning fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                    <span><i class="fas fa-bolt me-1 text-warning"></i> Walk-in Waiting</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis" style="background: rgba(245,158,11,0.18);"><?= $walkinCount ?></span>
                  </li>
                  <?php foreach ($recentWalkin as $w): ?>
                    <li>
                      <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="<?= BASE_URL ?>doctor/appointments.php?highlight=<?= (int)$w['id'] ?>">
                        <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(245, 158, 11, 0.15); color: #D97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                          <i class="fas fa-bolt"></i>
                        </div>
                        <div class="flex-grow-1 text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($w['patient_name']) ?>
                          </div>
                          <small class="text-warning-emphasis d-block text-truncate" style="font-size: 0.74rem;">
                            Walk-in Waiting &bull; Arrived at <?= date('h:i A', strtotime($w['appointment_time'])) ?>
                          </small>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($recentReadyPos)): ?>
                  <li class="dropdown-header text-uppercase text-success fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                    <span><i class="fas fa-check-circle me-1 text-success"></i> Ready for Billing</span>
                    <span class="badge bg-success-subtle text-success" style="background: rgba(16,185,129,0.18);"><?= $readyPosCount ?></span>
                  </li>
                  <?php foreach ($recentReadyPos as $rp): ?>
                    <li>
                      <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="<?= BASE_URL ?>saleslady/pos.php?patient_id=<?= (int)$rp['patient_id'] ?>&appt_id=<?= (int)$rp['id'] ?>">
                        <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(16, 185, 129, 0.15); color: #10B981; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                          <i class="fas fa-cash-register"></i>
                        </div>
                        <div class="flex-grow-1 text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($rp['patient_name']) ?>
                          </div>
                          <small class="text-success d-block text-truncate" style="font-size: 0.74rem;">
                            Exam Done &bull; Ready for POS Billing
                          </small>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($recentLowStock)): ?>
                  <li class="dropdown-header text-uppercase text-danger fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                    <span><i class="fas fa-boxes-stacked me-1"></i> Low Stock Alerts</span>
                    <span class="badge bg-danger-soft text-danger" style="background: rgba(239,68,68,0.12);"><?= $lowStockCount ?></span>
                  </li>
                  <?php foreach ($recentLowStock as $item): ?>
                    <li>
                      <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="<?= $headerInventoryLink ?>?highlight=<?= (int)$item['id'] ?>">
                        <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                          <i class="fas fa-triangle-exclamation"></i>
                        </div>
                        <div class="flex-grow-1 text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($item['name'] . ($item['variant_name'] ? ' — ' . $item['variant_name'] : '')) ?>
                          </div>
                          <div style="font-size: 0.74rem; color: #EF4444; font-weight: 600;">
                            Only <?= (int)$item['stock_quantity'] ?> left <span class="text-muted fw-normal">(Threshold &le; <?= (int)$item['low_stock_alert'] ?>)</span>
                          </div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($recentAppts)): ?>
                  <li class="dropdown-header text-uppercase text-primary fw-bold d-flex align-items-center justify-content-between px-3 pt-2 pb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                    <span><i class="fas fa-calendar-check me-1"></i> Appointments</span>
                    <span class="badge bg-primary-soft text-primary" style="background: rgba(0,173,239,0.12);"><?= $notifCount ?></span>
                  </li>
                  <?php foreach ($recentAppts as $appt): ?>
                    <li>
                      <a class="dropdown-item px-3 py-2 d-flex align-items-start gap-2 border-bottom border-light" href="<?= $headerApptsLink ?>?highlight=<?= (int)$appt['id'] ?>">
                        <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(0, 173, 239, 0.12); color: var(--clr-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.82rem; margin-top: 2px;">
                          <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="flex-grow-1 text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($appt['patient_name']) ?>
                          </div>
                          <small class="text-muted d-block text-truncate" style="font-size: 0.74rem;">
                            <?= date('M d, Y', strtotime($appt['appointment_date'])) ?> at <?= date('h:i A', strtotime($appt['appointment_time'])) ?>
                            <?php if (!empty($appt['purpose'])): ?>
                              &bull; <span class="text-capitalize"><?= str_replace('_', ' ', sanitize($appt['purpose'])) ?></span>
                            <?php endif; ?>
                          </small>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <li class="p-2 border-top d-flex flex-column gap-1" style="background: var(--bg-table-head);">
              <button type="button" class="dropdown-item text-center rounded py-2 fw-bold text-primary border-0 shadow-none" style="font-size: 0.82rem; background: var(--bg-card); cursor: pointer;" onclick="openModal('allNotificationsModal')">
                <i class="fas fa-bell me-1"></i> View Notifications
              </button>
              <div class="d-flex justify-content-between px-1 pt-1" style="font-size: 0.74rem;">
                <a class="text-muted text-decoration-none" href="<?= $headerApptsLink ?>" title="Go to Appointments Queue">
                  <i class="fas fa-calendar-alt me-1"></i> Appointments
                </a>
                <?php if (in_array($currentUserRole, ['admin', 'saleslady'])): ?>
                  <a class="<?= $lowStockCount > 0 ? 'text-danger fw-semibold' : 'text-muted' ?> text-decoration-none" href="<?= $headerInventoryLink ?>" title="Go to Inventory">
                    <i class="fas fa-boxes-stacked me-1"></i> Inventory <?= $lowStockCount > 0 ? "($lowStockCount)" : "" ?>
                  </a>
                <?php endif; ?>
              </div>
            </li>
          </ul>
        </div>

        <!-- User Initials / Profile Avatar -->
        <div class="header-avatar" title="<?= sanitize($fullName) ?>">
          <?= $initials ?>
        </div>
      </div>
    </header>

    <!-- ── All Notifications Modal ────────────────────────────── -->
    <div class="modal-overlay" id="allNotificationsModal" style="z-index: 105000;">
      <div class="modal-box" style="max-width: 620px; max-height: 85vh; display: flex; flex-direction: column;">
        <div class="modal-header d-flex align-items-center justify-content-between p-3 border-bottom" style="background: var(--bg-table-head);">
          <div class="d-flex align-items-center gap-2">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(0, 173, 239, 0.12); color: var(--clr-primary); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
              <i class="fas fa-bell"></i>
            </div>
            <div>
              <h6 class="mb-0 fw-bold" style="color: var(--text-primary);">Notifications Center</h6>
              <small class="text-muted" style="font-size: 0.74rem;">All active appointments and inventory alerts</small>
            </div>
          </div>
          <button type="button" class="btn-close" onclick="closeModal('allNotificationsModal')" aria-label="Close"></button>
        </div>
        <div class="modal-body p-3 custom-scroll" id="allNotificationsModalBody" style="overflow-y: auto; flex: 1;">
          <?php if (empty($recentAppts) && empty($recentLowStock)): ?>
            <div class="text-center py-5 text-muted">
              <i class="fas fa-bell-slash mb-3" style="font-size: 2.5rem; opacity: 0.35;"></i>
              <h6 class="fw-bold">No active notifications</h6>
              <p class="small mb-0">All appointments are handled and inventory stock levels are healthy.</p>
            </div>
          <?php else: ?>
            <?php if (!empty($recentLowStock)): ?>
              <div class="mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="fw-bold text-danger text-uppercase small" style="letter-spacing: 0.5px;">
                    <i class="fas fa-boxes-stacked me-1"></i> Low Stock Alerts (<?= $lowStockCount ?>)
                  </span>
                  <a href="<?= $headerInventoryLink ?>" class="small text-danger fw-semibold text-decoration-none">Manage Inventory &rarr;</a>
                </div>
                <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                  <?php foreach ($recentLowStock as $item): ?>
                    <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                      <div class="d-flex align-items-center gap-2 text-truncate me-2">
                        <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                          <i class="fas fa-triangle-exclamation"></i>
                        </div>
                        <div class="text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($item['name'] . ($item['variant_name'] ? ' — ' . $item['variant_name'] : '')) ?>
                          </div>
                          <small class="text-muted" style="font-size: 0.72rem;">Alert threshold: &le; <?= (int)$item['low_stock_alert'] ?> units</small>
                        </div>
                      </div>
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger-soft text-danger fw-bold px-2 py-1" style="background: rgba(239, 68, 68, 0.12); font-size: 0.75rem; white-space: nowrap;">
                          Only <?= (int)$item['stock_quantity'] ?> left
                        </span>
                        <a href="<?= $headerInventoryLink ?>?highlight=<?= (int)$item['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.74rem;">
                          View
                        </a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!empty($recentAppts)): ?>
              <div class="mb-2">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="fw-bold text-primary text-uppercase small" style="letter-spacing: 0.5px;">
                    <i class="fas fa-calendar-check me-1"></i> Pending Appointments (<?= $notifCount ?>)
                  </span>
                  <a href="<?= $headerApptsLink ?>" class="small text-primary fw-semibold text-decoration-none">Open Queue &rarr;</a>
                </div>
                <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                  <?php foreach ($recentAppts as $appt): ?>
                    <div class="list-group-item d-flex align-items-center justify-content-between py-2 px-3">
                      <div class="d-flex align-items-center gap-2 text-truncate me-2">
                        <div style="width: 28px; height: 28px; border-radius: 8px; background: rgba(0, 173, 239, 0.12); color: var(--clr-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">
                          <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="text-truncate">
                          <div class="fw-bold text-truncate" style="font-size: 0.83rem; color: var(--text-primary);">
                            <?= sanitize($appt['patient_name']) ?>
                          </div>
                          <small class="text-muted" style="font-size: 0.72rem;">
                            <?= date('M d, Y', strtotime($appt['appointment_date'])) ?> at <?= date('h:i A', strtotime($appt['appointment_time'])) ?>
                            <?php if (!empty($appt['purpose'])): ?>
                              &bull; <span class="text-capitalize"><?= str_replace('_', ' ', sanitize($appt['purpose'])) ?></span>
                            <?php endif; ?>
                          </small>
                        </div>
                      </div>
                      <a href="<?= $headerApptsLink ?>?highlight=<?= (int)$appt['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.74rem;">
                        View
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="modal-footer p-2 border-top d-flex align-items-center justify-content-between" style="background: var(--bg-table-head);">
          <div class="d-flex gap-2">
            <a href="<?= $headerApptsLink ?>" class="btn btn-sm btn-outline-primary" style="font-size: 0.78rem;">
              <i class="fas fa-calendar-alt me-1"></i> Appointment Queue
            </a>
            <?php if (in_array($currentUserRole, ['admin', 'saleslady'])): ?>
              <a href="<?= $headerInventoryLink ?>" class="btn btn-sm btn-outline-danger" style="font-size: 0.78rem;">
                <i class="fas fa-boxes-stacked me-1"></i> Inventory
              </a>
            <?php endif; ?>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" onclick="closeModal('allNotificationsModal')">Close</button>
        </div>
      </div>
    </div>

    <!-- PAGE CONTENT starts here -->
    <div class="page-content">