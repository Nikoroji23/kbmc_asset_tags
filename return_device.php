<?php
/**
 * KBMC Asset Management - View Device Details & Change Request Form Handler
 */

$pageTitle = 'Device Details';
require_once 'includes/header.php';
requireLogin();

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$assignmentStmt = $pdo->prepare(
    "SELECT da.*, u.full_name as employee_name, u.department, u.position, u.email,
            ub.full_name as assigned_by_name
     FROM device_assignments da
     JOIN users u ON da.employee_id = u.id
     LEFT JOIN users ub ON da.assigned_by = ub.id
     WHERE da.id = ? AND da.status = 'active'"
);
$assignmentStmt->execute([$assignmentId]);
$currentAssignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentAssignment) {
    setFlashMessage('error', 'Invalid request or active assignment not found.');
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

$showChangeRequestForm = false;

if (isset($_GET['mode']) && $_GET['mode'] === 'voluntary' && $currentAssignment && hasRole('employee') && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id']) {
    $showChangeRequestForm = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_change_request') {
    $changeType = trim($_POST['change_type'] ?? '');
    $changeDetails = trim($_POST['change_details'] ?? '');

    if ($changeType === '' || $changeDetails === '') {
        setFlashMessage('error', 'Please fill in all change request fields.');
        header('Location: return_device.php?id=' . urlencode($currentAssignment['id']) . '&mode=voluntary');
        exit();
    }

    $title = 'Device Change Requested';
    $message = "Employee {$currentAssignment['employee_name']} submitted a change request form for device {$device['asset_tag']}.";
    $message .= " Type: {$changeType}. Details: {$changeDetails}";

    notifyITStaff('user_clearance_required', $title, $message, $currentAssignment['id']);

    addNotificationIfNotExists(
        $_SESSION['user_id'],
        'voluntary_return_requested',
        'Change Request Form Submitted',
        "Your change request for {$device['asset_tag']} has been sent to IT for clearance.",
        $device['id']
    );

    setFlashMessage('success', 'Your change request form has been sent to IT. IT staff will contact you soon.');
    redirect('view_device.php?id=' . urlencode($device['id']));
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

<?php if (!empty($showChangeRequestForm)): ?>
<div style="max-width:1000px;margin:0 auto;padding:0 12px;">
    <div style="background:#fff;border:1px solid #d1d5db;border-radius:16px;overflow:hidden;margin-bottom:24px;font-family:Arial,Helvetica,sans-serif;color:#172134;">
        <div style="padding:32px 30px 24px;border-bottom:1px solid #e5e7eb;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;">
                <img src="assets/images/kbmc_logo_flat.png" alt="KBMC Logo" style="height:56px; width:auto; object-fit:contain;" />
                <div>
                    <div style="font-size:10px;font-weight:800;letter-spacing:0.05em;color:#FF0000;">Kitchen Beauty Marketing Corporation</div>
                    <div style="font-size:13px;color:#475569;margin-top:8px;line-height:1.6;">Camangyanan Road, Sta. Rosa 2, Marilao 3019, Bulacan, Philippines</div>
                    <div style="font-size:13px;color:#475569;margin-top:3px;">(632) 8242 1731</div>
                </div>
                <div style="font-size:28px;font-weight:800;color:#111827;letter-spacing:0.08em;">CHANGE REQUEST FORM</div>
                <div style="margin-top:6px;font-size:13px;color:#111827;">Change Request No.: <strong style="display:inline-block;width:70px;text-align:center;"><?php echo str_pad(max(0, intval($currentAssignment['id']) - 1), 4, '0', STR_PAD_LEFT); ?></strong></div>
            </div>
        </div>

        <form id="changeRequestForm" method="POST" style="padding:24px 30px;">
            <input type="hidden" id="changeAssignmentId" name="assignment_id" value="<?php echo intval($currentAssignment['id']); ?>">
            <input type="hidden" id="changeDeviceId" name="device_id" value="<?php echo intval($device['id']); ?>">
            <input type="hidden" id="changeDeviceTag" name="device_tag" value="<?php echo htmlspecialchars($device['asset_tag']); ?>">
            <input type="hidden" id="changeRequestorName" name="requestor_name" value="<?php echo htmlspecialchars($currentAssignment['employee_name']); ?>">
            <input type="hidden" id="changeEmployeeId" name="employee_id" value="<?php echo intval($currentAssignment['employee_id']); ?>">
            <input type="hidden" id="changeDepartment" name="department" value="<?php echo htmlspecialchars($currentAssignment['department']); ?>">

            <div style="margin-bottom:18px;padding:12px 16px;background:#111827;color:#fff;font-size:13px;font-weight:700;border-radius:6px;letter-spacing:0.05em;">GENERAL INFORMATION (TO BE ACCOMPLISHED BY CLIENT)</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
                <tr>
                    <td style="width:17%;padding:10px 12px;border:1px solid #111827;font-weight:700;background:#f8fafc;">Requestor Name:</td>
                    <td style="padding:10px 12px;border:1px solid #111827;"><?php echo htmlspecialchars($currentAssignment['employee_name']); ?></td>
                    <td style="padding:10px 12px;border:1px solid #111827;font-weight:700;background:#f8fafc;">Department:</td>
                    <td style="padding:10px 12px;border:1px solid #111827;"><?php echo htmlspecialchars($currentAssignment['department']); ?></td>
                </tr>
                <tr>
                    <td style="padding:10px 12px;border:1px solid #111827;font-weight:700;background:#f8fafc;">E-mail Address:</td>
                    <td style="padding:10px 12px;border:1px solid #111827;"><?php echo htmlspecialchars($currentAssignment['email'] ?? ''); ?></td>
                    <td style="padding:10px 12px;border:1px solid #111827;font-weight:700;background:#f8fafc;">Date of Request:</td>
                    <td style="padding:10px 12px;border:1px solid #111827;"><?php echo date('Y-m-d'); ?></td>
                </tr>
            </table>

            <div style="margin-bottom:18px;padding:12px 16px;background:#111827;color:#fff;font-size:13px;font-weight:700;border-radius:6px;letter-spacing:0.05em;">SECTION 1: CHANGE REQUEST (TO BE ACCOMPLISHED BY CLIENT)</div>
            <div style="font-size:13px;color:#111827;margin-bottom:12px;font-weight:700;">1.1 Type of Change: Please select one (1)</div>
            <div style="display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:10px;margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;">
                    <input type="radio" name="change_type" value="Hardware" style="width:16px;height:16px;"> Hardware
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;">
                    <input type="radio" name="change_type" value="Application / Software" style="width:16px;height:16px;"> Application / Software
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;">
                    <input type="radio" name="change_type" value="Email" style="width:16px;height:16px;"> Email
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;">
                    <input type="radio" name="change_type" value="Network" style="width:16px;height:16px;"> Network
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;">
                    <input type="radio" name="change_type" value="Operating System" style="width:16px;height:16px;"> Operating System
                </label>
            </div>
            <div style="margin-bottom:8px;font-size:13px;font-weight:700;color:#111827;">Please specify the data/setup changes:</div>
            <textarea id="changeDetails" name="change_details" required style="width:100%;min-height:210px;padding:14px;border:1px solid #111827;border-radius:6px;font-size:13px;line-height:1.6;color:#111827;"><?php echo htmlspecialchars($_POST['change_details'] ?? ''); ?></textarea>

            <input type="hidden" name="action" value="submit_change_request">

            <div style="margin-top:22px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center;">
                <div style="font-size:12px;color:#475569;">Note: Provide supporting documents/references (if applicable)</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="view_device.php?id=<?php echo intval($device['id']); ?>" class="btn btn-outline" style="padding:11px 22px;border-radius:8px;">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="padding:11px 24px;border-radius:8px;">Submit Request</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="grid-2">

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
                <p><?php echo nl2br(sanitize($device['specifications'] ?? '')); ?></p>
            </div>
            <?php if ($device['condition_notes']): ?>
            <div style="margin-top: 10px;">
                <strong style="color: #666; font-size: 12px;">Condition Notes</strong><br>
                <p><?php echo nl2br(sanitize($device['condition_notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

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

                <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin-top:18px;">
                    <?php if (hasRole('admin') || hasRole('it_staff')): ?>
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>"
                       class="btn btn-warning btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-undo-alt"></i> Return Device
                    </a>
                    <?php endif; ?>

                    <?php
                    // Check if self-assigned worker matches active profile context session
                    $isAssignedEmployee = (
                        hasRole('employee') &&
                        isset($_SESSION['user_id']) &&
                        (int)$_SESSION['user_id'] === (int)$currentAssignment['employee_id']
                    );
                    if ($isAssignedEmployee): ?>
                    <a href="return_device.php?id=<?php echo $currentAssignment['id']; ?>&mode=voluntary"
                       class="btn btn-outline btn-sm"
                       style="display:inline-flex;align-items:center;gap:6px;border-color:#E67E22;color:#E67E22;">
                        <i class="fas fa-exchange-alt"></i> Change Request Form
                    </a>
                    <?php endif; ?>
                </div>

                <?php if ($isAssignedEmployee ?? false): ?>
                <div style="margin-top:14px;background:#FEF9E7;border:1px solid #F39C1240;border-radius:6px;padding:10px 14px;font-size:12px;color:#7D6608;text-align:left;">
                    <i class="fas fa-info-circle"></i>
                    Clicking <strong>"Change Request Form"</strong> will open a change request form where IT staff will check
                    the device condition and log the details. You will need to be present during the inspection.
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

</div><div class="card">
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

<script>
(function() {
    var form = document.getElementById('changeRequestForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var assignmentId = document.getElementById('changeAssignmentId').value;
        var selectedTypeInput = document.querySelector('input[name="change_type"]:checked');
        var changeType = selectedTypeInput ? selectedTypeInput.value : '';
        var changeDetails = document.getElementById('changeDetails').value.trim();
        var deviceTag = document.getElementById('changeDeviceTag').value;
        var deviceId = document.getElementById('changeDeviceId').value;
        var requestorName = document.getElementById('changeRequestorName').value;
        var employeeId = document.getElementById('changeEmployeeId').value;
        var department = document.getElementById('changeDepartment').value;
        var requestDate = new Date().toLocaleString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });

        if (!changeType) {
            alert('Please select the type of change request.');
            return;
        }
        if (!changeDetails) {
            alert('Please describe the requested change.');
            return;
        }

        if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
            alert('PDF generation is not available in this browser. Please contact IT to submit this request manually.');
            return;
        }

        var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
        var margin = 40;
        var pageWidth = doc.internal.pageSize.getWidth();
        var maxLineWidth = pageWidth - margin * 2;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(18);
        doc.text('CHANGE REQUEST FORM', margin, 50);

        doc.setFontSize(10);
        doc.setFont('helvetica', 'normal');
        doc.text('KITCHEN BEAUTY MARKETING CORPORATION', margin, 68);
        doc.text('Camangyanan Road, Sta. Rosa 2, Marilao 3019, Bulacan, Philippines', margin, 80);

        doc.setDrawColor(34, 85, 170);
        doc.setLineWidth(1.8);
        doc.line(margin, 94, pageWidth - margin, 94);

        var y = 116;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text('GENERAL INFORMATION', margin, y);

        y += 18;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(11);
        doc.text('Requestor Name:', margin, y);
        doc.text(requestorName, margin + 150, y);
        y += 18;
        doc.text('Employee ID:', margin, y);
        doc.text(employeeId, margin + 150, y);
        y += 18;
        doc.text('Department:', margin, y);
        doc.text(department, margin + 150, y);
        y += 18;
        doc.text('Request Date:', margin, y);
        doc.text(requestDate, margin + 150, y);
        y += 18;
        doc.text('Device:', margin, y);
        doc.text(deviceTag, margin + 150, y);

        y += 28;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text('CHANGE REQUEST', margin, y);

        y += 18;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(11);
        doc.text('Type of Change:', margin, y);

        // Render checkbox list for Type of Change with selected item checked (draw square boxes and X for selected)
        var changeOptions = ['Hardware', 'Application / Software', 'Email', 'Network', 'Operating System'];
        var checkboxX = margin + 150;
        var checkboxY = y;
        var lineHeight = 18;
        var boxSize = 10;
        doc.setLineWidth(0.8);
        for (var i = 0; i < changeOptions.length; i++) {
            var opt = changeOptions[i];
            // draw box
            doc.rect(checkboxX, checkboxY - 9, boxSize, boxSize);
            if (opt === changeType) {
                // draw an X inside the box for checked state
                doc.setFontSize(12);
                doc.text('X', checkboxX + 2, checkboxY - 1);
                doc.setFontSize(11);
            }
            // draw label to the right of the box
            doc.text(opt, checkboxX + boxSize + 8, checkboxY);
            checkboxY += lineHeight;
        }

        y = checkboxY + 4;
        doc.setFont('helvetica', 'bold');
        doc.text('Request Details:', margin, y);

        y += 14;
        doc.setFont('helvetica', 'normal');
        // Ensure details are split to the available width and rendered as multiline
        var detailsMaxWidth = maxLineWidth - 16;
        var detailsLines = doc.splitTextToSize(changeDetails || '(No details provided)', detailsMaxWidth);
        for (var j = 0; j < detailsLines.length; j++) {
            doc.text(detailsLines[j], margin + 8, y);
            y += 14;
        }
        y += 8;

        doc.setFontSize(10);
        doc.setTextColor(110);
        doc.text('This form is a request only. IT staff will review the details and contact you to schedule next steps.', margin, y);

        var pdfDataUri = doc.output('datauristring');
        var base64Pdf = pdfDataUri.split('base64,')[1];
        var filename = 'change_request_' + assignmentId + '_' + Math.floor(Date.now() / 1000) + '.pdf';

        fetch('api_change_request_form.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                assignment_id: assignmentId,
                change_type: changeType,
                change_details: changeDetails,
                pdf_base64: base64Pdf,
                pdf_filename: filename
            })
        })
        .then(function(response) {
            return response.text().then(function(text) {
                var data;
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (parseError) {
                    throw new Error('Invalid server response: ' + text);
                }
                if (!response.ok) {
                    throw new Error(data.message || 'Server error: ' + response.status);
                }
                return data;
            });
        })
        .then(function(data) {
            if (data.success) {
                alert('Your change request form has been submitted successfully. IT staff will contact you to review your request.');
                window.location = 'view_device.php?id=' + encodeURIComponent(deviceId);
            } else {
                alert(data.message || 'Unable to submit your change request at this time. Please try again later.');
            }
        })
        .catch(function(error) {
            console.error('Change request submission error:', error);
            alert('An error occurred while sending your request: ' + error.message);
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>