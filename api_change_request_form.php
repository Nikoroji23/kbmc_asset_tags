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
 * "change_type": "Hardware",        // Required: Type of requested change
 * "change_details": "...",          // Required: Detailed explanation of the change request
 * "pdf_base64": "...",              // Optional: Base64-encoded PDF of the request form
 * "pdf_filename": "change_request_123.pdf" // Optional: Suggested PDF filename
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
 * - Updates device_assignments with change request metadata
 * - Creates entries in: notifications, audit_logs
 * - Updates: device_assignments (change request metadata)
 * * Related Files:
 * - user_asset_dashboard.php - UI with change request form button
 * - includes/functions.php - notifyITStaff(), logAudit()
 * - includes/email_config.php - Email sending setup
 * - it_clearance.php - Where IT staff handles the change/clearance pipeline
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

ensureChangeRequestSchema();

// Set JSON header
header('Content-Type: application/json');

// Verify user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Parse request payload from JSON or POST form data
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawInput = file_get_contents('php://input');
$input = [];

if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }
}

if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
}

if (!isset($input['assignment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing assignment_id']);
    exit();
}

$assignmentId = (int)$input['assignment_id'];
$changeType    = trim($input['change_type'] ?? '');
$changeDetails = trim($input['change_details'] ?? $input['change_reason'] ?? '');
$userId = $_SESSION['user_id'];

if ($changeType === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select the type of change request.']);
    exit();
}

if ($changeDetails === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please describe the requested change.']);
    exit();
}

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
    
    // Create notification for IT staff (concise message for email)
    $title = 'Device Change Requested';
    $message = "Change request for {$assignment['asset_tag']} by {$assignment['employee_name']}. Review required.";

    $emailAttachments = [];
    if (!empty($input['pdf_base64'])) {
        $pdfBase64 = $input['pdf_base64'];
        if (strpos($pdfBase64, 'base64,') !== false) {
            $pdfBase64 = substr($pdfBase64, strpos($pdfBase64, 'base64,') + 7);
        }

        $pdfData = base64_decode($pdfBase64);
        if ($pdfData === false || strlen($pdfData) === 0) {
            throw new Exception('Unable to decode submitted PDF data');
        }

        $uploadDir = __DIR__ . '/assets/uploads/change_requests';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Unable to create upload directory for change request PDFs');
        }

        $requestedFilename = basename(trim($input['pdf_filename'] ?? "change_request_{$assignmentId}_" . time() . '.pdf'));
        $safeFilename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $requestedFilename);
        if (strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION)) !== 'pdf') {
            $safeFilename .= '.pdf';
        }

        $pdfPath = $uploadDir . '/' . $safeFilename;
        if (file_put_contents($pdfPath, $pdfData) === false) {
            throw new Exception('Unable to save change request PDF to disk');
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $baseUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $pdfUrl = $protocol . $_SERVER['HTTP_HOST'] . $baseUrl . '/assets/uploads/change_requests/' . rawurlencode($safeFilename);
        // Save PDF to disk (do not append URL to the email message)
        // Do not attach the PDF file to IT notification emails.
    }

    // Persist the incoming request details so IT can load the form with the original request values.
    if (columnExists('device_assignments', 'change_request_type') && columnExists('device_assignments', 'change_request_details')) {
        $pdo->prepare(
            "UPDATE device_assignments SET change_request_type = ?, change_request_details = ?, change_request_pdf_url = ?, change_request_submitted_at = NOW() WHERE id = ?"
        )->execute([
            $changeType,
            $changeDetails,
            $pdfUrl ?? null,
            $assignmentId
        ]);
    } else {
        error_log('[api_change_request_form] change_request columns missing, skipping persistence.');
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
        'Change Request Submitted',
        "Change request for {$assignment['asset_tag']} submitted. IT will follow up.",
        $assignment['device_id']
    );
    
    // Log the activity for compliance/audit purposes
    logAudit(
        $userId,
        'DEVICE_CHANGE_REQUESTED',
        'device_assignments',
        $assignmentId,
        "Change request submitted for device: {$assignment['asset_tag']} ({$assignment['type_name']}). Type: {$changeType}. Details: {$changeDetails}"
    );
    
    // Log device change event
    logAudit(
        $userId,
        'DEVICE_CHANGE_INITIATED',
        'devices',
        $assignment['device_id'],
        "Device change pipeline initiated by employee {$assignment['employee_name']}. Asset Tag: {$assignment['asset_tag']}"
    );

    // Also write a clear, human-friendly audit entry to ensure visibility in IT Audit Log
    // (some views filter or match specific action text; this guarantees a consistent entry)
    logAudit(
        $userId,
        'Change Request Submitted',
        'device_assignments',
        $assignmentId,
        null,
        json_encode([
            'asset_tag' => $assignment['asset_tag'],
            'device_id' => $assignment['device_id'],
            'change_type' => $changeType,
            'change_details' => $changeDetails
        ]),
        'ChangeRequest'
    );

    // Ensure an explicit audit_logs row exists (fallback / debugging) so the UI can pick it up.
    try {
        $ipAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if (function_exists('auditLogsHasActivityTypeColumn') && auditLogsHasActivityTypeColumn()) {
            $ins = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, activity_type, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $userId,
                'Change Request Submitted',
                'device_assignments',
                $assignmentId,
                'ChangeRequest',
                null,
                json_encode([
                    'asset_tag' => $assignment['asset_tag'],
                    'device_id' => $assignment['device_id'],
                    'change_type' => $changeType,
                    'change_details' => $changeDetails
                ]),
                $ipAddr
            ]);
        } else {
            $ins = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $userId,
                'Change Request Submitted',
                'device_assignments',
                $assignmentId,
                null,
                json_encode([
                    'asset_tag' => $assignment['asset_tag'],
                    'device_id' => $assignment['device_id'],
                    'change_type' => $changeType,
                    'change_details' => $changeDetails
                ]),
                $ipAddr
            ]);
        }
    } catch (Exception $e) {
        error_log('[api_change_request_form] audit_logs insert failed: ' . $e->getMessage());
    }

    $response = [
        'success' => true,
        'message' => 'Change request sent. IT will follow up shortly.',
        'assignment_id' => $assignmentId,
        'device_asset_tag' => $assignment['asset_tag'],
        'notification_sent' => true
    ];

    if (!empty($pdfUrl)) {
        $response['pdf_url'] = $pdfUrl;
    }

    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error in api_change_request_form.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request. Please try again.'
    ]);
}
?>