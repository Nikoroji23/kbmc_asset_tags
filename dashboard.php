<?php
/**
 * KBMC Asset Management - Dashboard
 */
$pageTitle = 'Dashboard';
require_once 'includes/header.php';

if (hasRole('admin')) {
    header('Location: admin_dashboard.php');
    exit();
}

if (hasRole('it_staff')) {
    header('Location: it_dashboard.php');
    exit();
}

if (hasRole('employee')) {
    header('Location: requests.php');
    exit();
}

// Get statistics
$totalDevices = getTotalDeviceCount();
$inStock = getDeviceCountByStatus('in_stock');
$deployed = getDeviceCountByStatus('deployed');
$underRepair = getDeviceCountByStatus('under_repair');
$retired = getDeviceCountByStatus('retired') + getDeviceCountByStatus('disposed');
$activeAssignments = getActiveAssignmentCount();
$isAdmin = hasRole('admin');
$isITStaff = hasRole('it_staff');
$isEmployee = hasRole('employee');

// Get role display name
$currentRole = $_SESSION['role'] ?? 'unknown';
$roleDisplay = [
    'admin' => 'Administrator',
    'it_staff' => 'IT Staff',
    'employee' => 'Employee'
][$currentRole] ?? 'Unknown';

// Recent devices
$stmt = $pdo->query("SELECT d.*, dt.type_name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.status NOT IN ('retired', 'disposed') ORDER BY d.created_at DESC LIMIT 5");
$recentDevices = $stmt->fetchAll();

// Recent assignments
$stmt = $pdo->query("SELECT da.*, d.asset_tag, dt.type_name AS device_type, u.full_name as employee_name FROM device_assignments da JOIN devices d ON da.device_id = d.id JOIN device_types dt ON d.device_type_id = dt.id JOIN users u ON da.employee_id = u.id ORDER BY da.created_at DESC LIMIT 5");
$recentAssignments = $stmt->fetchAll();

// Pending requests
$pendingReqCount = $pdo->query("SELECT COUNT(*) FROM device_requests WHERE status = 'pending'")->fetchColumn();
?>

<!-- Role Verification Banner -->
<div style="margin-bottom: 20px; padding: 12px 16px; background: <?php echo $isAdmin ? '#FDE8E9' : ($isITStaff ? '#EBF5FB' : '#F0F3F7'); ?>; border-left: 4px solid <?php echo $isAdmin ? '#D9232E' : ($isITStaff ? '#3498DB' : '#95A5A6'); ?>; border-radius: 6px; font-size: 13px;">
    <strong>Logged in as:</strong> <?php echo sanitize($roleDisplay); ?> 
    <?php if ($isAdmin): ?>
    <span style="margin-left: 10px; background: #D9232E; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 700;">ADMIN DASHBOARD</span>
    <?php elseif ($isITStaff): ?>
    <span style="margin-left: 10px; background: #3498DB; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 700;">IT STAFF DASHBOARD</span>
    <?php endif; ?>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-laptop"></i></div>
        <div class="stat-info">
            <h3><?php echo $totalDevices; ?></h3>
            <span>Total Devices</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-box"></i></div>
        <div class="stat-info">
            <h3><?php echo $inStock; ?></h3>
            <span>In Stock</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-hand-holding"></i></div>
        <div class="stat-info">
            <h3><?php echo $deployed; ?></h3>
            <span>Deployed</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-tools"></i></div>
        <div class="stat-info">
            <h3><?php echo $underRepair; ?></h3>
            <span>Under Repair</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon gray"><i class="fas fa-trash-alt"></i></div>
        <div class="stat-info">
            <h3><?php echo $retired; ?></h3>
            <span>Retired / Disposed</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-tasks"></i></div>
        <div class="stat-info">
            <h3><?php echo $activeAssignments; ?></h3>
            <span>Active Assignments</span>
        </div>
    </div>
</div>

<!-- Recent Devices & Deployments -->
<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Recent Devices Added</h3>
            <a href="devices.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentDevices)): ?>
            <div class="empty-state">
                <i class="fas fa-laptop"></i>
                <h4>No devices yet</h4>
                <p>Devices added will appear here.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDevices as $dev): ?>
                        <tr>
                            <td><strong><?php echo sanitize($dev['asset_tag']); ?></strong></td>
                            <td><?php echo sanitize($dev['type_name']); ?></td>
                            <td><?php echo getStatusBadge($dev['status']); ?></td>
                            <td><?php echo formatDate($dev['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-hand-holding"></i> Recent Deployments</h3>
            <a href="deployments.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentAssignments)): ?>
            <div class="empty-state">
                <i class="fas fa-hand-holding"></i>
                <h4>No deployments yet</h4>
                <p>Device assignments will appear here.</p>
            </div>
            <?php else: ?>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAssignments as $asgn): ?>
                        <tr>
                            <td><strong><?php echo sanitize($asgn['asset_tag']); ?></strong><br><small><?php echo sanitize($asgn['device_type']); ?></small></td>
                            <td><?php echo sanitize($asgn['employee_name']); ?></td>
                            <td><?php echo formatDate($asgn['assigned_date']); ?></td>
                            <td>
                                <span class="status-badge" style="background: <?php echo $asgn['status'] == 'active' ? '#27AE6020' : '#F39C1220'; ?>; color: <?php echo $asgn['status'] == 'active' ? '#27AE60' : '#F39C12'; ?>; border: 1px solid <?php echo $asgn['status'] == 'active' ? '#27AE60' : '#F39C12'; ?>;">
                                    <?php echo ucfirst($asgn['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
    </div>
    <div class="card-body" style="display: flex; gap: 15px; flex-wrap: wrap;">
        <?php if (hasRole('admin') || hasRole('it_staff')): ?>
        <a href="add_device.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Device
        </a>
        <a href="inspections.php" class="btn btn-secondary">
            <i class="fas fa-clipboard-check"></i> Inspect Device
        </a>
        <a href="deployments.php?action=assign" class="btn btn-success">
            <i class="fas fa-hand-holding"></i> Assign Device
        </a>
        <?php endif; ?>
        <a href="requests.php?action=new" class="btn btn-warning">
            <i class="fas fa-hand-paper"></i> Request Device
        </a>
        <?php if ($pendingReqCount > 0 && (hasRole('admin') || hasRole('it_staff'))): ?>
        <a href="requests.php" class="btn btn-danger">
            <i class="fas fa-bell"></i> <?php echo $pendingReqCount; ?> Pending Request<?php echo $pendingReqCount > 1 ? 's' : ''; ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (hasRole('admin') && $pendingRecoveryCount > 0): ?>
<!-- Recovery Requests Alert Card -->
<div class="card" style="border-left: 4px solid #E74C3C;">
    <div class="card-header" style="background: #FDEDEC;">
        <h3 style="color: #E74C3C;"><i class="fas fa-user-shield"></i> Account Recovery Requests</h3>
        <a href="users.php#recovery" class="btn btn-sm btn-danger">View All</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecoveryRequests as $req): ?>
                    <tr>
                        <td><strong><?php echo sanitize($req['full_name']); ?></strong></td>
                        <td><?php echo sanitize($req['email']); ?></td>
                        <td><?php echo sanitize($req['department'] ?: 'N/A'); ?></td>
                        <td><?php echo sanitize(substr($req['request_reason'], 0, 40)) . (strlen($req['request_reason']) > 40 ? '...' : ''); ?></td>
                        <td><?php echo formatDate($req['requested_at']); ?></td>
                        <td>
                            <div class="action-btns">
                                <form method="POST" action="user_actions.php" style="display:inline-block;margin:0;">
                                    <?php echo csrfInputField(); ?>
                                    <input type="hidden" name="action" value="process_recovery">
                                    <input type="hidden" name="recovery_id" value="<?php echo $req['id']; ?>">
                                    <input type="hidden" name="approval_action" value="approve">
                                    <button type="submit" class="action-btn assign" title="Approve"
                                        onclick="return confirm('Approve recovery for <?php echo sanitize($req['full_name']); ?>?')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <form method="POST" action="user_actions.php" style="display:inline-block;margin:0;">
                                    <?php echo csrfInputField(); ?>
                                    <input type="hidden" name="action" value="process_recovery">
                                    <input type="hidden" name="recovery_id" value="<?php echo $req['id']; ?>">
                                    <input type="hidden" name="approval_action" value="reject">
                                    <button type="submit" class="action-btn delete" title="Reject"
                                        onclick="return confirm('Reject recovery for <?php echo sanitize($req['full_name']); ?>?')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Status Distribution Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php foreach ($statusData as $s) echo "'" . ucwords(str_replace('_', ' ', $s['status'])) . "',"; ?>],
        datasets: [{
            data: [<?php foreach ($statusData as $s) echo $s['count'] . ","; ?>],
            backgroundColor: [
                <?php foreach ($statusData as $s) echo "'" . ($status_colors[$s['status']] ?? '#6C757D') . "',"; ?>
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 15, font: { size: 11 } } }
        }
    }
});

// Device Type Chart
const typeCtx = document.getElementById('typeChart').getContext('2d');
new Chart(typeCtx, {
    type: 'bar',
    data: {
        labels: [<?php foreach ($typeData as $t) echo "'" . sanitize($t['type_name']) . "',"; ?>],
        datasets: [{
            label: 'Devices',
            data: [<?php foreach ($typeData as $t) echo $t['count'] . ","; ?>],
            backgroundColor: '#D9232E',
            borderRadius: 5,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>