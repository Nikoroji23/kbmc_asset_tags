<?php
/**
 * API Endpoint: Send Inspection Notification
 * Sends notification to all IT staff about device inspection completion
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
$inspection_id = intval($input['inspection_id'] ?? 0);
$asset_tag = $input['asset_tag'] ?? 'Unknown';

if (!$inspection_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid inspection ID']);
    exit;
}

try {
    // Get inspection details
    $stmt = $pdo->prepare("
        SELECT di.*, d.asset_tag, dt.type_name, u.full_name as inspector_name
        FROM device_inspections di
        JOIN devices d ON di.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        JOIN users u ON di.inspected_by = u.id
        WHERE di.id = ?
    ");
    $stmt->execute([$inspection_id]);
    $inspection = $stmt->fetch();
    
    if (!$inspection) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Inspection not found']);
        exit;
    }
    
    // Get all IT staff
    $itStaff = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active'")->fetchAll();
    
    $title = 'Device Inspection Completed';
    $message = "Device {$inspection['asset_tag']} ({$inspection['type_name']}) has been inspected. Result: " . ucfirst($inspection['result']) . ". Physical condition: " . ucfirst($inspection['physical_condition']);
    
    // Add notification to all IT staff
    foreach ($itStaff as $staff) {
        addSystemNotificationOnlyIfNotExists(
            $staff['id'],
            'inspection',
            $title,
            $message,
            $inspection['device_id']
        );
    }
    
    // Log audit
    logAudit($_SESSION['user_id'], 'Send Inspection Notification', 'device_inspections', $inspection_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification sent to ' . count($itStaff) . ' IT staff member(s)'
    ]);
    
} catch (Exception $e) {
    error_log('Error in api_send_inspection_notification.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
