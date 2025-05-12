<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: welcome.php");
    exit();
}

// Check if file was uploaded
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['profile_picture'];
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        $_SESSION['error'] = "Only JPG, PNG, GIF, and WEBP files are allowed.";
        header("Location: welcome.php");
        exit();
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        $_SESSION['error'] = "File size should be less than 5MB.";
        header("Location: welcome.php");
        exit();
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = 'uploads/profile_pictures/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $fileName = $_SESSION['user_id'] . '_' . time() . '_' . basename($file['name']);
    $targetFilePath = $uploadDir . $fileName;
    
    // Upload file
    if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
        try {
            // Update profile_picture_url in database
            $stmt = $pdo->prepare("UPDATE users SET profile_picture_url = ? WHERE id = ?");
            $stmt->execute([$targetFilePath, $_SESSION['user_id']]);
            
            $_SESSION['success'] = "Profile picture updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Failed to upload file.";
    }
} else {
    $_SESSION['error'] = "No file uploaded or upload error occurred.";
}

header("Location: welcome.php");
exit();
