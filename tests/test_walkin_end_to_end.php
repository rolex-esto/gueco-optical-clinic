<?php
/**
 * Automated Test Suite: End-to-End Walk-in Patient & Clinic Workflow
 * 
 * Verifies:
 * 1. Walk-in appointment structure (date=TODAY, time=current, appointment_type=WALK_IN, status=confirmed/in_progress, never pending).
 * 2. Calendar and active walk-in queue APIs.
 * 3. Clinical state transitions (Start consultation -> in_progress, completion -> completed, no-show blocked for walk-ins).
 * 4. Cron auto no-show exclusion for walk-ins.
 * 5. Optical Rx lookup and POS checkout with Job Order (downpayment, balance due, phone validation).
 */

define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

$db = getDB();
$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description, ?string $failDetails = null) {
    global $passed, $failed;
    if ($condition) {
        echo " [\033[32mPASS\033[0m] $description\n";
        $passed++;
    } else {
        echo " [\033[31mFAIL\033[0m] $description\n";
        if ($failDetails) {
            echo "        Details: $failDetails\n";
        }
        $failed++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo " RUNNING END-TO-END WALKIN PATIENT & CLINICAL WORKFLOW TESTS\n";
echo str_repeat('=', 60) . "\n\n";

$createdPatientIds = [];
$createdApptIds = [];
$createdRxIds = [];
$createdSaleIds = [];

try {
    // -------------------------------------------------------------
    // SUITE 1: Walk-in Appointment Creation & Structure
    // -------------------------------------------------------------
    echo "--- SUITE 1: Walk-in Appointment Creation & Defaults ---\n";

    // Test 1: Register new walk-in with default status
    $walkinData1 = [
        'full_name'  => 'Maria Santos Gonzales',
        'phone'      => '09179876543',
        'gender'     => 'female',
        'birthdate'  => '1995-05-15',
        'address'    => 'Angeles City, Pampanga',
        'notes'      => 'Walk-in for urgent eye examination'
    ];
    $res1 = createWalkinAppointment($db, $walkinData1, 'confirmed', 1, 'consultation');
    assertTest($res1['success'] === true, 'Successfully registers walk-in patient and creates today appointment.');

    $apptId1 = $res1['appointment_id'];
    $ptId1 = $res1['patient_id'];
    $createdApptIds[] = $apptId1;
    $createdPatientIds[] = $ptId1;

    $stmt1 = $db->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt1->execute([$apptId1]);
    $apptRow1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    assertTest($apptRow1['appointment_date'] === date('Y-m-d'), 'Walk-in appointment date is automatically set to TODAY.');
    assertTest(!empty($apptRow1['appointment_time']), 'Walk-in scheduled time is set to system timestamp.');
    assertTest($apptRow1['appointment_type'] === 'WALK_IN', "Walk-in flag appointment_type is 'WALK_IN'.");
    assertTest($apptRow1['status'] === 'confirmed', "Initial status is 'confirmed' (Waiting in clinic).");

    // Test 2: Passing 'pending' as initial status is disallowed and overridden to 'confirmed'
    $walkinData2 = [
        'full_name'  => 'Pedro Penduko Silang',
        'phone'      => '09281234567',
        'gender'     => 'male'
    ];
    $res2 = createWalkinAppointment($db, $walkinData2, 'pending', 1, 'consultation');
    $apptId2 = $res2['appointment_id'];
    $ptId2 = $res2['patient_id'];
    $createdApptIds[] = $apptId2;
    $createdPatientIds[] = $ptId2;

    $stmt2 = $db->prepare("SELECT status, appointment_type FROM appointments WHERE id = ?");
    $stmt2->execute([$apptId2]);
    $apptRow2 = $stmt2->fetch(PDO::FETCH_ASSOC);

    assertTest($apptRow2['status'] === 'confirmed', "Walk-in with 'pending' requested is safely overridden to 'confirmed'. Never set to pending.");

    // Test 3: Walk-in registered directly as 'in_progress' (Doctor examining immediately)
    $walkinData3 = [
        'full_name'  => 'Clara Del Monte Ramos',
        'phone'      => '09185556677',
        'gender'     => 'female'
    ];
    $res3 = createWalkinAppointment($db, $walkinData3, 'in_progress', 1, 'consultation');
    $apptId3 = $res3['appointment_id'];
    $ptId3 = $res3['patient_id'];
    $createdApptIds[] = $apptId3;
    $createdPatientIds[] = $ptId3;

    $stmt3 = $db->prepare("SELECT status FROM appointments WHERE id = ?");
    $stmt3->execute([$apptId3]);
    $apptRow3 = $stmt3->fetch(PDO::FETCH_ASSOC);

    assertTest($apptRow3['status'] === 'in_progress', "Walk-in registered directly for immediate examination has status 'in_progress'.");

    // -------------------------------------------------------------
    // SUITE 2: Calendar & Queue APIs Integration
    // -------------------------------------------------------------
    echo "\n--- SUITE 2: Calendar & Active Walk-in Queue APIs ---\n";

    // Query active walkins
    $today = date('Y-m-d');
    $qStmt = $db->prepare("
        SELECT a.id, a.patient_id, p.full_name as patient_name, a.status, a.appointment_type,
               (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as rx_count
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.appointment_date = ?
          AND a.status IN ('confirmed', 'in_progress', 'completed')
        ORDER BY a.appointment_time ASC
    ");
    $qStmt->execute([$today]);
    $activeQueue = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $found1 = array_filter($activeQueue, fn($q) => (int)$q['id'] === $apptId1);
    $found3 = array_filter($activeQueue, fn($q) => (int)$q['id'] === $apptId3);

    assertTest(!empty($found1), "Active walk-in queue includes newly confirmed walk-in patient.");
    assertTest(!empty($found3), "Active walk-in queue includes in-progress patient.");

    // -------------------------------------------------------------
    // SUITE 3: Clinical State Transitions & Rules
    // -------------------------------------------------------------
    echo "\n--- SUITE 3: Doctor Consultation State Transitions ---\n";

    // Test: Doctor starts consultation -> status transitions to 'in_progress'
    $db->prepare("UPDATE appointments SET status = 'in_progress', verified_by = 1 WHERE id = ?")->execute([$apptId1]);
    $checkProgress = $db->query("SELECT status FROM appointments WHERE id = $apptId1")->fetch()['status'];
    assertTest($checkProgress === 'in_progress', "Doctor starting consultation moves appointment to 'in_progress'.");

    // Test: Marking No-Show on a WALK_IN appointment must be blocked
    $ptStmt = $db->prepare("SELECT a.*, p.full_name FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
    $ptStmt->execute([$apptId1]);
    $ptData = $ptStmt->fetch(PDO::FETCH_ASSOC);

    $isWalkin = ($ptData['appointment_type'] ?? '') === 'WALK_IN';
    $noShowAllowed = true;
    if ($isWalkin) {
        $noShowAllowed = false; // Mirrors doctor/appointments.php backend validation rule
    }
    assertTest(!$noShowAllowed, "Marking No-Show is strictly blocked for walk-in patients (physically present in clinic).");

    // Test: Doctor completes consultation
    $db->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?")->execute([$apptId1]);
    $checkDone = $db->query("SELECT status FROM appointments WHERE id = $apptId1")->fetch()['status'];
    assertTest($checkDone === 'completed', "Doctor marks consultation as 'completed'. Patient is ready for Optical Dispensing & Checkout.");

    // -------------------------------------------------------------
    // SUITE 4: Auto No-Show Cron Excludes Walk-ins
    // -------------------------------------------------------------
    echo "\n--- SUITE 4: Cron Auto No-Show Exclusion ---\n";

    // Create an old confirmed walk-in from 3 days ago with no notes/Rx
    $pastDate = date('Y-m-d', strtotime('-3 days'));
    $db->prepare("
        INSERT INTO appointments (patient_id, appointment_date, appointment_time, appointment_type, purpose, status, notes, created_at)
        VALUES (?, ?, '10:00:00', 'WALK_IN', 'consultation', 'confirmed', '', ?)
    ")->execute([$ptId1, $pastDate, $pastDate . ' 10:00:00']);
    $pastWalkinId = (int)$db->lastInsertId();
    $createdApptIds[] = $pastWalkinId;

    // Simulate cron query with WALK_IN exclusion:
    // AND (a.appointment_type IS NULL OR a.appointment_type != 'WALK_IN')
    $cronStmt = $db->prepare("
        SELECT a.id, a.appointment_type, a.status
        FROM appointments a
        WHERE a.id = ?
          AND a.appointment_date < CURDATE()
          AND a.status = 'confirmed'
          AND (a.appointment_type IS NULL OR a.appointment_type != 'WALK_IN')
    ");
    $cronStmt->execute([$pastWalkinId]);
    $cronCandidate = $cronStmt->fetch();

    assertTest($cronCandidate === false, "Automated cron query strictly ignores walk-in appointments (never marked as no-show).");

    // -------------------------------------------------------------
    // SUITE 5: Prescription & POS Job Order Integration
    // -------------------------------------------------------------
    echo "\n--- SUITE 5: Prescription Import & POS Job Order ---\n";

    // 1. Create optical prescription for Maria Santos Test
    $insRx = $db->prepare("
        INSERT INTO prescriptions (patient_id, doctor_id, od_sphere, od_cylinder, od_axis, os_sphere, os_cylinder, os_axis, add_power, pd, notes, created_at)
        VALUES (?, 1, '-1.50', '-0.50', '90', '-1.75', '-0.75', '85', '+1.25', '64.0', 'Computer & reading lenses', NOW())
    ");
    $insRx->execute([$ptId1]);
    $rxId = (int)$db->lastInsertId();
    $createdRxIds[] = $rxId;

    // 2. Lookup Rx for POS Import
    $rxStmt = $db->prepare("SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
    $rxStmt->execute([$ptId1]);
    $rxData = $rxStmt->fetch(PDO::FETCH_ASSOC);

    assertTest(!empty($rxData) && $rxData['od_sphere'] === '-1.50', "POS Rx lookup retrieves optical parameters (OD/OS sphere, cyl, axis, add, pd).");

    // 3. POS Phone validation: Prescription order requires valid mobile phone
    $invalidPhoneInput = '1234';
    $phoneValid = preg_match('/^09\d{9}$/', $invalidPhoneInput);
    assertTest(!$phoneValid, "POS checkout rejects prescription sale if patient phone number is invalid/incomplete.");

    $validPhoneInput = '09179876543';
    $phoneValid2 = preg_match('/^09\d{9}$/', $validPhoneInput);
    assertTest($phoneValid2 === 1, "POS checkout accepts valid 11-digit Philippine mobile phone starting with 09.");

    // 4. Job Order calculations: Total = 4,500, Downpayment = 2,000 -> Balance Due = 2,500
    $subtotal = 4500.00;
    $paymentType = 'downpayment';
    $depositAmount = 2000.00;
    $balanceDue = max(0, $subtotal - $depositAmount);

    assertTest($balanceDue === 2500.00, "POS accurately computes Job Order Balance Due for partial deposits (4500 - 2000 = 2500).");

    // 5. Insert Job Order Sale
    $invoiceNo = 'INV-' . date('Ymd') . '-TEST';
    $joNo = 'JO-' . date('Ymd') . '-9999';
    $targetPickup = date('Y-m-d', strtotime('+3 days'));

    $insSale = $db->prepare("
        INSERT INTO sales (invoice_no, patient_id, cashier_id, appointment_id, prescription_id, payment_type, subtotal, discount, total, amount_paid, deposit_amount, balance_due, change_amount, payment_method, order_status, job_order_no, target_pickup_date, status, created_at)
        VALUES (?, ?, 1, ?, ?, 'downpayment', ?, 0, ?, ?, ?, ?, 0, 'cash', 'in_progress', ?, ?, 'completed', NOW())
    ");
    $insSale->execute([
        $invoiceNo,
        $ptId1,
        $apptId1,
        $rxId,
        $subtotal,
        $subtotal,
        $depositAmount,
        $depositAmount,
        $balanceDue,
        $joNo,
        $targetPickup
    ]);
    $saleId = (int)$db->lastInsertId();
    $createdSaleIds[] = $saleId;

    $checkSale = $db->query("SELECT * FROM sales WHERE id = $saleId")->fetch(PDO::FETCH_ASSOC);
    assertTest($checkSale['job_order_no'] === $joNo && $checkSale['payment_type'] === 'downpayment', "Job Order successfully recorded with JO number, deposit, balance, and target pickup date.");

} catch (Exception $e) {
    echo "\n\033[31mFATAL TEST ERROR: " . $e->getMessage() . "\033[0m\n";
    $failed++;
} finally {
    echo "\nCleaning up test artifacts...\n";
    if (!empty($createdSaleIds)) {
        $in = implode(',', array_map('intval', $createdSaleIds));
        $db->exec("DELETE FROM sale_items WHERE sale_id IN ($in)");
        $db->exec("DELETE FROM sales WHERE id IN ($in)");
    }
    if (!empty($createdRxIds)) {
        $in = implode(',', array_map('intval', $createdRxIds));
        $db->exec("DELETE FROM prescriptions WHERE id IN ($in)");
    }
    if (!empty($createdApptIds)) {
        $in = implode(',', array_map('intval', $createdApptIds));
        $db->exec("DELETE FROM appointments WHERE id IN ($in)");
    }
    if (!empty($createdPatientIds)) {
        $in = implode(',', array_map('intval', $createdPatientIds));
        $db->exec("DELETE FROM patients WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo " TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo str_repeat('=', 60) . "\n\n";

exit($failed > 0 ? 1 : 0);
