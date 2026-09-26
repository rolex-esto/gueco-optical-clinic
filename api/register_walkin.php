<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin', 'doctor', 'saleslady');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Security token invalid or expired. Please refresh the page.']);
    exit;
}

try {
    $db = getDB();
    $initialStatus = sanitize($_POST['initial_status'] ?? 'confirmed');
    $purpose       = sanitize($_POST['purpose'] ?? 'consultation');
    $staffUserId   = (int)($_SESSION['user_id'] ?? 0);

    $result = createWalkinAppointment($db, $_POST, $initialStatus, $staffUserId, $purpose);

    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    // Fetch the newly inserted appointment formatted for direct client consumption
    $apptStmt = $db->prepare("
        SELECT a.*, 
               p.full_name as patient_name, 
               p.phone as patient_phone, 
               p.email as patient_email, 
               p.gender as patient_gender,
               p.birthdate as patient_birthdate,
               0 as rx_count,
               0 as completed_visits
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.id = ?
    ");
    $apptStmt->execute([$result['appointment_id']]);
    $fullAppt = $apptStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'        => true,
        'message'        => 'Walk-in patient registered successfully.',
        'appointment'    => $fullAppt,
        'appointment_id' => $result['appointment_id'],
        'patient_id'     => $result['patient_id']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
