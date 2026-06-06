<?php
/**
 * KBMC Asset Management - View Device Details
 */

$pageTitle = 'Device Details';
require_once 'includes/header.php';
requireLogin();

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$assignmentStmt = $pdo->prepare(
    "SELECT da.*, u.full_name as employee_name, u.department, u.position,
            ub.full_name as assigned_by_name
     FROM device_assignments da
     JOIN users u ON da.employee_id = u.id
     LEFT JOIN users ub ON da.assigned_by = ub.id
     WHERE da.id = ? AND da.status = 'active'"
);
$assignmentStmt->execute([$assignmentId]);
$currentAssignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentAssignment) {
    setFlashMessage('error', 'Invalid return request or active assignment not found.');
    header('Location: devices.php');
    exit();
}

$deviceStmt = $pdo->prepare(
    "SELECT d.*, dt.type_name
     FROM devices d
     JOIN device_types dt ON d.device_type_id = dt.id
     WHERE d.id = ?"
);
$deviceStmt->execute([(int)$currentAssignment['device_id']]);
$device = $deviceStmt->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    setFlashMessage('error', 'Device not found.');
    header('Location: devices.php');
    exit();
}

$id = $device['id'];
$assignments = [$currentAssignment];

$stmt = $pdo->prepare(
    "SELECT di.*, u.full_name as inspector_name
     FROM device_inspections di
     JOIN users u ON di.inspected_by = u.id
     WHERE di.device_id = ?
     ORDER BY di.inspection_date DESC"
);
$stmt->execute([$device['id']]);
$inspections = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT dr.*, u.full_name as reporter_name
     FROM device_repairs dr
     JOIN users u ON dr.reported_by = u.id
     WHERE dr.device_id = ?
     ORDER BY dr.created_at DESC"
);
$stmt->execute([$device['id']]);
$repairs = $stmt->fetchAll();

// DEBUG: Log the voluntary return attempt
error_log("[VOLUNTARY_RETURN_DEBUG] Checking conditions...");
error_log("[VOLUNTARY_RETURN_DEBUG] mode=" . (isset($_GET['mode']) ? $_GET['mode'] : 'NOT SET'));
error_log("[VOLUNTARY_RETURN_DEBUG] currentAssignment exists: " . ($currentAssignment ? 'YES' : 'NO'));
error_log("[VOLUNTARY_RETURN_DEBUG] isEmployee: " . (hasRole('employee') ? 'YES' : 'NO'));
error_log("[VOLUNTARY_RETURN_DEBUG] session user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
error_log("[VOLUNTARY_RETURN_DEBUG] assignment employee_id: " . ($currentAssignment ? $currentAssignment['employee_id'] : 'N/A'));
if ($currentAssignment && isset($_SESSION['user_id'])) {
    error_log("[VOLUNTARY_RETURN_DEBUG] IDs match: " . ((int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id'] ? 'YES' : 'NO'));
}

if (isset($_GET['mode']) && $_GET['mode'] === 'voluntary' && $currentAssignment && hasRole('employee') && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id']) {
    error_log("[VOLUNTARY_RETURN] ✓ ALL CONDITIONS MET - Creating notification");
    $returnUrl = 'view_device.php?id=' . urlencode($device['id']);
    $title = 'Voluntary Return Requested';
    $message = "Employee {$currentAssignment['employee_name']} requested voluntary return for device {$device['asset_tag']}. Please review user clearance.";

    error_log("[VOLUNTARY_RETURN] Calling notifyITStaff with assignment_id=" . $currentAssignment['id']);
    notifyITStaff('user_clearance_required', $title, $message, $currentAssignment['id']);
    error_log("[VOLUNTARY_RETURN] notifyITStaff completed");
    
    addNotificationIfNotExists(
        $_SESSION['user_id'],
        'voluntary_return_requested',
        'Voluntary Return Requested',
        "Your voluntary return request for {$device['asset_tag']} has been sent to IT for clearance.",
        $device['id']
    );
    error_log("[VOLUNTARY_RETURN] addNotificationIfNotExists completed");

    setFlashMessage('success', 'Your voluntary return request has been sent to IT. Please complete clearance when IT contacts you.');
    redirect($returnUrl);
}
?>

<div class="page-header">
    <h1><i class="fas fa-laptop"></i> Device Details</h1>
    <div style="display: flex; gap: 10px;">
        <a href="devices.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
        <?php if (hasRole('admin') || hasRole('it_staff')): ?>
        <a href="edit_device.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">

    <!-- ══ Device Info ══════════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> Device Information</h3>
        </div>
        <div class="card-body" style="font-size: 14px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div>
                    <strong style="color: #666; font-size: 12px;">Asset Tag</strong><br>
                    <span style="font-size: 18px; font-weight: 700; color: var(--kbmc-red);">
                        <?php echo sanitize($device['asset_tag']); ?>
                    </span>
                </div>
                <div><strong style="color: #666; font-size: 12px;">Status</strong><br><?php echo getStatusBadge($device['status']); ?></div>
                <div><strong style="color: #666; font-size: 12px;">Device Type</strong><br><?php echo sanitize($device['type_name']); ?></div>
                <div><strong style="color: #666; font-size: 12px;">PC Name</strong><br><?php echo sanitize($device['pc_name'] ?: 'N/A'); ?></div>
                <div><strong style="color: #666; font-size: 12px;">IP Address</strong><br><?php echo sanitize($device['ip_address'] ?: 'N/A'); ?></div>
                <div><strong style="color: #666; font-size: 12px;">Location</strong><br><?php echo sanitize($device['location']); ?></div>
                <div><strong style="color: #666; font-size: 12px;">Vendor</strong><br><?php echo sanitize($device['vendor'] ?: 'N/A'); ?></div>
                <div><strong style="color: #666; font-size: 12px;">Purchase Date</strong><br><?php echo formatDate($device['purchase_date']); ?></div>
                <div><strong style="color: #666; font-size: 12px;">Warranty Expiry</strong><br><?php echo formatDate($device['warranty_expiry']); ?></div>
                <div>
                    <strong style="color: #666; font-size: 12px;">Purchase Price</strong><br>
                    <?php echo $device['purchase_price'] ? number_format($device['purchase_price'], 2) . ' PHP' : 'N/A'; ?>
                </div>
            </div>
            <hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">
            <div>
                <strong style="color: #666; font-size: 12px;">Specifications</strong><br>
                <p><?php echo nl2br(sanitize($device['specifications'])); ?></p>
            </div>
            <?php if ($device['condition_notes']): ?>
            <div style="margin-top: 10px;">
                <strong style="color: #666; font-size: 12px;">Condition Notes</strong><br>
                <p><?php echo nl2br(sanitize($device['condition_notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Current Assignment ════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-user-check"></i> Current Assignment</h3>
        </div>
        <div class="card-body">
            <?php if ($currentAssignment): ?>
            <div style="text-align: center; padding: 20px;">
                <div style="width:80px;height:80px;background:var(--kbmc-red-light);color:var(--kbmc-red);border-radius:50%;
                            display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 15px;">
                    <i class="fas fa-user"></i>
                </div>
                <h4 style="margin-bottom: 5px;"><?php echo sanitize($currentAssignment['employee_name']); ?></h4>
                <p style="color:#666;font-size:13px;">
                    <?php echo sanitize($currentAssignment['department']); ?> — <?php echo sanitize($currentAssignment['position']); ?>
                </p>
                <p style="font-size:12px;color:#999;margin-top:10px;">
                    Assigned on: <?php echo formatDate($currentAssignment['assigned_date']); ?><br>
                    By: <?php echo sanitize($currentAssignment['assigned_by_name']); ?>
                </p>
                <p style="margin-top:10px;font-size:13px;">
                    <strong>Purpose:</strong> <?php echo sanitize($currentAssignment['purpose']); ?>
                </p>
                <p style="margin-top:5px;">
                    <span class="status-badge" style="
                        background:<?php echo $currentAssignment['accountability_form_signed'] ? '#27AE6020' : '#F39C1220'; ?>;
                        color:<?php echo $currentAssignment['accountability_form_signed'] ? '#27AE60' : '#F39C12'; ?>;
                        border:1px solid <?php echo $currentAssignment['accountability_form_signed'] ? '#27AE60' : '#F39C12'; ?>;">
                        AAR Form: <?php echo $currentAssignment['accountability_form_signed'] ? 'Signed' : 'Pending'; ?>
                    </span>
                </p>

                <!-- ── Return Buttons ── -->
                <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin-top:18px;">
                    <?php if (hasRole('admin') || hasRole('it_staff')): ?>
                    <!-- IT-initiated return (full form) -->
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>"
                       class="btn btn-warning btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-undo-alt"></i> Return Device
                    </a>
                    <?php endif; ?>

                    <?php
                    // Allow the assigned employee themselves to request a voluntary return
                    $isAssignedEmployee = (
                        hasRole('employee') &&
                        isset($_SESSION['user_id']) &&
                        (int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id']
                    );
                    if ($isAssignedEmployee): ?>
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>"
                       class="btn btn-outline btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;border-color:#E67E22;color:#E67E22;">
                        <i class="fas fa-hand-holding"></i> Voluntarily Return
                    </a>
                    <?php endif; ?>
                </div>

                <!-- ── Voluntary return info strip (visible to employee) ── -->
                <?php if ($isAssignedEmployee ?? false): ?>
                <div style="margin-top:14px;background:#FEF9E7;border:1px solid #F39C1240;border-radius:6px;padding:10px 14px;font-size:12px;color:#7D6608;text-align:left;">
                    <i class="fas fa-info-circle"></i>
                    Clicking <strong>"Voluntarily Return"</strong> will open a return form where IT staff will check
                    the device condition and log the return. You will need to be present during the inspection.
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-slash" style="font-size:40px;"></i>
                <h4>Not Assigned</h4>
                <p>This device is currently not assigned to anyone.</p>
                <?php if ($device['status'] == 'in_stock' && (hasRole('admin') || hasRole('it_staff'))): ?>
                <a href="deployments.php?action=assign&device=<?php echo $id; ?>"
                   class="btn btn-success btn-sm" style="margin-top:10px;">
                    <i class="fas fa-hand-holding"></i> Assign Now
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.grid-2 -->

<!-- ══ Inspection History ═══════════════════════════════════════════ -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-check"></i> Inspection History</h3>
        <?php if (hasRole('admin') || hasRole('it_staff')): ?>
        <a href="inspections.php?device=<?php echo $id; ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Inspection
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($inspections)): ?>
        <div class="empty-state"><i class="fas fa-clipboard-check"></i><h4>No inspections recorded</h4></div>
        <?php else: ?>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th><th>Inspector</th><th>Condition</th>
                        <th>Functionality</th><th>Result</th><th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $i): ?>
                    <tr>
                        <td><?php echo formatDate($i['inspection_date']); ?></td>
                        <td><?php echo sanitize($i['inspector_name']); ?></td>
                        <td><?php echo ucfirst($i['physical_condition']); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $i['functionality_status'])); ?></td>
                        <td>
                            <span class="status-badge" style="
                                background:<?php echo $i['result']=='passed' ? '#27AE6020' : '#E74C3C20'; ?>;
                                color:<?php echo $i['result']=='passed' ? '#27AE60' : '#E74C3C'; ?>;
                                border:1px solid <?php echo $i['result']=='passed' ? '#27AE60' : '#E74C3C'; ?>;">
                                <?php echo ucfirst($i['result']); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($i['notes']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Repair History ════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-tools"></i> Repair History</h3>
    </div>
    <div class="card-body">
        <?php if (empty($repairs)): ?>
        <div class="empty-state"><i class="fas fa-tools"></i><h4>No repair records</h4></div>
        <?php else: ?>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Date</th><th>Reported By</th><th>Issue</th><th>Status</th><th>Cost</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($repairs as $r): ?>
                    <tr>
                        <td><?php echo formatDate($r['created_at']); ?></td>
                        <td><?php echo sanitize($r['reporter_name']); ?></td>
                        <td><?php echo sanitize($r['issue_description']); ?></td>
                        <td>
                            <span class="status-badge" style="
                                background:<?php echo $r['repair_status']=='completed' ? '#27AE6020' : '#F39C1220'; ?>;
                                color:<?php echo $r['repair_status']=='completed' ? '#27AE60' : '#F39C12'; ?>;">
                                <?php echo ucwords(str_replace('_', ' ', $r['repair_status'])); ?>
                            </span>
                        </td>
                        <td><?php echo $r['repair_cost'] ? number_format($r['repair_cost'], 2) . ' PHP' : 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>