<?php
/**
 * KBMC Asset Management - Login Page
 * Features: Remember Me, Account Lockout Protection
 */
require_once 'includes/functions.php';

$error = '';

// Check Remember Me cookie first
if (checkRememberMe()) {
    logAudit($_SESSION['user_id'], 'Login (Remember Me)', 'users', $_SESSION['user_id']);
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($_SESSION['role'] === 'it_staff') {
        header('Location: it_dashboard.php');
    } else {
        header('Location: requests.php');
    }
    exit();
}

if (isLoggedIn()) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($_SESSION['role'] === 'it_staff') {
        header('Location: it_dashboard.php');
    } else {
        header('Location: requests.php');
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        // Check if account is locked
        $lockedUntil = isAccountLocked($email);
        if ($lockedUntil) {
            $minsLeft = ceil((strtotime($lockedUntil) - time()) / 60);
            $error = "Account temporarily locked due to too many failed attempts. Please try again in {$minsLeft} minute(s).";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && $user['status'] !== 'active') {
                $error = 'Your account has been deactivated. Please contact your administrator or use Account Recovery.';
            } elseif ($user && password_verify($password, $user['password'])) {
                // Success - reset failed logins
                resetFailedLogins($user['id']);

                // Set session variables - ensure role is valid
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = isValidRole($user['role']) ? $user['role'] : 'employee'; // Fallback to employee if invalid
                $_SESSION['full_name'] = $user['full_name'];

                // Remember Me
                if ($remember) {
                    setRememberMe($user['id']);
                }

                logAudit($user['id'], 'Login', 'users', $user['id']);
                if ($user['role'] === 'admin') {
                    header('Location: admin_dashboard.php');
                } elseif ($user['role'] === 'it_staff') {
                    header('Location: it_dashboard.php');
                } else {
                    header('Location: requests.php');
                }
                exit();
            } else {
                // Failed login
                recordFailedLogin($email);
                $remaining = 5;
                if ($user) {
                    $stmt = $pdo->prepare("SELECT failed_logins FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $failed = $stmt->fetchColumn();
                    $remaining = max(0, 5 - $failed);
                }
                $error = 'Invalid email or password.';
                if ($remaining > 0 && $remaining < 5) {
                    $error .= " {$remaining} attempt(s) remaining before lockout.";
                }
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
    <title>Login - KBMC Asset Management</title>
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
            <h3>Welcome Back!</h3>
            <p>Please sign in to your account to continue.</p>

            <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;">
                <i class="fas fa-times-circle"></i> <?php echo $error; ?>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="text" name="email" id="email" class="form-control" placeholder="Enter your email" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                </div>
                <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                    <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; margin: 0; cursor: pointer;">
                        <input type="checkbox" name="remember" id="remember" style="width: auto; cursor: pointer;"> 
                        <span>Remember me for 30 days</span>
                    </label>
                    <a href="forgot_password.php" style="color: var(--kbmc-red); text-decoration: none; font-weight: 600;">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div style="text-align: center; margin-top: 15px;">
                <a href="account_recovery.php" style="color: #666; text-decoration: none; font-size: 13px;">
                    <i class="fas fa-user-shield"></i> Account locked or deactivated? Request recovery
                </a>
            </div>

            <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                <p style="font-size: 13px; color: #666; margin: 0 0 8px 0;">Don't have an account?</p>
                <a href="signup.php" class="btn btn-outline" style="width: 100%; justify-content: center;">
                    <i class="fas fa-user-plus"></i> Create Employee Account
                </a>
            </div>

            <div class="login-footer">
                <p><strong>Default Login Credentials:</strong></p>
                <p><strong>Admin:</strong> admin@kbmc.com / password</p>
                <p><strong>IT Staff:</strong> itstaff@kbmc.com / password</p>
                <p><strong>Employee:</strong> employee@kbmc.com / password</p>
            </div>
        </div>
    </div>
</body>
</html>
