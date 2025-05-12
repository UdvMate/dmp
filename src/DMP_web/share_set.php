<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to share sets']);
    exit;
}

// Validate input
if (!isset($_POST['set_id']) || !isset($_POST['friend_ids']) || empty($_POST['set_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$setId = intval($_POST['set_id']);
$friendIds = $_POST['friend_ids'];
$userId = $_SESSION['user_id'];

// Verify the set belongs to the user
try {
    $stmt = $pdo->prepare("SELECT * FROM sets WHERE set_id = ? AND user_id = ?");
    $stmt->execute([$setId, $userId]);
    $set = $stmt->fetch();
    
    if (!$set) {
        echo json_encode(['success' => false, 'message' => 'You can only share sets that belong to you']);
        exit;
    }
    
    // Process each friend ID
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($friendIds as $friendId) {
        $friendId = intval($friendId);
        
        // Verify this is actually a friend
        $stmt = $pdo->prepare("SELECT * FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
        $stmt->execute([$userId, $friendId, $friendId, $userId]);
        $friendship = $stmt->fetch();
        
        if (!$friendship) {
            $errorCount++;
            continue; // Skip if not a friend
        }
        
        // Check if set is already shared with this friend
        $stmt = $pdo->prepare("SELECT * FROM shared_sets WHERE set_id = ? AND user_id = ?");
        $stmt->execute([$setId, $friendId]);
        $alreadyShared = $stmt->fetch();
        
        if ($alreadyShared) {
            // Already shared, count as success
            $successCount++;
            continue;
        }
        
        // Share the set
        try {
            $stmt = $pdo->prepare("INSERT INTO shared_sets (set_id, owner_id, user_id, shared_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$setId, $userId, $friendId]);
            $successCount++;
        } catch (PDOException $e) {
            $errorCount++;
        }
    }
    
    echo json_encode([
        'success' => true, 
        'message' => "Set shared successfully with $successCount friends" . ($errorCount > 0 ? " ($errorCount failed)" : "")
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error sharing set: ' . $e->getMessage()]);
}
?>
