<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Create a log file for debugging
$logFile = fopen("friend_request_log.txt", "a");
fwrite($logFile, "Request received at " . date('Y-m-d H:i:s') . "\n");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    fwrite($logFile, "Error: User not logged in\n");
    echo json_encode(['success' => false, 'message' => 'Please log in to send friend requests']);
    fclose($logFile);
    exit;
}

// Get username from POST data
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
fwrite($logFile, "Username received: " . $username . "\n");

if (empty($username)) {
    fwrite($logFile, "Error: Empty username\n");
    echo json_encode(['success' => false, 'message' => 'No username provided']);
    fclose($logFile);
    exit;
}

try {
    // Get user ID from username
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        fwrite($logFile, "Error: User not found\n");
        echo json_encode(['success' => false, 'message' => 'User not found']);
        fclose($logFile);
        exit;
    }
    
    $friendId = $user['id'];
    fwrite($logFile, "Friend ID: " . $friendId . "\n");
    
    // Check if this is the same user
    if ($friendId == $_SESSION['user_id']) {
        fwrite($logFile, "Error: Cannot send request to self\n");
        echo json_encode(['success' => false, 'message' => 'You cannot send a friend request to yourself']);
        fclose($logFile);
        exit;
    }
    
    // Check if a friendship already exists
    $stmt = $pdo->prepare("
        SELECT * FROM friendships 
        WHERE (user_id = ? AND friend_id = ?) 
        OR (user_id = ? AND friend_id = ?)
    ");
    $stmt->execute([$_SESSION['user_id'], $friendId, $friendId, $_SESSION['user_id']]);
    $existingFriendship = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingFriendship) {
        fwrite($logFile, "Error: Friendship already exists with status: " . $existingFriendship['status'] . "\n");
        if ($existingFriendship['status'] == 'accepted') {
            echo json_encode(['success' => false, 'message' => 'You are already friends with this user']);
        } else if ($existingFriendship['status'] == 'pending') {
            if ($existingFriendship['user_id'] == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'You already sent a friend request to this user']);
            } else {
                echo json_encode(['success' => false, 'message' => 'This user already sent you a friend request']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'A friendship record already exists']);
        }
        fclose($logFile);
        exit;
    }
    
    // Create a new friendship record
    $stmt = $pdo->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$_SESSION['user_id'], $friendId]);
    
    fwrite($logFile, "Success: Friend request sent from user " . $_SESSION['user_id'] . " to user " . $friendId . "\n");
    echo json_encode(['success' => true, 'message' => 'Friend request sent successfully']);
    
} catch (PDOException $e) {
    fwrite($logFile, "Database error: " . $e->getMessage() . "\n");
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

fclose($logFile);
?>
