# KBMC Asset Management - Email Setup Guide

## For Local XAMPP Development

### Quick Start (No Email - Link Display Only)
By default, the system shows the reset link on screen. This works immediately without any configuration.

### Full Email Setup (Recommended for Production)

#### Method 1: Gmail SMTP (Easiest)

1. **Get a Gmail App Password:**
   - Go to https://myaccount.google.com/apppasswords
   - Sign in with your Google account
   - Select "Mail" and your device
   - Copy the 16-character app password

2. **Edit php.ini:**
   ```
   File: C:\xampp\php\php.ini

   [mail function]
   SMTP = smtp.gmail.com
   smtp_port = 587
   sendmail_from = your-email@gmail.com
   sendmail_path = "C:\xampp\sendmail\sendmail.exe -t"
   ```

3. **Edit sendmail.ini:**
   ```
   File: C:\xampp\sendmail\sendmail.ini

   [sendmail]
   smtp_server=smtp.gmail.com
   smtp_port=587
   error_logfile=error.log
   debug_logfile=debug.log
   auth_username=your-email@gmail.com
   auth_password=your-16-char-app-password
   force_sender=your-email@gmail.com
   ```

4. **Restart Apache** in XAMPP Control Panel

5. **Test:** Go to Forgot Password -> Enter email -> Should receive email

#### Method 2: PHPMailer (Most Reliable)

1. Download PHPMailer:
   ```bash
   cd C:\xampp\htdocs\kbmc_asset_management\includes
   git clone https://github.com/PHPMailer/PHPMailer.git
   ```

2. Or download ZIP from https://github.com/PHPMailer/PHPMailer/releases
   Extract to: includes/PHPMailer/

3. Create or update includes/email_config.php to load the PHPMailer config file and expose sendEmail().
   - Use the `includes/PHPMailer/` path.
   - This wrapper ensures the forgot password page can send reset links by email.

4. Update `includes/PHPMailer/email_config.php` with your Gmail credentials.

#### Method 3: Mailtrap (For Testing - No Real Emails)

1. Sign up at https://mailtrap.io
2. Get your SMTP credentials
3. Use those in sendmail.ini instead of Gmail

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Failed to connect to mailserver" | SMTP not configured in php.ini |
| "Authentication failed" | Wrong Gmail password - use App Password, not regular password |
| "Connection refused" | Firewall blocking port 587 - try port 465 with SSL |
| Email in spam folder | Add noreply@kbmc.com to contacts |
| No email received | Check Mailtrap or use link display fallback |

## Security Notes

- Never commit email passwords to Git
- Use environment variables for credentials in production
- Always use App Passwords, never your main Gmail password
- Enable 2FA on your Gmail account
