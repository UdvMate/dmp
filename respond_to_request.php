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
    echo json_encode(['success' => false, 'message' => 'Please log in to respond to friend requests']);
    exit;
}

// Get request ID and action from POST data
$requestId = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($requestId <= 0 || !in_array($action, ['accept', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

try {
    // Verify that the request exists and is for the current user
    $stmt = $pdo->prepare("SELECT * FROM friendships WHERE id = ? AND friend_id = ? AND status = 'pending'");
    $stmt->execute([$requestId, $_SESSION['user_id']]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Friend request not found or already processed']);
        exit;
    }
    
    if ($action === 'accept') {
        // Update the friendship status to accepted
        $stmt = $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$requestId]);
        
        echo json_encode(['success' => true, 'message' => 'Friend request accepted']);
    } else {
        // Delete the friendship record
        $stmt = $pdo->prepare("DELETE FROM friendships WHERE id = ?");
        $stmt->execute([$requestId]);
        
        echo json_encode(['success' => true, 'message' => 'Friend request rejected']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

