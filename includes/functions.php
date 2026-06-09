<?php
/**
 * KBMC Asset Management - Helper Functions
 */

require_once __DIR__ . '/config.php';

// Load email config safely - check if file exists first
$emailConfigPath = __DIR__ . '/email_config.php';
if (file_exists($emailConfigPath)) {
    require_once $emailConfigPath;
} else {
    $email_settings = [
        'from_email' => 'noreply@kbmc.com',
        'from_name'  => 'KBMC Asset Management',
    ];
    function sendEmail($to, $subject, $body, $html = true) {
        return ['success' => false, 'message' => 'Email system not configured. Please set up includes/email_config.php'];
    }
    function isEmailConfigured() { return false; }
    function emailTemplate($title, $content, $buttonText = '', $buttonUrl = '') {
        return "<html><body><h2>{$title}</h2><div>{$content}</div></body></html>";
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!hasRole('admin')) {
        header('Location: dashboard.php');
        exit();
    }
}

function requireITStaff() {
    requireLogin();
    if (!hasRole('admin') && !hasRole('it_staff')) {
        header('Location: dashboard.php');
        exit();
    }
}

function requireITStaffOnly() {
    requireLogin();
    if (!hasRole('it_staff')) {
        header('Location: dashboard.php');
        exit();
    }
}

function isValidRole($role) {
    $allowed_roles = ['admin', 'it_staff', 'employee'];
    return in_array($role, $allowed_roles);
}

function getAllowedRoles() {
    return ['admin', 'it_staff', 'employee'];
}

function isValidEmail($email) {
    // Permissive email validation that allows special characters
    // Checks for: local@domain format with basic validation
    if (empty($email)) {
        return false;
    }
    
    // Basic structure check: must have @ symbol and domain
    if (strpos($email, '@') === false) {
        return false;
    }
    
    list($local, $domain) = explode('@', $email, 2);
    
    // Local part must not be empty
    if (empty($local)) {
        return false;
    }
    
    // Domain part must have at least one dot
    if (strpos($domain, '.') === false) {
        return false;
    }
    
    // Domain must have valid structure
    $domainParts = explode('.', $domain);
    if (count($domainParts) < 2) {
        return false;
    }
    
    // Last part (TLD) must be at least 2 characters
    $tld = end($domainParts);
    if (strlen($tld) < 2) {
        return false;
    }
    
    // Check for valid characters using regex (allows special chars like +, -, ., parentheses)
    // Local part: alphanumeric, dots, hyphens, underscores, plus signs, parentheses
    if (!preg_match('/^[a-zA-Z0-9._+%()\-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) {
        return false;
    }
    
    return true;
}

function getUserInfo($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function getUnreadNotificationCount($userId) {
    global $pdo;
    
    // Get user role
    $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    $userRole = $user['role'] ?? 'employee';
    
    // IT staff see ALL unread notifications
    if ($userRole === 'it_staff') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
    } 
    // Admins only count admin-relevant notifications
    else if ($userRole === 'admin') {
        $adminTypes = [
            'audit_reminder',
            'user_approval_pending',
            'user_approval_requested',
            'request_approved',
            'request_rejected',
            'account_recovery_requested',
            'account_recovery_approved',
            'account_recovery_rejected',
            'it_user_created',
            'it_user_security_granted',
            'new_user_account_created',
            'user_creation_approved',
            'user_creation_rejected',
            'admin_alert_device_critical',
            'admin_alert_maintenance_overdue',
            'admin_alert_device_issue',
            'admin_alert_failed_logins',
            'admin_alert_security_warning',
            'admin_alert_system_alert',
            'admin_alert_custom'
        ];
        $placeholders = implode(',', array_fill(0, count($adminTypes), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND type IN ($placeholders)");
        $params = [$userId];
        foreach ($adminTypes as $type) {
            $params[] = $type;
        }
        $stmt->execute($params);
    } else {
        // Regular employees see all their unread notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
    }
    
    return $stmt->fetchColumn();
}

function getNotifications($userId, $limit = 5) {
    global $pdo;
    
    // Get user role to determine notification filtering
    $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    $userRole = $user['role'] ?? 'employee';
    
    // IT staff see ALL notifications (device, maintenance, repairs, etc.)
    if ($userRole === 'it_staff') {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
    } 
    // Admins only retrieve admin-relevant notifications
    else if ($userRole === 'admin') {
        $adminTypes = [
            'audit_reminder',
            'user_approval_pending',
            'user_approval_requested',
            'request_approved',
            'request_rejected',
            'account_recovery_requested',
            'account_recovery_approved',
            'account_recovery_rejected',
            'it_user_created',
            'it_user_security_granted',
            'new_user_account_created',
            'user_creation_approved',
            'user_creation_rejected',
            'admin_alert_device_critical',
            'admin_alert_maintenance_overdue',
            'admin_alert_device_issue',
            'admin_alert_failed_logins',
            'admin_alert_security_warning',
            'admin_alert_system_alert',
            'admin_alert_custom'
        ];
        $placeholders = implode(',', array_fill(0, count($adminTypes), '?'));
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND type IN ($placeholders) ORDER BY created_at DESC LIMIT ?");
        $params = [$userId];
        foreach ($adminTypes as $type) {
            $params[] = $type;
        }
        $params[] = $limit;
        $stmt->execute($params);
    } else {
        // Regular employees see all their notifications
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
    }
    
    return $stmt->fetchAll();
}

function isAdminRelevantNotification($type) {
    $adminTypes = [
        'low_stock',
        'audit_reminder',
        'user_approval_pending',
        'user_approval_requested',
        'request_approved',
        'request_rejected',
        'account_recovery_requested',
        'account_recovery_approved',
        'account_recovery_rejected',
        'it_user_created',
        'it_user_security_granted',
        'new_user_account_created',
        'user_creation_approved',
        'user_creation_rejected',
        'admin_alert_device_critical',
        'admin_alert_maintenance_overdue',
        'admin_alert_device_issue',
        'admin_alert_failed_logins',
        'admin_alert_security_warning',
        'admin_alert_system_alert',
        'admin_alert_custom'
    ];
    return in_array($type, $adminTypes);
}

/**
 * Get notification types for the 3 main admin features
 * 1. Security Features (master keys, recovery, security alerts)
 * 2. Request Approval (device requests)
 * 3. User Management (user accounts, approvals)
 */
function getAdminMainFeatureNotificationTypes() {
    return [
        // Security Features
        'account_recovery_requested',
        'account_recovery_approved',
        'account_recovery_rejected',
        'admin_alert_failed_logins',
        'admin_alert_security_warning',
        'admin_alert_system_alert',
        
        // Request Approval
        'request_approved',
        'request_rejected',
        
        // User Management
        'user_approval_requested',
        'user_creation_approved',
        'user_creation_rejected',
        'new_user_account_created'
    ];
}

/**
 * Categorize admin notifications by feature
 */
function categorizeAdminNotifications($notifications) {
    $categories = [
        'security' => [],
        'requests' => [],
        'users' => []
    ];
    
    foreach ($notifications as $notif) {
        $type = $notif['type'] ?? '';
        
        // Security Features
        if (in_array($type, ['account_recovery_requested', 'account_recovery_approved', 'account_recovery_rejected', 'admin_alert_failed_logins', 'admin_alert_security_warning', 'admin_alert_system_alert'])) {
            $categories['security'][] = $notif;
        }
        // Request Approval
        elseif (in_array($type, ['request_approved', 'request_rejected'])) {
            $categories['requests'][] = $notif;
        }
        // User Management
        elseif (in_array($type, ['user_approval_requested', 'user_creation_approved', 'user_creation_rejected', 'new_user_account_created'])) {
            $categories['users'][] = $notif;
        }
    }
    
    return $categories;
}

function filterUniqueEmails(array $recipients) {
    $seen = [];
    $unique = [];
    foreach ($recipients as $recipient) {
        $email = trim($recipient['email'] ?? '');
        if (!isValidEmail($email) || isset($seen[strtolower($email)])) {
            continue;
        }
        $seen[strtolower($email)] = true;
        $recipient['email'] = $email;
        $unique[] = $recipient;
    }
    return $unique;
}

function tableExists($table) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function columnExists($table, $column) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
        $stmt->execute([DB_NAME, $table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function ensureDeviceSchema() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!tableExists('devices')) {
        return;
    }

    try {
        if (!columnExists('devices', 'ip_address')) {
            $GLOBALS['pdo']->exec("ALTER TABLE devices ADD COLUMN ip_address VARCHAR(50) DEFAULT NULL AFTER serial_number");
        }
        if (!columnExists('devices', 'pc_name')) {
            $GLOBALS['pdo']->exec("ALTER TABLE devices ADD COLUMN pc_name VARCHAR(100) DEFAULT NULL AFTER ip_address");
        }
        if (!columnExists('devices', 'disposed_by')) {
            $GLOBALS['pdo']->exec("ALTER TABLE devices ADD COLUMN disposed_by INT DEFAULT NULL");
            $GLOBALS['pdo']->exec("ALTER TABLE devices ADD CONSTRAINT fk_disposed_by FOREIGN KEY (disposed_by) REFERENCES users(id) ON DELETE SET NULL");
        }
        if (!columnExists('devices', 'disposed_at')) {
            $GLOBALS['pdo']->exec("ALTER TABLE devices ADD COLUMN disposed_at TIMESTAMP NULL");
        }
    } catch (PDOException $e) {
        // If ALTER TABLE fails, allow the app to continue
    }
}

function ensureMaintenanceSchema() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!tableExists('maintenance_schedules')) {
        return;
    }

    try {
        if (!columnExists('maintenance_schedules', 'requested_by')) {
            $GLOBALS['pdo']->exec("ALTER TABLE maintenance_schedules ADD COLUMN requested_by INT DEFAULT NULL AFTER assigned_to");
        }
        if (!columnExists('maintenance_schedules', 'completed_by')) {
            $GLOBALS['pdo']->exec("ALTER TABLE maintenance_schedules ADD COLUMN completed_by INT DEFAULT NULL AFTER requested_by");
        }
        if (!columnExists('maintenance_schedules', 'completed_at')) {
            $GLOBALS['pdo']->exec("ALTER TABLE maintenance_schedules ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER completed_by");
        }
        if (!columnExists('maintenance_schedules', 'completion_notes')) {
            $GLOBALS['pdo']->exec("ALTER TABLE maintenance_schedules ADD COLUMN completion_notes TEXT DEFAULT NULL AFTER notes");
        }
    } catch (PDOException $e) {
        // If ALTER TABLE fails, continue gracefully.
    }
}

function ensureUserSecuritySchema() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!tableExists('users')) {
        return;
    }

    try {
        if (!columnExists('users', 'master_key')) {
            $GLOBALS['pdo']->exec("ALTER TABLE users ADD COLUMN master_key VARCHAR(64) DEFAULT NULL");
        }
        if (!columnExists('users', 'master_key_hash')) {
            $GLOBALS['pdo']->exec("ALTER TABLE users ADD COLUMN master_key_hash VARCHAR(255) DEFAULT NULL");
        }
        if (!columnExists('users', 'is_security_admin')) {
            $GLOBALS['pdo']->exec("ALTER TABLE users ADD COLUMN is_security_admin TINYINT(1) DEFAULT 0");
        }
    } catch (PDOException $e) {
        // If ALTER TABLE fails, continue gracefully.
    }
}

function addNotification($userId, $type, $title, $message, $relatedId = null) {
    global $pdo;
    // Prepare notification data and compute recipient-specific URL
    $notif = [
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'related_id' => $relatedId
    ];
    $url = null;
    try {
        $url = getNotificationUrl($notif, null);
    } catch (Exception $e) {
        // Fall back to null if URL resolution fails
        error_log('[ADD_NOTIFICATION] getNotificationUrl failed: ' . $e->getMessage());
        $url = null;
    }

    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, related_id, url) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $type, $title, $message, $relatedId, $url]);
    $notificationId = $pdo->lastInsertId();

    // Send email notification if configured
    if (isEmailConfigured()) {
        sendEmailNotificationToUser($userId, $type, $title, $message, $relatedId, $url);
    }

    return $notificationId;
}

function addSystemNotificationOnly($userId, $type, $title, $message, $relatedId = null) {
    global $pdo;
    $notif = [
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'related_id' => $relatedId
    ];
    $url = null;
    try { $url = getNotificationUrl($notif, null); } catch (Exception $e) { error_log('[SYS_NOTIF] getNotificationUrl failed: '.$e->getMessage()); }

    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, related_id, url) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $type, $title, $message, $relatedId, $url]);
    return $pdo->lastInsertId();
}

function addSystemNotificationOnlyIfNotExists($userId, $type, $title, $message, $relatedId = null) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND message = ? " .
        "AND ((related_id = ? ) OR (related_id IS NULL AND ? IS NULL)) LIMIT 1"
    );
    $stmt->execute([$userId, $type, $title, $message, $relatedId, $relatedId]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        return $existingId;
    }
    return addSystemNotificationOnly($userId, $type, $title, $message, $relatedId);
}

function addNotificationIfNotExists($userId, $type, $title, $message, $relatedId = null) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT id FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND message = ? " .
        "AND ((related_id = ? ) OR (related_id IS NULL AND ? IS NULL)) LIMIT 1"
    );
    $stmt->execute([$userId, $type, $title, $message, $relatedId, $relatedId]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        return $existingId;
    }
    return addNotification($userId, $type, $title, $message, $relatedId);
}

function notifyITStaff($type, $title, $message, $related_id = 0) {
    global $pdo;
    error_log("[NOTIFY_IT_STAFF] Called with: type='$type', title='$title', related_id=$related_id");
    
    // Fetch IT staff with their email addresses
    $itUsers = $pdo->query("SELECT id, email, full_name FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active'")->fetchAll();
    error_log("[NOTIFY_IT_STAFF] Found " . count($itUsers) . " IT staff members");
    
        // Create per-user system notifications (uses addSystemNotificationOnly to compute URL)
        foreach ($itUsers as &$user) {
            try {
                $nid = addSystemNotificationOnly($user['id'], $type, $title, $message, $related_id);
                // Ensure $user carries the computed URL for email sending
                $notif = [
                    'user_id' => $user['id'],
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'related_id' => $related_id
                ];
                $user['url'] = getNotificationUrl($notif, $user['role'] ?? null);
                error_log("[NOTIFY_IT_STAFF] Inserted notification id={$nid} for user {$user['id']} url=" . ($user['url'] ?? 'NULL'));
            } catch (Exception $e) {
                error_log("[NOTIFY_IT_STAFF] ❌ Failed to create notification for user {$user['id']}: " . $e->getMessage());
            }
        }
    
    // Send email notifications to all IT staff for ALL notification types
    if (isEmailConfigured() && !empty($itUsers)) {
        error_log("[NOTIFY_IT_STAFF_EMAIL] Sending email for type='$type' to " . count($itUsers) . " IT staff members");
        sendEmailNotificationToITStaff($type, $title, $message, $related_id, $itUsers);
    }
}

function sendEmailNotificationToITStaff($type, $title, $message, $related_id, $itUsers) {
    global $pdo;
    
    // Get additional context based on notification type
    $context = '';
    $deviceInfo = '';
    $employeeInfo = '';
    $actionUrl = '';
    
    if ($type === 'user_clearance_required' && $related_id > 0) {
        // Resolve assignment to get device and employee info
        $stmt = $pdo->prepare("
            SELECT da.id, da.employee_id, da.device_id, 
                   u.full_name, u.email, u.employee_id as emp_id, u.department,
                   d.asset_tag, d.vendor, dt.type_name
            FROM device_assignments da
            JOIN users u ON da.employee_id = u.id
            JOIN devices d ON da.device_id = d.id
            JOIN device_types dt ON d.device_type_id = dt.id
            WHERE da.id = ?
            LIMIT 1
        ");
        $stmt->execute([$related_id]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($assignment) {
            $employeeInfo = $assignment['full_name'] . " (ID: " . $assignment['emp_id'] . ", Dept: " . $assignment['department'] . ")";
            $deviceInfo = $assignment['asset_tag'] . " (" . $assignment['type_name'] . ")";
            $actionUrl = 'it_clearance.php?user_id=' . $assignment['employee_id'] . '&device_id=' . $assignment['device_id'] . '&assignment_id=' . $related_id;
            
            $context = "
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <p><strong>Clearance Request Details:</strong></p>
                    <p><i class='fas fa-user'></i> <strong>Employee:</strong> " . sanitize($employeeInfo) . "</p>
                    <p><i class='fas fa-laptop'></i> <strong>Device:</strong> " . sanitize($deviceInfo) . "</p>
                    <p><i class='fas fa-clock'></i> <strong>Requested:</strong> " . date('F d, Y g:i A') . "</p>
                </div>
            ";
        }
    } elseif ($type === 'user_clearance_completed' && $related_id > 0) {
        // Get user and their assigned devices info
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.employee_id, u.department
            FROM users u
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$related_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $employeeInfo = $user['full_name'] . " (ID: " . $user['employee_id'] . ", Dept: " . $user['department'] . ")";
            $actionUrl = 'it_clearance.php?user_id=' . $user['id'] . '&done=1';
            
            $context = "
                <div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #27ae60;'>
                    <p><strong>Clearance Completion Details:</strong></p>
                    <p><i class='fas fa-user'></i> <strong>Employee:</strong> " . sanitize($employeeInfo) . "</p>
                    <p><i class='fas fa-check-circle' style='color: #27ae60;'></i> <strong>Status:</strong> <span style='color: #27ae60;'>Clearance Completed</span></p>
                    <p><i class='fas fa-calendar'></i> <strong>Completion Date:</strong> " . date('F d, Y g:i A') . "</p>
                </div>
            ";
        }
    } elseif ($type === 'device_disposed' && $related_id > 0) {
        // Get device disposal info
        $stmt = $pdo->prepare("
            SELECT d.id, d.asset_tag, d.vendor, dt.type_name, 
                   d.serial_number, d.disposed_by, u.full_name as disposed_by_name, u.email as disposed_by_email
            FROM devices d
            JOIN device_types dt ON d.device_type_id = dt.id
            LEFT JOIN users u ON d.disposed_by = u.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$related_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device) {
            $deviceInfo = sanitize($device['asset_tag'] . " (" . $device['type_name'] . ")");
            $disposedByInfo = sanitize($device['disposed_by_name'] ?? 'System');
            
            $context = "
                <div style='background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                    <p><strong>Device Disposal Details:</strong></p>
                    <p><i class='fas fa-laptop'></i> <strong>Asset Tag:</strong> " . $deviceInfo . "</p>
                    <p><i class='fas fa-barcode'></i> <strong>Serial Number:</strong> " . sanitize($device['serial_number']) . "</p>
                    <p><i class='fas fa-brand'></i> <strong>Device Type:</strong> " . sanitize($device['type_name']) . "</p>
                    <p><i class='fas fa-building'></i> <strong>Vendor:</strong> " . sanitize($device['vendor'] ?? 'N/A') . "</p>
                    <p><i class='fas fa-user'></i> <strong>Disposed By:</strong> " . $disposedByInfo . "</p>
                    <p><i class='fas fa-calendar'></i> <strong>Disposal Date:</strong> " . date('F d, Y g:i A') . "</p>
                </div>
            ";
            $actionUrl = 'view_device.php?id=' . $device['id'];
        }
    }
    
    error_log("[NOTIFY_IT_STAFF_EMAIL] Preparing email body for " . count($itUsers) . " recipients");
    
    foreach ($itUsers as $staff) {
        if (empty($staff['email'])) {
            error_log("[NOTIFY_IT_STAFF_EMAIL] Skipping staff member (no email): " . $staff['full_name']);
            continue;
        }

        // Prefer precomputed per-staff URL when available
        $personalUrl = $staff['url'] ?? ($actionUrl ? ('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $actionUrl) : '');

        $emailBody = emailTemplate(
            $title,
            "<p>Hello <strong>" . sanitize($staff['full_name']) . "</strong>,</p>
            <p>" . sanitize($message) . "</p>" .
            $context .
            "<p style='margin-top: 20px; color: #666; font-size: 14px;'>This is an automated notification from the KBMC Asset Management System. Please review and take action as needed.</p>",
            $personalUrl ? 'Review in System' : '',
            $personalUrl
        );
        
        $subject = '[KBMC Alert] ' . $title;
        $result = sendEmail($staff['email'], $subject, $emailBody);
        
        if ($result['success'] ?? false) {
            error_log("[NOTIFY_IT_STAFF_EMAIL] ✓ Email sent to " . $staff['email']);
        } else {
            error_log("[NOTIFY_IT_STAFF_EMAIL] ❌ Email failed for " . $staff['email'] . ": " . ($result['message'] ?? 'Unknown error'));
        }
    }
}

function sendEmailNotificationToUser($userId, $type, $title, $message, $relatedId = null, $url = null) {
    global $pdo;
    
    // Get user email
    $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || empty($user['email'])) {
        error_log("[EMAIL_NOTIFICATION] Skipping - no email for user ID $userId");
        return;
    }
    
    error_log("[EMAIL_NOTIFICATION] Preparing email for user: " . $user['email'] . " (type: $type)");
    
    // Build context based on notification type
    $context = '';
    $actionUrl = '';
    
    // Get additional details based on notification type
    if ($relatedId) {
        switch ($type) {
            case 'device_deployed':
                $stmt = $pdo->prepare("SELECT asset_tag, dt.type_name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ?");
                $stmt->execute([$relatedId]);
                $device = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($device) {
                    $context = "
                        <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                            <p><strong>Device Details:</strong></p>
                            <p><i class='fas fa-laptop'></i> <strong>Asset Tag:</strong> " . sanitize($device['asset_tag']) . "</p>
                            <p><i class='fas fa-info-circle'></i> <strong>Device Type:</strong> " . sanitize($device['type_name']) . "</p>
                        </div>
                    ";
                    $actionUrl = 'view_device.php?id=' . $relatedId;
                }
                break;
                
            case 'device_returned':
            case 'request_approved':
            case 'request_rejected':
                $actionUrl = 'deployments.php';
                break;
                
            case 'repair_needed':
                $stmt = $pdo->prepare("SELECT asset_tag FROM devices WHERE id = ?");
                $stmt->execute([$relatedId]);
                $device = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($device) {
                    $context = "
                        <div style='background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f5c6cb;'>
                            <p><strong>Device:</strong> " . sanitize($device['asset_tag']) . "</p>
                        </div>
                    ";
                    $actionUrl = 'repairs.php';
                }
                break;
                
            case 'maintenance_assigned':
            case 'maintenance_due':
            case 'maintenance_completed':
                $actionUrl = 'maintenance_reminders.php';
                break;

            case 'user_clearance_completed':
                $context = "
                    <div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #27ae60;'>
                        <p><i class='fas fa-check-circle' style='color: #27ae60;'></i> <strong>Your IT clearance has been successfully completed.</strong></p>
                        <p>All your assigned devices have been processed and returned to stock. You're all set!</p>
                        <p><i class='fas fa-calendar'></i> <strong>Completion Date:</strong> " . date('F d, Y g:i A') . "</p>
                    </div>
                ";
                // Link directly to the clearance summary so user can view/print details
                $actionUrl = $relatedId ? 'it_clearance.php?user_id=' . $relatedId . '&done=1' : 'it_clearance.php';
                break;
        }
    }
    
    // Prefer passed URL when available
    $finalActionUrl = $url ?: ($actionUrl ? ('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $actionUrl) : '');

    // Create email body
    $emailBody = emailTemplate(
        $title,
        "<p>Hello <strong>" . sanitize($user['full_name']) . "</strong>,</p>
        <p>" . sanitize($message) . "</p>" .
        $context .
        "<p style='margin-top: 20px; color: #666; font-size: 14px;'>This is an automated notification from the KBMC Asset Management System.</p>",
        $finalActionUrl ? 'View Details' : '',
        $finalActionUrl
    );
    
    $subject = '[KBMC] ' . $title;
    $result = sendEmail($user['email'], $subject, $emailBody);
    
    if ($result['success'] ?? false) {
        error_log("[EMAIL_NOTIFICATION] ✓ Email sent to " . $user['email'] . " for $type notification");
    } else {
        error_log("[EMAIL_NOTIFICATION] ❌ Email failed for " . $user['email'] . ": " . ($result['message'] ?? 'Unknown error'));
    }
}

function resolveClearanceAssignment(int $refId): array {
    error_log("[CLEARANCE_DEBUG] resolveClearanceAssignment called with refId=$refId");
    
    $stmt = $GLOBALS['pdo']->prepare("SELECT employee_id, device_id FROM device_assignments WHERE id = ? LIMIT 1");
    $stmt->execute([$refId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($assignment) {
        error_log("[CLEARANCE_DEBUG] Found assignment by ID: employee_id={$assignment['employee_id']}, device_id={$assignment['device_id']}");
        return [(int)$assignment['employee_id'], (int)$assignment['device_id']];
    }

    error_log("[CLEARANCE_DEBUG] Assignment not found by ID, trying device_id fallback");
    $stmt = $GLOBALS['pdo']->prepare("SELECT employee_id, device_id FROM device_assignments WHERE device_id = ? AND status = 'active' ORDER BY assigned_date DESC LIMIT 1");
    $stmt->execute([$refId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($assignment) {
        error_log("[CLEARANCE_DEBUG] Found assignment by device_id: employee_id={$assignment['employee_id']}, device_id={$assignment['device_id']}");
        return [(int)$assignment['employee_id'], (int)$assignment['device_id']];
    }

    error_log("[CLEARANCE_DEBUG] No assignment found for refId=$refId, returning [0, 0]");
    return [0, 0];
}

function getNotificationUrl(array $notif, ?string $role = null): string
{
    // If URL was precomputed and stored on the notification, return it immediately
    if (!empty($notif['url'])) {
        return $notif['url'];
    }

    $rawType = $notif['type'] ?? '';
    $type  = strtolower(trim($rawType));
    $title = strtolower(trim($notif['title'] ?? ''));
    $refId = (int)($notif['related_id'] ?? 0);

    // Determine role: prefer passed-in role, else try to resolve from user_id, else session, default employee
    if ($role === null) {
        $role = 'employee';
        if (!empty($notif['user_id'])) {
            try {
                $uStmt = $GLOBALS['pdo']->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([(int)$notif['user_id']]);
                $ur = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($ur && !empty($ur['role'])) {
                    $role = $ur['role'];
                }
            } catch (Exception $e) {
                // ignore and fall back to session
            }
        } elseif (isset($_SESSION['role'])) {
            $role = $_SESSION['role'];
        }
    }

    $isAdmin = $role === 'admin';
    $isIT  = $role === 'it_staff';
    
    // Normalize notification type and detect legacy clearance completion variants.
    $isClearanceCompleted = str_contains($type, 'clearance_completed')
        || str_contains($type, 'clearance complete')
        || str_contains($title, 'clearance completed')
        || str_contains($title, 'clearance complete');

    $isDevicesDeployed = $type === 'device_deployed_bulk'
        || str_contains($title, 'devices deployed');

    // Log all notification URL requests (include raw vs normalized type)
    error_log("[NOTIF_URL_FLOW] Processing rawType='$rawType', normalizedType='$type', title='$title', refId=$refId, role='$role', isAdmin=$isAdmin, isIT=$isIT");
    
    // Log all user_clearance_completed notifications
    if ($isClearanceCompleted) {
        error_log("[NOTIF_URL_SELECTOR] user_clearance_completed detected - type='$type', title='$title', refId=$refId, role='$role', isIT=" . ($isIT ? 'true' : 'false') . ", isAdmin=" . ($isAdmin ? 'true' : 'false'));
    }
    
    // Log all user_creation_approved notifications
    if ($type === 'user_creation_approved') {
        error_log("[NOTIF_URL_SELECTOR] user_creation_approved detected - type='$type', refId=$refId, role='$role', isIT=" . ($isIT ? 'true' : 'false') . ", isAdmin=" . ($isAdmin ? 'true' : 'false'));
    }

    // Always link completed clearances to the IT clearance details page
    if ($isClearanceCompleted) {
        $targetUserId = $refId ?: (int)($notif['user_id'] ?? 0);
        return $targetUserId ? 'it_clearance.php?user_id=' . $targetUserId . '&done=1' : 'it_clearance.php?done=1';
    }

    // Legacy or missing-type devices deployed notifications
    if ($isDevicesDeployed) {
        return $refId ? 'view_device.php?id=' . $refId : 'deployments.php';
    }

    // ADMIN-SPECIFIC NOTIFICATIONS
    if ($isAdmin) {
        switch ($type) {
            case 'device_deployed':
            case 'device_deployed_bulk':
                return $refId ? 'view_device.php?id=' . $refId : 'deployments.php';

            case 'device_returned':
            case 'device_disposed':
                return $refId ? 'view_device.php?id=' . $refId : 'deployments.php';

            case 'new_device_added':
                return $refId ? 'view_device.php?id=' . $refId : 'devices.php';

            case 'low_stock':
                return 'devices.php';

            case 'account_recovery_requested':
            case 'account_recovery_approved':
            case 'account_recovery_rejected':
                return 'recovery_requests.php';

            case 'user_approval_requested':
            case 'user_creation_approved':
            case 'user_creation_rejected':
                return 'users.php?tab=approvals';

            case 'it_user_created':
            case 'it_user_security_granted':
                return 'users.php';

            case 'new_user_account_created':
                return 'admin_accounts.php';

            case 'audit_reminder':
                return 'recovery_requests.php';

            case 'admin_alert_device_critical':
            case 'admin_alert_maintenance_overdue':
            case 'admin_alert_device_issue':
                return $refId ? 'view_device.php?id=' . $refId : 'devices.php';

            case 'admin_alert_failed_logins':
            case 'admin_alert_security_warning':
            case 'admin_alert_system_alert':
            case 'admin_alert_custom':
                return 'admin_dashboard.php';

            case 'user_clearance_completed':
                return $refId ? 'it_clearance.php?user_id=' . $refId . '&done=1' : 'it_clearance.php';

            default:
                return 'admin_dashboard.php';
        }
    }

    // IT STAFF NOTIFICATIONS
    if ($isIT) {
        if (str_starts_with($type, 'lifespan_')) {
            return $refId
                ? 'device_lifespan.php?device_id=' . $refId
                : 'device_lifespan.php';
        }

        switch ($type) {
            case 'user_approval_pending':
            case 'user_approval_requested':
                return 'security_control.php';
            
            case 'it_user_created':
            case 'it_user_security_granted':
                return 'assign_security_it.php';
            
            case 'repair_needed':
            case 'repair_pending':
                return 'maintenance_repairs.php';

            case 'device_deployed':
            case 'device_deployed_bulk':
            case 'device_returned':
            case 'voluntary_return_requested':
                return 'deployments.php';

            case 'maintenance_assigned':
            case 'maintenance_completed':
            case 'maintenance_due':
                return 'maintenance_repairs.php';

            case 'warranty_expiring':
                return $refId
                    ? 'view_device.php?id=' . $refId
                    : 'devices.php';

            case 'user_clearance_completed':
                $url = $refId ? 'it_clearance.php?user_id=' . $refId . '&done=1' : 'it_clearance.php';
                error_log("[CLEARANCE_URL_IT] user_clearance_completed: refId=$refId, isIT=true, returning URL: $url");
                return $url;

            case 'user_clearance_required':
                error_log("[CLEARANCE_DEBUG] user_clearance_required: refId=$refId, isIT=" . ($isIT ? 'true' : 'false'));
                if ($refId) {
                    [$employeeId, $deviceId] = resolveClearanceAssignment($refId);
                    error_log("[CLEARANCE_DEBUG] After resolution: employeeId=$employeeId, deviceId=$deviceId");
                    if ($employeeId && $deviceId) {
                        $url = 'it_clearance.php?user_id=' . $employeeId . '&device_id=' . $deviceId . '&assignment_id=' . $refId;
                        error_log("[CLEARANCE_DEBUG] Generated URL (both IDs): $url");
                        return $url;
                    }
                    if ($employeeId) {
                        $url = 'it_clearance.php?user_id=' . $employeeId . '&assignment_id=' . $refId;
                        error_log("[CLEARANCE_DEBUG] Generated URL (employeeId only): $url");
                        return $url;
                    }
                    if ($deviceId) {
                        $url = 'it_clearance.php?device_id=' . $deviceId . '&assignment_id=' . $refId;
                        error_log("[CLEARANCE_DEBUG] Generated URL (deviceId only): $url");
                        return $url;
                    }
                }
                error_log("[CLEARANCE_DEBUG] No resolution found, using fallback");
                return $refId ? 'it_clearance.php?user_id=' . $refId : 'it_clearance.php';

            case 'audit_reminder':
                return 'recovery_requests.php';

            case 'account_recovery_requested':
            case 'account_recovery_approved':
            case 'account_recovery_rejected':
                return 'recovery_requests.php';

            case 'user_creation_approved':
            case 'user_creation_rejected':
            case 'user_approval_requested':
                $url = 'assign_security_it.php';
                error_log("[ACCOUNT_APPROVED_IT] user_creation_approved/rejected: refId=$refId, returning URL: $url");
                return $url;

            case 'device_request':
                return 'requests.php';

            case 'device_returned':
            case 'device_disposed':
                return $refId ? 'view_device.php?id=' . $refId : 'deployments.php';

            case 'new_device_added':
                return $refId ? 'view_device.php?id=' . $refId : 'devices.php';

            case 'low_stock':
                return 'devices.php';

            default:
                // Fallback: if there is a related_id, direct to device view; otherwise go to dashboard
                if ($refId) {
                    return 'view_device.php?id=' . $refId;
                }
                return 'dashboard.php';
        }
    }

    switch ($type) {
        case 'device_deployed':
        case 'device_deployed_bulk':
            return $refId
                ? 'view_device.php?id=' . $refId
                : 'deployments.php';

        case 'device_returned':
            return $refId ? 'view_device.php?id=' . $refId : 'deployments.php';

        case 'device_disposed':
            return $refId ? 'view_device.php?id=' . $refId : 'dashboard.php';

        case 'request_approved':
        case 'request_rejected':
            return 'requests.php';

        case 'account_recovery_approved':
        case 'account_recovery_rejected':
            return 'requests.php';

        case 'user_creation_approved':
        case 'user_creation_rejected':
        case 'user_approval_requested':
            return 'dashboard.php';

        case 'new_user_account_created':
            return 'dashboard.php';

        case 'user_clearance_completed':
            $url = $refId ? 'it_clearance.php?user_id=' . $refId . '&done=1' : 'it_clearance.php?done=1';
            error_log("[CLEARANCE_URL_EMPLOYEE] user_clearance_completed: refId=$refId, role=$role, returning URL: $url");
            return $url;

        case 'user_clearance_required':
            error_log("[CLEARANCE_DEBUG] user_clearance_required (employee view): refId=$refId");
            if ($refId) {
                [$employeeId, $deviceId] = resolveClearanceAssignment($refId);
                error_log("[CLEARANCE_DEBUG] After resolution: employeeId=$employeeId, deviceId=$deviceId");
                if ($employeeId && $deviceId) {
                    $url = 'it_clearance.php?user_id=' . $employeeId . '&device_id=' . $deviceId . '&assignment_id=' . $refId;
                    error_log("[CLEARANCE_DEBUG] Generated URL (both IDs): $url");
                    return $url;
                }
                if ($employeeId) {
                    $url = 'it_clearance.php?user_id=' . $employeeId . '&assignment_id=' . $refId;
                    error_log("[CLEARANCE_DEBUG] Generated URL (employeeId only): $url");
                    return $url;
                }
                if ($deviceId) {
                    $url = 'it_clearance.php?device_id=' . $deviceId . '&assignment_id=' . $refId;
                    error_log("[CLEARANCE_DEBUG] Generated URL (deviceId only): $url");
                    return $url;
                }
            }
            error_log("[CLEARANCE_DEBUG] No resolution found, using fallback");
            return $refId ? 'it_clearance.php?user_id=' . $refId : 'dashboard.php';

        case 'maintenance_assigned':
        case 'maintenance_due':
            return 'maintenance_reminders.php';

        case 'maintenance_completed':
            return 'dashboard.php';

        case 'repair_needed':
        case 'repair_pending':
            return $refId
                ? 'view_device.php?id=' . $refId
                : 'dashboard.php';

        default:
            // Fallback for non-IT/admin users: if related_id looks like a device, link to view_device, else dashboard
            if ($refId) {
                return 'view_device.php?id=' . $refId;
            }
            return 'dashboard.php';
    }
}

function createPasswordResetToken($userId) {
    global $pdo;
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $token, $expires]);
    return $token;
}

function getPasswordResetLink($token) {
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/') . '/reset_password.php?token=' . $token;
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname($_SERVER['PHP_SELF']);
    $basePath = $basePath === '/' ? '' : $basePath;
    return $protocol . '://' . $host . $basePath . '/reset_password.php?token=' . $token;
}

function sendPasswordResetEmail($userEmail, $fullName, $resetLink) {
    $emailBody = emailTemplate(
        'Password Reset Link',
        "<p>Hello <strong>" . sanitize($fullName) . "</strong>,</p>
        <p>Your account recovery request was approved. Please use the link below to reset your password.</p>
        <p style='margin: 30px 0;'><strong>Note:</strong> This link expires in <strong>1 hour</strong>.</p>",
        'Reset Password',
        $resetLink
    );
    return sendEmail($userEmail, 'Account Recovery Approved - Reset Your Password', $emailBody);
}

function logAudit($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null, $activityType = null) {
    global $pdo;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Auto-detect activity type from table name if not provided
    if ($activityType === null) {
        if ($tableName === 'device_repairs') {
            $activityType = 'Repair';
        } elseif ($tableName === 'maintenance_schedules') {
            $activityType = 'Maintenance';
        } elseif ($tableName === 'device_assignments') {
            $activityType = 'Assignment';
        } elseif ($tableName === 'devices') {
            $activityType = 'Device';
        }
    }

    $columns = ['user_id', 'action', 'table_name', 'record_id', 'old_values', 'new_values', 'ip_address'];
    $values = [$userId, $action, $tableName, $recordId, $oldValues, $newValues, $ipAddress];

    if ($activityType !== null && auditLogsHasActivityTypeColumn()) {
        array_splice($columns, 4, 0, 'activity_type');
        array_splice($values, 4, 0, $activityType);
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('INSERT INTO audit_logs (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($values);
}

function auditLogsHasActivityTypeColumn() {
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    global $pdo;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'activity_type'");
        $hasColumn = (bool)$stmt->fetch();
    } catch (Exception $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function tableHasColumn($table, $column) {
    static $cache = [];
    $key = $table . '::' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function ensureDeviceRepairsSchema() {
    global $pdo;

    $columnsToCheck = [
        'severity' => "ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium' AFTER issue_description",
        'issue_category' => "VARCHAR(50) DEFAULT 'other' AFTER severity",
        'incident_report_file' => "VARCHAR(255) DEFAULT NULL AFTER issue_category",
        'repair_status' => "ENUM('pending', 'under_repair', 'completed', 'cancelled') DEFAULT 'pending' AFTER incident_report_file",
        'completed_date' => "DATETIME DEFAULT NULL AFTER repair_status",
        'repair_notes' => "TEXT DEFAULT NULL AFTER completed_date",
        'completed_by' => "INT DEFAULT NULL AFTER repair_notes"
    ];

    foreach ($columnsToCheck as $column => $definition) {
        if (!tableHasColumn('device_repairs', $column)) {
            try {
                $pdo->exec("ALTER TABLE device_repairs ADD COLUMN $column $definition");
            } catch (Exception $e) {
                // If the column could not be added, preserve the original exception for later use.
                throw new Exception("Failed to ensure device_repairs column '$column': " . $e->getMessage());
            }
        }
    }
}

function getStatusBadge($status) {
    global $status_colors;
    $color = $status_colors[$status] ?? '#6C757D';
    $label = str_replace('_', ' ', ucwords($status));
    return '<span class="status-badge" style="background-color: ' . $color . '20; color: ' . $color . '; border: 1px solid ' . $color . ';">' . $label . '</span>';
}

function getDeviceCountByStatus($status) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE status = ?");
    $stmt->execute([$status]);
    return $stmt->fetchColumn();
}

function getTotalDeviceCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM devices WHERE status NOT IN ('retired', 'disposed')")->fetchColumn();
}

function getActiveAssignmentCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM device_assignments WHERE status = 'active'")->fetchColumn();
}

function getLowStockTypes() {
    global $pdo;
    $stmt = $pdo->query("SELECT dt.type_name, COUNT(d.id) as count FROM device_types dt LEFT JOIN devices d ON dt.id = d.device_type_id AND d.status = 'in_stock' GROUP BY dt.id HAVING count <= 2");
    return $stmt->fetchAll();
}

function formatDate($date, $format = 'M d, Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

function generateAssetTag($deviceTypeId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT type_name FROM device_types WHERE id = ?");
    $stmt->execute([$deviceTypeId]);
    $type = $stmt->fetch();
    $prefix = 'KBM-IT-';
    $stmt = $pdo->query("SELECT COUNT(*) FROM devices");
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 5, '0', STR_PAD_LEFT);
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function setFlashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect($url) {
    header('Location: ' . $url);
    exit();
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

function csrfInputField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function getAssignedAssets($userId) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT d.asset_tag,
                d.pc_name,
                d.ip_address,
                CONCAT(d.vendor, ' - ', dt.type_name) AS name,
                dt.type_name AS category,
                d.status,
                da.assigned_date AS assigned_at
         FROM device_assignments da
         JOIN devices d ON da.device_id = d.id
         LEFT JOIN device_types dt ON d.device_type_id = dt.id
         WHERE da.employee_id = ? AND da.status = 'active'
         ORDER BY da.assigned_date DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function toggleUserStatus($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        return false;
    }

    $newStatus = $status === 'active' ? 'inactive' : 'active';
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    return $newStatus;
}

function deleteUserById($userId) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, device_id FROM device_assignments WHERE employee_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignments as $a) {
            $pdo->prepare("UPDATE device_assignments SET status = 'returned', returned_date = CURDATE() WHERE id = ?")->execute([$a['id']]);
            $pdo->prepare("UPDATE devices SET status = 'in_stock', location = 'IT Stock Room' WHERE id = ?")->execute([$a['device_id']]);

            $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($admins as $admin) {
                if (!empty($admin['id'])) {
                    addNotification($admin['id'], 'device_returned', 'Device Returned', "Device with ID {$a['device_id']} has been returned to stock due to user removal.", $a['device_id']);
                }
            }

            if (session_status() == PHP_SESSION_NONE) {
                @session_start();
            }
            $currentUserId = $_SESSION['user_id'] ?? null;
            logAudit($currentUserId, 'AutoReturn', 'device_assignments', $a['id']);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $res = $stmt->execute([$userId]);

        $pdo->commit();
        return $res;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

function processRecoveryRequest($recoveryId, $action, $adminId) {
    global $pdo;
    $validActions = ['approve' => 'approved', 'reject' => 'rejected'];
    if (!isset($validActions[$action])) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT user_id FROM account_recovery_requests WHERE id = ?");
    $stmt->execute([$recoveryId]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        return false;
    }

    $pdo->prepare("UPDATE account_recovery_requests SET status = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?")
        ->execute([$validActions[$action], $adminId, $recoveryId]);

    if ($action === 'approve') {
        $pdo->prepare("UPDATE users SET status = 'active', failed_logins = 0, locked_until = NULL WHERE id = ?")
            ->execute([$userId]);
        addNotification($userId, 'request_approved', 'Account Recovered', 'Your account has been reactivated. You can now log in.', $recoveryId);

        $user = getUserInfo($userId);
        if ($user && !empty($user['email']) && isEmailConfigured()) {
            $token = createPasswordResetToken($userId);
            $resetLink = getPasswordResetLink($token);
            sendPasswordResetEmail($user['email'], $user['full_name'], $resetLink);
        }
    } else {
        addNotification($userId, 'request_rejected', 'Account Recovery Rejected', 'Your account recovery request was rejected. Contact admin for more info.', $recoveryId);
    }

    return true;
}

function downloadCSV($filename, $headers, $data) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ============================================================
// MASTER KEY & SECURITY FUNCTIONS
// ============================================================

// FIXED: removed dead $user_id check, use only $userId
function isSecurityAdmin($userId = null) {
    global $pdo;
    if (!$userId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT role, is_security_admin FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result && $result['is_security_admin'] == 1 && $result['role'] === 'it_staff';
}

function setSecurityITApprover($userId, $enabled = true) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET is_security_admin = ? WHERE id = ? AND role = 'it_staff'");
    return $stmt->execute([$enabled ? 1 : 0, $userId]);
}

function generateMasterKey($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function setMasterKey($userId, $masterKey) {
    global $pdo;
    $hashedKey = password_hash($masterKey, PASSWORD_BCRYPT);
    // Allow setting master key hash for any user (admins or it_staff)
    $stmt = $pdo->prepare("UPDATE users SET master_key_hash = ?, is_security_admin = 1 WHERE id = ?");
    $stmt->execute([$hashedKey, $userId]);
    return $stmt->rowCount() > 0;
}

function verifyMasterKey($userId, $masterKey) {
    global $pdo;
    $masterKey = trim((string)$masterKey);
    if ($masterKey === '') {
        logSecurityKeyUsage($userId, 'Key Verification Failed', false);
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, email, role, master_key_hash, master_key FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();

    if (!$result && !empty($_SESSION['email'])) {
        $fallbackStmt = $pdo->prepare("SELECT id, email, role, master_key_hash, master_key FROM users WHERE email = ? LIMIT 1");
        $fallbackStmt->execute([$_SESSION['email']]);
        $result = $fallbackStmt->fetch();
    }

    if ($result) {
        $plainStored = trim($result['master_key'] ?? '');
        $okHash = !empty($result['master_key_hash']) && password_verify($masterKey, $result['master_key_hash']);
        $okPlain = $plainStored !== '' && (hash_equals($plainStored, $masterKey) || (ctype_xdigit($plainStored) && ctype_xdigit($masterKey) && hash_equals(strtoupper($plainStored), strtoupper($masterKey))));
        $debug = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'session_email' => $_SESSION['email'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'submitted' => $masterKey,
            'submitted_length' => strlen($masterKey),
            'stored_plain' => $plainStored,
            'has_hash' => !empty($result['master_key_hash']),
            'ok_hash' => $okHash,
            'ok_plain' => $okPlain
        ];
        file_put_contents(__DIR__ . '/../master_key_debug.log', json_encode($debug) . PHP_EOL, FILE_APPEND);

        if ($okHash) {
            logSecurityKeyUsage($userId, 'Key Verified', true);
            $_SESSION['master_key_verified'] = true;
            $_SESSION['master_key_verified_at'] = time();
            return true;
        }

        if ($okPlain) {
            $hashedKey = password_hash($masterKey, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare("UPDATE users SET master_key_hash = ? WHERE id = ?");
            $updateStmt->execute([$hashedKey, $userId]);
            logSecurityKeyUsage($userId, 'Key Verified (legacy)', true);
            $_SESSION['master_key_verified'] = true;
            $_SESSION['master_key_verified_at'] = time();
            return true;
        }
    } else {
        file_put_contents(__DIR__ . '/../master_key_debug.log', json_encode(['timestamp' => date('Y-m-d H:i:s'), 'user_id' => $userId, 'found' => false, 'session_email' => $_SESSION['email'] ?? null, 'role' => $_SESSION['role'] ?? null]) . PHP_EOL, FILE_APPEND);
    }

    logSecurityKeyUsage($userId, 'Key Verification Failed', false);
    return false;
}

function logSecurityKeyUsage($userId, $action, $success = true) {
    global $pdo;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO security_key_logs (user_id, action, success, attempt_ip) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $success ? 1 : 0, $ipAddress]);
}

function isMasterKeyVerified($timeoutMinutes = 30) {
    if (empty($_SESSION['master_key_verified']) || empty($_SESSION['master_key_verified_at'])) {
        return false;
    }

    $elapsed = (time() - $_SESSION['master_key_verified_at']) / 60;
    if ($elapsed > $timeoutMinutes) {
        unset($_SESSION['master_key_verified']);
        unset($_SESSION['master_key_verified_at']);
        return false;
    }

    return true;
}

function createUserApprovalRequest($requestedByUserId, $fullName, $email, $requestedRole, $employeeId = '', $department = '', $position = '', $phone = '', $passwordHash = '', $reason = '') {
    global $pdo;

    if (!in_array($requestedRole, ['it_staff', 'admin'])) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_approval_requests 
            (requested_by, employee_id, full_name, email, requested_role, department, position, phone, password_hash, reason, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        return $stmt->execute([
            $requestedByUserId,
            $employeeId,
            $fullName,
            $email,
            $requestedRole,
            $department,
            $position,
            $phone,
            $passwordHash,
            $reason
        ]);
    } catch (PDOException $e) {
        return false;
    }
}

function getPendingUserApprovals() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT ar.*, u.full_name as requested_by_name 
        FROM user_approval_requests ar
        LEFT JOIN users u ON ar.requested_by = u.id
        WHERE ar.status = 'pending'
        ORDER BY ar.created_at DESC
    ");
    return $stmt->fetchAll();
}

function approveUserCreation($approvalId, $approvedByUserId, $masterKey = null) {
    global $pdo;

    if ($masterKey && !verifyMasterKey($approvedByUserId, $masterKey)) {
        return ['success' => false, 'message' => 'Invalid master security key'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM user_approval_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$approvalId]);
        $request = $stmt->fetch();

        if (!$request) {
            return ['success' => false, 'message' => 'Request not found or already processed'];
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO users (employee_id, full_name, email, password, role, department, position, phone, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $insertStmt->execute([
            $request['employee_id'],
            $request['full_name'],
            $request['email'],
            $request['password_hash'],
            $request['requested_role'],
            $request['department'],
            $request['position'],
            $request['phone']
        ]);

        $newUserId = $pdo->lastInsertId();

        addNotification(
            $newUserId,
            'user_creation_approved',
            'Account Approved',
            'Your IT/Admin account has been approved. You can now log in.',
            null
        );

        $updateStmt = $pdo->prepare("
            UPDATE user_approval_requests 
            SET status = 'approved', approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$approvedByUserId, $approvalId]);

        logAudit($approvedByUserId, 'Approve User Creation', 'user_approval_requests', $approvalId, null,
            "New {$request['requested_role']} user created: {$request['full_name']}");

        return ['success' => true, 'message' => 'User approved and created successfully', 'userId' => $newUserId];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function rejectUserCreation($approvalId, $rejectedByUserId, $rejectionReason = '') {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            UPDATE user_approval_requests 
            SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? 
            WHERE id = ? AND status = 'pending'
        ");

        $result = $stmt->execute([$rejectedByUserId, $rejectionReason, $approvalId]);

        if ($result) {
            logAudit($rejectedByUserId, 'Reject User Creation', 'user_approval_requests', $approvalId);
        }

        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

function logMasterKeyAudit($userId, $action, $details = null) {
    global $pdo;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO master_key_audit (user_id, action, details, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $action, $details, $ipAddress, $userAgent]);
}

// ============================================================
// REMEMBER ME FUNCTIONS
// ============================================================

function generateRememberToken() {
    return bin2hex(random_bytes(32));
}

function setRememberMe($userId) {
    global $pdo;
    $token = generateRememberToken();
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
        ->execute([$token, $userId]);
    setcookie('remember_token', $token, [
        'expires'  => time() + 30 * 24 * 60 * 60,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clearRememberMe() {
    global $pdo;
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $pdo->prepare("UPDATE users SET remember_token = NULL WHERE remember_token = ?")
            ->execute([$token]);
        setcookie('remember_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

function checkRememberMe() {
    global $pdo;
    if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND status = 'active'");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $newToken = generateRememberToken();
            $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
                ->execute([$newToken, $user['id']]);
            setcookie('remember_token', $newToken, [
                'expires'  => time() + 30 * 24 * 60 * 60,
                'path'     => '/',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            return true;
        }
    }
    return false;
}

// ============================================================
// ACCOUNT LOCKOUT / FAILED LOGIN
// ============================================================

function recordFailedLogin($email) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET failed_logins = failed_logins + 1 WHERE email = ?");
    $stmt->execute([$email]);
    $stmt = $pdo->prepare("SELECT failed_logins FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $count = $stmt->fetchColumn();
    if ($count >= 5) {
        $lockTime = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $pdo->prepare("UPDATE users SET locked_until = ? WHERE email = ?")
            ->execute([$lockTime, $email]);
    }
}

function isAccountLocked($email) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $lockedUntil = $stmt->fetchColumn();
    if ($lockedUntil && strtotime($lockedUntil) > time()) {
        return $lockedUntil;
    }
    return false;
}

function resetFailedLogins($userId) {
    global $pdo;
    $pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?")
        ->execute([$userId]);
}

// ============================================================
// ACCOUNT RECOVERY
// ============================================================

function submitAccountRecovery($userId, $reason) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO account_recovery_requests (user_id, request_reason) VALUES (?, ?)");
    $stmt->execute([$userId, $reason]);
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $admin) {
        addNotification($admin['id'], 'account_recovery_requested', 'Account Recovery Request',
            'A user has submitted an account recovery request.', $pdo->lastInsertId());
    }
    return $pdo->lastInsertId();
}

function getPendingRecoveryRequests() {
    global $pdo;
    return $pdo->query("
        SELECT ar.*, u.full_name, u.email, u.employee_id, u.department, u.position
        FROM account_recovery_requests ar
        JOIN users u ON ar.user_id = u.id
        WHERE ar.status = 'pending'
        ORDER BY ar.requested_at DESC
    ")->fetchAll();
}

// ============================================================
// EMAIL NOTIFICATIONS & MAINTENANCE REMINDERS
// ============================================================

function queueEmailNotification($userId, $recipientEmail, $notificationType, $subject, $body, $relatedDeviceId = null, $relatedRepairId = null) {
    global $pdo;
    $recipientEmail = trim($recipientEmail ?? '');
    if (!isValidEmail($recipientEmail)) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM email_notifications WHERE recipient_email = ? AND notification_type = ? " .
        "AND ((related_device_id = ? OR (related_device_id IS NULL AND ? IS NULL)) " .
        "AND (related_repair_id = ? OR (related_repair_id IS NULL AND ? IS NULL))) " .
        "AND status = 'pending'"
    );
    $stmt->execute([$recipientEmail, $notificationType, $relatedDeviceId, $relatedDeviceId, $relatedRepairId, $relatedRepairId]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        return $existingId;
    }

    $insert = $pdo->prepare("INSERT INTO email_notifications (user_id, recipient_email, notification_type, subject, body, related_device_id, related_repair_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $insert->execute([$userId, $recipientEmail, $notificationType, $subject, $body, $relatedDeviceId, $relatedRepairId]);
    return $pdo->lastInsertId();
}

function sendPendingEmailNotifications() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM email_notifications WHERE status = 'pending' AND retry_count < 3 ORDER BY created_at ASC LIMIT 10");
    $stmt->execute();
    $notifications = $stmt->fetchAll();

    foreach ($notifications as $notif) {
        if (isEmailConfigured()) {
            try {
                $result = sendEmail($notif['recipient_email'], $notif['subject'], $notif['body']);
                if ($result['success']) {
                    $pdo->prepare("UPDATE email_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$notif['id']]);
                } else {
                    $pdo->prepare("UPDATE email_notifications SET status = 'failed', failure_reason = ?, retry_count = retry_count + 1 WHERE id = ?")->execute([$result['message'], $notif['id']]);
                }
            } catch (Exception $emailException) {
                error_log('sendEmail failed for notification ' . $notif['id'] . ': ' . $emailException->getMessage());
                $pdo->prepare("UPDATE email_notifications SET status = 'failed', failure_reason = ?, retry_count = retry_count + 1 WHERE id = ?")->execute([$emailException->getMessage(), $notif['id']]);
            }
        }
    }
}

function createMaintenanceSchedule($deviceId, $maintenanceType, $description, $scheduledDate, $assignedTo = null, $requestedBy = null) {
    global $pdo;
    ensureMaintenanceSchema();

    $columns = ['device_id', 'maintenance_type', 'description', 'scheduled_date', 'next_due_date', 'assigned_to'];
    $values = [$deviceId, $maintenanceType, $description, $scheduledDate, $scheduledDate, $assignedTo];

    if (columnExists('maintenance_schedules', 'requested_by')) {
        $columns[] = 'requested_by';
        $values[] = $requestedBy;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnList = implode(', ', $columns);
    $sql = "INSERT INTO maintenance_schedules ($columnList) VALUES ($placeholders)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $scheduleId = $pdo->lastInsertId();

    // Log audit trail for maintenance creation with enhanced details
    if (isset($_SESSION['user_id'])) {
        $assignedToName = 'Unassigned';
        if ($assignedTo) {
            $assignStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $assignStmt->execute([$assignedTo]);
            $assignedUser = $assignStmt->fetch();
            $assignedToName = $assignedUser ? $assignedUser['full_name'] : "User ID {$assignedTo}";
        }
        
        $auditDetails = "Type: " . ucfirst($maintenanceType) . ". " .
                       "Device ID: {$deviceId}. " .
                       "Due Date: {$scheduledDate}. " .
                       "Assigned To: {$assignedToName}";
        logAudit($_SESSION['user_id'], 'Create Maintenance Schedule', 'maintenance_schedules', $scheduleId, $auditDetails);
    }

    $deviceStmt = $pdo->prepare("SELECT d.asset_tag, dt.type_name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ?");
    $deviceStmt->execute([$deviceId]);
    $device = $deviceStmt->fetch();
    $assetTag = $device ? $device['asset_tag'] : 'device';
    $deviceType = $device ? $device['type_name'] : 'Device';
    $dueDate = date('M d, Y', strtotime($scheduledDate));

    if ($assignedTo) {
        addSystemNotificationOnly($assignedTo, 'maintenance_assigned', 'Maintenance Assigned', "You have been assigned maintenance for {$assetTag} due {$dueDate}.", $deviceId);
        
        // Send email notification
        $userStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? AND status = 'active'");
        $userStmt->execute([$assignedTo]);
        $staffMember = $userStmt->fetch();
        
        if ($staffMember && isEmailConfigured()) {
            $emailBody = emailTemplate(
                'Maintenance Task Assigned',
                "<p>Hello <strong>" . sanitize($staffMember['full_name']) . "</strong>,</p>
                <p>A new maintenance task has been assigned to you.</p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                    <p><strong>Maintenance Details:</strong></p>
                    <p><i class='fas fa-laptop'></i> <strong>Device:</strong> " . sanitize($assetTag) . " (" . sanitize($deviceType) . ")</p>
                    <p><i class='fas fa-tools'></i> <strong>Maintenance Type:</strong> " . sanitize(ucfirst($maintenanceType)) . "</p>
                    <p><i class='fas fa-calendar'></i> <strong>Due Date:</strong> " . sanitize($dueDate) . "</p>
                    <p><i class='fas fa-align-left'></i> <strong>Description:</strong> " . nl2br(sanitize($description)) . "</p>
                </div>
                <p>Please log in to the system to view more details and mark the maintenance as complete when finished.</p>",
                'View Task',
                'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/maintenance_reminders.php'
            );
            sendEmail($staffMember['email'], 'Maintenance Task Assigned - ' . sanitize($assetTag) . ' (' . sanitize($deviceType) . ')', $emailBody);
        }
    } else {
        $staffStmt = $pdo->query("SELECT id, email, full_name FROM users WHERE role IN ('admin','it_staff') AND status = 'active'");
        $staffMembers = $staffStmt->fetchAll();
        foreach ($staffMembers as $member) {
            addSystemNotificationOnly($member['id'], 'maintenance_assigned', 'Maintenance Task Pending', "Maintenance for {$assetTag} is scheduled for {$dueDate} and needs IT assignment.", $deviceId);
            
            // Send email notification for unassigned maintenance
            if (isEmailConfigured()) {
                $emailBody = emailTemplate(
                    'Maintenance Task Awaiting Assignment',
                    "<p>Hello <strong>" . sanitize($member['full_name']) . "</strong>,</p>
                    <p>A new maintenance task has been created and is awaiting assignment.</p>
                    <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f39c12;'>
                        <p><strong>Maintenance Details:</strong></p>
                        <p><i class='fas fa-laptop'></i> <strong>Device:</strong> " . sanitize($assetTag) . " (" . sanitize($deviceType) . ")</p>
                        <p><i class='fas fa-tools'></i> <strong>Maintenance Type:</strong> " . sanitize(ucfirst($maintenanceType)) . "</p>
                        <p><i class='fas fa-calendar'></i> <strong>Due Date:</strong> " . sanitize($dueDate) . "</p>
                        <p><i class='fas fa-align-left'></i> <strong>Description:</strong> " . nl2br(sanitize($description)) . "</p>
                    </div>
                    <p>Please log in to the system to assign this task to a team member.</p>",
                    'Review Task',
                    'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/maintenance_reminders.php'
                );
                sendEmail($member['email'], 'Maintenance Task Pending Assignment - ' . sanitize($assetTag) . ' (' . sanitize($deviceType) . ')', $emailBody);
            }
        }
    }

    return $scheduleId;
}

function getUpcomingMaintenanceReminders($daysAhead = 7) {
    global $pdo;
    $futureDate = date('Y-m-d', strtotime("+$daysAhead days"));
    $selectFields = [
        'ms.*', 'd.asset_tag', 'dt.type_name AS device_type',
        "a.email AS assigned_to_email", "a.full_name AS assigned_to_name"
    ];
    $joins = [
        "JOIN devices d ON ms.device_id = d.id",
        "JOIN device_types dt ON d.device_type_id = dt.id",
        "LEFT JOIN users a ON ms.assigned_to = a.id"
    ];

    if (columnExists('maintenance_schedules', 'requested_by')) {
        $selectFields[] = "r.full_name AS requested_by_name";
        $joins[] = "LEFT JOIN users r ON ms.requested_by = r.id";
    }

    if (columnExists('maintenance_schedules', 'completed_by')) {
        $selectFields[] = "c.full_name AS completed_by_name";
        $joins[] = "LEFT JOIN users c ON ms.completed_by = c.id";
    }

    $sql = "SELECT " . implode(', ', $selectFields) . " FROM maintenance_schedules ms " . implode(' ', $joins) . " WHERE ms.next_due_date <= ? AND ms.next_due_date > NOW() AND ms.last_performed_date IS NULL ORDER BY ms.next_due_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$futureDate]);
    return $stmt->fetchAll();
}

function markMaintenanceCompleted($maintenanceId, $completedBy = null, $completedAt = null, $completionNotes = null) {
    global $pdo;
    ensureMaintenanceSchema();
    if (!$completedAt) {
        $completedAt = date('Y-m-d H:i:s');
    }

    if (!$completedBy && isset($_SESSION['user_id'])) {
        $completedBy = $_SESSION['user_id'];
    }

    $sets = [];
    $params = [];

    if (columnExists('maintenance_schedules', 'last_performed_date')) {
        $sets[] = 'last_performed_date = DATE(?)';
        $params[] = $completedAt;
    }
    if (columnExists('maintenance_schedules', 'next_due_date')) {
        $sets[] = 'next_due_date = DATE_ADD(DATE(?), INTERVAL 6 MONTH)';
        $params[] = $completedAt;
    }
    if (columnExists('maintenance_schedules', 'completed_at')) {
        $sets[] = 'completed_at = ?';
        $params[] = $completedAt;
    }
    if (columnExists('maintenance_schedules', 'completed_by')) {
        $sets[] = 'completed_by = ?';
        $params[] = $completedBy;
    }
    if (columnExists('maintenance_schedules', 'completion_notes')) {
        $sets[] = 'completion_notes = ?';
        $params[] = $completionNotes;
    }

    $deviceId = null;
    $assignedTo = null;
    $assetTag = '';
    $deviceType = '';
    
    try {
        $devStmt = $pdo->prepare("SELECT device_id, assigned_to FROM maintenance_schedules WHERE id = ? LIMIT 1");
        $devStmt->execute([$maintenanceId]);
        $maint = $devStmt->fetch();
        $deviceId = $maint['device_id'] ?? null;
        $assignedTo = $maint['assigned_to'] ?? null;
        
        // Get device asset tag and type
        if ($deviceId) {
            $assetStmt = $pdo->prepare("SELECT d.asset_tag, dt.type_name FROM devices d JOIN device_types dt ON d.device_type_id = dt.id WHERE d.id = ? LIMIT 1");
            $assetStmt->execute([$deviceId]);
            $deviceData = $assetStmt->fetch();
            $assetTag = $deviceData ? $deviceData['asset_tag'] : '';
            $deviceType = $deviceData ? $deviceData['type_name'] : 'Device';
        }
    } catch (Exception $e) {
        // ignore
    }

    if (empty($sets)) {
        return false;
    }

    $sql = 'UPDATE maintenance_schedules SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $params[] = $maintenanceId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log audit trail for maintenance completion with completed_by information
    if (isset($_SESSION['user_id'])) {
        $completedByStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $completedByStmt->execute([$completedBy]);
        $completedByUser = $completedByStmt->fetch();
        $completedByName = $completedByUser ? $completedByUser['full_name'] : 'Unknown User';
        
        $auditDetails = "Device: {$assetTag} ({$deviceType}). " .
                       "Completed By: {$completedByName}. " .
                       "Notes: " . ($completionNotes ?: 'N/A');
        logAudit($_SESSION['user_id'], 'Mark Maintenance Completed', 'maintenance_schedules', $maintenanceId, $auditDetails);
    }

    if ($deviceId) {
        try {
            $clearStmt = $pdo->prepare("DELETE FROM notifications WHERE (type = 'maintenance_due' OR type = 'maintenance_assigned') AND related_id = ? AND is_read = 0");
            $clearStmt->execute([$deviceId]);
        } catch (Exception $e) {
            // ignore
        }
        
        // Notify IT staff about completion (includes system notification + email)
        notifyITStaff('maintenance_completed', 'Maintenance Completed', "Maintenance for {$assetTag} has been marked as complete.", $deviceId);
    }
    
    return true;
}

// ============================================================
// SERIAL NUMBER SEARCH
// ============================================================

function searchDeviceBySerialNumber($serialNumber) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT d.*, dt.type_name, u.full_name as created_by_name
        FROM devices d
        JOIN device_types dt ON d.device_type_id = dt.id
        LEFT JOIN users u ON d.created_by = u.id
        WHERE d.serial_number LIKE ? OR d.asset_tag LIKE ?
        LIMIT 1
    ");
    $search = "%$serialNumber%";
    $stmt->execute([$search, $search]);
    return $stmt->fetch();
}

function searchDevicesBySerialOrAsset($searchTerm) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT d.*, dt.type_name
        FROM devices d
        JOIN device_types dt ON d.device_type_id = dt.id
        WHERE d.serial_number LIKE ? OR d.asset_tag LIKE ? OR d.vendor LIKE ? OR dt.type_name LIKE ?
        ORDER BY d.updated_at DESC
        LIMIT 20
    ");
    $search = "%$searchTerm%";
    $stmt->execute([$search, $search, $search, $search]);
    return $stmt->fetchAll();
}

// ============================================================
// STATUS COLOR & DISPLAY
// ============================================================

function getStatusColor($status) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT color_code, icon_class, display_label FROM device_status_colors WHERE status = ?");
    $stmt->execute([$status]);
    $result = $stmt->fetch();
    if (!$result) {
        return ['color_code' => '#95a5a6', 'icon_class' => 'fas fa-question', 'display_label' => ucfirst(str_replace('_', ' ', $status))];
    }
    return $result;
}

function getStatusBadgeHtml($status) {
    $statusInfo = getStatusColor($status);
    return '<span class="status-badge" style="background-color: ' . $statusInfo['color_code'] . '20; color: ' . $statusInfo['color_code'] . '; border: 1px solid ' . $statusInfo['color_code'] . '; padding: 5px 10px; border-radius: 4px; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
        <i class="' . $statusInfo['icon_class'] . '"></i> ' . $statusInfo['display_label'] . '
    </span>';
}

// ============================================================
// USER ASSET DASHBOARD
// ============================================================

function getEmployeeAssignedDevices($employeeId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT d.*, dt.type_name, da.assigned_date, da.purpose, 
               (SELECT COUNT(*) FROM device_repairs WHERE device_id = d.id AND repair_status IN ('pending', 'under_repair')) as pending_repairs,
               (SELECT COUNT(*) FROM maintenance_schedules WHERE device_id = d.id AND next_due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)) as upcoming_maintenance
        FROM device_assignments da
        JOIN devices d ON da.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        WHERE da.employee_id = ? AND da.status = 'active' AND d.status = 'deployed'
        ORDER BY da.assigned_date DESC
    ");
    $stmt->execute([$employeeId]);
    return $stmt->fetchAll();
}

function getEmployeeDeviceStats($employeeId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT da.device_id) as total_devices,
            SUM(CASE WHEN d.status = 'deployed' THEN 1 ELSE 0 END) as active_devices,
            SUM(CASE WHEN d.status = 'under_repair' THEN 1 ELSE 0 END) as devices_under_repair,
            SUM(CASE WHEN dr.repair_status IN ('pending', 'under_repair') THEN 1 ELSE 0 END) as pending_repairs
        FROM device_assignments da
        LEFT JOIN devices d ON da.device_id = d.id
        LEFT JOIN device_repairs dr ON d.id = dr.device_id AND dr.repair_status IN ('pending', 'under_repair')
        WHERE da.employee_id = ? AND da.status = 'active'
    ");
    $stmt->execute([$employeeId]);
    return $stmt->fetch();
}

function getDeviceAssignmentHistory($deviceId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT da.*, u.full_name, u.employee_id
        FROM device_assignments da
        JOIN users u ON da.employee_id = u.id
        WHERE da.device_id = ?
        ORDER BY da.assigned_date DESC
        LIMIT 5
    ");
    $stmt->execute([$deviceId]);
    return $stmt->fetchAll();
}

// ============================================================
// REPAIR COMPLETION & NOTIFICATION
// ============================================================

function markRepairAsCompleted($repairId, $completionNotes = '') {
    global $pdo;

    ensureDeviceRepairsSchema();

    $stmt = $pdo->prepare("
        SELECT dr.*, d.asset_tag, dt.type_name AS type_name, u.email, u.full_name as reporter_name, u.id as reported_by_id
        FROM device_repairs dr
        JOIN devices d ON dr.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        JOIN users u ON dr.reported_by = u.id
        WHERE dr.id = ?
    ");
    $stmt->execute([$repairId]);
    $repair = $stmt->fetch();

    if (!$repair) {
        return ['success' => false, 'message' => 'Repair not found'];
    }

    $pdo->prepare("
        UPDATE device_repairs 
        SET repair_status = 'completed', completed_date = NOW(), repair_notes = ?
        WHERE id = ?
    ")->execute([$completionNotes, $repairId]);

    $pdo->prepare("UPDATE devices SET status = 'deployed' WHERE id = ? AND status = 'under_repair'")->execute([$repair['device_id']]);

    // Log audit trail for repair completion
    if (isset($_SESSION['user_id'])) {
        logAudit($_SESSION['user_id'], 'Mark Repair Completed', 'device_repairs', $repairId, "Device: {$repair['asset_tag']} ({$repair['type_name']}). Notes: " . ($completionNotes ?: 'N/A'));
    }

    // Add system notification (without email - email is queued separately)
    addSystemNotificationOnly(
        $repair['reported_by_id'],
        'repair_completed',
        'Device Repair Completed',
        'Your repair request for ' . $repair['asset_tag'] . ' has been completed. The device is now ready for use.',
        $repairId
    );

    $subject = 'Device Repair Completed - ' . $repair['asset_tag'] . ' (' . $repair['type_name'] . ')';
    $emailBody = emailTemplate(
        'Your Device Repair is Complete',
        "<p>Hello <strong>" . sanitize($repair['reporter_name']) . "</strong>,</p>
        <p>We're pleased to inform you that your device repair request has been completed.</p>
        <ul style='margin-left: 20px;'>
            <li><strong>Device:</strong> " . sanitize($repair['asset_tag']) . " (" . sanitize($repair['type_name']) . ")</li>
            <li><strong>Original Issue:</strong> " . sanitize(substr($repair['issue_description'], 0, 100)) . "...</li>
            <li><strong>Repair Completed:</strong> " . date('M d, Y h:i A') . "</li>
            <li><strong>Status:</strong> Ready for pickup/use</li>
        </ul>
        " . (!empty($completionNotes) ? "<p><strong>Repair Notes:</strong><br>" . sanitize($completionNotes) . "</p>" : "") . "
        <p>If you have any questions about the repair, please contact the IT department.</p>",
        'View Device Details',
        (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . '/view_device.php?id=' . $repair['device_id']
    );

    // Queue email notification (will be sent by caller via sendPendingEmailNotifications())
    try {
        queueEmailNotification(
            $repair['reported_by_id'],
            $repair['email'],
            'repair_completed',
            $subject,
            $emailBody,
            $repair['device_id'],
            $repairId
        );
    } catch (Exception $emailQueueException) {
        error_log('queueEmailNotification failed for repair ' . $repairId . ': ' . $emailQueueException->getMessage());
    }

    return ['success' => true, 'message' => 'Repair marked as completed. Employee has been notified.'];
}

function getPendingRepairs() {
    global $pdo;
    $sql = "
        SELECT dr.*, d.asset_tag, d.serial_number, d.vendor, dt.type_name, u.full_name as reporter_name, u.email, 
               DATEDIFF(NOW(), dr.started_date) as days_in_repair";
    
    // Include assigned_to info if column exists
    if (columnExists('device_repairs', 'assigned_to')) {
        $sql .= ", a.full_name as assigned_to_name, a.email as assigned_to_email";
    }
    
    $sql .= "
        FROM device_repairs dr
        JOIN devices d ON dr.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        JOIN users u ON dr.reported_by = u.id";
    
    if (columnExists('device_repairs', 'assigned_to')) {
        $sql .= "
        LEFT JOIN users a ON dr.assigned_to = a.id";
    }
    
    $sql .= "
        WHERE dr.repair_status IN ('pending', 'under_repair')
        ORDER BY dr.severity DESC, dr.started_date ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCompletedRepairs($limit = 10) {
    global $pdo;
    $sql = "
        SELECT dr.*, d.asset_tag, d.serial_number, d.vendor, dt.type_name, u.full_name as reporter_name,
               DATEDIFF(dr.completed_date, dr.started_date) as days_to_repair";
    
    // Include assigned_to and completed_by info if columns exist
    if (columnExists('device_repairs', 'assigned_to')) {
        $sql .= ", a.full_name as assigned_to_name";
    }
    if (columnExists('device_repairs', 'completed_by')) {
        $sql .= ", cb.full_name as completed_by_name";
    }
    
    $sql .= "
        FROM device_repairs dr
        JOIN devices d ON dr.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        JOIN users u ON dr.reported_by = u.id";
    
    if (columnExists('device_repairs', 'assigned_to')) {
        $sql .= "
        LEFT JOIN users a ON dr.assigned_to = a.id";
    }
    if (columnExists('device_repairs', 'completed_by')) {
        $sql .= "
        LEFT JOIN users cb ON dr.completed_by = cb.id";
    }
    
    $sql .= "
        WHERE dr.repair_status = 'completed'
        ORDER BY dr.completed_date DESC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getCompletedMaintenance($limit = 10) {
    global $pdo;
    
    // Check which columns exist in the table
    $hasCompletedAt = columnExists('maintenance_schedules', 'completed_at');
    $hasCompletedBy = columnExists('maintenance_schedules', 'completed_by');
    $hasCompletionNotes = columnExists('maintenance_schedules', 'completion_notes');
    $hasRequestedBy = columnExists('maintenance_schedules', 'requested_by');
    
    // Build SELECT clause
    $selectCols = "ms.id, ms.device_id, ms.maintenance_type, ms.description, ms.assigned_to, ms.last_performed_date, d.asset_tag, d.serial_number, d.vendor, dt.type_name AS device_type";
    
    // Add optional columns
    if ($hasCompletedAt) {
        $selectCols .= ", ms.completed_at";
    }
    if ($hasCompletedBy) {
        $selectCols .= ", ms.completed_by";
    }
    if ($hasCompletionNotes) {
        $selectCols .= ", ms.completion_notes";
    }
    
    // Build JOIN clauses for user tables
    $joins = "
        FROM maintenance_schedules ms
        JOIN devices d ON ms.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id";
    
    // Add requested_by user info if column exists
    if ($hasRequestedBy) {
        $selectCols .= ", u.full_name as requested_by_name";
        $joins .= "
        LEFT JOIN users u ON ms.requested_by = u.id";
    }
    
    // Add completed_by user info if column exists
    if ($hasCompletedBy) {
        $selectCols .= ", cb.full_name as completed_by_name";
        $joins .= "
        LEFT JOIN users cb ON ms.completed_by = cb.id";
    }
    
    $sql = "SELECT " . $selectCols . $joins . "
        WHERE ms.last_performed_date IS NOT NULL
        ORDER BY " . ($hasCompletedAt ? "ms.completed_at" : "ms.last_performed_date") . " DESC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// ============================================================
// DEVICE DEPLOYMENT CONSISTENCY FUNCTIONS
// ============================================================

function fixDeploymentStatusConsistency() {
    global $pdo;
    $results = [
        'fixed_deployed' => 0,
        'fixed_unassigned' => 0,
        'errors' => []
    ];

    try {
        $stmt = $pdo->prepare("
            UPDATE device_assignments da
            LEFT JOIN users u ON da.employee_id = u.id AND u.status = 'active'
            SET da.status = 'returned', da.returned_date = CURDATE(), da.notes = CONCAT(COALESCE(da.notes, ''), ?)
            WHERE da.status = 'active' AND (da.employee_id IS NULL OR u.id IS NULL)
        ");
        $note = '\nAuto-returned by consistency cleanup on ' . date('Y-m-d H:i:s');
        $stmt->execute([$note]);
        $results['fixed_orphan_assignments'] = $stmt->rowCount();

        $stmt = $pdo->prepare("
            UPDATE devices d
            SET d.status = 'in_stock', d.location = 'IT Stock Room'
            WHERE d.status = 'deployed'
            AND d.id NOT IN (
                SELECT DISTINCT da.device_id
                FROM device_assignments da
                JOIN users u ON da.employee_id = u.id AND u.status = 'active'
                WHERE da.status = 'active'
            )
        ");
        $stmt->execute();
        $results['fixed_deployed'] = $stmt->rowCount();

        $stmt = $pdo->prepare("
            UPDATE devices d
            SET d.status = 'deployed'
            WHERE d.id IN (
                SELECT DISTINCT da.device_id
                FROM device_assignments da
                JOIN users u ON da.employee_id = u.id AND u.status = 'active'
                WHERE da.status = 'active'
            )
            AND d.status != 'deployed'
        ");
        $stmt->execute();
        $results['fixed_unassigned'] = $stmt->rowCount();

    } catch (PDOException $e) {
        $results['errors'][] = $e->getMessage();
    }

    return $results;
}

function hasActiveDeployment($deviceId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM device_assignments WHERE device_id = ? AND status = 'active'");
    $stmt->execute([$deviceId]);
    return $stmt->fetchColumn() > 0;
}

function getActiveDeviceAssignment($deviceId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT da.*, u.full_name, u.email, u.department 
        FROM device_assignments da
        JOIN users u ON da.employee_id = u.id
        WHERE da.device_id = ? AND da.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$deviceId]);
    return $stmt->fetch();
}