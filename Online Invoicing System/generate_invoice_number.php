<?php
session_start();
$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die("Connection failed");
}

$userId = $_SESSION['user_id'] ?? 1;

// Get last invoice number for this user
$stmt = $conn->prepare("SELECT invoice_number FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$nextNumber = 1;

if ($row = $result->fetch_assoc()) {
    $lastInvoice = $row['invoice_number'];

    // Extract numeric part
    if (preg_match('/INV(\d+)/', $lastInvoice, $matches)) {
        $nextNumber = intval($matches[1]) + 1;
    }
}

// Format with leading zero
$newInvoiceNumber = "INV" . str_pad($nextNumber, 2, "0", STR_PAD_LEFT);

echo $newInvoiceNumber;
