<?php
/**
 * KBMC Asset Management - System Entry Point
 */
require_once 'includes/functions.php';

if (isLoggedIn()) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($_SESSION['role'] === 'it_staff') {
        header('Location: it_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
} else {
    header('Location: login.php');
}
exit();
