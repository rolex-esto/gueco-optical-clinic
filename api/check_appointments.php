<?php
require_once __DIR__ . '/../config/functions.php';
startSession();

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDB();
    $userRole = $_SESSION['role'] ?? ($_GET['role'] ?? '');
    
    $purposeFilterCount = ($userRole === 'doctor') ? " AND purpose != 'eyeglass_claim'" : "";
    $purposeFilterJoin = ($userRole === 'doctor') ? " AND a.purpose != 'eyeglass_claim'" : "";

    // Fetch count
    $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()" . $purposeFilterCount);
    $stmtCount->execute();
    $notifCount = $stmtCount->fetch()['c'];

    // Fetch the recent pending appointments with patient names
    $stmtRecent = $db->prepare("
        SELECT a.id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose 
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE a.status = 'pending' AND a.appointment_date >= CURDATE() " . $purposeFilterJoin . "
        ORDER BY a.created_at DESC 
        LIMIT 10
    ");
    $stmtRecent->execute();
    $recentAppts = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'count' => (int)$notifCount,
        'appointments' => $recentAppts
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
