<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to remove friends']);
    exit;
}

// Get friend ID from POST data
$friendId = isset($_POST['friend_id']) ? intval($_POST['friend_id']) : 0;

if ($friendId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid friend ID']);
    exit;
}

try {
    // Delete the friendship record
    $stmt = $pdo->prepare("
        DELETE FROM friendships 
        WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) 
        AND status = 'accepted'
    ");
    $stmt->execute([$_SESSION['user_id'], $friendId, $friendId, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Friend removed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Friendship not found or already removed']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
