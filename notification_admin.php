<?php
/**
 * KBMC Asset Management - Admin Notifications
 * Exclusive notifications page for administrators
 */
$pageTitle = 'Admin Notifications';
require_once 'includes/functions.php';

// Admin access only
if (!isLoggedIn() || !hasRole('admin')) {
    setFlashMessage('error', 'Access denied. Admin only.');
    header('Location: dashboard.php');
    exit();
}

require_once 'includes/header.php';

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type IN (
        'account_recovery_requested',
        'account_recovery_approved',
        'account_recovery_rejected',
        'user_approval_requested',
        'user_creation_approved',
        'user_creation_rejected',
        'audit_reminder',
        'admin_alert_device_critical',
        'admin_alert_maintenance_overdue',
        'admin_alert_failed_logins',
        'admin_alert_system_alert',
        'admin_alert_security_warning',
        'admin_alert_device_issue',
        'admin_alert_custom',
        'new_user_account_created'
    )")->execute([$_SESSION['user_id']]);
    setFlashMessage('success', 'All admin notifications marked as read.');
    header('Location: notification_admin.php');
    exit();
}

// Mark single as read (via AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
    header('Content-Type: application/json');
    $notifId = (int)$_POST['notification_id'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$allNotifications = $stmt->fetchAll();

// Filter to only 3 main admin features
$mainFeatureTypes = getAdminMainFeatureNotificationTypes();

$notifications = [];
foreach ($allNotifications as $notif) {
    if (in_array($notif['type'], $mainFeatureTypes)) {
        $notifications[] = $notif;
    }
}

// Categorize notifications by feature
$categorizedNotifications = categorizeAdminNotifications($notifications);

// Icon mapping for admin notification types
function getAdminNotificationIcon(string $type): string {
    return match($type) {
        'account_recovery_requested'       => 'key',
        'account_recovery_approved'        => 'check-circle',
        'account_recovery_rejected'        => 'times-circle',
        'user_approval_requested'          => 'user-check',
        'user_creation_approved'           => 'user-plus',
        'user_creation_rejected'           => 'user-xmark',
        'audit_reminder'                   => 'clipboard-check',
        'admin_alert_device_critical'      => 'exclamation-circle',
        'admin_alert_maintenance_overdue'  => 'tools',
        'admin_alert_failed_logins'        => 'shield',
        'admin_alert_system_alert'         => 'exclamation-triangle',
        'admin_alert_security_warning'     => 'lock',
        'admin_alert_device_issue'         => 'laptop',
        'admin_alert_custom'               => 'bell',
        'new_user_account_created'         => 'user-circle',
        default                            => 'info-circle',
    };
}

// Color mapping for admin notification types
function getAdminNotificationColor(string $type): string {
    return match($type) {
        'account_recovery_requested'       => '#3498DB',  // Blue
        'account_recovery_approved'        => '#27AE60',  // Green
        'account_recovery_rejected'        => '#E74C3C',  // Red
        'user_approval_requested'          => '#F39C12',  // Orange
        'user_creation_approved'           => '#27AE60',  // Green
        'user_creation_rejected'           => '#E74C3C',  // Red
        'audit_reminder'                   => '#9B59B6',  // Purple
        'admin_alert_device_critical'      => '#C0392B',  // Dark Red
        'admin_alert_maintenance_overdue'  => '#E67E22',  // Orange
        'admin_alert_failed_logins'        => '#E74C3C',  // Red
        'admin_alert_system_alert'         => '#E67E22',  // Orange
        'admin_alert_security_warning'     => '#C0392B',  // Dark Red
        'admin_alert_device_issue'         => '#F39C12',  // Orange
        'admin_alert_custom'               => '#3498DB',  // Blue
        'new_user_account_created'         => '#2ECC71',  // Green
        default                            => '#95A5A6',  // Gray
    };
}

// Get friendly notification type label
function getAdminNotificationLabel(string $type): string {
    return match($type) {
        'account_recovery_requested'       => 'Account Recovery Request',
        'account_recovery_approved'        => 'Account Recovery Approved',
        'account_recovery_rejected'        => 'Account Recovery Rejected',
        'user_approval_requested'          => 'User Approval Requested',
        'user_creation_approved'           => 'User Creation Approved',
        'user_creation_rejected'           => 'User Creation Rejected',
        'audit_reminder'                   => 'Audit Reminder',
        'admin_alert_device_critical'      => 'Device Critical Alert',
        'admin_alert_maintenance_overdue'  => 'Maintenance Overdue Alert',
        'admin_alert_failed_logins'        => 'Failed Login Alert',
        'admin_alert_system_alert'         => 'System Alert',
        'admin_alert_security_warning'     => 'Security Warning',
        'admin_alert_device_issue'         => 'Device Issue Alert',
        'admin_alert_custom'               => 'Custom Alert',
        'new_user_account_created'         => 'New User Account',
        default                            => ucfirst(str_replace('_', ' ', $type)),
    };
}
?>

<!-- Page Header -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1><i class="fas fa-shield-alt"></i> Administrator Notifications</h1>
    <div style="display:flex;gap:10px;align-items:center;">
        <?php
        $unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));
        if ($unreadCount > 0):
        ?>
        <span style="font-size:13px;color:#666;"><?= $unreadCount ?> unread</span>
        <a href="notification_admin.php?mark_all_read=1" class="btn btn-outline">
            <i class="fas fa-check-double"></i> Mark All as Read
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Notification Tabs -->
<div style="margin: 20px 0; display: flex; gap: 10px; border-bottom: 2px solid #eee; flex-wrap: wrap;">
    <button class="notif-tab active" data-filter="all" onclick="filterNotifications('all')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
        <i class="fas fa-inbox"></i> All (<?= count($notifications) ?>)
    </button>
    <button class="notif-tab" data-filter="security" onclick="filterNotifications('security')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
        <i class="fas fa-lock"></i> 🔒 Security (<?= count($categorizedNotifications['security']) ?>)
    </button>
    <button class="notif-tab" data-filter="requests" onclick="filterNotifications('requests')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
        <i class="fas fa-check-square"></i> 📊 Requests (<?= count($categorizedNotifications['requests']) ?>)
    </button>
    <button class="notif-tab" data-filter="users" onclick="filterNotifications('users')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
        <i class="fas fa-users"></i> 👥 Users (<?= count($categorizedNotifications['users']) ?>)
    </button>
</div>

<!-- Notifications List -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <!-- All Notifications -->
        <div id="notif-all" class="notif-group" style="display: block;">
            <?php if (empty($notifications)): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999;">
                <i class="fas fa-inbox" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3 style="color: #999; margin: 0;">No Notifications</h3>
                <p style="font-size: 13px; margin: 5px 0 0 0;">All admin alerts are current.</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($notifications as $notif): 
                    $icon = getAdminNotificationIcon($notif['type']);
                    $color = getAdminNotificationColor($notif['type']);
                    $label = getAdminNotificationLabel($notif['type']);
                    $unreadClass = !$notif['is_read'] ? 'background-color: rgba(52, 152, 219, 0.08);' : '';
                    $notifUrl = htmlspecialchars(getNotificationUrl($notif), ENT_QUOTES);
                ?>
                <div class="notification-item" data-read="<?= $notif['is_read'] ? 1 : 0 ?>" style="<?= $unreadClass ?>padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; gap: 12px; align-items: flex-start; cursor: pointer; transition: background 0.2s; position: relative;" onclick="handleNotificationClick(<?= $notif['id'] ?>, '<?= $notifUrl ?>')">
                    <?php if (!$notif['is_read']): ?>
                    <div style="width: 4px; height: 4px; background: #3498DB; border-radius: 50%; margin-top: 8px; flex-shrink: 0;"></div>
                    <?php else: ?>
                    <div style="width: 4px; height: 4px; background: transparent; margin-top: 8px; flex-shrink: 0;"></div>
                    <?php endif; ?>
                    <div style="flex-shrink: 0;">
                        <div style="width: 40px; height: 40px; background: <?= $color ?>20; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?= $color ?>;">
                            <i class="fas fa-<?= $icon ?>" style="font-size: 18px;"></i>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2c3e50; font-size: 14px; margin-bottom: 4px;">
                                    <?= sanitize($notif['title'] ?? $label) ?>
                                </div>
                                <div style="font-size: 13px; color: #555; word-break: break-word; line-height: 1.4;">
                                    <?= sanitize($notif['message'] ?? '') ?>
                                </div>
                                <div style="font-size: 11px; color: #999; margin-top: 6px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <span><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                                    <span style="background: <?= $color ?>20; color: <?= $color ?>; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase;"><?= $label ?></span>
                                    <?php if ($notif['is_read']): ?>
                                    <span style="background: #ecf0f1; color: #7f8c8d; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600;"><i class="fas fa-check"></i> Read</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                                    <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; gap: 6px; opacity: 0; transition: opacity 0.2s;" class="notif-actions">
                                <?php if (!$notif['is_read']): ?>
                                <button onclick="markAsRead(event, <?= $notif['id'] ?>)" title="Mark as read" style="background: none; border: none; color: #3498DB; cursor: pointer; font-size: 14px; padding: 4px; border-radius: 3px;">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php endif; ?>
                                <a href="<?= $notifUrl ?>" title="View details" target="_blank" style="background: none; border: none; color: #3498DB; cursor: pointer; font-size: 14px; padding: 4px; border-radius: 3px; text-decoration: none;">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Security Notifications -->
        <div id="notif-security" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['security'])): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999;">
                <i class="fas fa-lock" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3 style="color: #999; margin: 0;">No Security Alerts</h3>
                <p style="font-size: 13px; margin: 5px 0 0 0;">No security-related notifications.</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($categorizedNotifications['security'] as $notif): 
                    $icon = getAdminNotificationIcon($notif['type']);
                    $color = getAdminNotificationColor($notif['type']);
                    $label = getAdminNotificationLabel($notif['type']);
                    $notifUrl = htmlspecialchars(getNotificationUrl($notif), ENT_QUOTES);
                ?>
                <div class="notification-item" data-read="<?= $notif['is_read'] ? 1 : 0 ?>" style="padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; gap: 12px; align-items: flex-start; cursor: pointer; transition: background 0.2s; position: relative;" onclick="handleNotificationClick(<?= $notif['id'] ?>, '<?= $notifUrl ?>')">
                    <div style="width: 4px; height: 4px; background: transparent; margin-top: 8px; flex-shrink: 0;"></div>
                    <div style="flex-shrink: 0;">
                        <div style="width: 40px; height: 40px; background: <?= $color ?>20; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?= $color ?>;">
                            <i class="fas fa-<?= $icon ?>" style="font-size: 18px;"></i>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2c3e50; font-size: 14px; margin-bottom: 4px;">
                                    <?= sanitize($notif['title'] ?? $label) ?>
                                </div>
                                <div style="font-size: 13px; color: #555; word-break: break-word; line-height: 1.4;">
                                    <?= sanitize($notif['message'] ?? '') ?>
                                </div>
                                <div style="font-size: 11px; color: #999; margin-top: 6px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <span><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                                    <span style="background: <?= $color ?>20; color: <?= $color ?>; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase;"><?= $label ?></span>
                                </div>
                                <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                                    <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; gap: 6px; opacity: 0; transition: opacity 0.2s;" class="notif-actions">
                                <a href="<?= $notifUrl ?>" title="View details" target="_blank" style="background: none; border: none; color: #3498DB; cursor: pointer; font-size: 14px; padding: 4px; border-radius: 3px; text-decoration: none;">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Request Approval Notifications -->
        <div id="notif-requests" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['requests'])): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999;">
                <i class="fas fa-check-square" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3 style="color: #999; margin: 0;">No Request Alerts</h3>
                <p style="font-size: 13px; margin: 5px 0 0 0;">No device request notifications.</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($categorizedNotifications['requests'] as $notif): 
                    $icon = getAdminNotificationIcon($notif['type']);
                    $color = getAdminNotificationColor($notif['type']);
                    $label = getAdminNotificationLabel($notif['type']);
                    $notifUrl = htmlspecialchars(getNotificationUrl($notif), ENT_QUOTES);
                ?>
                <div class="notification-item" data-read="<?= $notif['is_read'] ? 1 : 0 ?>" style="padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; gap: 12px; align-items: flex-start; cursor: pointer; transition: background 0.2s; position: relative;" onclick="handleNotificationClick(<?= $notif['id'] ?>, '<?= $notifUrl ?>')">
                    <div style="width: 4px; height: 4px; background: transparent; margin-top: 8px; flex-shrink: 0;"></div>
                    <div style="flex-shrink: 0;">
                        <div style="width: 40px; height: 40px; background: <?= $color ?>20; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?= $color ?>;">
                            <i class="fas fa-<?= $icon ?>" style="font-size: 18px;"></i>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2c3e50; font-size: 14px; margin-bottom: 4px;">
                                    <?= sanitize($notif['title'] ?? $label) ?>
                                </div>
                                <div style="font-size: 13px; color: #555; word-break: break-word; line-height: 1.4;">
                                    <?= sanitize($notif['message'] ?? '') ?>
                                </div>
                                <div style="font-size: 11px; color: #999; margin-top: 6px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <span><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                                    <span style="background: <?= $color ?>20; color: <?= $color ?>; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase;"><?= $label ?></span>
                                </div>
                                <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                                    <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; gap: 6px; opacity: 0; transition: opacity 0.2s;" class="notif-actions">
                                <a href="<?= $notifUrl ?>" title="View details" target="_blank" style="background: none; border: none; color: #3498DB; cursor: pointer; font-size: 14px; padding: 4px; border-radius: 3px; text-decoration: none;">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- User Management Notifications -->
        <div id="notif-users" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['users'])): ?>
            <div style="padding: 60px 20px; text-align: center; color: #999;">
                <i class="fas fa-users" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3 style="color: #999; margin: 0;">No User Alerts</h3>
                <p style="font-size: 13px; margin: 5px 0 0 0;">No user-related notifications.</p>
            </div>
            <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($categorizedNotifications['users'] as $notif): 
                    $icon = getAdminNotificationIcon($notif['type']);
                    $color = getAdminNotificationColor($notif['type']);
                    $label = getAdminNotificationLabel($notif['type']);
                    $notifUrl = htmlspecialchars(getNotificationUrl($notif), ENT_QUOTES);
                ?>
                <div class="notification-item" data-read="<?= $notif['is_read'] ? 1 : 0 ?>" style="padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; gap: 12px; align-items: flex-start; cursor: pointer; transition: background 0.2s; position: relative;" onclick="handleNotificationClick(<?= $notif['id'] ?>, '<?= $notifUrl ?>')">
                    <div style="width: 4px; height: 4px; background: transparent; margin-top: 8px; flex-shrink: 0;"></div>
                    <div style="flex-shrink: 0;">
                        <div style="width: 40px; height: 40px; background: <?= $color ?>20; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?= $color ?>;">
                            <i class="fas fa-<?= $icon ?>" style="font-size: 18px;"></i>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2c3e50; font-size: 14px; margin-bottom: 4px;">
                                    <?= sanitize($notif['title'] ?? $label) ?>
                                </div>
                                <div style="font-size: 13px; color: #555; word-break: break-word; line-height: 1.4;">
                                    <?= sanitize($notif['message'] ?? '') ?>
                                </div>
                                <div style="font-size: 11px; color: #999; margin-top: 6px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <span><i class="fas fa-calendar-alt" style="margin-right: 4px;"></i><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                                    <span style="background: <?= $color ?>20; color: <?= $color ?>; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase;"><?= $label ?></span>
                                </div>
                                <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                                    <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; gap: 6px; opacity: 0; transition: opacity 0.2s;" class="notif-actions">
                                <a href="<?= $notifUrl ?>" title="View details" target="_blank" style="background: none; border: none; color: #3498DB; cursor: pointer; font-size: 14px; padding: 4px; border-radius: 3px; text-decoration: none;">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Back to Dashboard link -->
<div style="margin-top: 20px; text-align: center;">
    <a href="admin_dashboard.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<style>
.notification-item:hover {
    background-color: #f8f9fa !important;
}

.notification-item:hover .notif-actions {
    opacity: 1 !important;
}

.notif-tab {
    transition: all 0.3s ease;
}

.notif-tab.active {
    border-bottom: 3px solid #3498DB !important;
    color: #3498DB !important;
}

.notif-tab:hover {
    color: #3498DB;
}

.notif-group {
    transition: opacity 0.3s ease;
}
</style>

<script>
function filterNotifications(filter) {
    // Update tabs
    document.querySelectorAll('.notif-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
    
    // Update groups
    document.querySelectorAll('.notif-group').forEach(group => {
        group.style.display = 'none';
        group.style.opacity = '0';
    });
    
    const selectedGroup = document.getElementById(`notif-${filter}`);
    if (selectedGroup) {
        selectedGroup.style.display = 'block';
        setTimeout(() => selectedGroup.style.opacity = '1', 10);
    }
}

function handleNotificationClick(notifId, url) {
    // Mark as read when clicking
    markAsRead(event, notifId, function() {
        // Then navigate
        if (url) window.location.href = url;
    });
}

function markAsRead(e, notifId, callback) {
    e.stopPropagation();
    
    fetch('notification_admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=mark_read&notification_id=' + notifId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Update UI: remove unread indicator
            const item = event.target.closest('.notification-item');
            if (item) {
                item.style.backgroundColor = '';
                // Hide the unread dot
                const dot = item.querySelector('div[style*="width: 4px"]');
                if (dot && dot.style.backgroundColor === 'rgb(52, 152, 219)') {
                    dot.style.backgroundColor = 'transparent';
                }
                item.setAttribute('data-read', '1');
            }
            if (callback) callback();
        }
    })
    .catch(err => console.error('Error:', err));
}
</script>

<?php require_once 'includes/footer.php'; ?>
