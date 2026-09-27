<?php
// ============================================================
// HELPER FUNCTIONS
// Gueco Optical Clinic Management System
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google_oauth.php';
require_once __DIR__ . '/mail.php';

// --- Session & Auth ---

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function isPatientLoggedIn(): bool {
    startSession();
    return isset($_SESSION['patient_id']);
}

function isPatient2FAVerified(): bool {
    startSession();
    return !empty($_SESSION['patient_id']) && !empty($_SESSION['patient_2fa_verified']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $currentUser = null;
    if ($currentUser !== null) {
        return $currentUser;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, full_name, role, email, phone, status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            // Synchronize active session with current database data
            $_SESSION['user_name']  = $user['full_name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $currentUser = $user;
            return $currentUser;
        }
    } catch (Exception $e) {
        // Fallback to session data
    }
    $currentUser = [
        'id'        => $_SESSION['user_id'] ?? 0,
        'full_name' => $_SESSION['user_name'] ?? 'User',
        'role'      => $_SESSION['user_role'] ?? 'guest',
        'email'     => $_SESSION['user_email'] ?? '',
    ];
    return $currentUser;
}

function getAppBaseUrl(): string {
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = (strpos($script, '/gueco-optical/') !== false) ? '/gueco-optical' : '';
    }
    return $base;
}

function requireRole(string ...$roles): void {
    startSession();
    $base = getAppBaseUrl();
    if (!isLoggedIn()) {
        header('Location: ' . $base . '/login.php');
        exit;
    }
    if (!in_array($_SESSION['user_role'], $roles)) {
        header('Location: ' . $base . '/unauthorized.php');
        exit;
    }
}

function isPatientProfileComplete(int $patientId): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        
        $hasName = (!empty(trim((string)($row['last_name'] ?? ''))) && !empty(trim((string)($row['first_name'] ?? ''))))
                   || !empty(trim((string)($row['full_name'] ?? '')));

        return $hasName
            && !empty(trim((string)($row['phone'] ?? '')))
            && !empty(trim((string)($row['address'] ?? '')))
            && !empty(trim((string)($row['gender'] ?? '')));
    } catch (Exception $e) {
        return false;
    }
}

function ensurePatientSchema(?PDO $db = null): void {
    static $checked = false;
    if ($checked) return;
    try {
        if (!$db) {
            $db = getDB();
        }
        $colStmt = $db->query("SHOW COLUMNS FROM patients");
        if ($colStmt) {
            $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('login_count', $cols)) {
                $db->exec("ALTER TABLE patients ADD COLUMN login_count INT NOT NULL DEFAULT 1");
            }
            if (!in_array('last_login_at', $cols)) {
                $db->exec("ALTER TABLE patients ADD COLUMN last_login_at DATETIME NULL");
            }
            if (!in_array('email_verified', $cols)) {
                $db->exec("ALTER TABLE patients ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!in_array('auth_provider', $cols)) {
                $db->exec("ALTER TABLE patients ADD COLUMN auth_provider VARCHAR(20) DEFAULT 'email'");
            }
        }
        $checked = true;
    } catch (Exception $e) {}
}

function ensureCertificateSchema(?PDO $db = null): void {
    static $checked = false;
    if ($checked) return;
    try {
        if (!$db) {
            $db = getDB();
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS `examination_certificates` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `certificate_no` VARCHAR(50) NOT NULL UNIQUE,
              `patient_id` INT NOT NULL,
              `doctor_id` INT NOT NULL,
              `appointment_id` INT NULL,
              `certificate_date` DATE NOT NULL,
              `patient_name` VARCHAR(255) NOT NULL,
              `patient_age` INT NULL,
              `patient_address` VARCHAR(255) NULL,
              `branch` VARCHAR(255) NOT NULL DEFAULT 'Poblacion, Capas, Tarlac | Cel No.: 0923-425-7857',
              `reason_for_exam` TEXT NOT NULL,
              `requested_by` VARCHAR(255) NOT NULL,
              `purpose` VARCHAR(255) NOT NULL,
              `doctor_name` VARCHAR(150) NOT NULL DEFAULT 'MARIA LUZ S. GUECO, O.D.',
              `doctor_title` VARCHAR(100) NOT NULL DEFAULT 'OPTOMETRIST',
              `doctor_license_no` VARCHAR(100) NOT NULL DEFAULT 'LIC. NO. 4385',
              `include_signature` TINYINT(1) NOT NULL DEFAULT 1,
              `remarks` TEXT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_cert_patient` (`patient_id`),
              INDEX `idx_cert_doctor` (`doctor_id`),
              INDEX `idx_cert_appointment` (`appointment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $checked = true;
    } catch (Exception $e) {
        error_log("Failed to ensure examination_certificates table: " . $e->getMessage());
    }
}

function ensureAppointmentsSchema(?PDO $db = null): void {
    static $checked = false;
    if ($checked) return;
    try {
        if (!$db) {
            $db = getDB();
        }
        $colStmt = $db->query("SHOW COLUMNS FROM appointments");
        if ($colStmt) {
            $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('appointment_type', $cols)) {
                $db->exec("ALTER TABLE appointments ADD COLUMN appointment_type VARCHAR(20) NOT NULL DEFAULT 'SCHEDULED' AFTER appointment_time");
            }
        }
        $statusStmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'status'");
        if ($statusStmt) {
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
            $typeStr = $statusRow['Type'] ?? '';
            if (strpos($typeStr, "'in_progress'") === false) {
                $db->exec("ALTER TABLE appointments MODIFY COLUMN status ENUM('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending'");
            }
        }
        // Ensure patients table has middle_name
        $ptColStmt = $db->query("SHOW COLUMNS FROM patients");
        if ($ptColStmt) {
            $ptCols = $ptColStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('middle_name', $ptCols)) {
                $db->exec("ALTER TABLE patients ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name");
            }
        }
        $checked = true;
    } catch (Exception $e) {
        error_log("Failed to ensure appointments schema: " . $e->getMessage());
    }
}

function ensureJobOrderSchema(?PDO $db = null): void {
    static $checked = false;
    if ($checked) return;
    try {
        if (!$db) {
            $db = getDB();
        }
        ensureAppointmentsSchema($db);
        $colStmt = $db->query("SHOW COLUMNS FROM sales");
        if ($colStmt) {
            $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('prescription_id', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN prescription_id INT(11) NULL AFTER appointment_id");
            }
            if (!in_array('payment_type', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN payment_type ENUM('full','downpayment') NOT NULL DEFAULT 'full' AFTER payment_method");
            }
            if (!in_array('deposit_amount', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_type");
            }
            if (!in_array('balance_due', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_amount");
            }
            if (!in_array('target_pickup_date', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN target_pickup_date DATE NULL AFTER balance_due");
            }
            if (!in_array('job_order_no', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN job_order_no VARCHAR(50) NULL AFTER target_pickup_date");
            }
            if (!in_array('order_status', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN order_status ENUM('completed','in_progress','ready_for_pickup','claimed') NOT NULL DEFAULT 'completed' AFTER job_order_no");
            }
            if (!in_array('status', $cols)) {
                $db->exec("ALTER TABLE sales ADD COLUMN status ENUM('completed','refunded','voided') NOT NULL DEFAULT 'completed'");
            }
            // Auto-heal any existing records where status is NULL or empty
            $db->exec("UPDATE sales SET status = 'completed' WHERE status IS NULL OR status = ''");
        }
        $colItemStmt = $db->query("SHOW COLUMNS FROM sale_items");
        if ($colItemStmt) {
            $itemCols = $colItemStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('notes', $itemCols)) {
                $db->exec("ALTER TABLE sale_items ADD COLUMN notes TEXT NULL AFTER total_price");
            }
        }
        $checked = true;
    } catch (Exception $e) {
        error_log("Failed to ensure job order schema: " . $e->getMessage());
    }
}

function ensurePrescriptionsSchema(?PDO $db = null): void {
    static $checked = false;
    if ($checked) return;
    try {
        if (!$db) {
            $db = getDB();
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS `prescriptions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `patient_id` int(11) NOT NULL,
              `doctor_id` int(11) NOT NULL,
              `record_id` int(11) DEFAULT NULL,
              `appointment_id` int(11) DEFAULT NULL,
              `od_sphere` varchar(20) DEFAULT NULL,
              `od_cylinder` varchar(20) DEFAULT NULL,
              `od_axis` varchar(20) DEFAULT NULL,
              `od_add` varchar(20) DEFAULT NULL,
              `os_sphere` varchar(20) DEFAULT NULL,
              `os_cylinder` varchar(20) DEFAULT NULL,
              `os_axis` varchar(20) DEFAULT NULL,
              `os_add` varchar(20) DEFAULT NULL,
              `pd` varchar(20) DEFAULT NULL,
              `add_power` varchar(20) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `recommendations` text DEFAULT NULL,
              `lens_type` varchar(100) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `patient_id` (`patient_id`),
              KEY `doctor_id` (`doctor_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $colStmt = $db->query("SHOW COLUMNS FROM prescriptions");
        if ($colStmt) {
            $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('appointment_id', $cols)) {
                $db->exec("ALTER TABLE prescriptions ADD COLUMN appointment_id INT(11) NULL AFTER record_id");
            }
            if (!in_array('add_power', $cols)) {
                $db->exec("ALTER TABLE prescriptions ADD COLUMN add_power VARCHAR(20) NULL AFTER pd");
            }
            if (!in_array('notes', $cols)) {
                $db->exec("ALTER TABLE prescriptions ADD COLUMN notes TEXT NULL");
            }
            if (!in_array('recommendations', $cols)) {
                $db->exec("ALTER TABLE prescriptions ADD COLUMN recommendations TEXT NULL");
            }
        }
        $checked = true;
    } catch (Exception $e) {
        error_log("Failed to ensure prescriptions schema: " . $e->getMessage());
    }
}

function generateCertificateNumber(?PDO $db = null): string {
    if (!$db) $db = getDB();
    ensureCertificateSchema($db);
    $datePrefix = date('Ymd');
    $stmt = $db->query("SELECT MAX(id) as max_id FROM examination_certificates");
    $maxId = (int)($stmt->fetch()['max_id'] ?? 0);
    $nextNum = str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
    return "COE-{$datePrefix}-{$nextNum}";
}

function calculateAge(?string $birthdate): ?int {
    if (empty($birthdate) || $birthdate === '0000-00-00') {
        return null;
    }
    try {
        $bdate = new DateTime($birthdate);
        $today = new DateTime('today');
        return $bdate->diff($today)->y;
    } catch (Exception $e) {
        return null;
    }
}

function requirePatientLogin(): void {
    startSession();
    $base = getAppBaseUrl();
    if (!isPatientLoggedIn()) {
        header('Location: ' . $base . '/index.php');
        exit;
    }
    // Check if 2FA OTP verification is complete
    if (!isPatient2FAVerified()) {
        header('Location: ' . $base . '/verify-otp.php');
        exit;
    }
    // Check if essential profile setup is complete
    if (!isPatientProfileComplete((int)$_SESSION['patient_id'])) {
        header('Location: ' . $base . '/complete-profile.php');
        exit;
    }
    ensurePatientSchema();
}

function getDashboardUrl(string $role): string {
    $base = getAppBaseUrl();
    return match($role) {
        'admin'     => $base . '/admin/dashboard.php',
        'doctor'    => $base . '/doctor/dashboard.php',
        'saleslady' => $base . '/saleslady/dashboard.php',
        default     => $base . '/login.php',
    };
}

function getRoleLabel(string $role): string {
    return match($role) {
        'admin'     => 'Administrator',
        'doctor'    => 'Optometrist',
        'saleslady' => 'Saleslady / Cashier',
        default     => ucfirst($role),
    };
}

// --- Security & Rate Limiting ---

function sanitize(?string $value): string {
    if ($value === null) return '';
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function getPatientDisplayName(array $patient): string {
    $name = trim((string)($patient['full_name'] ?? ''));
    if ($name === '') {
        $first = trim((string)($patient['first_name'] ?? ''));
        $last  = trim((string)($patient['last_name'] ?? ''));
        $name  = trim("$first $last");
    }
    if ($name === '') {
        $name = (string)($patient['email'] ?? ('Patient #' . ($patient['id'] ?? '')));
    }
    return $name;
}

/**
 * Validates and sanitizes walk-in patient registration inputs to prevent spam,
 * gibberish, or nonsense data from being entered.
 *
 * @param array $data Input data (full_name, email, phone, gender, birthdate, address)
 * @return array ['valid' => bool, 'errors' => array, 'cleaned' => array]
 */
function validateWalkinPatientData(array $data): array {
    $errors = [];
    $cleaned = [];

    // 1. NAME VALIDATION (Last Name, First Name, Middle Name)
    $rawLastName   = trim($data['last_name'] ?? '');
    $rawFirstName  = trim($data['first_name'] ?? '');
    $rawMiddleName = trim($data['middle_name'] ?? '');

    if ($rawLastName !== '' || $rawFirstName !== '') {
        // Strict Alphabetical Only: No numbers or special characters allowed
        if ($rawLastName === '') {
            $errors['last_name'] = 'Last Name is required.';
        } elseif (!preg_match("/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/u", $rawLastName)) {
            $errors['last_name'] = 'Last Name must only contain alphabetical letters (no numbers or special characters).';
        } elseif (mb_strlen($rawLastName) < 2) {
            $errors['last_name'] = 'Last Name must be at least 2 characters long.';
        }

        if ($rawFirstName === '') {
            $errors['first_name'] = 'First Name is required.';
        } elseif (!preg_match("/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/u", $rawFirstName)) {
            $errors['first_name'] = 'First Name must only contain alphabetical letters (no numbers or special characters).';
        } elseif (mb_strlen($rawFirstName) < 2) {
            $errors['first_name'] = 'First Name must be at least 2 characters long.';
        }

        if ($rawMiddleName !== '') {
            if (!preg_match("/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/u", $rawMiddleName)) {
                $errors['middle_name'] = 'Middle Name must only contain alphabetical letters (no numbers or special characters).';
            }
        }

        $cleanLast   = ucwords(strtolower(preg_replace('/\s+/', ' ', $rawLastName)));
        $cleanFirst  = ucwords(strtolower(preg_replace('/\s+/', ' ', $rawFirstName)));
        $cleanMiddle = $rawMiddleName !== '' ? ucwords(strtolower(preg_replace('/\s+/', ' ', $rawMiddleName))) : '';

        $fullName = trim($cleanFirst . ($cleanMiddle !== '' ? ' ' . $cleanMiddle : '') . ' ' . $cleanLast);
        $cleaned['last_name']   = $cleanLast;
        $cleaned['first_name']  = $cleanFirst;
        $cleaned['middle_name'] = $cleanMiddle !== '' ? $cleanMiddle : null;
        $cleaned['full_name']   = $fullName;
    } else {
        // Fallback for single full_name input
        $rawName = trim($data['full_name'] ?? '');
        $cleanName = preg_replace('/\s+/', ' ', $rawName);
        $cleanName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $cleanName);
        $cleanName = ucwords(strtolower($cleanName));

        if (empty($cleanName)) {
            $errors['full_name'] = 'Full Name is required.';
        } elseif (!preg_match("/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/u", $cleanName)) {
            $errors['full_name'] = 'Name must only contain alphabetical letters (no numbers or special characters).';
        } else {
            $nameParts = explode(' ', $cleanName);
            $cleanLast = count($nameParts) > 1 ? array_pop($nameParts) : $cleanName;
            $cleanFirst = implode(' ', $nameParts) ?: $cleanLast;
            $cleaned['last_name']   = $cleanLast;
            $cleaned['first_name']  = $cleanFirst;
            $cleaned['middle_name'] = null;
            $cleaned['full_name']   = $cleanName;
        }
    }

    // 2. EMAIL VALIDATION (OPTIONAL)
    $email = trim($data['email'] ?? '');
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address format (e.g. name@example.com).';
        } elseif (preg_match('/@(gmai|gmal|gmial|gmaill|gmil)\.com$/i', $email)) {
            $errors['email'] = 'Did you mean @gmail.com? Please check your email spelling.';
        } else {
            $cleaned['email'] = strtolower($email);
        }
    } else {
        $cleaned['email'] = ''; // Will generate walkin dummy email
    }

    // 3. PHONE NUMBER VALIDATION (NUMBERS ONLY, REQUIRED)
    $rawPhone = trim($data['phone'] ?? '');
    if (empty($rawPhone)) {
        $errors['phone'] = 'Mobile number is required.';
    } elseif (!preg_match('/^[0-9]+$/', $rawPhone)) {
        $errors['phone'] = 'Mobile number must contain digits only (no letters or special characters).';
    } else {
        $cleanPhone = $rawPhone;
        if (str_starts_with($cleanPhone, '63') && strlen($cleanPhone) === 12) {
            $cleanPhone = '0' . substr($cleanPhone, 2);
        }

        if (!preg_match('/^09\d{9}$/', $cleanPhone)) {
            $errors['phone'] = 'Mobile number must be an 11-digit Philippine mobile number starting with 09 (e.g., 09171234567).';
        } else {
            $cleaned['phone'] = $cleanPhone;
        }
    }

    // 4. SEX / GENDER VALIDATION (REQUIRED)
    $gender = trim($data['gender'] ?? ($data['sex'] ?? ''));
    if (empty($gender)) {
        $errors['gender'] = 'Sex is required. Please select an option.';
    } elseif (!in_array($gender, ['male', 'female', 'other'], true)) {
        $errors['gender'] = 'Invalid sex selected.';
    } else {
        $cleaned['gender'] = $gender;
    }

    // 5. BIRTHDATE VALIDATION (REQUIRED)
    $birthdate = trim($data['birthdate'] ?? '');
    if (empty($birthdate)) {
        $errors['birthdate'] = 'Birthdate is required.';
    } else {
        $ts = strtotime($birthdate);
        $todayTs = strtotime(date('Y-m-d'));
        $minTs = strtotime('1900-01-01');

        if ($ts === false) {
            $errors['birthdate'] = 'Invalid birthdate format.';
        } elseif ($ts > $todayTs) {
            $errors['birthdate'] = 'Birthdate cannot be in the future.';
        } elseif ($ts < $minTs) {
            $errors['birthdate'] = 'Birthdate cannot be earlier than year 1900.';
        } else {
            $cleaned['birthdate'] = date('Y-m-d', $ts);
        }
    }

    // 6. ADDRESS VALIDATION (NOT OPTIONAL)
    $rawAddress = trim($data['address'] ?? '');
    if (empty($rawAddress)) {
        $errors['address'] = 'Address is required.';
    } else {
        $cleanAddress = preg_replace('/\s+/', ' ', $rawAddress);
        if (mb_strlen($cleanAddress) < 3) {
            $errors['address'] = 'Address must be at least 3 characters long.';
        } elseif (mb_strlen($cleanAddress) > 255) {
            $errors['address'] = 'Address cannot exceed 255 characters.';
        } else {
            $cleaned['address'] = $cleanAddress;
        }
    }

    return [
        'valid'   => empty($errors),
        'errors'  => $errors,
        'cleaned' => $cleaned
    ];
}

/**
 * Creates an end-to-end Walk-in Patient & Appointment record.
 * 
 * - Date & Time: Automatically sets date to TODAY and scheduled_time to current timestamp.
 * - Appointment Type: 'WALK_IN'.
 * - Initial Status: 'confirmed' (Waiting) or 'in_progress' (Examining Now). Never 'pending'.
 *
 * @param PDO $db
 * @param array $patientInput Data array containing either patient_id OR new patient fields
 * @param string $initialStatus 'confirmed' or 'in_progress'
 * @param int $staffUserId
 * @param string $purpose
 * @return array ['success' => bool, 'error' => ?string, 'patient_id' => int, 'appointment_id' => int, 'status' => string]
 */
function createWalkinAppointment(PDO $db, array $patientInput, string $initialStatus = 'confirmed', int $staffUserId = 0, string $purpose = 'consultation'): array {
    ensureAppointmentsSchema($db);
    $initialStatus = strtolower(trim($initialStatus));
    // Walk-ins must NEVER be 'pending'. Default to 'confirmed' if invalid or pending passed.
    if ($initialStatus !== 'in_progress') {
        $initialStatus = 'confirmed';
    }

    $validPurposes = ['consultation', 'eyeglass_claim', 'follow_up', 'contact_lens_fitting', 'other'];
    if (!in_array($purpose, $validPurposes, true)) {
        $purpose = 'consultation';
    }

    $existingPatientId = (int)($patientInput['patient_id'] ?? 0);
    $patientId = 0;
    $patientName = '';
    $patientPhone = '';

    if ($existingPatientId > 0) {
        $ptStmt = $db->prepare("SELECT id, full_name, phone FROM patients WHERE id = ?");
        $ptStmt->execute([$existingPatientId]);
        $pt = $ptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pt) {
            return ['success' => false, 'error' => 'Selected patient does not exist.'];
        }
        $patientId = (int)$pt['id'];
        $patientName = $pt['full_name'];
        $patientPhone = $pt['phone'] ?? '';
    } else {
        // Register new walk-in patient with validation
        $val = validateWalkinPatientData($patientInput);
        if (!$val['valid']) {
            return ['success' => false, 'error' => reset($val['errors']), 'errors' => $val['errors']];
        }

        $cleaned    = $val['cleaned'];
        $firstName  = $cleaned['first_name'] ?? '';
        $middleName = $cleaned['middle_name'] ?? null;
        $lastName   = $cleaned['last_name'] ?? '';
        $fullName   = $cleaned['full_name'];
        $email      = $cleaned['email'];
        $phone      = $cleaned['phone'];
        $address    = $cleaned['address'];
        $gender     = $cleaned['gender'];
        $birthdate  = $cleaned['birthdate'];

        if (empty($email)) {
            $email = 'walkin_' . time() . '_' . rand(100, 999) . '@guest.gueco.local';
        }

        // Check if email taken
        $check = $db->prepare("SELECT id FROM patients WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            return ['success' => false, 'error' => 'Email address is already registered.'];
        }

        if (empty($firstName) || empty($lastName)) {
            $nameParts = explode(' ', $fullName);
            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : $fullName;
            $firstName = implode(' ', $nameParts) ?: $lastName;
        }
        $randomPass = bin2hex(random_bytes(6));

        $insertPt = $db->prepare("
            INSERT INTO patients (first_name, middle_name, last_name, full_name, email, password, phone, address, gender, birthdate, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insertPt->execute([
            $firstName,
            $middleName,
            $lastName,
            $fullName,
            $email,
            password_hash($randomPass, PASSWORD_DEFAULT),
            $phone,
            $address,
            $gender,
            $birthdate
        ]);
        $patientId = (int)$db->lastInsertId();
        $patientName = $fullName;
        $patientPhone = $phone ?? '';
    }

    $today = date('Y-m-d');
    $currentTime = date('H:i:s');
    $notes = trim($patientInput['notes'] ?? 'Walk-in registration at clinic queue.');

    $insertAppt = $db->prepare("
        INSERT INTO appointments (patient_id, appointment_date, appointment_time, appointment_type, purpose, status, notes, verified_by, created_at)
        VALUES (?, ?, ?, 'WALK_IN', ?, ?, ?, ?, NOW())
    ");
    $insertAppt->execute([
        $patientId,
        $today,
        $currentTime,
        $purpose,
        $initialStatus,
        $notes,
        $staffUserId ?: null
    ]);
    $apptId = (int)$db->lastInsertId();

    $statusLabel = $initialStatus === 'in_progress' ? 'In Consultation (Examining Now)' : 'Confirmed (Waiting in Queue)';
    if ($staffUserId > 0) {
        logActivity("Registered walk-in appointment #$apptId for patient $patientName (Status: $statusLabel)", "Appointments", $staffUserId, 'staff');
    }

    return [
        'success'          => true,
        'patient_id'       => $patientId,
        'patient_name'     => $patientName,
        'patient_phone'    => $patientPhone,
        'appointment_id'   => $apptId,
        'appointment_date' => $today,
        'appointment_time' => $currentTime,
        'appointment_type' => 'WALK_IN',
        'status'           => $initialStatus,
        'purpose'          => $purpose
    ];
}

function generateCsrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    startSession();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        die('Invalid or expired security token (CSRF). Please refresh the page and try again.');
    }
}

function checkRateLimit(string $key, int $maxAttempts = 5, int $decaySeconds = 900): bool {
    startSession();
    $now = time();
    if (!isset($_SESSION['rate_limits'][$key])) {
        return true;
    }
    $entry = $_SESSION['rate_limits'][$key];
    if ($now > $entry['reset_at']) {
        unset($_SESSION['rate_limits'][$key]);
        return true;
    }
    return $entry['attempts'] < $maxAttempts;
}

function recordFailedAttempt(string $key, int $decaySeconds = 900): int {
    startSession();
    $now = time();
    if (!isset($_SESSION['rate_limits'][$key]) || $now > $_SESSION['rate_limits'][$key]['reset_at']) {
        $_SESSION['rate_limits'][$key] = [
            'attempts' => 1,
            'reset_at' => $now + $decaySeconds
        ];
    } else {
        $_SESSION['rate_limits'][$key]['attempts']++;
    }
    return $_SESSION['rate_limits'][$key]['attempts'];
}

function clearRateLimit(string $key): void {
    startSession();
    if (isset($_SESSION['rate_limits'][$key])) {
        unset($_SESSION['rate_limits'][$key]);
    }
}

function getRateLimitRemainingSeconds(string $key): int {
    startSession();
    if (!isset($_SESSION['rate_limits'][$key])) return 0;
    return max(0, $_SESSION['rate_limits'][$key]['reset_at'] - time());
}

// --- Database Helpers ---

function getSetting(string $key): ?string {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : null;
}

function logActivity(string $action, string $module = '', ?int $userId = null, string $userType = 'staff'): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $stmt = $db->prepare(
            "INSERT INTO activity_logs (user_id, user_type, action, module, ip_address) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$uid, $userType, $action, $module, $ip]);
    } catch (Exception $e) {
        // Silently fail — don't break app for logging errors
    }
}

function generateInvoiceNo(): string {
    $prefix = getSetting('invoice_prefix') ?? 'GO-';
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM sales");
    $row = $stmt->fetch();
    $num = str_pad(($row['cnt'] + 1), 6, '0', STR_PAD_LEFT);
    return $prefix . date('Ymd') . '-' . $num;
}

// --- Formatting ---

function formatCurrency(float $amount): string {
    return '₱' . number_format($amount, 2);
}

function formatDate(?string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    return date('F d, Y', strtotime($date));
}

function formatDateTime(?string $datetime): string {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '—';
    return date('M d, Y h:i A', strtotime($datetime));
}

function formatTime(?string $time): string {
    if (!$time) return '—';
    return date('h:i A', strtotime($time));
}

function timeAgo(?string $datetime): string {
    if (!$datetime) return '—';
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);
        $past = new DateTime($datetime, $tz);
        
        // If the timestamp is slightly in the future due to small clock discrepancies
        if ($past > $now) {
            $diffSeconds = $past->getTimestamp() - $now->getTimestamp();
            if ($diffSeconds < 120) {
                return 'Just now';
            }
        }
        
        $diff = $now->diff($past);
        if ($diff->y > 0) return $diff->y . 'y ago';
        if ($diff->m > 0) return $diff->m . 'mo ago';
        if ($diff->d >= 7) return floor($diff->d / 7) . 'w ago';
        if ($diff->d > 0) return $diff->d . 'd ago';
        if ($diff->h > 0) return $diff->h . 'h ago';
        if ($diff->i > 0) return $diff->i . 'm ago';
        return 'Just now';
    } catch (Exception $e) {
        return formatDate($datetime);
    }
}

// --- Badge Helpers ---

function statusBadge(?string $status): string {
    $status = (string)($status ?? '');
    $map = [
        'pending'   => ['warning', 'clock'],
        'confirmed' => ['info',    'check-circle'],
        'completed' => ['success', 'check-double'],
        'cancelled' => ['danger',  'times-circle'],
        'no_show'   => ['secondary','user-times'],
        'active'    => ['success', 'circle'],
        'inactive'  => ['danger',  'circle'],
        'stock_in'  => ['success', 'arrow-up'],
        'stock_out' => ['danger',  'arrow-down'],
        'adjustment'=> ['warning', 'edit'],
    ];
    $cfg = $map[$status] ?? ['secondary', 'question-circle'];
    $label = ucwords(str_replace('_', ' ', $status ?: 'unknown'));
    return "<span class='badge bg-{$cfg[0]}'><i class='fas fa-{$cfg[1]} me-1'></i>{$label}</span>";
}

function roleBadge(?string $role): string {
    $role = (string)($role ?? '');
    $map = [
        'admin'     => ['primary',   'shield-alt', 'Administrator'],
        'doctor'    => ['info',      'user-md',    'Optometrist'],
        'saleslady' => ['secondary', 'user-tie',   'Saleslady'],
    ];
    $cfg = $map[$role] ?? ['secondary', 'user', ucfirst($role)];
    return "<span class='badge bg-{$cfg[0]}'><i class='fas fa-{$cfg[1]} me-1'></i>{$cfg[2]}</span>";
}

function tierBadge(string $tier): string {
    $tier = strtolower(trim($tier));
    return match($tier) {
        'budget' => '<span class="badge badge-tier badge-tier-budget"><i class="fas fa-tag me-1"></i>Budget</span>',
        'mid'    => '<span class="badge badge-tier badge-tier-mid"><i class="fas fa-layer-group me-1"></i>Mid Product</span>',
        'high'   => '<span class="badge badge-tier badge-tier-high"><i class="fas fa-crown me-1"></i>High Product</span>',
        default  => '<span class="badge bg-secondary"><i class="fas fa-tag me-1"></i>' . htmlspecialchars(ucfirst($tier)) . '</span>',
    };
}

function tierLabel(string $tier): string {
    $tier = strtolower(trim($tier));
    return match($tier) {
        'budget' => 'Budget Product',
        'mid'    => 'Mid Product',
        'high'   => 'High Product',
        default  => ucfirst($tier),
    };
}

// --- Pagination ---

function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = (int) ceil($total / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'pages'       => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $currentPage > 1,
        'has_next'    => $currentPage < $totalPages,
    ];
}

// --- Stats for Dashboards ---

function getDashboardStats(): array {
    $db = getDB();
    $today = date('Y-m-d');

    // Total patients
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM patients WHERE status = 'active'");
    $totalPatients = $stmt->fetch()['cnt'];

    // Today's appointments
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no_show')");
    $stmt->execute([$today]);
    $todayAppointments = $stmt->fetch()['cnt'];

    // Today's sales
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE DATE(created_at) = ? AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))");
    $stmt->execute([$today]);
    $todaySales = $stmt->fetch()['total'];

    // Low stock items
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM products WHERE stock_quantity <= low_stock_alert AND status = 'active'");
    $lowStock = $stmt->fetch()['cnt'];

    // Monthly sales
    $startMonth = date('Y-m-01');
    $endMonth   = date('Y-m-t');
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))");
    $stmt->execute([$startMonth, $endMonth]);
    $monthlySales = $stmt->fetch()['total'];

    // Pending appointments
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE appointment_date >= ? AND status = 'pending'");
    $stmt->execute([$today]);
    $pendingAppts = $stmt->fetch()['cnt'];

    return compact('totalPatients','todayAppointments','todaySales','lowStock','monthlySales','pendingAppts');
}

// --- Email ---

function sendEmailOTP(string $toEmail, string $otp, string $patientName = ''): bool {
    require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        if (isSMTPConfigured()) {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = (SMTP_ENCRYPTION === 'ssl') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            // Shared hosting SSL certificate chain tolerance
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom(SMTP_FROM_EMAIL ?: SMTP_USERNAME, SMTP_FROM_NAME ?: 'Gueco Optical Clinic');
            $mail->addReplyTo(SMTP_FROM_EMAIL ?: SMTP_USERNAME, SMTP_FROM_NAME ?: 'Gueco Optical Clinic');
            $mail->addAddress($toEmail, $patientName ?: 'Valued Patient');
            
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code — Gueco Optical Clinic';
            $greetingName  = !empty($patientName) ? "Hello " . htmlspecialchars($patientName) . "," : "Hello,";

            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 540px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #235EAE; margin: 0; font-size: 22px;'>Gueco Optical Clinic</h2>
                        <p style='color: #6B7280; font-size: 13px; margin: 4px 0 0 0;'>Password Reset Verification</p>
                    </div>
                    <p style='color: #374151; font-size: 15px;'>{$greetingName}</p>
                    <p style='color: #374151; font-size: 14px; line-height: 1.6;'>
                        We received a request to reset your patient account password. Use the 6-digit verification code below to proceed:
                    </p>
                    <div style='text-align: center; margin: 28px 0;'>
                        <span style='display: inline-block; background: #F0F4F9; color: #235EAE; font-size: 32px; font-weight: 800; letter-spacing: 8px; padding: 14px 28px; border-radius: 10px; border: 1px solid #BFDBFE;'>{$otp}</span>
                    </div>
                    <p style='color: #6B7280; font-size: 13px; margin-bottom: 8px;'>This code is valid for <b>15 minutes</b>. If you did not request a password reset, you can safely ignore this email.</p>
                    <hr style='border: none; border-top: 1px solid #F3F4F6; margin: 20px 0;'>
                    <p style='color: #9CA3AF; font-size: 12px; text-align: center; margin: 0;'>&copy; " . date('Y') . " Gueco Optical Clinic. All rights reserved.</p>
                </div>
            ";
            $mail->AltBody = "{$greetingName}\n\nYour Gueco Optical password reset code is: {$otp}\nThis code is valid for 15 minutes.\nIf you did not request this, please ignore this email.";

            $mail->send();
            return true;
        } else {
            error_log("SMTP not configured. Password reset OTP for $toEmail: $otp");
            return false;
        }
    } catch (Exception $e) {
        error_log("Password reset OTP mail error for {$toEmail}: {$mail->ErrorInfo} | Exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Send Login Verification OTP to patient email
 */
function sendLoginEmailOTP(string $toEmail, string $otp, string $patientName = ''): bool {
    require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        if (isSMTPConfigured()) {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = (SMTP_ENCRYPTION === 'ssl') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            // Shared hosting SSL certificate chain tolerance
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom(SMTP_FROM_EMAIL ?: SMTP_USERNAME, SMTP_FROM_NAME ?: 'Gueco Optical Clinic');
            $mail->addReplyTo(SMTP_FROM_EMAIL ?: SMTP_USERNAME, SMTP_FROM_NAME ?: 'Gueco Optical Clinic');
            $mail->addAddress($toEmail, $patientName ?: 'Valued Patient');
            
            $mail->isHTML(true);
            $mail->Subject = 'Your Login Verification Code — Gueco Optical Clinic';
            $nameGreeting  = !empty($patientName) ? "Hello " . htmlspecialchars($patientName) . "," : "Hello,";
            
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 540px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #235EAE; margin: 0; font-size: 22px;'>Gueco Optical Clinic</h2>
                        <p style='color: #6B7280; font-size: 13px; margin: 4px 0 0 0;'>Patient Portal Two-Factor Authentication</p>
                    </div>
                    <p style='color: #374151; font-size: 15px;'>{$nameGreeting}</p>
                    <p style='color: #374151; font-size: 14px; line-height: 1.6;'>
                        You recently attempted to sign in to your Gueco Optical Patient Account. Please enter the verification code below to complete your login:
                    </p>
                    <div style='text-align: center; margin: 28px 0;'>
                        <span style='display: inline-block; background: #F0F4F9; color: #235EAE; font-size: 32px; font-weight: 800; letter-spacing: 8px; padding: 14px 28px; border-radius: 10px; border: 1px solid #BFDBFE;'>{$otp}</span>
                    </div>
                    <p style='color: #6B7280; font-size: 13px; margin-bottom: 8px;'>This code is valid for <b>10 minutes</b>. If you did not attempt to sign in, please secure your account immediately.</p>
                    <hr style='border: none; border-top: 1px solid #F3F4F6; margin: 20px 0;'>
                    <p style='color: #9CA3AF; font-size: 12px; text-align: center; margin: 0;'>&copy; " . date('Y') . " Gueco Optical Clinic. All rights reserved.</p>
                </div>
            ";
            $mail->AltBody = "{$nameGreeting}\n\nYour Gueco Optical login verification code is: {$otp}\nThis code is valid for 10 minutes.";

            $mail->send();
            return true;
        } else {
            error_log("SMTP not configured yet. Login OTP for $toEmail: $otp");
            return false;
        }
    } catch (Exception $e) {
        error_log("Login OTP mail error: {$mail->ErrorInfo} | Exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate and issue a login OTP for patient session
 */
function issuePatientLoginOTP(array $patient): string {
    startSession();
    $otp = sprintf("%06d", mt_rand(100000, 999999));
    
    $_SESSION['patient_otp_code']    = $otp;
    $_SESSION['patient_otp_hash']    = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['patient_otp_expires'] = time() + (10 * 60); // 10 minutes
    $_SESSION['patient_otp_attempts']= 0;
    
    // Save to pending login session
    $_SESSION['patient_id_pending']  = (int)$patient['id'];
    $_SESSION['patient_name_pending']= $patient['full_name'] ?? '';
    $_SESSION['patient_email_pending']= $patient['email'];
    $_SESSION['patient_avatar_pending']= $patient['avatar'] ?? '';
    $_SESSION['patient_2fa_verified']= false;
    
    // Attempt sending via email and record delivery outcome
    $sent = sendLoginEmailOTP($patient['email'], $otp, $patient['full_name'] ?? '');
    $_SESSION['patient_otp_sent'] = $sent;
    
    return $otp;
}

/**
 * Validate password security standards:
 * - At least 8 characters long
 * - At least one capital letter (A–Z)
 * - At least one special character (!@#$%^&*, etc.)
 *
 * @param string $password
 * @return string|null Error description or null if valid
 */
function validatePasswordStrength(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one capital letter (A–Z).';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'Password must contain at least one special character (e.g. !@#$%^&*).';
    }
    return null;
}

/**
 * Get the active clinic logo URL with cache busting
 *
 * @param string $basePrefix Path prefix (e.g. '', '../', BASE_URL)
 * @return string Full relative URL to active clinic logo
 */
function getClinicLogoUrl(string $basePrefix = ''): string {
    static $cachedLogo = null;
    if ($cachedLogo !== null) {
        return $basePrefix . $cachedLogo;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'clinic_logo' LIMIT 1");
        $stmt->execute();
        $custom = $stmt->fetchColumn();
        if (!empty($custom) && file_exists(__DIR__ . '/../' . ltrim($custom, '/'))) {
            $cachedLogo = ltrim($custom, '/');
        } else {
            $cachedLogo = 'assets/images/logo.png';
        }
    } catch (Throwable $e) {
        $cachedLogo = 'assets/images/logo.png';
    }
    $realFile = __DIR__ . '/../' . $cachedLogo;
    $ver = file_exists($realFile) ? filemtime($realFile) : '2';
    $cachedLogo .= '?v=' . $ver;
    return $basePrefix . $cachedLogo;
}

