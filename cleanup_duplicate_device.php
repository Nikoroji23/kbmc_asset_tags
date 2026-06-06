<?php
/**
 * CLEANUP SCRIPT - Remove Duplicate Device
 * Removes the duplicate device 514 which is a duplicate of device 1001
 * Both have asset tag KBM-IT-001277
 */
require_once 'includes/config.php';

echo "=== DUPLICATE DEVICE CLEANUP ===\n\n";

// Check current status
$stmt = $pdo->query("SELECT id, asset_tag, serial_number, status FROM devices WHERE asset_tag = 'KBM-IT-001277' ORDER BY id");
$devices = $stmt->fetchAll();

echo "Devices with asset tag 'KBM-IT-001277':\n";
foreach ($devices as $d) {
    echo "  ID: " . $d['id'] . ", Serial: " . ($d['serial_number'] ?: 'NULL') . ", Status: " . $d['status'] . "\n";
}

echo "\n";

if (count($devices) > 1) {
    echo "Found " . count($devices) . " duplicate devices.\n";
    echo "Action: Removing device ID 514 (the older duplicate without serial number)\n\n";
    
    try {
        $pdo->beginTransaction();
        
        // Step 1: Delete ALL device_assignments for device 514 (they're duplicates)
        $stmt = $pdo->prepare("DELETE FROM device_assignments WHERE device_id = 514");
        $stmt->execute();
        echo "✓ Deleted all assignments for device 514\n";
        
        // Step 2: Delete inspection records for device 514
        $stmt = $pdo->prepare("DELETE FROM device_inspections WHERE device_id = 514");
        $stmt->execute();
        echo "✓ Deleted inspections for device 514\n";
        
        // Step 3: Delete repair records for device 514
        $stmt = $pdo->prepare("DELETE FROM device_repairs WHERE device_id = 514");
        $stmt->execute();
        echo "✓ Deleted repairs for device 514\n";
        
        // Step 4: Delete maintenance schedules for device 514
        $stmt = $pdo->prepare("DELETE FROM maintenance_schedules WHERE device_id = 514");
        $stmt->execute();
        echo "✓ Deleted maintenance schedules for device 514\n";
        
        // Step 5: Delete the duplicate device
        $stmt = $pdo->prepare("DELETE FROM devices WHERE id = 514");
        $stmt->execute();
        echo "✓ Deleted device ID 514\n";
        
        $pdo->commit();
        
        echo "\n✓ Cleanup complete!\n";
        echo "Device ID 1001 (with serial number) is now the only device with asset tag KBM-IT-001277\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "\n✗ Error during cleanup: " . $e->getMessage() . "\n";
    }
} else {
    echo "No duplicates found or already cleaned up.\n";
}
?>
