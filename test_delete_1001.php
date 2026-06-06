<?php
require 'includes/config.php';

echo "=== SIMULATE DELETE FOR DEVICE 1001 ===\n";

// Simulate what delete_device.php does
$id = 1001;  // The device shown in UI
$currentUserId = 1;

echo "1. Looking up device ID $id\n";
$stmt = $pdo->prepare("SELECT id, asset_tag, status FROM devices WHERE id = ?");
$stmt->execute([$id]);
$device = $stmt->fetch();

if ($device) {
    echo "   Found: Asset=" . $device['asset_tag'] . ", Status=" . $device['status'] . "\n";
    
    echo "\n2. Attempting UPDATE on device ID $id\n";
    $updateStmt = $pdo->prepare("
        UPDATE devices 
        SET status = 'disposed', 
            disposed_by = ?, 
            disposed_at = NOW()
        WHERE id = ?
    ");
    $result = $updateStmt->execute([$currentUserId, $id]);
    $rowsAffected = $updateStmt->rowCount();
    
    echo "   Result: $result, Rows Affected: $rowsAffected\n";
    
    if ($rowsAffected > 0) {
        echo "\n3. Verifying UPDATE\n";
        $verifyStmt = $pdo->query("SELECT id, asset_tag, status FROM devices WHERE id = $id");
        $verify = $verifyStmt->fetch();
        echo "   Device ID $id Status: " . $verify['status'] . "\n";
    } else {
        echo "\n3. WARNING: No rows were affected!\n";
    }
} else {
    echo "   ERROR: Device not found!\n";
}
?>
