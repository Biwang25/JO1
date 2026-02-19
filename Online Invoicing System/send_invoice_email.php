<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access']));
}

require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';
require 'email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

$invoiceId = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
$userId = $_SESSION['user_id'];

// Fetch invoice details
$stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $invoiceId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$invoice = $result->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die(json_encode(['status' => 'error', 'message' => 'Invoice not found']));
}

// Send email using PHPMailer
function sendInvoiceEmail($invoiceData) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($invoiceData['email'], $invoiceData['client_name']);

        $mail->isHTML(true);
        $mail->Subject = 'Invoice #' . $invoiceData['invoice_number'];

        // Payment section for pending invoices
        $paymentSection = '';
        if (strtolower($invoiceData['status']) === 'pending') {
            $paymentSection = '
            <div class="payment-options" style="margin-top: 30px;">
                <h3 style="color: #4f46e5;">Select Payment Method</h3>
                <p>Please scan the QR code or use the details below to pay your invoice:</p>

                <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1; min-width: 120px; text-align: center;">
                        <p>GCash</p>
                        <img src="gcash.png" alt="GCash QR" style="width: 120px; height: 120px;"/>
                    </div>
                    <div style="flex: 1; min-width: 120px; text-align: center;">
                        <p>PayMaya</p>
                        <img src="https://example.com/qrcodes/paymaya.png" alt="PayMaya QR" style="width: 120px; height: 120px;"/>
                    </div>
                    <div style="flex: 1; min-width: 120px; text-align: center;">
                        <p>Bank Transfer</p>
                        <img src="https://example.com/qrcodes/bank.png" alt="Bank QR" style="width: 120px; height: 120px;"/>
                        <p>Account: 123-456-789</p>
                    </div>
                    <div style="flex: 1; min-width: 120px; text-align: center;">
                        <p>PayPal</p>
                        <img src="https://example.com/qrcodes/paypal.png" alt="PayPal QR" style="width: 120px; height: 120px;"/>
                        <p>paypal@yourbusiness.com</p>
                    </div>
                </div>
            </div>';
        }

        $htmlBody = '
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            background-color: #f4f5f7;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .invoice-container {
            max-width: 650px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-top: 6px solid #4f46e5;
        }
        .header {
            background-color: #4f46e5;
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 32px;
            letter-spacing: 1px;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .invoice-details {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            background-color: #f9fafb;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: 600;
            color: #6b7280;
        }
        .amount-section {
            background-color: #f3f4f6;
            border-radius: 8px;
            padding: 25px;
            text-align: right;
            font-size: 18px;
        }
        .total-amount {
            font-size: 28px;
            font-weight: bold;
            color: #4f46e5;
            margin-top: 5px;
        }
        .thank-you {
            color: #4f46e5;
            font-weight: 600;
            margin: 25px 0;
            font-size: 16px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            background-color: #f9fafb;
            font-size: 12px;
            color: #9ca3af;
        }
        @media screen and (max-width: 600px) {
            .invoice-container { width: 95% !important; }
            .amount-section { font-size: 16px; }
            .total-amount { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <h1>Invoice</h1>
        </div>
        <div class="content">
            <p class="greeting">Hello ' . htmlspecialchars($invoiceData['client_name']) . ',</p>
            <p>We appreciate your business! Below are the details of your invoice:</p>

            <div class="invoice-details">
                <div class="detail-row">
                    <span class="label">Invoice Number:</span>
                    <span>' . htmlspecialchars($invoiceData['invoice_number']) . '</span>
                </div>
                <div class="detail-row">
                    <span class="label">Invoice Date:</span>
                    <span>' . htmlspecialchars($invoiceData['invoice_date']) . '</span>
                </div>
                <div class="detail-row">
                    <span class="label">Status:</span>
                    <span>' . htmlspecialchars($invoiceData['status']) . '</span>
                </div>
                <div class="detail-row">
                    <span class="label">Due Amount:</span>
                    <span>₱' . number_format($invoiceData['amount'], 2) . '</span>
                </div>
            </div>

            <div class="amount-section">
                Total Due
                <div class="total-amount">₱' . number_format($invoiceData['amount'], 2) . '</div>
            </div>

            ' . $paymentSection . '

            <p class="thank-you">Thank you for choosing SmartInvoice! If you have any questions, feel free to contact us.</p>

            <p>Best regards,<br>SmartInvoice Team</p>
        </div>
        <div class="footer">
            <p>This is an automated email. Please do not reply directly.</p>
        </div>
    </div>
</body>
</html>';

        $mail->Body = $htmlBody;
        $mail->AltBody = 'Invoice #' . $invoiceData['invoice_number'] . 
                        '\nDate: ' . $invoiceData['invoice_date'] . 
                        '\nAmount: ₱' . number_format($invoiceData['amount'], 2);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Email sending error: " . $mail->ErrorInfo);
        return false;
    }
}

if (sendInvoiceEmail($invoice)) {
    echo json_encode(['status' => 'success', 'message' => 'Invoice sent successfully to ' . $invoice['email']]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to send invoice. Check email_config.php settings.']);
}

$conn->close();
?>
