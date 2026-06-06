<?php
/**
 * KBMC Asset Management - Forgot Password
 * Uses PHPMailer for reliable email delivery
 */
require_once 'includes/functions.php';

$message = '';
$error = '';
$resetLink = '';
$emailSent = false;
$emailResult = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
            $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expires]);

            if (defined('BASE_URL') && BASE_URL !== '') {
                $resetLink = rtrim(BASE_URL, '/') . '/reset_password.php?token=' . $token;
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $basePath = dirname($_SERVER['PHP_SELF']);
                $basePath = $basePath === '/' ? '' : $basePath;
                $resetLink = $protocol . '://' . $host . $basePath . '/reset_password.php?token=' . $token;
            }

            // Send email with password reset link
            $emailBody = emailTemplate(
                'Password Reset Request',
                "<p>Hello <strong>" . sanitize($user['full_name']) . "</strong>,</p>
                <p>We received a request to reset your password for the KBMC Asset Management System.</p>
                <p>If you made this request, click the button below to reset your password. This link will expire in <strong>1 hour</strng>.</p>",
                'Reset My Password',
                $resetLink
            );

            $emailResult = sendEmail($user['email'], 'Password Reset Request', $emailBody);
            $emailSent = $emailResult['success'];

            logAudit($user['id'], 'Password Reset Request', 'users', $user['id']);

            if ($emailSent) {
                $message = 'A password reset link has been sent to <strong>' . sanitize($email) . '</strong>. Please check your inbox (and spam folder) within the next 1 hour.';
            } else {
                $error = 'Unable to send reset email. Please try again later or contact support.';
            }
        } else {
            // Don't reveal if email exists
            $message = 'If this email exists in our system, a password reset link will be sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - KBMC Asset Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .forgot-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #f5f6fa 0%, #fde8e9 100%); padding: 30px; }
        .forgot-box { background: white; border-radius: 10px; box-shadow: 0 5px 25px rgba(0,0,0,0.15); width: 80%; max-width: 420px; padding: 30px; text-align: center; min-heigt: 300px; }
        .forgot-logo { width: 60px; height: 60px; margin: 0 auto 30px; }
        .forgot-logo img { width: 100%; height: 100%; object-fit: contain; }
        .forgot-box h3 { font-size: 28px; color: #2c3e50; margin-bottom: 8px; }
        .forgot-box p { font-size: 14px; color: #888; margin-bottom: 25px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #666; text-decoration: none; font-size: 14px; margin-top: 20px; transition: color 0.3s; }
        .back-link:hover { color: var(--kbmc-red); }
        .forgot-form .form-group { text-align: left; margin-bottom: 30px; }
        .forgot-form .form-group label { display: block; font-size: 13px; font-weight: 600; color: #2c3e50; margin-bottom: 12px; }
        .forgot-form .btn { width: 100%; justify-content: center; padding: 13px; font-size: 15px; }

        /* Fix alert layout — overrides any flex/grid from style.css */
        .forgot-box .alert {
            display: block !important;
            text-align: left !important;
            word-break: break-word !important;
            white-space: normal !important;
            overflow: visible !important;
            margin-bottom: 25px;
            padding: 14px 16px;
            line-height: 1.6;
        }
        .forgot-box .alert i {
            margin-right: 8px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-box">
            <div class="forgot-logo">
                <img src="assets/images/logo.png" alt="KBMC Logo">
            </div>
            <h3>Forgot Password?</h3>
            <p>Enter your email address and we'll send you a password reset link.</p>

            <?php if ($error): ?>
            <div class="alert alert-error" style="display:flex !important; align-items:flex-start; gap:10px; text-align:left; margin-bottom:25px; padding:14px 16px; line-height:1.6; word-break:break-word;">
                <i class="fas fa-times-circle" style="flex-shrink:0; margin-top:2px;"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="alert alert-success" style="display:flex !important; align-items:flex-start; gap:10px; text-align:left; margin-bottom:25px; padding:14px 16px; line-height:1.6; word-break:break-word;">
                <i class="fas fa-check-circle" style="flex-shrink:0; margin-top:2px;"></i>
                <span><?php echo $message; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!$emailSent && empty($error)): ?>
            <form method="POST" class="forgot-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" 
                           placeholder="Enter your registered email" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>
            <?php endif; ?>

            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        // Email-only password reset (no manual link display)
    </script>
</body>
</html>