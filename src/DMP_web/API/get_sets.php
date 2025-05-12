<?php
header("Content-Type: application/json");
$input = json_decode(file_get_contents('php://input'), true);

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

$sql = "SELECT set_id, title, generated_at FROM sets WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$sets = [];
while ($row = $result->fetch_assoc()) {
    $sets[] = [
        'SetId' => $row['set_id'],
        'Title' => $row['title'],
        'GeneratedAt' => $row['generated_at']
    ];
}

echo json_encode(["success" => true, "sets" => $sets]);
$stmt->close();
$conn->close();
?>
