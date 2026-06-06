<?php
/**
 * KBMC Asset Management — Mark Notification Read (AJAX)
 * File: mark_notification_read.php
 *
 * Accepts POST with JSON body:
 *   { "id": 42 }       → mark single notification as read
 *   { "all": true }    → mark ALL of this user's notifications as read
 */
require_once __DIR__ . '/includes/functions.php';

// Must be logged in
if (empty($_SESSION['user_id'])) {
    // Temporary debug log for unauthorized access
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $logFile = $logDir . '/notifications_debug.log';
        $entry = sprintf("[%s] UNAUTHORIZED IP=%s\n", date('c'), $_SERVER['REMOTE_ADDR'] ?? 'cli');
        file_put_contents($logFile, $entry, FILE_APPEND);
    } catch (Exception $e) { /* ignore logging errors */ }

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Temporary debug logging: invalid JSON received
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $logFile = $logDir . '/notifications_debug.log';
        $raw = file_get_contents('php://input');
        $entry = sprintf("[%s] INVALID_JSON IP=%s UID=%s RAW=%s\n", date('c'), $_SERVER['REMOTE_ADDR'] ?? 'cli', session_id() ?: 'no-session', substr($raw ?? '', 0, 2000));
        file_put_contents($logFile, $entry, FILE_APPEND);
    } catch (Exception $e) { /* ignore logging errors */ }

    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    // Temporary debug logging: received request
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $logFile = $logDir . '/notifications_debug.log';
        $raw = json_encode($input);
        $entry = sprintf("[%s] REQ IP=%s UID=%s INPUT=%s\n", date('c'), $_SERVER['REMOTE_ADDR'] ?? 'cli', $_SESSION['user_id'] ?? '0', substr($raw,0,2000));
        file_put_contents($logFile, $entry, FILE_APPEND);
    } catch (Exception $e) { /* ignore logging errors */ }

    if (!empty($input['all'])) {
        // Mark ALL unread notifications for this user as read
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1
             WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$_SESSION['user_id']]);
        // Log update
        try { file_put_contents($logFile, sprintf("[%s] ACTION=mark_all UID=%s UPDATED=%d\n", date('c'), $_SESSION['user_id'], $stmt->rowCount()), FILE_APPEND); } catch (Exception $e) {}
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);

    } elseif (!empty($input['id'])) {
        // Mark a single notification as read — verify it belongs to this user
        $stmt = $pdo->prepare(
            "UPDATE notifications SET is_read = 1
             WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([(int)$input['id'], $_SESSION['user_id']]);
        // Log update
        try { file_put_contents($logFile, sprintf("[%s] ACTION=mark_one UID=%s ID=%d UPDATED=%d\n", date('c'), $_SESSION['user_id'], (int)$input['id'], $stmt->rowCount()), FILE_APPEND); } catch (Exception $e) {}
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);

    } else {
        echo json_encode(['success' => false, 'error' => 'No action specified']);
    }

} catch (Exception $e) {
    // Log exception
    try { file_put_contents($logFile, sprintf("[%s] EXCEPTION UID=%s MSG=%s\n", date('c'), $_SESSION['user_id'] ?? '0', substr($e->getMessage(),0,1000)), FILE_APPEND); } catch (Exception $ee) {}
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}