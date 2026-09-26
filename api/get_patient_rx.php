<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin', 'doctor', 'saleslady');

header('Content-Type: application/json');

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Patient ID is required.']);
    exit;
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT rx.*, 
               u.full_name as doctor_name,
               p.full_name as patient_name,
               p.phone as patient_phone
        FROM prescriptions rx
        JOIN patients p ON p.id = rx.patient_id
        LEFT JOIN users u ON u.id = rx.doctor_id
        WHERE rx.patient_id = ?
        ORDER BY rx.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $rx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rx) {
        echo json_encode([
            'success' => true,
            'has_rx'  => false,
            'message' => 'No prescription on file for this patient.'
        ]);
        exit;
    }

    // Format OD / OS string summaries
    $odParts = [];
    if (!empty($rx['od_sphere']))   $odParts[] = 'Sph ' . $rx['od_sphere'];
    if (!empty($rx['od_cylinder'])) $odParts[] = 'Cyl ' . $rx['od_cylinder'];
    if (!empty($rx['od_axis']))     $odParts[] = 'Axis ' . $rx['od_axis'] . '°';
    if (!empty($rx['od_add']))      $odParts[] = 'Add ' . $rx['od_add'];
    $odSummary = !empty($odParts) ? implode(', ', $odParts) : 'Plano';

    $osParts = [];
    if (!empty($rx['os_sphere']))   $osParts[] = 'Sph ' . $rx['os_sphere'];
    if (!empty($rx['os_cylinder'])) $osParts[] = 'Cyl ' . $rx['os_cylinder'];
    if (!empty($rx['os_axis']))     $osParts[] = 'Axis ' . $rx['os_axis'] . '°';
    if (!empty($rx['os_add']))      $osParts[] = 'Add ' . $rx['os_add'];
    $osSummary = !empty($osParts) ? implode(', ', $osParts) : 'Plano';

    $pdSummary = !empty($rx['pd']) ? $rx['pd'] . ' mm' : (!empty($rx['pd_right']) || !empty($rx['pd_left']) ? 'R:' . ($rx['pd_right'] ?? '-') . ' L:' . ($rx['pd_left'] ?? '-') : 'Standard');
    $lensType  = !empty($rx['lens_type']) ? $rx['lens_type'] : 'Single Vision / Multicoated';

    echo json_encode([
        'success'      => true,
        'has_rx'       => true,
        'rx'           => [
            'id'             => (int)$rx['id'],
            'patient_id'     => (int)$rx['patient_id'],
            'patient_name'   => $rx['patient_name'],
            'patient_phone'  => $rx['patient_phone'] ?: '',
            'doctor_name'    => $rx['doctor_name'] ?: 'Attending Optometrist',
            'created_at'     => $rx['created_at'],
            'date_formatted' => date('M d, Y', strtotime($rx['created_at'])),
            'od_sphere'      => $rx['od_sphere'],
            'od_cylinder'    => $rx['od_cylinder'],
            'od_axis'        => $rx['od_axis'],
            'od_add'         => $rx['od_add'] ?: $rx['add_power'],
            'os_sphere'      => $rx['os_sphere'],
            'os_cylinder'    => $rx['os_cylinder'],
            'os_axis'        => $rx['os_axis'],
            'os_add'         => $rx['os_add'] ?: $rx['add_power'],
            'pd'             => $rx['pd'],
            'od_summary'     => $odSummary,
            'os_summary'     => $osSummary,
            'pd_summary'     => $pdSummary,
            'lens_type'      => $lensType,
            'notes'          => $rx['notes'] ?: '',
            'recommendations'=> $rx['recommendations'] ?: ''
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
