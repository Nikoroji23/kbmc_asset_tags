<?php
/**
 * KBMC Asset Management - Deployments
 */
$pageTitle = 'Deployments';
require_once 'includes/header.php';
requireITStaffOnly();

$action = $_GET['action'] ?? 'list';

// Ensure deployment status consistency (fix orphaned deployed devices)
fixDeploymentStatusConsistency();

// Handle device return
if (isset($_GET['action']) && $_GET['action'] == 'return' && isset($_GET['id'])) {
    $assignmentId = $_GET['id'];
    $stmt = $pdo->prepare("SELECT da.*, d.asset_tag FROM device_assignments da JOIN devices d ON da.device_id = d.id WHERE da.id = ?");
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch();

    if ($assignment) {
        $pdo->prepare("UPDATE device_assignments SET status = 'returned', returned_date = CURDATE() WHERE id = ?")->execute([$assignmentId]);
        $pdo->prepare("UPDATE devices SET status = 'in_stock', location = 'IT Stock Room' WHERE id = ?")->execute([$assignment['device_id']]);

        // Notify employee
        addNotificationIfNotExists($assignment['employee_id'], 'device_returned', 'Device Returned', "Your assigned device {$assignment['asset_tag']} has been returned.", $assignment['device_id']);
        
        // Send email notification to employee
        $empStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $empStmt->execute([$assignment['employee_id']]);
        $employee = $empStmt->fetch();
        
        if ($employee && isEmailConfigured()) {
            $emailBody = emailTemplate(
                'Device Return Processed',
                "<p>Hello <strong>" . sanitize($employee['full_name']) . "</strong>,</p>
                <p>Your assigned device has been returned and processed by the IT department.</p>
                <div style='background: #c8e6c9; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #27ae60;'>
                    <p><strong>Device Details:</strong></p>
                    <p><i class='fas fa-laptop'></i> <strong>Asset Tag:</strong> " . sanitize($assignment['asset_tag']) . "</p>
                    <p><i class='fas fa-check'></i> <strong>Status:</strong> Returned to Stock</p>
                    <p><i class='fas fa-calendar'></i> <strong>Return Date:</strong> " . date('F d, Y') . "</p>
                </div>
                <p>Thank you for taking care of this device. If you have any questions, please contact the IT department.</p>",
                'View Details',
                'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/deployments.php'
            );
            sendEmail($employee['email'], 'Device Return Confirmation - ' . sanitize($assignment['asset_tag']), $emailBody);
        }
        
        // Notify IT staff
        notifyITStaff('device_returned', 'Device Returned', "Device {$assignment['asset_tag']} has been returned to stock.", $assignment['device_id']);

        logAudit($_SESSION['user_id'], 'Return', 'device_assignments', $assignmentId);
        setFlashMessage('success', 'Device returned successfully.');
    }
    header('Location: deployments.php');
    exit();
}

// Handle new assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_device'])) {
    $device_ids = $_POST['device_id'] ?? [];
    $employee_id = $_POST['employee_id'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');

    if (!is_array($device_ids)) {
        $device_ids = [$device_ids];
    }
    $device_ids = array_filter(array_map('intval', $device_ids));
    $employee_id = intval($employee_id);

    if (!empty($device_ids) && $employee_id) {
        try {
            $pdo->beginTransaction();

            $deviceStmt = $pdo->prepare("SELECT id, status, asset_tag FROM devices WHERE id = ? FOR UPDATE");
            $assignStmt = $pdo->prepare("INSERT INTO device_assignments (device_id, employee_id, assigned_by, assigned_date, purpose, accountability_form_signed, status) VALUES (?, ?, ?, CURDATE(), ?, 1, 'active')");
            $updateDeviceStmt = $pdo->prepare("UPDATE devices SET status = 'deployed' WHERE id = ?");
            $empStmt = $pdo->prepare("SELECT email, full_name, department FROM users WHERE id = ?");
            $empStmt->execute([$employee_id]);
            $employee = $empStmt->fetch();

            if (!$employee) {
                throw new Exception('Selected employee was not found.');
            }

            $assignedDevices = [];
            foreach ($device_ids as $device_id) {
                $deviceStmt->execute([$device_id]);
                $deviceCheck = $deviceStmt->fetch();
                if (!$deviceCheck || $deviceCheck['status'] !== 'in_stock') {
                    throw new Exception('Device ' . ($deviceCheck['asset_tag'] ?? $device_id) . ' is not available for assignment.');
                }

                $assignStmt->execute([$device_id, $employee_id, $_SESSION['user_id'], $purpose]);
                $updateDeviceStmt->execute([$device_id]);
                $assignedDevices[] = $deviceCheck;
            }

            $pdo->commit();

            // Create a consolidated system notification for the employee (no immediate email)
            $deviceListPlain = [];
            foreach ($assignedDevices as $device) {
                $deviceListPlain[] = $device['asset_tag'];
            }
            $deviceListText = implode(', ', $deviceListPlain);
            // Insert a single system notification (no email) for the employee
            addSystemNotificationOnly($employee_id, 'device_deployed_bulk', 'Devices Deployed', "The following device(s) have been assigned to you: {$deviceListText}");

            // Notify IT staff with a single consolidated notification/email
            $itMessage = "Devices ({$deviceListText}) have been assigned to {$employee['full_name']} ({$employee['department']}).";
            notifyITStaff('device_deployed_bulk', 'Devices Deployed', $itMessage, 0);

            if ($employee && isEmailConfigured()) {
                $deviceList = "<ul>";
                foreach ($assignedDevices as $device) {
                    $deviceList .= "<li><strong>Asset Tag:</strong> " . sanitize($device['asset_tag']) . "</li>";
                }
                $deviceList .= "</ul>";

                $emailBody = emailTemplate(
                    'Device Assigned to You',
                    "<p>Hello <strong>" . sanitize($employee['full_name']) . "</strong>,</p>
                    <p>The following device(s) have been assigned to you:</p>
                    <div style='background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                        <p><strong>Assigned Device(s):</strong></p>" .
                        $deviceList .
                        (!empty($purpose) ? "<p><i class='fas fa-align-left'></i> <strong>Purpose:</strong> " . sanitize($purpose) . "</p>" : '') .
                    "</div>
                    <p>Please ensure you follow company device policies and keep these devices secure. If you have any questions, contact the IT department.</p>",
                    'View Deployment',
                    'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/deployments.php'
                );
                sendEmail($employee['email'], 'Device Assignment Notification', $emailBody);
            }

            setFlashMessage('success', 'Device assignment completed successfully.');
            header('Location: deployments.php');
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlashMessage('error', 'Error: ' . $e->getMessage());
        }
    } else {
        setFlashMessage('error', 'Please select at least one device and an employee.');
    }
}

$preselectedDevice = $_GET['device'] ?? '';

// Get available devices
$availableDevices = $pdo->query("SELECT d.id, d.asset_tag, COALESCE(dt.type_name, 'N/A') AS type_name FROM devices d LEFT JOIN device_types dt ON d.device_type_id = dt.id WHERE d.status = 'in_stock' ORDER BY d.asset_tag")->fetchAll();

// Get active employees
$employees = $pdo->query("SELECT id, full_name, CONCAT(department, ' - ', position) as dept FROM users WHERE status = 'active' AND role = 'employee' ORDER BY full_name")->fetchAll();

// Get all assignments
$stmt = $pdo->query("SELECT da.*, d.asset_tag, d.asset_tag as device_name, dt.type_name, u.full_name as employee_name, u.department, ub.full_name as assigned_by_name 
    FROM device_assignments da 
    JOIN devices d ON da.device_id = d.id 
    JOIN device_types dt ON d.device_type_id = dt.id 
    JOIN users u ON da.employee_id = u.id 
    LEFT JOIN users ub ON da.assigned_by = ub.id 
    ORDER BY da.created_at DESC");
$assignments = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-hand-holding"></i> Device Deployments</h1>
    <div style="display: flex; gap: 10px;">
        <button class="btn btn-outline" onclick="exportDeploymentsCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button class="btn btn-outline" onclick="exportDeploymentsPDF()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <?php if ($action == 'assign'): ?>
        <a href="deployments.php" class="btn btn-outline">View List</a>
        <?php else: ?>
        <a href="deployments.php?action=assign" class="btn btn-primary"><i class="fas fa-plus"></i> Assign Device</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action == 'assign'): ?>
<div class="card">
    <div class="card-header"><h3>Assign Device to Employee</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Select Device(s) <span class="required">*</span></label>
                    <?php if (empty($availableDevices)): ?>
                        <div class="alert alert-warning">No devices are currently available for deployment.</div>
                    <?php else: ?>
                        <div class="device-selection-toolbar" style="display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px;">
                            <div style="flex:1; min-width:260px; display:flex; flex-direction:column; gap:8px;">
                                <input id="deviceSearch" class="form-control" placeholder="Filter devices by asset tag or type" style="width:100%;" autocomplete="off">
                                <select id="deviceSelect" class="form-control" size="6" style="width:100%; min-height:160px;">
                                    <?php foreach ($availableDevices as $dev): ?>
                                    <?php $displayTag = $dev['asset_tag'] ?: 'N/A'; ?>
                                    <option value="<?php echo sanitize($dev['id']); ?>"><?php echo sanitize($displayTag . ' - ' . $dev['type_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:8px; justify-content:flex-start; position:relative; z-index:2;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="clearDeviceSearch()" style="display: inline-flex; align-items: center; justify-content: center; text-align: center;">Reset</button>
                                <button id="addDeviceBtn" type="button" class="btn btn-sm btn-primary" style="position:relative; z-index:2; pointer-events:auto; display: inline-flex; align-items: center; justify-content: center; text-align: center;">Add</button>
                                <span style="font-size:0.95rem; color:#555;">Selected: <span id="selectedDeviceCount">0</span></span>
                            </div>
                        </div>
                        <div id="selectedDevicesContainer" style="margin-bottom:16px; display:none;">
                            <div style="margin-bottom:10px; font-weight:600; color:#333;">Selected Devices</div>
                            <table class="data-table" style="width:100%; margin-bottom:8px; border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th style="width:1px;">#</th>
                                        <th>Asset Tag</th>
                                        <th>Device Type</th>
                                        <th style="width:1px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="selectedDevicesBody"></tbody>
                            </table>
                            <div id="selectedDevicesEmpty" style="color:#555; font-size:0.95rem;">No devices selected yet. Search and select a device from the dropdown.</div>
                        </div>
                        <small class="form-text text-muted">Search and select a device from the dropdown to add it to the table.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Select Employee <span class="required">*</span></label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">Choose an employee</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"><?php echo sanitize($emp['full_name'] . ' (' . $emp['dept'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Purpose / Reason</label>
                    <textarea name="purpose" class="form-control" placeholder="Purpose of assignment"></textarea>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <button type="submit" name="assign_device" class="btn btn-primary btn-lg"><i class="fas fa-hand-holding"></i> Assign Device</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>All Deployments</h3></div>
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="deployTable">
                <thead>
                    <tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Department</th><th>Assigned Date</th><th>Returned Date</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                    <tr><td colspan="8" class="empty-state" style="padding: 40px;"><i class="fas fa-hand-holding" style="font-size: 40px; color: #ddd;"></i><h4 style="margin-top: 10px;">No deployments yet</h4></td></tr>
                    <?php else: ?>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><strong><?php echo sanitize($a['asset_tag']); ?></strong></td>
                        <td><?php echo sanitize($a['type_name']); ?></td>
                        <td><?php echo sanitize($a['employee_name']); ?></td>
                        <td><?php echo sanitize($a['department']); ?></td>
                        <td><?php echo formatDate($a['assigned_date']); ?></td>
                        <td><?php echo $a['returned_date'] ? formatDate($a['returned_date']) : '<span style="color: #999;">-</span>'; ?></td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $a['status'] == 'active' ? '#3498DB20' : '#27AE6020'; ?>; color: <?php echo $a['status'] == 'active' ? '#3498DB' : '#27AE60'; ?>; border: 1px solid <?php echo $a['status'] == 'active' ? '#3498DB' : '#27AE60'; ?>;"><?php echo ucfirst($a['status']); ?></span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view_device.php?id=<?php echo $a['device_id']; ?>" class="action-btn view" title="View Device"><i class="fas fa-eye"></i></a>
                                <?php if ($a['status'] == 'active'): ?>
                                <a href="deployments.php?action=return&id=<?php echo $a['id']; ?>" class="action-btn delete" title="Return Device" onclick="return confirm('Return this device?')"><i class="fas fa-undo"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function exportDeploymentsCSV() {
    const rows = [];
    document.querySelectorAll('#deployTable tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) {
            rows.push([cells[0]?.textContent.trim(), cells[1]?.textContent.trim(), cells[2]?.textContent.trim(), cells[3]?.textContent.trim(), cells[4]?.textContent.trim(), cells[5]?.textContent.trim(), cells[6]?.textContent.trim()]);
        }
    });
    exportToCSV('deployments_<?php echo date('Y-m-d'); ?>.csv', ['Asset Tag', 'Device', 'Employee', 'Department', 'Assigned', 'Returned', 'Status'], rows);
}
function exportDeploymentsPDF() {
    const rows = [];
    document.querySelectorAll('#deployTable tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) {
            rows.push([cells[0]?.textContent.trim(), cells[1]?.textContent.trim(), cells[2]?.textContent.trim(), cells[3]?.textContent.trim(), cells[4]?.textContent.trim(), cells[5]?.textContent.trim(), cells[6]?.textContent.trim()]);
        }
    });
    exportToPDF('Device Deployment Report', ['Asset Tag', 'Device', 'Employee', 'Department', 'Assigned', 'Returned', 'Status'], rows, 'deployments_<?php echo date('Y-m-d'); ?>.pdf');
}

const availableDevices = <?php echo json_encode($availableDevices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const preselectedDeviceId = <?php echo json_encode($preselectedDevice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let selectedDeviceIds = [];

function handleDeviceSearchKey(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        addDeviceFromSearch();
    }
}

function filterDeviceOptions() {
    const searchInput = document.getElementById('deviceSearch');
    const select = document.getElementById('deviceSelect');
    if (!searchInput || !select) return;

    const query = searchInput.value.trim().toLowerCase();
    for (const option of select.options) {
        const text = option.text.toLowerCase();
        option.hidden = query && !text.includes(query);
    }

    const firstVisible = Array.from(select.options).find(option => !option.hidden);
    if (firstVisible) {
        select.value = firstVisible.value;
    }
}

function addDeviceFromSearch() {
    const select = document.getElementById('deviceSelect');
    if (!select) return;

    const selectedOption = select.options[select.selectedIndex];
    if (!selectedOption || selectedOption.hidden) {
        alert('Please select a device from the list before clicking Add.');
        return;
    }

    const deviceId = selectedOption.value;
    if (!deviceId) {
        alert('The selected device is invalid. Please choose a different device.');
        return;
    }

    const device = availableDevices.find(dev => String(dev.id) === String(deviceId));
    if (!device) {
        alert('The selected device entry is incomplete and cannot be added.');
        return;
    }

    addSelectedDevice(deviceId);
}

function clearDeviceSearch() {
    const searchInput = document.getElementById('deviceSearch');
    const select = document.getElementById('deviceSelect');
    if (searchInput) {
        searchInput.value = '';
    }
    if (select) {
        for (const option of select.options) {
            option.hidden = false;
        }
        if (select.options.length > 0) {
            select.selectedIndex = 0;
        }
    }
}

function addSelectedDevice(deviceId) {
    const id = String(deviceId);
    if (selectedDeviceIds.includes(id)) {
        return;
    }
    selectedDeviceIds.push(id);
    renderSelectedDevicesTable();
}

function removeSelectedDevice(deviceId) {
    selectedDeviceIds = selectedDeviceIds.filter(id => id !== String(deviceId));
    renderSelectedDevicesTable();
}

function renderSelectedDevicesTable() {
    const container = document.getElementById('selectedDevicesContainer');
    const body = document.getElementById('selectedDevicesBody');
    const emptyMessage = document.getElementById('selectedDevicesEmpty');

    body.innerHTML = '';

    if (selectedDeviceIds.length === 0) {
        container.style.display = 'block';
        emptyMessage.style.display = 'block';
        document.getElementById('selectedDeviceCount').textContent = '0';
        return;
    }

        selectedDeviceIds.forEach((deviceId, index) => {
        const device = availableDevices.find(dev => String(dev.id) === String(deviceId));
        if (!device) return;
        const row = document.createElement('tr');
        const displayTag = device.asset_tag || 'N/A';
        row.innerHTML = `<td style="padding: 10px;">${index + 1}</td>
                         <td style="padding: 10px;">${escapeHtml(displayTag)}</td>
                         <td style="padding: 10px;">${escapeHtml(device.type_name)}</td>
                         <td style="padding: 10px; text-align:center;">
                             <button type="button" class="btn btn-sm btn-outline" onclick="removeSelectedDevice(${device.id})">Remove</button>
                             <input type="hidden" name="device_id[]" value="${device.id}">
                         </td>`;
        body.appendChild(row);
    });

    emptyMessage.style.display = 'none';
    container.style.display = 'block';
    document.getElementById('selectedDeviceCount').textContent = selectedDeviceIds.length.toString();

    const select = document.getElementById('deviceSelect');
    if (select) {
        for (const option of select.options) {
            option.disabled = selectedDeviceIds.includes(option.value);
        }
        const firstVisible = Array.from(select.options).find(option => !option.hidden && !option.disabled);
        if (firstVisible) {
            select.value = firstVisible.value;
        }
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

window.addEventListener('DOMContentLoaded', function() {
    renderSelectedDevicesTable();

    if (preselectedDeviceId) {
        const preselectedDevice = availableDevices.find(dev => String(dev.id) === String(preselectedDeviceId));
        if (preselectedDevice && !selectedDeviceIds.includes(String(preselectedDevice.id))) {
            addSelectedDevice(String(preselectedDevice.id));
            const deviceSearchInput = document.getElementById('deviceSearch');
            if (deviceSearchInput) {
                deviceSearchInput.value = preselectedDevice.asset_tag || 'N/A';
            }
        }
    }

    const addDeviceBtn = document.getElementById('addDeviceBtn');
    if (addDeviceBtn) {
        addDeviceBtn.addEventListener('click', addDeviceFromSearch);
    }

    const deviceSearchInput = document.getElementById('deviceSearch');
    if (deviceSearchInput) {
        deviceSearchInput.addEventListener('keydown', handleDeviceSearchKey);
        deviceSearchInput.addEventListener('input', filterDeviceOptions);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
