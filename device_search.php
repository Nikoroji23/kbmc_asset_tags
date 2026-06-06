<?php
/**
 * KBMC Asset Management - Device Search by Serial Number
 * Search and locate devices by serial number or asset tag
 */

$pageTitle = 'Device Search';
require_once 'includes/header.php';

requireLogin();

$searchResults = [];
$searchTerm = '';
$noResults = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_term'])) {
    $searchTerm = sanitize($_POST['search_term']);
    if (strlen($searchTerm) >= 2) {
        $searchResults = searchDevicesBySerialOrAsset($searchTerm);
        $noResults = empty($searchResults);
    }
}
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-search"></i> Device Search</h1>
        <p>Quickly locate devices by serial number or asset tag.</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" class="search-box">
            <input type="text" name="search_term" placeholder="Search serial number or asset tag..." 
                   value="<?php echo htmlspecialchars($searchTerm); ?>" 
                   class="form-control"
                   required>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($searchTerm): ?>
            <a href="device_search.php" class="btn btn-light">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($searchTerm): ?>
    <?php if ($noResults): ?>
    <div class="card empty-state">
        <div class="empty-state-icon">
            <i class="fas fa-search"></i>
        </div>
        <h4>No Devices Found</h4>
        <p>No devices match your search for <strong>&laquo;<?php echo htmlspecialchars($searchTerm); ?>&raquo;</strong>.</p>
        <p class="secondary-text">Try a different serial number or asset tag.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">
            <h3>Search Results (<strong><?php echo count($searchResults); ?></strong> device<?php echo count($searchResults) !== 1 ? 's' : ''; ?> found)</h3>
        </div>
        <div class="card-body">
            <div class="data-table-wrapper">
                <table class="data-table results-table">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Serial Number</th>
                            <th>Device Type</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $device): ?>
                        <?php 
                            $statusColor = getStatusColor($device['status']);
                            // Get current assignment
                            $stmt = $pdo->prepare("
                                SELECT u.full_name FROM device_assignments da
                                JOIN users u ON da.employee_id = u.id
                                WHERE da.device_id = ? AND da.status = 'active'
                                LIMIT 1
                            ");
                            $stmt->execute([$device['id']]);
                            $assignment = $stmt->fetch();
                            $assignedTo = $assignment ? $assignment['full_name'] : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo $device['asset_tag']; ?></strong></td>
                            <td><code style="background: #ecf0f1; padding: 3px 6px; border-radius: 3px; font-size: 11px;"><?php echo $device['serial_number']; ?></code></td>
                            <td><?php echo $device['type_name']; ?></td>
                            <td>
                                <span class="status-badge" style="background-color: <?php echo $statusColor['color_code']; ?>20; color: <?php echo $statusColor['color_code']; ?>; border: 1px solid <?php echo $statusColor['color_code']; ?>; padding: 4px 8px; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="<?php echo $statusColor['icon_class']; ?>"></i> <?php echo $statusColor['display_label']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($assignedTo); ?></td>
                            <td><?php echo htmlspecialchars($device['location'] ?? '—'); ?></td>
                            <td>
                                <a href="view_device.php?id=<?php echo $device['id']; ?>" class="btn btn-sm btn-secondary" title="View Full Details">
                                    <i class="fas fa-eye"></i> Details
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php else: ?>
<div class="card empty-state">
    <div class="empty-state-icon">
        <i class="fas fa-magnifying-glass"></i>
    </div>
    <h4>Start Searching</h4>
    <p>Enter a device serial number, asset tag, brand, or model to find devices in the system.</p>
    <div class="search-guidance-grid">
        <div class="search-tip-card">
            <i class="fas fa-hashtag"></i>
            <strong>By Serial #</strong>
            <small>e.g., SN123456789</small>
        </div>
        <div class="search-tip-card">
            <i class="fas fa-tag"></i>
            <strong>By Asset Tag</strong>
            <small>e.g., KBMC-LAP-001</small>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
