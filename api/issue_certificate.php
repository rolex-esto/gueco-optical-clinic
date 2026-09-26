<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

// Access control: Doctor and Admin only
requireRole('doctor', 'admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    requireCsrfToken();
    $db = getDB();
    ensureCertificateSchema($db);

    $patientId     = (int)($_POST['patient_id'] ?? 0);
    $appointmentId = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
    $patientName   = trim(sanitize($_POST['patient_name'] ?? ''));
    $patientAge    = isset($_POST['patient_age']) && $_POST['patient_age'] !== '' ? (int)$_POST['patient_age'] : null;
    $patientAddress= trim(sanitize($_POST['patient_address'] ?? ''));
    $certDate      = trim(sanitize($_POST['certificate_date'] ?? date('Y-m-d')));
    $branch        = trim(sanitize($_POST['branch'] ?? 'Poblacion, Capas, Tarlac | Cel No.: 0923-425-7857'));
    $reasonForExam = trim(sanitize($_POST['reason_for_exam'] ?? ''));
    $requestedBy   = trim(sanitize($_POST['requested_by'] ?? ''));
    $purpose       = trim(sanitize($_POST['purpose'] ?? ''));
    $doctorName    = trim(sanitize($_POST['doctor_name'] ?? 'MARIA LUZ S. GUECO, O.D.'));
    $doctorTitle   = trim(sanitize($_POST['doctor_title'] ?? 'OPTOMETRIST'));
    $doctorLicNo   = trim(sanitize($_POST['doctor_license_no'] ?? 'LIC. NO. 4385'));
    $includeSig    = 0; // Clean physical blank line for manual pen signature

    // Fallbacks from DB if patient details are missing
    if ($patientId > 0 && (empty($patientName) || $patientAge === null || empty($patientAddress))) {
        $ptStmt = $db->prepare("SELECT full_name, birthdate, address FROM patients WHERE id = ?");
        $ptStmt->execute([$patientId]);
        $ptRow = $ptStmt->fetch(PDO::FETCH_ASSOC);
        if ($ptRow) {
            if (empty($patientName)) $patientName = $ptRow['full_name'];
            if ($patientAge === null && !empty($ptRow['birthdate'])) $patientAge = calculateAge($ptRow['birthdate']);
            if (empty($patientAddress)) $patientAddress = $ptRow['address'] ?: 'Tarlac, Philippines';
        }
    }

    // Validation
    if ($patientId <= 0) {
        throw new Exception('Patient identification is required.');
    }
    if (empty($patientName)) {
        throw new Exception('Patient full name is required.');
    }
    if ($patientAge === null || $patientAge < 0 || $patientAge > 130) {
        throw new Exception('A valid patient age in years is required.');
    }
    if (empty($patientAddress)) {
        throw new Exception('Patient residential address is required.');
    }
    if (empty($reasonForExam)) {
        throw new Exception('Reason for examination is required.');
    }
    if (empty($requestedBy)) {
        throw new Exception('Requesting person/entity is required.');
    }
    if (empty($purpose)) {
        throw new Exception('Purpose of certification is required.');
    }
    if (!strtotime($certDate)) {
        $certDate = date('Y-m-d');
    }

    // Generate unique Certificate Number
    $certNo = generateCertificateNumber($db);
    $doctorId = (int)($_SESSION['user_id'] ?? 1);

    // Insert record
    $ins = $db->prepare("
        INSERT INTO examination_certificates (
            certificate_no, patient_id, doctor_id, appointment_id,
            certificate_date, patient_name, patient_age, patient_address,
            branch, reason_for_exam, requested_by, purpose,
            doctor_name, doctor_title, doctor_license_no,
            include_signature, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, NOW()
        )
    ");
    $ins->execute([
        $certNo, $patientId, $doctorId, $appointmentId,
        $certDate, $patientName, $patientAge, $patientAddress,
        $branch, $reasonForExam, $requestedBy, $purpose,
        $doctorName ?: 'MARIA LUZ S. GUECO, O.D.',
        $doctorTitle ?: 'OPTOMETRIST',
        $doctorLicNo ?: 'LIC. NO. 4385',
        $includeSig
    ]);

    $certId = (int)$db->lastInsertId();

    // Log Activity
    logActivity(
        "Issued Certificate of Examination ({$certNo}) for patient: {$patientName} (Purpose: {$purpose})",
        "Certificates",
        $doctorId,
        'staff'
    );

    echo json_encode([
        'success'        => true,
        'certificate_id' => $certId,
        'certificate_no' => $certNo,
        'print_url'      => BASE_URL . "doctor/print_certificate.php?id={$certId}",
        'message'        => 'Certificate of Examination issued successfully.'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
