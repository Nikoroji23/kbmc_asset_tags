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
    $masterKey = isset($_POST['master_key']) ? trim($_POST['master_key']) : '';

    if ($targetUserId <= 0 || !in_array($action, ['enable', 'disable'], true)) {
        setFlashMessage('error', 'Invalid security IT update request.');
        header('Location: assign_security_it.php');
        exit();
    }

    $enabled = $action === 'enable';

    // Require master key verification when granting Security IT privileges
    if ($enabled) {
        if (!verifyMasterKey($_SESSION['user_id'], $masterKey)) {
            setFlashMessage('error', 'Invalid master key. Security IT grant was not completed.');
            header('Location: assign_security_it.php');
            exit();
        }
    }
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

// Also support processing approval actions (approve/reject) and master-key verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If action coming from approvals, handle here (keeps single POST endpoint)
    $postAction = $_POST['action'] ?? '';
    if (in_array($postAction, ['approve_user', 'reject_user', 'verify_master_key'], true)) {
        if ($postAction === 'approve_user') {
            $approvalId = (int)($_POST['approval_id'] ?? 0);
            $masterKey = trim($_POST['master_key'] ?? '');
            $result = approveUserCreation($approvalId, $_SESSION['user_id'], $masterKey);
            setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
        } elseif ($postAction === 'reject_user') {
            $approvalId = (int)($_POST['approval_id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (rejectUserCreation($approvalId, $_SESSION['user_id'], $reason)) {
                setFlashMessage('success', 'User creation request rejected.');
            } else {
                setFlashMessage('error', 'Failed to reject request.');
            }
        } elseif ($postAction === 'verify_master_key') {
            $masterKey = trim($_POST['master_key'] ?? '');
            if (verifyMasterKey($_SESSION['user_id'], $masterKey)) {
                setFlashMessage('success', 'Master key verified! Session secured.');
            } else {
                setFlashMessage('error', 'Invalid master key. Please try again.');
            }
        }
        header('Location: assign_security_it.php');
        exit();
    }
}

$itStaff = $pdo->query("SELECT id, employee_id, full_name, email, department, position, is_security_admin FROM users WHERE role = 'it_staff' ORDER BY full_name ASC")->fetchAll();

// Fetch pending approvals and master key status so we can render them on the same page
$approvals = function_exists('getPendingUserApprovals') ? getPendingUserApprovals() : [];
$masterKeyVerified = function_exists('isMasterKeyVerified') ? isMasterKeyVerified() : false;

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
                            <form method="POST" action="assign_security_it.php" style="display:inline-block; margin:0;" onsubmit="return false;">
                                    <?php echo csrfInputField(); ?>
                                    <input type="hidden" name="user_id" value="<?php echo $staff['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $staff['is_security_admin'] ? 'disable' : 'enable'; ?>">
                                    <input type="hidden" name="master_key" value="" id="master_key_input_<?php echo $staff['id']; ?>">
                                    <button type="button" class="btn btn-sm <?php echo $staff['is_security_admin'] ? 'btn-danger' : 'btn-success'; ?>" onclick="openGrantModal(<?php echo $staff['id']; ?>, '<?php echo $staff['is_security_admin'] ? 'disable' : 'enable'; ?>')">
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

<!-- Pending Approvals UI (merged from security_control.php) -->
<div class="page-header" style="margin-top:20px;">
    <h1><i class="fas fa-shield-alt"></i> Security Control Center</h1>
</div>

<!-- Master Key Status -->
<div style="margin-bottom: 20px; padding: 15px; background: <?php echo $masterKeyVerified ? '#EBF5FB' : '#FDEDEC'; ?>; border: 1px solid <?php echo $masterKeyVerified ? '#AED6F1' : '#F5B7B1'; ?>; border-radius: 8px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="margin: 0; color: <?php echo $masterKeyVerified ? '#1F618D' : '#C0392B'; ?>;">
                <i class="fas fa-key"></i> Master Key Status: 
                <strong><?php echo $masterKeyVerified ? 'VERIFIED ✓' : 'NOT VERIFIED'; ?></strong>
            </h3>
            <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                <?php 
                if ($masterKeyVerified) {
                    echo 'Your master key session is active. Approvals can be processed.';
                } else {
                    echo 'Enter your master security key to approve IT/Admin user creation requests.';
                }
                ?>
            </p>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('masterKeyModal').style.display='flex';">
            <i class="fas fa-unlock"></i> <?php echo $masterKeyVerified ? 'Re-verify' : 'Verify Master Key'; ?>
        </button>
    </div>
</div>

<!-- Pending Approvals -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-hourglass-end"></i> Pending IT/Admin User Approvals (<?php echo count($approvals); ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($approvals)): ?>
        <div class="empty-state">
            <i class="fas fa-check-circle" style="font-size: 40px; color: #27AE60;"></i>
            <h4>No pending approvals</h4>
            <p>All IT/Admin user creation requests have been processed.</p>
        </div>
        <?php else: ?>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Requested By</th>
                        <th>New User</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvals as $req): ?>
                    <tr id="request-<?php echo $req['id']; ?>">
                        <td><?php echo sanitize($req['requested_by_name'] ?? 'Admin'); ?></td>
                        <td>
                            <strong><?php echo sanitize($req['full_name']); ?></strong><br>
                            <small><?php echo sanitize($req['email']); ?></small>
                        </td>
                        <td>
                            <span style="padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; background: <?php echo $req['requested_role'] == 'admin' ? '#D9232E30' : '#3498DB30'; ?>; color: <?php echo $req['requested_role'] == 'admin' ? '#D9232E' : '#3498DB'; ?>;">
                                <?php echo $req['requested_role'] == 'admin' ? 'ADMINISTRATOR' : 'IT STAFF'; ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($req['department'] ?? 'N/A'); ?></td>
                        <td><small><?php echo sanitize(substr($req['reason'] ?? '', 0, 50)) . (strlen($req['reason'] ?? '') > 50 ? '...' : ''); ?></small></td>
                        <td><?php echo formatDate($req['created_at']); ?></td>
                        <td style="display: flex; gap: 5px;">
                            <button class="action-btn assign" onclick="openApproveModal(<?php echo $req['id']; ?>)" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="action-btn delete" onclick="openRejectModal(<?php echo $req['id']; ?>)" title="Reject">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="action-btn view" 
                                data-requested-by="<?php echo sanitize($req['requested_by_name'] ?? 'Admin'); ?>"
                                data-full-name="<?php echo sanitize($req['full_name']); ?>"
                                data-email="<?php echo sanitize($req['email']); ?>"
                                data-role="<?php echo sanitize($req['requested_role']); ?>"
                                data-department="<?php echo sanitize($req['department'] ?? 'N/A'); ?>"
                                data-position="<?php echo sanitize($req['position'] ?? 'N/A'); ?>"
                                data-reason="<?php echo sanitize($req['reason'] ?? 'No reason provided'); ?>"
                                data-created-at="<?php echo formatDate($req['created_at'], 'M d, Y h:i A'); ?>"
                                onclick="viewRequestDetails(this)" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Approve Request Modal (merged) -->
<div id="approveModal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-check"></i> Approve User Creation</h3>
            <button class="modal-close" onclick="document.getElementById('approveModal').style.display='none';">&times;</button>
        </div>
        <form method="POST" id="approveForm">
            <?php echo csrfInputField(); ?>
            <input type="hidden" name="action" value="approve_user">
            <input type="hidden" name="approval_id" id="approveId">
            <div class="modal-body">
                <div style="padding: 15px; background: #EBF5FB; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #3498DB;">
                    <strong style="color: #1F618D;">ℹ️ Master Key Required</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #1F618D;">
                        Your master security key is required to approve this user creation request.
                    </p>
                </div>
                <div class="form-group">
                    <label>Master Security Key <span class="required">*</span></label>
                    <input type="password" name="master_key" class="form-control" placeholder="Enter your master key" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="document.getElementById('approveModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve & Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Request Modal (merged) -->
<div id="rejectModal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-times"></i> Reject User Creation</h3>
            <button class="modal-close" onclick="document.getElementById('rejectModal').style.display='none';">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfInputField(); ?>
            <input type="hidden" name="action" value="reject_user">
            <input type="hidden" name="approval_id" id="rejectId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Rejection Reason (Optional)</label>
                    <textarea name="rejection_reason" class="form-control" rows="4" placeholder="Explain why this user creation request is being rejected..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="document.getElementById('rejectModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Request Detail Modal (merged) -->
<div id="requestDetailModal" class="modal-overlay" style="display: none;">
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Request Details</h3>
            <button class="modal-close" onclick="document.getElementById('requestDetailModal').style.display='none';">&times;</button>
        </div>
        <div class="modal-body" id="requestDetailBody">
            <p style="text-align:center;color:#999;padding:30px;">Loading...</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" onclick="document.getElementById('requestDetailModal').style.display='none';">Close</button>
        </div>
    </div>
</div>

<!-- Master Key Verification Modal -->
<div id="masterKeyModal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Master Key Verification</h3>
            <button class="modal-close" onclick="document.getElementById('masterKeyModal').style.display='none';">&times;</button>
        </div>
        <form method="POST" id="masterKeyForm">
            <?php echo csrfInputField(); ?>
            <input type="hidden" name="action" value="verify_master_key">
            <div class="modal-body">
                <div style="padding: 15px; background: #FFF3CD; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #FFC107;">
                    <strong style="color: #856404;">⚠️ Security Notice:</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #856404;">
                        Enter your master security key to approve IT/Admin user creation requests.
                        This is a sensitive operation and will be logged.
                    </p>
                </div>
                <div class="form-group">
                    <label>Master Security Key <span class="required">*</span></label>
                    <input type="password" name="master_key" class="form-control" placeholder="Enter your master key" required autofocus>
                    <small style="color: #999; margin-top: 5px; display: block;">
                        Your master key is 32 characters long. It was provided when you were designated as a Security IT approver.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="document.getElementById('masterKeyModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-unlock"></i> Verify Key</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<!-- Grant Security IT Modal -->
<div id="grantSecurityModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-user-shield"></i> Confirm Security IT Grant</h3>
            <button class="modal-close" onclick="closeGrantModal()">&times;</button>
        </div>
        <form method="POST" id="grantSecurityForm">
            <?php echo csrfInputField(); ?>
            <input type="hidden" name="user_id" id="grant_user_id" value="">
            <input type="hidden" name="action" id="grant_action" value="">
            <div class="modal-body">
                <p>Please enter your master security key to confirm granting Security IT privileges.</p>
                <div class="form-group">
                    <label>Master Security Key <span class="required">*</span></label>
                    <input type="password" name="master_key" id="grant_master_key" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeGrantModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitGrantForm()">Confirm Grant</button>
            </div>
        </form>
    </div>
</div>

<script>
function openGrantModal(userId, action) {
    document.getElementById('grant_user_id').value = userId;
    document.getElementById('grant_action').value = action;
    document.getElementById('grant_master_key').value = '';
    document.getElementById('grantSecurityModal').style.display = 'flex';
}

function closeGrantModal() {
    document.getElementById('grantSecurityModal').style.display = 'none';
}

function submitGrantForm() {
    var form = document.getElementById('grantSecurityForm');
    var userId = document.getElementById('grant_user_id').value;
    var action = document.getElementById('grant_action').value;
    var key = document.getElementById('grant_master_key').value;
    if (!key || key.trim() === '') {
        alert('Please enter your master security key.');
        return;
    }

    // Find the hidden master_key input in the corresponding row form (if exists) and set it
    var rowInput = document.getElementById('master_key_input_' + userId);
    if (rowInput) rowInput.value = key;

    // Submit via regular POST to server
    form.submit();
}
</script>
<script>
function openApproveModal(id) {
    document.getElementById('approveId').value = id;
    document.getElementById('approveModal').style.display = 'flex';
}

function openRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectModal').style.display = 'flex';
}

function viewRequestDetails(button) {
    var detailModal = document.getElementById('requestDetailModal');
    var detailBody = document.getElementById('requestDetailBody');

    var requestedBy = button.getAttribute('data-requested-by') || 'Admin';
    var fullName = button.getAttribute('data-full-name') || 'N/A';
    var email = button.getAttribute('data-email') || 'N/A';
    var role = button.getAttribute('data-role') || 'N/A';
    var department = button.getAttribute('data-department') || 'N/A';
    var position = button.getAttribute('data-position') || 'N/A';
    var reason = button.getAttribute('data-reason') || 'No reason provided';
    var createdAt = button.getAttribute('data-created-at') || 'N/A';

    detailBody.innerHTML =
        '<div class="form-grid" style="margin-bottom:22px;">'
            + '<div><strong>Requested By</strong><p>' + requestedBy + '</p></div>'
            + '<div><strong>Requested User</strong><p>' + fullName + '</p></div>'
            + '<div><strong>Email</strong><p>' + email + '</p></div>'
            + '<div><strong>Role</strong><p>' + (role === 'admin' ? 'Administrator' : (role === 'it_staff' ? 'IT Staff' : role)) + '</p></div>'
            + '<div><strong>Department</strong><p>' + department + '</p></div>'
            + '<div><strong>Position</strong><p>' + position + '</p></div>'
            + '<div><strong>Requested At</strong><p>' + createdAt + '</p></div>'
        + '</div>'
        + '<hr style="border:none;border-top:1px solid #eee;margin-bottom:18px;">'
        + '<h4 style="font-size:14px;color:#2c3e50;margin-bottom:12px;"><i class="fas fa-comment-dots"></i> Request Reason</h4>'
        + '<p style="line-height:1.6;color:#4a4a4a;">' + reason + '</p>';

    detailModal.style.display = 'flex';
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var ids = ['masterKeyModal','approveModal','rejectModal','requestDetailModal','grantSecurityModal'];
        ids.forEach(function(id){ var m = document.getElementById(id); if (m) m.style.display='none'; });
    }
});

// Close modals on outside click
document.addEventListener('click', function(e) {
    const modals = ['masterKeyModal', 'approveModal', 'rejectModal', 'requestDetailModal', 'grantSecurityModal'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (modal && e.target === modal) modal.style.display = 'none';
    });
});
</script>
