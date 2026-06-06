<?php
/**
 * Quick fix for old user_clearance_completed notifications.
 *
 * This script updates employee-facing notifications where
 * the notification is a clearance completion event but related_id is missing,
 * and it also normalizes legacy type variants to 'user_clearance_completed'.
 * It sets related_id = user_id for employee notifications only.
 */

require_once __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->prepare(
        "SELECT n.id, n.user_id, n.related_id, n.type, n.title, u.role, u.full_name
         FROM notifications n
         JOIN users u ON u.id = n.user_id
         WHERE (n.type IN ('user_clearance_completed', 'clearance_completed', 'complete_user_clearance')
                OR LOWER(n.title) LIKE '%clearance completed%'
                OR LOWER(n.title) LIKE '%clearance complete%')
           AND (n.related_id IS NULL OR n.related_id = 0)
           AND u.role = 'employee'"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($rows) . " employee notification(s) requiring related_id fix." . PHP_EOL;

    if (empty($rows)) {
        exit(0);
    }

    $update = $pdo->prepare("UPDATE notifications SET related_id = ?, type = 'user_clearance_completed' WHERE id = ?");
    $updated = 0;

    foreach ($rows as $row) {
        $update->execute([$row['user_id'], $row['id']]);
        $updated += $update->rowCount();
        echo sprintf("Updated notification ID %d: user_id=%d -> related_id=%d, type=user_clearance_completed\n", $row['id'], $row['user_id'], $row['user_id']);
    }

    echo "\nTotal updated rows: " . $updated . PHP_EOL;
    echo "Done. Please refresh the notifications page after running this." . PHP_EOL;
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
