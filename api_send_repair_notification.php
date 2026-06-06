<?php
/**
 * API Endpoint: Send Repair Notification
 * Sends notification to all IT staff about pending/completed repairs
 */
header('Content-Type: application/json');
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Verify authentication
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'it_staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$repair_id = intval($input['repair_id'] ?? 0);
$asset_tag = $input['asset_tag'] ?? 'Unknown';

if (!$repair_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid repair ID']);
    exit;
}

try {
    // Get repair details
    $stmt = $pdo->prepare("
        SELECT dr.*, d.asset_tag, dt.type_name, u.full_name as reporter_name
        FROM device_repairs dr
        JOIN devices d ON dr.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        JOIN users u ON dr.reported_by = u.id
        WHERE dr.id = ?
    ");
    $stmt->execute([$repair_id]);
    $repair = $stmt->fetch();
    
    if (!$repair) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Repair not found']);
        exit;
    }
    
    // Get all IT staff
    $itStaff = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active'")->fetchAll();
    
    $title = 'Device Repair ' . ucfirst($repair['repair_status']);
    $message = "Device {$repair['asset_tag']} ({$repair['type_name']}) - Issue: " . substr($repair['issue_description'], 0, 100) . ". Status: " . str_replace('_', ' ', ucfirst($repair['repair_status']));
    
    // Add notification to all IT staff
    foreach ($itStaff as $staff) {
        addSystemNotificationOnlyIfNotExists(
            $staff['id'],
            'repair',
            $title,
            $message,
            $repair['device_id']
        );
    }
    
    // Log audit
    logAudit($_SESSION['user_id'], 'Send Repair Notification', 'device_repairs', $repair_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification sent to ' . count($itStaff) . ' IT staff member(s)'
    ]);
    
} catch (Exception $e) {
    error_log('Error in api_send_repair_notification.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
