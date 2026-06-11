<?php
/**
 * KBMC Asset Management - IT Audit Log (Comprehensive Device Activities)
 * Shows all IT staff activities: inspections, deployments, returns, clearances, modifications
 */
$pageTitle = 'IT Audit Log';
require_once 'includes/header.php';
requireITStaffOnly();

$search = $_GET['search'] ?? '';
$activity_type = $_GET['activity_type'] ?? '';
$it_staff = $_GET['it_staff'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build comprehensive query combining multiple IT activities
$sql = "SELECT * FROM (
    (SELECT 
        'asset_tag_change' as activity_type,
        al.id,
        al.user_id,
        al.action,
        al.table_name,
        al.record_id as device_id,
        al.old_values,
        al.new_values,
        al.created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        NULL as employee_name,
        NULL as assignment_status
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    LEFT JOIN devices d ON al.record_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    WHERE al.table_name = 'devices' AND (al.action = 'Asset Tag Change' OR al.action = 'Insert' OR al.action = 'Offboard User'))

    UNION ALL

    (SELECT 
        'inspection' as activity_type,
        di.id,
        di.inspected_by as user_id,
        'Device Inspection' as action,
        'device_inspections' as table_name,
        di.device_id,
        NULL as old_values,
        JSON_OBJECT('result', di.result, 'condition', di.physical_condition) as new_values,
        di.inspection_date as created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        NULL as employee_name,
        NULL as assignment_status
    FROM device_inspections di
    JOIN users u ON di.inspected_by = u.id
    JOIN devices d ON di.device_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id)

    UNION ALL

    (SELECT 
        'deployment' as activity_type,
        da.id,
        da.assigned_by as user_id,
        'Device Deployed' as action,
        'device_assignments' as table_name,
        da.device_id,
        NULL as old_values,
        JSON_OBJECT('status', da.status) as new_values,
        da.created_at as created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        emp.full_name as employee_name,
        da.status as assignment_status
    FROM device_assignments da
    JOIN users u ON da.assigned_by = u.id
    JOIN devices d ON da.device_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    JOIN users emp ON da.employee_id = emp.id
    WHERE da.status IN ('active', 'returned'))

    UNION ALL

    (SELECT 
        'clearance' as activity_type,
        al.id,
        al.user_id,
        al.action,
        'device_assignments' as table_name,
        al.record_id as device_id,
        NULL as old_values,
        al.new_values,
        al.created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        NULL as employee_name,
        NULL as assignment_status
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    LEFT JOIN devices d ON al.record_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    WHERE al.table_name = 'device_assignments' AND (al.action LIKE '%Clearance%' OR al.action LIKE '%Return%'))

    UNION ALL

    (SELECT 
        'disposal' as activity_type,
        al.id,
        al.user_id,
        al.action,
        'devices' as table_name,
        al.record_id as device_id,
        NULL as old_values,
        al.new_values,
        al.created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        NULL as employee_name,
        d.status as assignment_status
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    LEFT JOIN devices d ON al.record_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    WHERE al.table_name = 'devices' AND al.action = 'Dispose')

    UNION ALL

    (SELECT 
        'maintenance' as activity_type,
        al.id,
        al.user_id,
        al.action,
        'maintenance_schedules' as table_name,
        0 as device_id,
        NULL as old_values,
        al.new_values,
        al.created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        NULL as asset_tag,
        NULL as type_name,
        NULL as employee_name,
        NULL as assignment_status
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    WHERE al.table_name = 'maintenance_schedules' AND (al.action LIKE '%Maintenance%'))

    UNION ALL

    (SELECT 
        'repair' as activity_type,
        dr.id,
        dr.reported_by as user_id,
        'Create Repair Request' as action,
        'device_repairs' as table_name,
        dr.device_id,
        NULL as old_values,
        JSON_OBJECT('status', dr.repair_status, 'issue', dr.issue_description) as new_values,
        dr.created_at,
        u.full_name as staff_name,
        u.employee_id as staff_emp_id,
        d.asset_tag,
        dt.type_name,
        NULL as employee_name,
        dr.repair_status as assignment_status
    FROM device_repairs dr
    JOIN users u ON dr.reported_by = u.id
    JOIN devices d ON dr.device_id = d.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id)

) AS combined_activities WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (COALESCE(asset_tag, '') LIKE ? OR COALESCE(staff_name, '') LIKE ? OR COALESCE(employee_name, '') LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($activity_type) {
    $sql .= " AND activity_type = ?";
    $params[] = $activity_type;
}

if ($it_staff) {
    $sql .= " AND user_id = ?";
    $params[] = $it_staff;
}

if ($start_date) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $end_date;
}

$sql .= " ORDER BY created_at DESC LIMIT 500";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("[IT_AUDIT] SQL Error: " . $e->getMessage());
    error_log("[IT_AUDIT] SQL: " . $sql);
    error_log("[IT_AUDIT] Params: " . json_encode($params));
    $activities = [];
}

// Get list of IT staff for filter
$allITStaff = $pdo->query("
    SELECT DISTINCT u.id, u.full_name 
    FROM users u
    WHERE u.role IN ('admin', 'it_staff') AND u.status = 'active'
    ORDER BY u.full_name
")->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-history"></i> IT Audit Log</h1>
    <p style="color: #999; margin-top: 5px; font-size: 14px;">All device-related activities by IT staff</p>
    <a href="devices.php" class="btn btn-outline" style="margin-top: 10px;"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Filter Results</h3>
    </div>
    <div class="card-body">
        <div style="padding: 15px; background: #f9f9f9; border-radius: 4px; border-left: 3px solid #3498db;">
            <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: flex-end;">
                <div>
                    <label style="font-weight: 600; font-size: 13px; color: #555;">Search Asset Tag or Staff</label>
                    <input type="text" name="search" placeholder="Asset tag, staff name, employee..." value="<?php echo sanitize($search); ?>" class="form-control">
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 13px; color: #555;">Activity Type</label>
                    <select name="activity_type" class="form-control">
                        <option value="">All Activities</option>
                        <option value="inspection" <?php echo $activity_type === 'inspection' ? 'selected' : ''; ?>>Device Inspection</option>
                        <option value="deployment" <?php echo $activity_type === 'deployment' ? 'selected' : ''; ?>>Device Deployment</option>
                        <option value="clearance" <?php echo $activity_type === 'clearance' ? 'selected' : ''; ?>>Device Clearance</option>
                        <option value="asset_tag_change" <?php echo $activity_type === 'asset_tag_change' ? 'selected' : ''; ?>>Asset Tag Changes</option>
                        <option value="maintenance" <?php echo $activity_type === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="repair" <?php echo $activity_type === 'repair' ? 'selected' : ''; ?>>Device Repair</option>
                        <option value="disposal" <?php echo $activity_type === 'disposal' ? 'selected' : ''; ?>>Device Disposal</option>
                    </select>
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 13px; color: #555;">IT Staff</label>
                    <select name="it_staff" class="form-control">
                        <option value="">All Staff</option>
                        <?php foreach ($allITStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>" <?php echo $it_staff == $staff['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($staff['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 13px; color: #555;">From Date</label>
                    <input type="date" name="start_date" value="<?php echo sanitize($start_date); ?>" class="form-control">
                </div>

                <div>
                    <label style="font-weight: 600; font-size: 13px; color: #555;">To Date</label>
                    <input type="date" name="end_date" value="<?php echo sanitize($end_date); ?>" class="form-control">
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="asset_tag_audit.php" class="btn btn-light"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Latest Audit Records Section -->
<?php if (empty($activities)): ?>
<div class="card">
    <div class="card-body" style="text-align: center; padding: 40px;">
        <i class="fas fa-inbox" style="font-size: 48px; color: #bbb; display: block; margin-bottom: 15px;"></i>
        <h3 style="color: #999; margin: 0;">No Activities Found</h3>
        <p style="color: #bbb; margin-top: 10px;">No IT activities match your filters.</p>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Audit Logs (<span style="color: var(--kbmc-red); font-weight: 700;"><?php echo count($activities); ?></span> record<?php echo count($activities) !== 1 ? 's' : ''; ?>)</h3>
    </div>
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="auditLogsTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Activity Type</th>
                        <th>Asset Tag</th>
                        <th>Device Type</th>
                        <th>IT Staff</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $log): ?>
                        <?php
                        // Determine activity icon and label
                        $activityIcons = [
                            'inspection' => ['icon' => 'fa-clipboard-check', 'color' => '#3498db', 'label' => 'Device Inspection'],
                            'deployment' => ['icon' => 'fa-hand-holding', 'color' => '#27ae60', 'label' => 'Device Deployed'],
                            'clearance' => ['icon' => 'fa-check-circle', 'color' => '#9b59b6', 'label' => 'IT Clearance'],
                            'asset_tag_change' => ['icon' => 'fa-edit', 'color' => '#e74c3c', 'label' => 'Asset Tag Changed'],
                            'maintenance' => ['icon' => 'fa-wrench', 'color' => '#f39c12', 'label' => 'Maintenance'],
                            'repair' => ['icon' => 'fa-tools', 'color' => '#e67e22', 'label' => 'Repair'],
                            'disposal' => ['icon' => 'fa-trash', 'color' => '#95a5a6', 'label' => 'Device Disposed']
                        ];
                        
                        $icon = $activityIcons[$log['activity_type']]['icon'] ?? 'fa-info-circle';
                        $color = $activityIcons[$log['activity_type']]['color'] ?? '#999';
                        $label = $activityIcons[$log['activity_type']]['label'] ?? 'Activity';
                        
                        // Get details based on activity type
                        $details = '';
                        if ($log['activity_type'] === 'asset_tag_change') {
                            $oldData = json_decode($log['old_values'], true) ?? [];
                            $newData = json_decode($log['new_values'], true) ?? [];
                            $oldTag = $oldData['asset_tag'] ?? 'N/A';
                            $newTag = $newData['new_asset_tag'] ?? 'N/A';
                            $details = "<small style='color: #666;'><strong>Changed from:</strong> " . sanitize($oldTag) . " → " . sanitize($newTag) . "</small>";
                        } elseif ($log['activity_type'] === 'inspection') {
                            $newData = json_decode($log['new_values'], true) ?? [];
                            $result = $newData['result'] ?? 'N/A';
                            $condition = $newData['condition'] ?? 'N/A';
                            $details = "<small style='color: #666;'><strong>Result:</strong> " . ucfirst($result) . " | <strong>Condition:</strong> " . ucfirst($condition) . "</small>";
                        } elseif ($log['activity_type'] === 'deployment') {
                            $details = "<small style='color: #666;'><strong>Assigned to:</strong> " . sanitize($log['employee_name'] ?? 'N/A');
                            if ($log['assignment_status']) {
                                $details .= " | <strong>Status:</strong> " . ucfirst($log['assignment_status']);
                            }
                            $details .= "</small>";
                        } elseif ($log['activity_type'] === 'clearance') {
                            $details = "<small style='color: #666;'><strong>Action:</strong> " . sanitize($log['action']) . "</small>";
                        }
                        ?>
                    <tr>
                        <td>
                            <div style="font-size: 13px;">
                                <div style="font-weight: 600; color: #333;">
                                    <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                </div>
                                <div style="color: #999; font-size: 11px;">
                                    <?php echo date('g:i A', strtotime($log['created_at'])); ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas <?php echo $icon; ?>" style="color: <?php echo $color; ?>; font-size: 14px;"></i>
                                <span style="font-weight: 600; font-size: 12px; color: #333;"><?php echo $label; ?></span>
                            </div>
                        </td>
                        <td>
                            <span style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
                                <?php echo sanitize($log['asset_tag'] ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 12px;">
                                <div style="font-weight: 600; color: #333;">
                                    <?php echo sanitize($log['type_name'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 12px;">
                                <div style="font-weight: 600; color: #333;">
                                    <?php echo sanitize($log['staff_name']); ?>
                                </div>
                                <div style="color: #999; font-size: 11px;">
                                    ID: <?php echo sanitize($log['staff_emp_id']); ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php echo $details; ?>
                        </td>
                        <td class="action-btns">
                            <button onclick="sendAuditNotification(event, <?php echo $log['id']; ?>, '<?php echo sanitize($log['asset_tag'] ?? 'Device'); ?>')" class="btn btn-sm btn-info" title="Send Notification">
                                <i class="fas fa-bell"></i> Notify
                            </button>
                            <?php if (!empty($log['device_id'])): ?>
                            <a href="view_device.php?id=<?php echo $log['device_id']; ?>" class="btn btn-sm btn-secondary" title="View Device Details">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <?php else: ?>
                            <span style="color: #999; font-size: 12px;">Device Deleted</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<script>
function sendAuditNotification(e, auditId, assetTag) {
    e.preventDefault();
    const message = 'Send audit notification for device ' + assetTag + '?';
    if (!confirm(message)) return;
    
    fetch('api_send_inspection_notification.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            inspection_id: parseInt(auditId),
            asset_tag: assetTag
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Notification sent successfully to IT staff.');
        } else {
            alert('Error: ' + (data.message || 'Failed to send notification'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to send notification');
    });
}
</script>
