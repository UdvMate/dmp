<?php
header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "dmproject";

// Csatlakozás
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

// Ellenőrzés hogy van-e id paraméter
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing id parameter"]);
    exit;
}

$id = intval($_GET['id']);

// Törlés
$sql = "DELETE FROM flashcards WHERE flashcard_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to delete flashcard"]);
}

$stmt->close();
$conn->close();
?>
