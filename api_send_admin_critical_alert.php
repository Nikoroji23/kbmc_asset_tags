<?php
/**
 * API - Send Admin Critical Alert Notification
 * Endpoint for sending critical system alerts to all admins
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
$alertType = trim($json['alert_type'] ?? '');
$title = trim($json['title'] ?? '');
$message = trim($json['message'] ?? '');
$priority = trim($json['priority'] ?? 'normal'); // 'low', 'normal', 'high', 'critical'
$relatedDeviceId = (int)($json['related_device_id'] ?? 0);
$actionUrl = trim($json['action_url'] ?? '');

// Validate inputs
if (empty($alertType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing alert_type']);
    exit();
}

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing title']);
    exit();
}

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing message']);
    exit();
}

// Validate priority
$validPriorities = ['low', 'normal', 'high', 'critical'];
if (!in_array($priority, $validPriorities)) {
    $priority = 'normal';
}

// Validate alert type
$validAlertTypes = [
    'device_critical',
    'maintenance_overdue',
    'failed_logins',
    'system_alert',
    'security_warning',
    'device_issue',
    'custom'
];

if (!in_array($alertType, $validAlertTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid alert_type']);
    exit();
}

try {
    // Get all active admins to send notification to
    $admins = $pdo->query("
        SELECT id, email, full_name 
        FROM users 
        WHERE role = 'admin' AND status = 'active' 
        ORDER BY full_name
    ")->fetchAll();
    
    if (empty($admins)) {
        echo json_encode(['success' => false, 'message' => 'No active admins found to notify']);
        exit();
    }
    
    $sentCount = 0;
    
    // Determine priority styling
    $priorityColor = match($priority) {
        'critical' => '#d32f2f',
        'high' => '#f57c00',
        'normal' => '#0288d1',
        'low' => '#388e3c',
        default => '#0288d1'
    };
    
    $priorityLabel = ucfirst($priority);
    
    // Set default action URL if not provided
    if (empty($actionUrl)) {
        if ($relatedDeviceId > 0) {
            $actionUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/view_device.php?id=' . $relatedDeviceId;
        } else {
            $actionUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/admin_dashboard.php';
        }
    }
    
    // Get device info if related to a device
    $deviceInfo = '';
    if ($relatedDeviceId > 0) {
        $deviceStmt = $pdo->prepare("
            SELECT d.asset_tag, dt.type_name, d.status
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            WHERE d.id = ?
        ");
        $deviceStmt->execute([$relatedDeviceId]);
        $device = $deviceStmt->fetch();
        
        if ($device) {
            $deviceInfo = "
            <div style='background: #fafafa; padding: 10px; border-radius: 6px; margin: 10px 0;'>
                <p><i class='fas fa-laptop'></i> <strong>Device:</strong> " . htmlspecialchars($device['asset_tag']) . " (" . htmlspecialchars($device['type_name'] ?? 'Unknown') . ")</p>
                <p><i class='fas fa-info-circle'></i> <strong>Status:</strong> " . htmlspecialchars($device['status']) . "</p>
            </div>";
        }
    }
    
    if (isEmailConfigured()) {
        foreach (filterUniqueEmails($admins) as $admin) {
            $subject = '[KBMC ' . strtoupper($priority) . '] ' . htmlspecialchars($title);
            
            $body = emailTemplate(
                htmlspecialchars($title),
                "<p>Hello <strong>" . htmlspecialchars($admin['full_name']) . "</strong>,</p>
                <p><strong>An important system alert requires your attention:</strong></p>
                <div style='background: " . $priorityColor . "; color: white; padding: 12px 15px; border-radius: 8px; margin: 15px 0;'>
                    <p style='margin: 0; font-weight: bold; font-size: 16px;'><i class='fas fa-exclamation-triangle'></i> " . htmlspecialchars($title) . "</p>
                    <p style='margin: 8px 0 0 0; opacity: 0.9;'>Priority: <strong>" . $priorityLabel . "</strong></p>
                </div>
                <div style='background: #f5f5f5; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid " . $priorityColor . ";'>
                    <p style='margin: 0;'>" . nl2br(htmlspecialchars($message)) . "</p>
                </div>"
                . $deviceInfo .
                "<p><strong>Recommended Action:</strong> Review this alert immediately and take appropriate action.</p>",
                'View Details',
                $actionUrl
            );
            
            if (sendEmail($admin['email'], $subject, $body)) {
                $sentCount++;
                
                // Log the notification sent
                logAudit(
                    $_SESSION['user_id'],
                    'Send Critical Alert Notification',
                    'system_alerts',
                    null,
                    $relatedDeviceId,
                    "Alert: " . htmlspecialchars($title) . " sent to: {$admin['full_name']} (Priority: $priority)"
                );
            }
        }
    }
    
    // Add system notification to all admins
    foreach ($admins as $admin) {
        $notificationType = 'admin_alert_' . $alertType;
        addSystemNotificationOnlyIfNotExists(
            $admin['id'],
            $notificationType,
            htmlspecialchars($title),
            htmlspecialchars($message),
            $relatedDeviceId
        );
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Alert notification sent to $sentCount admin(s)",
        'sent_count' => $sentCount,
        'admins_notified' => count($admins),
        'alert_type' => $alertType,
        'priority' => $priority
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
