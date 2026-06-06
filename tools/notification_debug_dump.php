<?php
/**
 * Dump notification record(s) to logs/notification_debug_records.log
 * Usage (web): /tools/notification_debug_dump.php?id=41
 * Usage (cli): php tools/notification_debug_dump.php id=41
 * If no id provided, will dump the most recent N records (use ?limit=10)
 */

chdir(__DIR__ . '/..'); // ensure workspace root
require_once __DIR__ . '/../includes/config.php';

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$outLog = $logDir . '/notification_debug_records.log';

$id = null;
$limit = 0;

// CLI args support: php tools/notification_debug_dump.php id=41
if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, 'id=') === 0) $id = (int)substr($arg, 3);
        if (strpos($arg, 'limit=') === 0) $limit = (int)substr($arg, 6);
    }
}

// GET params (web)
if (isset($_GET['id'])) $id = (int)$_GET['id'];
if (isset($_GET['limit'])) $limit = (int)$_GET['limit'];

try {
    if ($id) {
        $stmt = $pdo->prepare('SELECT id, user_id, type, title, message, related_id, is_read, created_at FROM notifications WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($limit > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, type, title, message, related_id, is_read, created_at FROM notifications ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // default: last 5
        $stmt = $pdo->query('SELECT id, user_id, type, title, message, related_id, is_read, created_at FROM notifications ORDER BY created_at DESC LIMIT 5');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $time = date('c');
    $entry = "[$time] Dumped " . count($rows) . " notification(s)\n";
    foreach ($rows as $r) {
        $entry .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    $entry .= str_repeat('-', 80) . "\n";

    file_put_contents($outLog, $entry, FILE_APPEND | LOCK_EX);

    if (PHP_SAPI === 'cli') {
        echo "Wrote " . count($rows) . " record(s) to $outLog\n";
    } else {
        echo "<pre>Wrote " . count($rows) . " record(s) to logs/notification_debug_records.log\n</pre>";
    }

} catch (Exception $e) {
    $err = '[' . date('c') . '] ERROR: ' . $e->getMessage() . "\n";
    file_put_contents($outLog, $err, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $err);
    } else {
        echo "<pre>ERROR: " . htmlspecialchars($e->getMessage()) . "</pre>";
    }
}

?>
