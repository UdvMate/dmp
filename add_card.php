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
if (!isset($_POST['set_id']) || !isset($_POST['question']) || !isset($_POST['answer'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required card data.']);
    http_response_code(400); // Bad Request
    exit();
}

$setId = filter_input(INPUT_POST, 'set_id', FILTER_VALIDATE_INT);
$question = trim($_POST['question']);
$answer = trim($_POST['answer']);
$userId = $_SESSION['user_id'];

// Basic validation
if (!$setId || empty($question) || empty($answer)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided (set ID must be numeric, question/answer cannot be empty).']);
    http_response_code(400); // Bad Request
    exit();
}

try {
    // First verify that the set belongs to the current user
    $stmtVerify = $pdo->prepare("SELECT set_id FROM sets WHERE set_id = ? AND user_id = ?");
    $stmtVerify->execute([$setId, $userId]);
    $setExists = $stmtVerify->fetch(PDO::FETCH_ASSOC);
    
    if (!$setExists) {
        echo json_encode(['success' => false, 'message' => 'Set not found or you do not have permission to add cards to it.']);
        http_response_code(403); // Forbidden
        exit();
    }
    
    // Insert the new flashcard
    $sql = "INSERT INTO flashcards (set_id, question, answer) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([$setId, $question, $answer]);

    if ($success) {
        // Get the ID of the newly inserted card
        $newCardId = $pdo->lastInsertId();
        
        // Return success response with the new card data
        $response = [
            'success' => true, 
            'message' => 'Flashcard added successfully.',
            'card' => [
                'flashcard_id' => $newCardId,
                'set_id' => $setId,
                'question' => $question,
                'answer' => $answer
            ]
        ];
        
        echo json_encode($response);
        http_response_code(201); // Created
        
        // Update the session data if needed
        if (isset($_SESSION['current_flashcards']) && $_SESSION['current_set']['set_id'] == $setId) {
            // Add the new card to the session array
            $_SESSION['current_flashcards'][] = [
                'flashcard_id' => $newCardId,
                'set_id' => $setId,
                'question' => $question,
                'answer' => $answer
            ];
        }
    } else {
        // The execute() call failed
        echo json_encode(['success' => false, 'message' => 'Database insert failed.']);
        http_response_code(500); // Internal Server Error
    }

} catch (PDOException $e) {
    error_log("Error adding flashcard: " . $e->getMessage()); // Log the detailed error
    echo json_encode(['success' => false, 'message' => 'An error occurred while adding the flashcard.']);
    http_response_code(500); // Internal Server Error
}

exit(); // Terminate script execution
?>
