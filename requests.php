<?php
/**
 * KBMC Asset Management - Device Requests
 */
$pageTitle = 'Device Requests';
require_once 'includes/header.php';

// Submit request (employees)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_request'])) {
    $device_type_id = $_POST['device_type_id'] ?? null;
    $request_reason = trim($_POST['request_reason'] ?? '');
    $urgency = $_POST['urgency'] ?? 'medium';

    try {
        $stmt = $pdo->prepare("INSERT INTO device_requests (requester_id, device_type_id, request_reason, urgency, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$_SESSION['user_id'], $device_type_id, $request_reason, $urgency]);
        $requestId = $pdo->lastInsertId();

        // Get device type name
        $typeName = $device_type_id ? $pdo->query("SELECT type_name FROM device_types WHERE id = $device_type_id")->fetchColumn() : 'Any';
        
        // Notify admins and IT staff (system notification + email)
        $staff = $pdo->query("SELECT id, email, full_name FROM users WHERE role IN ('admin', 'it_staff') AND status = 'active'")->fetchAll();
        
        foreach (filterUniqueEmails($staff) as $s) {
            // System notification (popup in dashboard)
            addNotification($s['id'], 'device_request', 'New Device Request', 
                "New request for $typeName from " . $_SESSION['full_name'] . " (Urgency: " . ucfirst($urgency) . ")", $requestId);
            
            // Email notification
            if (isEmailConfigured()) {
                $requesterEmail = $_SESSION['email'] ?? 'N/A';
                $emailBody = emailTemplate(
                    'New Device Request Submitted',
                    "<p>Hello <strong>" . sanitize($s['full_name']) . "</strong>,</p>
                    <p>A new device request has been submitted and requires your attention.</p>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p><strong>Request Details:</strong></p>
                        <p><i class='fas fa-user'></i> <strong>Requester:</strong> " . sanitize($_SESSION['full_name']) . "</p>
                        <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . sanitize($requesterEmail) . "</p>
                        <p><i class='fas fa-laptop'></i> <strong>Device Type:</strong> " . sanitize($typeName) . "</p>
                        <p><i class='fas fa-exclamation-triangle'></i> <strong>Urgency:</strong> <span style='color: " . ($urgency === 'critical' ? '#e74c3c' : ($urgency === 'high' ? '#f39c12' : '#3498db')) . "; font-weight: bold;'>" . ucfirst($urgency) . "</span></p>
                        <p><i class='fas fa-align-left'></i> <strong>Reason:</strong> " . sanitize($request_reason) . "</p>
                    </div>",
                    'Review Request',
                    'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/requests.php'
                );
                
                sendEmail($s['email'], 'New Device Request - ' . sanitize($typeName), $emailBody);
            }
        }

        setFlashMessage('success', 'Your device request has been submitted. IT staff will be notified.');
        header('Location: requests.php');
        exit();
    } catch (PDOException $e) {
        setFlashMessage('error', 'Error submitting request: ' . $e->getMessage());
    }
}

// Cancel request (requester)
if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id'])) {
    $reqId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM device_requests WHERE id = ? AND requester_id = ? AND status = 'pending'");
    $stmt->execute([$reqId, $_SESSION['user_id']]);
    $request = $stmt->fetch();

    if ($request) {
        $pdo->prepare("UPDATE device_requests SET status = 'cancelled', approved_by = ?, approved_date = CURDATE() WHERE id = ?")->execute([$_SESSION['user_id'], $reqId]);
        setFlashMessage('success', 'Your device request has been cancelled.');
    } else {
        setFlashMessage('error', 'Unable to cancel this request. It may already be processed.');
    }

    header('Location: requests.php');
    exit();
}

// Approve/Reject request (admin/it_staff)
if ((hasRole('admin') || hasRole('it_staff')) && isset($_GET['action']) && isset($_GET['id'])) {
    $reqId = $_GET['id'];
    $newStatus = $_GET['action'] == 'approve' ? 'approved' : 'rejected';
    $pdo->prepare("UPDATE device_requests SET status = ?, approved_by = ?, approved_date = CURDATE() WHERE id = ?")->execute([$newStatus, $_SESSION['user_id'], $reqId]);

    // Get request and requester details
    $reqStmt = $pdo->prepare("SELECT dr.*, dt.type_name, u.email, u.full_name FROM device_requests dr 
        LEFT JOIN device_types dt ON dr.device_type_id = dt.id 
        LEFT JOIN users u ON dr.requester_id = u.id WHERE dr.id = ?");
    $reqStmt->execute([$reqId]);
    $request = $reqStmt->fetch();
    
    if ($request) {
        // System notification (popup in dashboard)
        addNotification($request['requester_id'], $newStatus == 'approved' ? 'request_approved' : 'request_rejected',
            $newStatus == 'approved' ? 'Request Approved ✓' : 'Request Rejected ✗',
            "Your device request for " . ($request['type_name'] ?? 'Any Device') . " has been " . $newStatus . ".", $reqId);
        
        // Email notification to requester
        if (isEmailConfigured() && $request['email']) {
            $statusIcon = $newStatus === 'approved' ? '✓' : '✗';
            $statusColor = $newStatus === 'approved' ? '#27ae60' : '#e74c3c';
            $approverName = $pdo->query("SELECT full_name FROM users WHERE id = " . $_SESSION['user_id'])->fetchColumn();
            
            $emailBody = emailTemplate(
                $newStatus === 'approved' ? 'Device Request Approved' : 'Device Request Rejected',
                "<p>Hello <strong>" . sanitize($request['full_name']) . "</strong>,</p>
                <p style='font-size: 16px; color: " . $statusColor . "; font-weight: bold;'>" . ($newStatus === 'approved' ? "🎉 Your device request has been APPROVED!" : "Your device request has been REJECTED.") . "</p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid " . $statusColor . ";'>
                    <p><strong>Request Details:</strong></p>
                    <p><i class='fas fa-laptop'></i> <strong>Device Type:</strong> " . sanitize($request['type_name'] ?? 'Any Device') . "</p>
                    <p><i class='fas fa-align-left'></i> <strong>Your Reason:</strong> " . sanitize($request['request_reason']) . "</p>
                    <p><i class='fas fa-user'></i> <strong>Processed By:</strong> " . sanitize($approverName) . "</p>
                    <p><i class='fas fa-calendar'></i> <strong>Date:</strong> " . date('F d, Y') . "</p>
                </div>" .
                ($newStatus === 'approved' ? 
                    "<p>Your device has been approved and will be available soon. You'll receive another notification once it's ready for pickup.</p>" :
                    "<p>If you believe this decision is incorrect, please contact the IT department for more information.</p>") .
                "",
                $newStatus === 'approved' ? 'View Request' : 'Contact IT',
                'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/requests.php'
            );
            
            $subject = ($newStatus === 'approved' ? '✓ Device Request Approved' : '✗ Device Request Rejected');
            sendEmail($request['email'], $subject, $emailBody);
        }
    }

    setFlashMessage('success', 'Request ' . $newStatus . '. Notification sent to requester.');
    header('Location: requests.php');
    exit();
}

$types = $pdo->query("SELECT * FROM device_types ORDER BY type_name")->fetchAll();

if (hasRole('admin') || hasRole('it_staff')) {
    $stmt = $pdo->query("SELECT dr.*, dt.type_name, u.full_name as requester_name, u.department, ab.full_name as approved_by_name 
        FROM device_requests dr LEFT JOIN device_types dt ON dr.device_type_id = dt.id 
        JOIN users u ON dr.requester_id = u.id LEFT JOIN users ab ON dr.approved_by = ab.id ORDER BY dr.created_at DESC");
} else {
    $stmt = $pdo->prepare("SELECT dr.*, dt.type_name, u.full_name as requester_name, u.department, ab.full_name as approved_by_name 
        FROM device_requests dr LEFT JOIN device_types dt ON dr.device_type_id = dt.id 
        JOIN users u ON dr.requester_id = u.id LEFT JOIN users ab ON dr.approved_by = ab.id 
        WHERE dr.requester_id = ? ORDER BY dr.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
}
$requests = $stmt->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-hand-paper"></i> Device Requests</h1>
    <?php if (isset($_GET['action']) && $_GET['action'] == 'new'): ?>
    <a href="requests.php" class="btn btn-outline">View Requests</a>
    <?php else: ?>
    <a href="requests.php?action=new" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] == 'new'): ?>
<div class="card">
    <div class="card-header"><h3>Submit Device Request</h3></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>Device Type (Optional)</label>
                    <select name="device_type_id" class="form-control">
                        <option value="">Any / No Preference</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo sanitize($t['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Urgency</label>
                    <select name="urgency" class="form-control">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Request Reason <span class="required">*</span></label>
                    <textarea name="request_reason" class="form-control" placeholder="Explain why you need this device..." required></textarea>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <button type="submit" name="submit_request" class="btn btn-primary btn-lg"><i class="fas fa-paper-plane"></i> Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><?php echo (hasRole('admin') || hasRole('it_staff')) ? 'All Requests' : 'My Requests'; ?></h3>
    </div>
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead><tr><th>ID</th><th>Requester</th><th>Department</th><th>Device Type</th><th>Reason</th><th>Urgency</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="9" class="empty-state" style="padding: 40px;"><i class="fas fa-hand-paper" style="font-size: 40px; color: #ddd;"></i><h4>No requests found</h4></td></tr>
                    <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td>#<?php echo $r['id']; ?></td>
                        <td><?php echo sanitize($r['requester_name']); ?></td>
                        <td><?php echo sanitize($r['department'] ?? '-'); ?></td>
                        <td><?php echo sanitize($r['type_name'] ?? 'Any'); ?></td>
                        <td><?php echo sanitize(substr($r['request_reason'], 0, 50)) . (strlen($r['request_reason']) > 50 ? '...' : ''); ?></td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $r['urgency'] == 'critical' ? '#E74C3C20' : ($r['urgency'] == 'high' ? '#F39C1220' : '#3498DB20'); ?>; color: <?php echo $r['urgency'] == 'critical' ? '#E74C3C' : ($r['urgency'] == 'high' ? '#F39C12' : '#3498DB'); ?>; border: 1px solid <?php echo $r['urgency'] == 'critical' ? '#E74C3C' : ($r['urgency'] == 'high' ? '#F39C12' : '#3498DB'); ?>;"><?php echo ucfirst($r['urgency']); ?></span>
                        </td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $r['status'] == 'pending' ? '#F39C1220' : ($r['status'] == 'approved' ? '#27AE6020' : ($r['status'] == 'fulfilled' ? '#3498DB20' : '#E74C3C20')); ?>; color: <?php echo $r['status'] == 'pending' ? '#F39C12' : ($r['status'] == 'approved' ? '#27AE60' : ($r['status'] == 'fulfilled' ? '#3498DB' : '#E74C3C')); ?>;"><?php echo ucfirst($r['status']); ?></span>
                        </td>
                        <td><?php echo formatDate($r['created_at']); ?></td>
                        <td>
                            <?php if ($r['status'] == 'pending' && $r['requester_id'] == $_SESSION['user_id']): ?>
                            <div class="action-btns">
                                <a href="requests.php?action=cancel&id=<?php echo $r['id']; ?>" class="action-btn delete" title="Cancel Request" onclick="return confirm('Cancel this request?')"><i class="fas fa-ban"></i></a>
                            </div>
                            <?php endif; ?>
                            <?php if ($r['status'] == 'pending' && (hasRole('admin') || hasRole('it_staff'))): ?>
                            <div class="action-btns">
                                <a href="requests.php?action=approve&id=<?php echo $r['id']; ?>" class="action-btn assign" title="Approve" onclick="return confirm('Approve this request?')"><i class="fas fa-check"></i></a>
                                <a href="requests.php?action=reject&id=<?php echo $r['id']; ?>" class="action-btn delete" title="Reject" onclick="return confirm('Reject this request?')"><i class="fas fa-times"></i></a>
                            </div>
                            <?php endif; ?>
                            <?php if (!($r['status'] == 'pending' && ($r['requester_id'] == $_SESSION['user_id'] || hasRole('admin') || hasRole('it_staff')))): ?>
                            <span style="color: #999; font-size: 12px;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
