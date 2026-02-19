<?php
header('Content-Type: application/json');
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Email and password required']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, firstName, lastName, password FROM users WHERE email = ? LIMIT 1"
);

$stmt->bind_param("s", $email);
$stmt->execute();

$stmt->bind_result($id, $firstName, $lastName, $hashedPassword);

if (!$stmt->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}

// Verify the password
// Handle both hashed passwords (from newer signups) and plain text passwords (from test data)
$passwordValid = false;

if (!empty($hashedPassword)) {
    // Check if it's a hashed password (starts with $) or plain text
    if (strpos($hashedPassword, '$') === 0) {
        $passwordValid = password_verify($password, $hashedPassword);
    } else {
        // Plain text password
        $passwordValid = ($password === $hashedPassword);
    }
}

if (!$passwordValid) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}

$_SESSION['user_id'] = $id;
$_SESSION['user_name'] = $firstName . ' ' . $lastName;
$_SESSION['logged_in'] = true;

echo json_encode(['status' => 'success']);

$stmt->close();
$conn->close();
