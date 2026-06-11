<?php
/**
 * KBMC Asset Management - User Asset Dashboard
 * Shows employees their assigned devices with status, maintenance alerts, and repair history
 */

$pageTitle = 'My Devices';
require_once 'includes/header.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userEmail = $_SESSION['email'];

// Get employee's assigned devices
$assignedDevices = getEmployeeAssignedDevices($userId);
$stats = getEmployeeDeviceStats($userId);

// Get current active assignments for change request form buttons
$activeAssignments = [];
if (!empty($assignedDevices)) {
    $placeholders = implode(',', array_fill(0, count($assignedDevices), '?'));
    $ids = array_column($assignedDevices, 'id');
    $stmt = $pdo->prepare("SELECT id, device_id FROM device_assignments WHERE id IN ($placeholders) AND status = 'active'");
    $stmt->execute($ids);
    $activeAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activeAssignmentMap = array_column($activeAssignments, 'id', 'device_id');
}

$search = $_GET['search'] ?? '';
?>

<style>
/* Modal Override - Force Full Viewport Coverage */
#reportIssueModal, #changeRequestModal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    max-width: none !important;
    max-height: none !important;
    margin: 0 !important;
    padding: 0 !important;
}
</style>

<div class="page-header">
    <h1><i class="fas fa-laptop"></i> My Devices</h1>
    <div class="page-header-btn">
        <button onclick="window.print()" class="btn btn-outline" title="Print or save as PDF" style="display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-print"></i> Print / PDF
        </button>
    </div>
</div>

<style>
/* Print Styles */
@media print {
    body, html {
        background: white;
        margin: 0;
        padding: 10px;
    }
    
    .sidebar, .page-header-btn, .search-area, .btn:not(.print-only), .action-btn, .no-print {
        display: none !important;
    }
    
    .page-header {
        margin-bottom: 30px;
        border-bottom: 3px solid #333;
        padding-bottom: 15px;
    }
    
    .page-header h1 {
        font-size: 22px;
        margin: 0;
        color: #000;
        font-weight: bold;
    }
    
    .card {
        box-shadow: none !important;
        border: 2px solid #333 !important;
        page-break-inside: avoid;
        margin-bottom: 20px;
    }
    
    .card-header {
        background: #f5f5f5;
        border-bottom: 2px solid #333;
        padding: 12px;
        font-weight: bold;
    }
    
    .data-table-wrapper {
        border: none !important;
    }
    
    table {
        border-collapse: collapse;
        width: 100%;
        font-size: 11px;
    }
    
    th {
        background: #f0f0f0 !important;
        border: 1px solid #333 !important;
        padding: 8px !important;
        text-align: left;
        font-weight: bold !important;
        color: #000 !important;
    }
    
    td {
        border: 1px solid #999 !important;
        padding: 7px !important;
        color: #000 !important;
    }
    
    tr:nth-child(even) {
        background: #f9f9f9;
    }
    
    .status-badge {
        border: 1px solid #000 !important;
        background-color: white !important;
        color: black !important;
    }
}

/* Screen Styles */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h1 {
    margin: 0;
    font-size: 28px;
    color: #2c3e50;
}

.page-header-btn {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.search-area {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: center;
}

.search-area input {
    padding: 10px 12px;
    border: 1px solid #d6d8db;
    border-radius: 8px;
    font-size: 14px;
    flex: 1;
    min-width: 250px;
}

.data-table-wrapper {
    border: 1px solid #ecf0f1;
    border-radius: 8px;
    max-height: 700px;
    overflow-y: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    position: sticky;
    top: 0;
    background-color: #f8f9fa;
    z-index: 10;
}

.data-table thead th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #2c3e50;
    border-bottom: 2px solid #dee2e6;
}

.data-table tbody tr {
    border-bottom: 1px solid #ecf0f1;
    transition: background-color 0.2s;
}

.data-table tbody tr:hover {
    background-color: #f5f7ff;
}

.data-table td {
    padding: 12px;
    vertical-align: middle;
}

.data-table strong {
    color: #2c3e50;
    font-weight: 600;
}

.action-btns {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.action-btn.view {
    background: #e8f4f8;
    color: #3498db;
}

.action-btn.view:hover {
    background: #3498db;
    color: white;
}

.action-btn.report {
    background: #fef5e7;
    color: #f39c12;
}

.action-btn.report:hover {
    background: #f39c12;
    color: white;
}

.action-btn.change-request {
    background: #eaf2f8;
    color: #2980b9;
}

.action-btn.change-request:hover {
    background: #2980b9;
    color: white;
}

.card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    margin-bottom: 25px;
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #dee2e6;
    padding: 18px 20px;
    border-radius: 12px 12px 0 0;
}

.card-header h3 {
    margin: 0;
    color: #2c3e50;
    font-size: 15px;
    font-weight: 600;
}

.card-body {
    padding: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stat-box {
    background: white;
    border: 1px solid #ecf0f1;
    border-radius: 8px;
    padding: 18px;
    text-align: center;
}

.stat-box .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 5px;
}

.stat-box .stat-label {
    font-size: 12px;
    color: #7f8c8d;
    font-weight: 500;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #95a5a6;
}

.empty-state i {
    font-size: 40px;
    color: #bdc3c7;
    margin-bottom: 15px;
    display: block;
}

.empty-state h4 {
    margin: 10px 0;
    color: #7f8c8d;
}
</style>

<div class="stats-grid">
    <div class="stat-box">
        <div class="stat-value"><?php echo $stats['total_devices'] ?? 0; ?></div>
        <div class="stat-label">Total Assigned</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?php echo $stats['active_devices'] ?? 0; ?></div>
        <div class="stat-label">Active & Functional</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?php echo $stats['devices_under_repair'] ?? 0; ?></div>
        <div class="stat-label">Under Repair</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?php echo $stats['pending_repairs'] ?? 0; ?></div>
        <div class="stat-label">Pending Repairs</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Your Devices (<?php echo count($assignedDevices); ?> Total)</h3>
    </div>
    <div class="card-body">
        <?php if (!empty($assignedDevices)): ?>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Asset Tag</th>
                        <th>Device Type</th>
                        <th>Status</th>
                        <th>Assigned Date</th>
                        <th>Maintenance</th>
                        <th>Repairs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignedDevices as $device): ?>
                    <?php $statusColor = getStatusColor($device['status']); ?>
                    <?php $assignmentId = isset($activeAssignmentMap[$device['id']]) ? $activeAssignmentMap[$device['id']] : null; ?>
                    <tr>
                        <td><strong><?php echo $device['asset_tag']; ?></strong></td>
                        <td><?php echo $device['type_name']; ?></td>
                        <td>
                            <span class="status-badge" style="background-color: <?php echo $statusColor['color_code']; ?>20; color: <?php echo $statusColor['color_code']; ?>; border: 1px solid <?php echo $statusColor['color_code']; ?>;">
                                <i class="<?php echo $statusColor['icon_class']; ?>"></i> <?php echo $statusColor['display_label']; ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($device['assigned_date'])); ?></td>
                        <td>
                            <?php if ($device['upcoming_maintenance'] > 0): ?>
                            <span style="background: #fff3cd; color: #856404; padding: 5px 8px; border-radius: 5px; font-size: 11px; font-weight: 600;">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $device['upcoming_maintenance']; ?> Due
                            </span>
                            <?php else: ?>
                            <span style="color: #27ae60; font-size: 11px; font-weight: 600;">✓ On Schedule</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($device['pending_repairs'] > 0): ?>
                            <span style="background: #f8d7da; color: #721c24; padding: 5px 8px; border-radius: 5px; font-size: 11px; font-weight: 600;">
                                <i class="fas fa-wrench"></i> <?php echo $device['pending_repairs']; ?>
                            </span>
                            <?php else: ?>
                            <span style="color: #95a5a6; font-size: 11px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view_device.php?id=<?php echo $device['id']; ?>" class="action-btn view" title="View Details"><i class="fas fa-eye"></i></a>
                                <button onclick="reportIssue(<?php echo $device['id']; ?>, '<?php echo $device['asset_tag']; ?>')" class="action-btn report" title="Report Issue"><i class="fas fa-exclamation-circle"></i></button>
                                <?php if ($assignmentId): ?>
                                <button onclick="openChangeRequestForm(<?php echo $assignmentId; ?>, '<?php echo $device['asset_tag']; ?>')" class="action-btn change-request" title="Device Change Request Form"><i class="fas fa-exchange-alt"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>No Devices Assigned Yet</h4>
            <p>You don't have any devices assigned to you. Contact IT if you need a device.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="reportIssueModal" style="display: none; position: fixed; inset: 0; background: linear-gradient(135deg, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0.4) 100%); z-index: 2000; align-items: center; justify-content: center; overflow-y: auto; padding: 20px; box-sizing: border-box; animation: fadeIn 0.3s ease;">
    <div style="width: 100%; max-width: 580px; background: white; border-radius: 16px; box-shadow: 0 25px 80px rgba(0,0,0,0.3); overflow: hidden; animation: slideUp 0.3s ease;">
        <div style="background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); padding: 30px 28px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-exclamation-circle" style="font-size: 28px; color: white;"></i>
                <h2 style="margin: 0; color: white; font-size: 22px; font-weight: 700;">Report Device Issue</h2>
            </div>
            <button onclick="closeReportIssue()" style="background: rgba(255,255,255,0.2); border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; color: white; font-size: 24px; transition: all 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255,255,255,0.3)';" onmouseout="this.style.background='rgba(255,255,255,0.2)';">&times;</button>
        </div>

        <div style="padding: 28px;">
            <form id="reportForm" method="POST" enctype="multipart/form-data">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Device</label>
                    <input type="text" id="deviceTag" readonly style="background: #f0f2f5; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; width: 100%; font-size: 15px; color: #555; font-weight: 600;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Issue Description <span style="color: #e74c3c;">*</span></label>
                    <textarea id="issueDescription" name="issue_description" required style="width: 100%; height: 110px; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; font-family: 'Segoe UI', Arial, sans-serif; font-size: 14px; resize: vertical; transition: border 0.2s;" placeholder="Describe the issue you're experiencing..." onfocus="this.style.borderColor='#f39c12';" onblur="this.style.borderColor='#e8eaed';"></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Severity</label>
                        <select id="severity" name="severity" style="width: 100%; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; font-size: 14px; background: white; cursor: pointer; transition: border 0.2s;" onfocus="this.style.borderColor='#f39c12';" onblur="this.style.borderColor='#e8eaed';">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Category <span style="color: #e74c3c;">*</span></label>
                        <select id="issueCategory" name="issue_category" required style="width: 100%; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; font-size: 14px; background: white; cursor: pointer; transition: border 0.2s;" onfocus="this.style.borderColor='#f39c12';" onblur="this.style.borderColor='#e8eaed';">
                            <option value="">Select...</option>
                            <option value="hardware">🔧 Hardware</option>
                            <option value="software">💻 Software</option>
                            <option value="connectivity">🌐 Connectivity</option>
                            <option value="battery">🔋 Battery</option>
                            <option value="display">📺 Display</option>
                            <option value="keyboard">⌨️ Keyboard</option>
                            <option value="other">📋 Other</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Attach Evidence</label>
                    <div style="position: relative;">
                        <input type="file" id="attachment" name="attachment" accept="image/*,.pdf" style="padding: 12px 14px; border: 2px dashed #3498db; border-radius: 8px; width: 100%; font-size: 13px; color: #7f8c8d; background: #f0f8ff; cursor: pointer;">
                        <small style="color: #7f8c8d; display: block; margin-top: 6px; font-size: 12px;">📎 Max 5MB (Images or PDF)</small>
                    </div>
                </div>

                <input type="hidden" id="deviceId" name="device_id">

                <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid #ecf0f1;">
                    <button type="button" onclick="closeReportIssue()" style="padding: 12px 24px; border: 2px solid #ddd; background: white; color: #555; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 14px;" onmouseover="this.style.background='#f5f5f5';" onmouseout="this.style.background='white';">Cancel</button>
                    <button type="submit" style="padding: 12px 28px; background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; transition: all 0.2s; font-size: 14px; box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(243, 156, 18, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(243, 156, 18, 0.3)';">✓ Report Issue</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="changeRequestModal" style="display: none; position: fixed; inset: 0; background: linear-gradient(135deg, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0.4) 100%); z-index: 2000; align-items: center; justify-content: center; overflow-y: auto; padding: 20px; box-sizing: border-box; animation: fadeIn 0.3s ease;">
    <div style="width: 100%; max-width: 580px; background: white; border-radius: 16px; box-shadow: 0 25px 80px rgba(0,0,0,0.3); overflow: hidden; animation: slideUp 0.3s ease;">
        <div style="background: linear-gradient(135deg, #2980b9 0%, #1f618d 100%); padding: 30px 28px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-exchange-alt" style="font-size: 28px; color: white;"></i>
                <h2 style="margin: 0; color: white; font-size: 22px; font-weight: 700;">Device Change Request Form</h2>
            </div>
            <button onclick="closeChangeRequestForm()" style="background: rgba(255,255,255,0.2); border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; color: white; font-size: 24px; transition: all 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255,255,255,0.3)';" onmouseout="this.style.background='rgba(255,255,255,0.2)';">&times;</button>
        </div>

        <div style="background: #eaf2f8; border-left: 4px solid #2980b9; padding: 16px 24px; margin: 0;">
            <p style="margin: 0; color: #1b4f72; font-size: 13px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-info-circle"></i>
                <strong>IT staff will be notified and will contact you to review your change form.</strong>
            </p>
        </div>

        <div style="padding: 28px;">
            <form id="changeRequestForm" method="POST">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Device</label>
                    <input type="text" id="changeDeviceTag" readonly style="background: #f0f2f5; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; width: 100%; font-size: 15px; color: #555; font-weight: 600;">
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #2c3e50; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Reason for Change Request Form</label>
                    <textarea id="changeReason" name="change_reason" style="width: 100%; height: 100px; padding: 12px 14px; border: 2px solid #e8eaed; border-radius: 8px; font-family: 'Segoe UI', Arial, sans-serif; font-size: 14px; resize: vertical; transition: border 0.2s;" placeholder="Tell us why you are submitting this change request form..." onfocus="this.style.borderColor='#2980b9';" onblur="this.style.borderColor='#e8eaed';"></textarea>
                </div>

                <input type="hidden" id="changeAssignmentId" name="assignment_id">

                <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid #ecf0f1;">
                    <button type="button" onclick="closeChangeRequestForm()" style="padding: 12px 24px; border: 2px solid #ddd; background: white; color: #555; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 14px;" onmouseover="this.style.background='#f5f5f5';" onmouseout="this.style.background='white';">Cancel</button>
                    <button type="submit" style="padding: 12px 28px; background: linear-gradient(135deg, #2980b9 0%, #1f618d 100%); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; transition: all 0.2s; font-size: 14px; box-shadow: 0 4px 15px rgba(41, 128, 185, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(41, 128, 185, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(41, 128, 185, 0.3)';">✓ Submit Change Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Report Issue Functions
function reportIssue(deviceId, assetTag) {
    document.getElementById('reportForm').reset();
    document.getElementById('deviceId').value = deviceId;
    document.getElementById('deviceTag').value = assetTag;
    document.getElementById('reportIssueModal').style.display = 'flex';
}

function closeReportIssue() {
    document.getElementById('reportIssueModal').style.display = 'none';
}

document.getElementById('reportForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    fetch('api_report_device_issue.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Issue reported successfully!\n\nIT team has been notified and will review your report.');
            closeReportIssue();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to report issue. Please try again.');
    });
});

document.getElementById('reportIssueModal').addEventListener('click', function(e) {
    if (e.target === this) closeReportIssue();
});

// Change Request Functions
function openChangeRequestForm(assignmentId, assetTag) {
    document.getElementById('changeAssignmentId').value = assignmentId;
    document.getElementById('changeDeviceTag').value = assetTag;
    document.getElementById('changeRequestForm').reset();
    document.getElementById('changeRequestModal').style.display = 'flex';
}

function closeChangeRequestForm() {
    document.getElementById('changeRequestModal').style.display = 'none';
}

document.getElementById('changeRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const assignmentId = document.getElementById('changeAssignmentId').value;
    const changeReason = document.getElementById('changeReason').value;
    
    fetch('api_change_request_form.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            assignment_id: assignmentId,
            change_reason: changeReason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Your change request form has been submitted successfully. IT staff will contact you to review your request.');
            closeChangeRequestForm();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to submit change request form. Please try again.');
    });
});

document.getElementById('changeRequestModal').addEventListener('click', function(e) {
    if (e.target === this) closeChangeRequestForm();
});

// Move modals to body to escape container constraints
document.addEventListener('DOMContentLoaded', function() {
    const reportModal = document.getElementById('reportIssueModal');
    const changeModal = document.getElementById('changeRequestModal');
    
    if (reportModal && reportModal.parentElement) {
        reportModal.parentElement.removeChild(reportModal);
        document.body.appendChild(reportModal);
    }
    
    if (changeModal && changeModal.parentElement) {
        changeModal.parentElement.removeChild(changeModal);
        document.body.appendChild(changeModal);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>