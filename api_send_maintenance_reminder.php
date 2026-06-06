<?php
/**
 * API - Send Maintenance Reminder Email
 * Endpoint for IT staff to send email reminders about upcoming maintenance
 */

header('Content-Type: application/json');
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || (!hasRole('admin') && !hasRole('it_staff'))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$json = json_decode(file_get_contents('php://input'), true);
$maintenanceId = (int)($json['maintenance_id'] ?? 0);

if (!$maintenanceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing maintenance_id']);
    exit();
}

try {
    // Get maintenance schedule details
    $stmt = $pdo->prepare("
        SELECT ms.*, d.asset_tag, dt.type_name, u.email, u.full_name, u.id as assigned_user_id
        FROM maintenance_schedules ms
        JOIN devices d ON ms.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        LEFT JOIN users u ON ms.assigned_to = u.id
        WHERE ms.id = ?
    ");
    $stmt->execute([$maintenanceId]);
    $maintenance = $stmt->fetch();
    
    if (!$maintenance) {
        echo json_encode(['success' => false, 'message' => 'Maintenance schedule not found']);
        exit();
    }
    
    if (!$maintenance['email']) {
        echo json_encode(['success' => false, 'message' => 'No email address assigned to this maintenance task']);
        exit();
    }
    
    // Prepare email
    $subject = 'Maintenance Reminder: ' . $maintenance['asset_tag'] . ' (' . $maintenance['type_name'] . ') - Due ' . date('M d, Y', strtotime($maintenance['next_due_date']));
    $body = emailTemplate(
        'Maintenance Reminder',
        "<p>Hello <strong>" . htmlspecialchars($maintenance['full_name']) . "</strong>,</p>
        <p>This is a reminder that maintenance is due for the following device:</p>
        <ul style='margin-left: 20px;'>
            <li><strong>Asset Tag:</strong> " . htmlspecialchars($maintenance['asset_tag']) . "</li>
            <li><strong>Device Type:</strong> " . htmlspecialchars($maintenance['type_name']) . "</li>
            <li><strong>Maintenance Type:</strong> " . str_replace('_', ' ', ucfirst($maintenance['maintenance_type'])) . "</li>
            <li><strong>Due Date:</strong> " . date('M d, Y', strtotime($maintenance['next_due_date'])) . "</li>
            <li><strong>Description:</strong> " . htmlspecialchars($maintenance['description']) . "</li>
        </ul>
        <p>Please schedule and complete this maintenance within the next 7 days.</p>",
        'View Device Details',
        (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/view_device.php?id=' . $maintenance['device_id']
    );
    
    // Queue email notification
    $emailNotifId = queueEmailNotification(
        $maintenance['assigned_user_id'],
        $maintenance['email'],
        'maintenance_due',
        $subject,
        $body,
        $maintenance['device_id'],
        null
    );
    
    // Send immediately
    sendPendingEmailNotifications();

    if (!empty($maintenance['assigned_user_id'])) {
        addSystemNotificationOnlyIfNotExists($maintenance['assigned_user_id'], 'maintenance_due', 'Maintenance Reminder', "Maintenance is due for {$maintenance['asset_tag']} on " . date('M d, Y', strtotime($maintenance['next_due_date'])) . ".", $maintenance['device_id']);
    }
    $pdo->prepare("
        INSERT INTO maintenance_reminders_sent (maintenance_id, email_notification_id, sent_to_user_id)
        VALUES (?, ?, ?)
    ")->execute([$maintenanceId, $emailNotifId, $maintenance['assigned_user_id']]);
    
    // Log action
    logAudit($_SESSION['user_id'], 'Send Maintenance Reminder', 'maintenance_schedules', $maintenanceId);
    
    echo json_encode(['success' => true, 'message' => 'Maintenance reminder sent to ' . htmlspecialchars($maintenance['full_name'])]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
