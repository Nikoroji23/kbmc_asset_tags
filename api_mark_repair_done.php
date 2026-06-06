<?php
/**
 * API - Mark Repair as Completed
 * Endpoint for IT staff to mark device repairs as complete and notify employee
 */

header('Content-Type: application/json');
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || (!hasRole('admin') && !hasRole('it_staff'))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$json = json_decode(file_get_contents('php://input'), true);
$repairId = (int)($json['repair_id'] ?? 0);
$completionNotes = sanitize($json['completion_notes'] ?? '');

if (!$repairId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing repair_id']);
    exit();
}

try {
    // Call helper function
    $result = markRepairAsCompleted($repairId, $completionNotes);
    
    if ($result['success']) {
        // Update completed_by if column exists
        if (columnExists('device_repairs', 'completed_by')) {
            $pdo->prepare("UPDATE device_repairs SET completed_by = ? WHERE id = ?")->execute([$_SESSION['user_id'], $repairId]);
        }
        
        // Get repair details for enhanced audit logging
        $repairStmt = $pdo->prepare("
            SELECT dr.*, d.asset_tag, dt.type_name, u.full_name as completed_by_name 
            FROM device_repairs dr
            JOIN devices d ON dr.device_id = d.id
            JOIN device_types dt ON d.device_type_id = dt.id
            LEFT JOIN users u ON u.id = ?
            WHERE dr.id = ?
        ");
        $repairStmt->execute([$_SESSION['user_id'], $repairId]);
        $repair = $repairStmt->fetch();
        
        // Enhanced audit log with more details
        if ($repair) {
            $auditDetails = "Device: {$repair['asset_tag']} ({$repair['type_name']}). " .
                           "Completed By: {$repair['completed_by_name']}. " .
                           "Notes: " . ($completionNotes ?: 'N/A');
            logAudit($_SESSION['user_id'], 'Mark Repair Complete', 'device_repairs', $repairId, $auditDetails);
        } else {
            logAudit($_SESSION['user_id'], 'Mark Repair Complete', 'device_repairs', $repairId);
        }
        
        // Send pending emails as a best-effort operation
        try {
            sendPendingEmailNotifications();
        } catch (Exception $emailException) {
            error_log('sendPendingEmailNotifications failed: ' . $emailException->getMessage());
        }
        
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
