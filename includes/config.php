<?php
/**
 * KBMC Device Arrival & Asset Management System
 * Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'kbmc_asset_db');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . 
        "<br>Please make sure:<br>" .
        "1. XAMPP MySQL is running<br>" .
        "2. Database 'kbmc_asset_db' has been created (run database.sql)<br>" .
        "3. Username/password in config.php matches your XAMPP setup");
}

// Ensure sessions can be stored in a writable local folder when system tmp is not available.
$localSessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($localSessionPath)) {
    mkdir($localSessionPath, 0755, true);
}
if (is_writable($localSessionPath)) {
    session_save_path($localSessionPath);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base URL used for email links and public access. Change this to your live site URL.
define('BASE_URL', 'http://localhost/kbmc_new_asset');

$base_url = '/kbmc_asset_management/';

$brand_colors = [
    'primary'     => '#D9232E',
    'primary_dark'=> '#B91C24',
    'secondary'   => '#2C3E50',
    'success'     => '#27AE60',
    'warning'     => '#F39C12',
    'danger'      => '#E74C3C',
    'info'        => '#3498DB',
    'light'       => '#F8F9FA',
    'dark'        => '#343A40',
    'sidebar'     => '#D9232E',
    'sidebar_dark'=> '#B91C24',
];

$status_colors = [
    'in_stock'          => '#27AE60',
    'deployed'          => '#3498DB',
    'under_repair'      => '#F39C12',
    'retired'           => '#7F8C8D',
    'disposed'          => '#95A5A6',
    'pending_inspection'=> '#E67E22',
    'rejected'          => '#E74C3C',
];

$role_names = [
    'admin'     => 'Administrator',
    'it_staff'  => 'IT Staff',
    'employee'  => 'Employee',
];
