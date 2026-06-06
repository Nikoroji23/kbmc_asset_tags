<?php
/**
 * API - Sync All Pending IT User Creation Notifications
 * Called by system or admins to ensure all IT user creation notifications are properly synced
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

try {
    // Get all IT staff and admin users created in the last 24 hours that don't have IT user creation notifications
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.employee_id, u.full_name, u.email, u.role, u.created_at
        FROM users u
        LEFT JOIN notifications n ON n.related_id = u.id AND n.type = 'it_user_created'
        WHERE (u.role = 'it_staff' OR u.role = 'admin')
          AND u.status = 'active'
          AND u.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND n.id IS NULL
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $pendingUsers = $stmt->fetchAll();
    
    $syncedCount = 0;
    
    foreach ($pendingUsers as $user) {
        // Get all active IT staff with Security IT approval privileges
        $recipientStmt = $pdo->query("
            SELECT id, email, full_name
            FROM users 
            WHERE is_security_admin = 1 AND status = 'active' AND role = 'it_staff'
            ORDER BY full_name
        ");
        $recipients = $recipientStmt->fetchAll();
        
        if (empty($recipients)) {
            continue;
        }
        
        // Create system notifications for each Security IT approver
        $notificationStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, related_id, title, message, is_read, created_at) 
            VALUES (?, 'it_user_created', ?, ?, ?, 0, NOW())
        ");
        
        foreach ($recipients as $recipient) {
            // Check if notification already exists
            $existsStmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? AND type = 'it_user_created' AND related_id = ?
            ");
            $existsStmt->execute([$recipient['id'], $user['id']]);
            
            if ($existsStmt->fetchColumn() == 0) {
                $title = 'New ' . ucfirst(str_replace('_', ' ', $user['role'])) . ' User Created';
                $message = 'A new ' . strtoupper($user['role']) . ' account has been created for ' . $user['full_name'] . ' (' . $user['employee_id'] . '). Master key and security approval may be required.';
                
                try {
                    $notificationStmt->execute([
                        $recipient['id'],
                        $user['id'],
                        $title,
                        $message
                    ]);
                    $syncedCount++;
                } catch (Exception $e) {
                    error_log("[SYNC_IT_NOTIF] Failed to create notification: " . $e->getMessage());
                }
            }
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Synced $syncedCount pending IT user creation notifications",
        'pending_users_checked' => count($pendingUsers),
        'notifications_created' => $syncedCount
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
