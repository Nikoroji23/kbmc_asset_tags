<?php
/**
 * Cleanup script: mark orphaned assignments as returned and set devices to in_stock
 * Usage (CLI): php tools/cleanup_orphan_assignments.php [--dry]
 * Usage (web): tools/cleanup_orphan_assignments.php?dry=1
 */
require_once __DIR__ . '/../includes/functions.php';

$isCli = php_sapi_name() === 'cli';
$dry = false;
if ($isCli) {
    $args = array_slice($argv, 1);
    if (in_array('--dry', $args) || in_array('-n', $args)) $dry = true;
} else {
    if (!empty($_GET['dry'])) $dry = true;
}

// Find orphaned active assignments: active assignment but no active user linked
$stmt = $pdo->prepare(
    "SELECT da.id, da.device_id, da.employee_id, da.assigned_date, da.notes, d.asset_tag, d.status AS device_status
     FROM device_assignments da
     LEFT JOIN users u ON da.employee_id = u.id
     JOIN devices d ON da.device_id = d.id
     WHERE da.status = 'active' AND (da.employee_id IS NULL OR u.id IS NULL OR u.status != 'active')"
);
$stmt->execute();
$orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = function($s) use ($isCli) { if ($isCli) echo $s . PHP_EOL; else echo nl2br(htmlspecialchars($s)) . "<br>\n"; };

if (empty($orphans)) {
    $out('No orphaned active assignments found.');
    exit(0);
}

$out(count($orphans) . ' orphaned active assignment(s) found.');
if ($dry) {
    foreach ($orphans as $o) {
        $out("DRY: assignment id={$o['id']} device_id={$o['device_id']} asset_tag={$o['asset_tag']} assigned_date={$o['assigned_date']}");
    }
    $out('Dry run complete. No changes applied.');
    exit(0);
}

try {
    $pdo->beginTransaction();
    $processed = 0;
    foreach ($orphans as $o) {
        $note = "Auto-returned by cleanup script on " . date('Y-m-d H:i:s') . " due to orphaned assignment.";
        $pdo->prepare("UPDATE device_assignments SET status = 'returned', returned_date = CURDATE(), notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?")->execute(["\n" . $note, $o['id']]);

        // Update device to in_stock (safe to set even if already in_stock)
        $pdo->prepare("UPDATE devices SET status = 'in_stock', location = 'IT Stock Room' WHERE id = ?")->execute([$o['device_id']]);

        // Notify admins
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $admin) {
            if (!empty($admin['id'])) {
                addNotification($admin['id'], 'device_returned', 'Device Returned (Auto)', "Device {$o['asset_tag']} (assignment {$o['id']}) was auto-returned to stock by cleanup.", $o['device_id']);
            }
        }

        // Audit log — use current session user if available
        if (session_status() == PHP_SESSION_NONE) @session_start();
        $currentUserId = $_SESSION['user_id'] ?? null;
        logAudit($currentUserId, 'AutoReturnCleanup', 'device_assignments', $o['id']);

        $processed++;
    }
    $pdo->commit();
    $out("Processed: {$processed} assignment(s). Devices set to in_stock and admins notified.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $out('Error during cleanup: ' . $e->getMessage());
    exit(1);
}

exit(0);
