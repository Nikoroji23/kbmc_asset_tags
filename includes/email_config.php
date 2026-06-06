<?php
// Email config loader wrapper.
// The app includes includes/functions.php, which expects includes/email_config.php.

$primaryEmailConfig = __DIR__ . '/PHPMailer/email_config.php';

if (file_exists($primaryEmailConfig)) {
    require_once $primaryEmailConfig;
} else {
    // Fallback if no email config file exists.
    $email_settings = [
        'from_email' => 'noreply@kbmc.com',
        'from_name'  => 'KBMC Asset Management',
    ];

    function sendEmail($to, $subject, $body, $html = true) {
        return [
            'success' => false,
            'message' => 'Email system not configured. Please set up includes/email_config.php',
        ];
    }

    function isEmailConfigured() {
        return false;
    }

    function emailTemplate($title, $content, $buttonText = '', $buttonUrl = '') {
        return "<html><body><h2>{$title}</h2><div>{$content}</div></body></html>";
    }
}
