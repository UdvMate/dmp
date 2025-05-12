<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['id'])) {
    echo json_encode(["error" => "Missing id"]);
    exit;
}

$user_id = $input['id'];

$conn = new mysqli("localhost", "root", "", "dmproject");
if ($conn->connect_error) {
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// Csak a saját set-eket és flashcardokat kérdezzük le
$sql = "
    SELECT flashcards.flashcard_id, flashcards.question, flashcards.answer, sets.title
    FROM flashcards
    JOIN sets ON flashcards.set_id = sets.set_id
    WHERE sets.user_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$cards = [];
while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
}

echo json_encode(["success" => true, "flashcards" => $cards]);

$stmt->close();
$conn->close();
?>
