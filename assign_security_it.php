<?php
/**
 * KBMC Asset Management - Assign Security IT Approvers
 * Allows admins to designate which IT staff can approve new IT/Admin account requests.
 */
$pageTitle = 'Assign Security IT';
require_once 'includes/functions.php';
requireITStaff();

if (!isSecurityAdmin($_SESSION['user_id'])) {
    setFlashMessage('error', 'You do not have permission to manage Security IT approvers.');
    header('Location: it_dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        setFlashMessage('error', 'Invalid request token. Please try again.');
        header('Location: assign_security_it.php');
        exit();
    }

    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($targetUserId <= 0 || !in_array($action, ['enable', 'disable'], true)) {
        setFlashMessage('error', 'Invalid security IT update request.');
        header('Location: assign_security_it.php');
        exit();
    }

    $enabled = $action === 'enable';
    if (setSecurityITApprover($targetUserId, $enabled)) {
        $display = $enabled ? 'granted' : 'revoked';
        $user = getUserInfo($targetUserId);
        $name = $user['full_name'] ?? 'IT Staff';
        logAudit($_SESSION['user_id'], 'Update Security IT Approver', 'users', $targetUserId, null, "is_security_admin={$enabled}");
        setFlashMessage('success', "Security IT approval privileges have been {$display} for {$name}.");
        
        // Sync notifications after granting security access
        if ($enabled) {
            try {
                $ch = curl_init();
                $sessionCookie = session_name() . '=' . session_id();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/api_sync_it_user_security_notifications.php',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_COOKIE => $sessionCookie,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode(['user_id' => (int)$targetUserId]),
                    CURLOPT_TIMEOUT => 5
                ]);
                $response = curl_exec($ch);
                curl_close($ch);
                error_log("Security IT notifications synced for user {$targetUserId}");
            } catch (Exception $e) {
                error_log("Failed to sync security IT notifications: " . $e->getMessage());
            }
        }
    } else {
        setFlashMessage('error', 'Unable to update Security IT approver status. Make sure the selected user is IT staff.');
    }

    header('Location: assign_security_it.php');
    exit();
}

$itStaff = $pdo->query("SELECT id, employee_id, full_name, email, department, position, is_security_admin FROM users WHERE role = 'it_staff' ORDER BY full_name ASC")->fetchAll();
require_once 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-shield"></i> Security IT Approver Management</h1>
    <p style="margin-top: 8px; color: #555;">
        Use this page to grant or remove Security IT approval privileges from IT staff.
        Only designated Security IT approvers can approve new IT/Admin account creation requests.
    </p>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users-cog"></i> IT Staff Accounts</h3>
    </div>
    <div class="card-body">
        <?php if (empty($itStaff)): ?>
        <div class="empty-state">
            <i class="fas fa-info-circle"></i>
            <h4>No IT staff users found</h4>
            <p>Create IT staff users under Manage Users before assigning approval privileges.</p>
        </div>
        <?php else: ?>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itStaff as $staff): ?>
                    <tr>
                        <td><?php echo sanitize($staff['employee_id'] ?: 'N/A'); ?></td>
                        <td><strong><?php echo sanitize($staff['full_name']); ?></strong></td>
                        <td><?php echo sanitize($staff['email']); ?></td>
                        <td><?php echo sanitize($staff['department'] ?: 'N/A'); ?></td>
                        <td><?php echo sanitize($staff['position'] ?: 'N/A'); ?></td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $staff['is_security_admin'] ? '#27AE6020' : '#E74C3C20'; ?>; color: <?php echo $staff['is_security_admin'] ? '#27AE60' : '#E74C3C'; ?>; border: 1px solid <?php echo $staff['is_security_admin'] ? '#27AE60' : '#E74C3C'; ?>;">
                                <?php echo $staff['is_security_admin'] ? 'Security IT' : 'Standard IT'; ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="assign_security_it.php" style="display:inline-block; margin:0;">
                                <?php echo csrfInputField(); ?>
                                <input type="hidden" name="user_id" value="<?php echo $staff['id']; ?>">
                                <input type="hidden" name="action" value="<?php echo $staff['is_security_admin'] ? 'disable' : 'enable'; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $staff['is_security_admin'] ? 'btn-danger' : 'btn-success'; ?>">
                                    <?php echo $staff['is_security_admin'] ? 'Revoke' : 'Grant'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
