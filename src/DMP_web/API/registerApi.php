<?php
header("Content-Type: application/json");

$host = "localhost";
$dbname = "dmproject";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["Error:Database connection failed: " . $e->getMessage()]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data["username"]);
$email = trim($data["email"]);
$password = trim($data["password"]);


if (!isset($data["username"], $data["email"], $data["password"])) {
    echo json_encode(["Error:Missing required fields."]);
    exit();
}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["Error:Invalid email format."]);
    exit();
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
$stmt->execute(["username" => $username, "email" => $email]);
if ($stmt->fetchColumn() > 0) {
    echo json_encode(["Error:Username or email already exists."]);
    exit();
}

// Jelszó hash
$hashedPassword = base64_encode(hash('sha256', $password, true));

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, created_at) VALUES (:username, :email, :password, NOW())");
    $stmt->execute([
        "username" => $username,
        "email" => $email,
        "password" => $hashedPassword
    ]);

    echo json_encode(["success" => true]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "error" => "Registration failed: " . $e->getMessage()]);
}
?>
