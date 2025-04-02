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

// Check if required parameters are provided
if (!isset($_POST['set_id']) || !is_numeric($_POST['set_id']) || !isset($_POST['title']) || empty($_POST['title'])) {
    http_response_code(400);
    echo "Invalid request";
    exit;
}

$setId = $_POST['set_id'];
$newTitle = trim($_POST['title']);

// Validate title length
if (strlen($newTitle) > 50) {
    http_response_code(400);
    echo "Title too long (maximum 50 characters)";
    exit;
}

try {
    // First, verify the set belongs to the user
    $stmt = $pdo->prepare("SELECT set_id FROM sets WHERE set_id = ? AND user_id = ?");
    $stmt->execute([$setId, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        // Set doesn't exist or doesn't belong to this user
        http_response_code(403);
        echo "You don't have permission to edit this set";
        exit;
    }
    
    // Update the set title
    $stmt = $pdo->prepare("UPDATE sets SET title = ? WHERE set_id = ?");
    $stmt->execute([$newTitle, $setId]);
    
    echo "Set renamed successfully";
    
} catch (PDOException $e) {
    error_log("Error updating set title: " . $e->getMessage());
    http_response_code(500);
    echo "Database error";
}
?>