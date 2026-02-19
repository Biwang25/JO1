# Invoice Email Function - Setup Guide

## Overview
The invoice email feature allows you to send invoices directly to client emails using PHPMailer and SMTP. This guide will help you configure it properly.

## Files Created/Modified

### 1. **send_invoice_email.php** (New)
   - Handles the email sending functionality
   - Uses PHPMailer for secure SMTP connections
   - Returns JSON response for AJAX handling

### 2. **email_config.php** (New)
   - Contains email configuration settings
   - Keep this file secure and never expose publicly
   - Update with your email credentials

### 3. **dashboard.php** (Modified)
   - Added "Email" button in the invoice actions
   - Added `sendInvoiceEmail()` JavaScript function
   - AJAX handles the email sending without page reload

## Configuration Steps

### For Gmail Users:

1. **Enable 2-Factor Authentication**
   - Go to https://myaccount.google.com/security
   - Enable 2-Step Verification

2. **Generate App Password**
   - Go to https://myaccount.google.com/apppasswords
   - Select "Mail" and "Windows Computer" (or your device)
   - Google will generate a 16-character password

3. **Configure email_config.php**
   ```php
   define('SMTP_HOST', 'smtp.gmail.com');
   define('SMTP_PORT', 587);
   define('SMTP_USERNAME', 'your_email@gmail.com');
   define('SMTP_PASSWORD', 'xxxx xxxx xxxx xxxx');  // 16-char app password
   define('SMTP_FROM_EMAIL', 'your_email@gmail.com');
   define('SMTP_FROM_NAME', 'SmartInvoice System');
   define('SMTP_ENCRYPTION', 'tls');
   ```

### For Office 365 Users:

1. **Configure email_config.php**
   ```php
   define('SMTP_HOST', 'smtp.office365.com');
   define('SMTP_PORT', 587);
   define('SMTP_USERNAME', 'your_email@domain.com');
   define('SMTP_PASSWORD', 'your_office365_password');
   define('SMTP_FROM_EMAIL', 'your_email@domain.com');
   define('SMTP_FROM_NAME', 'SmartInvoice System');
   define('SMTP_ENCRYPTION', 'tls');
   ```

### For Other Email Providers:

   Check your email provider's SMTP settings and enter them in `email_config.php`.

   Common providers:
   - **SendGrid**: smtp.sendgrid.net (Port 587)
   - **AWS SES**: email-smtp.region.amazonaws.com (Port 587)
   - **Yahoo**: smtp.mail.yahoo.com (Port 587)

## How to Use

1. **View Invoices**: Click "View Invoices" in the dashboard
2. **Send Invoice**: Click the green "Email" button in the Actions column
3. **Confirm**: A confirmation dialog will appear
4. **Success/Error**: You'll receive a notification about the email status

## Email Template Features

- Professional HTML email format
- Client name personalization
- Invoice details display
- Amount formatting with Philippine Peso symbol (₱)
- Responsive design for all email clients
- Plain text alternative for email clients that don't support HTML

## Troubleshooting

### "Failed to send invoice. Please check email configuration."
- Verify SMTP credentials in `email_config.php`
- Check if 2-Factor Authentication is enabled (Gmail)
- Verify the app password is correct (Gmail)
- Ensure firewall/network allows SMTP connections on port 587/465

### Email not received
- Check spam/junk folder
- Verify recipient email address is correct
- Check email provider's security settings
- Verify SMTP_FROM_EMAIL is verified on your email provider

### PHPMailer errors
- Check server error logs in your web server directory
- Enable debug mode in send_invoice_email.php (add: $mail->SMTPDebug = 2;)

## Security Notes

1. **Never commit credentials** to version control
2. **Use .gitignore** to exclude email_config.php from git
3. **Restrict file permissions** on email_config.php (chmod 600)
4. **Use app passwords** instead of main account passwords
5. **Enable 2FA** on your email account for extra security

## Database Logging (Optional)

To log sent invoices, you can add a database table:

```sql
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('SUCCESS', 'FAILED') DEFAULT 'SUCCESS',
    error_message TEXT,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
);
```

## Need Help?

For PHPMailer documentation, visit: https://github.com/PHPMailer/PHPMailer
