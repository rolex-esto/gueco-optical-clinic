<?php
// ============================================================
// STAFF MANAGEMENT PORTAL LOGIN
// Gueco Optical Clinic Management System
// Redesigned with Modern SaaS Dashboard Aesthetic (Savsass Ref)
// ============================================================

define('BASE_URL', '');
require_once 'config/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl($_SESSION['user_role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlKey = 'staff_login_' . $ip;

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } elseif (!checkRateLimit($rlKey, 5, 900)) {
        $remaining = ceil(getRateLimitRemainingSeconds($rlKey) / 60);
        $error = "Too many failed attempts. Please wait {$remaining} minute(s) before trying again.";
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            try {
                $db   = getDB();
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    clearRateLimit($rlKey);
                    session_regenerate_id(true);

                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['full_name'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['user_email'] = $user['email'];

                    logActivity('Login', 'Auth', $user['id']);
                    header('Location: ' . getDashboardUrl($user['role']));
                    exit;
                } else {
                    recordFailedAttempt($rlKey, 900);
                    $error = 'Invalid email or password. Please try again.';
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again later.';
            }
        }
    }
}
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'light');
$currentTheme = ($userTheme === 'dark') ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Portal Login — Gueco Optical Clinic</title>
  <meta name="description" content="Gueco Optical Clinic Centralized Staff Management Portal">

  <!-- Immediate Theme Initialization & Caret Browsing Prevention -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem("gueco_theme") || localStorage.getItem("gueco-theme") || localStorage.getItem("theme") || localStorage.getItem("guecoTheme");
        if (!theme) {
          var m = document.cookie.match(/(?:^|;\s*)gueco_theme=([^;]+)/);
          theme = m ? m[1] : "<?= $currentTheme ?>";
        }
        if (theme !== "light" && theme !== "dark") theme = "light";
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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* Universal Caret & Text-Selection Prevention */
    *, *::before, *::after {
      caret-color: transparent;
    }

    body, h1, h2, h3, h4, h5, h6, p, span, div, a, label, li, ul, ol, section, main, header, footer, nav,
    button, [type="button"], [type="reset"], [type="submit"], .btn, .theme-btn, .card {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }

    h1, h2, h3, h4, h5, h6, p, label, .card {
      cursor: default;
    }

    button, [type="button"], [type="reset"], [type="submit"], .btn, a, .theme-btn {
      cursor: pointer;
    }

    input, textarea, [contenteditable="true"], .allow-select {
      -webkit-user-select: text !important;
      -moz-user-select: text !important;
      -ms-user-select: text !important;
      user-select: text !important;
      cursor: text !important;
      caret-color: auto !important;
    }

    /* ─── Color Palette (Savsass SaaS Reference Inspired) ─── */
    :root {
      --brand-indigo:       #6366F1;
      --brand-indigo-dark:  #4F46E5;
      --brand-teal:         #14B8A6;
      --brand-cyan:         #06B6D4;
      --brand-mint:         #10B981;
      --brand-rose:         #F43F5E;
      --brand-amber:        #F59E0B;
      --brand-purple:       #8B5CF6;
    }

    /* LIGHT THEME (Matches clean dashboard screenshot) */
    [data-theme="light"] {
      --bg-page:            #F8F9FD;
      --bg-surface:         #FFFFFF;
      --bg-surface-elev:    #FFFFFF;
      --bg-subtle:          #F1F3F9;
      --bg-input:           #F8FAFC;
      --text-main:          #0F172A;
      --text-muted:         #64748B;
      --text-subtle:        #94A3B8;
      --border-subtle:      #E2E8F0;
      --border-strong:      #CBD5E1;
      --border-focus:       #6366F1;
      --shadow-sm:          0 1px 3px rgba(15, 23, 42, 0.05);
      --shadow-md:          0 4px 20px -2px rgba(15, 23, 42, 0.06);
      --shadow-card:        0 12px 36px -4px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(226, 232, 240, 0.8);
      --badge-pos-bg:       #ECFDF5;
      --badge-pos-text:     #059669;
      --badge-neg-bg:       #FFF1F2;
      --badge-neg-text:     #E11D48;
      --bar-purple:         #6366F1;
      --bar-teal:           #14B8A6;
      --grid-dot:           rgba(15, 23, 42, 0.04);
      --btn-primary-bg:     #0F172A;
      --btn-primary-text:   #FFFFFF;
      --btn-primary-hover:  #1E293B;
    }

    /* DARK THEME (High-legibility modern dark mode) */
    [data-theme="dark"] {
      --bg-page:            #0B0F19;
      --bg-surface:         #121826;
      --bg-surface-elev:    #182234;
      --bg-subtle:          #162032;
      --bg-input:           #0E1422;
      --text-main:          #F8FAFC;
      --text-muted:         #94A3B8;
      --text-subtle:        #64748B;
      --border-subtle:      rgba(255, 255, 255, 0.08);
      --border-strong:      rgba(255, 255, 255, 0.16);
      --border-focus:       #818CF8;
      --shadow-sm:          0 1px 3px rgba(0, 0, 0, 0.3);
      --shadow-md:          0 8px 24px -4px rgba(0, 0, 0, 0.4);
      --shadow-card:        0 20px 50px -10px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.08);
      --badge-pos-bg:       rgba(16, 185, 129, 0.15);
      --badge-pos-text:     #34D399;
      --badge-neg-bg:       rgba(244, 63, 94, 0.15);
      --badge-neg-text:     #FB7185;
      --bar-purple:         #818CF8;
      --bar-teal:           #2DD4BF;
      --grid-dot:           rgba(255, 255, 255, 0.03);
      --btn-primary-bg:     #6366F1;
      --btn-primary-text:   #FFFFFF;
      --btn-primary-hover:  #4F46E5;
    }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: var(--bg-page);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Ambient Subtle Grid Pattern */
    .bg-canvas-pattern {
      position: fixed;
      inset: 0;
      background-image: radial-gradient(var(--grid-dot) 1px, transparent 1px);
      background-size: 24px 24px;
      pointer-events: none;
      z-index: 0;
    }

    /* Ambient Soft Glows */
    .ambient-glow {
      position: fixed;
      border-radius: 50%;
      filter: blur(100px);
      pointer-events: none;
      z-index: 0;
      opacity: 0.5;
    }
    .glow-purple {
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.12) 0%, transparent 70%);
      top: -100px;
      left: 10%;
    }
    .glow-teal {
      width: 450px;
      height: 450px;
      background: radial-gradient(circle, rgba(20, 184, 166, 0.10) 0%, transparent 70%);
      bottom: -100px;
      right: 15%;
    }

    /* ─── Top Navigation Header ─── */
    .portal-nav {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 1440px;
      margin: 0 auto;
      padding: 24px 40px 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .brand-link {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--text-main);
    }
    .brand-logo-icon {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      background: #FFFFFF;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
      border: 1px solid var(--border-subtle);
    }
    .brand-logo-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
    .brand-text-wrap {
      display: flex;
      flex-direction: column;
    }
    .brand-name {
      font-size: 1.15rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      line-height: 1.2;
    }
    .brand-pill {
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--brand-indigo);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .brand-pill-dot {
      width: 6px;
      height: 6px;
      background: var(--brand-indigo);
      border-radius: 50%;
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .btn-portal-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-muted);
      text-decoration: none;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      transition: all 0.2s ease;
      box-shadow: var(--shadow-sm);
    }
    .btn-portal-back:hover {
      color: var(--brand-indigo);
      border-color: var(--brand-indigo);
      transform: translateY(-1px);
    }

    .theme-toggle-btn {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      cursor: pointer;
      box-shadow: var(--shadow-sm);
      transition: all 0.2s ease;
    }
    .theme-toggle-btn:hover {
      border-color: var(--brand-indigo);
      color: var(--brand-indigo);
      transform: translateY(-1px);
    }

    /* ─── Main Content Container (Two Column Split) ─── */
    .portal-main {
      position: relative;
      z-index: 2;
      flex: 1;
      width: 100%;
      max-width: 1440px;
      margin: 0 auto;
      padding: 20px 40px 40px;
      display: grid;
      grid-template-columns: 1.25fr 0.95fr;
      align-items: center;
      gap: 48px;
    }

    /* ─── Left Side: The SaaS Dashboard Showcase ─── */
    .showcase-area {
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .hero-heading-group {
      max-width: 620px;
    }
    .hero-badge-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 14px;
      border-radius: 9999px;
      background: var(--badge-pos-bg);
      color: var(--badge-pos-text);
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      margin-bottom: 16px;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .hero-badge-tag i {
      font-size: 0.7rem;
    }
    .hero-title {
      font-size: clamp(2.2rem, 3.8vw, 3.2rem);
      font-weight: 800;
      line-height: 1.15;
      letter-spacing: -0.03em;
      color: var(--text-main);
      margin-bottom: 14px;
    }
    .hero-title-gradient {
      background: linear-gradient(135deg, #6366F1 0%, #14B8A6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
    }
    .hero-subtitle {
      font-size: 1rem;
      color: var(--text-muted);
      line-height: 1.6;
      margin-bottom: 0;
    }

    /* ─── The Savsass-Inspired Dashboard Window Mockup ─── */
    .dashboard-preview-window {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 20px;
      box-shadow: var(--shadow-card);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      transition: all 0.3s ease;
    }

    /* Window Top Header Bar */
    .preview-header {
      padding: 12px 18px;
      background: var(--bg-subtle);
      border-bottom: 1px solid var(--border-subtle);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .window-dots {
      display: flex;
      gap: 6px;
    }
    .window-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }
    .dot-red   { background: #FF5F56; }
    .dot-amber { background: #FFBD2E; }
    .dot-green { background: #27C93F; }

    .preview-header-center {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--text-muted);
    }
    .live-pulse-dot {
      width: 7px;
      height: 7px;
      background: var(--brand-mint);
      border-radius: 50%;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
      animation: pulseAnim 2s infinite;
    }
    @keyframes pulseAnim {
      0%, 100% { transform: scale(1); opacity: 1; }
      50%      { transform: scale(1.3); opacity: 0.6; }
    }

    .preview-search-pill {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--bg-surface);
      padding: 4px 10px;
      border-radius: 6px;
      border: 1px solid var(--border-subtle);
      font-size: 0.72rem;
      color: var(--text-subtle);
    }
    .preview-search-pill kbd {
      background: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: 4px;
      padding: 1px 4px;
      font-size: 0.65rem;
      color: var(--text-muted);
    }

    /* Window Body */
    .preview-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    /* 4 Stat Cards Row (Exact Replica from Screenshot) */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
    }
    .stat-card {
      background: var(--bg-surface-elev);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: var(--shadow-sm);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      border-color: var(--brand-indigo);
    }
    .stat-card-top {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .stat-icon-wrap {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    .stat-icon-purple { background: rgba(99, 102, 241, 0.15); color: #6366F1; }
    .stat-icon-rose   { background: rgba(244, 63, 94, 0.15);  color: #F43F5E; }
    .stat-icon-teal   { background: rgba(20, 184, 166, 0.15); color: #14B8A6; }
    .stat-icon-amber  { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }

    .stat-title-label {
      font-size: 0.68rem;
      font-weight: 700;
      color: var(--text-muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
      line-height: 1.2;
    }
    .stat-val-row {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .stat-number {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1;
    }
    .pill-badge {
      display: inline-flex;
      align-items: center;
      gap: 2px;
      font-size: 0.68rem;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 6px;
      line-height: 1.2;
    }
    .pill-badge.pos {
      background: var(--badge-pos-bg);
      color: var(--badge-pos-text);
    }
    .pill-badge.neg {
      background: var(--badge-neg-bg);
      color: var(--badge-neg-text);
    }
    .stat-footer-text {
      font-size: 0.68rem;
      color: var(--text-subtle);
    }

    /* Dual-Tone Chart Area & Live Queue Split */
    .preview-split-row {
      display: grid;
      grid-template-columns: 1.5fr 1fr;
      gap: 14px;
    }

    /* Chart Box (Savsass Bar Graph Replica) */
    .chart-box {
      background: var(--bg-surface-elev);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .chart-box-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .chart-box-title {
      font-size: 0.82rem;
      font-weight: 800;
      color: var(--text-main);
    }
    .chart-legend {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.70rem;
      color: var(--text-muted);
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .legend-color-dot {
      width: 8px;
      height: 8px;
      border-radius: 2px;
    }
    .dot-purple { background: var(--bar-purple); }
    .dot-teal   { background: var(--bar-teal); }

    /* The Simulated Bar Graph */
    .simulated-bars-wrap {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      height: 105px;
      padding-top: 10px;
      gap: 8px;
      border-bottom: 1px dashed var(--border-subtle);
      padding-bottom: 8px;
    }
    .bar-col {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      flex: 1;
      height: 100%;
      justify-content: flex-end;
    }
    .bar-stack {
      width: 100%;
      max-width: 18px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      align-items: center;
      height: 100%;
      justify-content: flex-end;
    }
    .bar-slice {
      width: 100%;
      border-radius: 4px;
      transition: height 0.3s ease;
    }
    .slice-purple {
      background: var(--bar-purple);
      opacity: 0.9;
    }
    .slice-teal {
      background: var(--bar-teal);
    }
    .bar-day-label {
      font-size: 0.65rem;
      font-weight: 600;
      color: var(--text-subtle);
      text-transform: uppercase;
    }

    /* Live Queue Box (Savsass Recent Leads Replica) */
    .queue-box {
      background: var(--bg-surface-elev);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .queue-box-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .queue-box-title {
      font-size: 0.82rem;
      font-weight: 800;
      color: var(--text-main);
    }
    .queue-box-sub {
      font-size: 0.70rem;
      font-weight: 700;
      color: var(--brand-indigo);
    }
    .queue-list {
      display: flex;
      flex-direction: column;
      gap: 9px;
    }
    .queue-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 6px 8px;
      border-radius: 8px;
      background: var(--bg-subtle);
    }
    .queue-avatar {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.70rem;
      font-weight: 700;
      color: #FFFFFF;
      flex-shrink: 0;
    }
    .qa-blue   { background: #3B82F6; }
    .qa-purple { background: #8B5CF6; }
    .qa-teal   { background: #14B8A6; }

    .queue-info {
      flex: 1;
      min-width: 0;
    }
    .queue-name {
      font-size: 0.74rem;
      font-weight: 700;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .queue-desc {
      font-size: 0.65rem;
      color: var(--text-subtle);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .queue-badge {
      font-size: 0.64rem;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 4px;
      white-space: nowrap;
    }
    .qb-exam  { background: rgba(99, 102, 241, 0.15); color: var(--brand-indigo); }
    .qb-claim { background: rgba(16, 185, 129, 0.15); color: var(--brand-mint); }
    .qb-check { background: rgba(245, 158, 11, 0.15); color: var(--brand-amber); }

    /* Bottom Capability Pills */
    .capabilities-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .cap-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 8px;
      font-size: 0.74rem;
      font-weight: 600;
      color: var(--text-muted);
    }
    .cap-pill i {
      color: var(--brand-indigo);
      font-size: 0.75rem;
    }

    /* ─── Right Side: Modern SaaS Staff Login Card ─── */
    .login-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
    }

    .login-card {
      width: 100%;
      max-width: 460px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: 24px;
      padding: 40px 36px;
      box-shadow: var(--shadow-card);
      position: relative;
      overflow: hidden;
      transition: all 0.3s ease;
    }

    /* Top Accent Line */
    .login-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #6366F1, #14B8A6, #8B5CF6);
    }

    /* Card Top Header */
    .card-top-header {
      margin-bottom: 28px;
      text-align: left;
    }
    .card-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 9999px;
      background: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      font-size: 0.74rem;
      font-weight: 700;
      color: var(--text-muted);
      margin-bottom: 16px;
    }
    .card-badge-pill i {
      color: var(--brand-indigo);
    }
    .card-title-text {
      font-size: 1.75rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--text-main);
      margin-bottom: 6px;
    }
    .card-subtitle-text {
      font-size: 0.88rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    /* Error Alert Box */
    .auth-alert-error {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      background: rgba(239, 68, 68, 0.10);
      border: 1px solid rgba(239, 68, 68, 0.25);
      border-radius: 12px;
      padding: 12px 16px;
      margin-bottom: 22px;
      font-size: 0.85rem;
      color: #EF4444;
      line-height: 1.4;
      animation: alertSlide 0.25s ease;
    }
    @keyframes alertSlide {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Form Fields */
    .form-group-wrap {
      margin-bottom: 20px;
    }
    .form-label-custom {
      display: block;
      font-size: 0.80rem;
      font-weight: 700;
      color: var(--text-muted);
      margin-bottom: 8px;
      letter-spacing: 0.01em;
    }
    .input-field-relative {
      position: relative;
    }
    .input-field-icon {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-subtle);
      font-size: 0.95rem;
      pointer-events: none;
      transition: color 0.2s ease;
    }
    .input-control-custom {
      width: 100%;
      background: var(--bg-input);
      border: 1px solid var(--border-subtle);
      border-radius: 12px;
      padding: 13px 44px;
      font-family: inherit;
      font-size: 0.92rem;
      color: var(--text-main);
      outline: none;
      transition: all 0.2s ease;
    }
    .input-control-custom::placeholder {
      color: var(--text-subtle);
    }
    .input-control-custom:focus {
      background: var(--bg-surface);
      border-color: var(--border-focus);
      box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14);
    }
    .input-control-custom:focus ~ .input-field-icon,
    .input-field-relative:has(.input-control-custom:focus) .input-field-icon {
      color: var(--brand-indigo);
    }

    /* Password Eye Reveal */
    .password-eye-btn {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--text-subtle);
      cursor: pointer;
      font-size: 0.92rem;
      padding: 4px 6px;
      transition: color 0.2s ease;
    }
    .password-eye-btn:hover {
      color: var(--text-main);
    }

    /* Primary Submit Button */
    .btn-submit-login {
      width: 100%;
      padding: 14px 24px;
      background: var(--btn-primary-bg);
      color: var(--btn-primary-text);
      font-family: inherit;
      font-size: 0.95rem;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 26px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .btn-submit-login:hover {
      background: var(--btn-primary-hover);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.20);
    }
    .btn-submit-login:hover i.fa-arrow-right {
      transform: translateX(4px);
    }
    .btn-submit-login:active {
      transform: translateY(0);
    }
    .btn-submit-login i.fa-arrow-right {
      transition: transform 0.2s ease;
    }

    /* Loading state */
    @keyframes shimmerAnim {
      0%   { background-position: -200% center; }
      100% { background-position: 200% center; }
    }
    .btn-submit-login.loading {
      background: linear-gradient(90deg, #6366F1 25%, #14B8A6 50%, #6366F1 75%);
      background-size: 200% auto;
      color: #FFFFFF;
      animation: shimmerAnim 1.2s linear infinite;
      cursor: wait;
      pointer-events: none;
    }

    /* Card Footer Info */
    .login-card-footer {
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid var(--border-subtle);
      text-align: center;
      font-size: 0.75rem;
      color: var(--text-subtle);
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .footer-secure-tag {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      color: var(--text-muted);
      font-weight: 600;
    }
    .footer-secure-tag i {
      color: var(--brand-mint);
    }

    /* ─── Responsive Breakpoints ─── */
    @media (max-width: 1080px) {
      .portal-main {
        grid-template-columns: 1fr;
        gap: 40px;
        padding: 20px 24px 40px;
      }
      .showcase-area {
        order: 2;
        max-width: 600px;
        margin: 0 auto;
      }
      .login-panel {
        order: 1;
      }
      .stats-row {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 640px) {
      .portal-nav {
        padding: 16px 20px;
      }
      .brand-name {
        font-size: 1rem;
      }
      .btn-portal-back span {
        display: none;
      }
      .portal-main {
        padding: 10px 16px 30px;
      }
      .login-card {
        padding: 30px 22px;
      }
      .stats-row {
        grid-template-columns: 1fr;
      }
      .preview-split-row {
        grid-template-columns: 1fr;
      }
      .hero-title {
        font-size: 1.85rem;
      }
    }
  </style>
</head>
<body>

  <!-- Background Canvas Grid & Ambient Glows -->
  <div class="bg-canvas-pattern"></div>
  <div class="ambient-glow glow-purple"></div>
  <div class="ambient-glow glow-teal"></div>

  <!-- Top Navigation Header -->
  <header class="portal-nav">
    <a href="index.php" class="brand-link" title="Gueco Optical Clinic">
      <div class="brand-logo-icon">
        <img src="<?= htmlspecialchars(getClinicLogoUrl()) ?>" alt="Gueco Optical Logo">
      </div>
      <div class="brand-text-wrap">
        <div class="brand-name">Gueco Optical Clinic</div>
        <div class="brand-pill">
          <span class="brand-pill-dot"></span>
          <span>Staff Management Suite</span>
        </div>
      </div>
    </a>

    <div class="nav-actions">
      <a href="index.php" class="btn-portal-back" title="Go to Patient Website">
        <i class="fas fa-arrow-left"></i>
        <span>Patient Portal</span>
      </a>
      <button class="theme-toggle-btn" id="themeToggleBtn" title="Toggle Theme" aria-label="Toggle theme">
        <i class="fas fa-sun" id="themeIcon"></i>
      </button>
    </div>
  </header>

  <!-- Main Body Section -->
  <main class="portal-main">

    <!-- ======= LEFT SIDE: DASHBOARD SHOWCASE (SAVSASS REFERENCE) ======= -->
    <section class="showcase-area">

      <div class="hero-heading-group">
        <div class="hero-badge-tag">
          <i class="fas fa-shield-check"></i>
          <span>Internal Operating System v2.6</span>
        </div>
        <h1 class="hero-title">
          Precision Vision Care,<br>
          <span class="hero-title-gradient">Intelligent Clinic Operations.</span>
        </h1>
        <p class="hero-subtitle">
          The centralized administrative platform for Gueco Optical Clinic — connecting optometrists, clinic staff, patient queues, prescriptions, inventory, and point of sale into one cohesive system.
        </p>
      </div>

      <!-- Dashboard Window Container -->
      <div class="dashboard-preview-window">

        <!-- Window Mockup Header -->
        <div class="preview-header">
          <div class="window-dots">
            <span class="window-dot dot-red"></span>
            <span class="window-dot dot-amber"></span>
            <span class="window-dot dot-green"></span>
          </div>
          <div class="preview-header-center">
            <span class="live-pulse-dot"></span>
            <span>Live Clinic Telemetry &amp; Operations</span>
          </div>
          <div class="preview-search-pill">
            <i class="fas fa-search"></i>
            <span>Search records</span>
            <kbd>Ctrl+K</kbd>
          </div>
        </div>

        <!-- Window Mockup Body -->
        <div class="preview-body">

          <!-- 4 Savsass Metric Cards -->
          <div class="stats-row">

            <!-- Stat 1: Total Appointments -->
            <div class="stat-card">
              <div class="stat-card-top">
                <div class="stat-icon-wrap stat-icon-purple">
                  <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                  <div class="stat-title-label">Appointments</div>
                </div>
              </div>
              <div class="stat-val-row">
                <span class="stat-number">148</span>
                <span class="pill-badge pos">+24% <i class="fas fa-arrow-up"></i></span>
              </div>
              <div class="stat-footer-text">+18 from yesterday</div>
            </div>

            <!-- Stat 2: Active Patients -->
            <div class="stat-card">
              <div class="stat-card-top">
                <div class="stat-icon-wrap stat-icon-rose">
                  <i class="fas fa-user-group"></i>
                </div>
                <div>
                  <div class="stat-title-label">Patients</div>
                </div>
              </div>
              <div class="stat-val-row">
                <span class="stat-number">1,890</span>
                <span class="pill-badge pos">+12% <i class="fas fa-arrow-up"></i></span>
              </div>
              <div class="stat-footer-text">Verified clinic records</div>
            </div>

            <!-- Stat 3: Prescriptions -->
            <div class="stat-card">
              <div class="stat-card-top">
                <div class="stat-icon-wrap stat-icon-teal">
                  <i class="fas fa-glasses"></i>
                </div>
                <div>
                  <div class="stat-title-label">Rx Records</div>
                </div>
              </div>
              <div class="stat-val-row">
                <span class="stat-number">362</span>
                <span class="pill-badge pos">+94% <i class="fas fa-arrow-up"></i></span>
              </div>
              <div class="stat-footer-text">Digital refractions</div>
            </div>

            <!-- Stat 4: Clinic Revenue -->
            <div class="stat-card">
              <div class="stat-card-top">
                <div class="stat-icon-wrap stat-icon-amber">
                  <i class="fas fa-coins"></i>
                </div>
                <div>
                  <div class="stat-title-label">POS Revenue</div>
                </div>
              </div>
              <div class="stat-val-row">
                <span class="stat-number">&#8369;84,250</span>
                <span class="pill-badge pos">+38% <i class="fas fa-arrow-up"></i></span>
              </div>
              <div class="stat-footer-text">Optical sales &amp; lenses</div>
            </div>

          </div>

          <!-- Dual-Tone Bar Chart & Live Queue Split -->
          <div class="preview-split-row">

            <!-- Chart Box (Savsass Bar Graph Replica) -->
            <div class="chart-box">
              <div class="chart-box-header">
                <div class="chart-box-title">Weekly Patient Inflow &amp; Sales</div>
                <div class="chart-legend">
                  <span class="legend-item">
                    <span class="legend-color-dot dot-purple"></span>
                    <span>Appointments</span>
                  </span>
                  <span class="legend-item">
                    <span class="legend-color-dot dot-teal"></span>
                    <span>Sales (&#8369;)</span>
                  </span>
                </div>
              </div>

              <!-- Visual Dual-Tone CSS/SVG Bars -->
              <div class="simulated-bars-wrap">
                <!-- Mon -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 38px;"></div>
                    <div class="bar-slice slice-purple" style="height: 28px;"></div>
                  </div>
                  <span class="bar-day-label">Mon</span>
                </div>
                <!-- Tue -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 52px;"></div>
                    <div class="bar-slice slice-purple" style="height: 35px;"></div>
                  </div>
                  <span class="bar-day-label">Tue</span>
                </div>
                <!-- Wed -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 44px;"></div>
                    <div class="bar-slice slice-purple" style="height: 30px;"></div>
                  </div>
                  <span class="bar-day-label">Wed</span>
                </div>
                <!-- Thu -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 68px;"></div>
                    <div class="bar-slice slice-purple" style="height: 48px;"></div>
                  </div>
                  <span class="bar-day-label">Thu</span>
                </div>
                <!-- Fri -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 82px;"></div>
                    <div class="bar-slice slice-purple" style="height: 60px;"></div>
                  </div>
                  <span class="bar-day-label">Fri</span>
                </div>
                <!-- Sat -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 94px;"></div>
                    <div class="bar-slice slice-purple" style="height: 72px;"></div>
                  </div>
                  <span class="bar-day-label">Sat</span>
                </div>
                <!-- Sun -->
                <div class="bar-col">
                  <div class="bar-stack">
                    <div class="bar-slice slice-teal" style="height: 64px;"></div>
                    <div class="bar-slice slice-purple" style="height: 42px;"></div>
                  </div>
                  <span class="bar-day-label">Sun</span>
                </div>
              </div>
            </div>

            <!-- Queue Box (Recent Leads Replica) -->
            <div class="queue-box">
              <div class="queue-box-header">
                <div class="queue-box-title">Recent Queue</div>
                <div class="queue-box-sub">Active Now</div>
              </div>

              <div class="queue-list">
                <div class="queue-item">
                  <div class="queue-avatar qa-purple">ES</div>
                  <div class="queue-info">
                    <div class="queue-name">Eduardo Santos</div>
                    <div class="queue-desc">Refraction Exam &bull; Room 1</div>
                  </div>
                  <span class="queue-badge qb-exam">Exam</span>
                </div>

                <div class="queue-item">
                  <div class="queue-avatar qa-teal">MC</div>
                  <div class="queue-info">
                    <div class="queue-name">Maria Cruz</div>
                    <div class="queue-desc">Frame Fitting &bull; Counter 2</div>
                  </div>
                  <span class="queue-badge qb-claim">Claim</span>
                </div>

                <div class="queue-item">
                  <div class="queue-avatar qa-blue">JR</div>
                  <div class="queue-info">
                    <div class="queue-name">John Ramos</div>
                    <div class="queue-desc">Prescription Check &bull; Dr. Gueco</div>
                  </div>
                  <span class="queue-badge qb-check">Waiting</span>
                </div>
              </div>
            </div>

          </div>

        </div>

      </div>

      <!-- Feature Capabilities Row -->
      <div class="capabilities-row">
        <div class="cap-pill"><i class="fas fa-lock"></i> Role-Based Access Control</div>
        <div class="cap-pill"><i class="fas fa-shield-halved"></i> 256-Bit Data Encryption</div>
        <div class="cap-pill"><i class="fas fa-bolt"></i> Real-Time Sync</div>
        <div class="cap-pill"><i class="fas fa-cloud-arrow-up"></i> Auto Cloud Backup</div>
      </div>

    </section>

    <!-- ======= RIGHT SIDE: STAFF LOGIN FORM ======= -->
    <section class="login-panel">
      <div class="login-card">

        <div class="card-top-header">
          <div class="card-badge-pill">
            <i class="fas fa-lock-keyhole"></i>
            <span>Authorized Clinical Access</span>
          </div>
          <h2 class="card-title-text">Welcome back</h2>
          <p class="card-subtitle-text">
            Enter your clinic credentials to log in to the management workspace.
          </p>
        </div>

        <!-- Error Notification -->
        <?php if (!empty($error)): ?>
        <div class="auth-alert-error" role="alert">
          <i class="fas fa-circle-exclamation mt-1"></i>
          <div><?= sanitize($error) ?></div>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php" id="staffLoginForm" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

          <!-- Email Address -->
          <div class="form-group-wrap">
            <label class="form-label-custom" for="staffEmail">Work Email Address</label>
            <div class="input-field-relative">
              <i class="fas fa-envelope input-field-icon"></i>
              <input
                type="email"
                id="staffEmail"
                name="email"
                class="input-control-custom"
                placeholder="name@guecooptical.com"
                value="<?= sanitize($_POST['email'] ?? '') ?>"
                required
                autocomplete="email"
                autofocus>
            </div>
          </div>

          <!-- Password -->
          <div class="form-group-wrap">
            <label class="form-label-custom" for="staffPassword">Password</label>
            <div class="input-field-relative">
              <i class="fas fa-lock input-field-icon"></i>
              <input
                type="password"
                id="staffPassword"
                name="password"
                class="input-control-custom"
                placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                required
                autocomplete="current-password"
                style="padding-right: 46px;">
              <button
                type="button"
                class="password-eye-btn"
                onclick="togglePasswordVisibility()"
                aria-label="Toggle password visibility">
                <i class="fas fa-eye" id="passToggleIcon"></i>
              </button>
            </div>
          </div>

          <!-- Submit Button -->
          <button type="submit" class="btn-submit-login" id="submitLoginBtn">
            <span>Sign In to Workspace</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </form>

        <!-- Card Footer -->
        <div class="login-card-footer">
          <div class="footer-secure-tag">
            <i class="fas fa-circle-check"></i>
            <span>Session Guard &amp; Brute-Force Rate Limiting Active</span>
          </div>
          <div>
            &copy; <?= date('Y') ?> Gueco Optical Clinic &bull; Capas, Tarlac
          </div>
        </div>

      </div>
    </section>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Theme Management
    const htmlElement = document.documentElement;
    const themeBtn    = document.getElementById('themeToggleBtn');
    const themeIcon   = document.getElementById('themeIcon');

    function applyPortalTheme(theme) {
      if (theme !== 'light' && theme !== 'dark') theme = 'light';
      htmlElement.setAttribute('data-theme', theme);

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

    const initialTheme = localStorage.getItem('gueco_theme') 
                      || localStorage.getItem('gueco-theme') 
                      || localStorage.getItem('theme') 
                      || '<?= $currentTheme ?>';
    applyPortalTheme(initialTheme);

    if (themeBtn) {
      themeBtn.addEventListener('click', () => {
        const curTheme  = htmlElement.getAttribute('data-theme') || 'light';
        const nextTheme = (curTheme === 'dark') ? 'light' : 'dark';
        applyPortalTheme(nextTheme);
      });
    }

    // Toggle Password Visibility
    function togglePasswordVisibility() {
      const passInput = document.getElementById('staffPassword');
      const passIcon  = document.getElementById('passToggleIcon');
      if (passInput.type === 'password') {
        passInput.type = 'text';
        passIcon.className = 'fas fa-eye-slash';
      } else {
        passInput.type = 'password';
        passIcon.className = 'fas fa-eye';
      }
    }

    // Submit Loading State
    const loginForm = document.getElementById('staffLoginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', () => {
        const submitBtn = document.getElementById('submitLoginBtn');
        if (submitBtn) {
          submitBtn.classList.add('loading');
          submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Authenticating credentials...</span>';
        }
      });
    }
  </script>
</body>
</html>