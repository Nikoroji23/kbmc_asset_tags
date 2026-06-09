<?php
/**
 * KBMC Asset Management — IT User Clearance (Merged & Print Optimized)
 *
 * Combines:
 * • Expandable backend tracking & multi-device single mode transactions
 * • High-fidelity printable stylesheet tailored to match the KBMC PDF layout
 * • Includes Checkbox (☐) column adjacent to the RIGHT side of Quantity per design specification
 * • Control Number auto-increments based on systemic records starting from IT-26-0029 baseline
 */

$pageTitle = 'IT User Clearance';
require_once 'includes/functions.php';
requireITStaff();

$successMessage = '';
$errorMessage   = '';

/* ─── helper ────────────────────────────────────────────────────────────── */
function condBadge(string $cond): string {
    $map = [
        'good'      => ['#27AE60','Good'],
        'fair'      => ['#F39C12','Fair'],
        'damaged'   => ['#E74C3C','Damaged'],
        'defective' => ['#E74C3C','Defective'],
    ];
    [$col, $lbl] = $map[$cond] ?? ['#999', ucfirst($cond)];
    return "<span style='background:{$col}20;color:{$col};border:1px solid {$col};border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;'>{$lbl}</span>";
}

/* ─── mode detection ────────────────────────────────────────────────────── */
$preselectedDevId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$isSingleMode     = $preselectedDevId > 0;
$modeLabel        = $isSingleMode ? 'Single-Device Clearance' : 'IT User Clearance';
$isDone           = isset($_GET['done']) && $_GET['done'] == '1';

/* ─── dynamic sequential control number generator ───────────────────────── */
// Computes an auto-incrementing serial token relative to baseline IT-26-0029
$baseSequence = 29; 
$countPastAssignments = 0;
try {
    $countStmt = $pdo->query("SELECT COUNT(DISTINCT employee_id) FROM device_assignments WHERE status = 'returned'");
    $countPastAssignments = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    // Graceful fallback if historical queries hit schema isolation limits
    $countPastAssignments = 0;
}

// Compute sequence integer assignment
$currentSequenceValue = $baseSequence;

/* ─── POST: process clearance ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_clearance') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request token. Please try again.');
        redirect('it_clearance.php');
    }

    $userId      = (int)($_POST['user_id']      ?? 0);
    $singleDevId = (int)($_POST['single_device_id'] ?? 0);
    $confirm     = isset($_POST['confirm_clearance']);
    $notes       = trim($_POST['clearance_notes'] ?? '');
    $deactivate  = isset($_POST['deactivate_user']) && $singleDevId === 0;
    $returnDate  = trim($_POST['return_date'] ?? date('Y-m-d'));

    if ($userId <= 0)  { $errorMessage = 'Please select a user to clear.'; }
    elseif (!$confirm) { $errorMessage = 'Please confirm that all items are returned and in good condition.'; }
    else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errorMessage = 'Selected user was not found or is no longer active.';
        } else {
            // fetch assignments (all or single)
            if ($singleDevId > 0) {
                $aStmt = $pdo->prepare("
                    SELECT da.*, d.asset_tag, d.id AS device_id, dt.type_name
                    FROM device_assignments da
                    JOIN devices d ON da.device_id = d.id
                    JOIN device_types dt ON d.device_type_id = dt.id
                    WHERE da.employee_id = ? AND da.status = 'active' AND d.id = ?
                ");
                $aStmt->execute([$userId, $singleDevId]);
            } else {
                $aStmt = $pdo->prepare("
                    SELECT da.*, d.asset_tag, d.id AS device_id, dt.type_name
                    FROM device_assignments da
                    JOIN devices d ON da.device_id = d.id
                    JOIN device_types dt ON d.device_type_id = dt.id
                    WHERE da.employee_id = ? AND da.status = 'active'
                ");
                $aStmt->execute([$userId]);
            }
            $assignments = $aStmt->fetchAll(PDO::FETCH_ASSOC);
            $deviceChecklists = $_POST['device_checklist'] ?? [];

            try {
                $pdo->beginTransaction();
                $repairNeeded = false;

                foreach ($assignments as $a) {
                    $deviceId = (int)$a['device_id'];
                    $cl       = $deviceChecklists[$deviceId] ?? [];

                    $conditionLabel = $cl['condition'] ?? 'not_checked';
                    $checkedItems   = $cl['items']     ?? [];
                    $deviceNotes    = trim($cl['notes']      ?? '');
                    $checkedById    = (int)($cl['checked_by'] ?? 0);

                    // Validate that IT Staff is selected for each device
                    if ($checkedById <= 0) {
                        $errorMessage = "Please select an IT Staff member for device: " . $a['asset_tag'];
                        break;
                    }
                    $physMap = [
                        'excellent' => 'good',
                        'good'      => 'good',
                        'fair'      => 'fair',
                        'poor'      => 'damaged',
                        'damaged'   => 'damaged',
                        'not_checked'=>'good',
                    ];
                    $funcMap = [
                        'excellent' => 'working',
                        'good'      => 'working',
                        'fair'      => 'partial',
                        'poor'      => 'not_working',
                        'damaged'   => 'not_working',
                        'not_checked'=>'working',
                    ];
                    $physCond   = $physMap[$conditionLabel] ?? 'good';
                    $funcStatus = $funcMap[$conditionLabel] ?? 'working';
                    $inspResult = (in_array($conditionLabel, ['damaged','poor','defective'])) ? 'failed' : 'passed';
                    $newDevStatus = ($funcStatus === 'not_working' || $physCond === 'damaged' || $physCond === 'defective') ? 'under_repair' : 'in_stock';
                    if ($newDevStatus === 'under_repair') {
                        $repairNeeded = true;
                    }

                    // human-readable condition text
                    $conditionMap = [
                        'excellent'   => 'Excellent',
                        'good'        => 'Good',
                        'fair'        => 'Fair',
                        'poor'        => 'Poor',
                        'damaged'     => 'Damaged',
                        'not_checked' => 'Not Checked',
                    ];
                    $conditionText = $conditionMap[$conditionLabel] ?? 'Not Checked';

                    // resolve checker name for notes
                    $checkedByName = 'N/A';
                    if ($checkedById > 0) {
                        $cbStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                        $cbStmt->execute([$checkedById]);
                        $cbRow = $cbStmt->fetch(PDO::FETCH_ASSOC);
                        if ($cbRow) $checkedByName = $cbRow['full_name'];
                    }

                    // checklist item labels
                    $itemLabels = [];
                    $allItems = [
                        'no_physical_damage'    => 'No physical damage',
                        'screen_intact'         => 'Screen intact',
                        'ports_intact'          => 'All ports intact',
                        'keyboard_intact'       => 'Keyboard functional',
                        'battery_present'       => 'Battery/power adapter present',
                        'charger_returned'      => 'Charger returned',
                        'bag_case_returned'     => 'Bag/case returned',
                        'peripherals_returned'  => 'Peripherals returned',
                        'cables_returned'       => 'Cables/dongles returned',
                        'data_wiped'            => 'Data wiped',
                        'os_intact'             => 'OS in working order',
                        'antivirus_ok'          => 'Antivirus intact',
                        'company_files_removed' => 'Employee files removed',
                    ];
                    foreach ($checkedItems as $key) {
                        if (isset($allItems[$key])) $itemLabels[] = $allItems[$key];
                    }

                    // build notes
                    $returnNotes = "[Clearance {$returnDate}] Condition: {$conditionText} | Functional: {$funcStatus} | Checker: {$checkedByName} (ID:{$checkedById})";
                    if ($itemLabels)  $returnNotes .= " | Passed: " . implode(', ', $itemLabels);
                    if ($deviceNotes) $returnNotes .= " | Remarks: {$deviceNotes}";
                    if ($notes)       $returnNotes .= " | General notes: {$notes}";

                    // 1) assignment
                    $pdo->prepare("
                        UPDATE device_assignments
                        SET status        = 'returned',
                            returned_date = ?,
                            notes         = CONCAT(COALESCE(notes,''), ?)
                        WHERE id = ?
                    ")->execute([$returnDate, "\n{$returnNotes}", $a['id']]);

                    // 2) device
                    $pdo->prepare("
                        UPDATE devices
                        SET status = ?, location = 'IT Stock Room', condition_notes = ?
                        WHERE id = ?
                    ")->execute([$newDevStatus, $returnNotes, $deviceId]);

                    // 3) auto-inspection
                    $pdo->prepare("
                        INSERT INTO device_inspections
                            (device_id, inspected_by, inspection_date, physical_condition, functionality_status, result, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $deviceId, $checkedById, $returnDate,
                        $physCond, $funcStatus, $inspResult,
                        "Auto-logged on " . ($singleDevId ? 'single-device' : 'full') . " clearance. {$returnNotes}"
                    ]);

                    logAudit($_SESSION['user_id'], 'Clearance Return', 'device_assignments', $a['id'], null,
                        ($singleDevId ? 'Single-device' : 'Full') . ' clearance return');

                    // Create repair mapping structural nodes if routing anomalies trigger
                    if ($newDevStatus === 'under_repair') {
                        if (function_exists('ensureDeviceRepairsSchema')) {
                            ensureDeviceRepairsSchema();
                        }

                        try {
                            $repairStmt = $pdo->prepare("INSERT INTO device_repairs
                                (device_id, reported_by, issue_description, severity, issue_category, incident_report_file, repair_status, started_date)
                                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");

                            $repairDesc = "Marked for repair during clearance. " . $returnNotes;
                            $repairStmt->execute([
                                $deviceId,
                                $_SESSION['user_id'] ?? 0,
                                $repairDesc,
                                'high', 
                                'clearance',
                                null
                            ]);
                            $repairId = $pdo->lastInsertId();

                            logAudit($_SESSION['user_id'], 'Auto Create Repair', 'device_repairs', $repairId, null, json_encode(['source'=>'clearance','device'=>$deviceId]));

                            if (function_exists('notifyITStaff')) {
                                notifyITStaff('repair_needed', 'Repair Needed', 'Device marked for repair during clearance: ' . $a['asset_tag'], $deviceId);
                            } else {
                                $itRows = $pdo->query("SELECT id FROM users WHERE role IN ('admin','it_staff') AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($itRows as $row) {
                                    if (function_exists('addSystemNotificationOnlyIfNotExists')) {
                                        addSystemNotificationOnlyIfNotExists($row['id'], 'repair_needed', 'Repair Needed', 'Device marked for repair during clearance: ' . $a['asset_tag'], $repairId);
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Auto-create repair failed: ' . $e->getMessage());
                        }
                    }
                }

                if (!empty($errorMessage)) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    if ($deactivate) {
                        $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")
                            ->execute([$userId]);
                        logAudit($_SESSION['user_id'], 'Offboard User', 'users', $userId, null,
                            'User cleared and marked inactive');
                    }

                    $pdo->commit();

                    $deviceTags = array_map(function($a) {
                        return $a['asset_tag'];
                    }, $assignments);
                    $deviceList = implode(', ', $deviceTags);

                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION['last_clearance_processed'] = [
                        'user' => $user,
                        'devices' => $assignments,
                        'device_tags' => $deviceTags,
                        'device_list' => $deviceList,
                        'notes' => $notes,
                        'date' => date('F j, Y'),
                        'control_no' => 'IT-26-' . str_pad($currentSequenceValue, 4, '0', STR_PAD_LEFT),
                        'message' => $successMessage ?? ''
                    ];

                    addNotificationIfNotExists(
                        $userId,
                        'user_clearance_completed',
                        'Clearance Completed',
                        "Your device(s) {$deviceList} have been returned to stock and cleared by IT.",
                        $userId
                    );
                    
                    notifyITStaff(
                        'user_clearance_completed',
                        'User Clearance Completed',
                        "IT completed clearance for {$user['full_name']} ({$user['employee_id']}) and returned device(s): {$deviceList}.",
                        $user['id']
                    );

                    if ($repairNeeded) {
                        $successMessage = $singleDevId
                            ? 'Single-device clearance completed. Device marked for repair and not returned to stock.'
                            : 'Full clearance completed. Some devices were marked for repair and not returned to stock.';
                    } else {
                        $successMessage = $singleDevId
                            ? 'Single-device clearance completed. Device returned to stock.'
                            : 'Full clearance completed. All assigned devices returned to stock.';
                    }
                    setFlashMessage('success', $successMessage);

                    $qs = "user_id={$userId}" . ($singleDevId ? "&device_id={$singleDevId}" : '') . "&done=1";
                    redirect("it_clearance.php?{$qs}");
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

/* ─── GET params ────────────────────────────────────────────────────────── */
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$preselectedAssignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
if (empty($selectedUserId) && isset($_POST['user_id'])) {
    $selectedUserId = (int)$_POST['user_id'];
}

if ($preselectedAssignmentId > 0) {
    $stmt = $pdo->prepare("SELECT employee_id, device_id FROM device_assignments WHERE id = ? LIMIT 1");
    $stmt->execute([$preselectedAssignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($assignment) {
        if ($selectedUserId === 0) {
            $selectedUserId = (int)$assignment['employee_id'];
        }
        if ($preselectedDevId === 0) {
            $preselectedDevId = (int)$assignment['device_id'];
        }
    }
}

if ($preselectedDevId > 0 && $selectedUserId === 0) {
    $stmt = $pdo->prepare("SELECT employee_id FROM device_assignments WHERE device_id = ? AND status = 'active' ORDER BY assigned_date DESC LIMIT 1");
    $stmt->execute([$preselectedDevId]);
    $assignmentUserId = $stmt->fetchColumn();
    if ($assignmentUserId) {
        $selectedUserId = (int)$assignmentUserId;
    }
}

/* ─── data ──────────────────────────────────────────────────────────────── */
$employees = $pdo->query("
    SELECT id, employee_id, full_name, department, position
    FROM users WHERE status = 'active' AND role = 'employee' ORDER BY full_name
")->fetchAll();

$itStaff = $pdo->query("
    SELECT id, full_name, employee_id FROM users
    WHERE role IN ('admin','it_staff') AND status = 'active' ORDER BY full_name
")->fetchAll();

$selectedUser    = null;
$assignedDevices = [];

if ($selectedUserId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'");
    $stmt->execute([$selectedUserId]);
    $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selectedUser) {
        $assignedDevices = getEmployeeAssignedDevices($selectedUserId);
    }
}

// identify pre-selected device in list
$preselectedDevice = null;
if ($preselectedDevId > 0 && !empty($assignedDevices)) {
    foreach ($assignedDevices as $ad) {
        if ((int)($ad['device_id'] ?? 0) === $preselectedDevId || (int)$ad['id'] === $preselectedDevId) {
            $preselectedDevice = $ad;
            break;
        }
    }
}

/* ─── checklist config ──────────────────────────────────────────────────── */
$checklistGroups = [
    'Physical' => [
        'no_physical_damage'    => 'No physical damage (cracks, dents, scratches)',
        'screen_intact'         => 'Screen / display intact',
        'ports_intact'          => 'All ports intact and functional',
        'keyboard_intact'       => 'Keyboard / input device functional',
        'battery_present'       => 'Battery / power adapter present',
    ],
    'Accessories' => [
        'charger_returned'      => 'Charger / power cable returned',
        'bag_case_returned'     => 'Bag / protective case returned',
        'peripherals_returned'  => 'Mouse / keyboard / peripherals returned',
        'cables_returned'       => 'All cables / dongles returned',
    ],
    'Data & Security' => [
        'data_wiped'            => 'Personal data wiped / account logged out',
        'os_intact'             => 'OS / software in working order',
        'antivirus_ok'          => 'Antivirus / security software intact',
        'company_files_removed' => 'Employee personal files removed',
    ],
];

/* ─── which devices get an editable card? ───────────────────────────────── */
$formDevices = [];
if (!empty($assignedDevices)) {
    foreach ($assignedDevices as $asset) {
        $did = (int)($asset['device_id'] ?? $asset['id']);
        if ($isSingleMode && $did !== $preselectedDevId) continue;
        $formDevices[] = $asset;
    }
}
$totalFormDevices = count($formDevices);

require_once 'includes/header.php';
?>

<!-- ══ PAGE HEADER ════════════════════════════════════════════════════════ -->
<div class="page-header">
    <h1><i class="fas fa-user-check"></i> <?php echo $modeLabel; ?></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($isSingleMode && $selectedUserId): ?>
        <a href="it_clearance.php?user_id=<?php echo $selectedUserId; ?>" class="btn btn-outline no-print">
            <i class="fas fa-users"></i> Full Employee Clearance
        </a>
        <?php endif; ?>
        <button class="btn btn-outline no-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Clearance Form
        </button>
    </div>
</div>

<!-- Mode banner -->
<?php if ($isSingleMode): ?>
<div style="background:#EBF5FB;border:1px solid #3498DB40;border-radius:8px;padding:13px 18px;margin-bottom:18px;font-size:13px;color:#1A5276;" class="no-print">
    <i class="fas fa-info-circle"></i>
    <strong>Single-Device Mode:</strong> Only the pre-selected device will be cleared.
    <?php if (!$isDone): ?>
    To clear <em>all</em> devices for this employee, use
    <a href="it_clearance.php?user_id=<?php echo $selectedUserId; ?>">Full Employee Clearance</a>.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ EMPLOYEE SELECTOR ════════════════════════════════════════════════════ -->
<?php if (!$isSingleMode || !$selectedUserId): ?>
<div class="card no-print">
    <div class="card-header"><h3>Find Employee for Clearance</h3></div>
    <div class="card-body">
        <?php if ($errorMessage && !$selectedUser): ?>
        <div class="alert alert-error"><?php echo sanitize($errorMessage); ?></div>
        <?php endif; ?>
        <form method="GET" action="it_clearance.php" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($isSingleMode): ?>
            <input type="hidden" name="device_id" value="<?php echo $preselectedDevId; ?>">
            <?php endif; ?>
            <div class="form-group" style="flex:1;min-width:220px;">
                <label>Select Employee</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Choose an employee</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $selectedUserId===(int)$emp['id']?'selected':''; ?>>
                        <?php echo sanitize($emp['full_name'].' ('.$emp['employee_id'].')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <button type="submit" class="btn btn-primary">Load User</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<!-- ══ PRINT STYLES & PRINTABLE AREA (True to Example PDF Layout) ═════════ -->
<style>
    /* I-update o idagdag ito sa iyong umiiral na .pc-header */
    .pc-header { 
        text-align: center; 
        margin-bottom: 25px; 
    }
    
    /* Bagong style para sa logo */
    .pc-logo {
        max-height: 55px; /* Sukat ng logo mo */
        width: auto;
        display: block;    /* Pinipilit nito ang kasunod na text na bumaba */
        margin: 0 auto 10px auto; /* Inilalagay ang logo sa gitna at nagbibigay ng 10px na espasyo sa ilalim */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    /* Print Setup Constraints */
    @page { 
        size: portrait; 
        margin: 20mm 15mm 20mm 15mm; 
    }
    
    @media screen {
        .printable-clearance { display: none; }
    }
    
    @media print {
        html, body { 
            height: auto !important; 
            background: #fff !important;
            font-family: Arial, sans-serif !important;
            font-size: 12px !important;
            color: #000 !important;
        }
        body * { visibility: hidden; }
        
        .printable-clearance, .printable-clearance * { visibility: visible; }
        .printable-clearance { 
            display: block !important; 
            position: absolute !important; 
            left: 0 !important; 
            top: 0 !important; 
            width: 100% !important; 
            padding: 0 !important; 
            margin: 0 !important; 
            box-sizing: border-box; 
        }
        .no-print { display: none !important; }
    }

    /* KBMC Branding Stylesheet Configuration */
    .printable-clearance { 
        font-family: Arial, sans-serif; 
        color: #000; 
        background: #fff; 
        line-height: 1.4;
    }
    .pc-header { 
        text-align: center; 
        margin-bottom: 25px; 
    }
    .pc-comp-name { 
        font-size: 9px; 
        color: red;
        font-weight: bold; 
        margin-bottom: 2px; 
        letter-spacing: 0.5px;
    }
    .pc-title { 
        font-weight: bold; 
        font-size: 18px; 
        margin-top: 10px;
        border-bottom: 2px solid #000;
        display: inline-block;
        padding-bottom: 3px;
        margin-bottom: 25px;
    }
    .pc-meta { 
        width: 100%; 
        margin-bottom: 20px; 
        font-size: 12px; 
        border-collapse: collapse;
    }
    .pc-meta td { 
        vertical-align: middle; 
        padding: 5px 0; 
    }
    .pc-meta td strong {
        display: inline-block;
        width: 130px;
    }
    .pc-section-title {
        font-weight: bold;
        font-size: 12px;
        margin-top: 15px;
        margin-bottom: 6px;
    }
    .pc-table { 
        width: 100%; 
        border-collapse: collapse; 
        margin-bottom: 20px; 
        font-size: 12px; 
    }
    .pc-table th { 
        border: 1px solid #000; 
        padding: 7px 9px; 
        font-weight: bold; 
        text-align: left; 
        background-color: #f2f2f2;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .pc-table td { 
        border: 1px solid #000; 
        padding: 7px 9px; 
        vertical-align: middle;
    }
    .text-center { text-align: center !important; }
    
    /* Document Checkbox Style matching "image_6dd18b.png" reference */
    .pc-checkbox-cell {
        font-size: 15px;
        font-family: "Courier New", Courier, monospace;
        text-align: center;
        width: 11%;
        font-weight: bold;
    }
    
    .pc-remarks-label {
        font-weight: bold;
        margin-top: 15px;
        margin-bottom: 5px;
    }
    .pc-remarks { 
        min-height: 60px; 
        border: 1px solid #000; 
        padding: 8px; 
        margin-bottom: 20px; 
        font-size: 12px;
        white-space: pre-wrap;
    }
    .pc-ack-text {
        font-size: 11.5px;
        text-align: justify;
        margin-bottom: 40px;
        line-height: 1.5;
    }
    
    /* PDF Document Mirror Signature Matrix Blocks */
    .pc-sigs-container {
        width: 100%;
        margin-top: 30px;
    }
    .pc-sig-row {
        display: table;
        width: 100%;
        table-layout: fixed;
        margin-bottom: 25px;
    }
    .pc-sig-cell {
        display: table-cell;
        vertical-align: bottom;
        padding-right: 30px;
    }
    .pc-date-cell {
        display: table-cell;
        vertical-align: bottom;
        width: 25%;
    }
    .pc-line {
        border-bottom: 1px solid #000;
        width: 100%;
        margin-bottom: 4px;
        height: 24px;
    }
    .pc-label {
        font-size: 11px;
        font-weight: bold;
    }
</style>

<div class="printable-clearance" id="printable-clearance">
    <div class="pc-header">
        <!-- Idinagdag ang logo dito sa pinakataas -->
        <img src="assets/images/kbmc_logo_flat.png" alt="KBMC Logo" class="pc-logo">
        
        <!-- Ang Text ay nasa ilalim na ngayon ng Logo gamit ang block tag -->
        <div class="pc-comp-name">KITCHEN BEAUTY MARKETING CORPORATION</div>
        
        <!-- Ang Title ng Form -->
        <div class="pc-title">IT PROPERTY CLEARANCE FORM</div>
    </div>

    <?php
    $lastClear = null;
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['last_clearance_processed']) && isset($_GET['done']) && $_GET['done'] == '1') {
        $lastClear = $_SESSION['last_clearance_processed'];
    }

    if ($lastClear) {
        $printDevices = $lastClear['devices'];
        $printUser = $lastClear['user'];
        $displayControlNumber = $lastClear['control_no'];
    } else {
        $printDevices = !empty($formDevices) ? $formDevices : $assignedDevices;
        $printUser = $selectedUser;
        $displayControlNumber = 'IT-26-' . str_pad($currentSequenceValue, 4, '0', STR_PAD_LEFT);
    }
    ?>

    <table class="pc-meta">
        <tr>
            <td style="width: 60%;">
                <strong>Name of Employee:</strong> <?php echo sanitize($printUser['full_name'] ?? ''); ?><br>
                <strong>Department:</strong> <?php echo sanitize($printUser['department'] ?? ''); ?>
            </td>
            <td style="width: 40%; text-align: left; padding-left: 20px;">
                <strong>Control No.:</strong> <?php echo htmlspecialchars($displayControlNumber); ?><br>
                <strong>Date:</strong> <?php echo htmlspecialchars($lastClear['date'] ?? date('F j, Y')); ?>
            </td>
        </tr>
    </table>

    <div class="pc-section-title">IT PROPERTY INFORMATION</div>
    <table class="pc-table">
        <thead>
            <tr>
                <th style="width: 40%;">Property ID</th>
                <th style="width: 38%;">Description</th>
                <th style="width: 11%;" class="text-center">Quantity</th>
                <th style="width: 11%;" class="text-center">Check</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($printDevices)): ?>
            <tr>
                <td colspan="4" class="text-center" style="padding: 15px;">No assigned devices found.</td>
            </tr>
            <?php else: 
                foreach ($printDevices as $d): 
                    $pid = sanitize($d['asset_tag'] ?? ''); 
                    $desc = sanitize($d['type_name'] ?? 'Device');
            ?>
            <tr>
                <td><?php echo $pid; ?></td>
                <td><?php echo $desc; ?></td>
                <td class="text-center">1</td>
                <td class="pc-checkbox-cell">☐</td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="pc-remarks-label">Remarks:</div>
    <div class="pc-remarks"><?php echo nl2br(htmlspecialchars($lastClear['notes'] ?? ($_POST['clearance_notes'] ?? ''))); ?></div>

    <div class="pc-ack-text">
        I, <strong><u><?php echo sanitize($printUser['full_name'] ?? '____________________________'); ?></u></strong>, hereby acknowledge that I have returned all property/property checked above in good condition for return. Nevertheless, through IT inspection, any problems/issues found, the user can be held accountable and endorsed to the HR Department.
    </div>

    <div class="pc-sigs-container">
        <div class="pc-sig-row">
            <div class="pc-sig-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Employee Signature</div>
            </div>
            <div class="pc-date-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Date</div>
            </div>
        </div>

        <div class="pc-sig-row">
            <div class="pc-sig-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Department Head/OIC</div>
            </div>
            <div class="pc-date-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Date</div>
            </div>
        </div>

        <div style="font-weight: bold; margin-top: 15px; margin-bottom: 15px; font-size: 11px;">For IT Personnel only:</div>

        <div class="pc-sig-row">
            <div class="pc-sig-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Checked by</div>
            </div>
            <div class="pc-date-cell">
                <div class="pc-line"></div>
                <div class="pc-label">Date</div>
            </div>
        </div>
    </div>
</div>

<!-- ══ SCREEN DASHBOARD INTERACTIVE VIEW ══════════════════════════════════ -->
<div class="no-print">
    <?php 
    $flashSuccess = getFlashMessage('success');
    $flashError = getFlashMessage('error');
    if ($flashSuccess): 
    ?>
        <div class="alert alert-success"><?php echo sanitize($flashSuccess); ?></div>
    <?php endif; ?>
    <?php if ($flashError || $errorMessage): ?>
        <div class="alert alert-error"><?php echo sanitize($flashError ?: $errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($selectedUser && !$isDone): ?>
    <form method="POST" action="it_clearance.php<?php echo $isSingleMode ? '?device_id='.$preselectedDevId : ''; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <input type="hidden" name="action" value="process_clearance">
        <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
        <?php if ($isSingleMode): ?>
            <input type="hidden" name="single_device_id" value="<?php echo $preselectedDevId; ?>">
        <?php endif; ?>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><h3>Account Details & Global Options</h3></div>
            <div class="card-body" style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="flex:1; min-width:250px;">
                    <p><strong>Employee Name:</strong> <?php echo sanitize($selectedUser['full_name']); ?></p>
                    <p><strong>Employee ID:</strong> <?php echo sanitize($selectedUser['employee_id']); ?></p>
                    <p><strong>Department:</strong> <?php echo sanitize($selectedUser['department']); ?></p>
                    <p><strong>Pending Control No:</strong> <span style="font-family:monospace; font-weight:bold; color:#16a085;"><?php echo 'IT-26-' . str_pad($currentSequenceValue, 4, '0', STR_PAD_LEFT); ?></span></p>
                </div>
                <div style="flex:1; min-width:250px;">
                    <div class="form-group">
                        <label for="return_date">Return Date</label>
                        <input type="date" name="return_date" id="return_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <?php if (!$isSingleMode): ?>
                    <div class="form-group" style="margin-top:15px;">
                        <label>
                            <input type="checkbox" name="deactivate_user" value="1"> 
                            Deactivate employee account upon completing offboarding clearance
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <h2 style="margin:20px 0 10px 0;"><i class="fas fa-laptop"></i> Assigned Equipment Items (<?php echo $totalFormDevices; ?>)</h2>
        
        <?php foreach ($formDevices as $asset): 
            $dId = (int)($asset['device_id'] ?? $asset['id']);
        ?>
        <div class="card" style="margin-bottom:15px; border-left:4px solid #3498db;">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3>Asset Tag: <?php echo sanitize($asset['asset_tag']); ?> — <?php echo sanitize($asset['type_name']); ?></h3>
                <?php if (isset($asset['status']) && $asset['status'] !== 'active'): ?>
                    <?php echo condBadge(sanitize($asset['status'])); ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:15px;">
                    <label>Assigned Device Intake Inspector</label>
                    <select name="device_checklist[<?php echo $dId; ?>][checked_by]" class="form-control" required>
                        <option value="">Select inspecting IT Staff member</option>
                        <?php foreach ($itStaff as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>" <?php echo (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $staff['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize($staff['full_name'] . ' ('.$staff['employee_id'].')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Evaluated Physical Condition Status</label>
                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="excellent" checked> Excellent / Untouched</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="good"> Good Condition</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="fair"> Fair / Operational Wear</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="poor"> Poor Status</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="damaged"> Broken / Physical Damage</label>
                    </div>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Evaluation Checklist Protocol Items</label>
                    <<!-- BAGONG CODE (Naka-uncheck na lahat): -->
<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:8px;">
    <?php foreach ($checklistGroups as $groupLabel => $items): ?>
        <?php foreach ($items as $key => $text): ?>
        <label style="font-weight:normal;">
            <!-- Tinanggal ang salitang 'checked' sa dulo nito -->
            <input type="checkbox" name="device_checklist[<?php echo $dId; ?>][items][]" value="<?php echo $key; ?>">
            <?php echo htmlspecialchars($text); ?>
        </label>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
                </div>

                <div class="form-group">
                    <label>Asset-Specific Diagnostic Notes</label>
                    <input type="text" name="device_checklist[<?php echo $dId; ?>][notes]" class="form-control" placeholder="Optional diagnostics details...">
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card" style="margin-top:20px;">
            <div class="card-header"><h3>Finalize Clearance Form</h3></div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:15px;">
                    <label for="clearance_notes">Global Remarks / Notes</label>
                    <textarea name="clearance_notes" id="clearance_notes" rows="3" class="form-control" placeholder="Add summary global transaction notes here..."></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="confirm_clearance" value="1" required> 
                        <strong>I confirm that the properties marked above have been inspected and logged correctly.</strong>
                    </label>
                </div>
                <div style="margin-top:15px;">
                    <button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> Save and Process Clearance</button>
                </div>
            </div>
        </div>
    </form>
    <?php elseif ($isDone && $selectedUser): ?>
        <div class="card style-success" style="margin-bottom:20px; background:#e8f8f5; border:1px solid #a3e4d7; padding:20px; border-radius:8px;">
            <h3><i class="fas fa-check-circle" style="color:#27ae60;"></i> Transaction Clearance Processed Successfully!</h3>
            <p style="margin-top:5px;">The dynamic tracking states have updated. Click <strong>"Print Clearance Form"</strong> at the header line to output the layout template.</p>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once 'includes/footer.php'; 
?>