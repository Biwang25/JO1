<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: signin.html");
    exit;
}

$userId = $_SESSION['user_id'];

/* DATABASE CONNECTION */
$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die("Database connection failed");
}

$invoiceId = $_GET['invoice_id'];

// Fetch the invoice details
$stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $invoiceId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$invoice = $result->fetch_assoc();

$stmt->close();

if (!$invoice) {
    die("Invoice not found.");
}

// Email sending function
function sendInvoiceEmail($to, $subject, $message) {
    $headers = "From: jonatanumpingbiwang@gmail.com\r\n"; // Replace with your email

    if(mail($to, $subject, $message, $headers)) {
        return true;
    } else {
        return false;
    }
}

// Handle the sending of the email when the button is clicked
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sendInvoice'])) {
    $clientEmail = $invoice['email'];
    $subject = "Invoice #" . $invoice['invoice_number'];
    $message = "Dear " . $invoice['client_name'] . ",\n\n";
    $message .= "Please find your invoice below:\n";
    $message .= "Invoice Number: " . $invoice['invoice_number'] . "\n";
    $message .= "Date: " . $invoice['invoice_date'] . "\n";
    $message .= "Total Amount: ₱" . number_format($invoice['amount'], 2) . "\n\n";
    $message .= "Thank you!";
    
    if (sendInvoiceEmail($clientEmail, $subject, $message)) {
        echo "<script>alert('Invoice sent successfully!');</script>";
    } else {
        echo "<script>alert('Failed to send invoice.');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Print Invoice</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 20px; }
        .invoice { padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        h1 { font-size: 24px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f0f0f0; }
        .total { font-weight: bold; font-size: 18px; }
        .button { background: orange; color: white; border: none; padding: 10px; cursor: pointer; }
    </style>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</head>
<body>

<div class="invoice">
    <h1>Invoice Details</h1>
    <p><strong>Client Name:</strong> <?php echo htmlspecialchars($invoice['client_name']); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($invoice['email']); ?></p>
    <p><strong>Invoice Number:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
    <p><strong>Date:</strong> <?php echo htmlspecialchars($invoice['invoice_date']); ?></p>
    <p><strong>Status:</strong> <?php echo htmlspecialchars($invoice['status']); ?></p>
    
    <table>
        <tbody>
            <tr>
                <td><strong>Amount</strong></td>
                <td>₱<?php echo number_format($invoice['amount'], 2); ?></td>
            </tr>
        </tbody>
    </table>

    <p class="total">Total Amount: ₱<?php echo number_format($invoice['amount'], 2); ?></p>

    <form method="POST">
        <button type="submit" name="sendInvoice" class="button">Send Invoice</button>
    </form>
</div>

</body>
</html>