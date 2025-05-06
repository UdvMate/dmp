<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to view friends']);
    exit;
}

$userId = $_SESSION['user_id'];
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

try {
    // Get all friends of the current user with optional search filter
    if (!empty($searchTerm)) {
        // Search by username
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.profile_picture_url 
            FROM users u
            JOIN friendships f ON (f.friend_id = u.id AND f.user_id = ?) OR (f.user_id = u.id AND f.friend_id = ?)
            WHERE u.username LIKE ?
            ORDER BY u.username
        ");
        $stmt->execute([$userId, $userId, "%$searchTerm%"]);
    } else {
        // Get all friends
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.profile_picture_url 
            FROM users u
            JOIN friendships f ON (f.friend_id = u.id AND f.user_id = ?) OR (f.user_id = u.id AND f.friend_id = ?)
            ORDER BY u.username
        ");
        $stmt->execute([$userId, $userId]);
    }
    
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format profile picture URLs
    foreach ($friends as &$friend) {
        $friend['profile_picture_url'] = !empty($friend['profile_picture_url']) ? 
            htmlspecialchars($friend['profile_picture_url']) : 
            'media/images/pfp.png';
    }
    
    echo json_encode(['success' => true, 'friends' => $friends]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching friends: ' . $e->getMessage()]);
}
?>
