<?php
/**
 * KBMC Asset Management — IT User Clearance (Merged)
 *
 * Combines:
 *   • File-1 per-device expandable checklist UI (13 items, condition pills, progress)
 *   • File-2 backend logic (single-device mode, transactions, device_inspections,
 *     smart status routing, optional deactivation, receipt view, ref numbers)
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
                    SELECT da.*, d.asset_tag, d.id AS device_id, d.vendor, dt.type_name
                    FROM device_assignments da
                    JOIN devices d ON da.device_id = d.id
                    JOIN device_types dt ON d.device_type_id = dt.id
                    WHERE da.employee_id = ? AND da.status = 'active' AND d.id = ?
                ");
                $aStmt->execute([$userId, $singleDevId]);
            } else {
                $aStmt = $pdo->prepare("
                    SELECT da.*, d.asset_tag, d.id AS device_id, d.vendor, dt.type_name
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
                }

                // Check if validation error occurred
                if (!empty($errorMessage)) {
                    $pdo->rollBack();
                } else {
                    // deactivate only on full clearance
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

                    addNotificationIfNotExists(
                        $userId,
                        'user_clearance_completed',
                        'Clearance Completed',
                        "Your device(s) {$deviceList} have been returned to stock and cleared by IT.",
                        $userId
                    );
                    
                    // Employee notification is created above with addNotificationIfNotExists()
                    // which triggers the email via centralized notification helpers.
                    
                    notifyITStaff(
                        'user_clearance_completed',
                        'User Clearance Completed',
                        "IT completed clearance for {$user['full_name']} ({$user['employee_id']}) and returned device(s): {$deviceList}.",
                        $user['id']
                    );
                    
                    // Send email notification to IT staff about clearance completion
                    if (isEmailConfigured()) {
                        $itStaff = $pdo->query("SELECT email, full_name FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active'")->fetchAll();
                        
                        foreach ($itStaff as $staff) {
                            $emailBody = emailTemplate(
                                'User Clearance Completed',
                                "<p>Hello <strong>" . sanitize($staff['full_name']) . "</strong>,</p>
                                <p>A user has completed their IT clearance process.</p>
                                <div style='background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                                    <p><strong>Clearance Details:</strong></p>
                                    <p><i class='fas fa-user'></i> <strong>Employee:</strong> " . sanitize($user['full_name']) . " (ID: " . sanitize($user['employee_id']) . ")</p>
                                    <p><i class='fas fa-building'></i> <strong>Department:</strong> " . sanitize($user['department']) . "</p>
                                    <p><i class='fas fa-laptop'></i> <strong>Devices Processed:</strong> " . count($assignments) . " device(s)</p>
                                    <p><i class='fas fa-list'></i> <strong>Device List:</strong> " . sanitize($deviceList) . "</p>
                                    <p><i class='fas fa-calendar'></i> <strong>Completion Date:</strong> " . date('F d, Y g:i A') . "</p>" .
                                    ($deactivate ? "<p><i class='fas fa-times-circle' style='color: #e74c3c;'></i> <strong>Employee Status:</strong> <span style='color: #e74c3c;'>Deactivated</span></p>" : '') .
                                "</div>
                                <p>All devices have been appropriately routed for stock return or repair. Please verify the status in the system.</p>",
                                'View Clearance Details',
                                'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/it_clearance.php'
                            );
                            sendEmail($staff['email'], 'User Clearance Completed - ' . sanitize($user['full_name']), $emailBody);
                        }
                    }

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
                $pdo->rollBack();
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

/* ─── checklist config (File-1) ─────────────────────────────────────────── */
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
$totalCheckItems = array_sum(array_map('count', $checklistGroups));

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
    The employee's other devices (if any) will remain assigned.
    <?php if (!$isDone): ?>
    To clear <em>all</em> devices for this employee, use
    <a href="it_clearance.php?user_id=<?php echo $selectedUserId; ?>">Full Employee Clearance</a>.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ EMPLOYEE SELECTOR ════════════════════════════════════════════════════ -->
<?php if (!$isSingleMode || !$selectedUserId): ?>
<div class="card no-print">
    <div class="card-header"><h3><?php echo $isSingleMode ? 'Employee' : 'Find Employee for Clearance'; ?></h3></div>
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

<?php if ($selectedUser): ?>

<!-- ══ MAIN CLEARANCE CARD ════════════════════════════════════════════════ -->
<div class="card print-section" style="margin-top:18px;">

    <!-- red header band -->
    <div style="background:var(--kbmc-red);color:#fff;padding:18px 26px;display:flex;align-items:center;gap:14px;border-radius:8px 8px 0 0;">
        <i class="fas fa-user-check" style="font-size:24px;opacity:.9;"></i>
        <div style="flex:1;">
            <div style="font-weight:700;font-size:17px;letter-spacing:.4px;">
                IT <?php echo strtoupper($modeLabel); ?> FORM
            </div>
            <div style="font-size:12px;opacity:.85;">Kidapawan Beneficial Multipurpose Cooperative — IT Department</div>
        </div>
        <div style="text-align:right;font-size:12px;opacity:.9;line-height:1.8;">
            <?php
            $refSuffix = $isSingleMode
                ? 'SD-'.str_pad($preselectedDevId,4,'0',STR_PAD_LEFT)
                : 'EMP-'.str_pad($selectedUserId,4,'0',STR_PAD_LEFT);
            ?>
            <div><strong>Ref #:</strong> CLR-<?php echo $refSuffix; ?></div>
            <div><strong>Date:</strong> <?php echo date('F j, Y'); ?></div>
        </div>
    </div>

    <div style="padding:26px;">

        <?php if ($errorMessage && $selectedUser): ?>
        <div class="alert alert-error no-print" style="margin-bottom:18px;"><?php echo sanitize($errorMessage); ?></div>
        <?php endif; ?>

        <!-- ── Employee details ── -->
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:22px;">
            <?php
            $details = [
                ['Employee ID', $selectedUser['employee_id']],
                ['Full Name',   $selectedUser['full_name']],
                ['Email',       $selectedUser['email']],
                ['Phone',       $selectedUser['phone'] ?? 'N/A'],
                ['Department',  $selectedUser['department']],
                ['Position',    $selectedUser['position']],
                ['Role',        $role_names[$selectedUser['role']] ?? $selectedUser['role']],
                ['Status',      ucfirst($selectedUser['status'])],
            ];
            foreach ($details as [$lbl,$val]): ?>
            <div>
                <div style="font-size:11px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;"><?php echo $lbl; ?></div>
                <p style="margin:6px 0 14px;font-weight:600;"><?php echo sanitize($val ?? 'N/A'); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <hr style="margin:4px 0 22px;border:none;border-top:1px solid #eee;">

        <!-- ── Devices table (all devices) ── -->
        <h4 style="font-size:14px;margin-bottom:14px;font-weight:700;">
            <i class="fas fa-laptop" style="color:var(--kbmc-red);margin-right:5px;"></i>
            <?php echo $isSingleMode ? 'Device Being Cleared' : 'Assigned Devices'; ?>
        </h4>

        <?php if (empty($assignedDevices)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>No active assigned devices</h4>
            <p>There are no active devices currently assigned to this employee.</p>
        </div>
        <?php else: ?>
        <div class="data-table-wrapper" style="margin-bottom:22px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if (!$isSingleMode): ?><th style="width:36px;"></th><?php endif; ?>
                        <th>Asset Tag</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Assigned Date</th>
                        <?php if ($isSingleMode): ?><th>Clearance</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignedDevices as $asset):
                        $did = (int)($asset['device_id'] ?? $asset['id']);
                        $isTarget = $isSingleMode && $did === $preselectedDevId;
                        $rowStyle = $isTarget ? 'background:#FEF9E7;' : ($isSingleMode ? 'opacity:.6;' : '');
                    ?>
                    <tr id="device-row-<?php echo $did; ?>" style="<?php echo $rowStyle; ?>">
                        <?php if (!$isSingleMode): ?>
                        <td style="text-align:center;"><i class="fas fa-check-circle" style="color:#27AE60;"></i></td>
                        <?php endif; ?>
                        <td><strong><?php echo sanitize($asset['asset_tag']); ?></strong></td>
                        <td><?php echo sanitize($asset['type_name'] ?? ''); ?></td>
                        <td><?php echo getStatusBadgeHtml($asset['status']); ?></td>
                        <td><?php echo formatDate($asset['assigned_date'] ?? ''); ?></td>
                        <?php if ($isSingleMode): ?>
                        <td>
                            <?php if ($isTarget): ?>
                            <span style="background:#F39C1220;color:#F39C12;border:1px solid #F39C12;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;">← THIS DEVICE</span>
                            <?php else: ?>
                            <span style="color:#aaa;font-size:12px;">Not affected</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── Editable clearance form ── -->
        <?php if (!$isDone && !empty($assignedDevices)): ?>
        <form method="POST" id="clearanceForm">
            <?php echo csrfInputField(); ?>
            <input type="hidden" name="action" value="process_clearance">
            <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
            <?php if ($isSingleMode): ?>
            <input type="hidden" name="single_device_id" value="<?php echo $preselectedDevId; ?>">
            <?php endif; ?>

            <p style="color:#555;font-size:13px;margin-bottom:16px;" class="no-print">
                <i class="fas fa-info-circle" style="color:#3498db;"></i>
                Expand each device card and complete the return checklist before submitting.
            </p>

            <!-- ===== PER-DEVICE CARDS (File-1 UI) ===== -->
            <?php if (empty($formDevices)): ?>
            <div class="empty-state no-print">
                <i class="fas fa-inbox"></i>
                <h4>No devices to clear</h4>
                <p>The selected device is not currently assigned to this employee.</p>
            </div>
            <?php else: ?>
                <?php foreach ($formDevices as $asset):
                    $did = (int)($asset['device_id'] ?? $asset['id']);
                ?>
                <div class="device-card" id="card-<?php echo $did; ?>" style="border:1px solid #e0e0e0;border-radius:8px;margin-bottom:20px;overflow:hidden;">

                    <!-- Card Header -->
                    <div style="background:#f8f9fa;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid #e0e0e0;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:38px;height:38px;background:#3498db18;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-laptop" style="color:#3498db;font-size:16px;"></i>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:15px;">
                                    <?php echo sanitize($asset['type_name'] ?? 'Device'); ?>
                                </div>
                                <div style="font-size:12px;color:#888;">
                                    <span style="background:#eee;padding:2px 7px;border-radius:4px;margin-right:6px;"><?php echo sanitize($asset['asset_tag']); ?></span>
                                    &nbsp;&bull;&nbsp; Assigned: <?php echo formatDate($asset['assigned_date'] ?? ''); ?>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;" class="no-print">
                            <span id="prog-<?php echo $did; ?>" class="progress-badge" style="font-size:12px;color:#888;">
                                0 / <?php echo $totalCheckItems; ?> checked
                            </span>
                            <button type="button" class="btn btn-outline btn-sm expand-btn" data-id="<?php echo $did; ?>"
                                    style="font-size:12px;padding:4px 12px;" onclick="toggleCard(<?php echo $did; ?>)">
                                <i class="fas fa-chevron-down" id="icon-<?php echo $did; ?>"></i> Expand
                            </button>
                        </div>
                    </div>

                    <!-- Expandable Body -->
                    <div id="body-<?php echo $did; ?>" style="display:none;">
                        <div style="padding:18px;">

                            <!-- Condition Rating -->
                            <div style="margin-bottom:20px;">
                                <div class="section-label" style="margin-bottom:10px;">
                                    <i class="fas fa-star" style="color:#f39c12;"></i> Overall Device Condition
                                    <span style="color:#e74c3c;font-size:12px;font-weight:400;margin-left:6px;">* Required</span>
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <?php
                                    $conditions = [
                                        'excellent' => ['Excellent','#27AE60'],
                                        'good'      => ['Good',     '#2ecc71'],
                                        'fair'      => ['Fair',     '#f39c12'],
                                        'poor'      => ['Poor',     '#e67e22'],
                                        'damaged'   => ['Damaged',  '#e74c3c'],
                                    ];
                                    foreach ($conditions as $val => [$label,$color]):
                                    ?>
                                    <label class="condition-pill" data-color="<?php echo $color; ?>"
                                           style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;border:2px solid <?php echo $color; ?>22;background:<?php echo $color; ?>12;font-size:13px;transition:all .15s;user-select:none;">
                                        <input type="radio"
                                               name="device_checklist[<?php echo $did; ?>][condition]"
                                               value="<?php echo $val; ?>"
                                               class="condition-radio" data-device="<?php echo $did; ?>"
                                               style="width:14px;height:14px;accent-color:<?php echo $color; ?>;" required>
                                        <span style="color:<?php echo $color; ?>;font-weight:600;"><?php echo $label; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Checklist Groups -->
                            <div class="section-label" style="margin-bottom:10px;">
                                <i class="fas fa-clipboard-check" style="color:#3498db;"></i> Return Checklist
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;margin-bottom:18px;">
                                <?php foreach ($checklistGroups as $groupName => $items):
                                    $icons = ['Physical'=>'fa-shield-alt','Accessories'=>'fa-box-open','Data & Security'=>'fa-lock'];
                                    $icon  = $icons[$groupName] ?? 'fa-list';
                                ?>
                                <div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:13px;">
                                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#3498db;margin-bottom:10px;">
                                        <i class="fas <?php echo $icon; ?>"></i> <?php echo $groupName; ?>
                                    </div>
                                    <?php foreach ($items as $itemKey => $itemLabel): ?>
                                    <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;cursor:pointer;font-size:13px;color:#333;line-height:1.4;">
                                        <input type="checkbox"
                                               name="device_checklist[<?php echo $did; ?>][items][]"
                                               value="<?php echo $itemKey; ?>"
                                               class="item-cb" data-device="<?php echo $did; ?>"
                                               style="margin-top:2px;width:15px;height:15px;accent-color:#27AE60;flex-shrink:0;">
                                        <?php echo sanitize($itemLabel); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Checked By + Remarks -->
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div>
                                    <label class="section-label" style="display:block;margin-bottom:6px;">
                                        <i class="fas fa-user-shield" style="color:#3498db;"></i> Checked By (IT Staff)
                                        <span style="color:#e74c3c;font-size:12px;font-weight:700;margin-left:4px;">*</span>
                                    </label>
                                    <select name="device_checklist[<?php echo $did; ?>][checked_by]" class="form-control" style="font-size:13px;" required>
                                        <option value="">— Select IT Staff —</option>
                                        <?php foreach ($itStaff as $staff): ?>
                                        <option value="<?php echo $staff['id']; ?>"
                                            <?php echo ($staff['id'] == ($_SESSION['user_id']??0)) ? 'selected' : ''; ?>>
                                            <?php echo sanitize($staff['full_name'] . ' (' . $staff['employee_id'] . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="section-label" style="display:block;margin-bottom:6px;">
                                        <i class="fas fa-comment-alt" style="color:#3498db;"></i> Device Remarks
                                    </label>
                                    <textarea name="device_checklist[<?php echo $did; ?>][notes]"
                                              class="form-control" rows="2" style="font-size:13px;"
                                              placeholder="Missing accessories, visible damage, etc."></textarea>
                                </div>
                            </div>

                        </div><!-- /padding -->

                        <!-- Collapsed hint -->
                        <div id="hint-<?php echo $did; ?>" style="padding:9px 18px;font-size:12px;color:#bbb;border-top:1px solid #f0f0f0;" class="no-print">
                            <i class="fas fa-info-circle"></i> Click <strong>Expand</strong> to complete the return checklist for this device.
                        </div>

                    </div><!-- /device-card -->
                    <?php endforeach; ?>

                </div>

                <!-- Static info rows -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                    <div>
                        <div style="font-size:11px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Employee</div>
                        <p style="margin:6px 0;font-weight:600;"><?php echo sanitize($selectedUser['full_name']); ?></p>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Clearance Date</div>
                        <p style="margin:6px 0;font-weight:600;"><?php echo date('F j, Y'); ?></p>
                    </div>
                </div>

                <!-- Signature block -->
                <div style="margin-bottom:28px;">
                    <div style="font-size:13px;font-weight:700;color:#2c3e50;margin-bottom:16px;text-transform:uppercase;letter-spacing:.5px;">Authorized Signatures & Certification</div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;">
                        <!-- Employee Signature Box -->
                        <div style="border:1px solid #ddd;border-radius:6px;padding:16px;background:#fafbfc;">
                            <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Employee / User</div>
                            <div style="border-bottom:2px solid #333;height:70px;margin-bottom:12px;"></div>
                            <div style="margin-bottom:10px;">
                                <div style="font-size:12px;font-weight:700;color:#222;"><?php echo sanitize($selectedUser['full_name']); ?></div>
                                <div style="font-size:11px;color:#666;margin-top:2px;"><?php echo sanitize($selectedUser['position'] ?? ''); ?></div>
                                <div style="font-size:11px;color:#666;"><?php echo sanitize($selectedUser['employee_id']); ?></div>
                            </div>
                            <div style="font-size:10px;color:#999;margin-top:8px;">
                                <div style="font-weight:600;color:#666;">Date:</div>
                                <div style="border-bottom:1px solid #ccc;height:18px;margin-top:2px;"></div>
                            </div>
                        </div>

                        <!-- IT Staff Signature Box -->
                        <div style="border:1px solid #ddd;border-radius:6px;padding:16px;background:#fafbfc;">
                            <div style="font-size:10px;color:#666;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">IT Staff Signature</div>
                            <div style="border-bottom:2px solid #333;height:70px;margin-bottom:12px;"></div>
                            <div style="margin-bottom:10px;">
                                <div style="font-size:12px;font-weight:700;color:#222;"><?php echo sanitize($_SESSION['full_name'] ?? ''); ?></div>
                                <div style="font-size:11px;color:#666;margin-top:2px;">IT Department</div>
                                <div style="font-size:11px;color:#666;"><?php echo sanitize($_SESSION['employee_id'] ?? 'N/A'); ?></div>
                            </div>
                            <div style="font-size:10px;color:#999;margin-top:8px;">
                                <div style="font-weight:600;color:#666;">Date:</div>
                                <div style="border-bottom:1px solid #ccc;height:18px;margin-top:2px;"></div>
                            </div>
                        </div>

                        <!-- Supervisor Signature Box -->
                        <div style="border:1px solid #ddd;border-radius:6px;padding:16px;background:#fafbfc;">
                            <div style="font-size:10px;color:#999;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">Name (Please print):</div>
                            <div style="border-bottom:1px solid #ccc;height:16px;margin-bottom:12px;"></div>
                            <div style="margin-bottom:10px;">
                                <div style="font-size:10px;color:#999;font-weight:600;">Signature:</div>
                            </div>
                            <div style="font-size:10px;color:#999;margin-top:8px;">
                                <div style="font-weight:600;color:#666;">Date:</div>
                                <div style="border-bottom:1px solid #ccc;height:18px;margin-top:2px;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Deactivate (full mode only) -->
                <?php if (!$isSingleMode): ?>
                <div class="form-group" style="margin-bottom:18px;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="deactivate_user" value="1"
                               <?php echo isset($_POST['deactivate_user'])?'checked':''; ?>>
                        <span>Mark employee account as <strong>Inactive</strong> after clearance (offboarding)</span>
                    </label>
                </div>
                <?php endif; ?>

                <!-- General notes -->
                <div class="form-group" style="margin-bottom:18px;">
                    <label class="form-label">
                        <?php echo $isSingleMode ? 'Return Notes' : 'Clearance Notes'; ?> <em style="font-weight:400;color:#888;">(optional)</em>
                    </label>
                    <textarea name="clearance_notes" class="form-control" rows="3"
                              placeholder="Overall handover remarks, missing accessories across all devices, or special notes…"><?php echo htmlspecialchars($_POST['clearance_notes'] ?? ''); ?></textarea>
                </div>

                <!-- Confirmation -->
                <div style="background:#fef9f0;border:1px solid #F39C12;border-radius:8px;padding:13px 18px;margin-bottom:20px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
                        <input type="checkbox" name="confirm_clearance" id="confirm_clearance" value="1" required
                               style="margin-top:3px;width:16px;height:16px;flex-shrink:0;"
                               <?php echo isset($_POST['confirm_clearance'])?'checked':''; ?>>
                        <span style="font-size:13px;color:#7D6608;">
                            <strong>Confirmation:</strong>
                            <?php if ($isSingleMode): ?>
                            I confirm the selected device has been physically received, inspected, and the per-device checklist above is accurate.
                            <?php else: ?>
                            I confirm all assigned devices are returned and the per-device checklists above are accurate.
                            The employee has been cleared by the IT Department.
                            <?php endif; ?>
                        </span>
                    </label>
                </div>

                <!-- Submit -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;" class="no-print">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-check"></i>
                        <?php echo $isSingleMode ? 'Complete Single-Device Clearance' : 'Complete Full Employee Clearance'; ?>
                    </button>
                    <button type="button" class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Form
                    </button>
                </div>
            </div><!-- /Authorization -->

            <!-- Overall progress indicator (File-1) -->
            <div id="overall-progress" class="no-print" style="background:#f0f8ff;border:1px solid #bee3f8;border-radius:8px;padding:12px 16px;margin-top:16px;display:flex;align-items:center;gap:12px;">
                <i class="fas fa-tasks" style="color:#3498db;font-size:18px;"></i>
                <div>
                    <div style="font-weight:600;font-size:13px;color:#2c3e50;">Checklist Progress</div>
                    <div id="progress-detail" style="font-size:12px;color:#888;">Expand each device above to fill in return details.</div>
                </div>
                <div style="margin-left:auto;text-align:center;">
                    <span id="progress-count" style="font-size:22px;font-weight:700;color:#3498db;">0</span>
                    <span style="font-size:12px;color:#888;"> / <?php echo $totalFormDevices; ?> devices reviewed</span>
                </div>
            </div>

            <?php endif; ?>
        </form>

        <!-- ── Post-clearance receipt (File-2) ── -->
        <?php else: /* $isDone */ 
            // Ensure we have the user's assigned devices for the receipt
            if (empty($assignedDevices) && $selectedUserId > 0) {
                $assignedDevices = getEmployeeAssignedDevices($selectedUserId);
            }
            $assignments = $assignedDevices ?? [];
        ?>
        <div style="background:#EAFAF1;border:1px solid #27AE6040;border-radius:8px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#1E8449;">
            <i class="fas fa-check-circle"></i>
            <strong>Clearance completed successfully.</strong>
            <?php echo $isSingleMode ? 'The selected device has been returned to stock or sent for repair as needed.' : 'Assigned devices have been returned to stock or sent for repair as needed.'; ?>
        </div>

        <div style="background:linear-gradient(135deg, #2c3e50 0%, #34495e 100%);border-radius:8px;padding:24px;margin-bottom:28px;color:white;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:16px;">
                <div>
                    <div style="font-size:10px;color:rgba(255,255,255,0.7);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Employee</div>
                    <div style="font-size:16px;font-weight:700;"><?php echo sanitize($selectedUser['full_name']); ?></div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.8);margin-top:4px;margin-bottom:2px;"><?php echo sanitize($selectedUser['position'] ?? ''); ?> • <?php echo sanitize($selectedUser['department']); ?></div>
                    <div style="font-size:10px;color:rgba(255,255,255,0.6);">ID: <?php echo sanitize($selectedUser['employee_id']); ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:10px;color:rgba(255,255,255,0.7);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Clearance Date</div>
                    <div style="font-size:18px;font-weight:700;"><?php echo date('F j, Y'); ?></div>
                    <div style="font-size:10px;color:rgba(255,255,255,0.6);margin-top:6px;">Clearance Reference: CLR-<?php echo $refSuffix; ?></div>
                </div>
            </div>
            <div style="border-top:1px solid rgba(255,255,255,0.2);padding-top:16px;">
                <div style="font-size:11px;color:rgba(255,255,255,0.8);display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-check-circle" style="color:#27ae60;"></i>
                    <span><?php echo $isSingleMode ? 'Single-device clearance completed' : 'Full employee clearance completed'; ?> • Devices processed: <?php echo count($assignments); ?></span>
                </div>
            </div>
        </div>

        <!-- Device Condition Summary Section -->
        <div style="background:linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%);border:1px solid #d1dce6;border-radius:8px;padding:20px 24px;margin-bottom:32px;">
            <div style="display:flex;align-items:center;margin-bottom:16px;">
                <div style="flex:1;">
                    <div style="font-size:14px;font-weight:700;color:#2c3e50;text-transform:uppercase;letter-spacing:.6px;">Device Status Summary</div>
                </div>
                <div style="font-size:11px;color:#888;font-weight:600;"><?php echo date('F j, Y'); ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="background:white;border-left:4px solid #27ae60;padding:12px 14px;border-radius:4px;">
                    <div style="font-size:11px;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Returned to Stock</div>
                    <div style="font-size:18px;font-weight:700;color:#27ae60;">
                        <?php 
                            $inStockCount = 0;
                            $repairCount = 0;
                            if ($selectedUserId > 0) {
                                // Query devices that have been returned (device_assignments.status = 'returned')
                                if ($isSingleMode && $preselectedDevId > 0) {
                                    // Single device mode
                                    $countStmt = $pdo->prepare("
                                        SELECT 
                                            SUM(CASE WHEN d.status = 'in_stock' THEN 1 ELSE 0 END) as in_stock,
                                            SUM(CASE WHEN d.status = 'under_repair' THEN 1 ELSE 0 END) as under_repair
                                        FROM device_assignments da
                                        JOIN devices d ON da.device_id = d.id
                                        WHERE da.employee_id = ? AND da.status = 'returned' AND d.id = ?
                                    ");
                                    $countStmt->execute([$selectedUserId, $preselectedDevId]);
                                } else {
                                    // Full employee clearance
                                    $countStmt = $pdo->prepare("
                                        SELECT 
                                            SUM(CASE WHEN d.status = 'in_stock' THEN 1 ELSE 0 END) as in_stock,
                                            SUM(CASE WHEN d.status = 'under_repair' THEN 1 ELSE 0 END) as under_repair
                                        FROM device_assignments da
                                        JOIN devices d ON da.device_id = d.id
                                        WHERE da.employee_id = ? AND da.status = 'returned'
                                    ");
                                    $countStmt->execute([$selectedUserId]);
                                }
                                $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
                                $inStockCount = (int)($counts['in_stock'] ?? 0);
                                $repairCount = (int)($counts['under_repair'] ?? 0);
                            }
                            echo $inStockCount;
                        ?>
                    </div>
                </div>
                <div style="background:white;border-left:4px solid #e74c3c;padding:12px 14px;border-radius:4px;">
                    <div style="font-size:11px;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Marked for Repair</div>
                    <div style="font-size:18px;font-weight:700;color:#e74c3c;">
                        <?php echo $repairCount; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page Separator -->
        <div style="border-top:3px solid #2c3e50;margin:40px 0;"></div>

        <!-- Authorized Signatures Header -->
        <div class="sig-header-section" style="background:linear-gradient(135deg, #2c3e50 0%, #34495e 100%);border-radius:8px;padding:24px;margin-bottom:24px;color:white;">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
                <div style="font-size:28px;color:#3498db;">
                    <i class="fas fa-file-signature"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:18px;font-weight:700;margin-bottom:4px;">Authorized Signatures & Certification</div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.8);">All signatories must complete this clearance documentation</div>
                </div>
                <div style="text-align:right;border-left:1px solid rgba(255,255,255,0.2);padding-left:20px;">
                    <div style="font-size:10px;color:rgba(255,255,255,0.7);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Reference</div>
                    <div style="font-size:16px;font-weight:700;">CLR-<?php echo $refSuffix; ?></div>
                </div>
            </div>
        </div>

        <!-- Signees Information Grid (Screen Only) -->
        <div class="signee-info-grid" style="background:#f8f9fa;border:1px solid #e0e6ed;border-radius:8px;padding:24px;margin-bottom:32px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-bottom:24px;">
                <!-- Employee Info -->
                <div style="border-right:1px solid #e0e6ed;padding-right:20px;">
                    <div style="font-size:9px;color:#7f8c8d;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-user" style="color:#3498db;"></i> Employee / User
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Full Name</div>
                        <div style="font-size:14px;font-weight:700;color:#2c3e50;"><?php echo sanitize($selectedUser['full_name']); ?></div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Employee ID</div>
                        <div style="font-size:13px;color:#3498db;font-weight:600;"><?php echo sanitize($selectedUser['employee_id']); ?></div>
                    </div>
                    <div style="margin-bottom:0;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Department</div>
                        <div style="font-size:12px;color:#555;"><?php echo sanitize($selectedUser['department']); ?></div>
                    </div>
                </div>

                <!-- IT Staff Info -->
                <div style="border-right:1px solid #e0e6ed;padding-right:20px;">
                    <div style="font-size:9px;color:#7f8c8d;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-shield-alt" style="color:#27ae60;"></i> IT Staff Approval
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Full Name</div>
                        <div style="font-size:14px;font-weight:700;color:#2c3e50;"><?php echo sanitize($_SESSION['full_name']); ?></div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Employee ID</div>
                        <div style="font-size:13px;color:#27ae60;font-weight:600;"><?php echo sanitize($_SESSION['employee_id'] ?? 'N/A'); ?></div>
                    </div>
                    <div style="margin-bottom:0;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Department</div>
                        <div style="font-size:12px;color:#555;">IT Department</div>
                    </div>
                </div>

                <!-- Supervisor Info -->
                <div style="padding-right:0;">
                    <div style="font-size:9px;color:#7f8c8d;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-check-double" style="color:#9b59b6;"></i> Supervisor / Manager
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Full Name</div>
                        <div style="font-size:14px;font-weight:700;color:#2c3e50;">_____________________</div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Employee ID</div>
                        <div style="font-size:13px;color:#9b59b6;font-weight:600;">_____________________</div>
                    </div>
                    <div style="margin-bottom:0;">
                        <div style="font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Department</div>
                        <div style="font-size:12px;color:#555;">_____________________</div>
                    </div>
                </div>
            </div>

            <!-- Clearance Metadata -->
            <div style="border-top:1px solid #e0e6ed;padding-top:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div>
                    <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Clearance Type</div>
                    <div style="font-size:12px;color:#2c3e50;font-weight:600;"><?php echo $isSingleMode ? 'Single-Device' : 'Full Employee'; ?></div>
                </div>
                <div>
                    <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Devices Processed</div>
                    <div style="font-size:12px;color:#2c3e50;font-weight:600;"><?php echo count($assignments); ?> device(s)</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:9px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;">Completion Date</div>
                    <div style="font-size:12px;color:#2c3e50;font-weight:600;"><?php echo date('F j, Y'); ?></div>
                </div>
            </div>
        </div>

        <!-- Signature Boxes (Screen Version) and Print-Simple (Print Version) -->
        <div style="margin-bottom:28px;">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;">
                <!-- Employee Signature Box -->
                <div class="signature-box" style="border:2px solid #3498db;border-radius:8px;padding:24px;background:linear-gradient(to bottom, #f0f8ff, white);box-shadow:0 4px 8px rgba(52,152,219,0.12);">
                    <div class="sig-header" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #3498db;">
                        <i class="fas fa-user" style="color:#3498db;font-size:14px;"></i>
                        <div style="font-size:9px;color:#3498db;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">Employee Signature</div>
                    </div>
                    <div class="sig-info" style="margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #e0e6ed;">
                        <div style="font-size:12px;font-weight:700;color:#2c3e50;margin-bottom:6px;"><?php echo sanitize($selectedUser['full_name']); ?></div>
                        <div style="font-size:10px;color:#7f8c8d;">ID: <?php echo sanitize($selectedUser['employee_id']); ?></div>
                    </div>
                    <div class="sig-area" style="margin-bottom:16px;">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:8px;">Signature:</div>
                        <div style="border-bottom:2px solid #333;height:80px;background:white;border-radius:2px;"></div>
                    </div>
                    <div class="sig-date">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:6px;">Date:</div>
                        <div style="border-bottom:1px solid #bdc3c7;height:18px;"></div>
                    </div>
                </div>

                <!-- IT Staff Signature Box -->
                <div class="signature-box" style="border:2px solid #27ae60;border-radius:8px;padding:24px;background:linear-gradient(to bottom, #f0fdf4, white);box-shadow:0 4px 8px rgba(39,174,96,0.12);">
                    <div class="sig-header" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #27ae60;">
                        <i class="fas fa-shield-alt" style="color:#27ae60;font-size:14px;"></i>
                        <div style="font-size:9px;color:#27ae60;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">IT Staff Approval</div>
                    </div>
                    <div class="sig-info" style="margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #e0e6ed;">
                        <div style="font-size:12px;font-weight:700;color:#2c3e50;margin-bottom:6px;"><?php echo sanitize($_SESSION['full_name']); ?></div>
                        <div style="font-size:10px;color:#7f8c8d;">ID: <?php echo sanitize($_SESSION['employee_id'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="sig-area" style="margin-bottom:16px;">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:8px;">Signature:</div>
                        <div style="border-bottom:2px solid #333;height:80px;background:white;border-radius:2px;"></div>
                    </div>
                    <div class="sig-date">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:6px;">Date:</div>
                        <div style="border-bottom:1px solid #bdc3c7;height:18px;"></div>
                    </div>
                </div>

                <!-- Supervisor Signature Box -->
                <div class="signature-box" style="border:2px solid #9b59b6;border-radius:8px;padding:24px;background:linear-gradient(to bottom, #fdf7ff, white);box-shadow:0 4px 8px rgba(155,89,182,0.12);">
                    <div class="sig-header" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #9b59b6;">
                        <i class="fas fa-check-double" style="color:#9b59b6;font-size:14px;"></i>
                        <div style="font-size:9px;color:#9b59b6;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">Supervisor Authorization</div>
                    </div>
                    <div class="sig-info" style="margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #e0e6ed;">
                        <div style="font-size:10px;color:#7f8c8d;font-weight:600;">Name (Please print):</div>
                        <div style="border-bottom:1px solid #bdc3c7;height:18px;margin-top:4px;"></div>
                    </div>
                    <div class="sig-area" style="margin-bottom:16px;">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:8px;">Signature:</div>
                        <div style="border-bottom:2px solid #333;height:80px;background:white;border-radius:2px;"></div>
                    </div>
                    <div class="sig-date">
                        <div style="font-size:9px;color:#666;font-weight:600;margin-bottom:6px;">Date:</div>
                        <div style="border-bottom:1px solid #bdc3c7;height:18px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div style="background:#f8f9fa;border-radius:6px;padding:10px 14px;font-size:11px;color:#666;text-align:center;">
            Ref: CLR-<?php echo $refSuffix; ?> &nbsp;&middot;&nbsp;
            <?php echo $isSingleMode ? 'Single-device clearance' : 'Full employee clearance'; ?> &nbsp;&middot;&nbsp;
            Printed: <?php echo date('F j, Y g:i A'); ?>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;" class="no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Clearance Form
            </button>
            <?php if ($isSingleMode): ?>
            <a href="it_clearance.php?user_id=<?php echo $selectedUserId; ?>" class="btn btn-outline">
                <i class="fas fa-user-check"></i> Full Employee Clearance
            </a>
            <?php endif; ?>
            <a href="it_clearance.php" class="btn btn-outline">
                <i class="fas fa-user-plus"></i> New Clearance
            </a>
        </div>
        <?php endif; /* isDone */ ?>

    </div><!-- /padding -->
</div><!-- /card -->

<?php endif; /* selectedUser */ ?>

<!-- ══ STYLES ═══════════════════════════════════════════════════════════════ -->
<style>
/* Shared label/value */
.cl-label { font-size:11px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px; }
.cl-value  { margin:8px 0 16px;font-weight:600; }
.section-label { font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555; }

/* Condition pills */
.condition-pill { transition:all .15s; }
.condition-pill:hover { filter:brightness(.96); }

/* Print */
@media print {
    .no-print, .sidebar, .top-header, .app-footer, .btn,
    .alert, .form-control, select, input[type="text"], input[type="date"],
    textarea, .card-header a { display: none !important; }
    .print-only{ display:block !important; }
    .main-wrapper { margin: 0; padding: 0; }
    .page-header, .card { box-shadow: none !important; border: none !important; }
    .print-section { width: 100%; }
    body { font-size: 12px; }
    .device-card { break-inside: avoid; page-break-inside: avoid; }
    [id^="body-"] { display: none !important; }
    [id^="hint-"] { display: none !important; }
    
    /* Print: Collapse device cards to header only */
    .device-card { border: none !important; margin-bottom: 12px !important; }
    .device-card > div:first-child {
        background: #f5f5f5 !important;
        border: 1px solid #ddd !important;
        border-radius: 4px !important;
        padding: 10px 14px !important;
    }
    
    /* Print: Show signee info grid */
    .signee-info-grid { display: block !important; }
    
    /* Print: Show signature boxes with minimal styling */
    .signature-box { 
        border: 1px solid #333 !important; 
        background: white !important; 
        padding: 16px !important; 
        box-shadow: none !important;
        margin-bottom: 24px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    
    /* Show signature box headers in print */
    .signature-box .sig-header { 
        display: flex !important;
        align-items: center;
        gap: 6px;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #333 !important;
        font-size: 10px !important;
    }
    
    .signature-box .sig-header i {
        display: none !important;
    }
    
    /* Show signature box info section */
    .signature-box .sig-info { 
        display: block !important;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #ddd !important;
        font-size: 10px !important;
    }
    
    /* Show signature area */
    .signature-box .sig-area { 
        display: block !important;
        margin-bottom: 12px;
    }
    
    .signature-box .sig-area div:last-child {
        height: 60px !important;
        border-bottom: 1px solid #333 !important;
    }
    
    /* Show date field */
    .signature-box .sig-date { 
        display: block !important;
        font-size: 10px !important;
    }
    
    .signature-box .sig-date > div:first-child {
        display: block !important;
    }
    
    .signature-box .sig-date > div:last-child {
        border-bottom: 1px solid #333 !important;
        height: auto !important;
        margin-bottom: 0;
    }
    
    /* Ensure header section is visible in print */
    .sig-header-section {
        display: block !important;
    }
}
</style>

<!-- ══ SCRIPTS ══════════════════════════════════════════════════════════════ -->
<script>
var totalCheckItems = <?php echo $totalCheckItems; ?>;
var totalDevices    = <?php echo $totalFormDevices; ?>;
var preselectedDevId = <?php echo $preselectedDevId; ?>;

function toggleCard(id) {
    var body = document.getElementById('body-' + id);
    var icon = document.getElementById('icon-' + id);
    var hint = document.getElementById('hint-' + id);
    var btn  = icon.closest('button');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.className = 'fas fa-chevron-up';
        btn.innerHTML  = '<i class="fas fa-chevron-up" id="icon-' + id + '"></i> Collapse';
        if (hint) hint.style.display = 'none';
    } else {
        body.style.display = 'none';
        icon.className = 'fas fa-chevron-down';
        btn.innerHTML  = '<i class="fas fa-chevron-down" id="icon-' + id + '"></i> Expand';
        if (hint) hint.style.display = '';
    }
}

// Update per-device progress badge
document.addEventListener('change', function(e) {
    var el = e.target;
    if (el.classList.contains('item-cb') || el.classList.contains('condition-radio')) {
        var did  = el.dataset.device;
        var cbs  = document.querySelectorAll('.item-cb[data-device="' + did + '"]:checked').length;
        var badge= document.getElementById('prog-' + did);
        if (badge) {
            badge.textContent = cbs + ' / ' + totalCheckItems + ' checked';
            badge.style.color = cbs === totalCheckItems ? '#27AE60' : (cbs > 0 ? '#f39c12' : '#888');
        }
        updateOverallProgress();
    }
});

// Condition pill visual highlight
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('condition-radio')) return;
    var did   = e.target.dataset.device;
    var pills = document.querySelectorAll('input.condition-radio[data-device="' + did + '"]');
    pills.forEach(function(r) {
        var pill = r.closest('.condition-pill');
        var c    = pill.dataset.color;
        if (r.checked) {
            pill.style.boxShadow = '0 0 0 3px ' + c + '44';
            pill.style.background = c + '22';
        } else {
            pill.style.boxShadow = '';
            pill.style.background = c + '12';
        }
    });
});

function updateOverallProgress() {
    var selected = document.querySelectorAll('.condition-radio:checked');
    var devicesDone = {};
    selected.forEach(function(r){ devicesDone[r.dataset.device] = true; });
    var reviewed = Object.keys(devicesDone).length;

    var cnt    = document.getElementById('progress-count');
    var detail = document.getElementById('progress-detail');
    if (cnt)    cnt.textContent = reviewed;
    if (detail) {
        if (reviewed === 0)
            detail.textContent = 'Expand each device above to fill in return details.';
        else if (reviewed < totalDevices)
            detail.textContent = reviewed + ' of ' + totalDevices + ' devices have a condition rating selected.';
        else
            detail.textContent = 'All devices reviewed. You may now submit the clearance.';
    }
    if (cnt) cnt.style.color = reviewed === totalDevices ? '#27AE60' : '#3498db';
}

window.addEventListener('DOMContentLoaded', function() {
    if (preselectedDevId > 0) {
        var targetCard = document.getElementById('card-' + preselectedDevId);
        var targetRow  = document.getElementById('device-row-' + preselectedDevId);

        if (targetCard) {
            var body = document.getElementById('body-' + preselectedDevId);
            if (body && body.style.display === 'none') {
                toggleCard(preselectedDevId);
            }
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        if (targetRow) {
            targetRow.style.boxShadow = '0 0 0 2px rgba(243, 156, 18, 0.8)';
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>