<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin', 'doctor', 'saleslady');

header('Content-Type: application/json');

try {
    $db = getDB();
    $today = date('Y-m-d');

    // Query all appointments for TODAY where status is active
    $stmt = $db->prepare("
        SELECT 
            a.id as appointment_id,
            a.patient_id,
            p.full_name as patient_name,
            p.phone as patient_phone,
            p.email as patient_email,
            a.appointment_date,
            a.appointment_time,
            a.appointment_type,
            a.status,
            a.purpose,
            a.notes,
            (SELECT rx.id FROM prescriptions rx WHERE rx.patient_id = a.patient_id ORDER BY rx.created_at DESC LIMIT 1) as latest_rx_id,
            (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as total_rx_count
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.appointment_date = ?
          AND a.status IN ('confirmed', 'in_progress', 'completed')
        ORDER BY 
            CASE a.status
                WHEN 'completed' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'confirmed' THEN 3
                ELSE 4
            END,
            a.appointment_time DESC
    ");
    $stmt->execute([$today]);
    $activePatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($activePatients as $pt) {
        $statusKey = $pt['status'];
        $statusLabel = 'Waiting in Queue';
        $badgeClass = 'warning';
        
        if ($statusKey === 'in_progress') {
            $statusLabel = 'With Doctor (In Consultation)';
            $badgeClass = 'primary';
        } elseif ($statusKey === 'completed') {
            $statusLabel = 'Refraction Done (Ready for Dispensing)';
            $badgeClass = 'success';
        }

        $formatted[] = [
            'appointment_id'   => (int)$pt['appointment_id'],
            'patient_id'       => (int)$pt['patient_id'],
            'patient_name'     => $pt['patient_name'],
            'patient_phone'    => $pt['patient_phone'] ?: '',
            'patient_email'    => $pt['patient_email'] ?: '',
            'appointment_time' => $pt['appointment_time'],
            'appointment_type' => $pt['appointment_type'] ?? 'SCHEDULED',
            'is_walkin'        => ($pt['appointment_type'] ?? '') === 'WALK_IN',
            'status'           => $statusKey,
            'status_label'     => $statusLabel,
            'badge_class'      => $badgeClass,
            'has_rx'           => !empty($pt['latest_rx_id']),
            'latest_rx_id'     => (int)($pt['latest_rx_id'] ?? 0)
        ];
    }

    echo json_encode([
        'success'   => true,
        'date'      => $today,
        'count'     => count($formatted),
        'patients'  => $formatted
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
