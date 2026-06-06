<?php
/**
 * KBMC Asset Management - Get New Notifications (API)
 * Returns unread notifications in JSON format for real-time popups
 */
header('Content-Type: application/json');
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

try {
    // Get only unread notifications from the last 2 minutes
    // This prevents showing too many old notifications
    $stmt = $pdo->prepare("
        SELECT id, type, title, message, created_at 
        FROM notifications 
        WHERE user_id = ? 
        AND is_read = 0 
        AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total unread count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->execute([$_SESSION['user_id']]);
    $unreadCount = $countStmt->fetchColumn();
    
    // Mark these notifications as displayed (but not read, so they still appear in the bell)
    // We'll mark them as read only when user clicks on them
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => (int)$unreadCount
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
