<?php
require_once 'includes/config.php';

echo '<style>
table { border-collapse: collapse; width: 100%; margin: 20px 0; }
th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
th { background: #27ae60; color: white; }
tr:nth-child(even) { background: #f5f5f5; }
.assigned { background: #d5f4e6; }
.unassigned { background: #fadbd8; }
</style>';

echo '<h2>Device Import Report - All Devices with Assignments</h2>';

$stmt = $pdo->prepare("
SELECT 
    d.asset_tag,
    dt.type_name as device_type,
    d.pc_name,
    d.ip_address,
    d.status,
    u.full_name as assigned_to,
    da.assigned_date,
    CASE WHEN da.id IS NOT NULL THEN 'YES' ELSE 'NO' END as has_assignment
FROM devices d
LEFT JOIN device_types dt ON d.device_type_id = dt.id
LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active'
LEFT JOIN users u ON da.employee_id = u.id
WHERE d.asset_tag LIKE ?
ORDER BY d.asset_tag
");
$stmt->execute(['KBM-%']);
$devices = $stmt->fetchAll();

echo '<table>';
echo '<thead><tr>';
echo '<th>Asset Tag</th>';
echo '<th>Device Type</th>';
echo '<th>PC Name</th>';
echo '<th>IP Address</th>';
echo '<th>Status</th>';
echo '<th>Assigned To</th>';
echo '<th>Assignment</th>';
echo '</tr></thead>';
echo '<tbody>';

$assignedCount = 0;
$unassignedCount = 0;

foreach ($devices as $dev) {
    $rowClass = $dev['has_assignment'] === 'YES' ? 'assigned' : 'unassigned';
    if ($dev['has_assignment'] === 'YES') $assignedCount++;
    else $unassignedCount++;
    
    echo '<tr class="' . $rowClass . '">';
    echo '<td>' . htmlspecialchars($dev['asset_tag'] ?? 'N/A') . '</td>';
    echo '<td>' . htmlspecialchars($dev['device_type'] ?? 'N/A') . '</td>';
    echo '<td>' . htmlspecialchars($dev['pc_name'] ?? 'N/A') . '</td>';
    echo '<td>' . htmlspecialchars($dev['ip_address'] ?? 'N/A') . '</td>';
    echo '<td><strong>' . htmlspecialchars($dev['status'] ?? 'N/A') . '</strong></td>';
    echo '<td>' . htmlspecialchars($dev['assigned_to'] ?? 'Unassigned') . '</td>';
    echo '<td>' . ($dev['has_assignment'] === 'YES' ? '✓ Assigned' : '⊗ Unassigned') . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '<h3>Summary</h3>';
echo '<p><strong>Total Devices:</strong> ' . count($devices) . '</p>';
echo '<p><strong>Assigned Devices:</strong> ' . $assignedCount . ' ✓</p>';
echo '<p><strong>Unassigned Devices:</strong> ' . $unassignedCount . ' ⊗</p>';
?>
