<?php
require 'includes/config.php';

echo "=== ANALYZE DUPLICATE DEVICES ===\n";

// Check both devices
$stmt = $pdo->query("SELECT id, asset_tag, status, serial_number FROM devices WHERE asset_tag = 'KBM-IT-001277' ORDER BY id");
$devices = $stmt->fetchAll();

foreach ($devices as $d) {
    echo "\nDevice ID " . $d['id'] . ":\n";
    echo "  Asset Tag: " . $d['asset_tag'] . "\n";
    echo "  Serial: " . $d['serial_number'] . "\n";
    echo "  Status: " . $d['status'] . "\n";
    
    // Check if assigned
    $assignStmt = $pdo->query("SELECT da.id, da.status, u.full_name FROM device_assignments da LEFT JOIN users u ON da.employee_id = u.id WHERE da.device_id = " . $d['id']);
    $assignments = $assignStmt->fetchAll();
    if (!empty($assignments)) {
        echo "  Assignments:\n";
        foreach ($assignments as $a) {
            echo "    - ID: " . $a['id'] . ", Status: " . $a['status'] . ", Assigned to: " . ($a['full_name'] ?: 'N/A') . "\n";
        }
    } else {
        echo "  Assignments: None\n";
    }
}

echo "\n=== DELETE RECOMMENDATION ===\n";
echo "The duplicate with ID 514 (disposed) can be permanently deleted.\n";
echo "Keep ID 1001 (deployed) as the active one.\n";
?>
