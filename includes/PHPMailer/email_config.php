<?php
/**
 * KBMC Asset Management - Email Configuration
 * Uses PHPMailer for reliable email delivery
 * 
 * ============================================
 * SETUP INSTRUCTIONS
 * ============================================
 * 
 * 1. Get Gmail App Password:
 *    https://myaccount.google.com/apppasswords
 *    - Sign in -> Select "Mail" -> Copy 16-char password
 * 
 * 2. Fill in your credentials below (lines 39-45)
 * 
 * 3. Save and test!
 * ============================================
 */

// Prevent redeclaration errors
if (!class_exists('PHPMailer\PHPMailer\PHPMailer', false)) {
    $phpmailer_path = __DIR__ . '/src/PHPMailer.php';
    if (file_exists($phpmailer_path)) {
        require_once $phpmailer_path;
    }
}
if (!class_exists('PHPMailer\PHPMailer\SMTP', false)) {
    $smtp_path = __DIR__ . '/src/SMTP.php';
    if (file_exists($smtp_path)) {
        require_once $smtp_path;
    }
}
if (!class_exists('PHPMailer\PHPMailer\Exception', false)) {
    $exception_path = __DIR__ . '/src/Exception.php';
    if (file_exists($exception_path)) {
        require_once $exception_path;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ============================================
// CONFIGURE YOUR EMAIL HERE
// ============================================
$email_settings = [
    'from_email'    => 'itbizserv@kbmc.com.ph',      // <-- CHANGE THIS
    'from_name'     => 'KBMC Asset Management',
    'smtp_host'     => 'smtp.gmail.com',
    'smtp_port'     => 587,
    'smtp_secure'   => 'tls',
    'smtp_auth'     => true,
    'smtp_user'     => 'itbizserv@kbmc.com.ph',      // <-- CHANGE THIS
    'smtp_pass'     => 'rmyl hwxj htgb uhbb',                          // <-- CHANGE THIS: 16-char App Password
];

/**
 * Send email using PHPMailer
 */
function sendEmail($to, $subject, $body, $html = true, $attachments = []) {
    global $email_settings;

    // Check if email is configured
    if (empty($email_settings['smtp_user']) || empty($email_settings['smtp_pass'])) {
        return [
            'success' => false,
            'message' => 'Email not configured. Please set your Gmail credentials in includes/email_config.php'
        ];
    }

    // Check if PHPMailer class exists
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return [
            'success' => false,
            'message' => 'PHPMailer library not found. Please ensure PHPMailer.php, SMTP.php, and Exception.php are in the includes/ folder.'
        ];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $email_settings['smtp_host'];
        $mail->SMTPAuth   = $email_settings['smtp_auth'];
        $mail->Username   = $email_settings['smtp_user'];
        $mail->Password   = $email_settings['smtp_pass'];
        $mail->SMTPSecure = $email_settings['smtp_secure'];
        $mail->Port       = $email_settings['smtp_port'];

        // XAMPP/localhost SSL fix - REMOVE IN PRODUCTION
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->SMTPDebug = 0;
        $mail->setFrom($email_settings['from_email'], $email_settings['from_name']);
        $mail->addAddress($to);
        $mail->addReplyTo($email_settings['from_email'], $email_settings['from_name']);
        $mail->isHTML($html);
        $mail->Subject = '[KBMC] ' . $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (is_array($attachment) && !empty($attachment['path'])) {
                    $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                } elseif (is_string($attachment) && file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
        }

        $mail->send();

        return [
            'success' => true,
            'message' => 'Email sent successfully to ' . $to
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Email failed: ' . $mail->ErrorInfo
        ];
    }
}

/**
 * Check if email is configured
 */
function isEmailConfigured() {
    global $email_settings;
    return !empty($email_settings['smtp_user']) && !empty($email_settings['smtp_pass']);
}

/**
 * Generate HTML email template with KBMC branding
 */
function emailTemplate($title, $content, $buttonText = '', $buttonUrl = '') {
    $year = date('Y');
    $buttonHtml = '';
    if ($buttonText && $buttonUrl) {
        $buttonHtml = "
        <div style='text-align: center; margin: 30px 0;'>
            <a href='{$buttonUrl}' style='background: #D9232E; color: white; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block; font-size: 14px;'>{$buttonText}</a>
        </div>";
    }

    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0; padding:0; background:#f5f6fa; font-family: Arial, Helvetica, sans-serif; line-height: 1.6;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0'>
<tr><td align='center' style='padding: 40px 20px;'>
    <table width='600' cellpadding='0' cellspacing='0' border='0' style='background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 100%;'>
        <tr>
            <td style='background: #D9232E; padding: 30px; text-align: center;'>
                <h2 style='color: white; margin: 0; font-size: 24px; font-weight: 700;'>KBMC</h2>
                <p style='color: rgba(255,255,255,0.85); margin: 5px 0 0; font-size: 13px;'>Kitchen Beauty Marketing Corporation</p>
            </td>
        </tr>
        <tr>
            <td style='padding: 35px 30px;'>
                <h3 style='color: #2c3e50; margin: 0 0 15px; font-size: 18px; font-weight: 600;'>{$title}</h3>
                <div style='color: #555; font-size: 14px; line-height: 1.7;'>
                    {$content}
                </div>
                {$buttonHtml}
                <hr style='border: none; border-top: 1px solid #eee; margin: 25px 0;'>
                <p style='color: #999; font-size: 12px; margin: 0; line-height: 1.5;'>
                    If you didn't request this, please ignore this email or contact your IT administrator.<br>
                    This is an automated message from KBMC Asset Management System.<br>
                    Do not reply to this email.
                </p>
            </td>
        </tr>
        <tr>
            <td style='background: #f8f9fa; padding: 15px; text-align: center;'>
                <p style='color: #999; font-size: 11px; margin: 0;'>
                    &copy; {$year} Kitchen Beauty Marketing Corporation. All rights reserved.
                </p>
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>";
}