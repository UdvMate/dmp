<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

// Check if set_id is provided
if (!isset($_POST['set_id']) || !is_numeric($_POST['set_id'])) {
    http_response_code(400);
    echo "Invalid request";
    exit;
}

$setId = $_POST['set_id'];

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // First, verify the set belongs to the user
    $stmt = $pdo->prepare("SELECT set_id FROM sets WHERE set_id = ? AND user_id = ?");
    $stmt->execute([$setId, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        // Set doesn't exist or doesn't belong to this user
        $pdo->rollBack();
        http_response_code(403);
        echo "You don't have permission to delete this set";
        exit;
    }
    
    // Delete flashcards first (due to foreign key constraints)
    $stmt = $pdo->prepare("DELETE FROM flashcards WHERE set_id = ?");
    $stmt->execute([$setId]);
    
    // Then delete the set
    $stmt = $pdo->prepare("DELETE FROM sets WHERE set_id = ?");
    $stmt->execute([$setId]);
    
    // Commit transaction
    $pdo->commit();
    
    echo "Set deleted successfully";
    
} catch (PDOException $e) {
    // Roll back transaction on error
    $pdo->rollBack();
    error_log("Error deleting set: " . $e->getMessage());
    http_response_code(500);
    echo "Database error";
}
?>