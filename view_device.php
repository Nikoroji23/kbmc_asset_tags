<?php
/**
 * KBMC Asset Management — View Device Details
 */

$pageTitle = 'Device Details';
require_once 'includes/header.php';
requireLogin();

// Ensure disposal tracking columns exist
ensureDeviceSchema();

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare(
    "SELECT d.*, dt.type_name, u.full_name as created_by_name, ud.full_name as disposed_by_name, ud.email as disposed_by_email
     FROM devices d
     JOIN device_types dt ON d.device_type_id = dt.id
     LEFT JOIN users u ON d.created_by = u.id
     LEFT JOIN users ud ON d.disposed_by = ud.id
     WHERE d.id = ?"
);
$stmt->execute([$id]);
$device = $stmt->fetch();

if (!$device) {
    setFlashMessage('error', 'Device not found.');
    header('Location: devices.php');
    exit();
}

$stmt = $pdo->prepare(
    "SELECT da.*, u.full_name as employee_name, u.department, u.position,
            ub.full_name as assigned_by_name
     FROM device_assignments da
     JOIN users u  ON da.employee_id = u.id
     LEFT JOIN users ub ON da.assigned_by = ub.id
     WHERE da.device_id = ?
     ORDER BY da.created_at DESC"
);
$stmt->execute([$id]);
$assignments = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT di.*, u.full_name as inspector_name
     FROM device_inspections di
     JOIN users u ON di.inspected_by = u.id
     WHERE di.device_id = ?
     ORDER BY di.inspection_date DESC"
);
$stmt->execute([$id]);
$inspections = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT dr.*, u.full_name as reporter_name
     FROM device_repairs dr
     JOIN users u ON dr.reported_by = u.id
     WHERE dr.device_id = ?
     ORDER BY dr.created_at DESC"
);
$stmt->execute([$id]);
$repairs = $stmt->fetchAll();

// Get repair assignments if column exists
$repairAssignments = [];
if (columnExists('device_repairs', 'assigned_to')) {
    $stmt = $pdo->prepare(
        "SELECT dr.id, a.full_name as assigned_to_name, a.email as assigned_to_email,
                cb.full_name as completed_by_name
         FROM device_repairs dr
         LEFT JOIN users a ON dr.assigned_to = a.id
         LEFT JOIN users cb ON dr.completed_by = cb.id
         WHERE dr.device_id = ?"
    );
    $stmt->execute([$id]);
    $assignments_data = $stmt->fetchAll();
    foreach ($assignments_data as $assn) {
        $repairAssignments[$assn['id']] = $assn;
    }
}

$currentAssignment = null;
foreach ($assignments as $a) {
    if ($a['status'] == 'active') { $currentAssignment = $a; break; }
}

$isITOrAdmin = hasRole('admin') || hasRole('it_staff');
$isAssignedEmployee = (
    hasRole('employee') &&
    $currentAssignment &&
    isset($_SESSION['user_id']) &&
    (int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id']
);
?>

<div class="page-header">
    <h1><i class="fas fa-laptop"></i> Device Details</h1>
    <div style="display:flex;gap:10px;">
        <a href="devices.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
        <?php if ($isITOrAdmin): ?>
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
        <div class="card-body" style="font-size:14px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div>
                    <strong style="color:#666;font-size:12px;">Asset Tag</strong><br>
                    <span style="font-size:18px;font-weight:700;color:var(--kbmc-red);"><?php echo sanitize($device['asset_tag']); ?></span>
                </div>
                <div><strong style="color:#666;font-size:12px;">Status</strong><br><?php echo getStatusBadge($device['status']); ?></div>
                <div><strong style="color:#666;font-size:12px;">Device Type</strong><br><?php echo sanitize($device['type_name']); ?></div>
                <div><strong style="color:#666;font-size:12px;">PC Name</strong><br><?php echo sanitize($device['pc_name'] ?: 'N/A'); ?></div>
                <div><strong style="color:#666;font-size:12px;">IP Address</strong><br><?php echo sanitize($device['ip_address'] ?: 'N/A'); ?></div>
                <div><strong style="color:#666;font-size:12px;">Location</strong><br><?php echo sanitize($device['location']); ?></div>
                <div><strong style="color:#666;font-size:12px;">Vendor</strong><br><?php echo sanitize($device['vendor'] ?: 'N/A'); ?></div>
                <div><strong style="color:#666;font-size:12px;">Purchase Date</strong><br><?php echo formatDate($device['purchase_date']); ?></div>
                <div><strong style="color:#666;font-size:12px;">Warranty Expiry</strong><br><?php echo formatDate($device['warranty_expiry']); ?></div>
                <div>
                    <strong style="color:#666;font-size:12px;">Purchase Price</strong><br>
                    <?php echo $device['purchase_price'] ? number_format($device['purchase_price'],2).' PHP' : 'N/A'; ?>
                </div>
                <?php if ($device['status'] == 'disposed' && isset($device['disposed_by_name'])): ?>
                <div><strong style="color:#666;font-size:12px;">Disposed By</strong><br><?php echo sanitize($device['disposed_by_name']); ?></div>
                <div><strong style="color:#666;font-size:12px;">Disposal Date</strong><br><?php echo $device['disposed_at'] ? formatDate($device['disposed_at']) : 'N/A'; ?></div>
                <?php endif; ?>
            </div>
            <hr style="margin:15px 0;border:none;border-top:1px solid #eee;">
            <div>
                <strong style="color:#666;font-size:12px;">Specifications</strong><br>
                <p><?php echo nl2br(sanitize($device['specifications'] ?? 'N/A')); ?></p>
            </div>
            <?php if (!empty($device['condition_notes'])): ?>
            <div style="margin-top:10px;">
                <strong style="color:#666;font-size:12px;">Condition Notes</strong><br>
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
            <div style="text-align:center;padding:20px;">
                <div style="width:80px;height:80px;background:var(--kbmc-red-light);color:var(--kbmc-red);border-radius:50%;
                            display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 15px;">
                    <i class="fas fa-user"></i>
                </div>
                <h4 style="margin-bottom:5px;"><?php echo sanitize($currentAssignment['employee_name']); ?></h4>
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

                <!-- ── Action buttons ── -->
                <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin-top:20px;">

                    <?php if ($isITOrAdmin): ?>
                    <!-- IT-initiated return → full return form -->
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>"
                       class="btn btn-warning btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-undo-alt"></i> Return Device
                    </a>

                    <!-- Single-device IT Clearance form shortcut -->
                    <a href="it_clearance.php?user_id=<?php echo $currentAssignment['employee_id']; ?>&device_id=<?php echo $id; ?>"
                       class="btn btn-outline btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;border-color:var(--kbmc-red);color:var(--kbmc-red);">
                        <i class="fas fa-file-check"></i> IT Clearance (1 Device)
                    </a>
                    <?php endif; ?>

                    <?php if ($isAssignedEmployee): ?>
                    <!-- Employee-initiated voluntary return -->
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>&mode=voluntary"
                       class="btn btn-outline btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;border-color:#E67E22;color:#E67E22;">
                        <i class="fas fa-hand-holding"></i> Voluntarily Return
                    </a>
                    <?php endif; ?>
                </div>

                <?php if ($isAssignedEmployee): ?>
                <div style="margin-top:12px;background:#FEF9E7;border:1px solid #F39C1240;border-radius:6px;
                             padding:10px 14px;font-size:12px;color:#7D6608;text-align:left;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Voluntary Return:</strong> Clicking the button opens a return form.
                    An IT staff member must be present to inspect the device and co-sign.
                    A printable <strong>IT Clearance Form</strong> will be generated after.
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-slash" style="font-size:40px;"></i>
                <h4>Not Assigned</h4>
                <p>This device is currently not assigned to anyone.</p>
                <?php if ($device['status'] == 'in_stock' && $isITOrAdmin): ?>
                <a href="deployments.php?action=assign&device=<?php echo $id; ?>"
                   class="btn btn-success btn-sm" style="margin-top:10px;">
                    <i class="fas fa-hand-holding"></i> Assign Now
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ══ Inspection History ═════════════════════════════════════════════════════→ -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-check"></i> Inspection History</h3>
        <?php if ($isITOrAdmin): ?>
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
                    <tr><th>Date</th><th>Inspector</th><th>Condition</th><th>Functionality</th><th>Result</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $i): ?>
                    <tr>
                        <td><?php echo formatDate($i['inspection_date']); ?></td>
                        <td><?php echo sanitize($i['inspector_name']); ?></td>
                        <td><?php echo ucfirst($i['physical_condition']); ?></td>
                        <td><?php echo ucfirst(str_replace('_',' ',$i['functionality_status'])); ?></td>
                        <td>
                            <span class="status-badge" style="
                                background:<?php echo $i['result']=='passed'?'#27AE6020':'#E74C3C20'; ?>;
                                color:<?php echo $i['result']=='passed'?'#27AE60':'#E74C3C'; ?>;
                                border:1px solid <?php echo $i['result']=='passed'?'#27AE60':'#E74C3C'; ?>;">
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

<!-- ══ Repair History ════════════════════════════════════════════════════════ -->
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
                    <tr>
                        <th>Date</th>
                        <th>Reported By</th>
                        <th>Issue</th>
                        <?php if (!empty($repairAssignments)): ?><th>Assigned To</th><?php endif; ?>
                        <th>Status</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repairs as $r): 
                        $assignmentInfo = $repairAssignments[$r['id']] ?? null;
                    ?>
                    <tr>
                        <td><?php echo formatDate($r['created_at']); ?></td>
                        <td><?php echo sanitize($r['reporter_name']); ?></td>
                        <td><?php echo sanitize($r['issue_description']); ?></td>
                        <?php if (!empty($repairAssignments)): ?>
                        <td>
                            <?php if (!empty($assignmentInfo['assigned_to_name'])): ?>
                                <div style="color:#16a34a;font-weight:600;"><?php echo sanitize($assignmentInfo['assigned_to_name']); ?></div>
                                <?php if (!empty($assignmentInfo['completed_by_name'])): ?>
                                    <div style="font-size:11px;color:#6b7280;">Completed by: <?php echo sanitize($assignmentInfo['completed_by_name']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="color:#9ca3af;font-style:italic;">—</div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="status-badge" style="
                                background:<?php echo $r['repair_status']=='completed'?'#27AE6020':'#F39C1220'; ?>;
                                color:<?php echo $r['repair_status']=='completed'?'#27AE60':'#F39C12'; ?>;">
                                <?php echo ucwords(str_replace('_',' ',$r['repair_status'])); ?>
                            </span>
                        </td>
                        <td><?php echo $r['repair_cost'] ? number_format($r['repair_cost'],2).' PHP' : 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>