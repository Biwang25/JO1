<?php

header('Content-Type: application/json');

session_start();

ini_set('display_errors', 1);

error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);

exit;

}

$email = trim($_POST['email'] ?? '');

$password = $_POST['password'] ?? '';

if (!$email || !$password) {

echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);

exit;

}

$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {

echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);

exit;

}

$stmt = $conn->prepare("

SELECT id, firstName, lastName, password, last_login

FROM users

WHERE email = ?

");

$stmt->bind_param("s", $email);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);

exit;

}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {

echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);

exit;

}

/* Session */

$_SESSION['user_id'] = $user['id'];

$_SESSION['user_name'] = $user['firstName'] . ' ' . $user['lastName'];

$_SESSION['logged_in'] = true;

echo json_encode(['status' => 'success']);

$stmt->close();

$conn->close();