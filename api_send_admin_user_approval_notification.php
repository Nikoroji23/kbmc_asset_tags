<?php
/**
 * API - Send Admin User Approval Request Notification
 * Endpoint for admins to send email reminders about pending user approval requests
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
$approvalId = (int)($json['approval_id'] ?? 0);

if (!$approvalId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing approval_id']);
    exit();
}

try {
    // Get user approval request details
    $stmt = $pdo->prepare("
        SELECT uar.*, u.full_name as requested_by_name, u.email as requested_by_email
        FROM user_approval_requests uar
        LEFT JOIN users u ON uar.requested_by = u.id
        WHERE uar.id = ? AND uar.status = 'pending'
    ");
    $stmt->execute([$approvalId]);
    $approval = $stmt->fetch();
    
    if (!$approval) {
        echo json_encode(['success' => false, 'message' => 'Approval request not found or already processed']);
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
            $roleLabel = ucfirst(str_replace('_', ' ', $approval['requested_role']));
            $subject = '[KBMC Alert] New User Approval Required - ' . htmlspecialchars($approval['full_name']) . ' (' . $roleLabel . ')';
            
            $approvalLink = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/users.php?tab=approvals';
            
            $body = emailTemplate(
                'New User Account Approval Required',
                "<p>Hello <strong>" . htmlspecialchars($admin['full_name']) . "</strong>,</p>
                <p>A new user account request requires your approval. This is for a new " . htmlspecialchars($roleLabel) . " account.</p>
                <div style='background: #e7f3ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0056b3;'>
                    <p><strong>User Details:</strong></p>
                    <p><i class='fas fa-user'></i> <strong>Name:</strong> " . htmlspecialchars($approval['full_name']) . "</p>
                    <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . htmlspecialchars($approval['employee_id']) . "</p>
                    <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . htmlspecialchars($approval['email']) . "</p>
                    <p><i class='fas fa-building'></i> <strong>Department:</strong> " . htmlspecialchars($approval['department'] ?: 'Not specified') . "</p>
                    <p><i class='fas fa-briefcase'></i> <strong>Position:</strong> " . htmlspecialchars($approval['position'] ?: 'Not specified') . "</p>
                    <p><i class='fas fa-shield-alt'></i> <strong>Requested Role:</strong> <span style='background: #007bff; color: white; padding: 2px 8px; border-radius: 4px;'>" . htmlspecialchars($roleLabel) . "</span></p>
                </div>
                <div style='background: #f0f7ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                    <p><strong>Request Information:</strong></p>
                    <p><i class='fas fa-calendar'></i> <strong>Requested At:</strong> " . date('M d, Y h:i A', strtotime($approval['created_at'])) . "</p>
                    <p><i class='fas fa-user-circle'></i> <strong>Requested By:</strong> " . htmlspecialchars($approval['requested_by_name'] ?: 'System') . "</p>
                </div>
                <p><strong>Action Required:</strong> Please review this account request and approve or reject it in the system. Approval will require your master security key for security verification.</p>",
                'Review Pending Approvals',
                $approvalLink
            );
            
            if (sendEmail($admin['email'], $subject, $body)) {
                $sentCount++;
                
                // Log the notification sent
                logAudit(
                    $_SESSION['user_id'],
                    'Send Admin User Approval Notification',
                    'user_approval_requests',
                    $approvalId,
                    null,
                    "User approval notification sent to: {$admin['full_name']} ({$admin['email']}) for {$approval['full_name']} ({$roleLabel})"
                );
            }
        }
    }
    
    // Add system notification to all admins
    foreach ($admins as $admin) {
        addSystemNotificationOnlyIfNotExists(
            $admin['id'],
            'user_approval_requested',
            'New User Approval Required',
            "New " . htmlspecialchars(strtolower(str_replace('_', ' ', $approval['requested_role']))) . " account request from " . htmlspecialchars($approval['full_name']) . " requires approval.",
            null
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
