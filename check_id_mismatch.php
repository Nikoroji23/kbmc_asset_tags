<?php
require 'includes/config.php';

echo "=== CHECK DEVICE MISMATCH ===\n";

// Check device with asset tag
echo "\n1. Device with asset tag 'KBM-IT-001277':\n";
$stmt = $pdo->query("SELECT id, asset_tag, status FROM devices WHERE asset_tag = 'KBM-IT-001277' LIMIT 1");
$dev1 = $stmt->fetch();
if ($dev1) {
    echo "  ID: " . $dev1['id'] . ", Asset: " . $dev1['asset_tag'] . ", Status: " . $dev1['status'] . "\n";
}

// Check device with ID 1001
echo "\n2. Device with ID 1001:\n";
$stmt = $pdo->query("SELECT id, asset_tag, status FROM devices WHERE id = 1001 LIMIT 1");
$dev2 = $stmt->fetch();
if ($dev2) {
    echo "  ID: " . $dev2['id'] . ", Asset: " . $dev2['asset_tag'] . ", Status: " . $dev2['status'] . "\n";
} else {
    echo "  No device with ID 1001\n";
}

// Check how many devices with ID > 500
echo "\n3. Highest device IDs:\n";
$stmt = $pdo->query("SELECT id, asset_tag, status FROM devices ORDER BY id DESC LIMIT 5");
$devices = $stmt->fetchAll();
foreach ($devices as $d) {
    echo "  ID: " . $d['id'] . ", Asset: " . $d['asset_tag'] . ", Status: " . $d['status'] . "\n";
}
?>
