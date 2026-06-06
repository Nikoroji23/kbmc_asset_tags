<?php
/**
 * KBMC Asset Management - Logout
 * Clears session + Remember Me cookie
 */
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    logAudit($_SESSION['user_id'], 'Logout', 'users', $_SESSION['user_id']);
}

// Clear Remember Me cookie
clearRememberMe();

session_unset();
session_destroy();

setFlashMessage('success', 'You have been logged out successfully.');
header('Location: login.php');
exit();
