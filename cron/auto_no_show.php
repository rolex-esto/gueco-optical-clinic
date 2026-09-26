<?php
// ============================================================
// AUTOMATED END-OF-DAY CRON / BACKGROUND JOB: AUTO NO-SHOW
// Gueco Optical Clinic Management System
// Runs daily at 23:59 or every midnight to process abandoned
// confirmed bookings that have passed without clinical encounters.
// ============================================================

if (!defined('BASE_URL')) {
    define('BASE_URL', '../');
}
require_once __DIR__ . '/../config/functions.php';

if (empty($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/**
 * Executes the Auto No-Show background automation.
 *
 * @param PDO $db
 * @return array Processed summary
 */
function processAutoNoShowAppointments(PDO $db): array {
    $now = date('Y-m-d H:i:s');
    
    // Find confirmed appointments where:
    // 1. Date is in the past (< TODAY) OR scheduled time was > 24 hours ago
    // 2. Status is 'confirmed'
    // 3. No clinical notes are present
    // 4. No encounters / patient_records exist
    // 5. No prescriptions exist
    $query = "
        SELECT a.id, a.patient_id, a.appointment_date, a.appointment_time, a.notes, p.full_name as patient_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.status = 'confirmed'
          AND (a.appointment_type IS NULL OR a.appointment_type != 'WALK_IN')
          AND (
            a.appointment_date < CURDATE()
            OR TIMESTAMP(a.appointment_date, a.appointment_time) <= NOW() - INTERVAL 24 HOUR
          )
          AND (
            a.notes IS NULL 
            OR TRIM(a.notes) = '' 
            OR a.notes LIKE '%No special notes%'
          )
          AND NOT EXISTS (
            SELECT 1 FROM patient_records pr 
            WHERE pr.appointment_id = a.id 
               OR (pr.patient_id = a.patient_id AND pr.visit_date = a.appointment_date)
          )
          AND NOT EXISTS (
            SELECT 1 FROM prescriptions rx 
            WHERE (rx.record_id IN (SELECT id FROM patient_records WHERE appointment_id = a.id))
               OR (rx.patient_id = a.patient_id AND DATE(rx.created_at) = a.appointment_date)
          )
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
    ";

    $stmt = $db->query($query);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = [];
    $updateStmt = $db->prepare("
        UPDATE appointments 
        SET status = 'no_show',
            notes = CASE 
                WHEN notes IS NULL OR TRIM(notes) = '' OR notes LIKE '%No special notes%' 
                THEN '[AUTO_NOSHOW] No patient attendance recorded prior to end-of-day automation.'
                ELSE CONCAT(notes, ' [AUTO_NOSHOW]')
            END
        WHERE id = ?
    ");

    foreach ($candidates as $appt) {
        $apptId = (int)$appt['id'];
        $ptName = $appt['patient_name'] ?? 'Patient #' . $appt['patient_id'];

        try {
            $db->beginTransaction();
            $updateStmt->execute([$apptId]);

            // Audit log entry: "Status updated to NO-SHOW by System Automation"
            logActivity(
                "Status updated to NO-SHOW by System Automation (Appointment #$apptId - Patient: $ptName)",
                "Appointments",
                null,
                'system'
            );

            $db->commit();
            $processed[] = [
                'id' => $apptId,
                'patient_id' => $appt['patient_id'],
                'patient_name' => $ptName,
                'appointment_date' => $appt['appointment_date'],
                'appointment_time' => $appt['appointment_time'],
                'status' => 'no_show'
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Failed to auto no-show appointment #$apptId: " . $e->getMessage());
        }
    }

    return [
        'timestamp' => $now,
        'candidates_found' => count($candidates),
        'processed_count' => count($processed),
        'appointments' => $processed
    ];
}

// Check if this script is being executed directly (CLI or Web endpoint)
$isDirectExecution = (isset($_SERVER['SCRIPT_FILENAME']) && (
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__) ||
    basename($_SERVER['SCRIPT_FILENAME']) === 'cron_auto_no_show.php'
));

if (!$isDirectExecution) {
    // If required/included by tests or other modules, do not auto-run
    return;
}

// Check execution context
$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));
$cronToken = getSetting('cron_secret_token') ?: 'GuecoAutoNoShowSecretToken2026';
$providedToken = $_GET['token'] ?? $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

$authorized = false;
if ($isCli) {
    $authorized = true;
} else {
    // If called via web, require token match or active admin session
    if ($providedToken === $cronToken) {
        $authorized = true;
    } elseif (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin') {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. A valid cron secret token or admin authentication is required.'
    ]);
    exit;
}

$db = getDB();
$result = processAutoNoShowAppointments($db);

if ($isCli) {
    echo "============================================================\n";
    echo " GUECO OPTICAL - AUTOMATED AUTO NO-SHOW CRON JOB\n";
    echo " Executed at: " . $result['timestamp'] . "\n";
    echo "============================================================\n";
    echo "Candidates Found: " . $result['candidates_found'] . "\n";
    echo "Updated to NO-SHOW: " . $result['processed_count'] . "\n";
    foreach ($result['appointments'] as $item) {
        echo " - Appt #{$item['id']}: {$item['patient_name']} ({$item['appointment_date']} {$item['appointment_time']})\n";
    }
    echo "Done.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_PRETTY_PRINT);
}
