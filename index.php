<?php
// index.php — landing page
define('BASE_URL', '');
require_once 'config/functions.php';
startSession();

$isPatient = isPatient2FAVerified();
$isStaff = isLoggedIn();

$error = '';
$errorField = '';
$tab   = $_GET['tab'] ?? 'login'; // 'login' or 'register'
$showModal = false;

if (isset($_GET['registered']) || isset($_GET['existing']) || (isset($_GET['tab']) && in_array($_GET['tab'], ['login', 'register', 'forgot']))) {
    $showModal = true;
    if (isset($_GET['registered']) || isset($_GET['existing'])) {
        $tab = 'login';
    }
}

// ── REGISTER (Email & Password Only with Real Domain Verification) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $showModal = true;
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
        $tab   = 'register';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $consent  = !empty($_POST['privacy_consent']);

        if (empty($email)) {
            $error = 'Please enter your email address.';
            $errorField = 'email';
            $tab   = 'register';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address (e.g. yourname@gmail.com).';
            $errorField = 'email';
            $tab   = 'register';
        } else {
            $domain = strtolower(substr(strrchr($email, "@"), 1));
            $disallowedDomains = [
                'tempmail.com', 'throwawaymail.com', 'mailinator.com', 
                'guerrillamail.com', '10minutemail.com', 'yopmail.com', 
                'trashmail.com', 'sharklasers.com', 'guerrillamailblock.com',
                'fakemailgenerator.com', 'dispostable.com'
            ];

            if (in_array($domain, $disallowedDomains)) {
                $error = 'Temporary or disposable email addresses are not allowed. Please use your real email or Gmail.';
                $errorField = 'email';
                $tab   = 'register';
            } elseif (in_array($domain, ['gmai.com', 'gmal.com', 'gmial.com', 'gmaill.com', 'gmil.com'])) {
                $error = 'Did you mean @gmail.com? Please check your email spelling.';
                $errorField = 'email';
                $tab   = 'register';
            } elseif (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
                $error = 'We could not reach this email domain. Please provide a real, active email or Gmail address to receive your verification code.';
                $errorField = 'email';
                $tab   = 'register';
            } elseif (empty($password)) {
                $error = 'Please enter a password.';
                $errorField = 'password';
                $tab   = 'register';
            } elseif ($passErr = validatePasswordStrength($password)) {
                $error = $passErr;
                $errorField = 'password';
                $tab   = 'register';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
                $errorField = 'confirm_password';
                $tab   = 'register';
            } elseif (!$consent) {
                $error = 'You must agree to the Terms and Conditions to create an account.';
                $errorField = 'privacy_consent';
                $tab   = 'register';
            } else {
                try {
                    $db = getDB();
                    $check = $db->prepare("SELECT id FROM patients WHERE email = ?");
                    $check->execute([$email]);
                    if ($check->fetch()) {
                        $error = 'This email is already registered. Please log in instead.';
                        $errorField = 'email';
                        $tab   = 'register';
                    } else {
                        ensurePatientSchema($db);

                        // Generate 6-digit OTP
                        $otp = sprintf("%06d", mt_rand(100000, 999999));

                        // Store pending registration securely in session - DO NOT insert into DB yet!
                        $_SESSION['pending_registration'] = [
                            'email'            => strtolower(trim($email)),
                            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
                            'otp_code'         => $otp,
                            'otp_hash'         => password_hash($otp, PASSWORD_DEFAULT),
                            'otp_expires'      => time() + (10 * 60), // 10 minutes
                            'otp_attempts'     => 0,
                            'otp_last_resend'  => time(),
                        ];

                        // Set standard OTP variables for verify-otp.php
                        $_SESSION['patient_otp_code']        = $otp;
                        $_SESSION['patient_otp_hash']        = password_hash($otp, PASSWORD_DEFAULT);
                        $_SESSION['patient_otp_expires']     = time() + (10 * 60);
                        $_SESSION['patient_otp_attempts']    = 0;
                        $_SESSION['patient_otp_last_resend'] = time();
                        $_SESSION['patient_email']           = strtolower(trim($email));
                        $_SESSION['patient_name']            = 'Valued Patient';

                        // Ensure patient is not logged in / verified yet
                        unset(
                            $_SESSION['patient_id'],
                            $_SESSION['patient_avatar'],
                            $_SESSION['patient_2fa_verified'],
                            $_SESSION['patient_id_pending'],
                            $_SESSION['patient_name_pending'],
                            $_SESSION['patient_email_pending'],
                            $_SESSION['patient_avatar_pending']
                        );

                        // Send verification code via email
                        $sent = sendLoginEmailOTP($email, $otp, 'Valued Patient');
                        $_SESSION['patient_otp_sent'] = $sent;

                        $_SESSION['flash_msg']   = 'A 6-digit verification code has been sent to ' . htmlspecialchars($email) . '. Please enter it below to activate your account.';
                        $_SESSION['flash_type']  = 'info';
                        $_SESSION['flash_title'] = 'Verify Your Email';

                        header('Location: verify-otp.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $error = 'Registration failed. Please try again.';
                    $tab   = 'register';
                }
            }
        }
    }
}

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $showModal = true;
    $tab = 'login';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlKey = 'patient_login_' . $ip;

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
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
                ensurePatientSchema($db);
                $stmt = $db->prepare("SELECT * FROM patients WHERE BINARY email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();

                if ($patient && $patient['email'] === $email && password_verify($password, $patient['password'])) {
                    // Defense-in-depth: if account is not yet email verified, require OTP verification
                    if (isset($patient['email_verified']) && (int)$patient['email_verified'] === 0) {
                        issuePatientLoginOTP([
                            'id'        => (int)$patient['id'],
                            'full_name' => $patient['full_name'] ?? '',
                            'email'     => $patient['email'],
                            'avatar'    => $patient['avatar'] ?? ''
                        ]);
                        $_SESSION['flash_msg']   = 'Please verify your email address to access your account.';
                        $_SESSION['flash_type']  = 'warning';
                        $_SESSION['flash_title'] = 'Email Verification Required';
                        header('Location: verify-otp.php');
                        exit;
                    }

                    clearRateLimit($rlKey);
                    session_regenerate_id(true);

                    $_SESSION['patient_id']     = (int)$patient['id'];
                    $_SESSION['patient_name']   = $patient['full_name'];
                    $_SESSION['patient_email']  = $patient['email'];
                    $_SESSION['patient_avatar'] = $patient['avatar'] ?? '';
                    $_SESSION['patient_2fa_verified'] = true;

                    $isComplete = isPatientProfileComplete((int)$patient['id']);

                    // Track login count: if profile is already complete, this is a returning login (bump to at least 2)
                    $currentLogins = (int)($patient['login_count'] ?? 0);
                    $newLogins = $isComplete ? max(2, $currentLogins + 1) : max(1, $currentLogins);

                    $db->prepare("UPDATE patients SET login_count = ?, last_login_at = NOW() WHERE id = ?")
                       ->execute([$newLogins, $patient['id']]);

                    logActivity('Patient Login', 'Auth', (int)$patient['id'], 'patient');

                    // If profile is incomplete, guide them to complete profile; otherwise go directly to dashboard
                    if (!$isComplete) {
                        header('Location: complete-profile.php');
                        exit;
                    }

                    $isFirstLogin = ($newLogins <= 1);
                    $displayName = !empty($patient['first_name']) 
                        ? $patient['first_name'] 
                        : (!empty($patient['full_name']) ? explode(' ', trim($patient['full_name']))[0] : 'Patient');

                    if ($isFirstLogin) {
                        $_SESSION['flash_msg']   = 'Welcome, ' . htmlspecialchars($displayName) . '!';
                        $_SESSION['flash_type']  = 'success';
                        $_SESSION['flash_title'] = 'Welcome!';
                    } else {
                        $_SESSION['flash_msg']   = 'Welcome back, ' . htmlspecialchars($displayName) . '!';
                        $_SESSION['flash_type']  = 'success';
                        $_SESSION['flash_title'] = 'Welcome Back!';
                    }

                    header('Location: patient/dashboard.php');
                    exit;
                } else {
                    recordFailedAttempt($rlKey, 900);
                    $error = 'Invalid email or password.';
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again.';
            }
        }
    }
}

// ── FORGOT PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $showModal = true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlKey = 'patient_otp_req_' . $ip;

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'forgot';
    } elseif (!checkRateLimit($rlKey, 5, 900)) {
        $remaining = ceil(getRateLimitRemainingSeconds($rlKey) / 60);
        $error = "Too many OTP requests. Please wait {$remaining} minute(s) before requesting again.";
        $tab = 'forgot';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        
        if (empty($email)) {
            $error = 'Please enter your email.';
            $tab = 'forgot';
        } else {
            try {
                $db = getDB();

                // Self-healing database schema: ensure reset_otp_hash and reset_expires columns exist
                try {
                    $colStmt = $db->query("SHOW COLUMNS FROM patients");
                    if ($colStmt) {
                        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!in_array('reset_otp_hash', $cols)) {
                            $db->exec("ALTER TABLE patients ADD COLUMN reset_otp_hash VARCHAR(255) NULL");
                        }
                        if (!in_array('reset_expires', $cols)) {
                            $db->exec("ALTER TABLE patients ADD COLUMN reset_expires DATETIME NULL");
                        }
                    }
                } catch (Exception $colEx) {}

                // Case-insensitive email lookup, allowing active or unset status
                $stmt = $db->prepare("SELECT id, full_name, email, status FROM patients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();
                
                if ($patient) {
                    if (($patient['status'] ?? 'active') === 'inactive') {
                        $error = 'This account has been deactivated. Please contact the clinic for assistance.';
                        $tab = 'forgot';
                    } else {
                        $otp = sprintf("%06d", mt_rand(100000, 999999));
                        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        
                        $update = $db->prepare("UPDATE patients SET reset_otp_hash = ?, reset_expires = ? WHERE id = ?");
                        $update->execute([$otpHash, $expires, $patient['id']]);
                        
                        $sent = sendEmailOTP($patient['email'], $otp, $patient['full_name'] ?? '');
                        
                        if ($sent) {
                            clearRateLimit($rlKey);
                            $_SESSION['reset_email'] = $patient['email'];
                            $_SESSION['flash_msg']   = 'A 6-digit verification code has been sent to your Gmail (' . htmlspecialchars($patient['email']) . '). Please check your inbox (and Spam folder).';
                            $_SESSION['flash_type']  = 'success';
                            $tab = 'otp';
                        } else {
                            $error = 'Unable to deliver verification code to your email at this time. Please check your connection or try again shortly.';
                            $tab = 'forgot';
                        }
                    }
                } else {
                    recordFailedAttempt($rlKey, 900);
                    $error = 'We could not find an account associated with this email address.';
                    $tab = 'forgot';
                }
            } catch (Exception $e) {
                error_log("Forgot password exception: " . $e->getMessage());
                $error = 'System error. Please try again.';
                $tab = 'forgot';
            }
        }
    }
}

// ── VERIFY OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'otp') {
    $showModal = true;
    $otp = preg_replace('/[^0-9]/', '', trim($_POST['otp'] ?? ''));
    $email = strtolower(trim($_SESSION['reset_email'] ?? ''));
    $otpKey = 'otp_verify_' . md5($email ?: 'guest');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'otp';
    } elseif (!checkRateLimit($otpKey, 5, 900)) {
        if ($email) {
            $db = getDB();
            $db->prepare("UPDATE patients SET reset_otp_hash = NULL, reset_expires = NULL WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")->execute([$email]);
        }
        $error = 'Too many failed OTP attempts. For your security, this verification code has been invalidated. Please request a new one.';
        $tab = 'forgot';
    } elseif (empty($otp) || strlen($otp) !== 6) {
        $error = 'Please enter the valid 6-digit verification code.';
        $tab = 'otp';
    } elseif (empty($email)) {
        $error = 'Session expired. Please request a new verification code.';
        $tab = 'forgot';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, reset_otp_hash, reset_expires FROM patients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$email]);
            $patient = $stmt->fetch();
            
            if ($patient && !empty($patient['reset_expires']) && $patient['reset_expires'] > date('Y-m-d H:i:s')) {
                if (password_verify($otp, $patient['reset_otp_hash'] ?? '')) {
                    // OTP is valid
                    clearRateLimit($otpKey);
                    $_SESSION['reset_verified'] = true;
                    $tab = 'new-password';
                } else {
                    recordFailedAttempt($otpKey, 900);
                    $error = 'Invalid verification code. Please check your email and try again.';
                    $tab = 'otp';
                }
            } else {
                $error = 'The verification code has expired. Please request a new one.';
                $tab = 'forgot';
            }
        } catch (Exception $e) {
            error_log("Verify OTP exception: " . $e->getMessage());
            $error = 'System error. Please try again.';
            $tab = 'otp';
        }
    }
}

// ── NEW PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new-password') {
    $showModal = true;
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'new-password';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $email    = strtolower(trim($_SESSION['reset_email'] ?? ''));
        
        if (empty($_SESSION['reset_verified'])) {
            $error = 'Please verify your OTP code first.';
            $tab = 'forgot';
        } elseif (empty($password)) {
            $error = 'Please enter a new password.';
            $tab = 'new-password';
        } elseif ($passErr = validatePasswordStrength($password)) {
            $error = $passErr;
            $tab = 'new-password';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
            $tab = 'new-password';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT id FROM patients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();
                
                if ($patient) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $db->prepare("UPDATE patients SET password = ?, reset_otp_hash = NULL, reset_expires = NULL WHERE id = ?");
                    $update->execute([$hash, $patient['id']]);
                    
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_verified']);
                    
                    $_SESSION['flash_msg']  = 'Password reset successfully! You can now sign in with your new password.';
                    $_SESSION['flash_type'] = 'success';
                    
                    $tab = 'login';
                } else {
                    $error = 'Account not found.';
                    $tab = 'forgot';
                }
            } catch (Exception $e) {
                error_log("New password exception: " . $e->getMessage());
                $error = 'System error. Please try again.';
                $tab = 'new-password';
            }
        }
    }
}
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';

// Dynamic site settings & FAQs
$siteSettings = [];
$clinicFaqs = [];
try {
    $idxDb = getDB();
    $sRows = $idxDb->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($sRows)) $siteSettings = $sRows;

    $fRows = $idxDb->query("SELECT question, answer, icon FROM clinic_faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fRows)) $clinicFaqs = $fRows;
} catch (Throwable $e) {}

if (!function_exists('getSiteSetting')) {
    function getSiteSetting(array $settings, string $key, string $default = ''): string {
        return $settings[$key] ?? $default;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gueco Optical Clinic — Vision Care Center</title>
  <meta name="description" content="Book appointments and access professional eye care at Gueco Optical Clinic, Capas, Tarlac.">
  
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
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script>document.documentElement.classList.add("js")</script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    :root { 
      /* Blue Luxury Brand Color Palette */
      --clr-bronze-light: #27AAE2;
      --clr-bronze:       #235EAE;
      --clr-bronze-dark:  #272264;
      --clr-gold:         #00ADEF;
      --clr-amber:        #1E74BD;

      --clr-primary:      #235EAE;
      --clr-primary-light:#00ADEF;
      --clr-primary-dark: #272264;
      --clr-secondary:    #00ADEF;
      
      --clr-success:      #10B981;
      --clr-danger:       #EF4444;
      --clr-warning:      #268FC8;
      --clr-info:         #00ADEF;

      /* Dark Theme */
      --bg-body:          #0A0A0D;
      --bg-card:          #13162B;
      --bg-card-glass:    rgba(19, 22, 43, 0.88);
      --bg-topbar:        rgba(10, 10, 13, 0.85);
      --bg-hover:         rgba(0, 173, 239, 0.08);
      --bg-input:         #1A1D36;
      --bg-input-focus:   #23274A;

      --text-primary:     #F9FAFB;
      --text-secondary:   #E5E7EB;
      --text-muted:       #9CA3AF;
      --text-subtle:      #6B7280;

      --border-color:     rgba(255, 255, 255, 0.09);
      --border-light:     rgba(255, 255, 255, 0.05);
      --border-glow:      rgba(0, 173, 239, 0.35);

      --shadow-sm:        0 2px 8px rgba(0, 0, 0, 0.45);
      --shadow-md:        0 8px 24px rgba(0, 0, 0, 0.55);
      --shadow-lg:        0 16px 36px rgba(0, 0, 0, 0.65);
      --shadow-xl:        0 28px 60px rgba(0, 0, 0, 0.8);
    }
    [data-theme="light"] {
      /* Clean Crisp Canvas */
      --bg-body:          #F0F4F9;
      --bg-card:          #FFFFFF;
      --bg-card-glass:    rgba(255, 255, 255, 0.95);
      --bg-topbar:        rgba(255, 255, 255, 0.9);
      --bg-hover:         #E0EBF7;
      --bg-input:         #E5EEF8;
      --bg-input-focus:   #FFFFFF;

      --text-primary:     #18181B;
      --text-secondary:   #52525B;
      --text-muted:       #71717A;
      --text-subtle:      #A1A1AA;

      --border-color:     rgba(0, 0, 0, 0.08);
      --border-light:     rgba(0, 0, 0, 0.04);
      --border-glow:      rgba(35, 94, 174, 0.25);

      --shadow-sm:        0 2px 6px rgba(0, 0, 0, 0.03);
      --shadow-md:        0 8px 24px rgba(0, 0, 0, 0.06);
      --shadow-lg:        0 16px 36px rgba(0, 0, 0, 0.08);
      --shadow-xl:        0 24px 48px rgba(0, 0, 0, 0.12);
    }

    /* ─── Universal Caret & Text-Selection Prevention ─────── */
    *, *::before, *::after {
      caret-color: transparent;
    }

    body, h1, h2, h3, h4, h5, h6, p, span, div, a, label, li, ul, ol, section, main, header, footer, nav, table, tr, th, td,
    button,
    [type="button"],
    [type="reset"],
    [type="submit"],
    .btn,
    .btn-primary,
    .btn-secondary,
    .auth-tab,
    .auth-tabs,
    .auth-header,
    .close-btn,
    .topbar,
    .nav-links a,
    .theme-btn,
    .badge,
    .form-label,
    .faq-trigger,
    .hero-title,
    .hero-subtitle,
    .stat-card,
    .service-card,
    .review-card,
    .about-card,
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
    .btn,
    a,
    .auth-tab,
    .close-btn,
    .theme-btn,
    .faq-trigger,
    .nav-links a {
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

:root{
  box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px);
  --bg:#f3f6fc;--surface:#fff;--ink:#0e1a3a;--muted:#4f5c78;--line:#d6deec;
  --blue:#1a3cb0;--blue-ink:#fff;--link:#1a3cb0;--tint:#c9dcff;--sky:#dce8ff;--navy:#0e1a3a;--navy-ink:#eef2fb;
  --head:'Bricolage Grotesque','Segoe UI',Arial,sans-serif;--body:'Figtree','Segoe UI',Arial,sans-serif;
  --p-boutique:url(assets/images/clinic_boutique.jpg);--p-grey:url(assets/images/clinic_grey.jpg);--p-frames:url(assets/images/clinic_frames.jpg);
}
@media(prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0a1122;--surface:#121c36;--ink:#eef2fb;--muted:#a3afc9;--line:#243456;--sky:#16264d;--navy:#060b18;--link:#9dbfff;--tint:#1f3a7a}}
:root[data-theme="dark"], [data-theme="dark"]{--bg:#0a1122;--surface:#121c36;--ink:#eef2fb;--muted:#a3afc9;--line:#243456;--sky:#16264d;--navy:#060b18;--link:#9dbfff;--tint:#1f3a7a}
:root[data-theme="light"], [data-theme="light"]{--bg:#f3f6fc;--surface:#fff;--ink:#0e1a3a;--muted:#4f5c78;--line:#d6deec;--blue:#1a3cb0;--blue-ink:#fff;--link:#1a3cb0;--tint:#c9dcff;--sky:#dce8ff;--navy:#0e1a3a;--navy-ink:#eef2fb}
*,*::before,*::after{box-sizing:inherit}
html{scroll-padding-top:calc(env(safe-area-inset-top,0px) + 76px);scroll-behavior:smooth}
body{margin:0;background:var(--bg);color:var(--ink);font:400 1.0625rem/1.6 var(--body)}
a{color:inherit}
.wrap{max-width:1120px;margin:0 auto;padding:0 20px}
:focus-visible{outline:3px solid var(--link);outline-offset:3px;border-radius:6px}
h1,h2,h3{font-family:var(--head);margin:0;letter-spacing:-.02em}
.btn{display:inline-block;background:var(--blue);color:#fff;font:700 1rem var(--body);padding:14px 26px;border-radius:999px;text-decoration:none;border:0;cursor:pointer;transition:transform .15s}
.btn:hover{transform:translateY(-2px);color:#fff}
.btn.line{background:transparent;color:inherit;border:2px solid currentColor}
.btn.sm{padding:9px 18px;font-size:.92rem}
.ph{background-size:cover;background-position:center}

header{position:sticky;top:env(safe-area-inset-top,0px);z-index:10;background:var(--bg);border-bottom:1px solid var(--line)}
.bar{display:flex;align-items:center;justify-content:space-between;height:66px;gap:14px}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font:800 1.2rem/1 var(--head);color:var(--ink)}
.brand svg{width:36px;color:var(--link);flex-shrink:0}
.brand small{display:block;font:500 .72rem/1.2 var(--body);color:var(--muted);margin-top:3px}
nav{display:flex;align-items:center;gap:22px;font-weight:500;font-size:.95rem}
nav a:not(.btn){text-decoration:none;color:var(--muted)}
nav a:not(.btn):hover{color:var(--ink)}
.theme{width:38px;height:38px;border-radius:50%;border:1.5px solid var(--line);background:transparent;color:var(--ink);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:1.1rem;line-height:1}
@media(max-width:780px){.hide-s{display:none}nav{gap:10px}}

/* hero */
.hero{background:var(--surface);color:var(--ink);overflow:hidden;border-bottom:1px solid var(--line)}
.hero-in{display:grid;grid-template-columns:1.05fr 1fr;gap:32px;align-items:center;padding-top:56px;padding-bottom:72px}
.hero h1{font-size:clamp(2.6rem,6.4vw,4.8rem);line-height:1;font-weight:800}
.hero h1 em{font-style:normal;color:var(--link)}
.hero p.lede{font-size:1.15rem;max-width:44ch;margin:22px 0 30px;color:var(--muted)}
.cta-row{display:flex;gap:12px;flex-wrap:wrap}
.chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:34px}
.chips span{background:var(--sky);border-radius:999px;padding:7px 15px;font-size:.92rem;font-weight:500}
.lenses{display:flex;align-items:center;justify-content:center}
.lens{width:clamp(140px,24vw,270px);aspect-ratio:1;border-radius:50%;border:10px solid var(--link);flex:none;animation:pop .9s cubic-bezier(.2,.8,.2,1) both}
.lens.b{margin-top:70px;animation-delay:.12s}
.bridge{width:clamp(14px,3vw,34px);height:10px;background:var(--link);margin-top:-30px;flex:none}
@keyframes pop{from{transform:scale(.7);opacity:0}to{transform:none;opacity:1}}
@media(max-width:860px){.hero-in{grid-template-columns:1fr;padding-top:36px;padding-bottom:48px}.lens.b{margin-top:50px}}

/* services bento */
section{padding:72px 0}
.title{font-size:clamp(2rem,4.4vw,3rem);line-height:1.05;font-weight:800;max-width:18ch}
.sub{color:var(--muted);max-width:52ch;margin:14px 0 36px}
.bento{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.tile{border-radius:28px;padding:28px;min-height:220px;display:flex;flex-direction:column;justify-content:flex-end}
.tile h3{font-size:1.45rem;line-height:1.15;margin-bottom:8px}
.tile p{margin:0;max-width:40ch}
.t1{grid-column:span 2;grid-row:span 2;background:var(--tint);color:var(--ink);border-radius:28px 28px 28px 120px}
.t1 h3{font-size:clamp(2rem,4vw,3rem);line-height:1}
.t2{grid-column:span 2;color:#fff;background:linear-gradient(rgba(10,17,34,.45),rgba(10,17,34,.72)),var(--p-frames);background-size:cover;background-position:30% 75%}
.t3{background:var(--sky);border-radius:120px 28px 28px 28px;justify-content:center}
.t4{background:var(--navy);color:var(--navy-ink)}
@media(max-width:820px){.bento{grid-template-columns:1fr 1fr}.t1,.t2{grid-column:span 2}.t1{grid-row:auto;min-height:280px}}
@media(max-width:520px){.bento{grid-template-columns:1fr}.t1,.t2{grid-column:auto}}

/* since */
.since{background:var(--sky)}
.since-in{display:grid;grid-template-columns:.9fr 1.1fr;gap:56px;align-items:center}
.arch{aspect-ratio:4/5;border-radius:999px 999px 28px 28px;background-image:var(--p-boutique);background-size:cover;background-position:60% 50%;position:relative;max-width:420px;width:100%}
.arch b{position:absolute;right:-14px;bottom:28px;background:#fff;color:var(--blue);font:800 1.5rem/1 var(--head);padding:16px 22px;border-radius:20px;transform:rotate(-4deg);box-shadow:0 4px 14px rgba(0,0,0,0.08)}
.since p{color:var(--muted);max-width:50ch;margin:16px 0 0}
@media(max-width:780px){.since-in{grid-template-columns:1fr;gap:36px}.arch{margin:0 auto}}

/* steps */
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;margin-top:36px}
.step{border-top:3px solid var(--link);padding-top:16px}
.step i{font:800 2.8rem/1 var(--head);font-style:normal;color:var(--link)}
.step h3{font-size:1.3rem;margin:8px 0 6px}
.step p{margin:0;color:var(--muted)}
@media(max-width:700px){.steps{grid-template-columns:1fr}}

/* gallery */
.strip{display:flex;gap:18px;overflow-x:auto;scroll-snap-type:x mandatory;padding:6px 20px 20px;margin:0 -20px;scrollbar-width:thin}
.strip figure{margin:0;flex:0 0 min(270px,72vw);scroll-snap-align:start}
.strip .ph{height:360px;border-radius:999px 999px 24px 24px}
.strip figure:nth-child(even) .ph{border-radius:24px 24px 999px 999px}
.strip figcaption{margin-top:10px;font-weight:600;text-align:center}

/* faq */
.faq-in{display:grid;grid-template-columns:.8fr 1.2fr;gap:56px;align-items:start}
.faq-in aside{position:sticky;top:96px}
details{background:var(--surface);border:1px solid var(--line);border-radius:20px;margin-bottom:12px}
summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 22px;font:700 1.08rem/1.35 var(--head)}
summary::-webkit-details-marker{display:none}
summary::after{content:"+";flex:none;width:32px;height:32px;border-radius:50%;background:var(--blue);color:#fff;display:grid;place-items:center;font:500 1.4rem/1 var(--body);transition:transform .2s}
details[open] summary::after{transform:rotate(45deg)}
details p{margin:0;padding:0 22px 20px;color:var(--muted);max-width:60ch}
@media(max-width:780px){.faq-in{grid-template-columns:1fr;gap:28px}.faq-in aside{position:static}}

/* closing */
.close{background:var(--sky);color:var(--ink);border-radius:36px;padding:clamp(32px,6vw,64px);display:grid;grid-template-columns:1.2fr 1fr;gap:32px;align-items:center}
.close h2{font-size:clamp(2rem,4.4vw,3.2rem);line-height:1.05;font-weight:800}
.close dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 18px;color:var(--muted)}
.close dt{opacity:.9}.close dd{margin:0;font-weight:600;color:var(--ink)}
.close .btn{margin-top:22px}
.privacy{margin-top:20px;color:var(--muted);font-size:.95rem;max-width:70ch}
.privacy a{color:var(--link);font-weight:600}
@media(max-width:780px){.close{grid-template-columns:1fr}}
footer{border-top:1px solid var(--line);padding:36px 0 110px;color:var(--muted);font-size:.93rem}
.foot{display:flex;flex-wrap:wrap;gap:24px 56px;justify-content:space-between}
.foot b{display:block;color:var(--ink);margin-bottom:6px;font-family:var(--head)}
.foot a{display:block;text-decoration:none;padding:2px 0}
.float{position:fixed;right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:9;box-shadow:0 8px 24px rgba(14,26,58,.35)}
@media(min-width:861px){.float{display:none}}
@media(prefers-reduced-motion:reduce){.lens{animation:none}html{scroll-behavior:auto}.btn,summary::after{transition:none}}
.js .reveal{opacity:0;transform:translateY(40px) scale(.96);transition:opacity .8s ease,transform .9s cubic-bezier(.2,.8,.2,1);transition-delay:var(--d,0s)}
.js .reveal.in{opacity:1;transform:none}
.js .arch.reveal{transform:translateY(60px) scale(.92)}
.js .arch.reveal.in{transform:none}
@media(prefers-reduced-motion:reduce){.js .reveal,.js .reveal.in{opacity:1;transform:none;transition:none}}

    /* MODAL STYLES (PREMIUM 3D MODERN REDESIGN - NO WEIRD SCROLLBAR) */
    .modal-overlay { 
      display: none; position: fixed; inset: 0; 
      background: radial-gradient(circle at 50% 35%, rgba(10, 18, 36, 0.85) 0%, rgba(3, 7, 15, 0.95) 100%);
      backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
      z-index: 9999; align-items: center; justify-content: center; padding: 16px;
    }
    .modal-overlay.open { display: flex !important; }
    
    .auth-card {
      background: linear-gradient(165deg, rgba(20, 30, 52, 0.98) 0%, rgba(10, 16, 30, 0.99) 100%);
      border: 2px solid rgba(0, 173, 239, 0.45);
      border-radius: 28px;
      width: 100%; max-width: 530px;
      box-shadow: 0 35px 95px -15px rgba(0, 0, 0, 0.92), 
                  0 0 50px rgba(0, 173, 239, 0.25),
                  inset 0 1px 1px 0 rgba(255, 255, 255, 0.25);
      position: relative;
      animation: modalPopIn .32s cubic-bezier(0.16, 1, 0.3, 1);
      max-height: 94vh;
      overflow-y: auto;
      scrollbar-width: none !important;
      -ms-overflow-style: none !important;
    }
    .auth-card::-webkit-scrollbar {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    [data-theme="light"] .auth-card {
      background: #FFFFFF;
      border: 2px solid #CBD5E1;
      box-shadow: 0 30px 85px -10px rgba(35, 94, 174, 0.25),
                  0 12px 30px rgba(0, 0, 0, 0.08),
                  inset 0 1px 0 #FFFFFF;
    }
    @keyframes modalPopIn { 
      from { opacity: 0; transform: scale(.94) translateY(14px); }
      to   { opacity: 1; transform: scale(1) translateY(0); } 
    }

    .auth-header {
      padding: 22px 28px 16px; 
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1.5px solid var(--border-color);
      position: relative;
    }
    .auth-title-wrap { display: flex; align-items: center; gap: 14px; }
    .auth-brand-emblem {
      width: 50px; height: 50px; border-radius: 16px;
      background: #FFFFFF;
      border: 1.5px solid rgba(0, 173, 239, 0.35);
      box-shadow: 0 6px 18px rgba(0, 173, 239, 0.25), inset 0 1px 0 #FFFFFF;
      display: flex; align-items: center; justify-content: center;
      padding: 6px; flex-shrink: 0;
    }
    .auth-brand-emblem img {
      max-width: 100%; max-height: 100%; object-fit: contain;
    }
    .auth-title { font-size: 1.35rem; font-weight: 800; margin: 0; color: #0F172A; letter-spacing: -0.02em; }
    [data-theme="dark"] .auth-title { color: #FFFFFF; }
    .auth-sub { font-size: 0.88rem; color: #475569; margin: 3px 0 0; font-weight: 600; }
    [data-theme="dark"] .auth-sub { color: #94A3B8; }

    .close-btn {
      background: #F1F5F9; border: 1.5px solid #CBD5E1; color: #475569;
      width: 38px; height: 38px; border-radius: 50%; cursor: pointer; transition: all .2s;
      display: flex; align-items: center; justify-content: center; font-size: .95rem;
    }
    [data-theme="dark"] .close-btn {
      background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.2); color: #FFFFFF;
    }
    .close-btn:hover { background: var(--clr-danger); border-color: var(--clr-danger); color: #fff; transform: rotate(90deg) scale(1.06); }
    
    /* MODERN SEGMENTED PILL TABS */
    .auth-tabs {
      display: flex; gap: 8px; padding: 6px; margin: 18px 28px 0;
      background: rgba(15, 23, 42, 0.7);
      border-radius: 16px;
      border: 1.5px solid rgba(255, 255, 255, 0.16);
      box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.25);
      -webkit-user-select: none; -moz-user-select: none; user-select: none;
    }
    [data-theme="light"] .auth-tabs {
      background: #F1F5F9;
      border: 1.5px solid #CBD5E1;
      box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.06);
    }
    .auth-tab {
      flex: 1; padding: 12px 18px; text-align: center; cursor: pointer; font-size: 1.0rem; font-weight: 800;
      color: #94A3B8; background: transparent; border: none; border-radius: 12px;
      font-family: inherit; transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex; align-items: center; justify-content: center; gap: 8px;
      -webkit-user-select: none; -moz-user-select: none; user-select: none;
      -webkit-tap-highlight-color: transparent; outline: none;
    }
    [data-theme="light"] .auth-tab { color: #475569; }
    .auth-tab:hover { color: var(--text-primary); }
    .auth-tab.active {
      color: #fff;
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%);
      box-shadow: 0 4px 16px rgba(0, 173, 239, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }
    [data-theme="light"] .auth-tab.active {
      background: #FFFFFF;
      color: #0284C7;
      border: 1.5px solid rgba(0, 173, 239, 0.45);
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }
    
    .auth-panel { padding: 22px 28px 26px; display: none; }
    .auth-panel.active { display: block; animation: panelFade .2s ease; }
    @keyframes panelFade { from{opacity:0; transform:translateY(3px);} to{opacity:1; transform:translateY(0);} }
    
    .auth-panel-sub { font-size: .96rem; color: #CBD5E1; margin-bottom: 18px; line-height: 1.6; font-weight: 500; }
    [data-theme="light"] .auth-panel-sub { color: #334155; }

    .form-group { margin-bottom: 18px; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 440px) { .form-row-2 { grid-template-columns: 1fr; gap: 12px; } }

    .form-label {
      display: flex; align-items: center; font-size: .92rem; font-weight: 800; letter-spacing: .01em; color: #F8FAFC; margin-bottom: 8px;
    }
    [data-theme="light"] .form-label { color: #0F172A; }
    .form-label i { color: #00ADEF; font-size: 1.02rem; margin-right: 7px; }
    
    .form-control, .form-select {
      width: 100%; height: 52px; padding: 12px 18px; border-radius: 14px; 
      font-family: inherit; font-size: 1.05rem; font-weight: 600; transition: all .2s;
    }
    [data-theme="dark"] .form-control, [data-theme="dark"] .form-select {
      background: rgba(15, 23, 42, 0.8) !important;
      border: 2px solid rgba(255, 255, 255, 0.22) !important;
      color: #FFFFFF !important;
    }
    [data-theme="dark"] .form-control::placeholder {
      color: #94A3B8 !important; font-weight: 500; font-size: 1.02rem; opacity: 1 !important;
    }
    [data-theme="dark"] .form-control:focus, [data-theme="dark"] .form-select:focus {
      background: rgba(15, 23, 42, 0.95) !important;
      border-color: #00ADEF !important; outline: none;
      box-shadow: 0 0 0 4px rgba(0, 173, 239, 0.35) !important;
    }
    [data-theme="light"] .form-control, [data-theme="light"] .form-select {
      background: #FFFFFF !important;
      border: 2px solid #94A3B8 !important;
      color: #0F172A !important;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }
    [data-theme="light"] .form-control::placeholder {
      color: #64748B !important; font-weight: 500; font-size: 1.02rem; opacity: 1 !important;
    }
    [data-theme="light"] .form-control:focus, [data-theme="light"] .form-select:focus {
      background: #FFFFFF !important;
      border-color: #00ADEF !important; outline: none;
      box-shadow: 0 0 0 4px rgba(0, 173, 239, 0.25) !important;
    }
    .is-invalid { border-color: #EF4444 !important; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.25) !important; }

    .auth-forgot-link {
      font-size: .92rem; color: #38BDF8; text-decoration: none; font-weight: 700;
      transition: color 0.2s;
    }
    [data-theme="light"] .auth-forgot-link { color: #0284C7; }
    .auth-forgot-link:hover { text-decoration: underline; color: #00ADEF; }

    /* Password Security Requirements Indicator Box */
    .pass-req-box {
      margin-top: 8px;
      margin-bottom: 14px;
      padding: 10px 14px;
      border-radius: 14px;
      background: rgba(35, 94, 174, 0.1);
      border: 1px solid rgba(0, 173, 239, 0.25);
      font-size: 0.82rem;
      transition: all 0.25s ease;
    }
    [data-theme="light"] .pass-req-box {
      background: #F8FAFC;
      border: 1px solid #CBD5E1;
    }
    .pass-req-header {
      font-weight: 800;
      color: #38BDF8;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.82rem;
    }
    [data-theme="light"] .pass-req-header {
      color: #0284C7;
    }
    .pass-req-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 14px;
    }
    @media (max-width: 480px) {
      .pass-req-grid {
        grid-template-columns: 1fr;
      }
    }
    .pass-req-item {
      display: flex;
      align-items: center;
      gap: 7px;
      font-weight: 600;
      color: #94A3B8;
      font-size: 0.8rem;
      transition: all 0.2s ease;
    }
    [data-theme="light"] .pass-req-item {
      color: #64748B;
    }
    .pass-req-item i {
      font-size: 0.85rem;
      color: #94A3B8;
      transition: all 0.2s ease;
    }
    [data-theme="light"] .pass-req-item i {
      color: #94A3B8;
    }
    .pass-req-item.valid {
      color: #10B981 !important;
    }
    .pass-req-item.valid i {
      color: #10B981 !important;
      transform: scale(1.08);
    }

    /* MODERN CENTERED TOAST NOTIFICATION (DYNAMIC ISLAND / LUXURY FLOATING PILL) */
    .toast-container { 
      position: fixed; 
      top: 24px; 
      left: 50%; 
      transform: translateX(-50%); 
      z-index: 200050; 
      display: flex; 
      flex-direction: column; 
      align-items: center; 
      gap: 10px; 
      pointer-events: none;
      width: 100%;
      max-width: 520px;
      padding: 0 16px;
    }
    .toast {
      pointer-events: auto;
      background: #FFFFFF;
      color: #0F172A;
      padding: 11px 16px 11px 12px;
      border-radius: 16px;
      box-shadow: 0 20px 45px -8px rgba(35, 94, 174, 0.22), 0 4px 16px rgba(0, 0, 0, 0.06), inset 0 1px 0 #FFFFFF;
      display: flex;
      align-items: center;
      gap: 12px;
      width: auto;
      max-width: 100%;
      border: 1px solid rgba(0, 0, 0, 0.08);
      font-size: .88rem;
      font-weight: 600;
      opacity: 0;
      transform: translateY(-20px) scale(0.95);
      transition: all .35s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .toast.show {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
    [data-theme="dark"] .toast {
      background: rgba(22, 34, 56, 0.96);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      color: #F8FAFC;
      border: 1px solid rgba(255, 255, 255, 0.14);
      box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.85), 0 0 25px rgba(0, 173, 239, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }
    .toast-badge {
      width: 30px;
      height: 30px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: .90rem;
      flex-shrink: 0;
    }
    .toast.danger .toast-badge {
      background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    }
    .toast.success .toast-badge {
      background: linear-gradient(135deg, #10B981 0%, #059669 100%);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }
    .toast.info .toast-badge, .toast:not(.danger):not(.success) .toast-badge {
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%);
      box-shadow: 0 4px 12px rgba(0, 173, 239, 0.4);
    }
    .toast-msg {
      flex: 1;
      line-height: 1.45;
      font-weight: 600;
    }
    .toast-close {
      background: none;
      border: none;
      color: var(--text-muted);
      cursor: pointer;
      font-size: .82rem;
      padding: 4px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0.65;
      transition: all .2s;
    }
    .toast-close:hover {
      opacity: 1;
      color: var(--clr-danger);
    }
    .toast.danger {
      border-color: rgba(239, 68, 68, 0.4);
    }
    [data-theme="dark"] .toast.danger {
      border-color: rgba(239, 68, 68, 0.45);
      box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.85), 0 0 30px rgba(239, 68, 68, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }
    
    /* PRIMARY SUBMIT BUTTON */
    .btn-primary {
      width: 100%; height: 54px; border-radius: 14px; border: none;
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%);
      color: #fff; font-family: inherit; font-size: 1.06rem; font-weight: 800;
      cursor: pointer; transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 8px 24px -2px rgba(0, 173, 239, 0.45),
                  inset 0 1px 0 rgba(255, 255, 255, 0.4);
      display: flex; align-items: center; justify-content: center; gap: 10px;
      margin-top: 8px; margin-bottom: 4px;
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 30px -2px rgba(0, 173, 239, 0.65),
                  inset 0 1px 0 rgba(255, 255, 255, 0.5);
    }
    .btn-primary:active {
      transform: translateY(1px);
      box-shadow: 0 3px 10px rgba(0, 173, 239, 0.35);
    }
    
    .form-pass-wrap { position:relative; }
    .pass-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      width: 38px; height: 38px; border-radius: 10px; border: none; background: transparent;
      display: flex; align-items: center; justify-content: center;
      color: #475569; cursor: pointer; font-size: 1.15rem;
      transition: all .2s;
    }
    [data-theme="dark"] .pass-toggle { color: #94A3B8; }
    .pass-toggle:hover { color: #00ADEF; background: rgba(0, 173, 239, 0.12); }
    [data-theme="dark"] .pass-toggle:hover { color: #38BDF8; background: rgba(0, 173, 239, 0.2); }

    /* Hide native browser password reveal eye (prevents duplicate redundant eye in Edge/Chromium) */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear,
    input::-ms-reveal,
    input::-ms-clear {
      display: none !important;
      width: 0 !important;
      height: 0 !important;
      pointer-events: none !important;
    }

    /* DIVIDER */
    .auth-divider {
      display: flex; align-items: center; text-align: center;
      margin: 18px 0 16px; color: #475569; font-size: .88rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: .1em;
    }
    [data-theme="dark"] .auth-divider { color: #94A3B8; }
    .auth-divider::before, .auth-divider::after {
      content: ''; flex: 1; border-bottom: 2px solid #CBD5E1;
    }
    [data-theme="dark"] .auth-divider::before, [data-theme="dark"] .auth-divider::after {
      border-bottom: 2px solid rgba(255, 255, 255, 0.15);
    }
    .auth-divider span { padding: 0 16px; }

    /* GOOGLE AUTH BUTTON (AT BOTTOM) */
    .btn-google-auth {
      display: flex; align-items: center; justify-content: center; gap: 12px;
      width: 100%; height: 54px; border-radius: 14px;
      background: #FFFFFF; color: #0F172A; border: 2px solid #94A3B8;
      font-size: 1.02rem; font-weight: 800; text-decoration: none !important;
      cursor: pointer; transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      margin-bottom: 0;
    }
    .btn-google-auth:hover {
      background: #F8FAFC; border-color: #00ADEF; color: #0284C7;
      transform: translateY(-2px);
      box-shadow: 0 8px 22px rgba(0, 173, 239, 0.2);
    }
    .btn-google-auth:active {
      transform: translateY(1px);
    }
    [data-theme="dark"] .btn-google-auth {
      background: #FFFFFF; color: #0F172A; 
      border-color: rgba(255, 255, 255, 0.3);
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
    }
    [data-theme="dark"] .btn-google-auth:hover {
      background: #F1F5F9; border-color: #00ADEF; color: #0284C7;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0, 173, 239, 0.35);
    }
    .google-svg { flex-shrink: 0; }

    /* ============================================================
       SWEETALERT2 POPUP MODAL (MATCHING ADMIN & LUXURY GLASS)
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
      background: #FFFFFF !important;
      color: #0F172A !important;
      box-shadow: 0 25px 60px -8px rgba(0, 0, 0, 0.4) !important;
    }
    [data-theme="dark"] .swal2-popup.patient-swal-popup {
      background: #162238 !important;
      border: 1.5px solid rgba(56, 189, 248, 0.3) !important;
      box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.9), 0 0 35px rgba(56, 189, 248, 0.15) !important;
    }
    .patient-swal-popup .swal2-title {
      font-size: 1.35rem !important;
      font-weight: 800 !important;
      letter-spacing: -0.02em !important;
      color: #0F172A !important;
      padding: 0 0 6px !important;
    }
    [data-theme="dark"] .patient-swal-popup .swal2-title {
      color: #FFFFFF !important;
    }
    .patient-swal-popup .swal2-html-container {
      font-size: .92rem !important;
      color: #64748B !important;
      line-height: 1.55 !important;
      margin: 4px 0 16px !important;
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
      padding: 11px 28px !important;
      font-size: .90rem !important;
      font-weight: 700 !important;
      border: none !important;
      color: #FFFFFF !important;
      box-shadow: 0 4px 14px rgba(35, 94, 174, 0.3) !important;
      transition: all .15s ease !important;
    }
    .patient-swal-popup .swal2-confirm:hover {
      transform: translateY(-2px) !important;
      box-shadow: 0 6px 18px rgba(35, 94, 174, 0.45) !important;
    }
    .patient-swal-popup.patient-swal-danger .swal2-confirm {
      background: #EF4444 !important;
      box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35) !important;
    }
    .patient-swal-popup.patient-swal-danger .swal2-confirm:hover {
      box-shadow: 0 6px 18px rgba(239, 68, 68, 0.5) !important;
    }
    .patient-swal-popup.patient-swal-success .swal2-confirm {
      background: #10B981 !important;
      box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
    }
    .patient-swal-popup.patient-swal-success .swal2-confirm:hover {
      box-shadow: 0 6px 18px rgba(16, 185, 129, 0.5) !important;
    }
    .patient-swal-popup .swal2-cancel {
      border-radius: 12px !important;
      padding: 11px 22px !important;
      font-size: .88rem !important;
      font-weight: 800 !important;
      border: 1.5px solid rgba(125, 125, 125, 0.25) !important;
      background: transparent !important;
      color: #64748B !important;
      transition: all .15s ease !important;
    }
    .patient-swal-popup .swal2-cancel:hover {
      color: #0F172A !important;
      border-color: #94A3B8 !important;
      transform: translateY(-2px) !important;
    }
    [data-theme="dark"] .patient-swal-popup .swal2-cancel {
      border-color: rgba(255, 255, 255, 0.15) !important;
      color: #94A3B8 !important;
    }
    [data-theme="dark"] .patient-swal-popup .swal2-cancel:hover {
      color: #FFFFFF !important;
      border-color: rgba(255, 255, 255, 0.3) !important;
    }

    /* ============================================================
       HIGH-VISIBILITY OPTICAL TERMS & CONDITIONS MODAL
       Engineered for patients with low visual acuity / presbyopia
       ============================================================ */
    .privacy-card {
      background: var(--bg-card);
      border: 1.5px solid rgba(0, 173, 239, 0.4);
      border-radius: 28px;
      width: 100%;
      max-width: 860px;
      box-shadow: 0 35px 90px rgba(0, 0, 0, 0.65), 0 0 30px rgba(0, 173, 239, 0.18);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      max-height: 88vh;
      backdrop-filter: blur(28px);
      -webkit-backdrop-filter: blur(28px);
      animation: modalIn .3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .privacy-header {
      padding: 22px 30px;
      border-bottom: 1.5px solid var(--border-color);
      background: linear-gradient(135deg, rgba(35, 94, 174, 0.15), rgba(0, 173, 239, 0.08));
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-shrink: 0;
    }
    .privacy-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 1.45rem;
      box-shadow: 0 8px 22px rgba(0, 173, 239, 0.4);
      flex-shrink: 0;
    }
    .privacy-header-title {
      font-size: 1.38rem;
      font-weight: 800;
      color: #0F172A;
      letter-spacing: -0.02em;
      margin: 0;
      line-height: 1.25;
    }
    [data-theme="dark"] .privacy-header-title {
      color: #FFFFFF;
    }
    .privacy-header-sub {
      font-size: 0.92rem;
      color: #334155;
      font-weight: 600;
      margin-top: 3px;
    }
    [data-theme="dark"] .privacy-header-sub {
      color: #94A3B8;
    }
    .privacy-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 14px;
      border-radius: 100px;
      background: rgba(16, 185, 129, 0.14);
      border: 1.5px solid rgba(16, 185, 129, 0.35);
      color: #059669;
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    [data-theme="dark"] .privacy-badge {
      color: #34D399;
      background: rgba(16, 185, 129, 0.2);
    }
    /* Quick Text Resizer for Optical Patients */
    .optical-zoom-ctrl {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      background: rgba(0, 173, 239, 0.1);
      border: 1px solid rgba(0, 173, 239, 0.3);
      border-radius: 12px;
      padding: 3px 6px;
    }
    .optical-zoom-label {
      font-size: 0.76rem;
      font-weight: 800;
      color: var(--clr-primary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-right: 4px;
    }
    .optical-zoom-btn {
      background: transparent;
      border: none;
      padding: 4px 9px;
      border-radius: 8px;
      color: var(--text-primary);
      font-weight: 800;
      cursor: pointer;
      font-size: 0.82rem;
      transition: all 0.2s;
    }
    .optical-zoom-btn:hover, .optical-zoom-btn.active {
      background: var(--clr-primary);
      color: #FFFFFF;
      box-shadow: 0 2px 8px rgba(35, 94, 174, 0.3);
    }
    
    .privacy-body {
      padding: 28px 34px;
      overflow-y: auto;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 20px;
      scrollbar-width: thin;
      scrollbar-color: rgba(0, 173, 239, 0.6) rgba(0, 0, 0, 0.05);
    }
    .privacy-body::-webkit-scrollbar {
      width: 8px;
    }
    .privacy-body::-webkit-scrollbar-thumb {
      background: rgba(0, 173, 239, 0.5);
      border-radius: 10px;
    }
    .privacy-body::-webkit-scrollbar-track {
      background: rgba(0, 0, 0, 0.04);
      border-radius: 10px;
    }

    /* Font Scale Modes for Optical Patients */
    .privacy-card.font-large .terms-sec-intro,
    .privacy-card.font-large .terms-list-item,
    .privacy-card.font-large .terms-callout {
      font-size: 1.15rem !important;
      line-height: 1.78 !important;
    }
    .privacy-card.font-large .terms-sec-title {
      font-size: 1.38rem !important;
    }
    .privacy-card.font-xlarge .terms-sec-intro,
    .privacy-card.font-xlarge .terms-list-item,
    .privacy-card.font-xlarge .terms-callout {
      font-size: 1.28rem !important;
      line-height: 1.88 !important;
    }
    .privacy-card.font-xlarge .terms-sec-title {
      font-size: 1.50rem !important;
    }

    /* Callout Card */
    .terms-callout {
      background: linear-gradient(135deg, rgba(35, 94, 174, 0.10), rgba(0, 173, 239, 0.06));
      border: 1.5px solid rgba(0, 173, 239, 0.35);
      border-radius: 20px;
      padding: 18px 22px;
      font-size: 1.04rem;
      font-weight: 600;
      color: #0F172A;
      line-height: 1.65;
      display: flex;
      gap: 16px;
      align-items: flex-start;
    }
    [data-theme="dark"] .terms-callout {
      background: rgba(35, 94, 174, 0.18);
      color: #F8FAFC;
    }
    .terms-callout-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: var(--clr-primary);
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
      box-shadow: 0 4px 14px rgba(35, 94, 174, 0.35);
    }

    /* Section Cards - Clean & Highly Legible */
    .terms-section {
      background: #FFFFFF;
      border: 1.5px solid #E2E8F0;
      border-radius: 20px;
      padding: 24px 28px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
      transition: all 0.2s ease;
    }
    [data-theme="dark"] .terms-section {
      background: rgba(22, 26, 52, 0.92);
      border-color: rgba(255, 255, 255, 0.12);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }
    .terms-section:hover {
      border-color: rgba(0, 173, 239, 0.5);
      box-shadow: 0 8px 28px rgba(0, 173, 239, 0.14);
    }

    .terms-sec-head {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }
    .terms-sec-num {
      font-size: 0.82rem;
      font-weight: 800;
      padding: 4px 12px;
      border-radius: 100px;
      background: rgba(0, 173, 239, 0.15);
      color: #0284C7;
      border: 1px solid rgba(0, 173, 239, 0.3);
      letter-spacing: 0.04em;
    }
    [data-theme="dark"] .terms-sec-num {
      background: rgba(0, 173, 239, 0.25);
      color: #38BDF8;
      border-color: rgba(56, 189, 248, 0.4);
    }
    .terms-sec-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: #0F172A;
      margin: 0;
      letter-spacing: -0.01em;
    }
    [data-theme="dark"] .terms-sec-title {
      color: #FFFFFF;
    }

    .terms-sec-intro {
      font-size: 1.02rem;
      color: #0F172A;
      line-height: 1.65;
      font-weight: 600;
      margin-bottom: 14px;
    }
    [data-theme="dark"] .terms-sec-intro {
      color: #E2E8F0;
    }

    .terms-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .terms-list-item {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      font-size: 0.98rem;
      color: #1E293B;
      line-height: 1.65;
      font-weight: 500;
    }
    [data-theme="dark"] .terms-list-item {
      color: #CBD5E1;
    }
    .terms-bullet-icon {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: rgba(0, 173, 239, 0.15);
      color: #00ADEF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      flex-shrink: 0;
      margin-top: 3px;
    }
    [data-theme="dark"] .terms-bullet-icon {
      background: rgba(0, 173, 239, 0.25);
      color: #38BDF8;
    }
    .terms-list-item strong {
      color: #0F172A;
      font-weight: 700;
    }
    [data-theme="dark"] .terms-list-item strong {
      color: #F8FAFC;
    }

    /* Highlight Banner inside Section */
    .terms-highlight {
      background: rgba(16, 185, 129, 0.10);
      border: 1.5px solid rgba(16, 185, 129, 0.35);
      border-radius: 14px;
      padding: 14px 18px;
      font-size: 0.98rem;
      color: #065F46;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-top: 14px;
    }
    [data-theme="dark"] .terms-highlight {
      background: rgba(16, 185, 129, 0.18);
      border-color: rgba(16, 185, 129, 0.4);
      color: #6EE7B7;
    }

    /* Footer Controls */
    .privacy-footer {
      padding: 20px 32px;
      border-top: 1.5px solid var(--border-color);
      background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.05) 100%);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      flex-shrink: 0;
    }
    .btn-terms-close {
      padding: 13px 26px;
      font-size: 0.96rem;
      font-weight: 700;
      border-radius: 14px;
      border: 1.5px solid #CBD5E1;
      background: transparent;
      color: #475569;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-terms-close:hover {
      background: #F1F5F9;
      color: #0F172A;
    }
    [data-theme="dark"] .btn-terms-close {
      border-color: rgba(255, 255, 255, 0.2);
      color: #E2E8F0;
    }
    [data-theme="dark"] .btn-terms-close:hover {
      background: rgba(255, 255, 255, 0.1);
      color: #FFFFFF;
    }
    .btn-terms-agree {
      padding: 13px 32px;
      font-size: 1.02rem;
      font-weight: 800;
      border-radius: 14px;
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%);
      color: #FFFFFF;
      border: none;
      box-shadow: 0 8px 24px rgba(0, 173, 239, 0.4);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      transition: all 0.25s ease;
    }
    .btn-terms-agree:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(0, 173, 239, 0.55);
    }

    @media(max-width:992px){
      .hero { flex-direction:column; padding-top:120px; text-align:center; }
      .hero p { margin:0 auto 40px; }
      .stats { justify-content:center; }
      .details { grid-template-columns:1fr; }
    }

    /* ============================================================
       FAQ SECTION (HIGH CONTRAST & OPTICAL ACCESSIBILITY)
       ============================================================ */
    html {
      scroll-behavior: smooth;
      scroll-padding-top: 95px;
    }
    .faq-section {
      max-width: 1100px;
      margin: 0 auto 100px;
      padding: 0 24px;
    }
    .faq-header {
      text-align: center;
      margin-bottom: 45px;
    }
    .faq-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 18px;
      border-radius: 100px;
      background: rgba(0, 173, 239, 0.12);
      border: 1px solid rgba(0, 173, 239, 0.35);
      color: var(--clr-primary);
      font-size: 0.82rem;
      font-weight: 800;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      margin-bottom: 14px;
    }
    [data-theme="dark"] .faq-badge {
      color: #38BDF8;
      background: rgba(0, 173, 239, 0.18);
      border-color: rgba(56, 189, 248, 0.4);
    }
    .faq-title {
      font-size: 2.25rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--text-primary);
      margin-bottom: 12px;
      line-height: 1.25;
    }
    .faq-title span {
      background: linear-gradient(135deg, #00ADEF 0%, #235EAE 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .faq-subtitle {
      font-size: 1.05rem;
      color: var(--text-secondary);
      max-width: 680px;
      margin: 0 auto;
      line-height: 1.6;
    }
    .faq-grid {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .faq-card {
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 20px;
      overflow: hidden;
      backdrop-filter: blur(16px);
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
    }
    .faq-card:hover {
      border-color: rgba(0, 173, 239, 0.5);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 173, 239, 0.12);
    }
    .faq-card.active {
      border-color: rgba(0, 173, 239, 0.65);
      box-shadow: 0 12px 35px rgba(0, 173, 239, 0.18);
    }
    .faq-trigger {
      width: 100%;
      background: transparent;
      border: none;
      padding: 22px 28px;
      text-align: left;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      user-select: none;
    }
    .faq-q-wrap {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .faq-q-icon {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      background: rgba(0, 173, 239, 0.12);
      border: 1px solid rgba(0, 173, 239, 0.28);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--clr-primary);
      font-size: 1.15rem;
      flex-shrink: 0;
      transition: all 0.25s;
    }
    .faq-card.active .faq-q-icon {
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #FFFFFF;
      box-shadow: 0 4px 14px rgba(35, 94, 174, 0.4);
      border-color: transparent;
    }
    .faq-q-text {
      font-size: 1.12rem;
      font-weight: 700;
      color: var(--text-primary);
      margin: 0;
      line-height: 1.45;
    }
    .faq-arrow {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.06);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted);
      font-size: 0.9rem;
      flex-shrink: 0;
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s, color 0.2s;
    }
    [data-theme="dark"] .faq-arrow {
      background: rgba(255, 255, 255, 0.08);
      border-color: rgba(255, 255, 255, 0.1);
    }
    .faq-card.active .faq-arrow {
      transform: rotate(180deg);
      background: var(--clr-primary);
      color: #FFFFFF;
      border-color: var(--clr-primary);
    }
    .faq-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
      opacity: 0;
    }
    .faq-card.active .faq-content {
      max-height: 600px;
      opacity: 1;
    }
    .faq-inner {
      padding: 0 28px 24px 88px;
      font-size: 1.02rem;
      color: var(--text-secondary);
      line-height: 1.7;
    }
    .faq-inner p {
      margin: 0 0 10px 0;
    }
    .faq-inner p:last-child {
      margin-bottom: 0;
    }
    .faq-inner strong {
      color: var(--text-primary);
    }

    /* FAQ Bottom CTA */
    .faq-cta-box {
      margin-top: 36px;
      background: linear-gradient(135deg, rgba(35, 94, 174, 0.09), rgba(0, 173, 239, 0.05));
      border: 1.5px dashed rgba(0, 173, 239, 0.35);
      border-radius: 20px;
      padding: 24px 30px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }
    .faq-cta-info {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .faq-cta-icon {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: var(--clr-primary);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      flex-shrink: 0;
      box-shadow: 0 4px 14px rgba(35, 94, 174, 0.35);
    }
    .faq-cta-title {
      font-size: 1.08rem;
      font-weight: 800;
      color: var(--text-primary);
      margin: 0 0 3px 0;
    }
    .faq-cta-desc {
      font-size: 0.92rem;
      color: var(--text-secondary);
      margin: 0;
    }
    .faq-cta-btns {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    @media (max-width: 768px) {
      .faq-title { font-size: 1.85rem; }
      .faq-trigger { padding: 18px 20px; gap: 14px; }
      .faq-inner { padding: 0 20px 20px 20px; }
      .faq-q-text { font-size: 1.02rem; }
      .faq-cta-box { flex-direction: column; align-items: flex-start; }
      .faq-cta-btns { width: 100%; }
      .faq-cta-btns .btn-primary, .faq-cta-btns .btn { width: 100%; text-align: center; justify-content: center; }
    }
  </style>
</head>
<body>
<header><div class="wrap bar">
  <a class="brand" href="#top" aria-label="Gueco Optical home">
    <svg viewBox="0 0 48 20" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><circle cx="12" cy="11" r="8"/><circle cx="36" cy="11" r="8"/><path d="M20 10c2-2 6-2 8 0M0 8l4 1M48 8l-4 1"/></svg>
    <span>Gueco Optical<small>Capas, Tarlac</small></span>
  </a>
  <nav aria-label="Main">
    <a class="hide-s" href="#services">Services</a><a class="hide-s" href="#about">About</a><a class="hide-s" href="#faqs">FAQs</a><a class="hide-s" href="javascript:void(0)" onclick="openPrivacyModal()">Terms</a>
    <?php if($isPatient): ?>
      <a class="btn sm" href="patient/dashboard.php">My Dashboard</a>
    <?php else: ?>
      <button type="button" class="btn sm" onclick="openAuthModal()">Book a visit</button>
    <?php endif; ?>
    <button class="theme" id="theme" aria-label="Switch light or dark mode" onclick="toggleTheme()">◐</button>
  </nav>
</div></header>

<?php
$lensLeft = getSiteSetting($siteSettings, 'hero_lens_left', 'assets/images/clinic_boutique.jpg');
$lensRight = getSiteSetting($siteSettings, 'hero_lens_right', 'assets/images/clinic_grey.jpg');
$bentoT2Img = getSiteSetting($siteSettings, 'bento_t2_img', 'assets/images/clinic_frames.jpg');
$aboutArchImg = getSiteSetting($siteSettings, 'about_arch_img', 'assets/images/clinic_boutique.jpg');
$galleryItem1Img = getSiteSetting($siteSettings, 'gallery_item1_img', 'assets/images/clinic_boutique.jpg');
$galleryItem2Img = getSiteSetting($siteSettings, 'gallery_item2_img', 'assets/images/clinic_grey.jpg');
$galleryItem3Img = getSiteSetting($siteSettings, 'gallery_item3_img', 'assets/images/clinic_frames.jpg');
$galleryItem4Img = getSiteSetting($siteSettings, 'gallery_item4_img', 'assets/images/clinic_grey.jpg');
$galleryItem5Img = getSiteSetting($siteSettings, 'gallery_item5_img', 'assets/images/clinic_boutique.jpg');
$galleryItem1Caption = getSiteSetting($siteSettings, 'gallery_item1_caption', 'Reception');
$galleryItem2Caption = getSiteSetting($siteSettings, 'gallery_item2_caption', 'Frames wall');
$galleryItem3Caption = getSiteSetting($siteSettings, 'gallery_item3_caption', 'Sunglasses');
$galleryItem4Caption = getSiteSetting($siteSettings, 'gallery_item4_caption', 'Exam area');
$galleryItem5Caption = getSiteSetting($siteSettings, 'gallery_item5_caption', 'Waiting area');
?>
<main id="top">
<div class="hero"><div class="wrap hero-in">
  <div>
    <h1><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_headline_start', 'Good eyes,')) ?> <em><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_highlight', 'great frames,')) ?></em> <?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_headline_end', 'and a clinic you know.')) ?></h1>
    <p class="lede"><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_description', 'Eye exams by licensed optometrists, plus glasses and contact lenses you will want to wear. Serving Capas since 1986.')) ?></p>
    <div class="cta-row">
      <button type="button" class="btn" onclick="openAuthModal()">Book an appointment</button>
      <a class="btn line" href="#services">See what we offer</a>
    </div>
    <div class="chips">
      <span><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_chip1', 'Open Mon to Fri, 9 AM to 5 PM')) ?></span>
      <span><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_chip2', 'Capas, Tarlac')) ?></span>
      <span><?= htmlspecialchars(getSiteSetting($siteSettings, 'hero_chip3', 'RA 10173 compliant')) ?></span>
    </div>
  </div>
  <div class="lenses" role="img" aria-label="Photos of the clinic framed in a pair of glasses">
    <div class="lens ph" style="background-image:url(<?= htmlspecialchars($lensLeft) ?>);background-position:60% 50%"></div>
    <div class="bridge"></div>
    <div class="lens b ph" style="background-image:url(<?= htmlspecialchars($lensRight) ?>);background-position:25% 50%"></div>
  </div>
</div></div>

<section id="services"><div class="wrap">
  <h2 class="title"><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_header_title', 'Everything for your eyes, under one roof')) ?></h2>
  <p class="sub"><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_header_sub', 'From your first check to the pair you walk out wearing.')) ?></p>
  <div class="bento">
    <div class="tile t1">
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t1_title', 'Eye exams that take their time')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t1_desc', 'A thorough check and a prescription you can trust. Once a year for most people, every six months if you wear contacts or live on screens.')) ?></p>
    </div>
    <div class="tile t2 reveal" style="background-image:linear-gradient(rgba(10,17,34,.45),rgba(10,17,34,.72)),url(<?= htmlspecialchars($bentoT2Img) ?>);background-size:cover;background-position:30% 75%">
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t2_title', 'Eyeglasses')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t2_desc', 'Frames for every face and budget, with quality lenses fitted in the clinic.')) ?></p>
    </div>
    <div class="tile t3">
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t3_title', 'Contact lenses')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t3_desc', 'Fitting, trial, and follow-up.')) ?></p>
    </div>
    <div class="tile t4">
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t4_title', 'Aftercare')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'bento_t4_desc', 'Adjustments, repairs, and warranty support.')) ?></p>
    </div>
  </div>
</div></section>

<section id="about" class="since"><div class="wrap since-in">
  <div class="arch reveal" role="img" aria-label="Inside the clinic" style="background-image:url(<?= htmlspecialchars($aboutArchImg) ?>);background-size:cover;background-position:60% 50%">
    <b><?= htmlspecialchars(getSiteSetting($siteSettings, 'about_badge', 'Since 1986')) ?></b>
  </div>
  <div>
    <h2 class="title"><?= htmlspecialchars(getSiteSetting($siteSettings, 'about_title', 'Four decades of helping Capas see clearly')) ?></h2>
    <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'about_p1', 'Many of our patients now bring their children and parents. We have grown with the town and kept the part that matters: your optometrist knows your name.')) ?></p>
    <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'about_p2', 'Modern equipment, honest advice, and no pressure to buy more than you need.')) ?></p>
  </div>
</div></section>

<section><div class="wrap">
  <h2 class="title"><?= htmlspecialchars(getSiteSetting($siteSettings, 'steps_title', 'Your visit, start to finish')) ?></h2>
  <div class="steps">
    <div class="step">
      <i>1</i>
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'step1_title', 'Book a time')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'step1_desc', 'Pick a weekday slot through the patient portal.')) ?></p>
    </div>
    <div class="step">
      <i>2</i>
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'step2_title', 'Get your eyes checked')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'step2_desc', 'Bring your current glasses and any old prescription.')) ?></p>
    </div>
    <div class="step">
      <i>3</i>
      <h3><?= htmlspecialchars(getSiteSetting($siteSettings, 'step3_title', 'Choose your frames')) ?></h3>
      <p><?= htmlspecialchars(getSiteSetting($siteSettings, 'step3_desc', 'Try them on, then we confirm your pickup date before you pay.')) ?></p>
    </div>
  </div>
</div></section>

<section style="padding-top:0"><div class="wrap">
  <h2 class="title"><?= htmlspecialchars(getSiteSetting($siteSettings, 'gallery_title', 'Take a look around')) ?></h2>
  <p class="sub"><?= htmlspecialchars(getSiteSetting($siteSettings, 'gallery_sub', 'Swipe through the clinic.')) ?></p>
  <div class="strip" tabindex="0" aria-label="Clinic photos">
    <figure class="reveal"><div class="ph" role="img" aria-label="<?= htmlspecialchars($galleryItem1Caption) ?>" style="background-image:url(<?= htmlspecialchars($galleryItem1Img) ?>);background-position:50% 60%"></div><figcaption><?= htmlspecialchars($galleryItem1Caption) ?></figcaption></figure>
    <figure class="reveal"><div class="ph" role="img" aria-label="<?= htmlspecialchars($galleryItem2Caption) ?>" style="background-image:url(<?= htmlspecialchars($galleryItem2Img) ?>);background-position:20% 50%"></div><figcaption><?= htmlspecialchars($galleryItem2Caption) ?></figcaption></figure>
    <figure class="reveal"><div class="ph" role="img" aria-label="<?= htmlspecialchars($galleryItem3Caption) ?>" style="background-image:url(<?= htmlspecialchars($galleryItem3Img) ?>);background-position:30% 75%"></div><figcaption><?= htmlspecialchars($galleryItem3Caption) ?></figcaption></figure>
    <figure class="reveal"><div class="ph" role="img" aria-label="<?= htmlspecialchars($galleryItem4Caption) ?>" style="background-image:url(<?= htmlspecialchars($galleryItem4Img) ?>);background-position:90% 50%"></div><figcaption><?= htmlspecialchars($galleryItem4Caption) ?></figcaption></figure>
    <figure class="reveal"><div class="ph" role="img" aria-label="<?= htmlspecialchars($galleryItem5Caption) ?>" style="background-image:url(<?= htmlspecialchars($galleryItem5Img) ?>);background-position:90% 55%"></div><figcaption><?= htmlspecialchars($galleryItem5Caption) ?></figcaption></figure>
  </div>
</div></section>

<section id="faqs" style="padding-top:0"><div class="wrap faq-in">
  <aside><h2 class="title">Questions patients ask</h2><p class="sub">Still unsure? Visit us Monday to Friday, 9 AM to 5 PM.</p></aside>
  <div>
    <?php if (empty($clinicFaqs)): ?>
      <details open><summary>How often should I have an eye exam?</summary><p>Once a year is recommended. Routine checkups keep your prescription accurate. If you wear contact lenses, have an existing health condition, or spend long hours on screens, every six months may be better.</p></details>
      <details><summary>How do I book through the patient portal?</summary><p>Choose Book an appointment, sign in or register, then pick a weekday and time. We are open Monday to Friday, 9 AM to 5 PM.</p></details>
      <details><summary>What should I bring?</summary><p>Your current glasses or contact lenses, any previous prescription, and a list of medicines you take.</p></details>
      <details><summary>How long until my glasses are ready?</summary><p>It depends on the lenses and frame you choose. We give you a pickup date before you pay.</p></details>
      <details><summary>Do you offer warranty and aftercare?</summary><p>Yes. We cover adjustments and repairs. Warranty terms are in our Terms and Conditions.</p></details>
      <details><summary>Is my personal and medical information private?</summary><p>Yes. We handle your data under the Data Privacy Act of 2012 (RA 10173) and only use it for your care.</p></details>
    <?php else: ?>
      <?php foreach ($clinicFaqs as $idx => $faqItem): ?>
        <details <?= $idx === 0 ? 'open' : '' ?>>
          <summary><?= htmlspecialchars($faqItem['question']) ?></summary>
          <p><?= nl2br(htmlspecialchars($faqItem['answer'])) ?></p>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div></section>

<section id="terms" style="padding-top:0"><div class="wrap">
  <div class="close">
    <div>
      <h2><?= htmlspecialchars(getSiteSetting($siteSettings, 'close_title', 'Ready for a clearer view?')) ?></h2>
      <button type="button" class="btn" onclick="openAuthModal()">Book an appointment</button>
    </div>
    <dl><dt>Where</dt><dd>Capas, Tarlac</dd><dt>Mon to Fri</dt><dd>9:00 AM to 5:00 PM</dd><dt>Sat and Sun</dt><dd>Closed</dd></dl>
  </div>
  <p class="privacy"><?= htmlspecialchars(getSiteSetting($siteSettings, 'close_privacy', 'Our terms for appointments, eyewear warranties, and patient rights follow the Data Privacy Act of 2012 (RA 10173).')) ?> <a href="javascript:void(0)" onclick="openPrivacyModal()">Read Terms and Conditions</a></p>
</div></section>
</main>

<footer><div class="wrap foot">
  <div><b>Gueco Optical Clinic</b>Eye care and optical services<br>Capas, Tarlac, Philippines</div>
  <div><b>Pages</b><a href="#services">Services</a><a href="#about">About</a><a href="#faqs">FAQs</a><a href="javascript:void(0)" onclick="openPrivacyModal()">Terms and Conditions</a><a href="javascript:void(0)" onclick="openAuthModal()">Patient login or register</a></div>
  <div><b>Hours</b>Mon to Fri, 9 AM to 5 PM<br>Sat and Sun closed<br><br>&copy; 2026 Gueco Optical Clinic</div>
</div></footer>

<?php if($isPatient): ?>
  <a class="btn float" href="patient/dashboard.php">Book an appointment</a>
<?php else: ?>
  <button type="button" class="btn float" onclick="openAuthModal()">Book an appointment</button>
<?php endif; ?>

<!-- AUTHENTICATION MODAL -->
<div class="modal-overlay <?= $showModal ? 'open' : '' ?>" id="authModal">
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-title-wrap">
        <div class="auth-brand-emblem">
          <img src="<?= htmlspecialchars(getClinicLogoUrl()) ?>" alt="Gueco Optical Logo">
        </div>
        <div>
          <h2 class="auth-title">Patient Portal</h2>
          <p class="auth-sub">Gueco Optical Clinic &bull; Appointments &amp; Care</p>
        </div>
      </div>
      <button type="button" class="close-btn" onclick="closeAuthModal()" title="Close"><i class="fas fa-times"></i></button>
    </div>
    
    <div class="auth-tabs">
      <button type="button" class="auth-tab <?= $tab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">
        <i class="fas fa-sign-in-alt"></i> Login
      </button>
      <button type="button" class="auth-tab <?= $tab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">
        <i class="fas fa-user-plus"></i> Register
      </button>
    </div>

    <!-- LOGIN PANEL -->
    <div class="auth-panel <?= $tab === 'login' ? 'active' : '' ?>" id="panel-login">
      <p class="auth-panel-sub">
        Sign in to your patient account to schedule and manage optical appointments.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-envelope"></i>Email Address</label>
          <input type="email" name="email" id="loginEmail" class="form-control" placeholder="name@example.com" value="<?= htmlspecialchars($_POST['email'] ?? ($_GET['email'] ?? ($_SESSION['prefill_email'] ?? ''))) ?>" required autocomplete="email">
        </div>
        <div class="form-group">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <label class="form-label" style="margin-bottom:0;"><i class="fas fa-lock"></i>Password</label>
            <a href="#" onclick="switchTab('forgot')" class="auth-forgot-link">Forgot Password?</a>
          </div>
          <div class="form-pass-wrap">
            <input type="password" id="loginPass" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password" style="padding-right:48px">
            <button type="button" class="pass-toggle" data-toggle-pass="loginPass" title="Toggle password visibility">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:6px;">
          <span>Sign In</span>
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>

      <div class="auth-divider">
        <span>or</span>
      </div>

      <!-- Continue with Google at BOTTOM -->
      <a href="google-auth.php" class="btn-google-auth">
        <svg class="google-svg" viewBox="0 0 48 48" width="22" height="22">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.55 10.78l7.98-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          <path fill="none" d="M0 0h48v48H0z"/>
        </svg>
        <span>Continue with Google</span>
      </a>
    </div>

    <!-- FORGOT PASSWORD PANEL -->
    <div class="auth-panel <?= $tab === 'forgot' ? 'active' : '' ?>" id="panel-forgot">
      <p class="auth-panel-sub">
        Enter your email address to receive an OTP code to reset your password.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="forgot">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-envelope"></i>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter your email" required value="<?= (isset($_POST['action']) && $_POST['action'] === 'forgot') ? htmlspecialchars($_POST['email'] ?? '') : '' ?>">
        </div>
        <button type="submit" class="btn-primary" style="margin-top:6px;">
          <i class="fas fa-paper-plane"></i> Send OTP Code
        </button>
        <div style="text-align: center; margin-top: 16px;">
          <a href="#" onclick="switchTab('login')" style="font-size: .94rem; color: var(--text-secondary); text-decoration: none; font-weight: 700;"><i class="fas fa-arrow-left me-1"></i> Back to Login</a>
        </div>
      </form>
    </div>

    <!-- OTP PANEL -->
    <div class="auth-panel <?= $tab === 'otp' ? 'active' : '' ?>" id="panel-otp">
      <p class="auth-panel-sub">
        Enter the 6-digit OTP code sent to your email.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="otp">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-key"></i>Verification Code</label>
          <input type="text" name="otp" class="form-control" placeholder="6-digit code" required maxlength="6" style="letter-spacing:6px; font-size:1.35rem; font-weight:800; text-align:center;">
        </div>
        <button type="submit" class="btn-primary" style="margin-top:6px;">
          <i class="fas fa-check"></i> Verify OTP
        </button>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
          <a href="#" onclick="switchTab('forgot')" style="font-size: .9rem; color: var(--text-secondary); text-decoration: none; font-weight: 600;"><i class="fas fa-redo me-1"></i> Resend Code</a>
          <a href="#" onclick="switchTab('login')" style="font-size: .9rem; color: var(--text-secondary); text-decoration: none; font-weight: 600;"><i class="fas fa-arrow-left me-1"></i> Back to Login</a>
        </div>
      </form>
    </div>

    <!-- NEW PASSWORD PANEL -->
    <div class="auth-panel <?= $tab === 'new-password' ? 'active' : '' ?>" id="panel-new-password">
      <p class="auth-panel-sub">
        Create a new secure password for your account.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="new-password">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock"></i>New Password <span style="color:var(--clr-danger)">*</span></label>
          <div class="form-pass-wrap">
            <input type="password" id="newPass" name="password" class="form-control" placeholder="At least 8 characters" required minlength="8" style="padding-right:48px">
            <button type="button" class="pass-toggle" data-toggle-pass="newPass">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock"></i>Confirm Password <span style="color:var(--clr-danger)">*</span></label>
          <input type="password" id="newConfirmPass" name="confirm_password" class="form-control" placeholder="Repeat password" required minlength="8">
        </div>

        <!-- Real-time Password Security Requirements for Reset -->
        <div class="pass-req-box" id="newPassRules">
          <div class="pass-req-header"><i class="fas fa-shield-halved"></i> Password Security Requirements:</div>
          <div class="pass-req-grid">
            <div class="pass-req-item" id="newReqLength"><i class="fas fa-circle-xmark"></i> At least 8 characters</div>
            <div class="pass-req-item" id="newReqUpper"><i class="fas fa-circle-xmark"></i> At least 1 capital letter (A–Z)</div>
            <div class="pass-req-item" id="newReqSpecial"><i class="fas fa-circle-xmark"></i> At least 1 special char (!@#$...)</div>
            <div class="pass-req-item" id="newReqMatch"><i class="fas fa-circle-xmark"></i> Passwords match</div>
          </div>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:6px;">
          <i class="fas fa-save"></i> Save New Password
        </button>
        <div style="text-align:center; margin-top:16px;">
          <a href="#" onclick="switchTab('login')" style="font-size: .9rem; color: var(--text-secondary); text-decoration: none; font-weight: 600;"><i class="fas fa-arrow-left me-1"></i> Back to Login</a>
        </div>
      </form>
    </div>

    <!-- REGISTER PANEL -->
    <div class="auth-panel <?= $tab === 'register' ? 'active' : '' ?>" id="panel-register">
      <form method="POST">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        
        <div class="form-group">
          <label class="form-label"><i class="fas fa-envelope"></i>Email Address <span style="color:var(--clr-danger)">*</span></label>
          <input type="email" name="email" class="form-control <?= $errorField === 'email' ? 'is-invalid' : '' ?>" placeholder="e.g. yourname@gmail.com" value="<?= (isset($_POST['action']) && $_POST['action'] === 'register') ? htmlspecialchars($_POST['email'] ?? '') : '' ?>" required autocomplete="email">
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label"><i class="fas fa-lock"></i>Password <span style="color:var(--clr-danger)">*</span></label>
            <div class="form-pass-wrap">
              <input type="password" id="regPass" name="password" class="form-control <?= $errorField === 'password' ? 'is-invalid' : '' ?>" placeholder="Password" required minlength="8" autocomplete="new-password" style="padding-right:48px">
              <button type="button" class="pass-toggle" data-toggle-pass="regPass" title="Toggle password visibility">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label"><i class="fas fa-shield-alt"></i>Confirm <span style="color:var(--clr-danger)">*</span></label>
            <div class="form-pass-wrap">
              <input type="password" id="regConfirmPass" name="confirm_password" class="form-control <?= $errorField === 'confirm_password' ? 'is-invalid' : '' ?>" placeholder="Confirm Password" required minlength="8" autocomplete="new-password" style="padding-right:48px">
              <button type="button" class="pass-toggle" data-toggle-pass="regConfirmPass" title="Toggle password visibility">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Real-time Password Security Requirements -->
        <div class="pass-req-box" id="regPassRules">
          <div class="pass-req-header"><i class="fas fa-shield-halved"></i> Password Security Requirements:</div>
          <div class="pass-req-grid">
            <div class="pass-req-item" id="regReqLength"><i class="fas fa-circle-xmark"></i> At least 8 characters</div>
            <div class="pass-req-item" id="regReqUpper"><i class="fas fa-circle-xmark"></i> At least 1 capital letter (A–Z)</div>
            <div class="pass-req-item" id="regReqSpecial"><i class="fas fa-circle-xmark"></i> At least 1 special char (!@#$...)</div>
            <div class="pass-req-item" id="regReqMatch"><i class="fas fa-circle-xmark"></i> Passwords match</div>
          </div>
        </div>
        
        <!-- Terms and Conditions Consent -->
        <div class="form-group" style="margin-top: 6px; margin-bottom: 16px;">
          <div style="display: flex; align-items: flex-start; gap: 10px; font-size: 0.92rem; color: var(--text-secondary); line-height: 1.5; font-weight: 500;">
            <input type="checkbox" name="privacy_consent" id="privacyConsent" class="<?= $errorField === 'privacy_consent' ? 'is-invalid' : '' ?>" value="1" required style="margin-top: 3px; cursor: pointer; accent-color: var(--clr-primary); width: 18px; height: 18px; flex-shrink: 0;">
            <label for="privacyConsent" style="cursor: pointer;">
              I agree to the <a href="javascript:void(0)" onclick="openPrivacyModal()" style="color: var(--clr-primary); font-weight: 700; text-decoration: underline;">Terms and Conditions</a> for optical care services and portal access. <span style="color:var(--clr-danger)">*</span>
            </label>
          </div>
        </div>

        <button type="submit" class="btn-primary" style="margin-bottom: 2px;">
          <span>Create Account & Verify Email</span>
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>

      <div class="auth-divider">
        <span>or</span>
      </div>

      <!-- Continue with Google at BOTTOM -->
      <a href="google-auth.php" class="btn-google-auth">
        <svg class="google-svg" viewBox="0 0 48 48" width="22" height="22">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.55 10.78l7.98-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          <path fill="none" d="M0 0h48v48H0z"/>
        </svg>
        <span>Sign up with Google</span>
      </a>
    </div>

  </div>
</div>

<!-- TERMS AND CONDITIONS MODAL -->
<div class="modal-overlay" id="privacyModal" style="z-index: 100000;">
  <div class="privacy-card">
    <!-- Header -->
    <div class="privacy-header">
      <div style="display:flex; align-items:center; gap:16px;">
        <div class="privacy-icon-box">
          <i class="fas fa-file-contract"></i>
        </div>
        <div>
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px; flex-wrap:wrap;">
            <h3 class="privacy-header-title">Terms &amp; Conditions</h3>
            <span class="privacy-badge"><i class="fas fa-shield-check"></i> Patient Agreement</span>
          </div>
          <div class="privacy-header-sub">
            Gueco Optical Clinic &bull; Clinical Care &amp; Patient Portal Policies
          </div>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="optical-zoom-ctrl" title="Adjust text size for easier reading">
          <span class="optical-zoom-label"><i class="fas fa-eye me-1"></i>Text</span>
          <button type="button" class="optical-zoom-btn active" onclick="setTermsFontSize('normal')" title="Standard Size">A</button>
          <button type="button" class="optical-zoom-btn" onclick="setTermsFontSize('large')" title="Large Size (Clear Vision)">A+</button>
          <button type="button" class="optical-zoom-btn" onclick="setTermsFontSize('xlarge')" title="Extra Large Size (Maximum Clarity)">A++</button>
        </div>
        <button class="close-btn" onclick="closePrivacyModal()" title="Close"><i class="fas fa-times"></i></button>
      </div>
    </div>

    <!-- Body -->
    <div class="privacy-body">
      <!-- Callout Notice -->
      <div class="terms-callout">
        <div class="terms-callout-icon">
          <i class="fas fa-glasses"></i>
        </div>
        <div>
          Welcome to <strong>Gueco Optical Clinic</strong>. By booking appointments, receiving optometric consultations, or ordering prescription eyewear through our clinic and patient portal, you agree to the care and service terms outlined below.
        </div>
      </div>

      <!-- Section 1 -->
      <div class="terms-section">
        <div class="terms-sec-head">
          <span class="terms-sec-num">01</span>
          <h4 class="terms-sec-title">Appointments &amp; Clinic Visits</h4>
        </div>
        <p class="terms-sec-intro">
          We respect your time and provide focused clinical attention during every eye consultation:
        </p>
        <ul class="terms-list">
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Prompt Arrival:</strong> Please arrive at least 10 minutes prior to your scheduled slot. A 15-minute grace period is observed before slot reallocation.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Notice for Rescheduling:</strong> If you cannot make your appointment, please reschedule or cancel at least 24 hours in advance via your patient portal.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Emergency Schedule Changes:</strong> In rare cases of optometrist emergencies or urgent medical referrals, our clinic staff will contact you immediately to reschedule.</div>
          </li>
        </ul>
      </div>

      <!-- Section 2 -->
      <div class="terms-section">
        <div class="terms-sec-head">
          <span class="terms-sec-num">02</span>
          <h4 class="terms-sec-title">Eye Examinations &amp; Prescriptions</h4>
        </div>
        <p class="terms-sec-intro">
          All vision exams, refractions, and ophthalmic assessments are performed by licensed professional optometrists:
        </p>
        <ul class="terms-list">
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Accurate Medical History:</strong> Patients must disclose complete ocular and medical history (e.g., hypertension, diabetes, medications, eye trauma, previous surgeries) to ensure precise diagnostic care.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Prescription Validity:</strong> Optical prescriptions are certified at the examination date. Routine annual vision evaluations are recommended to monitor optical changes.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Specialist Referrals:</strong> Routine exams focus on refractive accuracy and primary eye wellness. Pathological conditions requiring surgical intervention will be referred to trusted ophthalmologists.</div>
          </li>
        </ul>
      </div>

      <!-- Section 3 -->
      <div class="terms-section">
        <div class="terms-sec-head">
          <span class="terms-sec-num">03</span>
          <h4 class="terms-sec-title">Eyewear Crafting, Dispensing &amp; Warranties</h4>
        </div>
        <p class="terms-sec-intro">
          Prescription lenses and frames are customized to your individual optical measurements:
        </p>
        <ul class="terms-list">
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Custom Tailored Lenses:</strong> Lenses are precision-cut according to your Pupillary Distance (PD), cylinder axes, and ocular focal heights.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Factory Material Warranty:</strong> Authentic frames and premium anti-glare/blue-shield lens coatings carry manufacturer warranties covering verified factory defects.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Exclusions:</strong> Scratches from rough cleaning, chemical exposure, or damage from drops and physical mishandling are not covered under warranty.</div>
          </li>
        </ul>
        <div class="terms-highlight">
          <i class="fas fa-sparkles" style="font-size:1.15rem; flex-shrink:0;"></i>
          <div><strong>Free Lifetime Maintenance:</strong> All patients enjoy complimentary nose pad replacements, screw tightening, ultrasonic cleaning, and frame adjustments anytime at our clinic.</div>
        </div>
      </div>

      <!-- Section 4 -->
      <div class="terms-section">
        <div class="terms-sec-head">
          <span class="terms-sec-num">04</span>
          <h4 class="terms-sec-title">Data Privacy &amp; Medical Record Protection</h4>
        </div>
        <p class="terms-sec-intro">
          Your personal data and health information are strictly safeguarded in compliance with the <strong>Philippine Data Privacy Act of 2012 (RA 10173)</strong>:
        </p>
        <ul class="terms-list">
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Strict Medical Confidentiality:</strong> Your diagnostic records, refraction measurements, and personal contacts are encrypted and accessible only to authorized clinic personnel.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Zero Commercial Sharing:</strong> We never sell, lease, or distribute patient records or phone numbers to third-party marketing entities.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Full Patient Access:</strong> You hold the legal right to review, update, or request copies of your optical history and examination records anytime through your patient account.</div>
          </li>
        </ul>
      </div>

      <!-- Section 5 -->
      <div class="terms-section">
        <div class="terms-sec-head">
          <span class="terms-sec-num">05</span>
          <h4 class="terms-sec-title">Patient Portal Security &amp; Conduct</h4>
        </div>
        <p class="terms-sec-intro">
          To ensure clinical safety and convenient scheduling for everyone:
        </p>
        <ul class="terms-list">
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Account Protection:</strong> Do not share your login credentials or OTP security verification codes with anyone.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Respectful Environment:</strong> Mutual respect and courteous interaction are expected between patients and staff at all times, online and in-clinic.</div>
          </li>
          <li class="terms-list-item">
            <span class="terms-bullet-icon"><i class="fas fa-check"></i></span>
            <div><strong>Booking Integrity:</strong> Repeated fake appointments or fraudulent reservations may result in temporary or permanent portal suspension.</div>
          </li>
        </ul>
      </div>

      <!-- Support Notice -->
      <div style="font-size:0.92rem; color:var(--text-muted); text-align:center; padding:8px 0; font-weight:600;">
        Have questions about our terms or clinic policies? Visit <strong>Gueco Optical Clinic</strong> in Capas, Tarlac or contact our eye care team.
      </div>
    </div>

    <!-- Footer -->
    <div class="privacy-footer">
      <button type="button" class="btn-terms-close" onclick="closePrivacyModal()">
        <i class="fas fa-times me-1"></i> Close
      </button>
      <button type="button" class="btn-terms-agree" onclick="acceptPrivacyAndClose()">
        <i class="fas fa-check-circle"></i> I Understand &amp; Agree to Terms
      </button>
    </div>
  </div>
</div>

<script>
// Prevent browser "Resubmit the form?" dialog on page refresh after POST
if (window.history.replaceState) {
  window.history.replaceState(null, null, window.location.href);
}

// Pop-up Modal Notification (Matching Admin Portal & SweetAlert2)
function showPopModal(title, msg, type = 'error') {
  if (typeof Swal !== 'undefined') {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const isErr = type === 'danger' || type === 'error' || type === true;
    const isSucc = type === 'success';
    const icon = isErr ? 'error' : (isSucc ? 'success' : (type === 'warning' ? 'warning' : 'info'));
    const header = title || (isErr ? 'Notice' : (isSucc ? 'Success!' : 'Notice'));
    const btnColor = isErr ? '#EF4444' : (isSucc ? '#10B981' : '#235EAE');
    const customCls = 'patient-swal-popup' + (isErr ? ' patient-swal-danger' : (isSucc ? ' patient-swal-success' : ''));

    Swal.fire({
      title: header,
      text: msg,
      icon: icon,
      confirmButtonText: 'OK',
      confirmButtonColor: btnColor,
      background: isDark ? '#162238' : '#FFFFFF',
      color: isDark ? '#FFFFFF' : '#0F172A',
      timer: isSucc ? 3500 : undefined,
      timerProgressBar: isSucc,
      customClass: {
        popup: customCls
      }
    });
  } else {
    alert(msg);
  }
}

// Fallback / Alias helper so any showToast calls invoke pop-up modal
function showToast(msg, isError = false) {
  const type = isError ? 'error' : 'success';
  const title = isError ? 'Notice' : 'Success!';
  showPopModal(title, msg, type);
}

<?php if ($error): ?>
  setTimeout(() => showPopModal('Notice', <?= json_encode($error) ?>, 'error'), 300);
<?php endif; ?>

<?php if (isset($_SESSION['flash_msg'])): ?>
  setTimeout(() => {
    showPopModal(
      <?= json_encode($_SESSION['flash_title'] ?? ($_SESSION['flash_type'] === 'error' ? 'Notice' : ($_SESSION['flash_type'] === 'success' ? 'Success!' : 'Notice'))) ?>,
      <?= json_encode($_SESSION['flash_msg']) ?>,
      <?= json_encode($_SESSION['flash_type'] ?? 'info') ?>
    );
    <?php if (isset($_SESSION['prefill_email']) || isset($_GET['existing'])): ?>
      const passField = document.getElementById('loginPass');
      if (passField) passField.focus();
    <?php endif; ?>
  }, 350);
  <?php 
    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
    unset($_SESSION['flash_title']);
    unset($_SESSION['prefill_email']);
  ?>
<?php endif; ?>

// FAQ Accordion logic
function toggleFaqCard(card) {
  const isAlreadyActive = card.classList.contains('active');
  document.querySelectorAll('.faq-card').forEach(c => {
    c.classList.remove('active');
    const btn = c.querySelector('.faq-trigger');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  });
  if (!isAlreadyActive) {
    card.classList.add('active');
    const btn = card.querySelector('.faq-trigger');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }
}

// Privacy Modal logic
function openPrivacyModal() {
  document.getElementById('privacyModal').classList.add('open');
}
function closePrivacyModal() {
  document.getElementById('privacyModal').classList.remove('open');
}
function acceptPrivacyAndClose() {
  const chk = document.getElementById('privacyConsent');
  if (chk) {
    chk.checked = true;
    chk.classList.remove('is-invalid');
  }
  closePrivacyModal();
}
function setTermsFontSize(scale) {
  const card = document.querySelector('.privacy-card');
  if (!card) return;
  card.classList.remove('font-large', 'font-xlarge');
  if (scale === 'large') card.classList.add('font-large');
  if (scale === 'xlarge') card.classList.add('font-xlarge');
  document.querySelectorAll('.optical-zoom-btn').forEach(btn => btn.classList.remove('active'));
  if (window.event && window.event.currentTarget) {
    window.event.currentTarget.classList.add('active');
  }
}
document.getElementById('privacyModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closePrivacyModal();
  }
});

// Modal logic
function openAuthModal() {
  document.getElementById('authModal').classList.add('open');
  document.body.style.overflow = 'hidden';
  switchTab('login'); // Always default to login tab when opening
}
function clearAuthModalInputs() {
  const modal = document.getElementById('authModal');
  if (!modal) return;
  const inputs = modal.querySelectorAll('input:not([type="hidden"]), select, textarea');
  inputs.forEach(input => {
    if (input.type === 'checkbox' || input.type === 'radio') {
      input.checked = false;
    } else {
      input.value = '';
    }
    input.classList.remove('is-invalid');
  });

  // Reset any toggled password fields back to password type
  const passInputs = modal.querySelectorAll('input[type="text"]');
  passInputs.forEach(input => {
    if (input.id && input.id.toLowerCase().includes('pass')) {
      input.type = 'password';
    }
  });
  modal.querySelectorAll('.pass-toggle').forEach(btn => {
    btn.innerHTML = '<i class="fas fa-eye"></i>';
  });
}

function closeAuthModal() {
  const modal = document.getElementById('authModal');
  if (!modal) return;

  const activePanel = modal.querySelector('.auth-panel.active');
  let hasData = false;

  // Only check for unsaved input data on panels where user is actively filling out multi-step forms (e.g. registration)
  // Simple login and forgot-password panels should never pester the user with discard prompts
  if (activePanel && (activePanel.id === 'panel-register' || activePanel.id === 'panel-new-password')) {
    const activeInputs = activePanel.querySelectorAll('input:not([type="hidden"]), select, textarea');
    activeInputs.forEach(input => {
      if (input.type === 'checkbox' || input.type === 'radio') {
        if (input.checked) hasData = true;
      } else if (input.value && input.value.trim() !== '') {
        hasData = true;
      }
    });
  }

  if (hasData) {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: 'Discard Inputted Data?',
        text: 'Are you sure you want to close? All inputted data will be cleared.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Close',
        cancelButtonText: 'Keep Editing',
        confirmButtonColor: '#EF4444',
        cancelButtonColor: isDark ? '#334155' : '#94A3B8',
        background: isDark ? '#162238' : '#FFFFFF',
        color: isDark ? '#FFFFFF' : '#0F172A',
        customClass: {
          popup: 'patient-swal-popup patient-swal-danger'
        }
      }).then((result) => {
        if (result.isConfirmed) {
          clearAuthModalInputs();
          modal.classList.remove('open');
          document.body.style.overflow = '';
          switchTab('login');
          if (window.history.replaceState) {
            const cleanUrl = window.location.pathname + (window.location.hash || '');
            window.history.replaceState(null, document.title, cleanUrl);
          }
        }
      });
      return;
    } else {
      if (!confirm('Are you sure you want to close? All inputted data will be cleared.')) {
        return;
      }
      clearAuthModalInputs();
    }
  }

  clearAuthModalInputs();
  modal.classList.remove('open');
  document.body.style.overflow = '';
  switchTab('login');
  if (window.history.replaceState) {
    const cleanUrl = window.location.pathname + (window.location.hash || '');
    window.history.replaceState(null, document.title, cleanUrl);
  }
}

// Close on outside click
document.getElementById('authModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeAuthModal();
  }
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const authModal = document.getElementById('authModal');
    if (authModal && authModal.classList.contains('open')) {
      closeAuthModal();
    }
  }
});

// If modal is open on page load (e.g. from validation error)
if (document.getElementById('authModal').classList.contains('open')) {
  document.body.style.overflow = 'hidden';
}

// Tab logic
function switchTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
  
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('active');
  
  if (tab === 'login' || tab === 'register') {
    const tabBtn = document.querySelector('.auth-tab:' + (tab === 'login' ? 'first-child' : 'last-child'));
    if (tabBtn) tabBtn.classList.add('active');
  }
}

// Password toggles
document.querySelectorAll('.pass-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    const input = document.getElementById(this.dataset.togglePass);
    if (input.type === 'password') {
      input.type = 'text';
      this.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
      input.type = 'password';
      this.innerHTML = '<i class="fas fa-eye"></i>';
    }
  });
});

// Real-time Password Security Requirements Validator
function initPasswordValidator(passId, confirmId, prefix) {
  const passEl = document.getElementById(passId);
  const confirmEl = document.getElementById(confirmId);
  const reqLen = document.getElementById(prefix + 'ReqLength');
  const reqUpp = document.getElementById(prefix + 'ReqUpper');
  const reqSpe = document.getElementById(prefix + 'ReqSpecial');
  const reqMat = document.getElementById(prefix + 'ReqMatch');

  if (!passEl) return;

  function setReq(el, ok) {
    if (!el) return;
    const icon = el.querySelector('i');
    if (ok) {
      el.classList.add('valid');
      if (icon) icon.className = 'fas fa-circle-check';
    } else {
      el.classList.remove('valid');
      if (icon) icon.className = 'fas fa-circle-xmark';
    }
  }

  function validate() {
    const val = passEl.value || '';
    const conf = confirmEl ? confirmEl.value : '';

    const hasLen = val.length >= 8;
    const hasUpp = /[A-Z]/.test(val);
    const hasSpe = /[^a-zA-Z0-9]/.test(val);
    const hasMat = val.length > 0 && conf.length > 0 && val === conf;

    setReq(reqLen, hasLen);
    setReq(reqUpp, hasUpp);
    setReq(reqSpe, hasSpe);
    if (reqMat) setReq(reqMat, hasMat);

    return hasLen && hasUpp && hasSpe && (confirmEl ? hasMat : true);
  }

  passEl.addEventListener('input', validate);
  if (confirmEl) confirmEl.addEventListener('input', validate);

  // Validate on form submission
  const form = passEl.closest('form');
  if (form) {
    form.addEventListener('submit', function(e) {
      const val = passEl.value || '';
      const conf = confirmEl ? confirmEl.value : '';
      if (val.length < 8) {
        e.preventDefault();
        passEl.focus();
        showPopModal('Security Requirement', 'Password must be at least 8 characters long.', 'warning');
        return false;
      }
      if (!/[A-Z]/.test(val)) {
        e.preventDefault();
        passEl.focus();
        showPopModal('Security Requirement', 'Password must contain at least one capital letter (A–Z).', 'warning');
        return false;
      }
      if (!/[^a-zA-Z0-9]/.test(val)) {
        e.preventDefault();
        passEl.focus();
        showPopModal('Security Requirement', 'Password must contain at least one special character (e.g. !@#$%^&*).', 'warning');
        return false;
      }
      if (confirmEl && val !== conf) {
        e.preventDefault();
        confirmEl.focus();
        showPopModal('Notice', 'Passwords do not match. Please verify both password fields.', 'warning');
        return false;
      }
    });
  }
}

initPasswordValidator('regPass', 'regConfirmPass', 'reg');
initPasswordValidator('newPass', 'newConfirmPass', 'new');

// Theme logic
function updateThemeIcons(theme) {
  const iconClass = (theme === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = iconClass;
  const iconMobile = document.getElementById('themeIconMobile');
  if (iconMobile) iconMobile.className = iconClass;
}

function applyLandingTheme(theme) {
  if (theme !== 'light' && theme !== 'dark') theme = 'dark';
  document.documentElement.setAttribute('data-theme', theme);
  try {
    localStorage.setItem('gueco_theme', theme);
    localStorage.setItem('gueco-theme', theme);
    localStorage.setItem('guecoTheme', theme);
    localStorage.setItem('theme', theme);
    document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
    document.cookie = "theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
  } catch(e) {}
  updateThemeIcons(theme);
}

const savedTheme = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || '<?= $currentTheme ?>';
applyLandingTheme(savedTheme);

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme');
  const isDark = current ? current === 'dark' : (window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches);
  const next = isDark ? 'light' : 'dark';
  applyLandingTheme(next);
}

document.getElementById('theme')?.addEventListener('click', toggleTheme);
document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
document.getElementById('themeToggleMobile')?.addEventListener('click', toggleTheme);
// ── SCROLL REVEAL (from reference template) ────────
(function(){
  var els = document.querySelectorAll('.reveal');
  els.forEach(function(e, i){
    if(e.parentNode && e.parentNode.classList.contains('strip')){
      e.style.setProperty('--d', (Array.prototype.indexOf.call(e.parentNode.children, e) * .12) + 's');
    }
  });
  if('IntersectionObserver' in window){
    var io = new IntersectionObserver(function(en){
      en.forEach(function(x){
        if(x.isIntersecting){
          x.target.classList.add('in');
          io.unobserve(x.target);
        }
      });
    }, {threshold: .05});
    els.forEach(function(e){ io.observe(e); });
  } else {
    els.forEach(function(e){ e.classList.add('in'); });
  }
})();

// ── LIVE PREVIEW POSTMESSAGE LISTENER (ADMIN CONTENT STUDIO) ──
window.addEventListener('message', function(event) {
  if (!event.data || event.data.type !== 'UPDATE_PREVIEW') return;
  var d = event.data.data;
  if (!d) return;

  // Update Hero
  var h1 = document.querySelector('.hero h1');
  if (h1) {
    var start = d.hero_headline_start !== undefined ? d.hero_headline_start : 'Good eyes,';
    var hl    = d.hero_highlight !== undefined ? d.hero_highlight : 'great frames,';
    var end   = d.hero_headline_end !== undefined ? d.hero_headline_end : 'and a clinic you know.';
    h1.innerHTML = (start ? start + ' ' : '') + '<em>' + hl + '</em>' + (end ? ' ' + end : '');
  }
  var lede = document.querySelector('.hero p.lede');
  if (lede && d.hero_description !== undefined) lede.textContent = d.hero_description;

  var chips = document.querySelectorAll('.hero .chips span');
  if (chips.length >= 3) {
    if (d.hero_chip1 !== undefined) chips[0].textContent = d.hero_chip1;
    if (d.hero_chip2 !== undefined) chips[1].textContent = d.hero_chip2;
    if (d.hero_chip3 !== undefined) chips[2].textContent = d.hero_chip3;
  }
  var lensLeft = document.querySelector('.lenses .lens:not(.b)');
  if (lensLeft && d.hero_lens_left) lensLeft.style.backgroundImage = 'url(' + d.hero_lens_left + ')';
  var lensRight = document.querySelector('.lenses .lens.b');
  if (lensRight && d.hero_lens_right) lensRight.style.backgroundImage = 'url(' + d.hero_lens_right + ')';

  // Update Bento
  var bentoTitle = document.querySelector('#services .title');
  if (bentoTitle && d.bento_header_title !== undefined) bentoTitle.textContent = d.bento_header_title;
  var bentoSub = document.querySelector('#services .sub');
  if (bentoSub && d.bento_header_sub !== undefined) bentoSub.textContent = d.bento_header_sub;

  var t1H3 = document.querySelector('#services .tile.t1 h3');
  var t1P  = document.querySelector('#services .tile.t1 p');
  if (t1H3 && d.bento_t1_title !== undefined) t1H3.textContent = d.bento_t1_title;
  if (t1P && d.bento_t1_desc !== undefined) t1P.textContent = d.bento_t1_desc;

  var t2H3 = document.querySelector('#services .tile.t2 h3');
  var t2P  = document.querySelector('#services .tile.t2 p');
  if (t2H3 && d.bento_t2_title !== undefined) t2H3.textContent = d.bento_t2_title;
  if (t2P && d.bento_t2_desc !== undefined) t2P.textContent = d.bento_t2_desc;
  if (d.bento_t2_img) {
    var t2 = document.querySelector('#services .tile.t2');
    if (t2) t2.style.backgroundImage = 'linear-gradient(rgba(10,17,34,.45),rgba(10,17,34,.72)),url(' + d.bento_t2_img + ')';
  }

  var t3H3 = document.querySelector('#services .tile.t3 h3');
  var t3P  = document.querySelector('#services .tile.t3 p');
  if (t3H3 && d.bento_t3_title !== undefined) t3H3.textContent = d.bento_t3_title;
  if (t3P && d.bento_t3_desc !== undefined) t3P.textContent = d.bento_t3_desc;

  var t4H3 = document.querySelector('#services .tile.t4 h3');
  var t4P  = document.querySelector('#services .tile.t4 p');
  if (t4H3 && d.bento_t4_title !== undefined) t4H3.textContent = d.bento_t4_title;
  if (t4P && d.bento_t4_desc !== undefined) t4P.textContent = d.bento_t4_desc;

  // Update About
  var archBadge = document.querySelector('#about .arch b');
  if (archBadge && d.about_badge !== undefined) archBadge.textContent = d.about_badge;
  var archImg = document.querySelector('#about .arch');
  if (archImg && d.about_arch_img) archImg.style.backgroundImage = 'url(' + d.about_arch_img + ')';

  var aboutTitle = document.querySelector('#about .title');
  if (aboutTitle && d.about_title !== undefined) aboutTitle.textContent = d.about_title;
  var aboutPs = document.querySelectorAll('#about p');
  if (aboutPs.length >= 2) {
    if (d.about_p1 !== undefined) aboutPs[0].textContent = d.about_p1;
    if (d.about_p2 !== undefined) aboutPs[1].textContent = d.about_p2;
  }

  // Update Steps
  var stepsTitle = document.querySelector('#top > section:nth-of-type(3) .title');
  if (stepsTitle && d.steps_title !== undefined) stepsTitle.textContent = d.steps_title;
  var steps = document.querySelectorAll('.steps .step');
  if (steps.length >= 3) {
    if (d.step1_title !== undefined && steps[0].querySelector('h3')) steps[0].querySelector('h3').textContent = d.step1_title;
    if (d.step1_desc !== undefined && steps[0].querySelector('p')) steps[0].querySelector('p').textContent = d.step1_desc;
    if (d.step2_title !== undefined && steps[1].querySelector('h3')) steps[1].querySelector('h3').textContent = d.step2_title;
    if (d.step2_desc !== undefined && steps[1].querySelector('p')) steps[1].querySelector('p').textContent = d.step2_desc;
    if (d.step3_title !== undefined && steps[2].querySelector('h3')) steps[2].querySelector('h3').textContent = d.step3_title;
    if (d.step3_desc !== undefined && steps[2].querySelector('p')) steps[2].querySelector('p').textContent = d.step3_desc;
  }

  // Update Gallery
  var galleryTitle = document.querySelector('.strip')?.closest('section')?.querySelector('.title');
  if (galleryTitle && d.gallery_title !== undefined) galleryTitle.textContent = d.gallery_title;
  var gallerySub = document.querySelector('.strip')?.closest('section')?.querySelector('.sub');
  if (gallerySub && d.gallery_sub !== undefined) gallerySub.textContent = d.gallery_sub;

  var stripFigs = document.querySelectorAll('.strip figure');
  if (stripFigs.length >= 5) {
    for (var gi = 1; gi <= 5; gi++) {
      var fig = stripFigs[gi - 1];
      var capVal = d['gallery_item' + gi + '_caption'];
      var imgVal = d['gallery_item' + gi + '_img'];
      if (capVal !== undefined) {
        var figCap = fig.querySelector('figcaption');
        if (figCap) figCap.textContent = capVal;
        var phEl = fig.querySelector('.ph');
        if (phEl) phEl.setAttribute('aria-label', capVal);
      }
      if (imgVal) {
        var phEl = fig.querySelector('.ph');
        if (phEl) phEl.style.backgroundImage = 'url(' + imgVal + ')';
      }
    }
  }

  // Update Close
  var closeH2 = document.querySelector('#terms .close h2');
  if (closeH2 && d.close_title !== undefined) closeH2.textContent = d.close_title;
  var closeP = document.querySelector('#terms .privacy');
  if (closeP && d.close_privacy !== undefined) {
    closeP.innerHTML = d.close_privacy + ' <a href="javascript:void(0)" onclick="openPrivacyModal()">Read Terms and Conditions</a>';
  }
});
</script>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
