<?php
$conn = new mysqli("localhost", "root", "", "invoicing_system");

if ($conn->connect_error) {
    die(json_encode([
        "status" => "error",
        "message" => "DB Connection failed: " . $conn->connect_error
    ]));
}
?>
