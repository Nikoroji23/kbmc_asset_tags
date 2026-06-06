<?php
/**
 * KBMC Asset Management - Reset Password
 * FIXED: Token expiry now uses PHP time comparison to avoid MySQL/PHP timezone mismatch
 */
require_once 'includes/functions.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$userId = null;
$reset = null;

// Validate token using PHP time (avoids MySQL/PHP timezone mismatch)
if ($token) {
    $stmt = $pdo->prepare("
        SELECT pr.user_id, pr.expires_at, pr.created_at, u.full_name, u.email 
        FROM password_resets pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if ($reset) {
        // FIX: Compare expiry using PHP strtotime() instead of MySQL NOW()
        // This avoids timezone mismatch between PHP and MySQL
        $expiresTimestamp = strtotime($reset['expires_at']);
        $currentTimestamp = time();

        if ($expiresTimestamp && $expiresTimestamp > $currentTimestamp) {
            $userId = $reset['user_id'];
        } else {
            $error = 'This reset link has expired. Please request a new one.';
            // Clean up expired token
            $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        }
    } else {
        $error = 'Invalid reset link. Please request a new one.';
    }
} else {
    $error = 'No reset token provided.';
}

// Process password reset
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $userId) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Update password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashedPassword, $userId]);

            // Delete used token (single-use)
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);

            // Log the action
            logAudit($userId, 'Password Reset Complete', 'users', $userId);

            $success = 'Your password has been reset successfully! You can now log in with your new password.';
        } catch (PDOException $e) {
            $error = 'Error resetting password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - KBMC Asset Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .reset-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f6fa 0%, #fde8e9 100%);
            padding: 20px;
        }
        .reset-box {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 450px;
            padding: 40px;
            text-align: center;
        }
        .reset-logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 20px;
        }
        .reset-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .reset-box h3 {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        .reset-box p {
            font-size: 14px;
            color: #888;
            margin-bottom: 25px;
        }
        .user-info {
            background: var(--kbmc-red-light);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
        }
        .user-info i {
            color: var(--kbmc-red);
            font-size: 20px;
            margin-bottom: 5px;
        }
        .user-info div {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 600;
        }
        .user-info small {
            font-size: 12px;
            color: #666;
        }
        .reset-form .form-group {
            text-align: left;
            margin-bottom: 18px;
        }
        .reset-form .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        .password-strength {
            height: 4px;
            background: #eee;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
            border-radius: 2px;
        }
        .strength-weak { background: #e74c3c; width: 33%; }
        .strength-medium { background: #f39c12; width: 66%; }
        .strength-strong { background: #27ae60; width: 100%; }
        .strength-text {
            font-size: 11px;
            margin-top: 4px;
            color: #888;
        }
        .reset-form .btn {
            width: 100%;
            justify-content: center;
            padding: 13px;
            font-size: 15px;
            margin-top: 10px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
            margin-top: 20px;
            transition: color 0.3s;
        }
        .back-link:hover {
            color: var(--kbmc-red);
        }
        .success-icon {
            width: 70px;
            height: 70px;
            background: #27ae60;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-box">
            <div class="reset-logo">
                <img src="assets/images/logo.png" alt="KBMC Logo">
            </div>

            <?php if ($success): ?>
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h3>Password Reset!</h3>
            <p><?php echo $success; ?></p>
            <a href="login.php" class="btn btn-primary btn-lg" style="width: 100%; justify-content: center;">
                <i class="fas fa-sign-in-alt"></i> Go to Login
            </a>

            <?php elseif ($error): ?>
            <div style="width: 70px; height: 70px; background: #e74c3c; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px;">
                <i class="fas fa-times"></i>
            </div>
            <h3>Reset Failed</h3>
            <div class="alert alert-error" style="margin: 20px 0; text-align: left;">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
            <a href="forgot_password.php" class="btn btn-primary btn-lg" style="width: 100%; justify-content: center;">
                <i class="fas fa-redo"></i> Request New Link
            </a>

            <?php elseif ($reset): ?>
            <h3>Reset Password</h3>
            <p>Create a new password for your account.</p>

            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <div><?php echo sanitize($reset['full_name']); ?></div>
                <small><?php echo sanitize($reset['email']); ?></small>
            </div>

            <form method="POST" class="reset-form">
                <div class="form-group">
                    <label for="password">New Password <span style="color: var(--kbmc-red);">*</span></label>
                    <input type="password" name="password" id="password" class="form-control" 
                           placeholder="Enter new password (min 6 characters)" required minlength="6"
                           oninput="checkStrength(this.value)">
                    <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="strength-text" id="strengthText"></div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span style="color: var(--kbmc-red);">*</span></label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                           placeholder="Confirm new password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Reset Password
                </button>
            </form>
            <?php endif; ?>

            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        function checkStrength(password) {
            const bar = document.getElementById('strengthBar');
            const text = document.getElementById('strengthText');

            if (password.length === 0) {
                bar.className = 'password-strength-bar';
                bar.style.width = '0';
                text.textContent = '';
                return;
            }

            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            bar.className = 'password-strength-bar';
            if (strength <= 2) {
                bar.classList.add('strength-weak');
                text.textContent = 'Weak password';
                text.style.color = '#e74c3c';
            } else if (strength <= 4) {
                bar.classList.add('strength-medium');
                text.textContent = 'Medium strength';
                text.style.color = '#f39c12';
            } else {
                bar.classList.add('strength-strong');
                text.textContent = 'Strong password';
                text.style.color = '#27ae60';
            }
        }
    </script>
</body>
</html>