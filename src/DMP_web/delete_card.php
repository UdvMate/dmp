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
if (!isset($_POST['card_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing card ID.']);
    http_response_code(400); // Bad Request
    exit();
}

$cardId = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
$userId = $_SESSION['user_id'];

// Basic validation
if (!$cardId) {
    echo json_encode(['success' => false, 'message' => 'Invalid card ID provided.']);
    http_response_code(400); // Bad Request
    exit();
}

try {
    // First, get the set_id of the card to be deleted (we'll need this for session updates)
    $stmtGetSetId = $pdo->prepare("SELECT set_id FROM flashcards WHERE flashcard_id = ?");
    $stmtGetSetId->execute([$cardId]);
    $cardDetails = $stmtGetSetId->fetch(PDO::FETCH_ASSOC);
    
    if (!$cardDetails) {
        echo json_encode(['success' => false, 'message' => 'Card not found.']);
        http_response_code(404); // Not Found
        exit();
    }
    
    $setId = $cardDetails['set_id'];
    
    // Prepare the delete statement
    // Ensure the card belongs to a set owned by the current user
    $sql = "DELETE f FROM flashcards f
            JOIN sets s ON f.set_id = s.set_id
            WHERE f.flashcard_id = ? AND s.user_id = ?";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$cardId, $userId]);

    if ($success && $stmt->rowCount() > 0) {
        // Delete successful and at least one row was affected
        echo json_encode(['success' => true, 'message' => 'Flashcard deleted successfully.']);
        http_response_code(200); // OK

        // Update the session data if you rely on it
        if (isset($_SESSION['current_flashcards']) && $_SESSION['current_set']['set_id'] == $setId) {
            // Filter out the deleted card from the session array
            $_SESSION['current_flashcards'] = array_filter($_SESSION['current_flashcards'], function($card) use ($cardId) {
                return $card['flashcard_id'] != $cardId;
            });
            
            // Re-index the array to ensure sequential keys
            $_SESSION['current_flashcards'] = array_values($_SESSION['current_flashcards']);
        }

    } elseif ($success && $stmt->rowCount() === 0) {
        // Query executed successfully, but no rows were affected.
        // This likely means the card_id didn't exist OR it didn't belong to the user.
        echo json_encode(['success' => false, 'message' => 'Card not found or you do not have permission to delete it.']);
        http_response_code(404); // Not Found or Forbidden
    } else {
        // The execute() call failed
        echo json_encode(['success' => false, 'message' => 'Database delete failed.']);
        http_response_code(500); // Internal Server Error
    }

} catch (PDOException $e) {
    error_log("Error deleting flashcard: " . $e->getMessage()); // Log the detailed error
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the flashcard.']);
    http_response_code(500); // Internal Server Error
}

exit(); // Terminate script execution
?>
