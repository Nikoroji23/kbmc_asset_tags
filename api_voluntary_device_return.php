<?php
/**
 * KBMC Asset Management - Voluntary Device Return API
 * 
 * ENDPOINT: POST /api_voluntary_device_return.php
 * 
 * Purpose:
 * Handles voluntary device returns from employees. When an employee requests to 
 * voluntarily return a device, this endpoint:
 * 1. Validates the request and device assignment
 * 2. Notifies all IT staff members via email and system notifications
 * 3. Creates audit logs for compliance tracking
 * 4. Confirms the request to the employee
 * 
 * Request Format (JSON):
 * {
 *   "assignment_id": 123,              // Required: Device assignment ID
 *   "return_reason": "..."             // Optional: Why employee is returning device
 * }
 * 
 * Response Format (JSON):
 * Success (200):
 * {
 *   "success": true,
 *   "message": "Return request submitted successfully...",
 *   "assignment_id": 123,
 *   "device_asset_tag": "KBM-IT-001492",
 *   "notification_sent": true
 * }
 * 
 * Error (4xx/5xx):
 * {
 *   "success": false,
 *   "message": "Error description"
 * }
 * 
 * Notifications Sent:
 * 1. EMAIL to ALL IT staff & admins with:
 *    - Employee name and department
 *    - Device asset tag and type
 *    - Return request time
 *    - Direct link to IT Clearance page
 *    - Return reason (if provided)
 * 
 * 2. SYSTEM NOTIFICATION to employee:
 *    - Confirms return request submitted
 *    - Reassures IT will contact them
 * 
 * 3. AUDIT LOG entries:
 *    - VOLUNTARY_RETURN: Records return request details
 *    - DEVICE_RETURN_INITIATED: Records device return initiation
 * 
 * Security:
 * - Requires user to be logged in (session check)
 * - Employee can only request return for their own devices
 * - Validates device assignment is active
 * 
 * Database Impact:
 * - No direct modifications to device_assignments
 * - Creates entries in: notifications, audit_logs
 * - Updates: none (device_assignments status changes via IT Clearance process)
 * 
 * Related Files:
 * - user_asset_dashboard.php - UI with voluntary return button
 * - includes/functions.php - notifyITStaff(), logAudit()
 * - includes/email_config.php - Email sending setup
 * - it_clearance.php - Where IT staff handles the return
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Verify user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Get JSON payload
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['assignment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing assignment_id']);
    exit();
}

$assignmentId = (int)$input['assignment_id'];
$returnReason = $input['return_reason'] ?? '';
$userId = $_SESSION['user_id'];

try {
    // Get assignment details with all required fields for notifications
    $stmt = $pdo->prepare(
        "SELECT da.*, d.asset_tag, dt.type_name, d.id as device_id, u.full_name as employee_name, u.email as employee_email
         FROM device_assignments da
         JOIN devices d ON da.device_id = d.id
         JOIN device_types dt ON d.device_type_id = dt.id
         JOIN users u ON da.employee_id = u.id
         WHERE da.id = ? AND da.status = 'active'"
    );
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid assignment or device not found']);
        exit();
    }
    
    // Verify the employee making the request is the assigned employee
    if ((int)$assignment['employee_id'] !== (int)$userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only request return for your own devices']);
        exit();
    }
    
    // Create notification for IT staff - this sends both email and system notifications
    $title = 'Voluntary Device Return Requested';
    $message = "{$assignment['employee_name']} ({$assignment['asset_tag']} - {$assignment['type_name']}) has requested voluntary device return.";
    if ($returnReason) {
        $message .= " Reason: {$returnReason}";
    }
    
    // Notify IT staff - this function:
    // 1. Creates system notifications for all IT staff in the database
    // 2. Sends emails to all active IT staff with device/employee context
    // 3. Includes action URL to it_clearance.php
    $itNotificationResult = notifyITStaff(
        'user_clearance_required',
        $title,
        $message,
        $assignmentId
    );
    
    // Create in-app notification for the employee confirming their request
    addNotificationIfNotExists(
        $userId,
        'voluntary_return_requested',
        'Return Request Submitted',
        "Your return request for {$assignment['asset_tag']} ({$assignment['type_name']}) has been submitted. IT staff will contact you to arrange clearance and pickup.",
        $assignment['device_id']
    );
    
    // Log the activity for compliance/audit purposes
    logAudit(
        $userId,
        'VOLUNTARY_RETURN',
        'device_assignments',
        $assignmentId,
        "Voluntary return requested for device: {$assignment['asset_tag']} ({$assignment['type_name']})" . ($returnReason ? ". Reason: {$returnReason}" : '')
    );
    
    // Log device return event
    logAudit(
        $userId,
        'DEVICE_RETURN_INITIATED',
        'devices',
        $assignment['device_id'],
        "Device return initiated by employee {$assignment['employee_name']}. Asset Tag: {$assignment['asset_tag']}"
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Voluntary return request submitted successfully. IT staff will contact you soon.',
        'assignment_id' => $assignmentId,
        'device_asset_tag' => $assignment['asset_tag'],
        'notification_sent' => true
    ]);
    
} catch (Exception $e) {
    error_log("Error in api_voluntary_device_return.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request. Please try again.'
    ]);
}
?>
