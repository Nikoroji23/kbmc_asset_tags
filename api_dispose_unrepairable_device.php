<?php
/**
 * API: Mark repair as unrepairable and dispose device
 * 
 * Marks a repair as unrepairable, closes the repair record, 
 * and moves the device to 'disposed' status
 */

header('Content-Type: application/json');

require_once 'includes/header.php';

requireITStaff();

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$repairId = isset($input['repair_id']) ? (int)$input['repair_id'] : 0;
$deviceId = isset($input['device_id']) ? (int)$input['device_id'] : 0;
$assignedTo = isset($input['assigned_to']) ? (int)$input['assigned_to'] : 0;
$notes = isset($input['notes']) ? sanitize($input['notes']) : '';

if (!$repairId || !$deviceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing repair ID or device ID']);
    exit();
}

if (!$assignedTo) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'IT staff member assignment is required']);
    exit();
}

if (empty($notes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Disposal notes are required']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get the repair record
    $repairStmt = $pdo->prepare('SELECT * FROM device_repairs WHERE id = ?');
    $repairStmt->execute([$repairId]);
    $repair = $repairStmt->fetch();

    if (!$repair) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Repair record not found']);
        exit();
    }

    // Update repair status to 'unrepairable'
    $updateRepair = $pdo->prepare('
        UPDATE device_repairs 
        SET repair_status = ?, 
            completed_date = NOW(), 
            completed_by = ?,
            assigned_to = ?,
            repair_notes = ?
        WHERE id = ?
    ');
    $updateRepair->execute(['unrepairable', $_SESSION['user_id'], $assignedTo, $notes, $repairId]);

    // Update device status to 'disposed' and record who disposed it
    $updateDevice = $pdo->prepare(
        'UPDATE devices 
         SET status = ?, 
             disposed_by = ?,
             disposed_at = NOW(),
             updated_at = NOW()
         WHERE id = ?'
    );
    $updateDevice->execute(['disposed', $assignedTo, $deviceId]);

    // Log the disposal action in audit log (if available)
    if (function_exists('logActivityAudit')) {
        logActivityAudit($deviceId, 'device_disposal', 'Device marked as unrepairable and disposed', [
            'repair_id' => $repairId,
            'disposal_reason' => $notes,
            'assigned_to' => $assignedTo,
            'user_id' => $_SESSION['user_id']
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Device successfully marked as unrepairable and disposed'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
