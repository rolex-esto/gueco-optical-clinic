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
    $userRole = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? ($_GET['role'] ?? ''));
    if (empty($userRole) && isLoggedIn()) {
        $u = getCurrentUser();
        $userRole = $u['role'] ?? '';
    }
    
    $purposeFilterCount = ($userRole === 'doctor') ? " AND purpose != 'eyeglass_claim'" : "";
    $purposeFilterJoin = ($userRole === 'doctor') ? " AND a.purpose != 'eyeglass_claim'" : "";

    // Fetch pending appointments
    $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()" . $purposeFilterCount);
    $stmtCount->execute();
    $apptCount = (int)$stmtCount->fetch()['c'];

    // Fetch the recent pending appointments with patient names
    $stmtRecent = $db->prepare("
        SELECT a.id, a.patient_id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose,
               'pending_booking' as notif_type
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE a.status = 'pending' AND a.appointment_date >= CURDATE() " . $purposeFilterJoin . "
        ORDER BY a.created_at DESC 
        LIMIT 10
    ");
    $stmtRecent->execute();
    $recentAppts = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    // 1. Doctor Notification: Active Walk-in patients waiting today for doctor examination
    $walkinCount = 0;
    $walkinAppts = [];
    if (in_array($userRole, ['doctor', 'admin'])) {
        $stmtWalkin = $db->prepare("
            SELECT a.id, a.patient_id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose,
                   'walkin_waiting' as notif_type
            FROM appointments a
            JOIN patients p ON a.patient_id = p.id
            WHERE a.appointment_date = CURDATE()
              AND a.appointment_type = 'WALK_IN'
              AND a.status IN ('confirmed', 'in_progress')
              AND a.id NOT IN (SELECT COALESCE(appointment_id, 0) FROM prescriptions WHERE appointment_id IS NOT NULL)
            ORDER BY a.created_at DESC
            LIMIT 10
        ");
        $stmtWalkin->execute();
        $walkinAppts = $stmtWalkin->fetchAll(PDO::FETCH_ASSOC);
        $walkinCount = count($walkinAppts);
    }

    // 2. Saleslady Notification: Patients who completed eye exam & prescription written, ready for POS billing
    $readyPosCount = 0;
    $readyPosAppts = [];
    if (in_array($userRole, ['saleslady', 'admin'])) {
        $stmtReadyPos = $db->prepare("
            SELECT a.id, a.patient_id, p.full_name as patient_name, a.appointment_date, a.appointment_time, a.purpose,
                   'ready_for_pos' as notif_type
            FROM appointments a
            JOIN patients p ON a.patient_id = p.id
            WHERE a.appointment_date = CURDATE()
              AND a.status NOT IN ('cancelled', 'no_show')
              AND NOT EXISTS (
                  SELECT 1 FROM sales s 
                  WHERE s.appointment_id = a.id 
                     OR (s.patient_id = a.patient_id AND DATE(s.created_at) = a.appointment_date)
              )
              AND (
                  a.status = 'completed'
                  OR EXISTS (
                      SELECT 1 FROM prescriptions rx 
                      WHERE rx.appointment_id = a.id 
                         OR (rx.patient_id = a.patient_id AND DATE(rx.created_at) = a.appointment_date)
                  )
              )
            ORDER BY a.id DESC
            LIMIT 10
        ");
        $stmtReadyPos->execute();
        $readyPosAppts = $stmtReadyPos->fetchAll(PDO::FETCH_ASSOC);
        $readyPosCount = count($readyPosAppts);
    }

    // Fetch low stock inventory for admin & saleslady
    $lowStockCount = 0;
    $recentLowStock = [];
    if (in_array($userRole, ['admin', 'saleslady'])) {
        $stmtLowCount = $db->query("SELECT COUNT(*) as c FROM products WHERE stock_quantity <= low_stock_alert AND status = 'active'");
        $lowStockCount = (int)($stmtLowCount ? $stmtLowCount->fetch()['c'] : 0);

        $stmtLowRecent = $db->query("
            SELECT p.id, p.name, p.base_model, p.variant_name, p.stock_quantity, p.low_stock_alert, COALESCE(c.name, 'Optical') as category_name
            FROM products p 
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.stock_quantity <= p.low_stock_alert AND p.status = 'active'
            ORDER BY p.stock_quantity ASC, p.id DESC
            LIMIT 10
        ");
        $recentLowStock = $stmtLowRecent ? $stmtLowRecent->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $totalCount = $apptCount + $lowStockCount + $walkinCount + $readyPosCount;
    
    echo json_encode([
        'count'           => $totalCount,
        'appt_count'      => $apptCount,
        'appointments'    => $recentAppts,
        'walkin_count'    => $walkinCount,
        'walkin_items'    => $walkinAppts,
        'ready_pos_count' => $readyPosCount,
        'ready_pos_items' => $readyPosAppts,
        'low_stock_count' => $lowStockCount,
        'low_stock_items' => $recentLowStock,
        'role'            => $userRole
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
