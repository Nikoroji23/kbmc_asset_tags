<?php
/**
 * KBMC Asset Management - Security Control Center
 * Master key verification and IT/Admin user approval system
 * Accessible by security IT approvers, including IT staff granted security approval rights.
 */
$pageTitle = 'Security Control';
require_once 'includes/header.php';
requireITStaff();

// Check if user is security IT approver
if (!isSecurityAdmin($_SESSION['user_id'])) {
    setFlashMessage('error', 'You do not have Security IT approval privileges.');
    if (hasRole('admin')) {
        header('Location: admin_dashboard.php');
    } elseif (hasRole('it_staff')) {
        header('Location: it_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $approvalId = (int)($_POST['approval_id'] ?? 0);
    $masterKey = $_POST['master_key'] ?? '';
    
    if ($action === 'approve_user') {
        $result = approveUserCreation($approvalId, $_SESSION['user_id'], $masterKey);
        setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'reject_user') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (rejectUserCreation($approvalId, $_SESSION['user_id'], $reason)) {
            setFlashMessage('success', 'User creation request rejected.');
        } else {
            setFlashMessage('error', 'Failed to reject request.');
        }
    } elseif ($action === 'verify_master_key') {
        if (verifyMasterKey($_SESSION['user_id'], trim($masterKey))) {
            setFlashMessage('success', 'Master key verified! Session secured.');
        } else {
            setFlashMessage('error', 'Invalid master key. Please try again.');
        }
    }
    header('Location: security_control.php');
    exit();
}

// Get pending approvals
$approvals = getPendingUserApprovals();
$masterKeyVerified = isMasterKeyVerified();
?>

<div class="page-header">
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

<!-- Flash Messages -->
<?php 
$flash = getFlashMessage();
if ($flash):
?>
<div style="margin-bottom: 15px; padding: 12px 15px; background: <?php echo $flash['type'] == 'success' ? '#EBF5FB' : '#FDEDEC'; ?>; border-left: 4px solid <?php echo $flash['type'] == 'success' ? '#3498DB' : '#E74C3C'; ?>; border-radius: 6px; color: <?php echo $flash['type'] == 'success' ? '#1F618D' : '#C0392B'; ?>;">
        <strong><?php echo ucfirst($flash['type']); ?>:</strong> <?php echo $flash['message']; ?>
    </div>
<?php endif; ?>

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
                            <button class="action-btn assign" onclick="approveRequest(<?php echo $req['id']; ?>)" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="action-btn delete" onclick="rejectRequest(<?php echo $req['id']; ?>)" title="Reject">
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

<!-- Approve Request Modal -->
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

<!-- Reject Request Modal -->
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

<script>
function approveRequest(id) {
    document.getElementById('approveId').value = id;
    document.getElementById('approveModal').style.display = 'flex';
}

function rejectRequest(id) {
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
        document.getElementById('masterKeyModal').style.display = 'none';
        document.getElementById('approveModal').style.display = 'none';
        document.getElementById('rejectModal').style.display = 'none';
    }
});

// Close modals on outside click
document.addEventListener('click', function(e) {
    const modals = ['masterKeyModal', 'approveModal', 'rejectModal', 'requestDetailModal'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
