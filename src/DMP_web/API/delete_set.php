<?php
header("Content-Type: application/json");
$setId = $_GET['id'] ?? null;

if (!$setId) {
    echo json_encode(["error" => "Missing set ID"]);
    exit;
}

$conn = new mysqli("localhost", "root", "", "dmproject");

try {
    $conn->begin_transaction();
    
    // Flashcard-ok törlése
    $stmt1 = $conn->prepare("DELETE FROM flashcards WHERE set_id = ?");
    $stmt1->bind_param("i", $setId);
    $stmt1->execute();
    
    // Szett törlése
    $stmt2 = $conn->prepare("DELETE FROM sets WHERE set_id = ?");
    $stmt2->bind_param("i", $setId);
    $stmt2->execute();
    
    $conn->commit();
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["error" => "Transaction failed: " . $e->getMessage()]);
}

$conn->close();
?>
