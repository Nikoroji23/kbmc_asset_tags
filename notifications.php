<?php
/**
 * KBMC Asset Management - All Notifications
 */
$pageTitle = 'Notifications';
require_once 'includes/functions.php';

// Allow both admins and IT staff to view notifications
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('it_staff'))) {
    setFlashMessage('error', 'Access denied. Admin or IT staff only.');
    header('Location: dashboard.php');
    exit();
}

require_once 'includes/header.php';

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$_SESSION['user_id']]);
    setFlashMessage('success', 'All notifications marked as read.');
    header('Location: notifications.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$allNotifications = $stmt->fetchAll();

// Filter notifications based on user role
$notifications = [];
$userRole = $_SESSION['role'] ?? 'employee';

if ($userRole === 'admin') {
    // Admins see only the 3 main feature notifications
    $mainFeatureTypes = getAdminMainFeatureNotificationTypes();
    foreach ($allNotifications as $notif) {
        if (in_array($notif['type'], $mainFeatureTypes)) {
            $notifications[] = $notif;
        }
    }
    
    // Categorize notifications by feature for tab display
    $categorizedNotifications = categorizeAdminNotifications($notifications);
} elseif ($userRole === 'it_staff') {
    // IT staff see all notifications (all system events are relevant to them)
    $notifications = $allNotifications;
} else {
    // Regular employees see only employee-relevant notifications
    $employeeRelevantTypes = [
        'device_deployed',
        'device_returned',
        'request_approved',
        'request_rejected',
        'user_clearance_required',
        'user_clearance_completed',
        'voluntary_return_requested',
        'warranty_expiring'
    ];
    
    foreach ($allNotifications as $notif) {
        if (in_array($notif['type'], $employeeRelevantTypes)) {
            $notifications[] = $notif;
        }
    }
}

// Icon map per notification type
function getNotificationIcon(string $type): string {
    return match($type) {
        'device_deployed'            => 'laptop',
        'device_returned'            => 'undo',
        'low_stock'                  => 'exclamation-triangle',
        'repair_needed'              => 'tools',
        'repair_pending'             => 'tools',
        'request_approved'           => 'check-circle',
        'request_rejected'           => 'times-circle',
        'warranty_expiring'          => 'clock',
        'user_clearance_required'    => 'file-signature',
        'user_clearance_completed'   => 'user-check',
        'user_approval_pending'      => 'user-check',
        'user_approval_requested'    => 'user-shield',
        'it_user_created'            => 'user-plus',
        'it_user_security_granted'   => 'user-shield',
        'voluntary_return_requested' => 'hand-holding',
        'maintenance_assigned'       => 'tools',
        'maintenance_completed'      => 'check-circle',
        'maintenance_due'            => 'calendar-alt',
        'lifespan_monitor'           => 'eye',
        'lifespan_replace_soon'      => 'hourglass-half',
        'lifespan_overdue'           => 'exclamation-triangle',
        'lifespan_replaced'          => 'archive',
        'lifespan_extended'          => 'plus-circle',
        'device_request'             => 'hand-paper',
        'new_device_added'           => 'plus-circle',
        default                      => 'info-circle',
    };
}

// Color per notification type
function getNotificationColor(string $type): string {
    return match(true) {
        $type === 'lifespan_overdue'             => '#E74C3C',
        $type === 'lifespan_replace_soon'        => '#E67E22',
        $type === 'lifespan_monitor'             => '#F39C12',
        $type === 'lifespan_extended'            => '#3498DB',
        $type === 'lifespan_replaced'            => '#7F8C8D',
        $type === 'request_approved'             => '#27AE60',
        $type === 'request_rejected'             => '#E74C3C',
        $type === 'repair_needed'                => '#E67E22',
        $type === 'repair_pending'               => '#F39C12',
        $type === 'warranty_expiring'            => '#F39C12',
        $type === 'maintenance_assigned'         => '#3498DB',
        $type === 'maintenance_completed'        => '#27AE60',
        $type === 'maintenance_due'              => '#E67E22',
        $type === 'device_deployed'              => '#3498DB',
        $type === 'device_returned'              => '#27AE60',
        $type === 'device_request'               => '#9B59B6',
        $type === 'new_device_added'             => '#2ECC71',
        $type === 'user_approval_pending'        => '#F39C12',
        $type === 'user_approval_requested'      => '#F39C12',
        $type === 'it_user_created'              => '#3498DB',
        $type === 'it_user_security_granted'     => '#27AE60',
        str_starts_with($type, 'user_clearance') => '#8E44AD',
        str_starts_with($type, 'account_recovery') => '#E74C3C',
        default                                  => '#C0392B',
    };
}

function getNotificationTypeLabel(string $type): string {
    return match(true) {
        $type === 'device_deployed'            => 'Device Deployed',
        $type === 'device_returned'            => 'Device Returned',
        $type === 'request_approved'           => 'Request Approved',
        $type === 'request_rejected'           => 'Request Rejected',
        $type === 'warranty_expiring'          => 'Warranty Expiring',
        $type === 'user_clearance_required'    => 'IT Clearance Required',
        $type === 'user_clearance_completed'   => 'IT Clearance Completed',
        $type === 'user_approval_pending'      => 'User Approval Pending',
        $type === 'user_approval_requested'    => 'User Approval Requested',
        $type === 'it_user_created'            => 'IT User Created',
        $type === 'it_user_security_granted'   => 'IT Security Granted',
        $type === 'voluntary_return_requested' => 'Voluntary Return',
        $type === 'maintenance_assigned'       => 'Maintenance Assigned',
        $type === 'maintenance_completed'      => 'Maintenance Completed',
        $type === 'maintenance_due'            => 'Maintenance Due',
        $type === 'lifespan_monitor'           => 'Lifespan Monitor',
        $type === 'lifespan_replace_soon'      => 'Lifespan Replace Soon',
        $type === 'lifespan_overdue'           => 'Lifespan Overdue',
        $type === 'lifespan_replaced'          => 'Lifespan Replaced',
        $type === 'lifespan_extended'          => 'Lifespan Extended',
        $type === 'device_request'             => 'Device Request',
        $type === 'new_device_added'           => 'New Device Added',
        default                                => ucwords(str_replace('_', ' ', $type)),
    };
}
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1><i class="fas fa-bell"></i> Notifications</h1>
    <div style="display:flex;gap:10px;align-items:center;">
        <?php
        $unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));
        if ($unreadCount > 0):
        ?>
        <span style="font-size:13px;color:#666;"><?= $unreadCount ?> unread</span>
        <a href="notifications.php?mark_all_read=1" class="btn btn-outline">
            <i class="fas fa-check-double"></i> Mark All as Read
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Notification Tabs -->
<div style="margin: 20px 0; display: flex; gap: 10px; border-bottom: 2px solid #eee; flex-wrap: wrap;">
    <?php if ($userRole === 'admin'): ?>
        <!-- Admin Feature Tabs -->
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
    <?php else: ?>
        <!-- Regular User Tabs -->
        <button class="notif-tab active" data-filter="all" onclick="filterNotifications('all')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
            <i class="fas fa-inbox"></i> All Notifications (<?= count($notifications) ?>)
        </button>
        <button class="notif-tab" data-filter="unread" onclick="filterNotifications('unread')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
            <i class="fas fa-circle"></i> Unread (<?= count(array_filter($notifications, fn($n) => !$n['is_read'])) ?>)
        </button>
        <button class="notif-tab" data-filter="read" onclick="filterNotifications('read')" style="padding: 12px 20px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 600; color: #555; font-size: 14px;">
            <i class="fas fa-check-circle"></i> Previous (<?= count(array_filter($notifications, fn($n) => $n['is_read'])) ?>)
        </button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <!-- All Notifications -->
        <div id="notif-all" class="notif-group" style="display: block;">
            <?php if (empty($notifications)): ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-bell-slash" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No notifications yet</h4>
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
                $isUnread  = !$notif['is_read'];
                
                // Log clearance notifications for debugging
                if ($type === 'user_clearance_completed') {
                    error_log("[NOTIF_PAGE] Rendering user_clearance_completed - ID={$notif['id']}, related_id={$notif['related_id']}, URL=$notifUrl");
                }
            ?>
            <div class="notif-row <?= $isUnread ? 'notif-unread' : '' ?> notif-all-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="<?= $notif['is_read'] ? 1 : 0 ?>"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <span style="color:<?= $isUnread ? 'var(--kbmc-red,#C0392B)' : '#aaa' ?>;font-weight:<?= $isUnread ? '600' : '400' ?>;">
                            <?= $isUnread ? '● Unread' : 'Read' ?>
                        </span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>

                <!-- Unread dot -->
                <?php if ($isUnread): ?>
                <div class="notif-row-dot" style="background:<?= $color ?>;"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Unread Notifications -->
        <div id="notif-unread" class="notif-group" style="display: none;">
            <?php 
            $unreadNotifications = array_filter($notifications, fn($n) => !$n['is_read']);
            if (empty($unreadNotifications)): 
            ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-check-circle" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No unread notifications</h4>
            </div>
            <?php else: ?>
            <?php foreach ($unreadNotifications as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
            ?>
            <div class="notif-row notif-unread notif-unread-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="0"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <span style="color:var(--kbmc-red,#C0392B);font-weight:600;">
                            ● Unread
                        </span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>

                <!-- Unread dot -->
                <div class="notif-row-dot" style="background:<?= $color ?>;"></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Previous (Read) Notifications -->
        <div id="notif-read" class="notif-group" style="display: none;">
            <?php 
            $readNotifications = array_filter($notifications, fn($n) => $n['is_read']);
            if (empty($readNotifications)): 
            ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-history" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No previous notifications</h4>
            </div>
            <?php else: ?>
            <?php foreach ($readNotifications as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
            ?>
            <div class="notif-row notif-read-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="1"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)"
                 style="opacity: 0.8;">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <span style="color:#aaa;font-weight:400;">
                            Read
                        </span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ADMIN FEATURE-BASED GROUPS -->
        <?php if ($userRole === 'admin'): ?>

        <!-- Security Notifications -->
        <div id="notif-security" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['security'])): ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-lock" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No security alerts</h4>
            </div>
            <?php else: ?>
            <?php foreach ($categorizedNotifications['security'] as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
            ?>
            <div class="notif-row notif-security-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="<?= $notif['is_read'] ? 1 : 0 ?>"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Request Approval Notifications -->
        <div id="notif-requests" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['requests'])): ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-check-square" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No request alerts</h4>
            </div>
            <?php else: ?>
            <?php foreach ($categorizedNotifications['requests'] as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
            ?>
            <div class="notif-row notif-requests-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="<?= $notif['is_read'] ? 1 : 0 ?>"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- User Management Notifications -->
        <div id="notif-users" class="notif-group" style="display: none;">
            <?php if (empty($categorizedNotifications['users'])): ?>
            <div class="empty-state" style="padding:60px;text-align:center;">
                <i class="fas fa-users" style="font-size:40px;color:#ddd;display:block;margin-bottom:12px;"></i>
                <h4 style="color:#aaa;font-weight:500;">No user alerts</h4>
            </div>
            <?php else: ?>
            <?php foreach ($categorizedNotifications['users'] as $notif):
                $type      = $notif['type'] ?? 'unknown';
                $notifUrl  = getNotificationUrl($notif);
                $typeLabel = getNotificationTypeLabel($type);
                $icon      = getNotificationIcon($type);
                $color     = getNotificationColor($type);
            ?>
            <div class="notif-row notif-users-item"
                 data-id="<?= $notif['id'] ?>"
                 data-read="<?= $notif['is_read'] ? 1 : 0 ?>"
                 data-url="<?= htmlspecialchars($notifUrl) ?>"
                 onclick="handleNotificationClick(this)"
                 role="button"
                 tabindex="0"
                 onkeydown="if(event.key==='Enter') handleNotificationClick(this)">

                <!-- Icon -->
                <div class="notif-row-icon" style="background:<?= $color ?>18;color:<?= $color ?>;">
                    <i class="fas fa-<?= $icon ?>"></i>
                </div>

                <!-- Content -->
                <div class="notif-row-content">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div class="notif-row-title"><?= htmlspecialchars($notif['title']) ?></div>
                        <span class="notif-type-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </div>
                    <div class="notif-row-message"><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notif-row-meta">
                        <span><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        <a href="<?= htmlspecialchars($notifUrl) ?>" class="notif-row-link" style="color:<?= $color ?>; text-decoration:none; font-weight:600;" onclick="event.stopPropagation(); handleNotificationClick(this.closest('.notif-row')); return false;">
                            <i class="fas fa-arrow-right"></i> Click to view
                        </a>
                    </div>
                    <!-- URL Display -->
                    <div style="font-size: 11px; color: #3498DB; margin-top: 8px; padding: 6px 8px; background: #ecf7fe; border-left: 2px solid #3498DB; border-radius: 2px; word-break: break-all;">
                        <i class="fas fa-link" style="margin-right: 4px;"></i><strong>URL:</strong> <?= $notifUrl ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<style>
.notif-row {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background 0.15s;
    position: relative;
}
.notif-row:last-child  { border-bottom: none; }
.notif-row:hover       { background: #fafafa; }
.notif-row.notif-unread { background: #fffbfb; }
.notif-row.notif-unread:hover { background: #fff5f5; }

.notif-row-icon {
    width: 42px; height: 42px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 16px;
}
.notif-row-content  { flex: 1; min-width: 0; }
.notif-row-title    { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 3px; }
.notif-type-badge   { display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; }
.notif-row-message  { font-size: 13px; color: #4b5563; margin-bottom: 5px; line-height: 1.45; }
.notif-row-meta     { display: flex; gap: 14px; flex-wrap: wrap; font-size: 11px; color: #9ca3af; }
.notif-row-meta span, .notif-row-meta a { display: flex; align-items: center; gap: 4px; }
.notif-row-link { transition: color 0.2s ease; }
.notif-row-link:hover { text-decoration: underline; }
.notif-row-dot      { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; margin-top: 6px; }
.notif-row:focus    { outline: 2px solid #3b82f6; outline-offset: -2px; }

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

function handleNotificationClick(element) {
    var url     = element.getAttribute('data-url');
    var notifId = element.getAttribute('data-id');
 
    // Optimistically mark as read in the UI immediately
    element.classList.remove('notif-unread');
    var dot = element.querySelector('.notif-row-dot');
    if (dot) dot.remove();
    updateNavBadge(-1);
 
    // Tell the server, then navigate regardless of outcome
    fetch('mark_notification_read.php', {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body:        JSON.stringify({ id: parseInt(notifId, 10) })
    })
    .then(function () {
        if (url && url !== '') {
            window.location.href = url;
        }
    })
    .catch(function () {
        // On error still navigate
        if (url && url !== '') {
            window.location.href = url;
        }
    });
}
 
function updateNavBadge(delta) {
    var badge = document.getElementById('notifBadge')
             || document.querySelector('.notif-badge');
    if (!badge) return;
    var next = (parseInt(badge.textContent, 10) || 0) + delta;
    if (next <= 0) { badge.style.display = 'none'; }
    else           { badge.textContent = next; badge.style.display = ''; }
}
</script>
<?php require_once 'includes/footer.php'; ?>