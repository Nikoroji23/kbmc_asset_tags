<?php
/**
 * API - Send Admin New User Registration Notification
 * Endpoint for admins to send email reminders about new employee account registrations
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
$creationId = (int)($json['creation_id'] ?? 0);

if (!$creationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing creation_id']);
    exit();
}

try {
    // Get account creation record details
    $stmt = $pdo->prepare("
        SELECT ac.*, u.status as user_status, u.id as user_id
        FROM account_creations ac
        LEFT JOIN users u ON ac.user_id = u.id
        WHERE ac.id = ?
    ");
    $stmt->execute([$creationId]);
    $creation = $stmt->fetch();
    
    if (!$creation) {
        echo json_encode(['success' => false, 'message' => 'Account creation record not found']);
        exit();
    }
    
    // Get all active admins and IT staff to send notification to
    $recipients = $pdo->query("
        SELECT id, email, full_name, role
        FROM users 
        WHERE (role = 'admin' OR role = 'it_staff') AND status = 'active' 
        ORDER BY role, full_name
    ")->fetchAll();
    
    if (empty($recipients)) {
        echo json_encode(['success' => false, 'message' => 'No active admins or IT staff found to notify']);
        exit();
    }
    
    $sentCount = 0;
    
    if (isEmailConfigured()) {
        foreach (filterUniqueEmails($recipients) as $recipient) {
            $subject = '[KBMC Alert] New Employee Account Registration - ' . htmlspecialchars($creation['full_name']) . ' (' . htmlspecialchars($creation['employee_id']) . ')';
            
            $accountsLink = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/admin_accounts.php';
            
            $statusBadge = ($creation['user_status'] === 'active') 
                ? '<span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 4px;">Active</span>'
                : '<span style="background: #ffc107; color: black; padding: 2px 8px; border-radius: 4px;">Pending</span>';
            
            $createdByLabel = ($creation['created_by'] === 'self_registration') ? 'Self Registration' : htmlspecialchars($creation['created_by']);
            
            $body = emailTemplate(
                'New Employee Account Registered',
                "<p>Hello <strong>" . htmlspecialchars($recipient['full_name']) . "</strong>,</p>
                <p>A new employee account has been registered in the KBMC Asset Management System.</p>
                <div style='background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4caf50;'>
                    <p><strong>Employee Account Details:</strong></p>
                    <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . htmlspecialchars($creation['employee_id']) . "</p>
                    <p><i class='fas fa-user'></i> <strong>Full Name:</strong> " . htmlspecialchars($creation['full_name']) . "</p>
                    <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . htmlspecialchars($creation['email']) . "</p>
                    <p><i class='fas fa-building'></i> <strong>Department:</strong> " . htmlspecialchars($creation['department'] ?: 'Not specified') . "</p>
                    <p><i class='fas fa-briefcase'></i> <strong>Position:</strong> " . htmlspecialchars($creation['position'] ?: 'Not specified') . "</p>
                    <p><i class='fas fa-phone'></i> <strong>Phone:</strong> " . htmlspecialchars($creation['phone'] ?: 'Not provided') . "</p>
                </div>
                <div style='background: #f0f7ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                    <p><strong>Registration Information:</strong></p>
                    <p><i class='fas fa-calendar'></i> <strong>Registered At:</strong> " . date('M d, Y h:i A', strtotime($creation['created_at'])) . "</p>
                    <p><i class='fas fa-user-plus'></i> <strong>Created By:</strong> " . $createdByLabel . "</p>
                    <p><i class='fas fa-info-circle'></i> <strong>Account Status:</strong> " . $statusBadge . "</p>
                </div>
                <p>Please review this account creation record for your archival purposes and ensure the employee is properly onboarded in the system.</p>",
                'View Account Records',
                $accountsLink
            );
            
            if (sendEmail($recipient['email'], $subject, $body)) {
                $sentCount++;
                
                // Log the notification sent
                logAudit(
                    $_SESSION['user_id'],
                    'Send Admin New User Notification',
                    'account_creations',
                    $creationId,
                    null,
                    "New user notification sent to: {$recipient['full_name']} ({$recipient['email']}) for {$creation['full_name']}"
                );
            }
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Notification sent to $sentCount admin/IT staff member(s)",
        'sent_count' => $sentCount,
        'recipients_notified' => count($recipients)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
