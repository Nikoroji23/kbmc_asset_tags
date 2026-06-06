<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

// Get all pending recovery requests
$pending = $pdo->query("
    SELECT ar.id, ar.user_id, u.full_name, u.employee_id
    FROM account_recovery_requests ar
    JOIN users u ON ar.user_id = u.id
    WHERE ar.status = 'pending'
")->fetchAll();

// Get all admin users
$admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);

$created = 0;
foreach ($pending as $recovery) {
    foreach ($admins as $admin_id) {
        // Check if notification already exists
        $exists = $pdo->query("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = $admin_id 
            AND type = 'account_recovery_requested' 
            AND related_id = {$recovery['id']}
        ")->fetchColumn();
        
        if (!$exists) {
            addNotification(
                $admin_id,
                'account_recovery_requested',
                'Account Recovery Request',
                "Recovery request from " . $recovery['full_name'] . " ({$recovery['employee_id']})",
                $recovery['id']
            );
            $created++;
        }
    }
}

echo json_encode([
    'success' => true,
    'pending_recoveries' => count($pending),
    'admin_users' => count($admins),
    'notifications_created' => $created,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);
?>
