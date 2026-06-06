<?php
/**
 * Admin-only debug: dump master key fields for a user by email
 * Usage (browser while logged in as admin):
 *   /tools/dump_user_security.php?email=admin@kbmc.com
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/header.php';

if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(403);
    echo "Access denied. Admin only.";
    exit;
}

$email = trim($_GET['email'] ?? '');
if ($email === '') {
    echo "Please provide an email via ?email=...\n";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, employee_id, full_name, email, role, master_key, master_key_hash, is_security_admin FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        echo "User not found: " . htmlspecialchars($email);
        exit;
    }

    echo "<h2>User security dump for " . htmlspecialchars($u['email']) . "</h2>";
    echo "<pre>";
    echo "id: " . htmlspecialchars($u['id']) . "\n";
    echo "employee_id: " . htmlspecialchars($u['employee_id']) . "\n";
    echo "full_name: " . htmlspecialchars($u['full_name']) . "\n";
    echo "role: " . htmlspecialchars($u['role']) . "\n";
    echo "master_key (plaintext column): " . ($u['master_key'] ? htmlspecialchars($u['master_key']) : '[empty]') . "\n";
    echo "master_key_hash: " . ($u['master_key_hash'] ? htmlspecialchars(substr($u['master_key_hash'],0,60)) . '...' : '[empty]') . "\n";
    echo "is_security_admin: " . ($u['is_security_admin'] ? '1' : '0') . "\n";
    echo "</pre>";

    echo "<p>Run <a href=\"/kbmc_new_asset/tools/hash_existing_master_keys.php\">hash_existing_master_keys.php</a> first if you added master_key values and they haven't been hashed yet.</p>";

} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}

?>