<?php
/**
 * Test Suite: Certificate of Examination Creation, Storage, and Validation
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

$db = getDB();
ensureCertificateSchema($db);

$passed = 0;
$failed = 0;

function assertTest($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo " \033[32m[PASS]\033[0m " . $message . "\n";
        $passed++;
    } else {
        echo " \033[31m[FAIL]\033[0m " . $message . "\n";
        $failed++;
    }
}

echo "\n============================================================\n";
echo " RUNNING CERTIFICATE OF EXAMINATION TESTS\n";
echo "============================================================\n\n";

$createdPatientIds = [];
$createdCertIds = [];

try {
    // -------------------------------------------------------------
    // SUITE 1: Helper Functions & Certificate Number Formatting
    // -------------------------------------------------------------
    echo "--- SUITE 1: Certificate Number & Age Calculation ---\n";

    $certNo = generateCertificateNumber($db);
    $datePart = date('Ymd');
    $pattern = "/^COE-{$datePart}-\d{4}$/";
    assertTest(preg_match($pattern, $certNo) === 1, "Certificate number follows format COE-YYYYMMDD-XXXX ({$certNo}).");

    $ageTest1 = calculateAge('2000-01-15');
    $expectedAge1 = (new DateTime('2000-01-15'))->diff(new DateTime('today'))->y;
    assertTest($ageTest1 === $expectedAge1, "calculateAge calculates correct age from birthdate (expected $expectedAge1, got $ageTest1).");

    $ageTest2 = calculateAge('0000-00-00');
    assertTest($ageTest2 === null, "calculateAge returns null for invalid 0000-00-00 birthdate.");

    $ageTest3 = calculateAge('');
    assertTest($ageTest3 === null, "calculateAge returns null for empty birthdate.");

    // -------------------------------------------------------------
    // SUITE 2: Schema & Column Verification
    // -------------------------------------------------------------
    echo "\n--- SUITE 2: Examination Certificates Schema ---\n";

    $cols = $db->query("SHOW COLUMNS FROM examination_certificates")->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = [
        'id', 'certificate_no', 'patient_id', 'doctor_id', 'appointment_id',
        'certificate_date', 'patient_name', 'patient_age', 'patient_address',
        'branch', 'reason_for_exam', 'requested_by', 'purpose',
        'doctor_name', 'doctor_title', 'doctor_license_no', 'include_signature',
        'created_at', 'updated_at'
    ];

    foreach ($requiredCols as $col) {
        assertTest(in_array($col, $cols), "Schema contains required column '{$col}'.");
    }

    // -------------------------------------------------------------
    // SUITE 3: Certificate Creation & Storage
    // -------------------------------------------------------------
    echo "\n--- SUITE 3: Certificate Creation & Physical Pad Data ---\n";

    // Create a dummy patient
    $ptStmt = $db->prepare("
        INSERT INTO patients (full_name, phone, email, gender, birthdate, address, status, created_at)
        VALUES ('Juan Santos Dela Cruz', '09181234567', 'juan.delacruz@example.ph', 'male', '1998-05-20', 'Poblacion, Capas, Tarlac', 'active', NOW())
    ");
    $ptStmt->execute();
    $testPatientId = (int)$db->lastInsertId();
    $createdPatientIds[] = $testPatientId;

    $calculatedAge = calculateAge('1998-05-20');

    // Create Certificate record matching the physical pad
    $testCertNo = generateCertificateNumber($db);
    $ins = $db->prepare("
        INSERT INTO examination_certificates (
            certificate_no, patient_id, doctor_id, appointment_id,
            certificate_date, patient_name, patient_age, patient_address,
            branch, reason_for_exam, requested_by, purpose,
            doctor_name, doctor_title, doctor_license_no,
            include_signature, created_at
        ) VALUES (
            ?, ?, 1, NULL,
            CURDATE(), 'Juan Santos Dela Cruz', ?, 'Poblacion, Capas, Tarlac',
            'Poblacion, Capas, Tarlac | Cel No.: 0923-425-7857',
            'Refraction / Visual Acuity Assessment',
            'Juan Santos Dela Cruz',
            'Employment / Pre-Employment',
            'MARIA LUZ S. GUECO, O.D.',
            'OPTOMETRIST',
            'LIC. NO. 4385',
            0, NOW()
        )
    ");
    $ins->execute([$testCertNo, $testPatientId, $calculatedAge]);
    $testCertId = (int)$db->lastInsertId();
    $createdCertIds[] = $testCertId;

    // Verify stored record
    $fetchCert = $db->query("SELECT * FROM examination_certificates WHERE id = $testCertId")->fetch(PDO::FETCH_ASSOC);

    assertTest(!empty($fetchCert), "Certificate record was successfully inserted into database.");
    assertTest($fetchCert['certificate_no'] === $testCertNo, "Stored certificate_no matches generated number ({$testCertNo}).");
    assertTest($fetchCert['patient_name'] === 'Juan Santos Dela Cruz', "Stored patient_name matches patient profile.");
    assertTest((int)$fetchCert['patient_age'] === $calculatedAge, "Stored patient_age accurately reflects patient age ({$calculatedAge}).");
    assertTest($fetchCert['patient_address'] === 'Poblacion, Capas, Tarlac', "Stored patient_address matches physical certificate requirement.");
    assertTest($fetchCert['doctor_name'] === 'MARIA LUZ S. GUECO, O.D.', "Doctor sign-off name defaults to MARIA LUZ S. GUECO, O.D.");
    assertTest($fetchCert['doctor_license_no'] === 'LIC. NO. 4385', "Doctor license number defaults to LIC. NO. 4385.");
    assertTest((int)$fetchCert['include_signature'] === 0, "Digital signature is removed (0), leaving a clean blank line for physical ink signing.");

    // -------------------------------------------------------------
    // SUITE 4: Patient Profile Query Retrieval
    // -------------------------------------------------------------
    echo "\n--- SUITE 4: Retrieval for Patient Profile & History ---\n";

    $listStmt = $db->prepare("SELECT * FROM examination_certificates WHERE patient_id = ? ORDER BY id DESC");
    $listStmt->execute([$testPatientId]);
    $patientCerts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    assertTest(count($patientCerts) === 1, "Patient Profile query retrieves issued certificates for the patient.");
    assertTest($patientCerts[0]['purpose'] === 'Employment / Pre-Employment', "Retrieved certificate contains correct purpose.");

} catch (Exception $e) {
    echo "\n\033[31mFATAL TEST ERROR: " . $e->getMessage() . "\033[0m\n";
    $failed++;
} finally {
    echo "\nCleaning up test artifacts...\n";
    if (!empty($createdCertIds)) {
        $in = implode(',', array_map('intval', $createdCertIds));
        $db->exec("DELETE FROM examination_certificates WHERE id IN ($in)");
    }
    if (!empty($createdPatientIds)) {
        $in = implode(',', array_map('intval', $createdPatientIds));
        $db->exec("DELETE FROM patients WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}

echo "\n============================================================\n";
echo " TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================================\n\n";

if ($failed > 0) exit(1);
exit(0);
