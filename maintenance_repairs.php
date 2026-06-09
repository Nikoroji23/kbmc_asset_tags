<?php
/**
 * KBMC Asset Management - Maintenance & Repairs Management
 * Combined view for managing preventive maintenance schedules, repairs, and device issues
 */

$pageTitle = 'Maintenance & Repairs';
require_once 'includes/header.php';

requireITStaff();
ensureMaintenanceSchema();

// Determine current tab from GET parameter
$activeTab = $_GET['tab'] ?? 'maintenance';

// ─────────────────────────────────────────────────────────────
// MAINTENANCE SECTION
// ─────────────────────────────────────────────────────────────

// Handle maintenance completion
if (isset($_POST['complete_maintenance']) && isset($_POST['maintenance_id'])) {
    $maintenanceId   = (int)$_POST['maintenance_id'];
    $completedBy     = !empty($_POST['completed_by']) ? (int)$_POST['completed_by'] : $_SESSION['user_id'];
    $completedAt     = !empty($_POST['completed_at']) ? $_POST['completed_at'] : null;
    $completionNotes = !empty($_POST['completion_notes']) ? sanitize($_POST['completion_notes']) : null;
    $ok = markMaintenanceCompleted($maintenanceId, $completedBy, $completedAt, $completionNotes);
    if ($ok) {
        setFlashMessage('success', 'Maintenance completion recorded.');
    } else {
        setFlashMessage('error', 'Could not update maintenance record.');
    }
    header('Location: maintenance_repairs.php?tab=maintenance');
    exit();
}

// Handle new maintenance schedule
if (isset($_POST['create_maintenance'])) {
    $deviceId = (int)$_POST['device_id'];
    $maintenanceType = $_POST['maintenance_type'];
    $description = sanitize($_POST['description']);
    $scheduledDate = $_POST['scheduled_date'];
    $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

    // Validate that IT staff is assigned
    if (empty($assignedTo)) {
        setFlashMessage('error', 'You must assign this maintenance to an IT staff member.');
        header('Location: maintenance_repairs.php?tab=maintenance');
        exit();
    }

    createMaintenanceSchedule($deviceId, $maintenanceType, $description, $scheduledDate, $assignedTo, $_SESSION['user_id']);
    setFlashMessage('success', 'Maintenance schedule created. Reminders will be sent.');
    header('Location: maintenance_repairs.php?tab=maintenance');
    exit();
}

// Get upcoming maintenance
$upcomingMaintenance = getUpcomingMaintenanceReminders(30);
$select = [
    'ms.*',
    'd.asset_tag',
    'dt.type_name AS device_type',
    "a.full_name AS assigned_to_name",
    "a.email AS assigned_to_email",
];

$joins = [
    'FROM maintenance_schedules ms',
    'JOIN devices d ON ms.device_id = d.id',
    'JOIN device_types dt ON d.device_type_id = dt.id',
    'LEFT JOIN users a ON ms.assigned_to = a.id',
];

if (function_exists('columnExists') && columnExists('maintenance_schedules', 'requested_by')) {
    $select[] = "r.full_name AS requested_by_name";
    $joins[]   = 'LEFT JOIN users r ON ms.requested_by = r.id';
}

if (function_exists('columnExists') && columnExists('maintenance_schedules', 'completed_by')) {
    $select[] = "c.full_name AS completed_by_name";
    $joins[]   = 'LEFT JOIN users c ON ms.completed_by = c.id';
}

$sql = 'SELECT ' . implode(', ', $select) . ' ' . implode(' ', $joins) . ' WHERE ms.last_performed_date IS NULL ORDER BY ms.next_due_date ASC LIMIT 50';
$allMaintenance = $pdo->query($sql)->fetchAll();

// ─────────────────────────────────────────────────────────────
// REPAIRS SECTION
// ─────────────────────────────────────────────────────────────

// Handle manual repair submission (IT staff can report)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_repair'])) {
    $device_id = $_POST['device_id'] ?? '';
    $issue_description = trim($_POST['issue_description'] ?? '');
    $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

    // Validate that IT staff is assigned
    if (empty($assigned_to)) {
        setFlashMessage('error', 'You must assign this repair to an IT staff member.');
        header('Location: maintenance_repairs.php?tab=repairs');
        exit();
    }

    try {
        // Check if assigned_to column exists (for backward compatibility)
        $columns = ['device_id', 'reported_by', 'issue_description', 'repair_status', 'started_date'];
        $values = [$device_id, $_SESSION['user_id'], $issue_description, 'under_repair', date('Y-m-d H:i:s')];
        
        if (columnExists('device_repairs', 'assigned_to')) {
            $columns[] = 'assigned_to';
            $values[] = $assigned_to;
        }
        
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $columnList = implode(', ', $columns);
        $sql = "INSERT INTO device_repairs ($columnList) VALUES ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        $repairId = $pdo->lastInsertId();

        // Update device status to under repair
        $pdo->prepare("UPDATE devices SET status = 'under_repair' WHERE id = ?")->execute([$device_id]);

        // Get device info for notifications
        $devStmt = $pdo->prepare("SELECT d.asset_tag, dt.type_name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ?");
        $devStmt->execute([$device_id]);
        $device = $devStmt->fetch();
        $assetTag = $device ? $device['asset_tag'] : 'Device';
        $deviceType = $device ? $device['type_name'] : 'Unknown';

        // Enhanced audit log with device and assignment details
        $assignedName = 'Unassigned';
        if ($assigned_to) {
            $assignStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $assignStmt->execute([$assigned_to]);
            $assignedUser = $assignStmt->fetch();
            $assignedName = $assignedUser ? $assignedUser['full_name'] : "User ID {$assigned_to}";
        }
        
        $auditDetails = "Device: {$assetTag} ({$deviceType}). " .
                       "Issue: " . substr($issue_description, 0, 100) . (strlen($issue_description) > 100 ? '...' : '') . ". " .
                       "Assigned To: {$assignedName}";
        logAudit($_SESSION['user_id'], 'Create Repair Request', 'device_repairs', $repairId, $auditDetails);

        // Notify assigned person if specified
        if ($assigned_to) {
            $userStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? AND status = 'active'");
            $userStmt->execute([$assigned_to]);
            $assignedPerson = $userStmt->fetch();
            
            if ($assignedPerson && isEmailConfigured()) {
                $emailBody = emailTemplate(
                    'Repair Task Assigned',
                    "<p>Hello <strong>" . sanitize($assignedPerson['full_name']) . "</strong>,</p>
                    <p>A new device repair task has been assigned to you.</p>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #e74c3c;'>
                        <p><strong>Repair Details:</strong></p>
                        <p><i class='fas fa-laptop'></i> <strong>Device:</strong> " . sanitize($assetTag) . "</p>
                        <p><i class='fas fa-tools'></i> <strong>Issue:</strong> " . sanitize($issue_description) . "</p>
                        <p><i class='fas fa-calendar'></i> <strong>Reported:</strong> " . date('F d, Y g:i A') . "</p>
                    </div>
                    <p>Please log in to the system to view more details and update the repair status when you have finished.</p>",
                    'View Repair',
                    'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/maintenance_repairs.php?tab=repairs'
                );
                sendEmail($assignedPerson['email'], 'Repair Task Assigned - ' . sanitize($assetTag), $emailBody);
            }
            
            addNotification($assigned_to, 'repair_assigned', 'Repair Task Assigned', "You have been assigned a repair for {$assetTag}.", $repairId);
        }

        // Notify all IT staff
        notifyITStaff('repair_needed', 'New Repair Request', "A new repair request has been created for {$assetTag}: {$issue_description}", $device_id);

        setFlashMessage('success', 'Repair request created and marked as under repair.' . ($assigned_to ? ' Assignment notification sent.' : ''));
        header('Location: maintenance_repairs.php?tab=repairs');
        exit();
    } catch (PDOException $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Get pending and completed repairs
$pendingRepairs = getPendingRepairs();
$completedRepairs = getCompletedRepairs(10);

// Get completed maintenance
$completedMaintenance = getCompletedMaintenance(10);

// Get IT staff for assignment (IT staff only, not admins)
$itStaff = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'it_staff' ORDER BY full_name")->fetchAll();

// Get all non-disposed devices for the searchable picker (with employee info and device type)
$devices = $pdo->query("
    SELECT d.id, d.asset_tag, dt.type_name, d.status, dt.type_name, COALESCE(u.full_name, '') as employee_name
    FROM devices d
    JOIN device_types dt ON d.device_type_id = dt.id
    LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active'
    LEFT JOIN users u ON da.employee_id = u.id
    WHERE d.status != 'disposed'
    ORDER BY d.asset_tag
")->fetchAll();
$repairableDevices = $pdo->query("
    SELECT d.id, d.asset_tag, d.vendor, dt.type_name, COALESCE(u.full_name, '') as employee_name, CONCAT(COALESCE(d.vendor, 'Unknown'), ' - ', dt.type_name) as name
    FROM devices d
    JOIN device_types dt ON d.device_type_id = dt.id
    LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active'
    LEFT JOIN users u ON da.employee_id = u.id
    WHERE d.status IN ('deployed', 'in_stock')
    ORDER BY d.asset_tag
")->fetchAll();

$flash = getFlashMessage();

// Compute maintenance overdue / urgent counts and collect urgent IDs
$overdueCount = 0;
$urgentCount  = 0;
$urgentIds    = [];
$today        = new DateTime();
foreach ($upcomingMaintenance as $m) {
    $due  = new DateTime($m['next_due_date']);
    $diff = (int)$today->diff($due)->days;
    if ($today > $due) {
        $overdueCount++;
        $urgentIds[] = $m['id'];
    } elseif ($diff <= 7) {
        $urgentCount++;
        $urgentIds[] = $m['id'];
    }
}

// Filter allMaintenance to exclude urgent items (to avoid redundancy)
$nonUrgentMaintenance = array_filter($allMaintenance, function($item) use ($urgentIds) {
    return !in_array($item['id'], $urgentIds);
});

// Merge urgent and non-urgent for single combined view
$allMaintenanceMerged = array_merge($upcomingMaintenance, $nonUrgentMaintenance);
// Sort by due date
usort($allMaintenanceMerged, function($a, $b) {
    return strtotime($a['next_due_date']) - strtotime($b['next_due_date']);
});
?>

<style>
/* ── Tabs ──────────────────────────────────────────────── */
.tab-navigation {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e8ecf0;
}
.tab-btn {
    background: none;
    border: none;
    padding: 12px 20px;
    font-size: 15px;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all .2s;
    position: relative;
    top: 2px;
}
.tab-btn:hover {
    color: #1a2332;
}
.tab-btn.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* ── Stats Cards ──────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
@media (max-width: 768px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

.stat-card {
    background: #fff;
    border-radius: 10px;
    padding: 20px 22px;
    border: 1px solid #e8ecf0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    display: flex;
    align-items: center;
    gap: 16px;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.stat-text .num {
    font-size: 28px;
    font-weight: 800;
    line-height: 1;
    color: #1a2332;
}
.stat-text .lbl {
    font-size: 12px;
    color: #6b7280;
    margin-top: 3px;
    font-weight: 500;
}

/* ── Table Styles ─────────────────────────────────────── */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.data-table thead tr {
    background: #f8fafc;
    border-bottom: 2px solid #e8ecf0;
}
.data-table thead th {
    padding: 11px 16px;
    text-align: left;
    font-size: 11.5px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}
.data-table tbody tr {
    border-bottom: 1px solid #f0f2f5;
    transition: background .12s;
}
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody tr:hover { background: #f8fafc; }
.data-table tbody td {
    padding: 12px 16px;
    vertical-align: middle;
    color: #374151;
}

/* ── Card Section ─────────────────────────────────────── */
.section-card {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #e8ecf0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    margin-bottom: 20px;
    overflow: hidden;
}
.section-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f5;
    flex-wrap: wrap;
    gap: 10px;
    background: #fff;
}
.section-card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #1a2332;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Search Input ─────────────────────────────────────── */
.table-search {
    padding: 7px 12px 7px 34px;
    border: 1.5px solid #dde1e7;
    border-radius: 7px;
    font-size: 13px;
    width: 210px;
    background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat left 10px center;
    transition: border-color .2s, box-shadow .2s;
    color: #374151;
}
.table-search:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.12);
    background-color: #fff;
}

/* ── Urgency Badges ───────────────────────────────────── */
.urgency-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    letter-spacing: .2px;
}
.urgency-overdue  { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
.urgency-critical { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
.urgency-warning  { background: #fffbe6; color: #ad8b00; border: 1px solid #ffe58f; }

/* ── Type Badges ──────────────────────────────────────── */
.maint-type-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 5px;
    font-size: 11.5px;
    font-weight: 600;
    text-transform: capitalize;
    letter-spacing: .1px;
}
.type-preventive  { background: #eff6ff; color: #1d4ed8; }
.type-corrective  { background: #fff1f0; color: #cf1322; }
.type-calibration { background: #f0fdf4; color: #15803d; }
.type-update      { background: #faf5ff; color: #7c3aed; }
.type-inspection  { background: #fffbeb; color: #b45309; }

/* ── Urgent Alert Banner ──────────────────────────────── */
.urgent-banner {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #fed7aa;
    border-left: 4px solid #f97316;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    margin-bottom: 20px;
    overflow: hidden;
}
.urgent-banner-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-bottom: 1px solid #fed7aa;
    background: #fff7ed;
}
.urgent-banner-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #c2410c;
}

/* ── Page Title Bar ────────────────────────────────────── */
.page-title-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-title-bar h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: #1a2332;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* ── Device Picker ─────────────────────────────────────── */
.device-picker-wrapper { position: relative; }

.device-picker-search {
    width: 100%;
    padding: 9px 38px 9px 12px;
    border: 1.5px solid #dde1e7;
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
    background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat right 10px center;
    transition: border-color .2s, box-shadow .2s;
    color: #374151;
}
.device-picker-search:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.12);
}
.device-picker-search.error {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239,68,68,.1);
}

.device-picker-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0; right: 0;
    background: white;
    border: 1.5px solid #dde1e7;
    border-radius: 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 9999;
    box-shadow: 0 8px 28px rgba(0,0,0,.12);
}
.device-picker-dropdown.open { display: block; }

.device-picker-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: background .12s;
}
.device-picker-item:last-child { border-bottom: none; }
.device-picker-item:hover,
.device-picker-item.highlighted { background: #eff6ff; }

.device-picker-item .dtag {
    font-weight: 700;
    font-size: 13px;
    color: #1a2332;
    flex: 0 0 150px;
    min-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.device-picker-item .dmodel {
    font-size: 12px;
    color: #6b7280;
    flex: 1;
}
.device-picker-item .demp {
    display: block;
    font-size: 11px;
    color: #059669;
    font-weight: 500;
    margin-top: 2px;
}
.device-picker-item .dstatus {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
    text-transform: capitalize;
    white-space: nowrap;
}

.device-picker-no-results {
    padding: 18px;
    color: #9ca3af;
    font-size: 13px;
    text-align: center;
}

.device-picker-selected {
    margin-top: 8px;
    padding: 8px 12px;
    background: #f0fdf4;
    border: 1.5px solid #86efac;
    border-radius: 7px;
    font-size: 13px;
    color: #15803d;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.device-picker-selected.hidden { display: none; }

.clear-device-btn {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    padding: 0 2px;
    margin-left: 6px;
    opacity: .7;
    transition: opacity .15s;
}
.clear-device-btn:hover { opacity: 1; }

/* ── Modal ────────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(4px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-overlay.open { display: flex; }

.modal-box {
    background: white;
    border-radius: 14px;
    width: 100%;
    max-width: 580px;
    max-height: 92vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px rgba(0,0,0,.2);
    animation: modalIn .22s cubic-bezier(.16,1,.3,1);
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-16px) scale(.96); }
    to   { opacity: 1; transform: none; }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px 16px;
    border-bottom: 1px solid #f0f2f5;
    position: sticky; top: 0;
    background: white; z-index: 1;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #1a2332;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close {
    background: #f3f4f6; border: none; border-radius: 50%;
    width: 32px; height: 32px; cursor: pointer; font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    color: #6b7280; transition: background .15s, color .15s;
}
.modal-close:hover { background: #ef4444; color: white; }

.modal-body { padding: 20px 24px 24px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

.form-group { margin-bottom: 16px; }
.form-group label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
    margin-bottom: 6px;
}
.form-group label .req { color: #ef4444; }

.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #dde1e7;
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
    font-family: inherit;
    color: #374151;
}
.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.12);
}

.form-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 12px;
    border-top: 1px solid #f0f2f5;
    margin-top: 6px;
}

/* For dispose modal: place Cancel immediately left of primary action */
.dispose-modal .modal-box {
    padding-bottom: 22px;
}
.dispose-modal .form-footer {
    justify-content: flex-end;
    align-items: center;
    padding: 12px 24px;
    gap: 12px;
}

.action-btns {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
</style>

<?php if ($flash): ?>
<div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <span><?php echo $flash['message']; ?></span>
    <button type="button" class="alert-close" onclick="this.closest('.alert').remove();" aria-label="Close">&times;</button>
</div>
<?php endif; ?>

<div class="page-title-bar">
    <h1>Maintenance & Repairs</h1>
    <div class="tab-navigation">
        <button type="button" class="tab-btn <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>" onclick="switchTab('maintenance', this)">Maintenance</button>
        <button type="button" class="tab-btn <?php echo $activeTab === 'repairs' ? 'active' : ''; ?>" onclick="switchTab('repairs', this)">Repairs</button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MAINTENANCE TAB
     ═══════════════════════════════════════════════════════════ -->
<div id="maintenance-tab" class="tab-content <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>">

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff7ed;color:#ea580c;">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="stat-text">
                <div class="num" style="color:#ea580c;"><?php echo count($upcomingMaintenance); ?></div>
                <div class="lbl">Due in 30 Days</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff1f0;color:#cf1322;">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="stat-text">
                <div class="num" style="color:#cf1322;"><?php echo $overdueCount; ?></div>
                <div class="lbl">Overdue</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff7e6;color:#d46b08;">
                <i class="fas fa-fire"></i>
            </div>
            <div class="stat-text">
                <div class="num" style="color:#d46b08;"><?php echo $urgentCount; ?></div>
                <div class="lbl">Due This Week</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff;color:#1d4ed8;">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-text">
                <div class="num" style="color:#1d4ed8;"><?php echo count($allMaintenanceMerged); ?></div>
                <div class="lbl">Total Scheduled</div>
            </div>
        </div>
    </div>

    <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
        <button onclick="openCreateSchedule()" class="btn btn-success">
            <i class="fas fa-plus"></i> Add Maintenance Schedule
        </button>
    </div>

    <!-- Maintenance Schedules (All) -->
    <div class="section-card">
        <div class="section-card-header">
            <h3>
                <i class="fas fa-list" style="color:#3b82f6;"></i>
                All Maintenance Schedules
            </h3>
            <input type="text" id="maintTableSearch" class="table-search"
                   placeholder="Filter schedules…"
                   oninput="filterTable('maintTableSearch', this.value)">
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table" id="maintTable">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Due Date</th>
                        <th>Assigned To</th>
                        <th>Completed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allMaintenanceMerged as $maint):
                        $dueDate   = new DateTime($maint['next_due_date']);
                        $todayRef  = new DateTime();
                        $diffDays  = (int)$todayRef->diff($dueDate)->days;
                        $isOverdue = $todayRef > $dueDate;
                        $isUrgent  = in_array($maint['id'], $urgentIds);
                        $tc        = 'type-'.strtolower($maint['maintenance_type']);
                        
                        // Determine status badge
                        $statusBadge = '';
                        $rowBg = '';
                        if ($isOverdue) {
                            $statusBadge = '<span class="urgency-badge urgency-overdue"><i class="fas fa-exclamation-circle"></i> Overdue</span>';
                            $rowBg = 'background:#fff5f5;';
                        } elseif ($diffDays <= 3) {
                            $statusBadge = '<span class="urgency-badge urgency-critical"><i class="fas fa-fire"></i> Critical - ' . $diffDays . 'd</span>';
                            $rowBg = 'background:#ffe8e8;';
                        } elseif ($isUrgent) {
                            $statusBadge = '<span class="urgency-badge urgency-warning"><i class="fas fa-clock"></i> Urgent - ' . $diffDays . 'd</span>';
                            $rowBg = 'background:#fff9e6;';
                        } else {
                            $statusBadge = '<span class="urgency-badge" style="background:#e0e7ff;color:#3730a3;"><i class="fas fa-calendar"></i> Scheduled</span>';
                        }
                    ?>
                    <tr style="<?php echo $rowBg; ?>">
                        <td><?php echo $statusBadge; ?></td>
                        <td>
                            <span style="font-weight:600;color:#1a2332;"><?php echo htmlspecialchars($maint['asset_tag']); ?></span>
                            <?php if (!empty($maint['device_type'])): ?>
                            <br><small style="color:#6b7280;"><?php echo htmlspecialchars($maint['device_type']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($maint['device_type'] ?? $maint['device_type'] ?? '—'); ?></td>
                        <td>
                            <span class="maint-type-badge <?php echo $tc; ?>">
                                <?php echo str_replace('_', ' ', ucfirst($maint['maintenance_type'])); ?>
                            </span>
                        </td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280;"
                            title="<?php echo htmlspecialchars($maint['description'] ?? ''); ?>">
                            <?php echo htmlspecialchars(substr($maint['description'] ?? '', 0, 50)); ?>
                        </td>
                        <td style="color:<?php echo $isOverdue ? '#cf1322' : '#374151'; ?>;font-weight:<?php echo $isOverdue ? '600' : '400'; ?>;">
                            <?php echo date('M d, Y', strtotime($maint['next_due_date'])); ?>
                        </td>
                        <td style="color:#374151;"><?php echo htmlspecialchars($maint['assigned_to_name'] ?? '—'); ?></td>
                        <td style="color:#6b7280;">
                            <?php if (!empty($maint['completed_at'])): ?>
                                <?php echo date('M d, Y H:i', strtotime($maint['completed_at'])); ?><br>
                                <small>by <?php echo htmlspecialchars($maint['completed_by_name'] ?? '—'); ?></small>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="action-btns">
                            <button type="button" onclick="openCompleteModal(<?php echo $maint['id']; ?>)" class="btn btn-sm btn-success" title="Record Completion">
                                <i class="fas fa-check"></i>
                            </button>
                            <button onclick="sendMaintenanceReminder(<?php echo $maint['id']; ?>)" class="btn btn-sm btn-primary" title="Send Reminder Email">
                                <i class="fas fa-envelope"></i>
                            </button>
                            <a href="view_device.php?id=<?php echo $maint['device_id']; ?>#maintenance" class="btn btn-sm btn-secondary" title="View Device">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allMaintenanceMerged)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px 20px;color:#9ca3af;">
                            <i class="fas fa-calendar-times" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>
                            No maintenance schedules found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recently Completed Maintenance -->
    <?php if (!empty($completedMaintenance)): ?>
    <div class="section-card" style="border-left: 4px solid #10b981; background: #f0fdf4;">
        <div class="section-card-header">
            <h3><i class="fas fa-check-circle" style="color: #10b981;"></i> Recently Completed Maintenance (<strong><?php echo count($completedMaintenance); ?></strong>)</h3>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Completed By</th>
                        <th>Completed Date</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completedMaintenance as $maint): ?>
                    <tr>
                        <td>
                            <span style="font-weight:600;color:#1a2332;"><?php echo htmlspecialchars($maint['asset_tag']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($maint['device_type'] ?? '—'); ?></td>
                        <td>
                            <span class="maint-type-badge type-<?php echo strtolower($maint['maintenance_type']); ?>"><?php echo str_replace('_', ' ', ucfirst($maint['maintenance_type'])); ?></span>
                        </td>
                        <td style="color:#6b7280;">
                            <?php if (!empty($maint['completed_at'])): ?>
                                <?php echo date('M d, Y H:i', strtotime($maint['completed_at'])); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="color:#6b7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($maint['completion_notes'] ?? ''); ?>">
                            <?php echo htmlspecialchars(substr($maint['completion_notes'] ?? '', 0, 50)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════
     REPAIRS TAB
     ═══════════════════════════════════════════════════════════ -->
<div id="repairs-tab" class="tab-content <?php echo $activeTab === 'repairs' ? 'active' : ''; ?>">

    <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
        <button onclick="openRepairForm()" class="btn btn-success">
            <i class="fas fa-plus"></i> New Repair Request
        </button>
    </div>

    <!-- Pending Repairs -->
    <?php if (!empty($pendingRepairs)): ?>
    <div class="section-card" style="border-left: 4px solid #e74c3c; background: #fff5f5;">
        <div class="section-card-header">
            <h3><i class="fas fa-exclamation-circle"></i> Pending Repairs (<strong><?php echo count($pendingRepairs); ?></strong>)</h3>
            <input type="text" id="repairTableSearch" class="table-search"
                   placeholder="Search by asset tag or employee name…"
                   oninput="filterTable('repairTableSearch', this.value)">
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table" id="repairTableSearch">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Issue</th>
                        <th>Reported By</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th>Days in Repair</th>
                        <th>Severity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRepairs as $r): 
                        $severityColor = $r['severity'] === 'critical' ? '#e74c3c' : ($r['severity'] === 'high' ? '#f39c12' : ($r['severity'] === 'medium' ? '#3498db' : '#95a5a6'));
                        $issueSnippet = strlen($r['issue_description']) > 60 ? substr($r['issue_description'], 0, 60) . '...' : $r['issue_description'];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($r['asset_tag']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($r['type_name'] ?? $r['device_type'] ?? '—'); ?></td>
                        <td>
                            <div><?php echo sanitize($issueSnippet); ?></div>
                            <?php if (!empty($r['incident_report_file']) && file_exists($r['incident_report_file'])): ?>
                                <div style="margin-top: 8px;">
                                    <button onclick="viewAttachment('<?php echo htmlspecialchars($r['incident_report_file']); ?>', '<?php echo htmlspecialchars(basename($r['incident_report_file'])); ?>')" title="Preview attached evidence" class="btn btn-sm btn-light" style="display: inline-flex; align-items: center; gap: 6px; background: #f3f4f6; border: 1px solid #d1d5db; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #4b5563; transition: all 0.2s;">
                                        <i class="fas fa-eye"></i> View Attachment
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?php echo sanitize($r['reporter_name']); ?></div>
                            <div class="text-muted" style="font-size: 12px; margin-top: 4px;"><?php echo sanitize($r['email']); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($r['assigned_to_name'])): ?>
                                <div style="color:#16a34a;font-weight:600;"><?php echo sanitize($r['assigned_to_name']); ?></div>
                                <div class="text-muted" style="font-size: 12px;"><?php echo sanitize($r['assigned_to_email']); ?></div>
                            <?php else: ?>
                                <div style="color:#9ca3af;font-style:italic;">— Unassigned —</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge" style="background: #fff3cd; color: #856404;"><?php echo str_replace('_', ' ', ucfirst($r['repair_status'])); ?></span>
                        </td>
                        <td><strong><?php echo $r['days_in_repair']; ?></strong> days</td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $severityColor; ?>20; color: <?php echo $severityColor; ?>;"><?php echo strtoupper($r['severity'] ?? 'medium'); ?></span>
                        </td>
                        <td class="action-btns">
                            <button onclick="sendRepairNotification(event, <?php echo $r['id']; ?>, '<?php echo sanitize($r['asset_tag']); ?>')" class="btn btn-sm btn-info" title="Send Notification">
                                <i class="fas fa-bell"></i>
                            </button>
                            <button onclick="markRepairDone(event, <?php echo $r['id']; ?>, '<?php echo sanitize($r['asset_tag']); ?>')" class="btn btn-sm btn-success" title="Mark as Complete">
                                <i class="fas fa-check"></i>
                            </button>
                            <button onclick="markRepairUnrepairable(event, <?php echo $r['id']; ?>, <?php echo $r['device_id']; ?>, '<?php echo sanitize($r['asset_tag']); ?>')" class="btn btn-sm btn-danger" title="Mark as Unrepairable & Dispose">
                                <i class="fas fa-times"></i>
                            </button>
                            <a href="view_device.php?id=<?php echo $r['device_id']; ?>" class="btn btn-sm btn-secondary" title="View Device">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Completed Repairs -->
    <div class="section-card">
        <div class="section-card-header">
            <h3><i class="fas fa-check-circle"></i> Recently Completed Repairs</h3>
        </div>
        <div style="overflow-x:auto;">
            <?php if (empty($completedRepairs)): ?>
            <div style="text-align: center; padding: 30px; color: #7f8c8d;">
                <i class="fas fa-box" style="font-size: 30px; margin-bottom: 10px; display: block;"></i>
                <p>No completed repairs yet</p>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Reported By</th>
                        <th>Assigned To</th>
                        <th>Started</th>
                        <th>Completed By</th>
                        <th>Days to Repair</th>
                        <th>Repair Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completedRepairs as $r): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($r['asset_tag']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($r['type_name'] ?? $r['device_type'] ?? '—'); ?></td>
                        <td><?php echo sanitize($r['reporter_name']); ?></td>
                        <td>
                            <?php if (!empty($r['assigned_to_name'])): ?>
                                <div style="color:#16a34a;font-weight:600;"><?php echo sanitize($r['assigned_to_name']); ?></div>
                            <?php else: ?>
                                <div style="color:#9ca3af;font-style:italic;">—</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($r['started_date'])); ?></td>
                        <td>
                            <?php if (!empty($r['completed_by_name'])): ?>
                                <div style="color:#1d4ed8;font-weight:600;"><?php echo sanitize($r['completed_by_name']); ?></div>
                                <div style="font-size:11px;color:#6b7280;"><?php echo date('M d, Y H:i', strtotime($r['completed_date'])); ?></div>
                            <?php else: ?>
                                <div style="color:#9ca3af;"><?php echo date('M d, Y', strtotime($r['completed_date'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo $r['days_to_repair']; ?></strong> days</td>
                        <td><span class="text-muted" style="font-size: 12px;"><?php echo sanitize(strlen($r['repair_notes'] ?? 'N/A') > 50 ? substr($r['repair_notes'] ?? 'N/A', 0, 50) . '...' : ($r['repair_notes'] ?? 'N/A')); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════════ -->

<!-- Add Maintenance Schedule Modal -->
<div id="createScheduleModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-plus" style="color:#27ae60;"></i> Add Maintenance Schedule</h3>
            <button class="modal-close" onclick="closeCreateSchedule()" title="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="createMaintenanceForm">
                <div class="form-group">
                    <label>Device <span class="req">*</span></label>
                    <input type="hidden" name="device_id" id="selectedDeviceId">
                    <div class="device-picker-wrapper">
                        <input type="text"
                               id="deviceSearch"
                               class="device-picker-search"
                               placeholder="Search by asset tag, device type, or employee name…"
                               autocomplete="off"
                               oninput="filterDevices(this.value)"
                               onfocus="openDeviceDropdown()"
                               onkeydown="handleDeviceKey(event)">
                        <div id="deviceDropdown" class="device-picker-dropdown">
                            <?php foreach ($devices as $dev): ?>
                            <div class="device-picker-item"
                                 data-id="<?php echo $dev['id']; ?>"
                                 data-tag="<?php echo htmlspecialchars($dev['asset_tag']); ?>"
                                 data-type="<?php echo htmlspecialchars($dev['type_name'] ?? ''); ?>"
                                 data-employee="<?php echo htmlspecialchars($dev['employee_name'] ?? ''); ?>"
                                 data-status="<?php echo htmlspecialchars($dev['status']); ?>"
                                 onclick="selectDevice(this)">
                                <span class="dtag"><?php echo htmlspecialchars($dev['asset_tag']); ?></span>
                                <span class="dmodel"><?php echo htmlspecialchars(!empty($dev['type_name']) ? $dev['type_name'] : 'No type info'); ?></span>
                                <?php if (!empty($dev['employee_name'])): ?>
                                    <span class="demp"><?php echo htmlspecialchars($dev['employee_name']); ?></span>
                                <?php endif; ?>
                                <span class="dstatus"><?php echo htmlspecialchars($dev['status']); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <div id="noDeviceResults" class="device-picker-no-results" style="display:none;">
                                <i class="fas fa-search"></i> No devices match your search
                            </div>
                        </div>
                    </div>
                    <div id="selectedDeviceDisplay" class="device-picker-selected hidden">
                        <span id="selectedDeviceText"></span>
                        <button type="button" class="clear-device-btn" onclick="clearDevice()" title="Clear">&times;</button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Maintenance Type <span class="req">*</span></label>
                        <select name="maintenance_type" class="form-control" required>
                            <option value="preventive">Preventive</option>
                            <option value="corrective">Corrective</option>
                            <option value="calibration">Calibration</option>
                            <option value="update">Software Update</option>
                            <option value="inspection">Inspection</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Scheduled Date <span class="req">*</span></label>
                        <input type="date" name="scheduled_date" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="Describe what needs to be done…"
                              style="resize:vertical;"></textarea>
                </div>

                <div class="form-group">
                    <label>Assign To (IT Staff) <span class="req">*</span></label>
                    <select name="assigned_to" class="form-control" required>
                        <option value="" disabled selected>— Select an IT staff member —</option>
                        <?php foreach ($itStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>">
                            <?php echo htmlspecialchars($staff['full_name']); ?>
                            &lt;<?php echo htmlspecialchars($staff['email']); ?>&gt;
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-footer">
                    <button type="button" onclick="closeCreateSchedule()" class="btn btn-outline">Cancel</button>
                    <button type="submit" name="create_maintenance" value="1" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Create Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Maintenance Modal -->
<div id="completeMaintenanceModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle" style="color:#16a34a;"></i> Record Maintenance Completion</h3>
            <button class="modal-close" onclick="closeCompleteModal()" title="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="completeMaintenanceForm">
                <input type="hidden" name="maintenance_id" id="completionMaintenanceId">
                <input type="hidden" name="complete_maintenance" value="1">

                <div class="form-group">
                    <label>Completed By <span class="req">*</span></label>
                    <select name="completed_by" id="completedBySelect" class="form-control" required>
                        <?php foreach ($itStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>"<?php echo $staff['id'] == $_SESSION['user_id'] ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($staff['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #7f8c8d;">Required. Select the IT staff member who verified/completed this maintenance</small>
                </div>
                <div class="form-group">
                    <label>Completion Date</label>
                    <input type="date" name="completed_at" id="completedAtInput" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Proof / Notes</label>
                    <textarea name="completion_notes" id="completionNotes" class="form-control" rows="4" placeholder="Describe what was done, findings, or attach proof URL..." style="resize:vertical;"></textarea>
                </div>

                <div class="form-footer">
                    <button type="button" onclick="closeCompleteModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Completion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- New Repair Request Modal -->
<div id="repairFormModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-tools"></i> New Repair Request</h3>
            <button type="button" class="modal-close" onclick="closeRepairForm()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <div class="form-group">
                    <label>Device <span class="required">*</span></label>
                    <input type="hidden" name="device_id" id="repairDeviceId">
                    <div class="device-picker-wrapper">
                        <input type="text"
                               id="repairDeviceSearch"
                               class="device-picker-search"
                               placeholder="Search by asset tag, device type, or employee name…"
                               autocomplete="off"
                               oninput="filterRepairDevices(this.value)"
                               onfocus="openRepairDeviceDropdown()"
                               onkeydown="handleRepairDeviceKey(event)">
                        <div id="repairDeviceDropdown" class="device-picker-dropdown">
                            <?php foreach ($repairableDevices as $rd): ?>
                            <div class="device-picker-item"
                                 data-id="<?php echo $rd['id']; ?>"
                                 data-tag="<?php echo htmlspecialchars($rd['asset_tag']); ?>"
                                 data-type="<?php echo htmlspecialchars($rd['type_name'] ?? ''); ?>"
                                 data-employee="<?php echo htmlspecialchars($rd['employee_name'] ?? ''); ?>"
                                 data-status="<?php echo htmlspecialchars($rd['status'] ?? 'in_stock'); ?>"
                                 onclick="selectRepairDevice(this)">
                                <span class="dtag"><?php echo htmlspecialchars($rd['asset_tag']); ?></span>
                                <span class="dmodel"><?php echo htmlspecialchars(!empty($rd['type_name']) ? $rd['type_name'] : 'No type info'); ?></span>
                                <?php if (!empty($rd['employee_name'])): ?>
                                    <span class="demp"><?php echo htmlspecialchars($rd['employee_name']); ?></span>
                                <?php endif; ?>
                                <span class="dstatus"><?php echo htmlspecialchars($rd['status'] ?? 'in_stock'); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <div id="noRepairDeviceResults" class="device-picker-no-results" style="display:none;">
                                <i class="fas fa-search"></i> No devices match your search
                            </div>
                        </div>
                    </div>
                    <div id="selectedRepairDeviceDisplay" class="device-picker-selected hidden">
                        <span id="selectedRepairDeviceText"></span>
                        <button type="button" class="clear-device-btn" onclick="clearRepairDevice()" title="Clear">&times;</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="issueDesc">Issue Description <span class="required">*</span></label>
                    <textarea name="issue_description" id="issueDesc" required class="form-control"></textarea>
                </div>

                <div class="form-group">
                    <label for="assignedTo">Assign To (IT Staff) <span class="required">*</span></label>
                    <select name="assigned_to" id="assignedTo" class="form-control" required>
                        <option value="" disabled selected>— Select an IT staff member —</option>
                        <?php foreach ($itStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>">
                            <?php echo htmlspecialchars($staff['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #7f8c8d;">Required. The assigned person will receive an email notification</small>
                </div>

                <div class="form-footer">
                    <button type="button" onclick="closeRepairForm()" class="btn btn-outline">Cancel</button>
                    <button type="submit" name="submit_repair" value="1" class="btn btn-primary">Create Repair Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Repair Done Modal -->
<div id="markDoneModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle"></i> Mark Repair as Complete</h3>
            <button type="button" class="modal-close" onclick="closeMarkDone()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="repairInfo" style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                <strong>Device:</strong> <span id="repairDeviceTag"></span><br>
                <small style="color: #7f8c8d;" id="repairDeviceModel"></small>
            </div>

            <div class="form-group">
                <label for="completionNotes">Completion Notes</label>
                <textarea id="completionNotes" class="form-control" placeholder="Describe what was done to fix the device..."></textarea>
                <small class="form-hint">This will be included in the email sent to the employee</small>
            </div>

            <div style="background: #e8f4f8; padding: 12px; border-left: 3px solid #3498db; margin-bottom: 15px; border-radius: 3px;">
                <strong>Employee will be notified:</strong>
                <ul style="margin: 8px 0 0 20px; font-size: 12px; color: #555;">
                    <li>System notification when repair completes</li>
                    <li>Email with repair completion details</li>
                    <li>Device will be marked as "Deployed" and ready for use</li>
                </ul>
            </div>

            <div class="form-footer">
                <button type="button" onclick="closeMarkDone()" class="btn btn-outline">Cancel</button>
                <button type="button" onclick="submitRepairCompletion()" class="btn btn-success">Mark as Complete</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="repairIdToMark">

<!-- Mark Repair Unrepairable & Dispose Modal -->
<div id="unreparableModal" class="modal-overlay">
    <div class="modal-box dispose-modal">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Mark as Unrepairable & Dispose</h3>
            <button type="button" class="modal-close" onclick="closeUnreparableModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background: #fef2f2; border: 1px solid #fed7d7; border-radius: 8px; padding: 12px; margin-bottom: 16px; color: #991b1b;">
                <i class="fas fa-warning" style="margin-right: 8px;"></i>
                <strong>Warning:</strong> This action will mark the device as unrepairable and move it to disposed status.
            </div>
            <div class="form-group">
                <label for="unreparableAssignedTo">Handled By IT Staff <span class="req">*</span></label>
                <select id="unreparableAssignedTo" class="form-control" style="padding: 10px 12px; border: 1.5px solid #dde1e7; border-radius: 7px; font-size: 14px; color: #374151; background: white;">
                    <option value="">— Select IT Staff Member —</option>
                    <?php foreach ($itStaff as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="unreparableNotes">Disposal Notes <span class="req">*</span></label>
                <textarea id="unreparableNotes" class="form-control" placeholder="Explain why this device cannot be repaired..." rows="4" style="resize: vertical;"></textarea>
            </div>
        </div>
        <div class="form-footer">
            <button type="button" class="btn btn-secondary" onclick="closeUnreparableModal()">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="submitDisposeRepair()">Mark as Disposed</button>
        </div>
    </div>
</div>

<input type="hidden" id="deviceIdToDispose">

<!-- Attachment Viewer Modal -->
<div id="attachmentViewerModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
    <div style="background: white; border-radius: 12px; width: 100%; max-width: 700px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <!-- Modal Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e9f0; display: flex; align-items: center; justify-content: space-between;">
            <h3 id="attachmentTitle" style="margin: 0; font-size: 16px; font-weight: 700; color: #1f2937;">Attachment Preview</h3>
            <button onclick="closeAttachmentViewer()" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                &times;
            </button>
        </div>
        <!-- Modal Body -->
        <div style="padding: 30px; display: flex; align-items: center; justify-content: center; min-height: 300px;">
            <div id="attachmentViewer" style="width: 100%; display: flex; align-items: center; justify-content: center;">
                <!-- Content will be inserted here by JavaScript -->
            </div>
        </div>
        <!-- Modal Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e9f0; display: flex; justify-content: flex-end; gap: 10px;">
            <button onclick="closeAttachmentViewer()" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-size: 13px; font-weight: 600; color: #4b5563;">
                <i class="fas fa-times"></i> Close
            </button>
            <a id="attachmentDownloadBtn" href="#" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; border: none; background: #c0392b; color: white; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none;">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────────
// TAB SWITCHING
// ─────────────────────────────────────────────────────────────
function switchTab(tabName, button) {
    // Hide all tabs
    document.getElementById('maintenance-tab').classList.remove('active');
    document.getElementById('repairs-tab').classList.remove('active');
    
    // Deactivate all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab and activate button
    document.getElementById(tabName + '-tab').classList.add('active');
    if (button) {
        button.classList.add('active');
    }
    
    // Update URL
    window.history.replaceState({}, '', '?tab=' + tabName);
}

// ─────────────────────────────────────────────────────────────
// MAINTENANCE FUNCTIONS
// ─────────────────────────────────────────────────────────────

let highlightedIndex = -1;

function getVisibleItems() {
    return Array.from(document.querySelectorAll('.device-picker-item'))
                .filter(el => el.style.display !== 'none');
}

function openDeviceDropdown() {
    document.getElementById('deviceDropdown').classList.add('open');
}

function closeDeviceDropdown() {
    document.getElementById('deviceDropdown').classList.remove('open');
    highlightedIndex = -1;
}

function filterDevices(query) {
    const q = query.toLowerCase().trim();
    const items = document.querySelectorAll('.device-picker-item');
    let visible = 0;

    items.forEach(item => {
        const matches = !q
            || item.dataset.tag.toLowerCase().includes(q)
            || item.dataset.type.toLowerCase().includes(q)
            || item.dataset.employee.toLowerCase().includes(q);
        item.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });

    document.getElementById('noDeviceResults').style.display = visible === 0 ? '' : 'none';
    openDeviceDropdown();
    highlightedIndex = -1;
}

function handleDeviceKey(e) {
    const items = getVisibleItems();
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
        updateHighlight(items);
        items[highlightedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
        updateHighlight(items);
        items[highlightedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightedIndex >= 0 && items[highlightedIndex]) {
            selectDevice(items[highlightedIndex]);
        }
    } else if (e.key === 'Escape') {
        closeDeviceDropdown();
    }
}

function updateHighlight(items) {
    items.forEach((el, i) => el.classList.toggle('highlighted', i === highlightedIndex));
}

function selectDevice(el) {
    const id     = el.dataset.id;
    const tag    = el.dataset.tag;
    const type   = el.dataset.type || 'No type info';
    const status = el.dataset.status;

    document.getElementById('selectedDeviceId').value = id;
    document.getElementById('deviceSearch').value     = '';
    document.getElementById('deviceSearch').classList.remove('error');

    const display = document.getElementById('selectedDeviceDisplay');
    display.classList.remove('hidden');
    document.getElementById('selectedDeviceText').innerHTML =
        '<i class="fas fa-check-circle"></i> <strong>Asset Tag:</strong> ' + tag + ' &mdash; '
        + type + ' <span style="font-size:11px;opacity:.7;">(' + status + ')</span>';

    closeDeviceDropdown();
    filterDevices('');
}

function clearDevice() {
    document.getElementById('selectedDeviceId').value = '';
    document.getElementById('selectedDeviceDisplay').classList.add('hidden');
    document.getElementById('deviceSearch').value = '';
    filterDevices('');
    document.getElementById('deviceSearch').focus();
}

document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.device-picker-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        closeDeviceDropdown();
    }
});

function openCreateSchedule() {
    document.getElementById('createScheduleModal').classList.add('open');
    setTimeout(function() {
        document.getElementById('deviceSearch').focus();
    }, 120);
}

function closeCreateSchedule() {
    document.getElementById('createScheduleModal').classList.remove('open');
}

function openCompleteModal(maintenanceId) {
    document.getElementById('completionMaintenanceId').value = maintenanceId;
    document.getElementById('completionNotes').value = '';
    document.getElementById('completeMaintenanceModal').classList.add('open');
}

function closeCompleteModal() {
    document.getElementById('completeMaintenanceModal').classList.remove('open');
}

document.getElementById('createScheduleModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCreateSchedule();
});

document.getElementById('completeMaintenanceModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCompleteModal();
});

document.getElementById('createMaintenanceForm')?.addEventListener('submit', function(e) {
    const deviceId = document.getElementById('selectedDeviceId').value;
    if (!deviceId) {
        e.preventDefault();
        const searchEl = document.getElementById('deviceSearch');
        searchEl.classList.add('error');
        searchEl.placeholder = 'Please select a device first';
        searchEl.focus();
        openDeviceDropdown();
    }
});

document.getElementById('deviceSearch')?.addEventListener('input', function() {
    this.classList.remove('error');
    this.placeholder = 'Search by asset tag or device type…';
});

function filterTable(tableId, query) {
    const q = query.toLowerCase();
    const tableElement = document.getElementById(tableId === 'maintTableSearch' ? 'maintTable' : tableId);
    if (tableElement) {
        tableElement.querySelectorAll('tbody tr').forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }
}

function sendMaintenanceReminder(maintenanceId) {
    if (!confirm('Send maintenance reminder email to assigned staff?')) return;

    fetch('api_send_maintenance_reminder.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ maintenance_id: maintenanceId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert(data.success ? 'Reminder email sent successfully.' : 'Error: ' + data.message);
    })
    .catch(function(err) { console.error('Error:', err); });
}

// ─────────────────────────────────────────────────────────────
// REPAIR FUNCTIONS
// ─────────────────────────────────────────────────────────────

function closeRepairForm() {
    document.getElementById('repairFormModal').style.display = 'none';
}

function sendRepairNotification(e, repairId, assetTag) {
    e.preventDefault();
    const message = 'Send repair notification for device ' + assetTag + '?';
    if (!confirm(message)) return;
    
    fetch('api_send_repair_notification.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            repair_id: parseInt(repairId),
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

function markRepairDone(e, repairId, assetTag) {
    const row = e.target.closest('tr');
    const model = row ? (row.querySelector('small') ? row.querySelector('small').textContent : '') : '';

    document.getElementById('repairIdToMark').value = repairId;
    document.getElementById('repairDeviceTag').textContent = assetTag;
    document.getElementById('repairDeviceModel').textContent = model;
    document.getElementById('completionNotes').value = '';
    document.getElementById('markDoneModal').style.display = 'flex';
}

function closeMarkDone() {
    document.getElementById('markDoneModal').style.display = 'none';
}

function submitRepairCompletion() {
    const repairId = document.getElementById('repairIdToMark').value;
    const completionNotes = document.getElementById('completionNotes').value;
    
    if (!repairId) {
        alert('Error: Repair ID not found');
        return;
    }
    
    fetch('api_mark_repair_done.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            repair_id: parseInt(repairId),
            completion_notes: completionNotes
        })
    })
    .then(response => response.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (err) {
            throw new Error('Invalid server response: ' + text);
        }
    }))
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeMarkDone();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to mark repair as complete'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to mark repair as complete: ' + error.message);
    });
}

function markRepairUnrepairable(e, repairId, deviceId, assetTag) {
    e.preventDefault();
    document.getElementById('repairIdToMark').value = repairId;
    document.getElementById('deviceIdToDispose').value = deviceId;
    document.getElementById('unreparableAssignedTo').value = '';
    document.getElementById('unreparableNotes').value = '';
    document.getElementById('unreparableModal').classList.add('open');
    setTimeout(function() {
        document.getElementById('unreparableAssignedTo').focus();
    }, 100);
}

function closeUnreparableModal() {
    document.getElementById('unreparableModal').classList.remove('open');
}

function submitDisposeRepair() {
    const repairId = document.getElementById('repairIdToMark').value;
    const deviceId = document.getElementById('deviceIdToDispose').value;
    const assignedTo = document.getElementById('unreparableAssignedTo').value;
    const notes = document.getElementById('unreparableNotes').value.trim();
    
    if (!assignedTo) {
        alert('Please select an IT staff member to handle the disposal.');
        document.getElementById('unreparableAssignedTo').focus();
        return;
    }
    
    if (!notes) {
        alert('Please provide a reason for disposal.');
        document.getElementById('unreparableNotes').focus();
        return;
    }
    
    if (!confirm('Are you sure? This will mark the device as unrepairable and move it to disposed status.')) {
        return;
    }
    
    fetch('api_dispose_unrepairable_device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ repair_id: repairId, device_id: deviceId, assigned_to: assignedTo, notes: notes })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Device marked as unrepairable and disposed successfully.');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to process'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to dispose device: ' + error.message);
    });
}

document.getElementById('repairFormModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRepairForm();
});

document.getElementById('markDoneModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeMarkDone();
});

document.getElementById('unreparableModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeUnreparableModal();
});

// ─────────────────────────────────────────────────────────────
// REPAIR DEVICE PICKER FUNCTIONS
// ─────────────────────────────────────────────────────────────

let repairDeviceHighlightedIndex = -1;

function openRepairDeviceDropdown() {
    const dropdown = document.getElementById('repairDeviceDropdown');
    dropdown.style.display = 'block';
}

function closeRepairDeviceDropdown() {
    const dropdown = document.getElementById('repairDeviceDropdown');
    dropdown.style.display = 'none';
}

function filterRepairDevices(query) {
    const q = query.toLowerCase().trim();
    const items = document.querySelectorAll('#repairDeviceDropdown .device-picker-item');
    let visible = 0;

    items.forEach(item => {
        const matches = !q
            || item.dataset.tag.toLowerCase().includes(q)
            || item.dataset.type.toLowerCase().includes(q)
            || item.dataset.employee.toLowerCase().includes(q);
        item.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });

    document.getElementById('noRepairDeviceResults').style.display = visible === 0 ? '' : 'none';
    openRepairDeviceDropdown();
    repairDeviceHighlightedIndex = -1;
}

function selectRepairDevice(el) {
    const id     = el.dataset.id;
    const tag    = el.dataset.tag;
    const type   = el.dataset.type || 'No type info';
    const employee = el.dataset.employee;

    document.getElementById('repairDeviceId').value = id;
    document.getElementById('repairDeviceSearch').value = '';

    const display = document.getElementById('selectedRepairDeviceDisplay');
    display.classList.remove('hidden');
    let displayText = '<i class="fas fa-check-circle"></i> <strong>Asset Tag:</strong> ' + tag + ' &mdash; ' + type;
    if (employee) {
        displayText += ' <span style="font-size:11px;color:#059669;">(' + employee + ')</span>';
    }
    document.getElementById('selectedRepairDeviceText').innerHTML = displayText;

    closeRepairDeviceDropdown();
    filterRepairDevices('');
}

function clearRepairDevice() {
    document.getElementById('repairDeviceId').value = '';
    document.getElementById('selectedRepairDeviceDisplay').classList.add('hidden');
    document.getElementById('repairDeviceSearch').value = '';
    filterRepairDevices('');
    document.getElementById('repairDeviceSearch').focus();
}

function handleRepairDeviceKey(e) {
    const items = Array.from(document.querySelectorAll('#repairDeviceDropdown .device-picker-item')).filter(item => item.style.display !== 'none');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        repairDeviceHighlightedIndex = Math.min(repairDeviceHighlightedIndex + 1, items.length - 1);
        items.forEach(item => item.classList.remove('highlighted'));
        items[repairDeviceHighlightedIndex].classList.add('highlighted');
        items[repairDeviceHighlightedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        repairDeviceHighlightedIndex = Math.max(repairDeviceHighlightedIndex - 1, 0);
        items.forEach(item => item.classList.remove('highlighted'));
        items[repairDeviceHighlightedIndex].classList.add('highlighted');
        items[repairDeviceHighlightedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && repairDeviceHighlightedIndex >= 0) {
        e.preventDefault();
        items[repairDeviceHighlightedIndex].click();
    }
}

document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('#repairDeviceDropdown')?.parentElement;
    if (wrapper && !wrapper.contains(e.target)) {
        closeRepairDeviceDropdown();
    }
});

function openRepairForm() {
    document.getElementById('repairFormModal').style.display = 'flex';
    setTimeout(function() {
        document.getElementById('repairDeviceSearch').focus();
    }, 120);
}

// Attachment viewer
function viewAttachment(filepath, filename) {
    const fileExt = filename.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
    const isPdf = fileExt === 'pdf';
    
    const modal = document.getElementById('attachmentViewerModal');
    const viewer = document.getElementById('attachmentViewer');
    const title = document.getElementById('attachmentTitle');
    const downloadBtn = document.getElementById('attachmentDownloadBtn');
    
    title.textContent = filename;
    downloadBtn.href = filepath;
    
    // Clear previous content
    viewer.innerHTML = '';
    
    if (isImage) {
        const img = document.createElement('img');
        img.src = filepath;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '500px';
        img.style.objectFit = 'contain';
        img.style.borderRadius = '8px';
        viewer.appendChild(img);
    } else if (isPdf) {
        const iframe = document.createElement('iframe');
        iframe.src = filepath;
        iframe.style.width = '100%';
        iframe.style.height = '500px';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '8px';
        viewer.appendChild(iframe);
    } else {
        viewer.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;"><i class="fas fa-file" style="font-size: 48px; margin-bottom: 16px; display: block;"></i><p>File preview not available</p><p style="font-size: 12px; color: #999;">Please download to view this file type.</p></div>';
    }
    
    modal.style.display = 'flex';
}

function closeAttachmentViewer() {
    document.getElementById('attachmentViewerModal').style.display = 'none';
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('attachmentViewerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeAttachmentViewer();
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
