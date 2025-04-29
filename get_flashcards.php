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

// Lekérdezés
$sql = "SELECT flashcard_id AS Id, question AS Question, answer AS Answer FROM flashcards";
$result = $conn->query($sql);

$flashcards = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $flashcards[] = $row;
    }
}

echo json_encode($flashcards);

$conn->close();
?>
