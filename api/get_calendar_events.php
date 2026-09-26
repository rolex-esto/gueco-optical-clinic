<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin', 'doctor', 'saleslady');
header('Content-Type: application/json');

try {
    $db = getDB();
    
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    $query = "
        SELECT 
            a.id, 
            a.patient_id,
            p.full_name as patient_name, 
            p.phone,
            p.email as patient_email,
            p.gender as patient_gender,
            p.birthdate as patient_birthdate,
            p.address as patient_address,
            a.appointment_date, 
            a.appointment_time, 
            a.appointment_type,
            a.status, 
            a.purpose, 
            a.notes,
            (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as rx_count,
            (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed') as completed_visits
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
    ";
    
    $params = [];
    if ($start && $end) {
        $query .= " WHERE a.appointment_date >= ? AND a.appointment_date <= ?";
        $params[] = substr($start, 0, 10);
        $params[] = substr($end, 0, 10);
    }
    
    $query .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $appts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $events = [];
    foreach ($appts as $a) {
        $isWalkin = ($a['appointment_type'] ?? '') === 'WALK_IN';
        
        // Map status to a color
        $color = '#64748B'; // default secondary
        if ($a['status'] === 'pending') $color = '#268FC8'; // azure blue
        elseif ($a['status'] === 'confirmed') $color = '#235EAE'; // sapphire blue
        elseif ($a['status'] === 'in_progress') $color = '#F59E0B'; // amber / in consultation
        elseif ($a['status'] === 'completed') $color = '#10B981'; // success/green
        elseif ($a['status'] === 'cancelled') $color = '#EF4444'; // danger/red
        elseif ($a['status'] === 'no_show') $color = '#475569'; // darker gray
        
        $titlePrefix = $isWalkin ? '[Walk-in] ' : '';
        $title = $titlePrefix . $a['patient_name'] . ' - ' . ucwords(str_replace('_', ' ', $a['purpose']));
        $startDateTime = $a['appointment_date'] . 'T' . $a['appointment_time'];
        
        $events[] = [
            'id' => $a['id'],
            'title' => $title,
            'start' => $startDateTime,
            'backgroundColor' => $color,
            'borderColor' => $isWalkin ? '#F59E0B' : $color,
            'extendedProps' => [
                'patient_id' => $a['patient_id'],
                'patient_name' => $a['patient_name'],
                'phone' => $a['phone'],
                'patient_phone' => $a['phone'],
                'patient_email' => $a['patient_email'],
                'patient_gender' => $a['patient_gender'],
                'appointment_type' => $a['appointment_type'] ?? 'SCHEDULED',
                'is_walkin' => $isWalkin,
                'status' => $a['status'],
                'purpose' => $a['purpose'],
                'notes' => $a['notes'],
                'appointment_date' => $a['appointment_date'],
                'appointment_time' => $a['appointment_time'],
                'rx_count' => (int)($a['rx_count'] ?? 0),
                'completed_visits' => (int)($a['completed_visits'] ?? 0),
                'time_formatted' => formatTime($a['appointment_time']),
                'date_formatted' => formatDate($a['appointment_date'])
            ]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'raw' => $appts
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
