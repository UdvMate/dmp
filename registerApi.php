<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Decode JSON payload
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Validate input
if (!isset($data['username']) || !isset($data['password'])) {
    echo json_encode(["success" => false, "error" => "Missing username or password"]);
    exit;
}

$username = $data['username'];
$password = $data['password'];

// Hash the password securely
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// DB connection
$conn = new mysqli("localhost", "root", "", "dmproject");
if ($conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Database connection failed"]);
    exit;
}

// Check if username already exists
$checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$checkStmt->bind_param("s", $username);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    echo json_encode(["success" => false, "error" => "Username already taken"]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// Insert new user
$stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
$stmt->bind_param("ss", $username, $hashedPassword);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "error" => "Registration failed"]);
}

$stmt->close();
$conn->close();
?>
