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

    // Fetch pending appointments
    $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()" . $purposeFilterCount);
    $stmtCount->execute();
    $apptCount = (int)$stmtCount->fetch()['c'];

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

    $totalCount = $apptCount + $lowStockCount;
    
    echo json_encode([
        'count'           => $totalCount,
        'appt_count'      => $apptCount,
        'appointments'    => $recentAppts,
        'low_stock_count' => $lowStockCount,
        'low_stock_items' => $recentLowStock,
        'role'            => $userRole
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
