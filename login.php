<?php
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
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Login — Gueco Optical Clinic</title>
  <meta name="description" content="Gueco Optical Clinic Staff Management Portal">

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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ─── Universal Caret & Text-Selection Prevention ─────── */
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

    select {
      -webkit-user-select: auto !important;
      -moz-user-select: auto !important;
      -ms-user-select: auto !important;
      user-select: auto !important;
      cursor: pointer !important;
    }

    :root {
      --clr-bronze-light: #27AAE2;
      --clr-bronze:       #235EAE;
      --clr-bronze-dark:  #272264;
      --clr-gold:         #00ADEF;
      --clr-primary:      #235EAE;
      --clr-accent:       #00ADEF;
      --clr-danger:       #EF4444;
    }

    [data-theme="dark"] {
      --bg-body:       #0A0A0C;
      --bg-card:       rgba(20, 19, 23, 0.72);
      --bg-card-hover: rgba(28, 26, 32, 0.85);
      --bg-input:      rgba(15, 14, 18, 0.7);
      --text-primary:  #F9FAFB;
      --text-muted:    #9CA3AF;
      --text-subtle:   #6B7280;
      --border-color:  rgba(255, 255, 255, 0.08);
      --border-glow:   rgba(0, 173, 239, 0.3);
      --pill-bg:       rgba(255, 255, 255, 0.04);
      --pill-border:   rgba(255, 255, 255, 0.08);
      --badge-bg:      rgba(35, 94, 174, 0.12);
      --badge-border:  rgba(0, 173, 239, 0.25);
      --card-shadow:   0 32px 80px -16px rgba(0,0,0,0.85), 0 0 0 1px rgba(255,255,255,0.06) inset;
    }

    [data-theme="light"] {
      --bg-body:       #F0F4F9;
      --bg-card:       rgba(255, 255, 255, 0.85);
      --bg-card-hover: rgba(255, 255, 255, 0.95);
      --bg-input:      rgba(229, 238, 248, 0.9);
      --text-primary:  #18181B;
      --text-muted:    #71717A;
      --text-subtle:   #A1A1AA;
      --border-color:  rgba(0, 0, 0, 0.08);
      --border-glow:   rgba(35, 94, 174, 0.25);
      --pill-bg:       rgba(255, 255, 255, 0.8);
      --pill-border:   rgba(0, 0, 0, 0.06);
      --badge-bg:      rgba(35, 94, 174, 0.08);
      --badge-border:  rgba(35, 94, 174, 0.2);
      --card-shadow:   0 24px 60px -12px rgba(35, 94, 174, 0.12), 0 0 0 1px rgba(255,255,255,0.8) inset;
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      overflow-x: hidden;
      position: relative;
      transition: background 0.3s ease, color 0.3s ease;
    }

    /* Ambient Blue Luxury Glows */
    .bg-ambient {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      overflow: hidden;
    }
    .glow-1 {
      position: absolute;
      width: 700px;
      height: 700px;
      border-radius: 50%;
      top: -150px;
      left: -100px;
      background: radial-gradient(circle, rgba(0, 173, 239, 0.14) 0%, rgba(39, 34, 100, 0.08) 45%, transparent 70%);
      filter: blur(80px);
      animation: ambientPulse 12s ease-in-out infinite alternate;
    }
    .glow-2 {
      position: absolute;
      width: 650px;
      height: 650px;
      border-radius: 50%;
      bottom: -100px;
      right: 5%;
      background: radial-gradient(circle, rgba(35, 94, 174, 0.14) 0%, rgba(0, 173, 239, 0.06) 40%, transparent 70%);
      filter: blur(90px);
      animation: ambientPulse 15s ease-in-out infinite alternate-reverse;
    }
    .glow-3 {
      position: absolute;
      width: 400px;
      height: 400px;
      border-radius: 50%;
      top: 40%;
      right: 35%;
      background: radial-gradient(circle, rgba(39, 170, 226, 0.06) 0%, transparent 70%);
      filter: blur(60px);
    }
    [data-theme="light"] .glow-1 {
      background: radial-gradient(circle, rgba(0, 173, 239, 0.18) 0%, transparent 70%);
    }
    [data-theme="light"] .glow-2 {
      background: radial-gradient(circle, rgba(35, 94, 174, 0.14) 0%, transparent 70%);
    }

    @keyframes ambientPulse {
      0% { transform: translate(0, 0) scale(1); opacity: 0.8; }
      50% { transform: translate(30px, -20px) scale(1.08); opacity: 1; }
      100% { transform: translate(-20px, 30px) scale(0.95); opacity: 0.75; }
    }

    /* Subtle Star / Sparkle Accents (SS1 inspiration) */
    .sparkle {
      position: absolute;
      color: var(--clr-bronze-light);
      pointer-events: none;
      z-index: 1;
      opacity: 0.75;
      animation: sparkleGlow 4s ease-in-out infinite alternate;
    }
    .sparkle-1 { top: 22%; left: 47%; font-size: 1.4rem; animation-delay: 0.5s; }
    .sparkle-2 { bottom: 25%; left: 49%; font-size: 0.9rem; animation-delay: 1.5s; }
    .sparkle-3 { top: 15%; right: 12%; font-size: 1.1rem; animation-delay: 2.2s; opacity: 0.5; }
    @keyframes sparkleGlow {
      0% { transform: scale(0.85) rotate(0deg); opacity: 0.4; }
      100% { transform: scale(1.15) rotate(15deg); opacity: 0.95; filter: drop-shadow(0 0 8px rgba(253,186,116,0.6)); }
    }

    /* Subtle Grid lines */
    .bg-grid {
      position: fixed;
      inset: 0;
      z-index: 0;
      background-image:
        radial-gradient(circle at 1px 1px, rgba(255,255,255,0.04) 1px, transparent 0);
      background-size: 36px 36px;
      pointer-events: none;
    }
    [data-theme="light"] .bg-grid {
      background-image:
        radial-gradient(circle at 1px 1px, rgba(0,0,0,0.04) 1px, transparent 0);
    }

    /* Page Container */
    .page-wrap {
      position: relative;
      z-index: 2;
      min-height: 100vh;
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      align-items: center;
      max-width: 1440px;
      margin: 0 auto;
      padding: 40px 60px;
      gap: 40px;
    }
    @media (max-width: 992px) {
      .page-wrap {
        grid-template-columns: 1fr;
        padding: 40px 24px;
        gap: 48px;
      }
      .left-panel { text-align: center; }
      .clinic-badge { margin: 0 auto 28px !important; }
      .feature-grid { max-width: 520px; margin: 0 auto; }
      .left-subtitle { margin-left: auto; margin-right: auto; }
      .sparkle { display: none; }
    }

    /* ================= LEFT PANEL ================= */
    .left-panel {
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding-right: 20px;
    }

    /* Clinic Top Badge */
    .clinic-badge {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      background: var(--badge-bg);
      border: 1px solid var(--badge-border);
      border-radius: 9999px;
      padding: 7px 18px 7px 8px;
      margin-bottom: 32px;
      width: fit-content;
      backdrop-filter: blur(16px);
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .clinic-badge:hover {
      transform: translateY(-1px);
      border-color: rgba(0, 173, 239, 0.45);
    }
    .clinic-badge-dot {
      width: 32px; height: 32px;
      background: #FFFFFF;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      padding: 3px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .clinic-badge-dot img {
      width: 100%; height: 100%;
      object-fit: contain;
    }
    .clinic-badge span {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-primary);
      letter-spacing: 0.02em;
    }

    /* Hero Typography */
    .left-title {
      font-size: clamp(2.5rem, 4.5vw, 3.8rem);
      font-weight: 800;
      line-height: 1.12;
      letter-spacing: -0.03em;
      margin-bottom: 20px;
      color: var(--text-primary);
    }
    .gradient-word {
      background: linear-gradient(135deg, #00ADEF 0%, #27AAE2 50%, #235EAE 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
      position: relative;
    }
    [data-theme="light"] .gradient-word {
      background: linear-gradient(135deg, #235EAE 0%, #1E74BD 50%, #272264 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .left-subtitle {
      font-size: 1.05rem;
      color: var(--text-muted);
      margin-bottom: 40px;
      line-height: 1.65;
      max-width: 520px;
      font-weight: 400;
    }

    /* Feature Pills Grid (Refined Dark Glass Look) */
    .feature-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    .feature-pill {
      display: flex;
      align-items: center;
      gap: 14px;
      background: var(--pill-bg);
      border: 1px solid var(--pill-border);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 18px;
      padding: 16px 18px;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
    }
    .feature-pill::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(0, 173, 239, 0.08), transparent);
      opacity: 0;
      transition: opacity 0.25s ease;
    }
    .feature-pill:hover {
      transform: translateY(-3px);
      border-color: rgba(0, 173, 239, 0.35);
      background: var(--bg-card-hover);
      box-shadow: 0 12px 30px -10px rgba(0,0,0,0.3);
    }
    .feature-pill:hover::before { opacity: 1; }

    .fp-icon {
      width: 42px; height: 42px;
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
      transition: transform 0.25s ease;
    }
    .feature-pill:hover .fp-icon { transform: scale(1.08); }

    .fp-icon.bronze { background: rgba(0, 173, 239, 0.15); color: #00ADEF; border: 1px solid rgba(0, 173, 239, 0.25); }
    .fp-icon.gold   { background: rgba(35, 94, 174, 0.15);  color: #27AAE2; border: 1px solid rgba(35, 94, 174, 0.25); }
    .fp-icon.amber  { background: rgba(30, 116, 189, 0.15); color: #1E74BD; border: 1px solid rgba(30, 116, 189, 0.25); }
    .fp-icon.warm   { background: rgba(39, 34, 100, 0.15);  color: #268FC8; border: 1px solid rgba(39, 34, 100, 0.25); }

    .fp-label { font-size: 0.86rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em; }
    .fp-desc  { font-size: 0.74rem; color: var(--text-muted); margin-top: 2px; }


    /* ================= RIGHT PANEL / LOGIN CARD ================= */
    .right-panel {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
    }

    .login-card {
      width: 100%;
      max-width: 440px;
      background: var(--bg-card);
      backdrop-filter: blur(32px);
      -webkit-backdrop-filter: blur(32px);
      border: 1px solid var(--border-color);
      border-radius: 28px;
      padding: 44px 38px;
      box-shadow: var(--card-shadow);
      position: relative;
      overflow: hidden;
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    /* Soft top highlight sheen */
    .login-card::before {
      content: '';
      position: absolute;
      top: 0; left: 15%; right: 15%;
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(253, 186, 116, 0.4), transparent);
    }

    .card-header-area {
      margin-bottom: 30px;
    }
    .card-logo-wrap {
      width: 60px; height: 60px;
      background: #FFFFFF;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      padding: 6px;
      margin-bottom: 22px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.8);
    }
    .card-logo-wrap img {
      width: 100%; height: 100%;
      object-fit: contain;
    }
    .card-title {
      font-size: 1.7rem;
      font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.02em;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-subtitle {
      font-size: 0.88rem;
      color: var(--text-muted);
    }

    /* Form Fields */
    .field-wrap { margin-bottom: 20px; }
    .field-label {
      display: block;
      font-size: 0.76rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 8px;
    }
    .field-input-wrap { position: relative; }
    .field-icon {
      position: absolute;
      left: 18px; top: 50%;
      transform: translateY(-50%);
      color: var(--text-subtle);
      font-size: 0.9rem;
      pointer-events: none;
      transition: color 0.2s ease;
    }
    .field-control {
      width: 100%;
      background: var(--bg-input);
      border: 1px solid var(--border-color);
      border-radius: 14px;
      padding: 14px 48px;
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      font-size: 0.92rem;
      color: var(--text-primary);
      transition: all 0.2s ease;
      outline: none;
    }
    .field-control::placeholder { color: var(--text-subtle); }
    .field-control:focus {
      border-color: var(--clr-bronze);
      background: var(--bg-card-hover);
      box-shadow: 0 0 0 4px rgba(0, 173, 239, 0.18);
    }
    .field-control:focus ~ .field-icon,
    .field-input-wrap:has(.field-control:focus) .field-icon {
      color: var(--clr-bronze-light);
    }
    .pass-eye {
      position: absolute;
      right: 16px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      color: var(--text-subtle); cursor: pointer;
      font-size: 0.9rem; padding: 4px 6px;
      transition: color 0.2s ease;
    }
    .pass-eye:hover { color: var(--clr-bronze-light); }

    /* Error alert */
    .err-box {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.25);
      border-radius: 12px;
      padding: 12px 16px;
      margin-bottom: 22px;
      font-size: 0.84rem;
      color: #F87171;
      animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Submit Button (Pill style matching SS1 "Get Started" / "Sign In") */
    .btn-login {
      width: 100%;
      padding: 15px 24px;
      background: #FFFFFF;
      color: #0A0A0C;
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      font-size: 0.95rem;
      font-weight: 700;
      border: none;
      border-radius: 9999px;
      cursor: pointer;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      position: relative;
      overflow: hidden;
      margin-top: 10px;
      box-shadow: 0 4px 20px rgba(255, 255, 255, 0.2);
    }
    .btn-login i.fa-arrow-right {
      transition: transform 0.2s ease;
    }
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 30px rgba(255, 255, 255, 0.35);
      background: #FAF8F5;
    }
    .btn-login:hover i.fa-arrow-right {
      transform: translateX(4px);
    }
    .btn-login:active { transform: translateY(0); }

    [data-theme="light"] .btn-login {
      background: #18181B;
      color: #FFFFFF;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }
    [data-theme="light"] .btn-login:hover {
      background: #27272A;
      box-shadow: 0 10px 28px rgba(0, 0, 0, 0.3);
    }

    /* Loading state */
    @keyframes shimmer {
      0%   { background-position: -200% center; }
      100% { background-position: 200% center; }
    }
    .btn-login.loading {
      background: linear-gradient(90deg, #235EAE 25%, #00ADEF 50%, #235EAE 75%);
      background-size: 200% auto;
      color: #FFFFFF;
      animation: shimmer 1.2s linear infinite;
    }

    .card-footer-txt {
      text-align: center;
      margin-top: 24px;
      font-size: 0.75rem;
      color: var(--text-subtle);
    }

    /* Floating Theme Toggle */
    .theme-btn {
      position: fixed;
      top: 24px; right: 28px;
      z-index: 100;
      width: 44px; height: 44px;
      border-radius: 50%;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      backdrop-filter: blur(16px);
      color: var(--text-primary);
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      transition: all 0.2s ease;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
    }
    .theme-btn:hover {
      transform: scale(1.1) rotate(15deg);
      border-color: var(--clr-bronze);
      color: var(--clr-bronze-light);
    }

    /* Hide native Edge password reveal */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
      display: none;
    }
  </style>
</head>
<body>
  <!-- Ambient Luxury Glows -->
  <div class="bg-ambient">
    <div class="glow-1"></div>
    <div class="glow-2"></div>
    <div class="glow-3"></div>
  </div>
  <div class="bg-grid"></div>

  <!-- Subtle Sparkles from Reference -->
  <div class="sparkle sparkle-1">✦</div>
  <div class="sparkle sparkle-2">✧</div>
  <div class="sparkle sparkle-3">✦</div>

  <!-- Theme Toggle -->
  <button class="theme-btn" id="themeToggle" title="Toggle Theme">
    <i class="fas fa-moon" id="themeIcon"></i>
  </button>

  <div class="page-wrap">

    <!-- ======= LEFT PANEL ======= -->
    <div class="left-panel">

      <div class="clinic-badge">
        <div class="clinic-badge-dot">
          <img src="<?= htmlspecialchars(getClinicLogoUrl()) ?>" alt="Logo">
        </div>
        <span>Gueco Optical Clinic — Capas, Tarlac</span>
      </div>

      <h1 class="left-title">
        Modern Clinic<br>
        <span class="gradient-word">Management</span><br>
        System
      </h1>

      <p class="left-subtitle">
        A complete digital solution for Gueco Optical Clinic — streamlining appointments, prescriptions, inventory, and sales in one powerful platform.
      </p>

      <div class="feature-grid">
        <div class="feature-pill">
          <div class="fp-icon bronze"><i class="fas fa-calendar-check"></i></div>
          <div>
            <div class="fp-label">Appointments</div>
            <div class="fp-desc">Smart scheduling</div>
          </div>
        </div>

        <div class="feature-pill">
          <div class="fp-icon gold"><i class="fas fa-glasses"></i></div>
          <div>
            <div class="fp-label">Prescriptions</div>
            <div class="fp-desc">Digital Rx records</div>
          </div>
        </div>

        <div class="feature-pill">
          <div class="fp-icon amber"><i class="fas fa-boxes"></i></div>
          <div>
            <div class="fp-label">Inventory</div>
            <div class="fp-desc">Real-time tracking</div>
          </div>
        </div>

        <div class="feature-pill">
          <div class="fp-icon warm"><i class="fas fa-cash-register"></i></div>
          <div>
            <div class="fp-label">Point of Sale</div>
            <div class="fp-desc">Fast transactions</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ======= RIGHT PANEL ======= -->
    <div class="right-panel">
      <div class="login-card">

        <div class="card-header-area">
          <div class="card-logo-wrap">
            <img src="<?= htmlspecialchars(getClinicLogoUrl()) ?>" alt="Gueco Optical Logo">
          </div>
          <div class="card-title">Welcome back 👋</div>
          <div class="card-subtitle">Enter your credentials to continue</div>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="err-box">
          <i class="fas fa-exclamation-circle"></i>
          <span><?= sanitize($error) ?></span>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="login.php" id="loginForm" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

          <div class="field-wrap">
            <label class="field-label" for="loginEmail">Email Address</label>
            <div class="field-input-wrap">
              <i class="fas fa-envelope field-icon"></i>
              <input
                type="email"
                id="loginEmail"
                name="email"
                class="field-control"
                placeholder="your@email.com"
                value="<?= sanitize($_POST['email'] ?? '') ?>"
                required autocomplete="email">
            </div>
          </div>

          <div class="field-wrap">
            <label class="field-label" for="loginPassword">Password</label>
            <div class="field-input-wrap">
              <i class="fas fa-lock field-icon"></i>
              <input
                type="password"
                id="loginPassword"
                name="password"
                class="field-control"
                placeholder="••••••••"
                required autocomplete="current-password"
                style="padding-right:48px">
              <button type="button" class="pass-eye" onclick="togglePass()" aria-label="Toggle password visibility">
                <i class="fas fa-eye" id="passEyeIcon"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-login" id="loginBtn">
            <span>Sign In</span>
            <i class="fas fa-arrow-right"></i>
          </button>
        </form>

        <p class="card-footer-txt">
          &copy; <?= date('Y') ?> Gueco Optical Clinic &mdash; Capas, Tarlac
        </p>
      </div>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
        // Theme toggle
    const html = document.documentElement;
    const themeBtn  = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');

    function applyLoginTheme(theme) {
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

    const saved = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || '<?= $currentTheme ?>';
    applyLoginTheme(saved);

    if (themeBtn) {
      themeBtn.addEventListener('click', () => {
        const cur = html.getAttribute('data-theme') || 'dark';
        const next = cur === 'dark' ? 'light' : 'dark';
        applyLoginTheme(next);
      });
    }

    // Password toggle
    function togglePass() {
      const inp  = document.getElementById('loginPassword');
      const icon = document.getElementById('passEyeIcon');
      if (inp.type === 'password') { inp.type = 'text';     icon.className = 'fas fa-eye-slash'; }
      else                         { inp.type = 'password'; icon.className = 'fas fa-eye'; }
    }

    // Loading state on submit
    document.getElementById('loginForm').addEventListener('submit', () => {
      const btn = document.getElementById('loginBtn');
      btn.classList.add('loading');
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Signing in...</span>';
    });
  </script>
</body>
</html>