<?php
require 'includes/config.php';

echo "=== TEST DEVICES.PHP QUERY ===\n";

$sql = "SELECT d.*, dt.type_name FROM devices d 
        JOIN device_types dt ON d.device_type_id = dt.id 
        WHERE d.status NOT IN ('retired', 'disposed')
        ORDER BY d.id DESC LIMIT 5";

echo "Query: $sql\n\n";
echo "Results:\n";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$devices = $stmt->fetchAll();

if (empty($devices)) {
    echo "No devices found (filter is working!)\n";
} else {
    foreach ($devices as $dev) {
        echo "ID: " . $dev['id'] . ", Asset: " . $dev['asset_tag'] . ", Status: " . $dev['status'] . "\n";
    }
}

// Now check how many devices have status IN ('retired', 'disposed')
echo "\n=== DEVICES THAT SHOULD BE HIDDEN ===\n";
$stmt = $pdo->query("SELECT id, asset_tag, status FROM devices WHERE status IN ('retired', 'disposed') ORDER BY id DESC LIMIT 10");
$hidden = $stmt->fetchAll();
echo count($hidden) . " devices with retired/disposed status:\n";
foreach ($hidden as $dev) {
    echo "  ID: " . $dev['id'] . ", Asset: " . $dev['asset_tag'] . ", Status: " . $dev['status'] . "\n";
}
?>
