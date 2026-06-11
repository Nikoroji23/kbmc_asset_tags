<?php
/**
 * KBMC Asset Management - Retired / Disposed Devices
 */
$pageTitle = 'Retired / Disposed Devices';
require_once 'includes/header.php';
requireITStaffOnly();

// Ensure disposal tracking columns exist
ensureDeviceSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revert_device_id'])) {
    $revertId = (int)$_POST['revert_device_id'];
    $deviceStmt = $pdo->prepare("SELECT id, asset_tag, status, disposed_at, updated_at FROM devices WHERE id = ? LIMIT 1");
    $deviceStmt->execute([$revertId]);
    $deviceToRevert = $deviceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$deviceToRevert) {
        setFlashMessage('error', 'Device not found.');
        header('Location: retired.php');
        exit();
    }

    if (!in_array($deviceToRevert['status'], ['retired', 'disposed'], true)) {
        setFlashMessage('error', 'Only retired or disposed devices can be reverted.');
        header('Location: retired.php');
        exit();
    }

    $revertDate = $deviceToRevert['disposed_at'] ?: $deviceToRevert['updated_at'];
    if (!$revertDate || strtotime($revertDate) < strtotime('-7 days')) {
        setFlashMessage('error', 'This device can no longer be reverted because it has been retired/disposed for more than 7 days.');
        header('Location: retired.php');
        exit();
    }

    try {
        $pdo->beginTransaction();

        $activeAssignmentStmt = $pdo->prepare("SELECT id FROM device_assignments WHERE device_id = ? AND status = 'active' LIMIT 1");
        $activeAssignmentStmt->execute([$revertId]);
        $hasActiveAssignment = (bool)$activeAssignmentStmt->fetch(PDO::FETCH_ASSOC);
        $newStatus = $hasActiveAssignment ? 'deployed' : 'in_stock';

        $updateStmt = $pdo->prepare("UPDATE devices SET status = ?, disposed_by = NULL, disposed_at = NULL WHERE id = ?");
        $updateStmt->execute([$newStatus, $revertId]);

        $oldData = json_encode([
            'asset_tag' => $deviceToRevert['asset_tag'],
            'status' => $deviceToRevert['status'],
            'disposed_at' => $deviceToRevert['disposed_at']
        ]);
        $newData = json_encode([
            'asset_tag' => $deviceToRevert['asset_tag'],
            'status' => $newStatus,
            'disposed_at' => null
        ]);
        logAudit($_SESSION['user_id'], 'Revert Disposal', 'devices', $revertId, $oldData, $newData);

        $pdo->commit();
        setFlashMessage('success', 'Device ' . sanitize($deviceToRevert['asset_tag']) . ' has been reverted successfully.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Device revert error for ID ' . $revertId . ': ' . $e->getMessage());
        setFlashMessage('error', 'Failed to revert the device.');
    }

    header('Location: retired.php');
    exit();
}

$stmt = $pdo->query("
    SELECT d.*, dt.type_name, u.full_name as disposed_by_name, u.email as disposed_by_email,
           COALESCE(
               JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.disposal_reason')),
               ur.repair_notes,
               d.condition_notes
           ) AS disposal_reason
    FROM devices d
    JOIN device_types dt ON d.device_type_id = dt.id
    LEFT JOIN users u ON d.disposed_by = u.id
    LEFT JOIN (
        SELECT a.record_id, a.new_values
        FROM audit_logs a
        WHERE a.table_name = 'devices' AND (a.activity_type = 'disposal' OR a.action LIKE '%Dispose%')
          AND a.created_at = (
              SELECT MAX(a2.created_at) FROM audit_logs a2
              WHERE a2.record_id = a.record_id AND a2.table_name = 'devices' AND (a2.activity_type = 'disposal' OR a2.action LIKE '%Dispose%')
          )
    ) al ON al.record_id = d.id
    LEFT JOIN (
        SELECT dr.device_id, dr.repair_notes
        FROM device_repairs dr
        INNER JOIN (
            SELECT device_id, MAX(completed_date) AS max_completed_date
            FROM device_repairs
            WHERE repair_status = 'unrepairable'
            GROUP BY device_id
        ) latest ON latest.device_id = dr.device_id AND latest.max_completed_date = dr.completed_date
        WHERE dr.repair_status = 'unrepairable'
    ) ur ON ur.device_id = d.id
    WHERE d.status IN ('retired', 'disposed')
    ORDER BY d.updated_at DESC
");
$devices = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-trash-alt"></i> Retired / Disposed Devices</h1>
    <button class="btn btn-outline" onclick="exportToPDF('Retired/Disposed Devices Report', ['Asset Tag', 'Type', 'Disposal Reason', 'Status', 'Disposed By', 'Disposed Date'], retiredRows, 'retired_devices_<?php echo date('Y-m-d'); ?>.pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
</div>

<div class="card">
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="retiredTable">
                <thead><tr><th>Asset Tag</th><th>Type</th><th>Disposal Reason</th><th>Status</th><th>Disposed By</th><th>Disposed Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($devices)): ?>
                    <tr><td colspan="7" class="empty-state" style="padding: 40px;"><i class="fas fa-check-circle" style="font-size: 40px; color: #27AE60;"></i><h4>No retired/disposed devices</h4><p>All devices are active.</p></td></tr>
                    <?php else: ?>
                    <?php foreach ($devices as $dev): ?>
                    <tr>
                        <td><strong><?php echo sanitize($dev['asset_tag']); ?></strong></td>
                        <td><?php echo sanitize($dev['type_name']); ?></td>
                        <td><?php echo trim($dev['disposal_reason']) ? sanitize($dev['disposal_reason']) : '<span style="color: #999;">N/A</span>'; ?></td>
                        <td><?php echo getStatusBadge($dev['status']); ?></td>
                        <td><?php echo $dev['disposed_by_name'] ? sanitize($dev['disposed_by_name']) . '<br><small style="color: #666;">' . sanitize($dev['disposed_by_email']) . '</small>' : '<span style="color: #999;">N/A</span>'; ?></td>
                        <td><?php echo $dev['disposed_at'] ? formatDate($dev['disposed_at']) : '<span style="color: #999;">N/A</span>'; ?></td>
                        <td style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <a href="view_device.php?id=<?php echo $dev['id']; ?>" class="action-btn view" title="View"><i class="fas fa-eye"></i></a>
                            <?php
                                $revertDate = $dev['disposed_at'] ?: $dev['updated_at'];
                                $canRevert = $revertDate && strtotime($revertDate) >= strtotime('-7 days');
                            ?>
                            <?php if ($canRevert): ?>
                                <form method="post" style="display:inline; margin:0;">
                                    <input type="hidden" name="revert_device_id" value="<?php echo $dev['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Revert this device back to the user? This is only allowed within 7 days of retirement/disposal.');" title="Revert">
                                        <i class="fas fa-undo-alt"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color:#999;font-size:12px;">Revert unavailable</span>
                            <?php endif; ?>
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
const retiredRows = [];
document.querySelectorAll('#retiredTable tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    if (cells.length > 1) {
        // Columns: Asset Tag, Type, Disposal Reason, Status, Disposed By, Disposed Date
        retiredRows.push([
            cells[0]?.textContent.trim(),
            cells[1]?.textContent.trim(),
            cells[2]?.textContent.trim(),
            cells[3]?.textContent.trim(),
            cells[4]?.textContent.trim(),
            cells[5]?.textContent.trim()
        ]);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
