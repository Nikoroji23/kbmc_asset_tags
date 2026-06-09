<?php
/**
 * KBMC Asset Management - Retired / Disposed Devices
 */
$pageTitle = 'Retired / Disposed Devices';
require_once 'includes/header.php';
requireITStaffOnly();

// Ensure disposal tracking columns exist
ensureDeviceSchema();

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
                        <td><a href="view_device.php?id=<?php echo $dev['id']; ?>" class="action-btn view"><i class="fas fa-eye"></i></a></td>
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
