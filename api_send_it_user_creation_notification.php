<?php
/**
 * API - Send IT User Creation Notification
 * Endpoint for admins to notify Security IT approvers about new IT user creation
 * Sends email and creates system notifications for IT staff with master key access
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
$userId = (int)($json['user_id'] ?? 0);

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit();
}

try {
    // Get the newly created IT user details
    $stmt = $pdo->prepare("
        SELECT id, employee_id, full_name, email, department, position, role, created_at
        FROM users
        WHERE id = ? AND role IN ('it_staff', 'admin')
    ");
    $stmt->execute([$userId]);
    $newUser = $stmt->fetch();
    
    if (!$newUser) {
        echo json_encode(['success' => false, 'message' => 'User not found or not an IT staff/admin']);
        exit();
    }
    
    // Get all active IT staff to send notifications (including Security IT Approvers)
    // ALL IT staff should be notified about new IT user creation
    $recipients = $pdo->query("
        SELECT id, email, full_name, role, is_security_admin
        FROM users 
        WHERE status = 'active' AND role = 'it_staff'
        ORDER BY full_name
    ")->fetchAll();
    
    if (empty($recipients)) {
        echo json_encode(['success' => false, 'message' => 'No active IT staff found to notify']);
        exit();
    }
    
    $sentCount = 0;
    $createdNotifications = 0;
    $failedCount = 0;
    
    // Create system notifications for each IT staff member
    // Use batch insertion to handle many users efficiently
    $notificationStmt = $pdo->prepare("
        INSERT IGNORE INTO notifications (user_id, type, related_id, title, message, is_read, created_at) 
        VALUES (?, 'it_user_created', ?, ?, ?, 0, NOW())
    ");
    
    $title = 'New ' . ucfirst(str_replace('_', ' ', $newUser['role'])) . ' User Created';
    $message = 'A new ' . strtoupper($newUser['role']) . ' account has been created for ' . $newUser['full_name'] . ' (' . $newUser['employee_id'] . '). Master key and security approval may be required.';
    
    // Prepare audit log entry (once per notification batch, not per recipient)
    $auditMessage = "New IT user created: {$newUser['full_name']} ({$newUser['email']}) - Role: {$newUser['role']}. Notifications sent to " . count($recipients) . " IT staff members.";
    
    foreach ($recipients as $recipient) {
        try {
            $notificationStmt->execute([
                $recipient['id'],
                $userId,
                $title,
                $message
            ]);
            $createdNotifications++;
        } catch (Exception $e) {
            error_log("[IT_USER_NOTIF] Failed to create notification for user {$recipient['id']}: " . $e->getMessage());
            $failedCount++;
        }
    }
    
    // Send emails if configured
    if (isEmailConfigured()) {
        foreach (filterUniqueEmails($recipients) as $recipient) {
            $subject = '[KBMC Alert] New ' . ucfirst(str_replace('_', ' ', $newUser['role'])) . ' User Created - ' . htmlspecialchars($newUser['full_name']) . ' (' . htmlspecialchars($newUser['employee_id']) . ')';
            
            $assignSecurityITLink = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/assign_security_it.php';
            
            $roleBadge = ($newUser['role'] === 'admin') 
                ? '<span style="background: #e74c3c; color: white; padding: 3px 10px; border-radius: 4px; font-weight: bold;">ADMIN</span>'
                : '<span style="background: #3498db; color: white; padding: 3px 10px; border-radius: 4px; font-weight: bold;">IT STAFF</span>';
            
            $body = emailTemplate(
                'New ' . ucfirst(str_replace('_', ' ', $newUser['role'])) . ' Account Created',
                "<p>Hello <strong>" . htmlspecialchars($recipient['full_name']) . "</strong>,</p>
                <p>A new " . htmlspecialchars(strtoupper($newUser['role'])) . " account has been created in the KBMC Asset Management System.</p>
                <div style='background: #e8f4f8; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                    <p><strong>New User Account Details:</strong></p>
                    <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . htmlspecialchars($newUser['employee_id']) . "</p>
                    <p><i class='fas fa-user'></i> <strong>Full Name:</strong> " . htmlspecialchars($newUser['full_name']) . "</p>
                    <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . htmlspecialchars($newUser['email']) . "</p>
                    <p><i class='fas fa-role'></i> <strong>Role:</strong> " . $roleBadge . "</p>
                    <p><i class='fas fa-building'></i> <strong>Department:</strong> " . htmlspecialchars($newUser['department'] ?: 'Not specified') . "</p>
                    <p><i class='fas fa-briefcase'></i> <strong>Position:</strong> " . htmlspecialchars($newUser['position'] ?: 'Not specified') . "</p>
                </div>
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <p><strong>Action Required:</strong></p>
                    <p>This new " . htmlspecialchars(strtoupper($newUser['role'])) . " account may require security approval and master key assignment. If you are a designated Security IT approver, please review and grant security access as needed.</p>
                </div>
                <p>Please check the KBMC Asset Management System for more details and to manage security approvals.</p>",
                'View Security IT Management',
                $assignSecurityITLink
            );
            
            if (sendEmail($recipient['email'], $subject, $body)) {
                $sentCount++;
                // Small delay between emails to prevent server overload
                if (count($recipients) > 5) {
                    usleep(100000); // 0.1 second delay if many recipients
                }
            } else {
                $failedCount++;
                error_log("[IT_USER_NOTIF_EMAIL] Failed to send email to {$recipient['email']}");
            }
        }
        
        // Single audit log entry for the batch (not per recipient to avoid log spam)
        if ($sentCount > 0 || $createdNotifications > 0) {
            logAudit(
                $_SESSION['user_id'],
                'Create IT User - Notifications',
                'users',
                $userId,
                null,
                $auditMessage . " - Emails sent: $sentCount, Notifications: $createdNotifications, Failed: $failedCount"
            );
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Notifications created for " . count($recipients) . " IT staff member(s)",
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'notifications_created' => $createdNotifications,
        'recipients_notified' => count($recipients),
        'user_id' => $userId,
        'user_role' => $newUser['role']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
