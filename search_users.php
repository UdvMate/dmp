<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Validate search query
if (strlen($query) < 2) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

try {
    // Search for users matching the query, excluding the current user
    $stmt = $pdo->prepare("SELECT id, username, profile_picture_url FROM users 
                           WHERE username LIKE ? AND id != ? 
                           LIMIT 10");
    $stmt->execute(['%' . $query . '%', $_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check for existing friend relationships or pending requests
    foreach ($users as &$user) {
        // Check if they are already friends
        $stmt = $pdo->prepare("SELECT status FROM friendships 
                              WHERE (user_id = ? AND friend_id = ?) 
                              OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$_SESSION['user_id'], $user['id'], $user['id'], $_SESSION['user_id']]);
        $friendship = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($friendship) {
            $user['friendship_status'] = $friendship['status'];
        } else {
            $user['friendship_status'] = null;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($users);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
