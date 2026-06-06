<?php
/**
 * API - Sync IT User Creation Notifications
 * Called after Security IT grant/approval to ensure notifications are synced properly
 */

header('Content-Type: application/json');
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
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
    // Get the IT user details
    $stmt = $pdo->prepare("
        SELECT id, employee_id, full_name, email, role, is_security_admin
        FROM users
        WHERE id = ? AND role IN ('it_staff', 'admin')
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Get all active IT staff and admins
    $recipients = $pdo->query("
        SELECT id, email, full_name
        FROM users 
        WHERE (role = 'it_staff' OR role = 'admin') AND status = 'active'
        ORDER BY full_name
    ")->fetchAll();
    
    if (empty($recipients)) {
        echo json_encode(['success' => false, 'message' => 'No recipients found']);
        exit();
    }
    
    $syncedCount = 0;
    $createdCount = 0;
    $failedCount = 0;
    
    // Create security grant notification for all IT staff and admins
    // Use INSERT IGNORE to prevent duplicate errors
    $notificationStmt = $pdo->prepare("
        INSERT IGNORE INTO notifications (user_id, type, related_id, title, message, is_read, created_at) 
        VALUES (?, 'it_user_security_granted', ?, ?, ?, 0, NOW())
    ");
    
    $securityStatus = $user['is_security_admin'] ? 'SECURITY IT APPROVER' : 'STANDARD IT STAFF';
    $title = 'Security Access Granted - ' . $user['full_name'];
    $message = 'Security access has been granted for ' . $user['full_name'] . ' (' . $user['employee_id'] . ') as ' . $securityStatus . '.';
    
    foreach ($recipients as $recipient) {
        try {
            $notificationStmt->execute([
                $recipient['id'],
                $user['id'],
                $title,
                $message
            ]);
            $createdCount++;
            $syncedCount++;
        } catch (Exception $e) {
            error_log("[IT_SECURITY_SYNC] Failed to create notification: " . $e->getMessage());
            $failedCount++;
        }
    }
    
    // Send email notification if configured
    if (isEmailConfigured()) {
        $notifText = $user['is_security_admin'] 
            ? "has been granted Security IT Approver privileges"
            : "has been assigned standard IT staff access";
        
        $statusBadge = $user['is_security_admin'] 
            ? '<span style="background: #e74c3c; color: white; padding: 3px 10px; border-radius: 4px; font-weight: bold;">SECURITY IT APPROVER</span>'
            : '<span style="background: #3498db; color: white; padding: 3px 10px; border-radius: 4px; font-weight: bold;">STANDARD IT STAFF</span>';
        
        $usersManagementLink = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/assign_security_it.php';
        
        $emailsSent = 0;
        foreach (filterUniqueEmails($recipients) as $recipient) {
            try {
                $subject = '[KBMC Alert] Security Access Granted - ' . htmlspecialchars($user['full_name']);
                
                $body = emailTemplate(
                    'Security Access Granted',
                    "<p>Hello <strong>" . htmlspecialchars($recipient['full_name']) . "</strong>,</p>
                    <p>Security access has been granted in the KBMC Asset Management System.</p>
                    <div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;'>
                        <p><strong>User Account Details:</strong></p>
                        <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . htmlspecialchars($user['employee_id']) . "</p>
                        <p><i class='fas fa-user'></i> <strong>Full Name:</strong> " . htmlspecialchars($user['full_name']) . "</p>
                        <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>
                        <p><i class='fas fa-lock'></i> <strong>Access Level:</strong> " . $statusBadge . "</p>
                    </div>
                    <p>The user " . $notifText . " and can now perform their assigned duties in the system.</p>",
                    'View IT Staff Management',
                    $usersManagementLink
                );
                
                if (sendEmail($recipient['email'], $subject, $body)) {
                    $emailsSent++;
                    // Small delay between emails if many recipients to prevent server overload
                    if (count($recipients) > 10) {
                        usleep(50000); // 0.05 second delay for bulk sends
                    }
                }
            } catch (Exception $e) {
                error_log("[IT_SECURITY_SYNC_EMAIL] Failed to send email to {$recipient['email']}: " . $e->getMessage());
            }
        }
        
        // Single audit log for the batch (not per recipient to avoid log spam)
        if ($createdCount > 0 || $emailsSent > 0) {
            logAudit(
                $_SESSION['user_id'],
                'Grant Security IT Access - Sync Notifications',
                'users',
                $userId,
                null,
                "User: {$user['full_name']} ({$user['email']}) - Status: $securityStatus. Notifications: $createdCount, Emails: $emailsSent, Failed: $failedCount, Total Recipients: " . count($recipients)
            );
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Notifications synced for " . count($recipients) . " recipients",
        'synced_count' => $syncedCount,
        'created_count' => $createdCount,
        'total_recipients' => count($recipients),
        'user_role' => $user['role']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
