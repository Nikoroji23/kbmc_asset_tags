<?php
/**
 * KBMC Asset Management - Device Change Request API
 * * ENDPOINT: POST /api_change_request_form.php
 * * Purpose:
 * Handles device change request form submissions from employees. When an employee requests a 
 * device change, this endpoint:
 * 1. Validates the request and device assignment
 * 2. Notifies all IT staff members via email and system notifications
 * 3. Creates audit logs for compliance tracking
 * 4. Confirms the request to the employee
 * * Request Format (JSON):
 * {
 * "assignment_id": 123,              // Required: Device assignment ID
 * "change_reason": "..."             // Optional: Why employee is requesting a change
 * }
 * * Response Format (JSON):
 * Success (200):
 * {
 * "success": true,
 * "message": "Change request form has been sent to IT...",
 * "assignment_id": 123,
 * "device_asset_tag": "KBM-IT-001492",
 * "notification_sent": true
 * }
 * * Error (4xx/5xx):
 * {
 * "success": false,
 * "message": "Error description"
 * }
 * * Notifications Sent:
 * 1. EMAIL to ALL IT staff & admins with:
 * - Employee name and department
 * - Device asset tag and type
 * - Change request submission time
 * - Direct link to IT Clearance / Review page
 * - Reason for change (if provided)
 * * 2. SYSTEM NOTIFICATION to employee:
 * - Confirms change request form submitted
 * - Reassures IT will contact them
 * * 3. AUDIT LOG entries:
 * - DEVICE_CHANGE_REQUESTED: Records details of the change request form
 * - DEVICE_CHANGE_INITIATED: Records the initiation of the device change pipeline
 * * Security:
 * - Requires user to be logged in (session check)
 * - Employee can only request changes for their own assigned devices
 * - Validates device assignment is active
 * * Database Impact:
 * - No direct modifications to device_assignments
 * - Creates entries in: notifications, audit_logs
 * - Updates: none (device_assignments status changes via IT Clearance/Review process)
 * * Related Files:
 * - user_asset_dashboard.php - UI with change request form button
 * - includes/functions.php - notifyITStaff(), logAudit()
 * - includes/email_config.php - Email sending setup
 * - it_clearance.php - Where IT staff handles the change/clearance pipeline
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
$changeReason = $input['change_reason'] ?? $input['return_reason'] ?? ''; // Accept both for backward compatibility with frontend JS templates
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
        echo json_encode(['success' => false, 'message' => 'You can only submit change requests for your own devices']);
        exit();
    }
    
    // Create notification for IT staff
    $title = 'Device Change Requested';
    $message = "{$assignment['employee_name']} ({$assignment['asset_tag']} - {$assignment['type_name']}) has submitted a change request form.";
    if ($changeReason) {
        $message .= " Reason: {$changeReason}";
    }

    $itNotificationResult = notifyITStaff(
        'user_clearance_required',
        $title,
        $message,
        $assignmentId
    );

    // Create in-app notification for the employee
    addNotificationIfNotExists(
        $userId,
        'change_request_submitted',
        'Change Request Form Submitted',
        "Your change request for {$assignment['asset_tag']} ({$assignment['type_name']}) has been submitted. IT staff will contact you to arrange clearance and pickup.",
        $assignment['device_id']
    );
    
    // Log the activity for compliance/audit purposes
    logAudit(
        $userId,
        'DEVICE_CHANGE_REQUESTED',
        'device_assignments',
        $assignmentId,
        "Change request form submitted for device: {$assignment['asset_tag']} ({$assignment['type_name']})" . ($changeReason ? ". Reason: {$changeReason}" : '')
    );
    
    // Log device change event
    logAudit(
        $userId,
        'DEVICE_CHANGE_INITIATED',
        'devices',
        $assignment['device_id'],
        "Device change pipeline initiated by employee {$assignment['employee_name']}. Asset Tag: {$assignment['asset_tag']}"
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Your change request form has been sent to IT. IT staff will contact you soon.',
        'assignment_id' => $assignmentId,
        'device_asset_tag' => $assignment['asset_tag'],
        'notification_sent' => true
    ]);
    
} catch (Exception $e) {
    error_log("Error in api_change_request_form.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request. Please try again.'
    ]);
}
?>