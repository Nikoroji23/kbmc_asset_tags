<?php
/**
 * One-off script: Hash existing plaintext master_key values into master_key_hash
 * Run from CLI or browser (admin only). Use once and then remove.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/header.php';

if (!isLoggedIn() || !hasRole('admin')) {
    echo "Access denied. Admin only.";
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, master_key FROM users WHERE master_key IS NOT NULL AND master_key_hash IS NULL AND master_key != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $plain = $r['master_key'];
        if ($plain) {
            if (setMasterKey($uid, $plain)) {
                $count++;
            }
        }
    }
    echo "Hashed and updated master_key for: $count user(s).\n";
    echo "Note: This sets `master_key_hash` and flags `is_security_admin` for those users.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

?>