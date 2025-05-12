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
    echo json_encode(['success' => false, 'message' => 'Please log in to view friend requests']);
    exit;
}

try {
    // Get pending friend requests where the current user is the friend_id
    $stmt = $pdo->prepare("
        SELECT f.id, f.user_id, u.username, u.profile_picture_url, f.created_at
        FROM friendships f
        JOIN users u ON f.user_id = u.id
        WHERE f.friend_id = ? AND f.status = 'pending'
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedRequests = [];
    
    foreach ($requests as $request) {
        // Calculate time ago
        $created = new DateTime($request['created_at']);
        $now = new DateTime();
        $interval = $created->diff($now);
        
        if ($interval->y > 0) {
            $time_ago = $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        } elseif ($interval->m > 0) {
            $time_ago = $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        } elseif ($interval->d > 0) {
            $time_ago = $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        } elseif ($interval->h > 0) {
            $time_ago = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i > 0) {
            $time_ago = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            $time_ago = 'just now';
        }
        
        $formattedRequests[] = [
            'id' => $request['id'],
            'user_id' => $request['user_id'],
            'username' => $request['username'],
            'profile_picture_url' => $request['profile_picture_url'],
            'time_ago' => $time_ago
        ];
    }
    
    echo json_encode(['success' => true, 'requests' => $formattedRequests]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
