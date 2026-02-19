<?php
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';
require 'email_config.php';
require 'ai_message_generator.php';

use DateTime;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die("Database connection failed");
}

// ✅ Send reminder every 3 MINUTES (not 1 hour)
$query = "
SELECT * FROM invoices 
WHERE status = 'pending' 
AND (last_reminder_sent IS NULL 
     OR last_reminder_sent <= NOW() - INTERVAL 3 MINUTE)
";

$result = $conn->query($query);

while ($invoice = $result->fetch_assoc()) {

    // ✅ Calculate TOTAL minutes overdue (clean method)
    $invoiceDate = new DateTime($invoice['invoice_date']);
    $now = new DateTime();

    $interval = $invoiceDate->diff($now);

    $minutesOverdue = ($interval->days * 24 * 60)
                    + ($interval->h * 60)
                    + $interval->i;

    // Generate AI message
    $aiMessage = generateAIReminder(
        $invoice['client_name'],
        $invoice['invoice_number'],
        number_format($invoice['amount'], 2),
        $minutesOverdue
    );

    if (!$aiMessage) {
        $aiMessage = "Reminder: Invoice #{$invoice['invoice_number']} is still pending.";
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($invoice['email'], $invoice['client_name']);

        $mail->isHTML(true);
        $mail->Subject = "Reminder: Invoice #" . $invoice['invoice_number'];

        $mail->Body = nl2br($aiMessage);
        $mail->AltBody = strip_tags($aiMessage);

        $mail->send();

        // ✅ Update reminder timestamp
        $update = $conn->prepare("UPDATE invoices SET last_reminder_sent = NOW() WHERE id = ?");
        $update->bind_param("i", $invoice['id']);
        $update->execute();
        $update->close();

    } catch (Exception $e) {
        error_log("Reminder email error: " . $mail->ErrorInfo);
    }
}

$conn->close();
?>
