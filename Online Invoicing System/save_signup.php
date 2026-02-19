<?php
// Must be at the very top
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Required PHPMailer files
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';
require 'email_config.php'; // Your SMTP settings: SMTP_HOST, SMTP_USERNAME, etc.

// Set JSON response and CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (empty($data['email']) || empty($data['firstName']) || empty($data['lastName']) || empty($data['password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate email
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'invoicing_system');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Check if email exists
$stmt = $conn->prepare("SELECT email FROM users WHERE email = ?");
$stmt->bind_param("s", $data['email']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// Hash password
$hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);

// Handle optional fields safely
$company = $data['company'] ?? '';
$industry = $data['industry'] ?? '';
$newsletter = isset($data['newsletter']) ? (int)$data['newsletter'] : 0;

// Insert user into DB
$insert = $conn->prepare("INSERT INTO users (firstName, lastName, company, email, password, industry, newsletter, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$insert->bind_param(
    "ssssssi",
    $data['firstName'],
    $data['lastName'],
    $company,
    $data['email'],
    $hashed_password,
    $industry,
    $newsletter
);

if ($insert->execute()) {
    $userId = $insert->insert_id;

    // Send confirmation email
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($data['email'], $data['firstName'] . ' ' . $data['lastName']);
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to InvoiceHub';
        $mail->Body = "<h2>Hi {$data['firstName']},</h2>
                       <p>Thank you for signing up! Your account has been created successfully.</p>
                       <p>Happy invoicing!</p>
                       <p>— InvoiceHub Team</p>";
        $mail->AltBody = "Hi {$data['firstName']},\n\nYour account has been created successfully.\n\n— InvoiceHub Team";
        $mail->send();
    } catch (Exception $e) {
        // Log email errors but still return success
        error_log('Email error: ' . $mail->ErrorInfo);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'user_id' => $userId
    ]);
} else {
    // Log and return insert error
    error_log('Insert Error: ' . $insert->error);
    echo json_encode(['success' => false, 'message' => 'Error creating account: ' . $insert->error]);
}

$insert->close();
$conn->close();
?>
