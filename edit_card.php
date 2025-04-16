<?php
// Start session if not already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php'; // Include your database configuration

header('Content-Type: application/json'); // Set response header to JSON

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    http_response_code(401); // Unauthorized
    exit();
}

// Check if required data is received via POST
if (!isset($_POST['card_id']) || !isset($_POST['question']) || !isset($_POST['answer'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required card data.']);
    http_response_code(400); // Bad Request
    exit();
}

$cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
$question = trim($_POST['question']);
$answer = trim($_POST['answer']);
$userId = $_SESSION['user_id'];

// Basic validation
if (!$cardId || empty($question) || empty($answer)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided (ID must be numeric, question/answer cannot be empty).']);
    http_response_code(400); // Bad Request
    exit();
}

try {
    // Prepare the update statement
    // Ensure the card belongs to a set owned by the current user
    $sql = "UPDATE flashcards f
            JOIN sets s ON f.set_id = s.set_id
            SET f.question = ?, f.answer = ?
            WHERE f.flashcard_id = ? AND s.user_id = ?";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$question, $answer, $cardId, $userId]);

    if ($success && $stmt->rowCount() > 0) {
        // Update successful and at least one row was affected
        echo json_encode(['success' => true, 'message' => 'Flashcard updated successfully.']);
        http_response_code(200); // OK

        // Optional: Update the session data if you rely on it heavily elsewhere
        // Find the set_id first
        $stmtSet = $pdo->prepare("SELECT set_id FROM flashcards WHERE flashcard_id = ?");
        $stmtSet->execute([$cardId]);
        $cardDetails = $stmtSet->fetch(PDO::FETCH_ASSOC);

        if ($cardDetails && isset($_SESSION['current_flashcards']) && $_SESSION['current_set']['set_id'] == $cardDetails['set_id']) {
             foreach ($_SESSION['current_flashcards'] as $key => $card) {
                 if ($card['flashcard_id'] == $cardId) {
                     $_SESSION['current_flashcards'][$key]['question'] = $question;
                     $_SESSION['current_flashcards'][$key]['answer'] = $answer;
                     break;
                 }
             }
        }

    } elseif ($success && $stmt->rowCount() === 0) {
        // Query executed successfully, but no rows were affected.
        // This likely means the card_id didn't exist OR it didn't belong to the user.
        echo json_encode(['success' => false, 'message' => 'Card not found or you do not have permission to edit it.']);
        http_response_code(404); // Not Found or Forbidden
    } else {
        // The execute() call failed
        echo json_encode(['success' => false, 'message' => 'Database update failed.']);
        http_response_code(500); // Internal Server Error
    }

} catch (PDOException $e) {
    error_log("Error updating flashcard: " . $e->getMessage()); // Log the detailed error
    echo json_encode(['success' => false, 'message' => 'An error occurred while updating the flashcard.']);
    http_response_code(500); // Internal Server Error
}

exit(); // Terminate script execution
?>
