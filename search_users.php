<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="no-results">Please log in to search for users</div>';
    exit;
}

// Changed validation to accept single character searches
if (strlen($query) < 1) {
    echo '<div class="no-results">Type at least 1 character to search</div>';
    exit;
}

try {
    // Search for users whose username starts with the query
    $stmt = $pdo->prepare("SELECT id, username, profile_picture_url FROM users WHERE username LIKE ? AND id != ?");
    $stmt->execute([$query . '%', $_SESSION['user_id']]);  // Exclude current user from results
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($users) > 0) {
        // Output HTML directly
        foreach ($users as $user) {
            // Check if user has a profile picture, if not use the default
            $profilePic = (!empty($user['profile_picture_url'])) ? 
                          htmlspecialchars($user['profile_picture_url']) : 
                          'media/images/pfp.png';
            
            // Check friendship status
            $friendStatus = checkFriendshipStatus($_SESSION['user_id'], $user['id'], $pdo);
            
            echo '<div class="user-item" data-user-id="' . $user['id'] . '">';
            echo '<img src="' . $profilePic . '" alt="' . htmlspecialchars($user['username']) . '" class="user-avatar">';
            echo '<span class="user-name">' . htmlspecialchars($user['username']) . '</span>';
            
            // Show appropriate button based on friendship status
            if ($friendStatus == 'none') {
                echo '<button class="add-friend-btn" data-username="' . htmlspecialchars($user['username']) . '">Add Friend</button>';
            } else if ($friendStatus == 'pending_sent') {
                echo '<button class="add-friend-btn pending-btn" disabled>Request Sent</button>';
            } else if ($friendStatus == 'pending_received') {
                echo '<button class="add-friend-btn pending-btn" disabled>Respond in Requests</button>';
            } else if ($friendStatus == 'friends') {
                echo '<button class="add-friend-btn friends-btn" disabled>Friends</button>';
            }
            
            echo '</div>';
        }
    } else {
        echo '<div class="no-results">No users found</div>';
    }
} catch (PDOException $e) {
    echo '<div class="no-results">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Function to check friendship status between two users
function checkFriendshipStatus($userId, $otherUserId, $pdo) {
    // Check if they are already friends or have pending requests
    $stmt = $pdo->prepare("
        SELECT * FROM friendships 
        WHERE (user_id = ? AND friend_id = ?) 
        OR (user_id = ? AND friend_id = ?)
    ");
    $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
    $friendship = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$friendship) {
        return 'none'; // No friendship record exists
    }
    
    if ($friendship['status'] == 'accepted') {
        return 'friends';
    }
    
    if ($friendship['status'] == 'pending') {
        // Check if current user sent the request
        if ($friendship['user_id'] == $userId) {
            return 'pending_sent';
        } else {
            return 'pending_received';
        }
    }
    
    return 'none'; // Default fallback
}
?>
