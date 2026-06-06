<?php
/**
 * KBMC Asset Management - Device Inspections
 */
$pageTitle = 'Device Inspections';
require_once 'includes/header.php';
requireITStaffOnly();

$types = $pdo->query("SELECT * FROM device_types ORDER BY type_name")->fetchAll();
$itStaff = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active' ORDER BY full_name")->fetchAll();


$deviceId = $_GET['device'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $inspection_device_id = $_POST['device_id'] ?? '';
    $physical_condition = $_POST['physical_condition'] ?? '';
    $functionality_status = $_POST['functionality_status'] ?? '';
    $result = $_POST['result'] ?? '';
    $inspected_by = $_POST['added_by_staff'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO device_inspections (device_id, inspected_by, inspection_date, physical_condition, functionality_status, result, notes, rejection_reason) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)");
        $stmt->execute([$inspection_device_id, $_SESSION['user_id'], $physical_condition, $functionality_status, $result, $notes, $rejection_reason]);

        // Update device status
        $newStatus = $result == 'passed' ? 'in_stock' : 'rejected';
        $pdo->prepare("UPDATE devices SET status = ?, condition_notes = ? WHERE id = ?")->execute([$newStatus, $notes, $inspection_device_id]);

        logAudit($_SESSION['user_id'], 'Insert', 'device_inspections', $pdo->lastInsertId());
        setFlashMessage('success', 'Inspection recorded. Device is now ' . strtoupper($newStatus) . '.');
        header('Location: devices.php');
        exit();
    } catch (PDOException $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

$pendingDevices = $pdo->query("SELECT d.id, d.asset_tag, CONCAT(COALESCE(d.vendor, 'Unknown'), ' - ', dt.type_name) as name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.status = 'pending_inspection' ORDER BY d.asset_tag")->fetchAll();

// Get all inspections with device and user details - combined and unified
$inspections = $pdo->query("
    SELECT 
        di.id,
        di.inspected_by,
        di.inspection_date,
        di.physical_condition,
        di.functionality_status,
        di.result,
        di.notes,
        d.id as device_id,
        d.asset_tag,
        d.vendor,
        dt.type_name,
        u.full_name as inspector_name
    FROM device_inspections di
    JOIN devices d ON di.device_id = d.id
    JOIN users u ON di.inspected_by = u.id
    LEFT JOIN device_types dt ON d.device_type_id = dt.id
    ORDER BY di.inspection_date DESC, di.id DESC LIMIT 100
")->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-clipboard-check"></i> Device Inspections</h1>
</div>

<div class="card">
    <div class="card-header"><h3>Record New Inspection</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>Select Device <span class="required">*</span></label>
                    <select name="device_id" class="form-control" required>
                        <option value="">Choose device pending inspection</option>
                        <?php foreach ($pendingDevices as $pd): ?>
                        <option value="<?php echo $pd['id']; ?>" <?php echo $deviceId == $pd['id'] ? 'selected' : ''; ?>><?php echo sanitize($pd['asset_tag'] . ' - ' . $pd['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Physical Condition <span class="required">*</span></label>
                    <select name="physical_condition" class="form-control" required>
                        <option value="excellent">Excellent</option>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="poor">Poor</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Functionality <span class="required">*</span></label>
                    <select name="functionality_status" class="form-control" required>
                        <option value="fully_functional" selected>Fully Functional</option>
                        <option value="partially_functional">Partially Functional</option>
                        <option value="not_functional">Not Functional</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Result <span class="required">*</span></label>
                    <select name="result" class="form-control" required>
                        <option value="passed" selected>Passed - Accept to Inventory</option>
                        <option value="rejected">Rejected - Return to Vendor</option>
                    </select>
                </div>
                 <div class="form-group">
                    <label>Inspected by IT Staff <span class="required">*</span></label>
                    <select name="added_by_staff" class="form-control" required>
                        <option value="">Select IT Staff Member</option>
                        <?php foreach ($itStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>" <?php echo (($_POST['added_by_staff'] ?? '') == $staff['id']) ? 'selected' : ''; ?>><?php echo sanitize($staff['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="font-size:11px; color:#aaa; margin-top:4px; display:block;">
                        For audit tracking - who is adding this device to inventory.
                    </small>
                </div>
                <div class="form-group full-width">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" placeholder="Inspection observations"></textarea>
                </div>
                <div class="form-group full-width">
                    <label>Rejection Reason (if rejected)</label>
                    <textarea name="rejection_reason" class="form-control" placeholder="Why was the device rejected?"></textarea>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Record Inspection</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Inspections</h3>
    </div>
    <div class="card-body">
        <!-- Filtering Section -->
        <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px; border-left: 3px solid #3498db;">
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 12px; font-weight: 600;">Filter by Result</label>
                    <select id="filterResult" class="form-control" onchange="filterInspections()">
                        <option value="">All Results</option>
                        <option value="passed">Passed</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 12px; font-weight: 600;">Filter by Inspector</label>
                    <select id="filterInspector" class="form-control" onchange="filterInspections()">
                        <option value="">All Inspectors</option>
                        <?php 
                        $inspectors = $pdo->query("SELECT DISTINCT u.id, u.full_name FROM device_inspections di JOIN users u ON di.inspected_by = u.id ORDER BY u.full_name")->fetchAll();
                        foreach ($inspectors as $insp): ?>
                        <option value="<?php echo $insp['id']; ?>"><?php echo sanitize($insp['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 12px; font-weight: 600;">Search Asset Tag</label>
                    <input type="text" id="filterAssetTag" class="form-control" placeholder="Search..." onkeyup="filterInspections()">
                </div>
                <div class="form-group" style="margin-bottom: 0; display: flex; align-items: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="clearFilters()" style="width: 100%;"><i class="fas fa-times"></i> Clear Filters</button>
                </div>
            </div>
        </div>

        <!-- Inspections Table -->
        <div class="data-table-wrapper">
            <table class="data-table" id="inspectionsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Asset Tag</th>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Condition</th>
                        <th>Functionality</th>
                        <th>Result</th>
                        <th>Inspector</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inspections)): ?>
                    <tr><td colspan="9" class="empty-state" style="padding: 40px;"><h4>No inspections yet</h4></td></tr>
                    <?php else: ?>
                    <?php foreach ($inspections as $i): ?>
                    <tr class="inspection-row" data-result="<?php echo $i['result']; ?>" data-inspector="<?php echo $i['inspected_by']; ?>" data-asset-tag="<?php echo strtolower($i['asset_tag']); ?>">
                        <td><?php echo formatDate($i['inspection_date']); ?></td>
                        <td><strong><?php echo sanitize($i['asset_tag']); ?></strong></td>
                        <td><span style="font-size: 11px; background: #e8f4f8; padding: 3px 8px; border-radius: 3px;"><?php echo sanitize($i['type_name'] ?? 'N/A'); ?></span></td>
                        <td><?php echo ucfirst($i['physical_condition']); ?></td>
                        <td><?php echo ucwords(str_replace('_', ' ', $i['functionality_status'])); ?></td>
                        <td><?php echo getStatusBadge($i['result']); ?></td>
                        <td><?php echo sanitize($i['inspector_name']); ?></td>
                        <td class="action-btns">
                            <button onclick="sendInspectionNotification(event, <?php echo $i['id']; ?>, '<?php echo sanitize($i['asset_tag']); ?>')" class="btn btn-sm btn-info" title="Send Notification">
                                <i class="fas fa-bell"></i> Notify
                            </button>
                            <a href="view_device.php?id=<?php echo $i['device_id']; ?>" class="btn btn-sm btn-secondary" title="View Device">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
function filterInspections() {
    const filterResult = document.getElementById('filterResult').value.toLowerCase();
    const filterInspector = document.getElementById('filterInspector').value;
    const filterAssetTag = document.getElementById('filterAssetTag').value.toLowerCase();
    
    const rows = document.querySelectorAll('.inspection-row');
    
    rows.forEach(row => {
        let show = true;
        
        // Filter by result
        if (filterResult && row.dataset.result !== filterResult) {
            show = false;
        }
        
        // Filter by inspector
        if (filterInspector && row.dataset.inspector !== filterInspector) {
            show = false;
        }
        
        // Filter by asset tag (search)
        if (filterAssetTag && !row.dataset.assetTag.includes(filterAssetTag)) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

function clearFilters() {
    document.getElementById('filterResult').value = '';
    document.getElementById('filterInspector').value = '';
    document.getElementById('filterAssetTag').value = '';
    filterInspections();
}

function sendInspectionNotification(e, inspectionId, assetTag) {
    e.preventDefault();
    const message = 'Send inspection notification for device ' + assetTag + '?';
    if (!confirm(message)) return;
    
    fetch('api_send_inspection_notification.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            inspection_id: parseInt(inspectionId),
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
