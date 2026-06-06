<?php
require 'includes/config.php';

echo "=== DATABASE DIAGNOSIS ===\n";
echo "Checking disposal columns:\n";

try {
    $query = "DESCRIBE devices";
    $result = $pdo->query($query)->fetchAll();
    foreach ($result as $col) {
        if (in_array($col['Field'], ['status', 'disposed_by', 'disposed_at'])) {
            echo "✓ " . $col['Field'] . " - " . $col['Type'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR checking columns: " . $e->getMessage() . "\n";
}

echo "\n=== DEVICE KBM-IT-001277 STATUS ===\n";

try {
    $stmt = $pdo->query("SELECT id, asset_tag, status, disposed_by, disposed_at FROM devices WHERE asset_tag = 'KBM-IT-001277' LIMIT 1");
    $device = $stmt->fetch();
    if ($device) {
        echo "Device Found!\n";
        echo "  ID: " . $device['id'] . "\n";
        echo "  Asset Tag: " . $device['asset_tag'] . "\n";
        echo "  Status: " . $device['status'] . "\n";
        echo "  Disposed By: " . ($device['disposed_by'] ?: 'NULL') . "\n";
        echo "  Disposed At: " . ($device['disposed_at'] ?: 'NULL') . "\n";
    } else {
        echo "Device not found in database!\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== TESTING MANUAL UPDATE ===\n";
try {
    // Test UPDATE
    $testId = $device['id'] ?? 0;
    if ($testId) {
        $updateStmt = $pdo->prepare("UPDATE devices SET status = 'disposed', disposed_by = 1, disposed_at = NOW() WHERE id = ?");
        $updateStmt->execute([$testId]);
        echo "Rows affected: " . $updateStmt->rowCount() . "\n";
        
        // Verify
        $verifyStmt = $pdo->query("SELECT status FROM devices WHERE id = $testId");
        $verify = $verifyStmt->fetch();
        echo "Status after UPDATE: " . $verify['status'] . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
