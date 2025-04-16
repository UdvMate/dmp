<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Set content type to JSON

// Get search query


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
            echo '<div class="library-item"><span>PHP Strings</span></div>';
            echo '<div class="library-item"><span>Server Requests</span></div>';
            echo '<div class="library-item"><span>Examples</span></div>';
        }
    } catch (PDOException $e) {
        // Handle database errors
        error_log("Error fetching flashcard sets: " . $e->getMessage());
        echo '<p style="color:red;">Error loading library items.</p>';
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
            <button class="toggle-btn" id="toggle-sidebar">
                <i class="fa fa-chevron-left"></i>
            </button>
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
            </div>
        </div>
        
        <div class="sidebar-bottom">
            <!-- Add download button above the account button -->
            <a href="#" class="nav-item" id="download-btn">
                <i class="fa fa-download"></i>
                <span>Download</span>
            </a>
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
            <div class="search-container">
                <div class="search-header">
                    <h2>Find Friends</h2>
                    <p>Connect with other users to share flashcards and study together</p>
                </div>
                
                <div class="search-box">
                    <input type="text" id="friendSearch" class="search-input" placeholder="Search for users by username...">
                    <i class="fa fa-search search-icon"></i>
                </div>
                
                <div id="searchResults" class="search-results" style="display: none;">
                    <!-- Search results will appear here -->
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
                        
                        userItem.innerHTML = `
                            <img src="${user.profile_picture_url}" alt="${user.username}" class="user-avatar">
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
    </script>
</body>
</html>

