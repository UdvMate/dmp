<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!isset($data['username']) || !isset($data['password'])) {
    echo json_encode(["success" => false, "error" => "Missing username or password"]);
    exit;
}

$username = $data['username'];
$password = $data['password'];

$conn = new mysqli("localhost", "root", "", "dmproject");
if ($conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Database connection failed"]);
    exit;
}

$stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $storedHash = $row['password'];
    $inputHash = base64_encode(hash('sha256', $password, true));

    if ($inputHash === $storedHash) {
        echo json_encode([
            "success" => true,
            "id" => $row['id'],
            "username" => $username
        ]);
    } else {
        echo json_encode(["success" => false, "error" => "Invalid credentials"]);
    }
} else {
    echo json_encode(["success" => false, "error" => "User not found"]);
}

$stmt->close();
$conn->close();
?>
