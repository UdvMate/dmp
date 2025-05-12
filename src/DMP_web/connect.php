<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';


// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: welcome.php");
    exit();
}

// Login logic
if (isset($_POST['login_submit'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        // Get stored hash from database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // Hash input password using SHA-256 + Base64
            $hashedInput = base64_encode(hash('sha256', $password, true));
            
            // Compare hashes
            if (hash_equals($user['password'], $hashedInput)) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['success'] = "Logged in successfully!";
                header("Location: welcome.php");
                exit();
            } else {
                $login_error = "Invalid username or password!";
            }
        } else {
            $login_error = "Invalid username or password!";
        }
    } catch (PDOException $e) {
        $login_error = "Login failed: " . $e->getMessage();
    }
}

// Register logic
if (isset($_POST['register_submit'])) {
    // Retrieve and sanitize inputs
    $username = trim($_POST['reg_username']);
    $email = trim($_POST['reg_email']);
    $password = $_POST['reg_password'];
    $passwordConfirm = $_POST['reg_passwordConfirm'];

    // Validate required fields
    if (empty($username) || empty($email) || empty($password)) {
        $register_error = "All fields are required!";
    } elseif ($password !== $passwordConfirm) {
        $register_error = "Passwords do not match!";
    } else {
        // Hash password using SHA-256 + Base64
        $hashedPassword = base64_encode(hash('sha256', $password, true));

        try {
            // Insert into database
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);

            // Set session and redirect
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['success'] = "Registration successful!";
            header("Location: welcome.php");
            exit();
        } catch (PDOException $e) {
            // Handle duplicate entries or other errors
            if ($e->getCode() == '23000') { // MySQL duplicate entry error code
                $register_error = "Username or email already exists!";
            } else {
                $register_error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

// Function to search for users based on a search term
function searchUsers($pdo, $searchTerm, $currentUserId) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, profile_picture_url FROM users 
                               WHERE username LIKE ? AND id != ? 
                               LIMIT 10");
        $stmt->execute(['%' . $searchTerm . '%', $currentUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error searching users: " . $e->getMessage());
        return [];
    }
}

// Function to display flashcard sets from database (keeping this from welcome.php for sidebar)
function displayFlashcardSetsFromDatabase($pdo, $userId) {
    try {
        // Prepare and execute the query to fetch sets for the logged-in user
        $stmt = $pdo->prepare("SELECT set_id, title FROM sets WHERE user_id = ? ORDER BY generated_at DESC");
        $stmt->execute([$userId]);
        
        // Fetch all results
        $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if any sets were found
        if (!empty($sets)) {
            foreach ($sets as $set) {
                echo '<div class="library-item-container">';
                echo '<a href="flashcard.php?set_id=' . $set['set_id'] . '" class="library-item" data-set-id="' . $set['set_id'] . '">';
                echo '<span class="set-title">' . htmlspecialchars($set['title']) . '</span>';
                echo '</a>';
                echo '<div class="item-actions">';
                echo '<button class="edit-set-btn" data-set-id="' . $set['set_id'] . '" data-set-title="' . htmlspecialchars($set['title']) . '">';
                echo '<i class="fa fa-pen"></i>';
                echo '</button>';
                echo '<button class="delete-set-btn" data-set-id="' . $set['set_id'] . '" data-set-title="' . htmlspecialchars($set['title']) . '">';
                echo '<i class="fa fa-trash"></i>';
                echo '</button>';
                echo '</div>';
                echo '</div>';
            }
        } else {
            // Display static examples if no sets are found
            echo '<div class="library-item"><span>Your sets will appear here</span></div>';
        }
    } catch (PDOException $e) {
        // Handle database errors
        error_log("Error fetching flashcard sets: " . $e->getMessage());
        echo '<p style="color:red;">Error loading library items.</p>';
    }
}
// Add this function to display shared sets
function displaySharedFlashcardSets($pdo, $userId) {
    try {
        // Prepare and execute the query to fetch sets shared with the logged-in user
        $stmt = $pdo->prepare("
            SELECT s.set_id, s.title, u.username as owner_name 
            FROM sets s
            JOIN shared_sets ss ON s.set_id = ss.set_id
            JOIN users u ON ss.owner_id = u.id
            WHERE ss.user_id = ? 
            ORDER BY ss.shared_at DESC
        ");
        $stmt->execute([$userId]);
        
        // Fetch all results
        $sharedSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if any shared sets were found
        if (!empty($sharedSets)) {
            foreach ($sharedSets as $set) {
                echo '<div class="library-item-container">';
                echo '<a href="flashcard.php?set_id=' . $set['set_id'] . '&shared=1" class="library-item shared-item" data-set-id="' . $set['set_id'] . '">';
                echo '<span class="set-title">' . htmlspecialchars($set['title']) . '</span>';
                echo '<span class="set-owner">by ' . htmlspecialchars($set['owner_name']) . '</span>';
                echo '</a>';
                echo '</div>';
            }
        } else {
            echo '<p class="no-sets-message">No sets have been shared with you yet.</p>';
        }
    } catch (PDOException $e) {
        // Handle database errors
        error_log("Error fetching shared flashcard sets: " . $e->getMessage());
        echo '<p style="color:red;">Error loading shared sets.</p>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect with Friends - Flashcard.ai</title>
    <link rel="icon" type="image/x-icon" href="./media/images/icon1.png">
    
    <style>
        :root {
            --sidebar-width: 180px;
            --sidebar-collapsed-width: 60px;
            --primary-color: #0D1117;
            --secondary-color: #161B22;
            --text-color: #e6edf3;
            --accent-color: #58a6ff;
            --border-color: #30363d;
            --hover-color: #21262d;
            --error-color: #f85149;
            --success-color: #56d364;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            display: flex;
            height: 100vh;
            background-color: var(--primary-color);
            color: var(--text-color);
            overflow: hidden;
        }

        /* Sidebar styles */
        .sidebar {
            display: flex;
            flex-direction: column;
            width: var(--sidebar-width);
            background-color: var(--secondary-color);
            border-right: 1px solid var(--border-color);
            transition: width 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            height: 100vh;
            position: relative;
            z-index: 10;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-top {
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .logo {
            display: flex;
            align-items: center;
            color: var(--text-color);
            font-weight: bold;
            white-space: nowrap;
        }

        .logo img {
            width: 24px;
            height: 24px;
            margin-right: 8px;
        }

        .toggle-btn {
            cursor: pointer;
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 16px;
        }

        .sidebar-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 16px 0;
            overflow-y: auto;
        }

        .library-section {
            flex: 1;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: background-color 0.2s;
            white-space: nowrap;
        }

        .nav-item:hover {
            background-color: var(--hover-color);
        }

        .nav-item i {
            margin-right: 10px;
            font-size: 18px;
            min-width: 24px;
            text-align: center;
        }

        .library-item {
            padding-left: 32px;
            font-size: 14px;
            margin-top: 4px;
            color: #8b949e;
            cursor: pointer;
            transition: color 0.2s;
        }

        .library-item:hover {
            color: var(--text-color);
            background-color: var(--hover-color);
        }

        .sidebar-bottom {
            padding: 16px;
            border-top: 1px solid var(--border-color);
        }

        .account {
            display: flex;
            align-items: center;
            white-space: nowrap;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .account:hover {
            background-color: var(--hover-color);
        }

        .account img {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            margin-right: 8px;
            background-color: lightgrey;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 20px;
        }

        /* Friend search styles */
        .search-container {
            max-width: 600px;
            margin: 0 auto;
            width: 100%;
        }

        .search-header {
            margin-bottom: 20px;
            text-align: center;
        }

        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            font-size: 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--secondary-color);
            color: var(--text-color);
            outline: none;
            transition: border-color 0.3s;
        }

        .search-input:focus {
            border-color: var(--accent-color);
        }

        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #8b949e;
        }

        .search-results {
            background-color: var(--secondary-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .user-card {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
            cursor: pointer;
        }

        .user-card:last-child {
            border-bottom: none;
        }

        .user-card:hover {
            background-color: var(--hover-color);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
            background-color: var(--hover-color);
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-weight: 500;
            margin-bottom: 4px;
        }

        .add-friend-btn {
            background-color: var(--accent-color);
            color: var(--text-color);
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .add-friend-btn:hover {
            background-color: #4a8ede;
        }

        .no-results {
            padding: 20px;
            text-align: center;
            color: #8b949e;
        }

        /* Auth modal styles */
        .auth-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .auth-container {
            background-color: var(--secondary-color);
            border-radius: 8px;
            width: 320px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }

        .auth-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
        }

        .auth-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .auth-tab.active {
            border-bottom: 2px solid var(--accent-color);
            font-weight: bold;
        }

        .auth-tab:hover {
            background-color: var(--hover-color);
        }

        .auth-form {
            padding: 16px;
            display: none;
        }

        .auth-form.active {
            display: block;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background-color: var(--primary-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .auth-btn {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 4px;
            background-color: var(--accent-color);
            color: var(--text-color);
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .auth-btn:hover {
            background-color: #4a8ede;
        }

        .error-message {
            color: var(--error-color);
            font-size: 12px;
            margin-top: 8px;
        }

        .success-message {
            color: var(--success-color);
            font-size: 12px;
            margin-top: 8px;
        }

        .close-modal {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 24px;
            cursor: pointer;
        }

        /* Library Section Styling */
        .library-section {
            padding: 10px;
            background-color: #161B22;
            border-radius: 8px;
            color: #e6edf3;
        }

        .library-header {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #e6edf3;
        }

        #library-items {
            display: flex;
            flex-direction: column;
        }

        .library-item {
            padding: 8px 12px;
            font-size: 14px;
            color: #8b949e;
            cursor: pointer;
            border-left: 2px solid #30363d;
            transition: all 0.2s ease-in-out;
        }

        .library-item:hover {
            color: #e6edf3;
            border-left-color: #58a6ff;
            background-color: #21262d;
        }

        .library-item-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .library-item {
            flex-grow: 1;
            padding: 8px 12px;
            font-size: 14px;
            color: #8b949e;
            cursor: pointer;
            border-left: 2px solid #30363d;
            transition: all 0.2s ease-in-out;
            text-decoration: none;
        }
        
        .library-item:hover {
            color: #e6edf3;
            border-left-color: #58a6ff;
            background-color: #21262d;
        }
        
        .item-actions {
            display: none;
            margin-right: 4px;
        }
        
        .edit-set-btn, .delete-set-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        
        .edit-set-btn {
            color: var(--accent-color);
            margin-right: 2px;
        }
        
        .edit-set-btn:hover {
            background-color: rgba(88, 166, 255, 0.1);
        }
        
        .delete-set-btn {
            color: var(--error-color);
        }
        
        .delete-set-btn:hover {
            background-color: rgba(248, 81, 73, 0.1);
        }
        
        .library-item-container:hover .item-actions {
            display: flex;
        }

        /* Profile picture styles */
        .profile-picture-section {
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .profile-picture {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            margin-bottom: 15px;
        }

        .upload-pfp-btn {
            background-color: var(--accent-color);
            color: var(--text-color);
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            transition: background-color 0.2s;
        }

        .upload-pfp-btn i {
            margin-right: 6px;
        }

        .upload-pfp-btn:hover {
            background-color: #4a8ede;
        }

        .profile-upload-form {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--primary-color);
        }

        ::-webkit-scrollbar-thumb {
            background: #3b4351;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #4c566b;
        }

        /* Friend request status indicators */
        .friend-status {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            margin-right: 10px;
        }

        .status-pending {
            background-color: #f0883e;
            color: var(--text-color);
        }

        .status-friends {
            background-color: var(--success-color);
            color: var(--text-color);
        }
        /* Add these styles to your connect.php file if they're not already there */
.search-results {
    margin-top: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
}

.user-card {
    display: flex;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 10px;
    object-fit: cover;
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: bold;
    color: var(--text-color);
}

.no-results {
    padding: 15px;
    text-align: center;
    color: #8b949e;
}

/* Add these styles to your connect.php file */
#searchResults {
    margin-top: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
    display: none;
}

.search-results-container {
    padding: 10px;
}

.user-item {
    display: flex;
    align-items: center;
    padding: 8px;
    border-bottom: 1px solid var(--border-color);
}

.user-item:last-child {
    border-bottom: none;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 10px;
    object-fit: cover;
}

.user-name {
    color: var(--text-color);
    font-weight: 500;
}

.no-results {
    padding: 15px;
    text-align: center;
    color: #8b949e;
}
/* Friends system styles */
.friends-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.friends-tabs {
    display: flex;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.tab-btn {
    background: none;
    border: none;
    padding: 10px 20px;
    color: var(--text-color);
    cursor: pointer;
    font-size: 16px;
    position: relative;
}

.tab-btn:hover {
    background-color: var(--hover-color);
}

.tab-btn.active {
    color: var(--accent-color);
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    width: 100%;
    height: 2px;
    background-color: var(--accent-color);
}

.tab-content {
    position: relative;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

/* Search results styles */
.search-container {
    margin-bottom: 20px;
}

#friendSearch {
    width: 100%;
    padding: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    color: var(--text-color);
    font-size: 16px;
}

#searchResults {
    margin-top: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    max-height: 400px;
    overflow-y: auto;
}

.user-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}

.user-item:last-child {
    border-bottom: none;
}

.user-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    margin-right: 15px;
    object-fit: cover;
}

.user-name {
    color: var(--text-color);
    font-weight: 500;
    flex: 1;
}

.add-friend-btn {
    background-color: var(--accent-color);
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.add-friend-btn:hover {
    background-color: #4a8ede;
}

.pending-btn {
    background-color: #f0883e;
}

.friends-btn {
    background-color: #56d364;
}

.no-results {
    padding: 15px;
    text-align: center;
    color: #8b949e;
}

/* Friend requests and friends list styles */
.friend-requests, .friends-list {
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    overflow: hidden;
}

.request-item, .friend-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
}

.request-item:last-child, .friend-item:last-child {
    border-bottom: none;
}

.request-actions {
    display: flex;
    gap: 10px;
}

.accept-btn {
    background-color: #56d364;
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
}

.reject-btn {
    background-color: #f85149;
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
}

.loading {
    padding: 20px;
    text-align: center;
    color: #8b949e;
}

.empty-state {
    padding: 30px;
    text-align: center;
    color: #8b949e;
}

/* Friends system styles */
.friends-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.friends-tabs {
    display: flex;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.tab-btn {
    background: none;
    border: none;
    padding: 10px 20px;
    color: var(--text-color);
    cursor: pointer;
    font-size: 16px;
    position: relative;
}

.tab-btn:hover {
    background-color: var(--hover-color);
}

.tab-btn.active {
    color: var(--accent-color);
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    width: 100%;
    height: 2px;
    background-color: var(--accent-color);
}

.tab-content {
    position: relative;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

/* Search results styles */
.search-container {
    margin-bottom: 20px;
}

#friendSearch {
    width: 100%;
    padding: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    color: var(--text-color);
    font-size: 16px;
}

#searchResults {
    margin-top: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    max-height: 400px;
    overflow-y: auto;
}

.user-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}

.user-item:last-child {
    border-bottom: none;
}

.user-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    margin-right: 15px;
    object-fit: cover;
}

.user-name {
    color: var(--text-color);
    font-weight: 500;
    flex: 1;
}

.add-friend-btn {
    background-color: var(--accent-color);
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.add-friend-btn:hover {
    background-color: #4a8ede;
}

.pending-btn {
    background-color: #f0883e;
}

.friends-btn {
    background-color: #56d364;
}

.no-results {
    padding: 15px;
    text-align: center;
    color: #8b949e;
}

/* Friend requests and friends list styles */
.friend-requests, .friends-list {
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    overflow: hidden;
}

.request-item, .friend-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
}

.request-item:last-child, .friend-item:last-child {
    border-bottom: none;
}

.request-actions {
    display: flex;
    gap: 10px;
}

.accept-btn {
    background-color: #56d364;
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
}

.reject-btn {
    background-color: #f85149;
    color: var(--text-color);
    border: none;
    border-radius: 4px;
    padding: 8px 12px;
    cursor: pointer;
}

.loading {
    padding: 20px;
    text-align: center;
    color: #8b949e;
}

.empty-state {
    padding: 30px;
    text-align: center;
    color: #8b949e;
}

/* Add or update these styles in the <style> section of connect.php */

.remove-friend-btn {
    background-color: transparent;
    color: var(--error-color);
    border: 1px solid var(--error-color);
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.remove-friend-btn:hover {
    background-color: rgba(248, 81, 73, 0.1);
}

.remove-friend-btn i {
    margin-right: 5px;
    font-size: 12px;
}

/* Update the friend-item styling to better align elements */
.friend-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    transition: background-color 0.2s;
}

.friend-item:hover {
    background-color: var(--hover-color);
}

.friend-item:last-child {
    border-bottom: none;
}

.user-info {
    flex: 1;
    margin-right: 15px;
}

/* Mobile responsiveness - for screens under 450px */
/* Update the mobile sidebar styles in your media query */
@media (max-width: 450px) {
    /* Sidebar adjustments - updated */
    .sidebar {
        width: 100%;
        height: auto;
        max-height: 60px;
        overflow: hidden;
        transition: max-height 0.3s ease, width 0s;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 100;
    }
    
    .sidebar.expanded {
        max-height: 100vh;
        overflow-y: auto;
        width: 100% !important; /* Force full width */
    }
    
    /* Force sidebar content to be visible when expanded */
    .sidebar.expanded .sidebar-content,
    .sidebar.expanded .library-section,
    .sidebar.expanded .sidebar-bottom {
        display: block;
        opacity: 1;
        visibility: visible;
    }
    
    /* Hide text in collapsed state */
    .sidebar:not(.expanded) .logo span,
    .sidebar:not(.expanded) .nav-item span,
    .sidebar:not(.expanded) .account span,
    .sidebar:not(.expanded) .library-section {
        display: none;
    }
    
    /* Show text in expanded state */
    .sidebar.expanded .logo span,
    .sidebar.expanded .nav-item span,
    .sidebar.expanded .account span {
        display: inline;
    }
    
    /* Ensure toggle button is visible and properly positioned */
    .toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
    }
    .sidebar-top {
        padding: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
    }
    
    /* Logo positioning for collapsed state (centered) */
    .sidebar:not(.expanded) .logo {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        justify-content: center;
    }
    
    /* Logo positioning for expanded state (left aligned) */
    .sidebar.expanded .logo {
        position: relative;
        left: 0;
        transform: none;
    }
    
    /* Title text styling */
    .logo-title {
        display: none;
        font-weight: bold;
        text-align: center;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
    }
    
    /* Show title in expanded state */
    .sidebar.expanded .logo-title {
        display: block;
    }
    
    /* Hide logo text in collapsed state */
    .sidebar:not(.expanded) .logo span {
        display: none;
    }
    
    /* Toggle button positioning */
    .toggle-btn {
        z-index: 10;
    }
    body {
        padding-top: 0; /* Remove any existing padding */
    }
    
    /* Adjust main content positioning */
    .main-content {
        margin-top: 60px; /* Match the height of the collapsed sidebar/navbar */
        width: 100%;
        position: relative;
        z-index: 1; /* Ensure it's below the sidebar but above other content */
    }
    
    /* When sidebar is expanded, push content further down or hide it */
    .sidebar.expanded + .main-content {
        margin-top: 60px; /* Keep the same margin when expanded */
        opacity: 0.3; /* Optional: dim the content when sidebar is expanded */
        pointer-events: none; /* Optional: prevent interaction with content when sidebar is expanded */
    }
    
    /* Ensure content area has proper padding */
    .content-area {
        padding: 12px;
        padding-top: 15px; /* Add a bit more padding at the top */
    }
    
    /* Ensure the input area at bottom doesn't overlap with content */
    .input-area {
        padding: 10px;
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        z-index: 90;
    }
    
    /* Add padding at the bottom to prevent content from being hidden behind the input area */
    .content-area {
        padding-bottom: 70px; /* Adjust based on the height of your input area */
    }
    
    /* Quick questions section needs margin to not be hidden by input area */
    .quick-questions {
        margin-bottom: 60px; /* Space for fixed input area */
    }

}

/* Add this to your existing CSS */
#shared-sets-section {
    display: none; /* Hidden by default, will be toggled with JS */
}

.shared-item {
    position: relative;
}

.set-owner {
    font-size: 12px;
    color: #8b949e;
    display: block;
    margin-top: 2px;
}

.no-sets-message {
    padding: 10px;
    color: #8b949e;
    font-style: italic;
    font-size: 14px;
}

/* Shared set indicator */
.shared-item::before {
    content: '\f064';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    left: -18px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--accent-color);
    font-size: 12px;
}

/* Add this to your existing CSS section */
.sidebar.collapsed .nav-item {
    justify-content: center;
    padding: 10px 0;
}

.sidebar.collapsed .nav-item i {
    margin-right: 0;
    margin-left: 0;
    text-align: center;
    width: 100%;
}

/* Ensure all icons have consistent width/alignment */
.nav-item i {
    min-width: 24px;
    text-align: center;
    margin-right: 10px;
    font-size: 18px;
}

/* Specifically target the shared sets icon if needed */
#shared-sets-toggle i {
    min-width: 24px;
    text-align: center;
}

    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo">
                <a href="welcome.php">    
                    <img src="media/images/icon2.png" alt="Logo">
                </a>
                <span>Flashcard.ai</span>
                
            </div>
            
        </div>
        
        <div class="sidebar-content">
            <a href="welcome.php" class="nav-item">
                <i class="fa fa-home"></i>
                <span>Home</span>
            </a>
            <a href="https://docs.google.com/document/d/1rvKo156DPou6UD3AZTfpJEa7ZuKD_uafZSG2bJSty6A/edit?pli=1&tab=t.0" class="nav-item" target="_blank">
                <i class="fa fa-file-alt"></i>
                <span>Documentation</span>
            </a>
            <a href="connect.php" class="nav-item active">
                <i class="fa fa-users"></i>
                <span>Friends</span>
            </a>

            <a href="#" class="nav-item">
                <i class="fa fa-book"></i>
                <span>Library</span>
            </a>

            <div class="library-section">
                <div id="library-items">
                    <?php 
                    if (isset($_SESSION['user_id'])) {
                        displayFlashcardSetsFromDatabase($pdo, $_SESSION['user_id']);
                    } 
                    else {
                        echo '<p>Please log in to view your library.</p>';
                    }
                    ?>
                </div>
                <!-- Add this after the Library nav item in welcome.php -->
<a href="#" class="nav-item" id="shared-sets-toggle">
    <i class="fa fa-share-alt"></i>
    <span>Shared Sets</span>
</a>

<div class="library-section" id="shared-sets-section">
    <div id="shared-sets-items">
        <?php 
        if (isset($_SESSION['user_id'])) {
            displaySharedFlashcardSets($pdo, $_SESSION['user_id']);
        } 
        else {
            echo '<p>Please log in to view shared sets.</p>';
        }
        ?>
    </div>
</div>

            </div>
            
        </div>
        
        
        
        <div class="sidebar-bottom">
            <div class="account" id="account-btn">
                <img src="<?php 
                    // Get profile picture URL from database or use default
                    if (isset($_SESSION['user_id'])) {
                        try {
                            $stmt = $pdo->prepare("SELECT profile_picture_url FROM users WHERE id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $user = $stmt->fetch();
                            echo !empty($user['profile_picture_url']) ? htmlspecialchars($user['profile_picture_url']) : 'media/images/pfp.png';
                        } catch (PDOException $e) {
                            echo 'media/images/pfp.png';
                        }
                    } else {
                        echo 'media/images/pfp.png';
                    }
                ?>" alt="User">
                <span>
                    <?php 
                    echo isset($_SESSION['username']) ? $_SESSION['username'] : 'Guest'; 
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <?php if (isset($_SESSION['user_id'])): ?>
            <!--<div class="search-container">
                <div class="search-header">
                    <h2>Find Friends</h2>
                    <p>Connect with other users to share flashcards and study together</p>
                </div>
                
                <div class="search-box">
                    <input type="text" id="friendSearch" class="search-input" placeholder="Search for users by username...">
                    <i class="fa fa-search search-icon"></i>
                </div>
                
                <div id="searchResults" class="search-results" style="display: none;">
                </div>
            </div> -->
            <div class="friends-container">
    <!-- Tabs navigation -->
    <div class="friends-tabs">
        <button class="tab-btn active" data-tab="search-tab">Search</button>
        <button class="tab-btn" data-tab="requests-tab">Requests</button>
        <button class="tab-btn" data-tab="friends-tab">Friends</button>
    </div>
    
    <!-- Tab content -->
    <div class="tab-content">
        <!-- Search tab -->
        <div id="search-tab" class="tab-pane active">
            <div class="search-container">
                <input type="text" id="friendSearch" placeholder="Search for users...">
                <div id="searchResults" class="search-results"></div>
            </div>
        </div>
        
        <!-- Requests tab -->
        <div id="requests-tab" class="tab-pane">
            <div class="requests-container">
                <h3>Friend Requests</h3>
                <div id="friendRequests" class="friend-requests">
                    <div class="loading">Loading requests...</div>
                </div>
            </div>
        </div>
        
        <!-- Friends tab -->
        <div id="friends-tab" class="tab-pane">
            <div class="friends-list-container">
                <h3>Your Friends</h3>
                <div id="friendsList" class="friends-list">
                    <div class="loading">Loading friends...</div>
                </div>
            </div>
        </div>
    </div>
</div>


        <?php else: ?>
            <div style="text-align: center; margin-top: 100px;">
                <h2>Connect with Friends</h2>
                <p>Please log in to search for and connect with friends.</p>
                <button class="auth-btn" style="max-width: 200px; margin: 20px auto;" id="login-prompt-btn">Log In</button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Authentication Modal -->
    <div class="auth-modal" id="auth-modal">
        <button class="close-modal" id="close-auth-modal">&times;</button>
        <div class="auth-container">
            <?php if (isset($_SESSION['user_id'])): ?>
                <!-- Logged in view -->
                <div class="auth-form active" id="logout-form">
                    <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                        <h3>Account</h3>
                        <p>Logged in as: <?php echo $_SESSION['username']; ?></p>
                        
                        <!-- Profile picture section -->
                        <div class="profile-picture-section">
                            <img src="<?php 
                                // Get profile picture URL from database or use default
                                try {
                                    $stmt = $pdo->prepare("SELECT profile_picture_url FROM users WHERE id = ?");
                                    $stmt->execute([$_SESSION['user_id']]);
                                    $user = $stmt->fetch();
                                    echo !empty($user['profile_picture_url']) ? htmlspecialchars($user['profile_picture_url']) : 'media/images/pfp.png';
                                } catch (PDOException $e) {
                                    echo 'media/images/pfp.png';
                                }
                            ?>" alt="Profile Picture" class="profile-picture">
                            
                            <form method="POST" action="upload_pfp.php" enctype="multipart/form-data" class="profile-upload-form">
                                <label for="profile-picture-upload" class="upload-pfp-btn">
                                    <i class="fa fa-camera"></i> Change Picture
                                </label>
                                <input type="file" id="profile-picture-upload" name="profile_picture" accept="image/*" style="display: none;">
                                <button type="submit" id="save-profile-picture" class="auth-btn" style="display: none;">Save Picture</button>
                            </form>
                        </div>
                    </div>
                    <a href="?logout" class="auth-btn" style="display: block; text-align: center; text-decoration: none;">Logout</a>
                </div>
            <?php else: ?>
                <!-- Login/Register tabs -->
                <div class="auth-tabs">
                    <div class="auth-tab active" data-form="login-form">Login</div>
                    <div class="auth-tab" data-form="register-form">Register</div>
                </div>
                
                <!-- Login form -->
                <form method="POST" action="" class="auth-form active" id="login-form">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <?php if (isset($login_error)): ?>
                        <div class="error-message"><?php echo $login_error; ?></div>
                    <?php endif; ?>
                    <button type="submit" name="login_submit" class="auth-btn">Login</button>
                </form>
                
                <!-- Register form -->
                <form method="POST" action="" class="auth-form" id="register-form">
                    <div class="form-group">
                        <label for="reg_username">Username</label>
                        <input type="text" id="reg_username" name="reg_username" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_email">Email</label>
                        <input type="email" id="reg_email" name="reg_email" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_password">Password</label>
                        <input type="password" id="reg_password" name="reg_password" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_passwordConfirm">Confirm Password</label>
                        <input type="password" id="reg_passwordConfirm" name="reg_passwordConfirm" required>
                    </div>
                    <?php if (isset($register_error)): ?>
                        <div class="error-message"><?php echo $register_error; ?></div>
                    <?php endif; ?>
                    <button type="submit" name="register_submit" class="auth-btn">Register</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Friend Request Modal -->
    <div class="auth-modal" id="friend-request-modal">
        <div class="auth-container">
            <button class="close-modal" id="close-friend-modal">&times;</button>
            <div class="auth-form active">
                <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                    <h3>Send Friend Request</h3>
                    <p>Do you want to send a friend request to <span id="friend-username"></span>?</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button id="cancel-request" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                    <button id="confirm-request" class="auth-btn">Send Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Download Modal -->
    <div id="download-modal" class="auth-modal" style="display: none;">
        <div class="auth-container" style="width: 350px;">
            <div class="auth-form active">
                <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                    <h3>Download</h3>
                    <p style="margin-top: 10px;">Download the app for managing, reviewing and to access your sets wherever you are!</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button id="cancel-download" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                    <button id="confirm-download" class="auth-btn" style="background-color: var(--accent-color);">Download</button>
                </div>
            </div>
        </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-sidebar');
    
    if (toggleBtn) {
        const toggleIcon = toggleBtn.querySelector('i');
        
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            } else {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        });
    }
    
    // Authentication modal
    const authModal = document.getElementById('auth-modal');
    const accountBtn = document.getElementById('account-btn');
    const closeAuthModal = document.getElementById('close-auth-modal');
    const loginPromptBtn = document.getElementById('login-prompt-btn');
    
    // Open modal on account click
    if (accountBtn) {
        accountBtn.addEventListener('click', function() {
            authModal.style.display = 'flex';
        });
    }
            
            // Open modal on login prompt click
            if (loginPromptBtn) {
                loginPromptBtn.addEventListener('click', function() {
                    authModal.style.display = 'flex';
                });
            }
            
            // Close modal on X click
            if (closeAuthModal) {
                closeAuthModal.addEventListener('click', function() {
                    authModal.style.display = 'none';
                });
            }
            
            // Tab switching for auth forms
            const authTabs = document.querySelectorAll('.auth-tab');
            
            authTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetFormId = this.getAttribute('data-form');
                    
                    // Deactivate all tabs and forms
                    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
                    
                    // Activate clicked tab and corresponding form
                    this.classList.add('active');
                    document.getElementById(targetFormId).classList.add('active');
                });
            });
            
            // Close modal on outside click
            window.addEventListener('click', function(e) {
                if (e.target === authModal) {
                    authModal.style.display = 'none';
                }
                
                if (e.target === document.getElementById('friend-request-modal')) {
                    document.getElementById('friend-request-modal').style.display = 'none';
                }
                
                if (e.target === document.getElementById('download-modal')) {
                    document.getElementById('download-modal').style.display = 'none';
                }
            });
            
            // Profile picture upload handling
            const profilePictureUpload = document.getElementById('profile-picture-upload');
            const saveProfilePictureBtn = document.getElementById('save-profile-picture');
            const profilePicture = document.querySelector('.profile-picture');
            
            if (profilePictureUpload) {
                profilePictureUpload.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        
                        // Check file type
                        const fileType = file.type;
                        if (!fileType.match('image.*')) {
                            alert('Please select an image file');
                            return;
                        }
                        
                        // Check file size (max 5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('File size should be less than 5MB');
                            return;
                        }
                        
                        // Preview the image
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            profilePicture.src = e.target.result;
                            saveProfilePictureBtn.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Friend search functionality
const friendSearch = document.getElementById('friendSearch');
const searchResults = document.getElementById('searchResults');

if (friendSearch) {
    friendSearch.addEventListener('input', function() {
        const searchTerm = this.value.trim();
        
        // Clear results if search term is too short
        if (searchTerm.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        
        // Make AJAX request to search for users
        fetch(`search_users.php?q=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(users => {
                // Clear previous results
                searchResults.innerHTML = '';
                
                if (users && users.length > 0) {
                    // Create a container for the results
                    const resultsContainer = document.createElement('div');
                    resultsContainer.className = 'search-results-container';
                    
                    // Add each user to the results
                    users.forEach(user => {
    const userItem = document.createElement('div');
    userItem.className = 'user-item';
    
    // Check if user has a profile picture, if not use the default
    const profilePic = user.profile_picture_url ? user.profile_picture_url : 'media/images/pfp.png';
    
    userItem.innerHTML = `
        <img src="${profilePic}" alt="${user.username}" class="user-avatar">
        <span class="user-name">${user.username}</span>
    `;
    
    resultsContainer.appendChild(userItem);
});

                    
                    // Add the results to the page
                    searchResults.appendChild(resultsContainer);
                    searchResults.style.display = 'block';
                } else {
                    // No users found
                    searchResults.innerHTML = '<div class="no-results">No users found</div>';
                    searchResults.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                searchResults.innerHTML = '<div class="no-results">Error searching for users</div>';
                searchResults.style.display = 'block';
            });
    });
}

            
            
            
            // Debounce function to limit API calls
            function debounce(func, wait) {
                let timeout;
                return function(...args) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            }
            
            // Download button functionality
            const downloadBtn = document.getElementById('download-btn');
            const downloadModal = document.getElementById('download-modal');
            const cancelDownload = document.getElementById('cancel-download');
            const confirmDownload = document.getElementById('confirm-download');
            
            if (downloadBtn) {
                downloadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    downloadModal.style.display = 'flex';
                });
            }
            
            if (cancelDownload) {
                cancelDownload.addEventListener('click', function() {
                    downloadModal.style.display = 'none';
                });
            }
            
            if (confirmDownload) {
                confirmDownload.addEventListener('click', function() {
                    alert('Download functionality will be implemented in the future.');
                    downloadModal.style.display = 'none';
                });
            }
            
            // Close search results when clicking outside
            document.addEventListener('click', function(e) {
                if (searchResults && friendSearch && !searchResults.contains(e.target) && e.target !== friendSearch) {
                    searchResults.style.display = 'none';
                }
            });
        });
        document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active class from all buttons and panes
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));
            
            // Add active class to clicked button and corresponding pane
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
            
            // Load content for the tab if needed
            if (tabId === 'requests-tab') {
                loadFriendRequests();
            } else if (tabId === 'friends-tab') {
                loadFriends();
            }
        });
    });
    
    // Friend search functionality
    const friendSearch = document.getElementById('friendSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (friendSearch) {
        friendSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 1) {
                searchResults.innerHTML = '';
                searchResults.style.display = 'none';
                return;
            }
            
            // Create an XMLHttpRequest object
            const xhr = new XMLHttpRequest();
            
            // Configure it to GET from search_users.php
            xhr.open('GET', 'search_users.php?q=' + encodeURIComponent(searchTerm), true);
            
            // Set up what happens on successful data submission
            xhr.onload = function() {
                if (xhr.status === 200) {
                    // Get the search results
                    const results = xhr.responseText;
                    
                    // Update the search results container
                    searchResults.innerHTML = results;
                    searchResults.style.display = 'block';
                    
                    // Add friend request buttons to each user item
                    addFriendRequestButtons();
                } else {
                    searchResults.innerHTML = '<div class="no-results">Error searching for users</div>';
                    searchResults.style.display = 'block';
                }
            };
            
            xhr.send();
        });
    }
    
    // Function to add friend request buttons to search results
    function addFriendRequestButtons() {
        const userItems = document.querySelectorAll('.user-item');
        
        userItems.forEach(item => {
            // Check if button already exists
            if (item.querySelector('button')) return;
            
            const username = item.querySelector('.user-name').textContent;
            
            // Create button
            const addButton = document.createElement('button');
            addButton.className = 'add-friend-btn';
            addButton.textContent = 'Add Friend';
            addButton.setAttribute('data-username', username);
            
            // Add click event to send friend request
            addButton.addEventListener('click', function(e) {
                e.preventDefault();
                sendFriendRequest(username, this);
            });
            
            // Append button to user item
            item.appendChild(addButton);
        });
    }
    
    // Function to send friend request
    function sendFriendRequest(username, button) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'send_friend_request.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                
                if (response.success) {
                    // Update button to show pending
                    button.textContent = 'Request Sent';
                    button.className = 'add-friend-btn pending-btn';
                    button.disabled = true;
                } else {
                    alert(response.message || 'Error sending friend request');
                }
            } else {
                alert('Error sending friend request');
            }
        };
        
        xhr.send('username=' + encodeURIComponent(username));
    }
    
    // Function to load friend requests
    // Function to load friend requests
function loadFriendRequests() {
    console.log("Loading friend requests");
    const requestsContainer = document.getElementById('friendRequests');
    
    if (!requestsContainer) {
        console.error("Friend requests container not found");
        return;
    }
    
    requestsContainer.innerHTML = '<div class="loading">Loading requests...</div>';
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_friend_requests.php', true);
    
    xhr.onload = function() {
        console.log("Friend requests response received");
        
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                
                if (response.success) {
                    if (response.requests && response.requests.length > 0) {
                        let html = '';
                        
                        response.requests.forEach(request => {
                            const profilePic = request.profile_picture_url || 'media/images/pfp.png';
                            
                            html += `
                            <div class="request-item">
                                <img src="${profilePic}" alt="${request.username}" class="user-avatar">
                                <div class="user-info">
                                    <div class="user-name">${request.username}</div>
                                    <div class="request-time">Requested ${request.time_ago}</div>
                                </div>
                                <div class="request-actions">
                                    <button class="accept-btn" data-id="${request.id}">Accept</button>
                                    <button class="reject-btn" data-id="${request.id}">Reject</button>
                                </div>
                            </div>
                            `;
                        });
                        
                        requestsContainer.innerHTML = html;
                        
                        // Add event listeners to accept/reject buttons
                        document.querySelectorAll('.accept-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                respondToRequest(this.getAttribute('data-id'), 'accept', this.closest('.request-item'));
                            });
                        });
                        
                        document.querySelectorAll('.reject-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                respondToRequest(this.getAttribute('data-id'), 'reject', this.closest('.request-item'));
                            });
                        });
                    } else {
                        requestsContainer.innerHTML = '<div class="empty-state">No friend requests at the moment</div>';
                    }
                } else {
                    requestsContainer.innerHTML = '<div class="empty-state">Error: ' + (response.message || 'Unknown error') + '</div>';
                }
            } catch (e) {
                console.error("Error parsing JSON:", e);
                requestsContainer.innerHTML = '<div class="empty-state">Error parsing response</div>';
            }
        } else {
            requestsContainer.innerHTML = '<div class="empty-state">Error loading requests</div>';
        }
    };
    
    xhr.onerror = function() {
        console.error("Network error loading requests");
        requestsContainer.innerHTML = '<div class="empty-state">Error connecting to server</div>';
    };
    
    xhr.send();
}

    
    // Function to respond to friend request
    function respondToRequest(requestId, action, requestItem) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'respond_to_request.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Remove the request item from the list
                        requestItem.remove();
                        
                        // If no more requests, show empty state
                        if (document.querySelectorAll('.request-item').length === 0) {
                            document.getElementById('friendRequests').innerHTML = 
                                '<div class="empty-state">No friend requests at the moment</div>';
                        }
                    } else {
                        alert(response.message || 'Error responding to request');
                    }
                } catch (e) {
                    alert('Error parsing response');
                }
            } else {
                alert('Error responding to request');
            }
        };
        
        xhr.send('request_id=' + encodeURIComponent(requestId) + '&action=' + encodeURIComponent(action));
    }
    
    // Function to load friends list
    // Function to load friends list
function loadFriends() {
    console.log("Loading friends list");
    const friendsContainer = document.getElementById('friendsList');
    
    if (!friendsContainer) {
        console.error("Friends list container not found");
        return;
    }
    
    friendsContainer.innerHTML = '<div class="loading">Loading friends...</div>';
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_friends.php', true);
    
    xhr.onload = function() {
        console.log("Friends list response received");
        
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                
                if (response.success) {
                    if (response.friends && response.friends.length > 0) {
                        let html = '';
                        
                        response.friends.forEach(friend => {
                            const profilePic = friend.profile_picture_url || 'media/images/pfp.png';
                            
                            html += `
                            <div class="friend-item">
                                <img src="${profilePic}" alt="${friend.username}" class="user-avatar">
                                <div class="user-info">
                                    <div class="user-name">${friend.username}</div>
                                    <div class="friend-since">Friends since ${friend.friends_since}</div>
                                </div>
                                <button class="remove-friend-btn" data-id="${friend.id}">Remove</button>
                            </div>
                            `;
                        });
                        
                        friendsContainer.innerHTML = html;
                        
                        // Add event listeners to remove buttons
                        document.querySelectorAll('.remove-friend-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                removeFriend(this.getAttribute('data-id'), this.closest('.friend-item'));
                            });
                        });
                    } else {
                        friendsContainer.innerHTML = '<div class="empty-state">You don\'t have any friends yet</div>';
                    }
                } else {
                    friendsContainer.innerHTML = '<div class="empty-state">Error: ' + (response.message || 'Unknown error') + '</div>';
                }
            } catch (e) {
                console.error("Error parsing JSON:", e);
                friendsContainer.innerHTML = '<div class="empty-state">Error parsing response</div>';
            }
        } else {
            friendsContainer.innerHTML = '<div class="empty-state">Error loading friends</div>';
        }
    };
    
    xhr.onerror = function() {
        console.error("Network error loading friends");
        friendsContainer.innerHTML = '<div class="empty-state">Error connecting to server</div>';
    };
    
    xhr.send();
}

    
    // Function to remove a friend
    function removeFriend(friendId, friendItem) {
        if (!confirm('Are you sure you want to remove this friend?')) {
            return;
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'remove_friend.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Remove the friend item from the list
                        friendItem.remove();
                        
                        // If no more friends, show empty state
                        if (document.querySelectorAll('.friend-item').length === 0) {
                            document.getElementById('friendsList').innerHTML = 
                                '<div class="empty-state">You don\'t have any friends yet</div>';
                        }
                    } else {
                        alert(response.message || 'Error removing friend');
                    }
                } catch (e) {
                    alert('Error parsing response');
                }
            } else {
                alert('Error removing friend');
            }
        };
        
        xhr.send('friend_id=' + encodeURIComponent(friendId));
    }
    
    // Load the initial tab content
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab) {
        const tabId = activeTab.getAttribute('data-tab');
        if (tabId === 'requests-tab') {
            loadFriendRequests();
        } else if (tabId === 'friends-tab') {
            loadFriends();
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            console.log("Tab clicked: " + this.getAttribute('data-tab'));
            
            // Remove active class from all buttons and panes
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));
            
            // Add active class to clicked button and corresponding pane
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
            
            // Load content for the tab if needed
            if (tabId === 'requests-tab') {
                loadFriendRequests();
            } else if (tabId === 'friends-tab') {
                loadFriends();
            }
        });
    });
    
    // Friend search functionality
    const friendSearch = document.getElementById('friendSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (friendSearch) {
        console.log("Friend search input found");
        
        friendSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            console.log("Searching for: " + searchTerm);
            
            if (searchTerm.length < 1) {
                searchResults.innerHTML = '';
                searchResults.style.display = 'none';
                return;
            }
            
            // Create an XMLHttpRequest object
            const xhr = new XMLHttpRequest();
            
            // Configure it to GET from search_users.php
            xhr.open('GET', 'search_users.php?q=' + encodeURIComponent(searchTerm), true);
            
            // Set up what happens on successful data submission
            xhr.onload = function() {
                if (xhr.status === 200) {
                    // Get the search results
                    const results = xhr.responseText;
                    console.log("Search results received");
                    
                    // Update the search results container
                    searchResults.innerHTML = results;
                    searchResults.style.display = 'block';
                    
                    // Add event listeners to the Add Friend buttons
                    const addFriendButtons = searchResults.querySelectorAll('.add-friend-btn');
                    console.log("Found " + addFriendButtons.length + " Add Friend buttons");
                    
                    addFriendButtons.forEach(button => {
                        button.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            const username = this.getAttribute('data-username');
                            console.log("Add friend button clicked for: " + username);
                            sendFriendRequest(username, this);
                        });
                    });
                } else {
                    searchResults.innerHTML = '<div class="no-results">Error searching for users</div>';
                    searchResults.style.display = 'block';
                }
            };
            
            xhr.send();
        });
    } else {
        console.error("Friend search input not found");
    }
    
    // Function to send friend request
    function sendFriendRequest(username, button) {
        console.log("Sending friend request to: " + username);
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'send_friend_request.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            console.log("Response received: " + xhr.responseText);
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    console.log("Parsed response:", response);
                    
                    if (response.success) {
                        // Update button to show pending
                        button.textContent = 'Request Sent';
                        button.className = 'add-friend-btn pending-btn';
                        button.disabled = true;
                        alert("Friend request sent successfully!");
                    } else {
                        alert(response.message || 'Error sending friend request');
                    }
                } catch (e) {
                    console.error("Error parsing JSON:", e);
                    alert("Error processing response");
                }
            } else {
                console.error("HTTP error:", xhr.status);
                alert('Error sending friend request');
            }
        };
        
        xhr.onerror = function() {
            console.error("Network error");
            alert('Network error when sending friend request');
        };
        
        const data = 'username=' + encodeURIComponent(username);
        console.log("Sending data: " + data);
        xhr.send(data);
    }
    
    
    
    // Function to respond to friend request
    function respondToRequest(requestId, action, requestItem) {
        console.log("Responding to request " + requestId + " with action: " + action);
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'respond_to_request.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            console.log("Response received: " + xhr.responseText);
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Remove the request item from the list
                        requestItem.remove();
                        
                        // If no more requests, show empty state
                        if (document.querySelectorAll('.request-item').length === 0) {
                            document.getElementById('friendRequests').innerHTML = 
                                '<div class="empty-state">No friend requests at the moment</div>';
                        }
                        
                        alert(action === 'accept' ? "Friend request accepted!" : "Friend request rejected");
                    } else {
                        alert(response.message || 'Error responding to request');
                    }
                } catch (e) {
                    console.error("Error parsing JSON:", e);
                    alert("Error processing response");
                }
            } else {
                console.error("HTTP error:", xhr.status);
                alert('Error responding to request');
            }
        };
        
        xhr.send('request_id=' + encodeURIComponent(requestId) + '&action=' + encodeURIComponent(action));
    }
        
    
    // Function to remove a friend
    function removeFriend(friendId, friendItem) {
        console.log("Removing friend with ID: " + friendId);
        
        if (!confirm('Are you sure you want to remove this friend?')) {
            return;
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'remove_friend.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            console.log("Response received: " + xhr.responseText);
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Remove the friend item from the list
                        friendItem.remove();
                        
                        // If no more friends, show empty state
                        if (document.querySelectorAll('.friend-item').length === 0) {
                            document.getElementById('friendsList').innerHTML = 
                                '<div class="empty-state">You don\'t have any friends yet</div>';
                        }
                        
                        alert("Friend removed successfully");
                    } else {
                        alert(response.message || 'Error removing friend');
                    }
                } catch (e) {
                    console.error("Error parsing JSON:", e);
                    alert("Error processing response");
                }
            } else {
                console.error("HTTP error:", xhr.status);
                alert('Error removing friend');
            }
        };
        
        xhr.send('friend_id=' + encodeURIComponent(friendId));
    }
    
    // Load the initial tab content
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab) {
        console.log("Initial active tab: " + activeTab.getAttribute('data-tab'));
        const tabId = activeTab.getAttribute('data-tab');
        if (tabId === 'requests-tab') {
            loadFriendRequests();
        } else if (tabId === 'friends-tab') {
            loadFriends();
        }
    } else {
        console.error("No active tab found");
    }
});

// Replace the existing sidebar toggle code in connect.php with this improved version
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle functionality
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-sidebar');
    const toggleIcon = toggleBtn.querySelector('i');
    
    // Function to handle sidebar toggle for both desktop and mobile
    function handleSidebarToggle(e) {
        if (e) e.stopPropagation();
        
        if (window.innerWidth <= 450) {
            // Mobile behavior - expand/collapse vertically
            sidebar.classList.toggle('expanded');
        } else {
            // Desktop behavior - collapse/expand horizontally
            sidebar.classList.toggle('collapsed');
        }
        
        // Update icon based on sidebar state
        if ((window.innerWidth <= 450 && sidebar.classList.contains('expanded')) || 
            (window.innerWidth > 450 && !sidebar.classList.contains('collapsed'))) {
            toggleIcon.classList.remove('fa-chevron-right');
            toggleIcon.classList.add('fa-chevron-left');
        } else {
            toggleIcon.classList.remove('fa-chevron-left');
            toggleIcon.classList.add('fa-chevron-right');
        }
    }
    
    // Initialize sidebar state based on screen size
    function initSidebar() {
        if (window.innerWidth <= 450) {
            // Mobile view - start collapsed (not expanded)
            sidebar.classList.remove('collapsed', 'expanded');
            toggleIcon.classList.remove('fa-chevron-left');
            toggleIcon.classList.add('fa-chevron-right');
            
            // Add click outside to close for mobile
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && sidebar.classList.contains('expanded')) {
                    handleSidebarToggle();
                }
            });
            
            // Prevent clicks inside sidebar from closing it
            sidebar.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        } else {
            // Desktop view - check if it should be collapsed
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            } else {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        }
    }
    
    // Add toggle button event listener
    toggleBtn.addEventListener('click', handleSidebarToggle);
    
    // Initialize on page load
    initSidebar();
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initSidebar, 250);
    });
});

// Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Toggle shared sets section
    const sharedSetsToggle = document.getElementById('shared-sets-toggle');
    const sharedSetsSection = document.getElementById('shared-sets-section');
    
    if (sharedSetsToggle && sharedSetsSection) {
        sharedSetsToggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle display
            if (sharedSetsSection.style.display === 'none' || sharedSetsSection.style.display === '') {
                sharedSetsSection.style.display = 'block';
            } else {
                sharedSetsSection.style.display = 'none';
            }
        });
    }
});

    </script>
</body>
</html>

