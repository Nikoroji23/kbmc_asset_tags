<?php
/**
 * API - Send Admin Account Recovery Request Notification
 * Endpoint for admins to send email reminders about pending account recovery requests
 */

header('Content-Type: application/json');
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !hasRole('admin')) {
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
$recoveryId = (int)($json['recovery_id'] ?? 0);

if (!$recoveryId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing recovery_id']);
    exit();
}

try {
    // Get account recovery request details
    $stmt = $pdo->prepare("
        SELECT ar.*, u.email, u.full_name, u.employee_id, u.department
        FROM account_recovery_requests ar
        JOIN users u ON ar.user_id = u.id
        WHERE ar.id = ?
    ");
    $stmt->execute([$recoveryId]);
    $recovery = $stmt->fetch();
    
    if (!$recovery) {
        echo json_encode(['success' => false, 'message' => 'Recovery request not found']);
        exit();
    }
    
    if ($recovery['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'This recovery request has already been processed']);
        exit();
    }
    
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
    
    if (isEmailConfigured()) {
        foreach (filterUniqueEmails($admins) as $admin) {
            $subject = '[KBMC Alert] Account Recovery Request - ' . htmlspecialchars($recovery['full_name']) . ' (' . htmlspecialchars($recovery['employee_id']) . ')';
            
            $recoveryLink = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/recovery_requests.php?status=pending';
            
            $body = emailTemplate(
                'Pending Account Recovery Request',
                "<p>Hello <strong>" . htmlspecialchars($admin['full_name']) . "</strong>,</p>
                <p>A new account recovery request requires your attention and approval.</p>
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <p><strong>Employee Information:</strong></p>
                    <p><i class='fas fa-user'></i> <strong>Name:</strong> " . htmlspecialchars($recovery['full_name']) . "</p>
                    <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . htmlspecialchars($recovery['employee_id']) . "</p>
                    <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . htmlspecialchars($recovery['email']) . "</p>
                    <p><i class='fas fa-building'></i> <strong>Department:</strong> " . htmlspecialchars($recovery['department'] ?: 'Not specified') . "</p>
                </div>
                <div style='background: #f0f7ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                    <p><strong>Request Details:</strong></p>
                    <p><i class='fas fa-calendar'></i> <strong>Requested At:</strong> " . date('M d, Y h:i A', strtotime($recovery['requested_at'])) . "</p>
                    <p><i class='fas fa-comment'></i> <strong>Reason:</strong> " . htmlspecialchars($recovery['request_reason'] ?: 'No reason provided') . "</p>
                </div>
                <p><strong>Action Required:</strong> Please review this request and approve or reject it in the system. Approval will require your master security key.</p>",
                'Review Recovery Requests',
                $recoveryLink
            );
            
            if (sendEmail($admin['email'], $subject, $body)) {
                $sentCount++;
                
                // Log the notification sent
                logAudit(
                    $_SESSION['user_id'],
                    'Send Admin Recovery Notification',
                    'account_recovery_requests',
                    $recoveryId,
                    null,
                    "Recovery request notification sent to: {$admin['full_name']} ({$admin['email']})"
                );
            }
        }
    }
    
    // Add system notification to all admins
    foreach ($admins as $admin) {
        addSystemNotificationOnlyIfNotExists(
            $admin['id'],
            'account_recovery_requested',
            'Account Recovery Request',
            "Recovery request from " . htmlspecialchars($recovery['full_name']) . " (" . htmlspecialchars($recovery['employee_id']) . ") requires approval.",
            $recoveryId
        );
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Notification sent to $sentCount admin(s)",
        'sent_count' => $sentCount,
        'admins_notified' => count($admins)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
