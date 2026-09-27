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
    $pStmt = $db->prepare("
        SELECT id, full_name, first_name, last_name, email, phone, address, gender,
               birth_date, created_at, status
        FROM patients
        WHERE id = ?
    ");
    $pStmt->execute([$patientId]);
    $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Patient not found.']);
        exit;
    }

    // All sales for this patient, newest first
    $sStmt = $db->prepare("
        SELECT s.id, s.invoice_no, s.subtotal, s.discount, s.total,
               s.payment_method, s.payment_type, s.deposit_amount, s.balance_due,
               s.amount_paid, s.change_amount, s.order_status, s.status,
               s.job_order_no, s.target_pickup_date, s.created_at,
               u.full_name AS cashier_name
        FROM sales s
        LEFT JOIN users u ON u.id = s.cashier_id
        WHERE s.patient_id = ?
        ORDER BY s.created_at DESC
    ");
    $sStmt->execute([$patientId]);
    $sales = $sStmt->fetchAll(PDO::FETCH_ASSOC);

    // For each sale, get items
    $siStmt = $db->prepare("
        SELECT item_name, item_type, quantity, unit_price, total_price
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY id ASC
    ");

    foreach ($sales as &$sale) {
        $siStmt->execute([$sale['id']]);
        $sale['items'] = $siStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($sale);

    // Build display name
    $displayName = !empty($patient['full_name'])
        ? $patient['full_name']
        : trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));

    echo json_encode([
        'success'  => true,
        'patient'  => [
            'id'           => (int)$patient['id'],
            'name'         => $displayName,
            'email'        => $patient['email'] ?? '',
            'phone'        => $patient['phone'] ?? '',
            'address'      => $patient['address'] ?? '',
            'gender'       => $patient['gender'] ?? '',
            'birth_date'   => $patient['birth_date'] ?? '',
            'registered'   => $patient['created_at'] ?? '',
            'status'       => $patient['status'] ?? 'active',
        ],
        'sales'    => $sales,
        'total_transactions' => count($sales),
        'total_spent' => array_sum(array_column($sales, 'total')),
    ]);

} catch (Throwable $e) {
    error_log('get_patient_history error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
