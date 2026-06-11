<?php
/**
 * KBMC Asset Management - Delete Device (FIXED)
 * Marks device as disposed and breaks the active user assignment link
 */
require_once 'includes/functions.php';
requireITStaff();

ensureDeviceSchema();

$id = (int)($_GET['id'] ?? 0);
$currentUserId = (int)$_SESSION['user_id'];

if (!$id || $id <= 0) {
    setFlashMessage('error', 'Invalid device ID provided');
    header('Location: devices.php');
    exit();
}

// Fetch device details
$stmt = $pdo->prepare("SELECT id, asset_tag, status FROM devices WHERE id = ?");
$stmt->execute([$id]);
$device = $stmt->fetch();

if (!$device) {
    setFlashMessage('error', 'Device not found in database');
    header('Location: devices.php');
    exit();
}

try {
    // Get current user info
    $userStmt = $pdo->prepare("SELECT full_name, email, role FROM users WHERE id = ?");
    $userStmt->execute([$currentUserId]);
    $currentUser = $userStmt->fetch();
    
    if (!$currentUser) {
        throw new Exception("Current user not found");
    }
    
    $currentUserName = $currentUser['full_name'];
    $currentUserEmail = $currentUser['email'];
    
    // START TRANSACTION
    $pdo->beginTransaction();

    // STEP 1: FORCE-TERMINATE THE ACTIVE ASSIGNMENT
    // This removes the device from the user's active profile view!
    $updateAssignmentStmt = $pdo->prepare("
        UPDATE device_assignments 
        SET status = 'returned', 
            returned_date = CURDATE() 
        WHERE device_id = ? AND status = 'active'
    ");
    $updateAssignmentStmt->execute([$id]);
    
    // STEP 2: Update device status to 'disposed'
    $updateStmt = $pdo->prepare("
        UPDATE devices 
        SET status = 'disposed', 
            disposed_by = ?, 
            disposed_at = NOW()
        WHERE id = ?
    ");
    
    if (!$updateStmt->execute([$currentUserId, $id])) {
        throw new Exception("UPDATE query execution failed");
    }
    
    $rowsAffected = $updateStmt->rowCount();
    if ($rowsAffected === 0) {
        throw new Exception("No rows were updated. Device may not exist or ID mismatch");
    }
    
    // Verify the update was successful
    $verifyStmt = $pdo->prepare("SELECT status, disposed_by, disposed_at FROM devices WHERE id = ?");
    if (!$verifyStmt->execute([$id])) {
        throw new Exception("Verification query failed");
    }
    
    $verifiedDevice = $verifyStmt->fetch();
    if (!$verifiedDevice) {
        throw new Exception("Device disappeared after update");
    }
    
    if ($verifiedDevice['status'] !== 'disposed') {
        throw new Exception("Status verification failed. Expected 'disposed', got '" . $verifiedDevice['status'] . "'");
    }
    
    // Commit transaction cleanly
    $pdo->commit();
    
    // Log the disposal
    $oldData = json_encode([
        'asset_tag' => $device['asset_tag'],
        'status' => $device['status']
    ]);
    $newData = json_encode([
        'asset_tag' => $device['asset_tag'],
        'status' => 'disposed',
        'disposed_by' => $currentUserId,
        'disposed_at' => date('Y-m-d H:i:s')
    ]);
    logAudit($currentUserId, 'Dispose', 'devices', $id, $oldData, $newData);
    
    // Notify IT staff
    $notificationTitle = "Device Disposed: " . $device['asset_tag'];
    $notificationMessage = "Device " . $device['asset_tag'] . " has been marked as disposed by " . $currentUserName . ".";
    notifyITStaff('device_disposed', $notificationTitle, $notificationMessage, $id);
    
    setFlashMessage('success', 'Device ' . $device['asset_tag'] . ' has been successfully moved to Retired/Disposed.');
    
} catch (Exception $e) {
    try {
        $pdo->rollBack();
    } catch (Exception $rbErr) {
        // Transaction might not be active
    }
    error_log("Device disposal error for ID $id: " . $e->getMessage());
    setFlashMessage('error', 'Failed to dispose device: ' . $e->getMessage());
}

header('Location: devices.php');
exit();
?>