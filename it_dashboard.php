<?php
/**
 * KBMC Asset Management - IT Dashboard
 * Exclusive dashboard for IT Staff members
 */
$pageTitle = 'IT Dashboard';
require_once 'includes/header.php';
requireITStaff();
$isSecurityAdmin = isSecurityAdmin($_SESSION['user_id']);

// Get IT-specific statistics
$totalDevices = getTotalDeviceCount();
$inStock = getDeviceCountByStatus('in_stock');
$deployed = getDeviceCountByStatus('deployed');
$underRepair = getDeviceCountByStatus('under_repair');
$retired = getDeviceCountByStatus('retired') + getDeviceCountByStatus('disposed');
$pendingRepairCount = count(getPendingRepairs());
$pendingInspectionCount = $pdo->query("SELECT COUNT(*) FROM devices WHERE status = 'pending_inspection'")->fetchColumn();
$activeAssignments = getActiveAssignmentCount();
$pendingReqCount = $pdo->query("SELECT COUNT(*) FROM device_requests WHERE status = 'pending'")->fetchColumn();

// Get recent repairs
$stmt = $pdo->query("SELECT dr.*, u.full_name, d.asset_tag FROM device_repairs dr JOIN users u ON dr.reported_by = u.id JOIN devices d ON dr.device_id = d.id WHERE dr.repair_status = 'pending' ORDER BY dr.created_at DESC LIMIT 5");
$pendingRepairs = $stmt->fetchAll();

// Get recent inspections
$stmt = $pdo->query("SELECT di.*, d.asset_tag, u.full_name FROM device_inspections di JOIN devices d ON di.device_id = d.id JOIN users u ON di.inspected_by = u.id ORDER BY di.created_at DESC LIMIT 5");
$recentInspections = $stmt->fetchAll();

// Get pending device requests
$stmt = $pdo->query("SELECT dr.*, u.full_name FROM device_requests dr JOIN users u ON dr.requester_id = u.id WHERE dr.status = 'pending' ORDER BY dr.created_at DESC LIMIT 5");
$pendingRequests = $stmt->fetchAll();

// Get recent deployments
$stmt = $pdo->query("SELECT da.*, d.asset_tag, u.full_name as employee_name FROM device_assignments da JOIN devices d ON da.device_id = d.id JOIN users u ON da.employee_id = u.id ORDER BY da.assigned_date DESC LIMIT 5");
$recentDeployments = $stmt->fetchAll();

// Get latest audit logs (for IT staff to view)
$stmt = $pdo->query("SELECT al.*, u.full_name FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 10");
$auditLogs = $stmt->fetchAll();
?>

<!-- IT Dashboard Header -->
<div style="margin-bottom: 20px; padding: 15px 20px; background: linear-gradient(135deg, #3498DB 0%, #2980B9 100%); border-radius: 8px; color: white;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="margin: 0; font-size: 24px;"><i class="fas fa-cogs"></i> IT Staff Dashboard</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 13px;">Device Management & Technical Support</p>
        </div>
        <div style="text-align: right; font-size: 12px; opacity: 0.9;">
            <div><strong><?php echo date('l, F d, Y'); ?></strong></div>
            <div>User: <?php echo sanitize($_SESSION['full_name']); ?></div>
            <?php if ($isSecurityAdmin): ?>
            <div style="margin-top: 5px; background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 3px; display: inline-block; font-size: 11px;">
                <i class="fas fa-key"></i> Security IT
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- IT-Specific Metrics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-tools"></i></div>
        <div class="stat-info">
            <h3><?php echo $pendingRepairCount; ?></h3>
            <span>Pending Repairs</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-clipboard-check"></i></div>
        <div class="stat-info">
            <h3><?php echo $pendingInspectionCount; ?></h3>
            <span>Pending Inspections</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-hand-holding"></i></div>
        <div class="stat-info">
            <h3><?php echo $activeAssignments; ?></h3>
            <span>Active Assignments</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-bell"></i></div>
        <div class="stat-info">
            <h3><?php echo $pendingReqCount; ?></h3>
            <span>Pending Requests</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-laptop"></i></div>
        <div class="stat-info">
            <h3><?php echo $totalDevices; ?></h3>
            <span>Total Devices</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray"><i class="fas fa-box"></i></div>
        <div class="stat-info">
            <h3><?php echo $inStock; ?></h3>
            <span>In Stock</span>
        </div>
    </div>
</div>



<!-- Quick Actions -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-lightning-bolt"></i> Quick Actions</h3>
    </div>
    <div class="card-body" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="import_assets.php" class="btn btn-primary" style="flex: 1; min-width: 150px;">
            <i class="fas fa-file-import"></i> Import Assets
        </a>
        <a href="add_device.php" class="btn btn-primary" style="flex: 1; min-width: 150px;">
            <i class="fas fa-plus"></i> Add New Device
        </a>
        <a href="inspections.php" class="btn btn-warning" style="flex: 1; min-width: 150px;">
            <i class="fas fa-clipboard-check"></i> Inspect Device
        </a>
        <a href="repairs.php" class="btn btn-danger" style="flex: 1; min-width: 150px;">
            <i class="fas fa-tools"></i> Manage Repairs
        </a>
        <a href="deployments.php" class="btn btn-success" style="flex: 1; min-width: 150px;">
            <i class="fas fa-hand-holding"></i> Deploy Device
        </a>
        <a href="maintenance_reminders.php" class="btn btn-info" style="flex: 1; min-width: 150px;">
            <i class="fas fa-calendar-check"></i> Maintenance
        </a>
        <a href="it_clearance.php" class="btn btn-danger" style="flex: 1; min-width: 150px;">
            <i class="fas fa-user-check"></i> User Clearance
        </a>
        <a href="requests.php" class="btn btn-outline" style="flex: 1; min-width: 150px;">
            <i class="fas fa-tasks"></i> Device Requests
        </a>
        <?php if ($isSecurityAdmin): ?>
        <a href="assign_security_it.php" class="btn btn-secondary" style="flex: 1; min-width: 150px;">
            <i class="fas fa-user-shield"></i> Manage Security IT
        </a>
        <a href="security_control.php" class="btn btn-danger" style="flex: 1; min-width: 150px;">
            <i class="fas fa-shield-alt"></i> Security Control
        </a>
        <?php else: ?>
        <button type="button" class="btn btn-outline" style="flex: 1; min-width: 150px; opacity: 0.6; cursor: not-allowed;" title="Ask your admin to assign Security IT approval privileges.">
            <i class="fas fa-shield-alt"></i> Security Control
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isSecurityAdmin): ?>
<div class="card" style="margin-top: 20px; border: 1px solid #f0ad4e; background: #fff8e1;">
    <div class="card-body">
        <h3 style="margin-top: 0;"><i class="fas fa-exclamation-triangle"></i> Security IT Access Required</h3>
        <p style="margin: 0; color: #555;">
            If you need to approve new IT or admin user requests, your IT account must be designated as a Security IT approver.
            Ask your administrator to assign <strong>Security IT</strong> privileges to your account and set your master key.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Pending Repairs & Inspections -->
<div class="grid-2" style="margin-top: 20px;">
    <!-- Pending Repairs -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-tools"></i> Pending Repairs (<?php echo count($pendingRepairs); ?>)</h3>
            <a href="repairs.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRepairs)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color: #27AE60;"></i>
                <h4>All caught up!</h4>
                <p>No pending repairs at the moment.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Issue</th>
                            <th>Reported By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRepairs as $repair): ?>
                        <tr>
                            <td><strong><?php echo sanitize($repair['asset_tag']); ?></strong></td>
                            <td><small><?php echo sanitize(substr($repair['issue_description'], 0, 40)) . (strlen($repair['issue_description']) > 40 ? '...' : ''); ?></small></td>
                            <td><?php echo sanitize($repair['full_name']); ?></td>
                            <td><?php echo formatDate($repair['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Inspections -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clipboard-check"></i> Recent Inspections</h3>
            <a href="inspections.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentInspections)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No inspections yet</h4>
                <p>Completed inspections will appear here.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Result</th>
                            <th>Condition</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInspections as $insp): ?>
                        <tr>
                            <td><strong><?php echo sanitize($insp['asset_tag']); ?></strong></td>
                            <td>
                                <span style="padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: <?php echo $insp['result'] == 'passed' ? '#27AE6030' : '#E74C3C30'; ?>; color: <?php echo $insp['result'] == 'passed' ? '#27AE60' : '#E74C3C'; ?>;">
                                    <?php echo ucfirst($insp['result']); ?>
                                </span>
                            </td>
                            <td><?php echo ucfirst($insp['physical_condition']); ?></td>
                            <td><?php echo formatDate($insp['inspection_date']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pending Requests & Deployments -->
<div class="grid-2" style="margin-top: 20px;">
    <!-- Pending Device Requests -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-tasks"></i> Pending Requests (<?php echo $pendingReqCount; ?>)</h3>
            <a href="requests.php?status=pending" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color: #27AE60;"></i>
                <h4>All requests processed</h4>
                <p>No pending device requests.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Requested By</th>
                            <th>Device Type</th>
                            <th>Quantity</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $req): ?>
                        <tr>
                            <td><?php echo sanitize($req['full_name']); ?></td>
                            <td><?php echo sanitize($req['device_type'] ?? 'Unspecified'); ?></td>
                            <td><?php echo $req['quantity'] ?? 1; ?></td>
                            <td><?php echo formatDate($req['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Deployments -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-hand-holding"></i> Recent Deployments</h3>
            <a href="deployments.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentDeployments)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No deployments yet</h4>
                <p>Device deployments will appear here.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Assigned To</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDeployments as $dep): ?>
                        <tr>
                            <td><strong><?php echo sanitize($dep['asset_tag']); ?></strong></td>
                            <td><?php echo sanitize($dep['employee_name']); ?></td>
                            <td>
                                <span style="padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: <?php echo $dep['status'] == 'active' ? '#27AE6030' : '#F39C1230'; ?>; color: <?php echo $dep['status'] == 'active' ? '#27AE60' : '#F39C12'; ?>;">
                                    <?php echo ucfirst(str_replace('_', ' ', $dep['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($dep['assigned_date']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Device Status Overview -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-chart-bar"></i> Device Status Overview</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
            <div style="padding: 15px; background: #27AE6015; border-radius: 8px; text-align: center; border-left: 4px solid #27AE60;">
                <div style="font-size: 24px; font-weight: 700; color: #27AE60;"><?php echo $inStock; ?></div>
                <div style="font-size: 12px; color: #555; margin-top: 5px;">In Stock</div>
            </div>
            <div style="padding: 15px; background: #3498DB15; border-radius: 8px; text-align: center; border-left: 4px solid #3498DB;">
                <div style="font-size: 24px; font-weight: 700; color: #3498DB;"><?php echo $deployed; ?></div>
                <div style="font-size: 12px; color: #555; margin-top: 5px;">Deployed</div>
            </div>
            <div style="padding: 15px; background: #F39C1215; border-radius: 8px; text-align: center; border-left: 4px solid #F39C12;">
                <div style="font-size: 24px; font-weight: 700; color: #F39C12;"><?php echo $underRepair; ?></div>
                <div style="font-size: 12px; color: #555; margin-top: 5px;">Under Repair</div>
            </div>
            <div style="padding: 15px; background: #7F8C8D15; border-radius: 8px; text-align: center; border-left: 4px solid #7F8C8D;">
                <div style="font-size: 24px; font-weight: 700; color: #7F8C8D;"><?php echo $retired; ?></div>
                <div style="font-size: 12px; color: #555; margin-top: 5px;">Retired/Disposed</div>
            </div>
        </div>
    </div>
</div>

<!-- Audit Logs & Notifications Popup Modal -->
<div id="auditNotificationsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; flex-display: flex; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 1000px; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <!-- Modal Header -->
        <div style="padding: 20px; background: linear-gradient(135deg, #3498DB 0%, #2980B9 100%); color: white; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="margin: 0; font-size: 20px;"><i class="fas fa-history"></i> Audit Logs & Notifications</h2>
                <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 12px;">Latest system activity and alerts for IT staff</p>
            </div>
            <button type="button" onclick="closeAuditNotificationsPopup()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 4px;">×</button>
        </div>

        <!-- Modal Tabs -->
        <div style="padding: 12px 20px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; display: flex; gap: 10px;">
            <button onclick="switchAuditTab('logs')" id="logsTabBtn" style="padding: 8px 16px; background: white; border: 2px solid #3498DB; color: #3498DB; cursor: pointer; border-radius: 4px; font-weight: 600; transition: all 0.3s;">
                <i class="fas fa-history"></i> Audit Logs (<?php echo count($auditLogs); ?>)
            </button>
            <button onclick="switchAuditTab('notifications')" id="notificationsTabBtn" style="padding: 8px 16px; background: transparent; border: 2px solid #ddd; color: #666; cursor: pointer; border-radius: 4px; font-weight: 600; transition: all 0.3s;">
                <i class="fas fa-bell"></i> Notifications (<?php echo count($latestNotifications); ?>)
            </button>
        </div>

        <!-- Modal Body -->
        <div style="flex: 1; overflow-y: auto; padding: 0;">
            <!-- Audit Logs Tab -->
            <div id="logsTab" style="padding: 20px;">
                <?php if (empty($auditLogs)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <i class="fas fa-inbox" style="font-size: 40px; color: #ddd; display: block; margin-bottom: 12px;"></i>
                    <h4 style="color: #aaa; font-weight: 500;">No audit logs yet</h4>
                </div>
                <?php else: ?>
                <div class="data-table-wrapper" style="border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                    <table class="data-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #e0e0e0;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #333;">Date & Time</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #333;">User</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #333;">Action</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #333;">Table</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #333;">Record ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogs as $log): ?>
                            <tr style="border-bottom: 1px solid #e0e0e0; hover: background #f8f9fa;">
                                <td style="padding: 12px; font-size: 12px; color: #666;"><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                <td style="padding: 12px; font-size: 12px;"><strong><?php echo sanitize($log['full_name'] ?? 'System'); ?></strong></td>
                                <td style="padding: 12px; font-size: 12px;">
                                    <span style="padding: 3px 8px; background: #E8F5E9; color: #2E7D32; border-radius: 3px; font-weight: 500;">
                                        <?php echo sanitize($log['action']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; font-size: 12px; color: #666;"><?php echo sanitize($log['table_name'] ?? 'N/A'); ?></td>
                                <td style="padding: 12px; font-size: 12px; color: #666;"><?php echo $log['record_id'] ?? 'N/A'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Notifications Tab -->
            <div id="notificationsTab" style="padding: 20px; display: none;">
                <?php if (empty($latestNotifications)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <i class="fas fa-bell-slash" style="font-size: 40px; color: #ddd; display: block; margin-bottom: 12px;"></i>
                    <h4 style="color: #aaa; font-weight: 500;">No notifications yet</h4>
                </div>
                <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($latestNotifications as $notif):
                        $type = $notif['type'] ?? 'unknown';
                        $notifIcon = match($type) {
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
                            default                      => 'info-circle',
                        };
                        
                        $notifColor = match(true) {
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
                            str_starts_with($type, 'user_clearance') => '#8E44AD',
                            default                                  => '#C0392B',
                        };
                    ?>
                    <div style="padding: 12px; background: #f8f9fa; border-left: 4px solid <?php echo $notifColor; ?>; border-radius: 4px; cursor: pointer;" onclick="this.style.opacity='0.7';">
                        <div style="display: flex; gap: 12px; align-items: flex-start;">
                            <div style="font-size: 18px; color: <?php echo $notifColor; ?>; margin-top: 2px;">
                                <i class="fas fa-<?php echo $notifIcon; ?>"></i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: #333; font-size: 13px;">
                                    <?php echo sanitize($notif['title'] ?? 'Notification'); ?>
                                    <?php if (!$notif['is_read']): ?>
                                    <span style="display: inline-block; width: 8px; height: 8px; background: #3498DB; border-radius: 50%; margin-left: 6px; vertical-align: middle;"></span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;"><?php echo sanitize($notif['message'] ?? ''); ?></p>
                                <div style="margin-top: 6px; font-size: 11px; color: #999;">
                                    <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal Footer -->
        <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; justify-content: flex-end; gap: 10px;">
            <a href="notifications.php" class="btn btn-outline" style="text-decoration: none; padding: 8px 16px;">
                <i class="fas fa-external-link-alt"></i> View All Notifications
            </a>
            <button type="button" onclick="closeAuditNotificationsPopup()" class="btn btn-primary" style="padding: 8px 16px;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- JavaScript for Modal -->
<script>
function openAuditNotificationsPopup() {
    const modal = document.getElementById('auditNotificationsModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeAuditNotificationsPopup() {
    const modal = document.getElementById('auditNotificationsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function switchAuditTab(tabName) {
    // Hide all tabs
    document.getElementById('logsTab').style.display = 'none';
    document.getElementById('notificationsTab').style.display = 'none';

    // Remove active styling from all buttons
    document.getElementById('logsTabBtn').style.background = 'transparent';
    document.getElementById('logsTabBtn').style.borderColor = '#ddd';
    document.getElementById('logsTabBtn').style.color = '#666';
    
    document.getElementById('notificationsTabBtn').style.background = 'transparent';
    document.getElementById('notificationsTabBtn').style.borderColor = '#ddd';
    document.getElementById('notificationsTabBtn').style.color = '#666';

    // Show selected tab
    if (tabName === 'logs') {
        document.getElementById('logsTab').style.display = 'block';
        document.getElementById('logsTabBtn').style.background = 'white';
        document.getElementById('logsTabBtn').style.borderColor = '#3498DB';
        document.getElementById('logsTabBtn').style.color = '#3498DB';
    } else if (tabName === 'notifications') {
        document.getElementById('notificationsTab').style.display = 'block';
        document.getElementById('notificationsTabBtn').style.background = 'white';
        document.getElementById('notificationsTabBtn').style.borderColor = '#3498DB';
        document.getElementById('notificationsTabBtn').style.color = '#3498DB';
    }
}

// Close modal when clicking outside of it
document.getElementById('auditNotificationsModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeAuditNotificationsPopup();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAuditNotificationsPopup();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
