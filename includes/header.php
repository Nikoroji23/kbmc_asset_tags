<?php
ob_start();
require_once __DIR__ . '/functions.php';
ensureDeviceSchema();
requireLogin();
// Fix any deployment/device status inconsistencies (e.g., orphaned deployed devices)
fixDeploymentStatusConsistency();

$user = getUserInfo($_SESSION['user_id']);
$unreadCount = getUnreadNotificationCount($_SESSION['user_id']);
$notifications = getNotifications($_SESSION['user_id'], 10);
$pageTitle = $pageTitle ?? 'KBMC Asset Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($pageTitle); ?> - KBMC Asset Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    
    <!-- Global Notification Handler (must be defined before page content uses it) -->
    <script>
        function handleNotificationClick(element) {
            var id = element.dataset.id;
            var url = element.dataset.url || 'notifications.php';
            var type = element.dataset.type || 'unknown';
            var title = element.querySelector('.notif-content .notif-title')?.textContent || 'Unknown';
            
            console.log('🔔 Notification clicked:', { 
                id, 
                url, 
                type, 
                title,
                urlLength: url ? url.length : 0,
                urlTrimmed: url ? url.trim() : ''
            });
            
            if (!url || url.trim() === '') {
                console.error('❌ No valid URL for notification. Type:', type, 'ID:', id);
                alert('⚠️ Notification URL is empty. Type: ' + type + '\nPlease report this issue.');
                return false;
            }
            
            function navigate() {
                console.log('➜ Navigating to:', url);
                console.log('🔗 Full URL would be:', window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + url);
                window.location.href = url;
            }
            
            if (id) {
                console.log('📍 Marking notification as read:', id);
                fetch('mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ id: parseInt(id, 10) }),
                    credentials: 'same-origin'
                })
                .then(function (response) {
                    console.log('✓ Notification marked as read, response:', response.status);
                    navigate();
                })
                .catch(function (error) {
                    console.warn('⚠️ Error marking notification read:', error);
                    navigate();
                });
            } else {
                navigate();
            }
            
            return false; // Prevent default behavior
        }

    </script>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <img src="assets/images/logo.png" alt="KBMC Logo" class="sidebar-logo-img">
            </div>
            <div class="company-name">
                Kitchen Beauty<br>Marketing Corp.
            </div>
            <button class="sidebar-close" id="sidebarClose">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo sanitize($user['full_name']); ?></div>
                <div class="user-role"><?php echo $role_names[$user['role']] ?? 'User'; ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php if (hasRole('admin')): ?>
            <a href="admin_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-crown"></i>
                <span>Admin Dashboard</span>
            </a>
            <?php elseif (hasRole('it_staff')): ?>
            <a href="it_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'it_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-cogs"></i>
                <span>IT Dashboard</span>
            </a>
            <?php endif; ?>

            <?php if (hasRole('it_staff')): ?>
            <div class="nav-section">Device Management</div>
            <a href="devices.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'devices.php' ? 'active' : ''; ?>">
                <i class="fas fa-laptop"></i>
                <span>All Devices</span>
            </a>
            <a href="add_device.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'add_device.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i>
                <span>Add Device</span>
            </a>
            <a href="inspections.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'inspections.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-check"></i>
                <span>Inspections</span>
            </a>
            <a href="deployments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'deployments.php' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding"></i>
                <span>Deployments</span>
            </a>
            <a href="asset_tag_audit.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'asset_tag_audit.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i>
                <span>IT Audit Log</span>
            </a>
            <a href="retired.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'retired.php' ? 'active' : ''; ?>">
                <i class="fas fa-trash-alt"></i>
                <span>Retired / Disposed</span>
            </a>
            <a href="device_lifespan.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'device_lifespan.php' ? 'active' : ''; ?>">
                <i class="fas fa-hourglass-half"></i>
                <span>Device Lifespan</span>
                <?php
                // Badge: count devices that are overdue or replace_soon
                try {
                    $urgentLifespan = $pdo->query("
                        SELECT COUNT(DISTINCT d.id) FROM devices d
                        LEFT JOIN device_lifespan_forecast dlf ON d.id = dlf.device_id
                        LEFT JOIN device_type_lifespans dtl ON d.device_type_id = dtl.device_type_id
                        WHERE d.purchase_date IS NOT NULL
                          AND d.status NOT IN ('retired','disposed')
                          AND (
                              dlf.forecast_status IN ('overdue','replace_soon')
                              OR (
                                  dlf.forecast_status IS NULL
                                  AND DATE_ADD(d.purchase_date, INTERVAL COALESCE(d.expected_lifespan_years, dtl.default_years, 5) YEAR) <= DATE_ADD(NOW(), INTERVAL 1 YEAR)
                              )
                          )
                    ")->fetchColumn();
                    if ($urgentLifespan > 0):
                ?>
                <span class="nav-badge"><?php echo $urgentLifespan; ?></span>
                <?php endif; } catch (Exception $e) {} ?>
            </a>
            <?php endif; ?>

            <a href="requests.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : ''; ?>">
                <i class="fas fa-hand-paper"></i>
                <span>Device Requests</span>
                <?php
                $pendingRequests = $pdo->query("SELECT COUNT(*) FROM device_requests WHERE status = 'pending'")->fetchColumn();
                if ($pendingRequests > 0):
                ?>
                <span class="nav-badge"><?php echo $pendingRequests; ?></span>
                <?php endif; ?>
            </a>

            <a href="user_asset_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'user_asset_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-laptop-house"></i>
                <span>My Devices</span>
            </a>

            <?php if (hasRole('it_staff')): ?>
            <a href="maintenance_repairs.php" class="nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['maintenance_repairs.php', 'maintenance_reminders.php', 'repairs.php']) ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i>
                <span>Maintenance & Repairs</span>
                <?php
                $pendingRepairs = $pdo->query("SELECT COUNT(*) FROM device_repairs WHERE repair_status IN ('pending', 'under_repair')")->fetchColumn();
                $upcomingMaint = $pdo->query("SELECT COUNT(*) FROM maintenance_schedules WHERE next_due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND next_due_date > NOW()")->fetchColumn();
                $totalBadge = $pendingRepairs + $upcomingMaint;
                if ($totalBadge > 0):
                ?>
                <span class="nav-badge"><?php echo $totalBadge; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
            <div class="nav-section">Administration</div>
            <a href="users.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users-cog"></i>
                <span>Manage Users</span>
            </a>
            <a href="recovery_requests.php" class="nav-item">
                <i class="fas fa-user-shield"></i>
                <span>Recovery Requests</span>
                <?php
                $pendingRecovery = $pdo->query("SELECT COUNT(*) FROM account_recovery_requests WHERE status = 'pending'")->fetchColumn();
                if ($pendingRecovery > 0):
                ?>
                <span class="nav-badge"><?php echo $pendingRecovery; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <div class="nav-section">Account</div>
            <a href="profile.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-cog"></i>
                <span>My Profile</span>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Top Header -->
        <header class="top-header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="header-title"><?php echo sanitize($pageTitle); ?></div>
            <div class="header-actions">
                <div class="notification-dropdown">
                    <button class="notif-btn" id="notifToggle" type="button" onclick="
                        var dropdown = document.getElementById('notifDropdown');
                        if (dropdown) {
                            dropdown.classList.toggle('show');
                        }
                        return false;
                    ">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <h4>Notifications</h4>
                            <a href="notifications.php">View All</a>
                        </div>
                        <div class="notif-list">
                            <?php if (empty($notifications)): ?>
                            <div class="notif-empty">No notifications</div>
                            <?php else: ?>
                            <?php foreach ($notifications as $notif):
                                $notifUrl = getNotificationUrl($notif);
                                if ($notif['type'] === 'user_clearance_required') {
                                    error_log("[NOTIF_RENDER] Type: {$notif['type']}, Related ID: {$notif['related_id']}, URL: $notifUrl");
                                }
                            ?>
                               <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" 
                                   data-id="<?php echo $notif['id']; ?>" 
                                   data-url="<?php echo sanitize($notifUrl); ?>"
                                   data-type="<?php echo sanitize($notif['type'] ?? 'unknown'); ?>"
                                   role="button"
                                   tabindex="0"
                                   style="cursor: pointer;"
                                   title="Click to navigate"
                                   onclick="handleNotificationClick(this)">
                                <div class="notif-icon">
                                    <i class="fas fa-<?php
                                        echo match($notif['type']) {
                                            'device_deployed' => 'laptop',
                                            'device_returned' => 'undo',
                                            'low_stock' => 'exclamation-triangle',
                                            'repair_needed' => 'tools',
                                            'request_approved' => 'check-circle',
                                            'request_rejected' => 'times-circle',
                                            'warranty_expiring' => 'clock',
                                            'user_clearance_required' => 'file-signature',
                                            'user_clearance_completed' => 'user-check',
                                            'user_approval_pending' => 'user-check',
                                            'user_approval_requested' => 'user-shield',
                                            'it_user_created' => 'user-plus',
                                            'it_user_security_granted' => 'user-shield',
                                            'voluntary_return_requested' => 'hand-holding',
                                            'lifespan_monitor' => 'eye',
                                            'lifespan_replace_soon' => 'hourglass-half',
                                            'lifespan_overdue' => 'exclamation-triangle',
                                            'lifespan_replaced' => 'archive',
                                            'lifespan_extended' => 'plus-circle',
                                            default => 'info-circle'
                                        };
                                    ?>"></i>
                                </div>
                                <div class="notif-content">
                                    <div class="notif-title"><?php echo sanitize($notif['title']); ?></div>
                                    <div class="notif-msg"><?php echo sanitize($notif['message']); ?></div>
                                    <div class="notif-time"><?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?></div>
                                    <div class="notif-hint" style="font-size: 12px; color: #888; margin-top: 4px;"><i class="fas fa-arrow-right"></i> Click to view</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <?php
            $flash = getFlashMessage();
            if ($flash):
            ?>
            <div class="alert alert-<?php echo $flash['type']; ?>" id="flashAlert">
                <i class="fas fa-<?php echo $flash['type'] == 'success' ? 'check-circle' : ($flash['type'] == 'error' ? 'times-circle' : 'info-circle'); ?>"></i>
                <?php echo sanitize($flash['message']); ?>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endif; ?>