<?php
/**
 * KBMC Asset Management - Employee Sign Up
 * Employees can create their own accounts
 */
require_once 'includes/functions.php';

$error = '';
$success = false;

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup'])) {
    $employee_id = trim($_POST['employee_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');

    // Validation
    if (empty($employee_id)) {
        $error = 'Employee ID is required.';
    } elseif (empty($full_name)) {
        $error = 'Full name is required.';
    } elseif (empty($email) || !isValidEmail($email)) {
        $error = 'Valid email address is required.';
    } elseif (empty($password) || strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (empty($department)) {
        $error = 'Department is required.';
    } elseif (in_array(strtolower($department), ['it', 'administration', 'admin'], true)
        || preg_match('/\b(it|admin|administrator|security)\b/i', $position)) {
        $error = 'IT or Administrator accounts cannot be created through self-registration. Please contact your administrator to request the proper account.';
    } else {
        // Check if employee_id or email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? OR email = ?");
        $checkStmt->execute([$employee_id, $email]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $error = 'Employee ID or email is already registered.';
        } else {
            try {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                // Insert new employee
                $stmt = $pdo->prepare("
                    INSERT INTO users (employee_id, full_name, email, password, phone, department, position, role, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'employee', 'active')
                ");
                $stmt->execute([$employee_id, $full_name, $email, $hashedPassword, $phone, $department, $position]);

                $newUserId = $pdo->lastInsertId();

                // Log the registration
                logAudit($newUserId, 'Employee Registration', 'users', $newUserId);

                // Log account creation for admin records
                $pdo->prepare("INSERT INTO account_creations (user_id, employee_id, full_name, email, department, position, phone, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$newUserId, $employee_id, $full_name, $email, $department, $position, $phone, 'self_registration']);

                // Send email to IT staff about new account creation
                $itStaff = $pdo->query("SELECT id, email, full_name FROM users WHERE role = 'it_staff' AND status = 'active'")->fetchAll();
                if (!empty($itStaff) && isEmailConfigured()) {
                    foreach (filterUniqueEmails($itStaff) as $staff) {
                        $emailBody = emailTemplate(
                            'New Employee Account Created',
                            "<p>Hello <strong>" . sanitize($staff['full_name']) . "</strong>,</p>
                            <p>A new employee account has been registered in the KBMC Asset Management System.</p>
                            <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                                <p><strong>Account Details:</strong></p>
                                <p><i class='fas fa-id-card'></i> <strong>Employee ID:</strong> " . sanitize($employee_id) . "</p>
                                <p><i class='fas fa-user'></i> <strong>Name:</strong> " . sanitize($full_name) . "</p>
                                <p><i class='fas fa-envelope'></i> <strong>Email:</strong> " . sanitize($email) . "</p>
                                <p><i class='fas fa-building'></i> <strong>Department:</strong> " . sanitize($department) . "</p>
                                <p><i class='fas fa-briefcase'></i> <strong>Position:</strong> " . sanitize($position ?: 'Not specified') . "</p>
                                <p><i class='fas fa-phone'></i> <strong>Phone:</strong> " . sanitize($phone ?: 'Not provided') . "</p>
                                <p><i class='fas fa-calendar'></i> <strong>Registration Date:</strong> " . date('F d, Y h:i A') . "</p>
                            </div>
                            <p>Please ensure this account is properly configured in the system.</p>",
                            'View Admin Records',
                            'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/admin_accounts.php'
                        );
                        sendEmail($staff['email'], 'New Employee Account Registered - ' . sanitize($full_name), $emailBody);
                    }
                }

                // Also notify admins
                $admins = $pdo->query("SELECT id, email, full_name FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
                if (!empty($admins) && isEmailConfigured()) {
                    foreach (filterUniqueEmails($admins) as $admin) {
                        $emailBody = emailTemplate(
                            'New Employee Account Registration',
                            "<p>Hello <strong>" . sanitize($admin['full_name']) . "</strong>,</p>
                            <p>A new employee account has been created in the system. Please review the account creation records for archival purposes.</p>
                            <div style='background: #f0f7ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3498db;'>
                                <p><strong>Employee Information:</strong></p>
                                <p><strong>Name:</strong> " . sanitize($full_name) . " (" . sanitize($employee_id) . ")</p>
                                <p><strong>Email:</strong> " . sanitize($email) . "</p>
                                <p><strong>Department:</strong> " . sanitize($department) . "</p>
                            </div>",
                            'View Account Records',
                            'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/admin_accounts.php'
                        );
                        sendEmail($admin['email'], 'New Account Registration Notice', $emailBody);
                    }
                }

                $success = true;
            } catch (PDOException $e) {
                $error = 'Error creating account: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Sign Up - KBMC Asset Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-left">
            <img src="assets/images/logo.png" alt="KBMC Logo" class="login-logo-img">
            <h2>Kitchen Beauty<br>Marketing Corp.</h2>
            <p>Device Arrival & Asset Management System. Track, manage, and monitor your IT assets throughout their entire lifecycle.</p>
            <ul class="login-features">
                <li><i class="fas fa-check-circle"></i> Complete device lifecycle management</li>
                <li><i class="fas fa-check-circle"></i> Role-based access control</li>
                <li><i class="fas fa-check-circle"></i> Real-time notifications & alerts</li>
                <li><i class="fas fa-check-circle"></i> Comprehensive reports & analytics</li>
            </ul>
        </div>
        <div class="login-right">
            <h3><i class="fas fa-user-plus"></i> Create Employee Account</h3>
            <p>Sign up to access the asset management system.</p>
            <p style="font-size: 13px; color: #555; margin-bottom: 18px;">
                Note: This form is for general employee accounts only. IT and Administrator accounts cannot be self-registered.
            </p>

            <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <strong>Account created successfully!</strong>
                <p style="margin-top: 8px; font-size: 13px;">Redirecting to login page...</p>
            </div>
            <script>
                setTimeout(() => window.location.href = 'login.php', 2000);
            </script>
            <?php elseif ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;">
                <i class="fas fa-times-circle"></i> <?php echo $error; ?>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" class="login-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="employee_id">Employee ID *</label>
                        <input type="text" name="employee_id" id="employee_id" class="form-control" placeholder="e.g., EMP-001" required
                               value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Enter your full name" required
                               value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="text" name="email" id="email" class="form-control" placeholder="Enter your email" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Min 8 characters" required pattern = "^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$" title="Password must be at least 8 characters long and include uppercase letters, lowercase letters, and numbers">
                        
                        <small style="color: #999;">Min 8 characters, include uppercase, lowercase, and numbers</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter password" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="department">Department *</label>
                        <select name="department" id="department" class="form-control" required>
                            <option value="">-- Select Department --</option>
                            <option value="Sales">Sales</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Logistics">Logistics</option>
                            <option value="HR">Human Resources</option>
                            <option value="Finance">Finance</option>
                            <option value="Supply Chain">Supply Chain</option>
                            <option value="QC/Technical">QC/Technical</option>
                            <option value="Warehouse">Warehouse</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="position">Position</label>
                        <input type="text" name="position" id="position" class="form-control" placeholder="e.g., Sales Manager"
                               value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" name="phone" id="phone" class="form-control" placeholder="Enter your 11-digit phone number"
                           pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits"
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    <small style="color: #999;">Must be 11 digits (e.g., 09123456789)</small>
                </div>

                <button type="submit" name="signup" class="btn btn-primary btn-lg">
                    <i class="fas fa-user-check"></i> Create Account
                </button>
            </form>

            <div style="text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                <p style="font-size: 13px; color: #666; margin-bottom: 8px;">Already have an account?</p>
                <a href="login.php" class="btn btn-outline" style="width: 100%; justify-content: center;">
                    <i class="fas fa-sign-in-alt"></i> Sign In Instead
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
