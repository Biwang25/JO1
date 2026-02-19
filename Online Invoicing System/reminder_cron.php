<?php
require 'openai_config.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';
require 'email_config.php';

use DateTime;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
/* ===============================
   AI MESSAGE GENERATOR FUNCTION
=================================*/
function generateAIReminder($clientName, $invoiceNumber, $amount, $minutesOverdue)
{
    if ($minutesOverdue <= 1) {
        $tone = "friendly and polite";
    } elseif ($minutesOverdue <= 3) {
        $tone = "professional and firm";
    } else {
        $tone = "urgent but respectful";
    }

    $prompt = "
Write a $tone invoice payment reminder email.

Client Name: $clientName
Invoice Number: $invoiceNumber
Amount Due: ₱$amount
Minutes Overdue: $minutesOverdue

Rules:
- Keep it professional
- Do not threaten
- Encourage immediate payment
- Keep it under 150 words
- End with: SmartInvoice Team
";

    $data = [
        "model" => "gpt-4.1-mini",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . OPENAI_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $result = json_decode($response, true);

    return $result['choices'][0]['message']['content'] ?? false;
}

/* ===============================
   DATABASE CONNECTION
=================================*/
$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die("Database connection failed");
}

/* ===============================
   GET INVOICES (Every 3 Minutes)
=================================*/
$query = "
SELECT * FROM invoices 
WHERE status = 'pending' 
AND (last_reminder_sent IS NULL 
     OR last_reminder_sent <= NOW() - INTERVAL 3 MINUTE)
";

$result = $conn->query($query);

if (!$result) {
    die("Query failed: " . $conn->error);
}

echo "Rows found: " . $result->num_rows . "<br>";

while ($invoice = $result->fetch_assoc()) {

    echo "Processing Invoice ID: " . $invoice['id'] . "<br>";

    $invoiceDate = new DateTime($invoice['invoice_date']);
    $now = new DateTime();

    $interval = $invoiceDate->diff($now);

    $minutesOverdue = ($interval->days * 24 * 60)
                    + ($interval->h * 60)
                    + $interval->i;

    echo "Minutes Overdue: " . $minutesOverdue . "<br>";

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
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($invoice['email'], $invoice['client_name']);

        $mail->isHTML(true);
        $mail->Subject = "Reminder: Invoice #" . $invoice['invoice_number'];
        $mail->Body = nl2br($aiMessage);
        $mail->AltBody = strip_tags($aiMessage);

        $mail->send();

        echo "Email Sent Successfully<br>";

        $update = $conn->prepare("UPDATE invoices SET last_reminder_sent = NOW() WHERE id = ?");
        $update->bind_param("i", $invoice['id']);
        $update->execute();
        $update->close();

    } catch (Exception $e) {
        echo "Mail Error: " . $mail->ErrorInfo . "<br>";
    }
}

$conn->close();

echo "Script Finished.";
?>
