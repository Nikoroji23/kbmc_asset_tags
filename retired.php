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
    SELECT d.*, dt.type_name, u.full_name as disposed_by_name, u.email as disposed_by_email
    FROM devices d 
    JOIN device_types dt ON d.device_type_id = dt.id 
    LEFT JOIN users u ON d.disposed_by = u.id
    WHERE d.status IN ('retired', 'disposed') 
    ORDER BY d.updated_at DESC
");
$devices = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-trash-alt"></i> Retired / Disposed Devices</h1>
    <button class="btn btn-outline" onclick="exportToPDF('Retired/Disposed Devices Report', ['Asset Tag', 'Type', 'Vendor', 'Serial', 'Status', 'Disposed By', 'Disposed Date'], retiredRows, 'retired_devices_<?php echo date('Y-m-d'); ?>.pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
</div>

<div class="card">
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="retiredTable">
                <thead><tr><th>Asset Tag</th><th>Type</th><th>Vendor</th><th>Serial Number</th><th>Status</th><th>Disposed By</th><th>Disposed Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($devices)): ?>
                    <tr><td colspan="8" class="empty-state" style="padding: 40px;"><i class="fas fa-check-circle" style="font-size: 40px; color: #27AE60;"></i><h4>No retired/disposed devices</h4><p>All devices are active.</p></td></tr>
                    <?php else: ?>
                    <?php foreach ($devices as $dev): ?>
                    <tr>
                        <td><strong><?php echo sanitize($dev['asset_tag']); ?></strong></td>
                        <td><?php echo sanitize($dev['type_name']); ?></td>
                        <td><?php echo sanitize($dev['vendor'] ?? 'N/A'); ?></td>
                        <td><?php echo sanitize($dev['serial_number']); ?></td>
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
        retiredRows.push([cells[0]?.textContent.trim(), cells[1]?.textContent.trim(), cells[2]?.textContent.trim(), cells[3]?.textContent.trim(), cells[4]?.textContent.trim(), cells[5]?.textContent.trim(), cells[6]?.textContent.trim()]);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
