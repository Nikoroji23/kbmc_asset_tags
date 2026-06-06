<?php
/**
 * KBMC Asset Management - User Actions Handler
 * Handles user creation, status toggle, deletion, and account recovery actions.
 */
require_once 'includes/functions.php';
requireITStaff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    setFlashMessage('error', 'Invalid request token. Please try again.');
    redirect('users.php');
}

$action = $_POST['action'] ?? '';
switch ($action) {
    case 'add_user':
        handleAddUser();
        break;
    case 'toggle_user':
        handleToggleUser();
        break;
    case 'delete_user':
        handleDeleteUser();
        break;
    case 'process_recovery':
        handleRecoveryRequest();
        break;
    default:
        setFlashMessage('error', 'Unknown action.');
        redirect('users.php');
}

function handleAddUser() {
    global $pdo;

    $employee_id = trim($_POST['employee_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'employee');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $phone = trim($_POST['phone_full'] ?? '');

    if (empty($full_name) || empty($email) || empty($password)) {
        setFlashMessage('error', 'Full name, email and password are required.');
        redirect('users.php');
    }

    if (!isValidEmail($email)) {
        setFlashMessage('error', 'Please enter a valid email address.');
        redirect('users.php');
    }

    // Validate role - must be one of the allowed values
    $allowed_roles = ['admin', 'it_staff', 'employee'];
    if (!in_array($role, $allowed_roles)) {
        setFlashMessage('error', 'Invalid role selected.');
        redirect('users.php');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        setFlashMessage('error', 'A user with that email already exists.');
        redirect('users.php');
    }

    try {
        // If role is IT Staff or Admin, create approval request
        if ($role === 'it_staff' || $role === 'admin') {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $reason = trim($_POST['request_reason'] ?? 'User creation request');
            
            $success = createUserApprovalRequest(
                $_SESSION['user_id'],
                $full_name,
                $email,
                $role,
                $employee_id,
                $department,
                $position,
                $phone,
                $passwordHash,
                $reason
            );
            
            if ($success) {
                $approvalRequestId = $pdo->lastInsertId();
                $roleDisplay = $role === 'admin' ? 'Administrator' : 'IT Staff';
                setFlashMessage('success', "User creation request submitted for $roleDisplay approval. Security IT approval required.");
                logAudit($_SESSION['user_id'], 'Submit User Approval Request', 'user_approval_requests', null, null, "role=$role, user=$email");

                $securityITApprovers = $pdo->query("SELECT id FROM users WHERE role = 'it_staff' AND is_security_admin = 1 AND status = 'active'")->fetchAll();
                foreach ($securityITApprovers as $approver) {
                    addNotification(
                        $approver['id'],
                        'user_approval_pending',
                        'New IT/Admin User Request',
                        "A $roleDisplay account request for $full_name has been submitted. Review pending approvals.",
                        $approvalRequestId
                    );
                }
            } else {
                setFlashMessage('error', 'Failed to submit approval request.');
            }
        } else {
            // Direct creation for regular employees
            $stmt = $pdo->prepare("INSERT INTO users (employee_id, full_name, email, password, role, department, position, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $result = $stmt->execute([
                $employee_id,
                $full_name,
                $email,
                password_hash($password, PASSWORD_BCRYPT),
                $role,
                $department,
                $position,
                $phone,
            ]);

            if ($result) {
                $newUserId = $pdo->lastInsertId();
                logAudit($_SESSION['user_id'], 'Create User', 'users', $newUserId, null, "role=employee");
                setFlashMessage('success', "Employee user '$full_name' created successfully.");
            } else {
                setFlashMessage('error', 'Failed to create user.');
            }
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Error adding user: ' . $e->getMessage());
    }

    redirect('users.php');
}

function handleToggleUser() {
    if (!hasRole('admin')) {
        setFlashMessage('error', 'Only administrators can update user status.');
        redirect('users.php');
    }

    $userId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($userId <= 0) {
        setFlashMessage('error', 'Invalid user selected.');
        redirect('users.php');
    }

    $newStatus = toggleUserStatus($userId);
    if ($newStatus === false) {
        setFlashMessage('error', 'Unable to update user status.');
    } else {
        setFlashMessage('success', 'User status updated to ' . $newStatus . '.');
    }

    redirect('users.php');
}

function handleDeleteUser() {
    if (!hasRole('admin')) {
        setFlashMessage('error', 'Only administrators can delete users.');
        redirect('users.php');
    }

    $userId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($userId <= 0) {
        setFlashMessage('error', 'Invalid user selected.');
        redirect('users.php');
    }

    try {
        if (deleteUserById($userId)) {
            setFlashMessage('success', 'User deleted successfully.');
        } else {
            setFlashMessage('error', 'Unable to delete user.');
        }
    } catch (PDOException $e) {
        setFlashMessage('error', 'Error deleting user: ' . $e->getMessage());
    }

    redirect('users.php');
}

function handleRecoveryRequest() {
    $recoveryId = isset($_POST['recovery_id']) ? (int) $_POST['recovery_id'] : 0;
    $approvalAction = $_POST['approval_action'] ?? '';

    if ($recoveryId <= 0 || !in_array($approvalAction, ['approve', 'reject'], true)) {
        setFlashMessage('error', 'Invalid recovery action.');
        redirect('users.php#recovery');
    }

    if (processRecoveryRequest($recoveryId, $approvalAction, $_SESSION['user_id'])) {
        setFlashMessage('success', 'Recovery request processed successfully.');
    } else {
        setFlashMessage('error', 'Unable to process recovery request.');
    }

    redirect('users.php#recovery');
}
