<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin', 'saleslady');

header('Content-Type: application/json');

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Patient ID is required.']);
    exit;
}

try {
    $db = getDB();

    // Patient basic info
    $pStmt = $db->prepare("SELECT * FROM patients WHERE id = ?");
    $pStmt->execute([$patientId]);
    $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Patient record not found.']);
        exit;
    }

    // All sales for this patient, newest first
    $sStmt = $db->prepare("
        SELECT s.*,
               u.full_name AS cashier_name
        FROM sales s
        LEFT JOIN users u ON u.id = s.cashier_id
        WHERE s.patient_id = ?
        ORDER BY s.created_at DESC, s.id DESC
    ");
    $sStmt->execute([$patientId]);
    $sales = $sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // For each sale, get item list
    $siStmt = $db->prepare("
        SELECT si.*, 
               COALESCE(p.name, si.item_name) AS display_name,
               c.name AS category_name
        FROM sale_items si
        LEFT JOIN products p ON p.id = si.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ");

    foreach ($sales as &$sale) {
        $siStmt->execute([$sale['id']]);
        $sale['items'] = $siStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($sale);

    $displayName = getPatientDisplayName($patient);

    echo json_encode([
        'success'  => true,
        'patient'  => [
            'id'           => (int)$patient['id'],
            'name'         => $displayName,
            'first_name'   => $patient['first_name'] ?? '',
            'last_name'    => $patient['last_name'] ?? '',
            'email'        => $patient['email'] ?? '',
            'phone'        => $patient['phone'] ?? '',
            'address'      => $patient['address'] ?? '',
            'gender'       => $patient['gender'] ?? '',
            'birthdate'    => $patient['birthdate'] ?? ($patient['birth_date'] ?? ''),
            'registered'   => $patient['created_at'] ?? '',
            'status'       => $patient['status'] ?? 'active',
            'avatar'       => $patient['avatar'] ?? '',
        ],
        'sales'    => $sales,
        'total_transactions' => count($sales),
        'total_spent' => array_sum(array_column($sales, 'total')),
    ]);

} catch (Throwable $e) {
    error_log('get_patient_history error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
