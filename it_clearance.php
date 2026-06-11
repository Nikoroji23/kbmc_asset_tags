<?php
/**
 * KBMC Asset Management — IT User Clearance (With Change Request Form Layout)
 *
 * Combines:
 * • Expandable backend tracking & multi-device single mode transactions
 * • High-fidelity printable stylesheet tailored to match the KBMC PDF layout
 * • Includes Checkbox (☐) column adjacent to the RIGHT side of Quantity per design specification
 * • Control Number auto-increments based on systemic records starting from IT-26-0029 baseline
 * • Includes complete replica structure for the two-page IT Property Change Request Form
 */

$pageTitle = 'IT User Clearance';
require_once 'includes/functions.php';
ensureChangeRequestSchema();
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
$baseSequence = 0;
$countPastAssignments = 0;
try {
    $countStmt = $pdo->query("SELECT COUNT(DISTINCT employee_id) FROM device_assignments WHERE status = 'returned'");
    $countPastAssignments = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $countPastAssignments = 0;
}

$currentSequenceValue = $countPastAssignments;

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

    if ($userId <= 0)  { $errorMessage = 'Please select a user to clear.';
    }
    elseif (!$confirm) { $errorMessage = 'Please confirm that all items are returned and in good condition.';
    }
    else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $errorMessage = 'Selected user was not found or is no longer active.';
        } else {
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

                    $conditionMap = [
                        'excellent'   => 'Excellent',
                        'good'        => 'Good',
                        'fair'        => 'Fair',
                        'poor'        => 'Poor',
                        'damaged'     => 'Damaged',
                        'not_checked' => 'Not Checked',
                    ];
                    $conditionText = $conditionMap[$conditionLabel] ?? 'Not Checked';

                    $checkedByName = 'N/A';
                    if ($checkedById > 0) {
                        $cbStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                        $cbStmt->execute([$checkedById]);
                        $cbRow = $cbStmt->fetch(PDO::FETCH_ASSOC);
                        if ($cbRow) $checkedByName = $cbRow['full_name'];
                    }

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

                    $returnNotes = "[Clearance {$returnDate}] Condition: {$conditionText} | Functional: {$funcStatus} | Checker: {$checkedByName} (ID:{$checkedById})";
                    if ($itemLabels)  $returnNotes .= " | Passed: " . implode(', ', $itemLabels);
                    if ($deviceNotes) $returnNotes .= " | Remarks: {$deviceNotes}";
                    if ($notes)       $returnNotes .= " | General notes: {$notes}";

                    $pdo->prepare("
                        UPDATE device_assignments
                        SET status        = 'returned',
                            returned_date = ?,
                            notes         = CONCAT(COALESCE(notes,''), ?)
                        WHERE id = ?
                    ")->execute([$returnDate, "\n{$returnNotes}", $a['id']]);

                    $pdo->prepare("
                        UPDATE devices
                        SET status = ?, location = 'IT Stock Room', condition_notes = ?
                        WHERE id = ?
                    ")->execute([$newDevStatus, $returnNotes, $deviceId]);

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
                        } catch (Exception $e) {
                            error_log('Auto-create repair failed: ' . $e->getMessage());
                        }
                    }
                }

                if (!empty($errorMessage)) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                } else {
                    if ($deactivate) {
                        $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")
                           ->execute([$userId]);
                    }

                    $pdo->commit();
                    $deviceTags = array_map(function($a) { return $a['asset_tag']; }, $assignments);
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
                    
                    $successMessage = $repairNeeded ? 'Clearance completed. Some items under repair.' : 'Clearance completed successfully.';
                    setFlashMessage('success', $successMessage);
                    $qs = "user_id={$userId}" . ($singleDevId ? "&device_id={$singleDevId}" : '') . "&done=1";
                    redirect("it_clearance.php?{$qs}");
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errorMessage = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

/* ─── GET params & Data Fetching ────────────────────────────────────────── */
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$preselectedAssignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

$employees = $pdo->query("SELECT id, employee_id, full_name, department, position FROM users WHERE status = 'active' AND role = 'employee' ORDER BY full_name")->fetchAll();
$itStaff = $pdo->query("SELECT id, full_name, employee_id FROM users WHERE role IN ('admin','it_staff') AND status = 'active' ORDER BY full_name")->fetchAll();
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

$formDevices = [];
if (!empty($assignedDevices)) {
    foreach ($assignedDevices as $asset) {
        $did = (int)($asset['device_id'] ?? $asset['id']);
        if ($isSingleMode && $did !== $preselectedDevId) continue;
        $formDevices[] = $asset;
    }
}
$totalFormDevices = count($formDevices);

$changeRequest = null;
$changeRequestType = '';
$changeRequestDetails = '';
$changeRequestSubmittedAt = '';

if (columnExists('device_assignments', 'change_request_type') && columnExists('device_assignments', 'change_request_details')) {
    if ($preselectedAssignmentId > 0) {
        $stmt = $pdo->prepare(
            "SELECT change_request_type, change_request_details, change_request_pdf_url, change_request_submitted_at
             FROM device_assignments
             WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$preselectedAssignmentId]);
        $changeRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (empty($changeRequest) && $selectedUserId > 0) {
        $stmt = $pdo->prepare(
            "SELECT change_request_type, change_request_details, change_request_pdf_url, change_request_submitted_at
             FROM device_assignments
             WHERE employee_id = ? AND change_request_type IS NOT NULL
             ORDER BY change_request_submitted_at DESC LIMIT 1"
        );
        $stmt->execute([$selectedUserId]);
        $changeRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (empty($changeRequest) && $preselectedDevId > 0) {
        $stmt = $pdo->prepare(
            "SELECT change_request_type, change_request_details, change_request_pdf_url, change_request_submitted_at
             FROM device_assignments
             WHERE device_id = ? AND change_request_type IS NOT NULL
             ORDER BY change_request_submitted_at DESC LIMIT 1"
        );
        $stmt->execute([$preselectedDevId]);
        $changeRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} else {
    error_log('[it_clearance] change_request columns missing, not loading request metadata.');
}

if (!empty($changeRequest)) {
    $changeRequestType = $changeRequest['change_request_type'] ?? '';
    $changeRequestDetails = $changeRequest['change_request_details'] ?? '';
    $changeRequestSubmittedAt = $changeRequest['change_request_submitted_at'] ?? '';
}

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

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-check"></i> <?php echo $modeLabel; ?></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($isSingleMode && $selectedUserId): ?>
        <a href="it_clearance.php?user_id=<?php echo $selectedUserId; ?>" class="btn btn-outline no-print">
            <i class="fas fa-users"></i> Full Employee Clearance
        </a>
        <?php endif; ?>
        
        <button class="btn btn-outline no-print" onclick="printChangeRequestForm()" style="border-color:#2980b9; color:#2980b9;">
            <i class="fas fa-exchange-alt"></i> Print Change Request Form
        </button>
        
        <button class="btn btn-outline no-print" onclick="printClearanceForm()">
            <i class="fas fa-print"></i> Print Clearance Form
        </button>
    </div>
</div>

<style>
    /* Base Global Setup Variables */
    .printable-clearance, .printable-cr-form { display: none; }

    @media print {
        html, body { 
            height: auto !important;
            background: #fff !important;
            font-family: Arial, sans-serif !important;
            font-size: 11px !important;
            color: #000 !important;
            margin: 0;
            padding: 0;
        }
        body * { visibility: hidden; }
        .no-print { display: none !important; }

        /* Conditional Selector Rendering */
        body.print-mode-clearance .printable-clearance, 
        body.print-mode-clearance .printable-clearance * { 
            visibility: visible; 
        }
        body.print-mode-clearance .printable-clearance { 
            display: block !important;
            position: absolute; left: 0; top: 0; width: 100%;
        }

        body.print-mode-cr .printable-cr-form, 
        body.print-mode-cr .printable-cr-form * { 
            visibility: visible; 
        }
        body.print-mode-cr .printable-cr-form { 
            display: block !important;
            position: absolute; left: 0; top: 0; width: 100%;
        }

        .page-break {
            page-break-before: always;
            clear: both;
        }
    }

    /* Common Branding Component Elements */
    .pc-header { text-align: center; margin-bottom: 20px; position: relative; }
    .pc-logo { max-height: 50px; width: auto; display: block; margin: 0 auto 6px auto; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .pc-comp-name { font-size: 10px; font-weight: bold; color: #e74c3c !important; letter-spacing: 0.5px; margin-bottom: 2px; }
    .pc-address { font-size: 9px; color: #555; margin-bottom: 4px; line-height: 1.2; }
    .pc-title { font-weight: bold; font-size: 16px; margin-top: 8px; border-bottom: 2px solid #000; display: inline-block; padding-bottom: 2px; text-transform: uppercase; }

    /* Metadata Layout Grid Elements */
    .pc-meta-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .pc-meta-table td { padding: 4px 6px; border: 1px solid #000; font-size: 11px; vertical-align: middle; }
    .pc-meta-table .bg-gray { background-color: #f2f2f2 !important; font-weight: bold; print-color-adjust: exact; -webkit-print-color-adjust: exact; }

    /* Section Structure Configuration Container */
    .cr-section-header { background: #000 !important; color: #fff !important; font-weight: bold; padding: 5px 8px; font-size: 11px; margin-top: 15px; margin-bottom: 10px; text-transform: uppercase; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    
    /* Standard Layout Tables */
    .pc-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .pc-table th { border: 1px solid #000; padding: 6px 8px; font-weight: bold; background-color: #f2f2f2 !important; text-align: left; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .pc-table td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
    
    /* Document Checkbox Elements */
    .cr-cb-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 5px; }
    .cr-cb-item { display: flex; align-items: center; font-size: 11px; }
    .cr-box { width: 14px; height: 14px; border: 1px solid #000; margin-right: 8px; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; font-size: 11px; }

    /* Text Blocks */
    .cr-textbox { border: 1px solid #000; min-height: 120px; padding: 8px; margin-bottom: 15px; font-size: 11px; width: 100%; box-sizing: border-box; }
    .cr-textbox-sm { border: 1px solid #000; min-height: 50px; padding: 6px; margin-bottom: 15px; font-size: 11px; width: 100%; box-sizing: border-box; }
    
    /* Footer & Signature Component Rows */
    .cr-footer-meta { display: flex; justify-content: space-between; font-size: 9px; color: #555; margin-top: 20px; border-top: 1px dashed #ccc; padding-top: 4px; }
    .cr-sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 30px; }
    .cr-sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 4px; }
    .cr-sig-label { font-size: 10px; font-weight: bold; }
</style>

<?php
// Resolve print dataset variables
$lastClear = null;
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['last_clearance_processed']) && isset($_GET['done']) && $_GET['done'] == '1') {
    $lastClear = $_SESSION['last_clearance_processed'];
}

$printDevices = $lastClear ? $lastClear['devices'] : (!empty($formDevices) ? $formDevices : $assignedDevices);
$printUser = $lastClear ? $lastClear['user'] : $selectedUser;
$displayControlNumber = $lastClear ? $lastClear['control_no'] : 'IT-26-' . str_pad($currentSequenceValue, 4, '0', STR_PAD_LEFT);

$changeRequestDisplayDate = $changeRequestSubmittedAt ? date('Y-m-d', strtotime($changeRequestSubmittedAt)) : date('Y-m-d');

function renderChangeTypeCheckbox($label) {
    global $changeRequestType;
    $checked = ($changeRequestType === $label) ? '☑' : '☐';
    return '<div class="cr-cb-item"><div class="cr-box">' . $checked . '</div>' . htmlspecialchars($label) . '</div>';
}
?>

<div class="printable-clearance">
    <div class="pc-header">
        <img src="assets/images/kbmc_logo_flat.png" alt="KBMC Logo" class="pc-logo">
        <div class="pc-comp-name">KITCHEN BEAUTY MARKETING CORPORATION</div>
        <div class="pc-title">IT PROPERTY CLEARANCE FORM</div>
    </div>

    <table style="width:100%; margin-bottom:15px; font-size:11px;">
        <tr>
            <td style="width: 60%; line-height: 1.5;">
                <strong>Name of Employee:</strong> <?php echo sanitize($printUser['full_name'] ?? ''); ?><br>
                <strong>Department:</strong> <?php echo sanitize($printUser['department'] ?? ''); ?>
            </td>
            <td style="width: 40%; line-height: 1.5; text-align: left; padding-left: 20px;">
                <strong>Control No.:</strong> <?php echo htmlspecialchars($displayControlNumber); ?><br>
                <strong>Date:</strong> <?php echo htmlspecialchars($lastClear['date'] ?? date('F j, Y')); ?>
            </td>
        </tr>
    </table>

    <div style="font-weight:bold; margin-top:15px; margin-bottom:6px; font-size:11px;">IT PROPERTY INFORMATION</div>
    <table class="pc-table">
        <thead>
            <tr>
                <th style="width: 40%;">Property ID</th>
                <th style="width: 38%;">Description</th>
                <th style="width: 11%; text-align:center;">Quantity</th>
                <th style="width: 11%; text-align:center;">Check</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($printDevices)): ?>
            <tr><td colspan="4" style="text-align:center; padding:12px;">No assigned devices found.</td></tr>
            <?php else: foreach ($printDevices as $d): ?>
            <tr>
                <td><?php echo sanitize($d['asset_tag'] ?? ''); ?></td>
                <td><?php echo sanitize($d['type_name'] ?? 'Device'); ?></td>
                <td style="text-align:center;">1</td>
                <td style="text-align:center; font-weight:bold; font-size:14px; font-family:Courier,monospace;">☐</td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div style="font-weight:bold; margin-top:12px; margin-bottom:4px;">Remarks:</div>
    <div style="border:1px solid #000; min-height:50px; padding:6px; font-size:11px; margin-bottom:15px; white-space:pre-wrap;"><?php echo htmlspecialchars($lastClear['notes'] ?? ($_POST['clearance_notes'] ?? '')); ?></div>

    <div style="font-size:11px; text-align:justify; margin-bottom:30px; line-height:1.4;">
        I, <strong><u><?php echo sanitize($printUser['full_name'] ?? '____________________________'); ?></u></strong>, confirm that all listed items have been returned in good condition. Any issues found during IT inspection will be handled according to policy.
    </div>

    <div class="cr-sig-grid" style="margin-bottom: 30px;">
        <div>
            <div style="margin-bottom: 25px;">
                <div class="cr-sig-line" style="max-width: 75%;"></div>
                <div class="cr-sig-label">Employee Signature</div>
            </div>
            <div>
                <div class="cr-sig-line" style="max-width: 75%;"></div>
                <div class="cr-sig-label">Department Head/OIC</div>
            </div>
        </div>
        
        <div>
            <div style="margin-bottom: 25px;">
                <div class="cr-sig-line" style="max-width: 45%"></div>
                <div class="cr-sig-label">Date Signed</div>
            </div>
            <div>
                <div class="cr-sig-line" style="max-width: 45%;"></div>
                <div class="cr-sig-label">Date Signed</div>
            </div>
        </div>
    </div>

    <div style="font-weight:bold; margin: 15px 0 5px 0; font-size:11px;">For IT Personnel only:</div>
    <!-- Wrap both signature and date fields inside the grid layout container -->
    <div class="cr-sig-grid">
        <!-- Left Column -->
        <div>
            <div class="cr-sig-line" style="max-width: 75%"></div>
            <div class="cr-sig-label">Checked by (IT Staff Signature)</div>
        </div>
        <!-- Right Column -->
        <div>
            <div class="cr-sig-line" style="max-width: 45%;"></div>
            <div class="cr-sig-label">Date Signed</div>
        </div>
    </div>
</div>


<div class="printable-cr-form">
    <div class="pc-header">
        <img src="assets/images/kbmc_logo_flat.png" alt="KBMC Logo" class="pc-logo">
        <div class="pc-comp-name">KITCHEN BEAUTY MARKETING CORPORATION</div>
        <div class="pc-address">(632)82421731<br>Camangyanan Road, Sta. Rosa 2, Marilao 3019, Bulacan, Philippines</div>
        <div class="pc-title">CHANGE REQUEST FORM</div>
    </div>

    <table style="width:100%; margin-bottom:15px; font-size:11px; border-collapse:collapse;">
        <tr>
            <td style="text-align: right; font-weight: bold; font-size:11px; padding-bottom:5px;">
                CHANGE REQUEST NO.: <span style="border-bottom: 1px solid #000; padding: 0 15px;"><?php echo str_replace('IT-26-', '', $displayControlNumber); ?></span>
            </td>
        </tr>
    </table>

    <div class="cr-section-header">GENERAL INFORMATION (TO BE ACCOMPLISHED BY CLIENT)</div>
    <table class="pc-meta-table">
        <tr>
            <td class="bg-gray" style="width: 20%;">Requestor Name:</td>
            <td style="width: 45%;"><?php echo sanitize($printUser['full_name'] ?? ''); ?></td>
            <td class="bg-gray" style="width: 15%;">Department:</td>
            <td style="width: 20%;"><?php echo sanitize($printUser['department'] ?? ''); ?></td>
        </tr>
        <tr>
            <td class="bg-gray">E-mail Address:</td>
            <td><?php echo sanitize($printUser['email'] ?? strtolower(str_replace(' ', '', $printUser['full_name'] ?? '')).'@kbmc.com.ph'); ?></td>
            <td class="bg-gray">Date of Request:</td>
            <td><?php echo htmlspecialchars($changeRequestDisplayDate); ?></td>
        </tr>
    </table>

    <div class="cr-section-header">SECTION 1: CHANGE REQUEST (TO BE ACCOMPLISHED BY CLIENT)</div>
    <div style="font-weight: bold; font-size:11px; margin-bottom: 5px;">1.1 Type of Change: <span style="font-weight: normal; font-style: italic;">Please select one (1)</span></div>
    
    <div class="cr-cb-grid">
        <?php echo renderChangeTypeCheckbox('Hardware'); ?>
        <?php echo renderChangeTypeCheckbox('Application / Software'); ?>
        <?php echo renderChangeTypeCheckbox('Email'); ?>
        <?php echo renderChangeTypeCheckbox('Network'); ?>
        <?php echo renderChangeTypeCheckbox('Operating System'); ?>
    </div>

    <div style="font-weight: bold; font-size:11px; margin-top:15px; margin-bottom: 5px;">Please specify the data/setup changes:</div>
    <div class="cr-textbox" style="white-space: pre-wrap;">
<?php 
if ($changeRequestDetails) {
    echo htmlspecialchars($changeRequestDetails) . "\n";
}
?>
    </div>
    <div style="font-size: 10px; font-style: italic; margin-bottom: 25px;">Note: Provide supporting documents/references (if applicable)</div>

    <div class="cr-sig-grid" style="margin-top: 40px;">
        <div>
            <div class="cr-sig-line" style="max-width: 75%;"></div>
            <div class="cr-sig-label">Requestor Signature</div>
        </div>
        <div>
            <div class="cr-sig-line" style="max-width: 45%;"></div>
            <div class="cr-sig-label">Date Signed</div>
        </div>
    </div>

    <div class="cr-footer-meta">
        <div>Change Request Form version 1.1</div>
        <div>Page 1 | 2</div>
    </div>

    <div class="page-break"></div>

    <div style="padding-top: 10px;">
        <div class="cr-section-header">SECTION 2: CHANGE EVALUATION (TO BE ACCOMPLISHED BY IT DEPARTMENT)</div>
        
        <div style="font-weight: bold; font-size:11px; margin-bottom: 5px;">Kindly specify affected modules:</div>
        <div class="cr-textbox-sm"></div>
        <div style="font-size: 10px; font-style: italic; margin-bottom: 20px;">Note: Attach detailed specification of changes</div>

        <table class="pc-meta-table" style="margin-top: 20px;">
            <tr>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Evaluated By:</td>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Date:</td>
            </tr>
            <tr>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Approved By:</td>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Date:</td>
            </tr>
        </table>

        <div style="font-weight: bold; font-size:11px; margin-top:15px; margin-bottom: 5px;">Approver Remarks:</div>
        <div class="cr-textbox" style="min-height: 100px;"></div>

        <table class="pc-meta-table" style="margin-top: 20px;">
            <tr>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Task Assigned To:</td>
                <td class="bg-gray" style="width: 50%; height: 45px; vertical-align: top; padding: 6px;">Date of Deployment:</td>
            </tr>
        </table>
    </div>

    <div class="cr-footer-meta" style="margin-top: 140px;">
        <div>Change Request Form version 1</div>
        <div>Page 2 | 2</div>
    </div>
</div>


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

    <?php if (!$isSingleMode || !$selectedUserId): ?>
    <div class="card">
        <div class="card-header"><h3>Find Employee for Clearance</h3></div>
        <div class="card-body">
            <form method="GET" action="it_clearance.php" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
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
                </div>
            </div>
        </div>

        <h2 style="margin:20px 0 10px 0;"><i class="fas fa-laptop"></i> Assigned Equipment Items (<?php echo $totalFormDevices; ?>)</h2>
        <?php foreach ($formDevices as $asset): $dId = (int)($asset['device_id'] ?? $asset['id']); ?>
        <div class="card" style="margin-bottom:15px; border-left:4px solid #3498db;">
            <div class="card-header">
                <h3>Asset Tag: <?php echo sanitize($asset['asset_tag']); ?> — <?php echo sanitize($asset['type_name']); ?></h3>
            </div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:15px;">
                    <label>Assigned Device Intake Inspector</label>
                    <select name="device_checklist[<?php echo $dId; ?>][checked_by]" class="form-control" required>
                        <option value="">Select inspecting IT Staff member</option>
                        <?php foreach ($itStaff as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>" <?php echo (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $staff['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize($staff['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Evaluated Physical Condition Status</label>
                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="excellent" checked> Excellent</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="good"> Good</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="fair"> Fair</label>
                        <label><input type="radio" name="device_checklist[<?php echo $dId; ?>][condition]" value="damaged"> Damaged</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Asset-Specific Diagnostic Notes</label>
                    <input type="text" name="device_checklist[<?php echo $dId; ?>][notes]" class="form-control">
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card" style="margin-top:20px;">
            <div class="card-header"><h3>Finalize Clearance Form</h3></div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:15px;">
                    <label for="clearance_notes">Global Remarks / Notes</label>
                    <textarea name="clearance_notes" id="clearance_notes" rows="3" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="confirm_clearance" value="1" required> <strong>I confirm that the properties marked above have been inspected and logged correctly.</strong></label>
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
            <p style="margin-top:5px;">Click any button in the top menu bar header line to instantly prompt output of either layout form structure template view.</p>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
function printClearanceForm() {
    document.body.classList.remove('print-mode-cr');
    document.body.classList.add('print-mode-clearance');
    window.print();
}

function printChangeRequestForm() {
    document.body.classList.remove('print-mode-clearance');
    document.body.classList.add('print-mode-cr');
    window.print();
}
</script>

<?php 
require_once 'includes/footer.php'; 
?>