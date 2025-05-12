<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'includes/config.php';

// Perplexity API key for flashcard generation
const PERPLEXITY_API_KEY = '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';

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

// Load flashcard set data
$currentSet = null;
$flashcards = [];
$setId = isset($_GET['set_id']) ? intval($_GET['set_id']) : null;
$isShared = isset($_GET['shared']) && $_GET['shared'] == '1';

// View specific flashcard set
if (isset($_GET['set_id']) && is_numeric($_GET['set_id'])) {
    try {
        if ($isShared) {
            // Verify the set is shared with the user
            $stmt = $pdo->prepare("
                SELECT s.* 
                FROM sets s
                JOIN shared_sets ss ON s.set_id = ss.set_id
                WHERE s.set_id = ? AND ss.user_id = ?
            ");
            $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        } else {
            // Verify the set belongs to the user
            $stmt = $pdo->prepare("SELECT * FROM sets WHERE set_id = ? AND user_id = ?");
            $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        }
        
        $currentSet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentSet) {
            // Get all flashcards in this set
            $stmt = $pdo->prepare("SELECT * FROM flashcards WHERE set_id = ?");
            $stmt->execute([$_GET['set_id']]);
            $_SESSION['current_flashcards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['current_set'] = $currentSet;
            $_SESSION['is_shared_set'] = $isShared;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error loading flashcard set: " . $e->getMessage();
    }
}


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
    <title>Flashcard.ai</title>
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
        }

        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        /* Flashcard styles */
        .flashcard {
            background-color: var(--secondary-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid var(--border-color);
            position: relative;
            cursor: pointer;
        }

        .flashcard h3 {
            margin-bottom: 8px;
            color: var(--accent-color);
        }

        .flashcard p {
            margin-bottom: 4px;
        }

        .flashcard-content {
            display: none;
        }

        .flashcard.flipped .flashcard-content {
            display: block;
        }

        .flashcard-question {
            cursor: pointer;
        }

        .flashcard-set-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .empty-state {
            text-align: center;
            max-width: 500px;
            margin: 0 auto;
            padding-top: 100px;
        }

        .empty-state h2 {
            margin-bottom: 16px;
            color: var(--text-color);
        }

        .empty-state p {
            color: #8b949e;
            margin-bottom: 24px;
        }

        /* For collapsible sidebar content */
        .sidebar.collapsed .logo span,
        .sidebar.collapsed .nav-item span,
        .sidebar.collapsed .account span,
        .sidebar.collapsed .library-item {
            display: none;
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

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background-color: var(--primary-color);
            color: var(--text-color);
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            max-height: 300px;
        }

        .form-group input:focus, .form-group textarea:focus {
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
            margin
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

        a {
            color: var(--accent-color);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
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

        /* Library Section Styling */
        .library-section {
            padding: 10px;
            background-color: #161B22; /* Dark background */
            border-radius: 8px;
            color: #e6edf3; /* Light text color */
        }

        .library-header {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #e6edf3; /* Ensure header matches text color */
        }

        #library-items {
            display: flex;
            flex-direction: column;
        }

        .library-item {
            padding: 8px 12px;
            font-size: 14px;
            color: #8b949e; /* Slightly muted text color */
            cursor: pointer;
            border-left: 2px solid #30363d; /* Left border for visual separation */
            transition: all 0.2s ease-in-out;
        }

        .library-item:hover {
            color: #e6edf3; /* Highlighted text color on hover */
            border-left-color: #58a6ff; /* Change left border to accent color */
            background-color: #21262d; /* Slight hover background for better visibility */
        }
        /* Library Section Styling */
.library-section {
    padding: 10px;
    background-color: #161B22; /* Dark background */
    border-radius: 8px;
    color: #e6edf3; /* Light text color */
}

.library-header {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 10px;
    color: #e6edf3; /* Ensure header matches text color */
}

#library-items {
    display: flex;
    flex-direction: column;
}

.library-item {
    padding: 8px 12px;
    font-size: 14px;
    color: #8b949e; /* Slightly muted text color */
    cursor: pointer;
    border-left: 2px solid #30363d; /* Left border for visual separation */
    transition: all 0.2s ease-in-out;
}

.library-item:hover {
    color: #e6edf3; /* Highlighted text color on hover */
    border-left-color: #58a6ff; /* Change left border to accent color */
    background-color: #21262d; /* Slight hover background for better visibility */
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

.delete-set-btn {
    display: none;
    background: none;
    border: none;
    color: var(--error-color);
    cursor: pointer;
    padding: 8px;
    margin-right: 4px;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.delete-set-btn:hover {
    background-color: rgba(248, 81, 73, 0.1);
}

.library-item-container:hover .delete-set-btn {
    display: block;
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
.sidebar-bottom {
    padding: 16px;
    border-top: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sidebar-bottom .nav-item {
    padding: 8px 12px;
}
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

/* Flashcard styles */
.content-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    height: 100%;
}

.flashcard-set-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--border-color);
    width: 100%;
    max-width: 600px;
}

.flashcard-set-header h2 {
    color: var(--accent-color);
    margin-bottom: 5px;
}

.flashcard-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
    flex-grow: 1;
}

.flashcards-wrapper {
    position: relative;
    width: 100%;
    height: 350px;
    perspective: 1000px;
}

.flashcard {
    position: absolute;
    width: 100%;
    height: 100%;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    transform: translateX(50px);
}

.flashcard.active {
    opacity: 1;
    visibility: visible;
    transform: translateX(0);
}

.flashcard-inner {
    position: relative;
    width: 100%;
    height: 100%;
    text-align: center;
    transition: transform 0.6s;
    transform-style: preserve-3d;
    cursor: pointer;
}

.flashcard.flipped .flashcard-inner {
    transform: rotateY(180deg);
}

.flashcard-front, .flashcard-back {
    position: absolute;
    width: 100%;
    height: 100%;
    backface-visibility: hidden;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 20px;
    border-radius: 10px;
    background-color: var(--secondary-color);
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.flashcard-back {
    transform: rotateY(180deg);
}

.flashcard h3 {
    color: var(--accent-color);
    margin-bottom: 15px;
}

.flashcard p {
    font-size: 18px;
    line-height: 1.5;
    margin-bottom: 20px;
    max-width: 100%;
    overflow-wrap: break-word;
}

.flashcard-hint {
    position: absolute;
    bottom: 10px;
    font-size: 12px;
    color: #8b949e;
    font-style: italic;
}

.flashcard-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    margin-top: 20px;
}

.nav-btn {
    background: none;
    border: 1px solid var(--accent-color);
    color: var(--accent-color);
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.nav-btn:hover {
    background-color: rgba(88, 166, 255, 0.1);
}

.nav-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

#card-counter {
    font-size: 14px;
    color: #8b949e;
}

/* Progress tracking toggle */
.progress-tracking {
    margin-top: 15px;
    display: flex;
    justify-content: center;
}

.toggle-switch {
    position: relative;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 20px;
    background-color: var(--hover-color);
    border-radius: 20px;
    transition: .4s;
    margin-right: 10px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 2px;
    bottom: 2px;
    background-color: var(--text-color);
    border-radius: 50%;
    transition: .4s;
}

input:checked + .toggle-slider {
    background-color: var(--accent-color);
}

input:checked + .toggle-slider:before {
    transform: translateX(20px);
}

.toggle-label {
    font-size: 14px;
    color: var(--text-color);
}

/* Review buttons */
.review-buttons {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 15px;
}

.review-btn {
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    border: none;
    display: flex;
    align-items: center;
    transition: all 0.2s;
}

.review-btn i {
    margin-right: 8px;
}

.know-btn {
    background-color: rgba(86, 211, 100, 0.2);
    color: var(--success-color);
    border: 1px solid var(--success-color);
}

.know-btn:hover {
    background-color: rgba(86, 211, 100, 0.3);
}

.dont-know-btn {
    background-color: rgba(248, 81, 73, 0.2);
    color: var(--error-color);
    border: 1px solid var(--error-color);
}

.dont-know-btn:hover {
    background-color: rgba(248, 81, 73, 0.3);
}

/* Again button */
.again-container {
    text-align: center;
    margin-top: 20px;
}

#review-complete-msg {
    margin-bottom: 10px;
    font-size: 16px;
    color: var(--success-color);
}

#review-again-btn, #reset-all-btn {
    margin: 0 5px;
}
/* Make sure these CSS rules are included */
.flashcard-inner {
    position: relative;
    width: 100%;
    height: 100%;
    text-align: center;
    transition: transform 0.6s;
    transform-style: preserve-3d;
    cursor: pointer;
}

.flashcard.flipped .flashcard-inner {
    transform: rotateY(180deg);
}

.flashcard-front, .flashcard-back {
    position: absolute;
    width: 100%;
    height: 100%;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden; /* For Safari */
}

.flashcard-back {
    transform: rotateY(180deg);
}


/* Action buttons styling */
.card-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
}

.action-btn {
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    border: 1px solid var(--border-color);
    background-color: var(--secondary-color);
    color: var(--text-color);
    display: flex;
    align-items: center;
    transition: all 0.2s;
}

.action-btn i {
    margin-right: 8px;
}

.action-btn:hover {
    background-color: var(--hover-color);
}

.delete-btn {
    color: var(--error-color);
    border-color: var(--error-color);
}

.delete-btn:hover {
    background-color: rgba(248, 81, 73, 0.1);
}

.add-btn {
    color: var(--success-color);
    border-color: var(--success-color);
}

.add-btn:hover {
    background-color: rgba(86, 211, 100, 0.1);
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

/* Auth modal styles - ensure these are updated */
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
    position: relative;
    z-index: 1001;
    opacity: 1;
    max-height: 90vh;
    overflow-y: auto;
}

/* Fallback to ensure modal visibility */
.auth-modal[style*="display: flex"] {
    display: flex !important;
}

.auth-modal[style*="display: flex"] .auth-container {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
}

/* Share button styling */
#share-set-btn {
        margin-left: 10px;
        padding: 5px 10px;
        background-color: var(--accent-color);
        color: var(--text-color);
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        transition: background-color 0.2s;
    }
    
    #share-set-btn i {
        margin-right: 5px;
    }
    
    #share-set-btn:hover {
        background-color: #4a8ede;
    }
    
    /* Friends list styling */
    .friends-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        margin-bottom: 15px;
        background-color: var(--primary-color);
    }
    
    .friend-item {
        display: flex;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .friend-item:last-child {
        border-bottom: none;
    }
    
    .friend-item:hover {
        background-color: var(--hover-color);
    }
    
    .friend-item.selected {
        background-color: rgba(88, 166, 255, 0.1);
    }
    
    .friend-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        margin-right: 10px;
        object-fit: cover;
    }
    
    .friend-name {
        flex-grow: 1;
    }
    
    .friend-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .loading-indicator {
        padding: 20px;
        text-align: center;
        color: #8b949e;
    }
    
    .no-friends {
        padding: 20px;
        text-align: center;
        color: #8b949e;
    }
    
    .selected-friends-count {
        font-size: 14px;
        color: #8b949e;
        margin-bottom: 10px;
        text-align: right;

        
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
/* Add this to your existing CSS */
.shared-set-notice {
    padding: 10px;
    background-color: rgba(88, 166, 255, 0.1);
    border: 1px solid var(--accent-color);
    border-radius: 4px;
    color: var(--accent-color);
    font-size: 14px;
    text-align: center;
}

/* Add this to your existing CSS */
.shared-by {
    margin-left: 10px;
    font-size: 14px;
    color: #8b949e;
    font-style: italic;
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
        margin-top: 60px; 
        opacity: 0.3; 
        pointer-events: none;
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
        
        <!-- Add this to the sidebar-content div, after the existing Library nav item -->
<div class="sidebar-content">
    <a href="welcome.php" class="nav-item">
        <i class="fa fa-home"></i>
        <span>Home</span>
    </a>
    <a href="https://docs.google.com/document/d/1rvKo156DPou6UD3AZTfpJEa7ZuKD_uafZSG2bJSty6A/edit?pli=1&tab=t.0" class="nav-item" target="_blank">
        <i class="fa fa-file-alt"></i>
        <span>Documentation</span>
    </a>
    <a href="connect.php" class="nav-item">
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
        <!-- Add Shared Sets section -->
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
        <div class="content-area" id="content-area">
        <?php if (isset($_SESSION['current_flashcards']) && !empty($_SESSION['current_flashcards'])): ?>
            <div class="flashcard-set-header">
    <h2><?php echo htmlspecialchars($_SESSION['current_set']['title']); ?></h2>
    <?php if (!isset($_SESSION['is_shared_set']) || !$_SESSION['is_shared_set']): ?>
        <button id="share-set-btn" class="action-btn" title="Share this set">
            <i class="fa fa-share-alt"></i> Share
        </button>
    <?php else: ?>
        <span class="shared-by">
            Shared by: <?php 
                // Get the owner's username
                try {
                    $stmt = $pdo->prepare("
                        SELECT u.username 
                        FROM users u
                        JOIN shared_sets ss ON u.id = ss.owner_id
                        WHERE ss.set_id = ? AND ss.user_id = ?
                    ");
                    $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
                    $owner = $stmt->fetch();
                    echo htmlspecialchars($owner['username'] ?? 'Unknown');
                } catch (PDOException $e) {
                    echo 'Unknown';
                }
            ?>
        </span>
    <?php endif; ?>
    <p>Total cards: <?php echo count($_SESSION['current_flashcards']); ?></p>
</div>

    <div class="flashcard-container">
        <!-- Add this div for the card action buttons above the flashcard -->
        <!-- Modify the card actions div to check if it's a shared set -->
<div class="card-actions" style="text-align: center; margin-bottom: 15px;">
    <?php if (!isset($_SESSION['is_shared_set']) || !$_SESSION['is_shared_set']): ?>
        <button id="edit-current-card-btn" class="action-btn">
            <i class="fa fa-pen"></i> Edit Card
        </button>
        <button id="delete-current-card-btn" class="action-btn delete-btn">
            <i class="fa fa-trash"></i> Delete Card
        </button>
        <button id="add-new-card-btn" class="action-btn add-btn">
            <i class="fa fa-plus"></i> Add Card
        </button>
    <?php else: ?>
        <div class="shared-set-notice">
            <i class="fa fa-info-circle"></i> This is a shared set. You can view but not edit these cards.
        </div>
    <?php endif; ?>
</div>


        <div class="flashcards-wrapper">
            <?php foreach ($_SESSION['current_flashcards'] as $index => $card): ?>
                <div class="flashcard <?php echo ($index === 0) ? 'active' : ''; ?>" data-index="<?php echo $index; ?>" data-card-id="<?php echo $card['flashcard_id']; // Important: Add card ID ?>">
                    <div class="flashcard-inner">
                        <div class="flashcard-front">
                            <h3>Question:</h3>
                            <p><?php echo htmlspecialchars($card['question']); ?></p>
                            <div class="flashcard-hint">Click to reveal answer</div>
                        </div>
                        <div class="flashcard-back">
                            <h3>Answer:</h3>
                            <p><?php echo htmlspecialchars($card['answer']); ?></p>
                            <div class="flashcard-hint">Click to see question</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flashcard-navigation">
            <button id="prev-card" class="nav-btn"><i class="fa fa-arrow-left"></i> Previous</button>
            <span id="card-counter">Card 1 of <?php echo count($_SESSION['current_flashcards']); ?></span>
            <button id="next-card" class="nav-btn">Next <i class="fa fa-arrow-right"></i></button>
        </div>

        <!-- Add this div for the edit button -->
        
        <!-- End of added div -->

        <div class="progress-tracking">
            <label class="toggle-switch">
                <input type="checkbox" id="progress-toggle">
                <span class="toggle-slider"></span>
                <span class="toggle-label">Track Progress</span>
            </label>
        </div>

        <!-- Review buttons (initially hidden) -->
        <div class="review-buttons" style="display: none;">
            <button id="know-btn" class="review-btn know-btn"><i class="fa fa-check"></i> I know this</button>
            <button id="dont-know-btn" class="review-btn dont-know-btn"><i class="fa fa-times"></i> Still learning</button>
        </div>

        <!-- Again button (initially hidden) -->
        <div class="again-container" style="display: none; margin-top: 20px; text-align: center;">
            <p id="review-complete-msg">Review complete!</p>
            <button id="review-again-btn" class="nav-btn"><i class="fa fa-redo"></i> Review again</button>
            <button id="reset-all-btn" class="nav-btn"><i class="fa fa-sync"></i> Reset all cards</button>
        </div>
    </div>
<?php else: ?>
    <div class="message-container">
        <div class="message bot-message">
            <p>Select a flashcard set from the library to start studying, or create a new set.</p>
        </div>
    </div>
<?php endif; ?>

                            
        </div>
    
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

    <div id="confirmation-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 300px;">
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Confirm Deletion</h3>
                <p>Are you sure you want to delete "<span id="set-title-to-delete"></span>"?</p>
                <p style="color: var(--error-color); font-size: 12px; margin-top: 8px;">This action cannot be undone.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="cancel-delete" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-delete" class="auth-btn" style="background-color: var(--error-color);">Yes, I'm sure</button>
            </div>
        </div>
    </div>
</div>

<div id="edit-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 300px;">
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Rename Set</h3>
            </div>
            <div class="form-group">
                <label for="new-set-title">New Title (Max 10 characters)</label>
                <input type="text" id="new-set-title" maxlength="10" required>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="cancel-edit" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-edit" class="auth-btn" style="background-color: var(--accent-color);">Save</button>
            </div>
        </div>
    </div>
</div>
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

<!-- Edit Card Modal -->
<div id="edit-card-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 450px;"> <!-- Wider modal for textareas -->
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Edit Flashcard</h3>
            </div>
            <input type="hidden" id="edit-card-id"> <!-- To store the ID of the card being edited -->
            <div class="form-group">
                <label for="edit-card-question">Question</label>
                <textarea id="edit-card-question" name="edit_card_question" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="edit-card-answer">Answer</label>
                <textarea id="edit-card-answer" name="edit_card_answer" rows="4" required></textarea>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button id="cancel-edit-card" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-edit-card" class="auth-btn" style="background-color: var(--accent-color);">Save Changes</button>
            </div>
            <div id="edit-card-error" class="error-message" style="margin-top: 10px; text-align: center;"></div>
        </div>
    </div>
</div>
<!-- Delete Card Confirmation Modal -->
<div id="delete-card-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 350px;">
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Delete Flashcard</h3>
                <p>Are you sure you want to delete this flashcard?</p>
                <p style="color: var(--error-color); font-size: 12px; margin-top: 8px;">This action cannot be undone.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="cancel-delete-card" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-delete-card" class="auth-btn" style="background-color: var(--error-color);">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Card Modal -->
<div id="add-card-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 450px;"> <!-- Wider modal for textareas -->
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Add New Flashcard</h3>
            </div>
            <input type="hidden" id="add-card-set-id"> <!-- To store the set ID -->
            <div class="form-group">
                <label for="add-card-question">Question</label>
                <textarea id="add-card-question" name="add_card_question" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="add-card-answer">Answer</label>
                <textarea id="add-card-answer" name="add_card_answer" rows="4" required></textarea>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button id="cancel-add-card" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-add-card" class="auth-btn" style="background-color: var(--accent-color);">Add Card</button>
            </div>
            <div id="add-card-error" class="error-message" style="margin-top: 10px; text-align: center;"></div>
        </div>
    </div>
</div>
<div id="share-set-modal" class="auth-modal" style="display: none;">
    <div class="auth-container" style="width: 450px;">
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Share Set</h3>
                <p>Share "<?php echo isset($_SESSION['current_set']) ? htmlspecialchars($_SESSION['current_set']['title']) : ''; ?>" with your friends</p>
            </div>
            
            <div class="form-group">
                <label for="friend-search">Search Friends</label>
                <input type="text" id="friend-search" placeholder="Type to search...">
            </div>
            
            <div class="friends-list" id="friends-list">
                <div class="loading-indicator">
                    <i class="fa fa-spinner fa-spin"></i> Loading friends...
                </div>
                <!-- Friends will be loaded here dynamically -->
            </div>
            
            <div class="selected-friends-count" id="selected-friends-count">
                0 friends selected
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button id="cancel-share" class="auth-btn" style="background-color: var(--hover-color);">Cancel</button>
                <button id="confirm-share" class="auth-btn" style="background-color: var(--accent-color);">Share Set</button>
            </div>
            
            <div id="share-error" class="error-message" style="margin-top: 10px; text-align: center;"></div>
            <div id="share-success" class="success-message" style="margin-top: 10px; text-align: center;"></div>
        </div>
    </div>
</div>


    <script>

        
 document.addEventListener('DOMContentLoaded', function() {
    // ... (existing sidebar, auth modal, delete/edit set, download modal, flashcard navigation code) ...

    // --- Start: Edit Card Functionality ---
    const editCardModal = document.getElementById('edit-card-modal');
    const editCardBtn = document.getElementById('edit-current-card-btn');
    const cancelEditCardBtn = document.getElementById('cancel-edit-card');
    const confirmEditCardBtn = document.getElementById('confirm-edit-card');
    const editCardIdInput = document.getElementById('edit-card-id');
    const editCardQuestionTextarea = document.getElementById('edit-card-question');
    const editCardAnswerTextarea = document.getElementById('edit-card-answer');
    const editCardErrorDiv = document.getElementById('edit-card-error');
    const flashcardsWrapper = document.querySelector('.flashcards-wrapper'); // Get the container

    // Show edit card modal
    if (editCardBtn) {
        editCardBtn.addEventListener('click', function() {
            const activeCard = document.querySelector('.flashcard.active');
            if (!activeCard) {
                alert('No active card selected.');
                return;
            }

            const cardId = activeCard.dataset.cardId;
            const question = activeCard.querySelector('.flashcard-front p').textContent;
            const answer = activeCard.querySelector('.flashcard-back p').textContent;

            // Populate the modal
            editCardIdInput.value = cardId;
            editCardQuestionTextarea.value = question;
            editCardAnswerTextarea.value = answer;
            editCardErrorDiv.textContent = ''; // Clear previous errors

            // Show the modal
            editCardModal.style.display = 'flex';
            editCardQuestionTextarea.focus(); // Focus the first field
        });
    }

    // Cancel editing card
    if (cancelEditCardBtn) {
        cancelEditCardBtn.addEventListener('click', function() {
            editCardModal.style.display = 'none';
        });
    }

    // Confirm editing card (Save Changes)
    if (confirmEditCardBtn) {
        confirmEditCardBtn.addEventListener('click', function() {
            const cardId = editCardIdInput.value;
            const newQuestion = editCardQuestionTextarea.value.trim();
            const newAnswer = editCardAnswerTextarea.value.trim();
            editCardErrorDiv.textContent = ''; // Clear previous errors

            if (!newQuestion || !newAnswer) {
                editCardErrorDiv.textContent = 'Question and Answer cannot be empty.';
                return;
            }

            // Send AJAX request to update the card
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'edit_card.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        if (response.success) {
                            // Update the card display on the page
                            const cardElement = flashcardsWrapper.querySelector(`.flashcard[data-card-id="${cardId}"]`);
                            if (cardElement) {
                                cardElement.querySelector('.flashcard-front p').textContent = newQuestion;
                                cardElement.querySelector('.flashcard-back p').textContent = newAnswer;
                            }

                            // Optional: Update the session data if needed for other features
                            // This requires more complex logic to find and update the specific card
                            // in the PHP session array, potentially needing another AJAX call or page reload.
                            // For simplicity, we'll just update the visual display for now.

                            editCardModal.style.display = 'none'; // Hide modal on success
                        } else {
                            editCardErrorDiv.textContent = response.message || 'An unknown error occurred.';
                        }
                    } catch (e) {
                         editCardErrorDiv.textContent = 'Error parsing server response.';
                         console.error("Parsing error:", e, "Response:", this.responseText);
                    }
                } else {
                    editCardErrorDiv.textContent = `Error updating card: ${this.statusText}`;
                }
            };

            xhr.onerror = function() {
                 editCardErrorDiv.textContent = 'Network error occurred while trying to save.';
            };

            const params = `card_id=${encodeURIComponent(cardId)}&question=${encodeURIComponent(newQuestion)}&answer=${encodeURIComponent(newAnswer)}`;
            xhr.send(params);
        });
    }

    // Close edit card modal when clicking outside
    if (editCardModal) {
        editCardModal.addEventListener('click', function(e) {
            if (e.target === editCardModal) {
                editCardModal.style.display = 'none';
            }
        });
    }
    // --- End: Edit Card Functionality ---

}); // End of DOMContentLoaded

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
    
    // Open modal on account click
    accountBtn.addEventListener('click', function() {
        authModal.style.display = 'flex';
    });
    
    // Close modal on X click
    closeAuthModal.addEventListener('click', function() {
        authModal.style.display = 'none';
    });
    
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
    });
    
    // Toggle flashcard answers
    const flashcards = document.querySelectorAll('.flashcard');
    flashcards.forEach(card => {
        card.addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Delete set functionality
    const confirmationModal = document.getElementById('confirmation-modal');
    const setTitleToDelete = document.getElementById('set-title-to-delete');
    const cancelDelete = document.getElementById('cancel-delete');
    const confirmDelete = document.getElementById('confirm-delete');
    
    // Edit set functionality
    const editModal = document.getElementById('edit-modal');
    const newSetTitleInput = document.getElementById('new-set-title');
    const cancelEdit = document.getElementById('cancel-edit');
    const confirmEdit = document.getElementById('confirm-edit');
    
    let currentSetId = null;
    
    // Add event listeners to delete buttons
    document.querySelectorAll('.delete-set-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent triggering the parent link
            
            // Store the set ID and title for deletion
            currentSetId = this.dataset.setId;
            setTitleToDelete.textContent = this.dataset.setTitle;
            
            // Show confirmation dialog
            confirmationModal.style.display = 'flex';
        });
    });
    
    // Add event listeners to edit buttons
    document.querySelectorAll('.edit-set-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent triggering the parent link
            
            // Store the set ID for editing
            currentSetId = this.dataset.setId;
            
            // Pre-fill the input with current title
            newSetTitleInput.value = this.dataset.setTitle;
            
            // Show edit dialog
            editModal.style.display = 'flex';
            newSetTitleInput.focus();
            newSetTitleInput.select();
        });
    });
    
    // Cancel deletion
    cancelDelete.addEventListener('click', function() {
        confirmationModal.style.display = 'none';
    });
    
    // Confirm deletion
    confirmDelete.addEventListener('click', function() {
        // Send AJAX request to delete the set
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'delete_set.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status === 200) {
                // Reload the page to refresh the library
                window.location.reload();
            } else {
                alert('Error deleting set: ' + this.responseText);
            }
        };
        xhr.send('set_id=' + currentSetId);
        
        // Hide the confirmation modal
        confirmationModal.style.display = 'none';
    });
    
    // Cancel edit
    cancelEdit.addEventListener('click', function() {
        editModal.style.display = 'none';
    });
    
    // Confirm edit
    confirmEdit.addEventListener('click', function() {
        const newTitle = newSetTitleInput.value.trim();
        
        if (!newTitle) {
            alert('Title cannot be empty');
            return;
        }
        
        // Send AJAX request to update the set title
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'edit_set.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status === 200) {
                // Reload the page to refresh the library
                window.location.reload();
            } else {
                alert('Error updating set title: ' + this.responseText);
            }
        };
        xhr.send('set_id=' + currentSetId + '&title=' + encodeURIComponent(newTitle));
        
        // Hide the edit modal
        editModal.style.display = 'none';
    });
    
    // Handle Enter key in edit input
    newSetTitleInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmEdit.click();
        }
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target === confirmationModal) {
            confirmationModal.style.display = 'none';
        }
        if (e.target === editModal) {
            editModal.style.display = 'none';
        }
    });
});

// MODIFIED: Removed this standalone keyboard event listener that was causing the issue
// Add keyboard navigation
// document.addEventListener('keydown', function(e) {
//     if (e.key === 'ArrowLeft' && !prevBtn.disabled) {
//         prevBtn.click();
//     } else if (e.key === 'ArrowRight' && !nextBtn.disabled) {
//         nextBtn.click();
//     } else if (e.key === ' ' || e.key === 'Spacebar') {
//         // Flip current card on spacebar
//         const currentCard = document.querySelector('.flashcard.active');
//         if (currentCard) {
//             currentCard.classList.toggle('flipped');
//         }
//         e.preventDefault(); // Prevent page scrolling on spacebar
//     }
// });



document.addEventListener('DOMContentLoaded', function() {
    // Flashcard navigation
    const flashcards = document.querySelectorAll('.flashcard');
    const prevBtn = document.getElementById('prev-card');
    const nextBtn = document.getElementById('next-card');
    const cardCounter = document.getElementById('card-counter');
    
    if (flashcards.length === 0) return; // Exit if no flashcards
    
    let currentIndex = 0;
    const totalCards = flashcards.length;
    
    // Initialize buttons state
    updateNavButtons();
    
    // Previous card button
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentIndex > 0) {
                showCard(currentIndex - 1);
            }
        });
    }
    
    // Next card button
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (currentIndex < totalCards - 1) {
                showCard(currentIndex + 1);
            }
        });
    }
    
    // Flip card on click
    document.querySelectorAll('.flashcard').forEach(card => {
        card.addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
    });
    
    // Function to show a specific card
    function showCard(index) {
        // Hide current card
        flashcards[currentIndex].classList.remove('active');
        // Reset flip state
        flashcards[currentIndex].classList.remove('flipped');
        
        // Show new card
        currentIndex = index;
        flashcards[currentIndex].classList.add('active');
        
        // Update counter
        cardCounter.textContent = `Card ${currentIndex + 1} of ${totalCards}`;
        
        // Update buttons state
        updateNavButtons();
    }
    
    // Update navigation buttons state
    function updateNavButtons() {
        if (prevBtn && nextBtn) {
            prevBtn.disabled = currentIndex === 0;
            nextBtn.disabled = currentIndex === totalCards - 1;
        }
    }
    
    // MODIFIED: Removed duplicate keyboard navigation code here
});

// Add this to your JavaScript
// Add this to your JavaScript
document.querySelectorAll('.flashcard').forEach(card => {
    card.addEventListener('click', function() {
        console.log('Card clicked');
        this.classList.toggle('flipped');
        console.log('Flipped class toggled:', this.classList.contains('flipped'));
    });
});
 
document.addEventListener('DOMContentLoaded', function() {
    // Flashcard navigation
    const flashcards = document.querySelectorAll('.flashcard');
    const prevBtn = document.getElementById('prev-card');
    const nextBtn = document.getElementById('next-card');
    const cardCounter = document.getElementById('card-counter');
    
    // Progress tracking elements
    const progressToggle = document.getElementById('progress-toggle');
    const reviewButtons = document.querySelector('.review-buttons');
    const knowBtn = document.getElementById('know-btn');
    const dontKnowBtn = document.getElementById('dont-know-btn');
    const againContainer = document.querySelector('.again-container');
    const reviewAgainBtn = document.getElementById('review-again-btn');
    const resetAllBtn = document.getElementById('reset-all-btn');
    const reviewCompleteMsg = document.getElementById('review-complete-msg');
    
    if (flashcards.length === 0) return; // Exit if no flashcards
    
    let currentIndex = 0;
    const totalCards = flashcards.length;
    let cardsToReview = []; // Array to store indices of cards to review again
    let reviewMode = false; // Flag to track if we're in review mode
    
    // Initialize buttons state
    updateNavButtons();
    
    // Previous card button
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentIndex > 0) {
                showCard(currentIndex - 1);
            }
        });
    }
    
    // Next card button
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (currentIndex < totalCards - 1) {
                showCard(currentIndex + 1);
            }
        });
    }
    
    // Flip card on click
    flashcards.forEach(card => {
        card.addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
    });
    
    // Progress tracking toggle
    progressToggle.addEventListener('change', function() {
        reviewMode = this.checked;
        
        if (reviewMode) {
            // Switch to review mode
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
            reviewButtons.style.display = 'flex';
            // Reset cards to review
            cardsToReview = Array.from({ length: totalCards }, (_, i) => i);
            // Start from the first card
            showCard(0);
        } else {
            // Switch back to normal mode
            prevBtn.style.display = 'block';
            nextBtn.style.display = 'block';
            reviewButtons.style.display = 'none';
            againContainer.style.display = 'none';
            // Keep current card
            showCard(currentIndex);
        }
    });
    
    // "I know this" button
    knowBtn.addEventListener('click', function() {
        // Remove current card from review list
        cardsToReview = cardsToReview.filter(index => index !== currentIndex);
        
        // Move to next card or show completion
        if (cardsToReview.length > 0) {
            // Find the next card to review
            const nextReviewIndex = cardsToReview.find(index => index > currentIndex);
            if (nextReviewIndex !== undefined) {
                showCard(nextReviewIndex);
            } else {
                showCard(cardsToReview[0]); // Wrap around to the first card to review
            }
        } else {
            // All cards reviewed
            showReviewComplete();
        }
    });
    
    // "Still learning" button
    dontKnowBtn.addEventListener('click', function() {
        // Keep the card in the review list but move to the next one
        if (cardsToReview.length > 1) {
            // Find the next card to review
            let nextReviewIndex;
            const currentPosition = cardsToReview.indexOf(currentIndex);
            
            if (currentPosition < cardsToReview.length - 1) {
                nextReviewIndex = cardsToReview[currentPosition + 1];
            } else {
                nextReviewIndex = cardsToReview[0]; // Wrap around to the first card
            }
            
            showCard(nextReviewIndex);
        } else if (cardsToReview.length === 1) {
            // Only one card left, show completion
            showReviewComplete();
        }
    });
    
    // "Review again" button
    reviewAgainBtn.addEventListener('click', function() {
        if (cardsToReview.length > 0) {
            againContainer.style.display = 'none';
            reviewButtons.style.display = 'flex';
            showCard(cardsToReview[0]);
        }
    });
    
    // "Reset all" button
    resetAllBtn.addEventListener('click', function() {
        // Reset to include all cards
        cardsToReview = Array.from({ length: totalCards }, (_, i) => i);
        againContainer.style.display = 'none';
        reviewButtons.style.display = 'flex';
        showCard(0);
    });
    
    // Function to show a specific card
    function showCard(index) {
        // Hide current card
        flashcards[currentIndex].classList.remove('active');
        // Reset flip state
        flashcards[currentIndex].classList.remove('flipped');
        
        // Show new card
        currentIndex = index;
        flashcards[currentIndex].classList.add('active');
        
        // Update counter
        if (reviewMode) {
            cardCounter.textContent = `Card ${cardsToReview.indexOf(currentIndex) + 1} of ${cardsToReview.length}`;
        } else {
            cardCounter.textContent = `Card ${currentIndex + 1} of ${totalCards}`;
        }
        
        // Update buttons state
        updateNavButtons();
    }
    
    // Update navigation buttons state
    function updateNavButtons() {
        if (!reviewMode && prevBtn && nextBtn) {
            prevBtn.disabled = currentIndex === 0;
            nextBtn.disabled = currentIndex === totalCards - 1;
        }
    }
    
    // Show review complete screen
    function showReviewComplete() {
        reviewButtons.style.display = 'none';
        againContainer.style.display = 'block';
        
        if (cardsToReview.length === 0) {
            reviewCompleteMsg.textContent = "Congratulations! You've learned all the cards!";
            reviewAgainBtn.style.display = 'none';
        } else {
            reviewCompleteMsg.textContent = `You still have ${cardsToReview.length} cards to review.`;
            reviewAgainBtn.style.display = 'inline-block';
        }
    }
    
    // Add keyboard navigation (Updated to allow spaces in inputs/textareas)
    document.addEventListener('keydown', function(e) {
        const targetElement = e.target;
        // Check if the event target is an input field or textarea
        const isTypingInInput = targetElement.tagName === 'INPUT' || targetElement.tagName === 'TEXTAREA';

        // Handle navigation and review keys ONLY if not typing in an input/textarea
        if (!isTypingInInput) {
            if (!reviewMode) {
                if (e.key === 'ArrowLeft' && prevBtn && !prevBtn.disabled) {
                    prevBtn.click();
                } else if (e.key === 'ArrowRight' && nextBtn && !nextBtn.disabled) {
                    nextBtn.click();
                }
            } else { // In review mode
                // Check if review buttons are visible before triggering click
                if ((e.key === 'ArrowRight' || e.key === 'y' || e.key === 'Y') && knowBtn && knowBtn.offsetParent !== null) {
                    knowBtn.click(); // "I know this"
                } else if ((e.key === 'ArrowLeft' || e.key === 'n' || e.key === 'N') && dontKnowBtn && dontKnowBtn.offsetParent !== null) {
                    dontKnowBtn.click(); // "Still learning"
                }
            }
        } // End of check for not typing

        // Handle spacebar: Flip card ONLY if not typing in an input/textarea
        if ((e.key === ' ' || e.key === 'Spacebar') && !isTypingInInput) {
            // Flip current card on spacebar
            const currentCard = document.querySelector('.flashcard.active');
            if (currentCard) {
                currentCard.classList.toggle('flipped');
            }
            // Prevent page scrolling ONLY when flipping the card
            e.preventDefault();
        }
        // If isTypingInInput is true, the spacebar event is not handled here,
        // allowing the default behavior (inserting a space) in the input/textarea.
    });
}); // Make sure this closing }); matches the outer DOMContentLoaded listener

document.addEventListener('DOMContentLoaded', function() {
    // Find all flashcards and add click event listeners
    const flashcards = document.querySelectorAll('.flashcard');
    
    flashcards.forEach(card => {
        // Remove any existing click event listeners (to avoid duplicates)
        card.removeEventListener('click', flipCard);
        
        // Add a new click event listener
        card.addEventListener('click', flipCard);
    });
    
    // Function to flip a card
    function flipCard(event) {
        // Make sure we're not clicking on a button inside the card
        if (event.target.closest('button') === null) {
            console.log('Card clicked');
            this.classList.toggle('flipped');
            console.log('Flipped class toggled:', this.classList.contains('flipped'));
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Existing edit card button functionality
    const editCardBtn = document.getElementById('edit-current-card-btn');
    
    // New buttons
    const deleteCardBtn = document.getElementById('delete-current-card-btn');
    const addCardBtn = document.getElementById('add-new-card-btn');
    
    // Add event listeners for the new buttons
    if (deleteCardBtn) {
        deleteCardBtn.addEventListener('click', function(event) {
            event.stopPropagation(); // Prevent card flipping
            
            const activeCard = document.querySelector('.flashcard.active');
            if (!activeCard) {
                alert('No active card selected.');
                return;
            }
            
            // Show the delete confirmation modal
            const deleteCardModal = document.getElementById('delete-card-modal');
            deleteCardModal.style.display = 'flex';
            
            // Store the card ID and index in variables accessible to the confirm handler
            const cardId = activeCard.dataset.cardId;
            const cardIndex = parseInt(activeCard.dataset.index);
            
            // Set up the confirm delete button
            const confirmDeleteCardBtn = document.getElementById('confirm-delete-card');
            const cancelDeleteCardBtn = document.getElementById('cancel-delete-card');
            
            // Remove any existing event listeners to prevent duplicates
            const newConfirmBtn = confirmDeleteCardBtn.cloneNode(true);
            confirmDeleteCardBtn.parentNode.replaceChild(newConfirmBtn, confirmDeleteCardBtn);
            
            const newCancelBtn = cancelDeleteCardBtn.cloneNode(true);
            cancelDeleteCardBtn.parentNode.replaceChild(newCancelBtn, cancelDeleteCardBtn);
            
            // Add event listener for cancel button
            newCancelBtn.addEventListener('click', function() {
                deleteCardModal.style.display = 'none';
            });
            
            // Add event listener for confirm button
            newConfirmBtn.addEventListener('click', function() {
                // Send AJAX request to delete the card
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'delete_card.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const response = JSON.parse(this.responseText);
                            
                            if (response.success) {
                                // Get all flashcards and the total count
                                const flashcards = document.querySelectorAll('.flashcard');
                                const totalCards = flashcards.length;
                                
                                if (totalCards <= 1) {
                                    // If this was the last card, reload the page
                                    alert('Last card deleted. Returning to set view.');
                                    window.location.reload();
                                    return;
                                }
                                
                                // Remove the deleted card from the DOM
                                activeCard.remove();
                                
                                // Update the remaining cards' indices
                                document.querySelectorAll('.flashcard').forEach((card, idx) => {
                                    card.dataset.index = idx;
                                });
                                
                                // Show the next card or the previous if this was the last
                                const newTotalCards = totalCards - 1;
                                let newIndex = cardIndex;
                                if (newIndex >= newTotalCards) {
                                    newIndex = newTotalCards - 1;
                                }
                                
                                // Find the card with the new index
                                const nextCard = document.querySelector(`.flashcard[data-index="${newIndex}"]`);
                                if (nextCard) {
                                    nextCard.classList.add('active');
                                }
                                
                                // Update the card counter
                                const cardCounter = document.getElementById('card-counter');
                                if (cardCounter) {
                                    cardCounter.textContent = `Card ${newIndex + 1} of ${newTotalCards}`;
                                }
                                
                                // Update navigation buttons state
                                const prevBtn = document.getElementById('prev-card');
                                const nextBtn = document.getElementById('next-card');
                                if (prevBtn) prevBtn.disabled = newIndex === 0;
                                if (nextBtn) nextBtn.disabled = newIndex === newTotalCards - 1;
                                
                                // Update the set header to reflect the new count
                                const totalCardsElement = document.querySelector('.flashcard-set-header p');
                                if (totalCardsElement) {
                                    totalCardsElement.textContent = `Total cards: ${newTotalCards}`;
                                }
                                
                                // Hide the modal
                                deleteCardModal.style.display = 'none';
                            } else {
                                alert(response.message || 'Error deleting flashcard.');
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            alert('Error processing server response.');
                        }
                    } else {
                        alert('Error deleting flashcard. Server returned: ' + this.status);
                    }
                };
                
                xhr.onerror = function() {
                    alert('Network error occurred while trying to delete the flashcard.');
                };
                
                xhr.send(`card_id=${encodeURIComponent(cardId)}`);
            });
        });
    }
    
    if (addCardBtn) {
    addCardBtn.addEventListener('click', function(event) {
        event.stopPropagation(); // Prevent card flipping
        
        // Get the current set ID from the URL or session
        let setId;
        const urlParams = new URLSearchParams(window.location.search);
        setId = urlParams.get('set_id');
        
        if (!setId) {
            alert('Could not determine the current set ID.');
            return;
        }
        
        // Show the add card modal
        const addCardModal = document.getElementById('add-card-modal');
        const addCardSetIdInput = document.getElementById('add-card-set-id');
        const addCardQuestionTextarea = document.getElementById('add-card-question');
        const addCardAnswerTextarea = document.getElementById('add-card-answer');
        const addCardErrorDiv = document.getElementById('add-card-error');
        
        // Reset form and set the set ID
        addCardSetIdInput.value = setId;
        addCardQuestionTextarea.value = '';
        addCardAnswerTextarea.value = '';
        addCardErrorDiv.textContent = '';
        
        // Show the modal
        addCardModal.style.display = 'flex';
        addCardQuestionTextarea.focus();
        
        // Set up the buttons
        const confirmAddCardBtn = document.getElementById('confirm-add-card');
        const cancelAddCardBtn = document.getElementById('cancel-add-card');
        
        // Remove any existing event listeners to prevent duplicates
        const newConfirmBtn = confirmAddCardBtn.cloneNode(true);
        confirmAddCardBtn.parentNode.replaceChild(newConfirmBtn, confirmAddCardBtn);
        
        const newCancelBtn = cancelAddCardBtn.cloneNode(true);
        cancelAddCardBtn.parentNode.replaceChild(newCancelBtn, cancelAddCardBtn);
        
        // Add event listener for cancel button
        newCancelBtn.addEventListener('click', function() {
            addCardModal.style.display = 'none';
        });
        
        // Add event listener for confirm button
        newConfirmBtn.addEventListener('click', function() {
            const question = addCardQuestionTextarea.value.trim();
            const answer = addCardAnswerTextarea.value.trim();
            addCardErrorDiv.textContent = '';
            
            if (!question || !answer) {
                addCardErrorDiv.textContent = 'Question and Answer cannot be empty.';
                return;
            }
            
            // Send AJAX request to add the card
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'add_card.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (this.status === 201) { // Created
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.success) {
                            // Get the new card data
                            const newCard = response.card;
                            
                            // Get all existing flashcards and the flashcards wrapper
                            const flashcardsWrapper = document.querySelector('.flashcards-wrapper');
                            const existingCards = document.querySelectorAll('.flashcard');
                            const totalCards = existingCards.length;
                            const newIndex = totalCards; // New card will be at the end
                            
                            // Create the new flashcard HTML
                            const newCardHTML = `
                                <div class="flashcard" data-index="${newIndex}" data-card-id="${newCard.flashcard_id}">
                                    <div class="flashcard-inner">
                                        <div class="flashcard-front">
                                            <h3>Question:</h3>
                                            <p>${newCard.question}</p>
                                            <div class="flashcard-hint">Click to reveal answer</div>
                                        </div>
                                        <div class="flashcard-back">
                                            <h3>Answer:</h3>
                                            <p>${newCard.answer}</p>
                                            <div class="flashcard-hint">Click to see question</div>
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            // Add the new card to the DOM
                            flashcardsWrapper.insertAdjacentHTML('beforeend', newCardHTML);
                            
                            // Add click event to the new card
                            const newCardElement = flashcardsWrapper.querySelector(`.flashcard[data-index="${newIndex}"]`);
                            newCardElement.addEventListener('click', function() {
                                this.classList.toggle('flipped');
                            });
                            
                            // Update the total cards count in the header
                            const newTotalCards = totalCards + 1;
                            const totalCardsElement = document.querySelector('.flashcard-set-header p');
                            if (totalCardsElement) {
                                totalCardsElement.textContent = `Total cards: ${newTotalCards}`;
                            }
                            
                            // Hide the modal
                            addCardModal.style.display = 'none';
                            
                            // Get navigation elements
                            const prevBtn = document.getElementById('prev-card');
                            const nextBtn = document.getElementById('next-card');
                            const cardCounter = document.getElementById('card-counter');
                            
                            // Hide all cards first
                            document.querySelectorAll('.flashcard').forEach(card => {
                                card.classList.remove('active');
                            });
                            
                            // Show the new card
                            newCardElement.classList.add('active');
                            
                            // Update the card counter
                            if (cardCounter) {
                                cardCounter.textContent = `Card ${newIndex + 1} of ${newTotalCards}`;
                            }
                            
                            // Update navigation buttons state
                            if (prevBtn) prevBtn.disabled = newIndex === 0;
                            if (nextBtn) nextBtn.disabled = newIndex === newTotalCards - 1;
                            
                            // IMPORTANT: Update the currentIndex variable in the outer scope
                            // This is crucial for navigation to work properly
                            currentIndex = newIndex;
                            
                            // IMPORTANT: Refresh the navigation button event listeners
                            // Remove existing listeners
                            if (prevBtn) {
                                const newPrevBtn = prevBtn.cloneNode(true);
                                prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
                                
                                // Add new listener
                                newPrevBtn.addEventListener('click', function() {
                                    if (currentIndex > 0) {
                                        showCard(currentIndex - 1);
                                    }
                                });
                            }
                            
                            if (nextBtn) {
                                const newNextBtn = nextBtn.cloneNode(true);
                                nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
                                
                                // Add new listener
                                newNextBtn.addEventListener('click', function() {
                                    if (currentIndex < newTotalCards - 1) {
                                        showCard(currentIndex + 1);
                                    }
                                });
                            }
                            
                            // Alert success
                            alert('Flashcard added successfully!');
                            window.location.reload();


                        } else {
                            addCardErrorDiv.textContent = response.message || 'An unknown error occurred.';
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                        addCardErrorDiv.textContent = 'Error processing server response.';
                    }
                } else {
                    addCardErrorDiv.textContent = `Error adding card: ${this.statusText}`;
                }
            };
            
            xhr.onerror = function() {
                addCardErrorDiv.textContent = 'Network error occurred while trying to add the card.';
            };
            
            const params = `set_id=${encodeURIComponent(setId)}&question=${encodeURIComponent(question)}&answer=${encodeURIComponent(answer)}`;
            xhr.send(params);
        });
    });
}


    
    // Make sure the edit button also prevents card flipping
    if (editCardBtn) {
        const originalClickHandler = editCardBtn.onclick;
        editCardBtn.onclick = function(event) {
            // Prevent the event from bubbling up to the card
            event.stopPropagation();
            // Call the original handler if it exists
            if (typeof originalClickHandler === 'function') {
                originalClickHandler.call(this, event);
            }
        };
    }
    
    // Close delete card modal when clicking outside
    const deleteCardModal = document.getElementById('delete-card-modal');
    if (deleteCardModal) {
        deleteCardModal.addEventListener('click', function(e) {
            if (e.target === deleteCardModal) {
                deleteCardModal.style.display = 'none';
            }
        });
    }
    // Close add card modal when clicking outside
const addCardModal = document.getElementById('add-card-modal');
if (addCardModal) {
    addCardModal.addEventListener('click', function(e) {
        if (e.target === addCardModal) {
            addCardModal.style.display = 'none';
        }
    });
}

});

// Profile picture upload handling
document.addEventListener('DOMContentLoaded', function() {
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
});

// Authentication modal
document.addEventListener('DOMContentLoaded', function() {
    const authModal = document.getElementById('auth-modal');
    const accountBtn = document.getElementById('account-btn');
    const closeAuthModal = document.getElementById('close-auth-modal');
    
    if (accountBtn) {
        accountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (authModal) {
                // Force layout recalculation before showing the modal
                void authModal.offsetWidth;
                
                // Show the modal
                authModal.style.display = 'flex';
                
                // Force the browser to repaint
                setTimeout(function() {
                    authModal.style.opacity = '1';
                }, 10);
            }
        });
    }
    
    // Close modal on X click
    if (closeAuthModal) {
        closeAuthModal.addEventListener('click', function() {
            authModal.style.display = 'none';
        });
    }
    
    // Close modal on outside click
    window.addEventListener('click', function(e) {
        if (e.target === authModal) {
            authModal.style.display = 'none';
        }
    });
    
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
});

document.addEventListener('DOMContentLoaded', function() {
        // Share set functionality
        const shareSetBtn = document.getElementById('share-set-btn');
        const shareSetModal = document.getElementById('share-set-modal');
        const friendSearch = document.getElementById('friend-search');
        const friendsList = document.getElementById('friends-list');
        const selectedFriendsCount = document.getElementById('selected-friends-count');
        const cancelShareBtn = document.getElementById('cancel-share');
        const confirmShareBtn = document.getElementById('confirm-share');
        const shareErrorDiv = document.getElementById('share-error');
        const shareSuccessDiv = document.getElementById('share-success');
        
        let friends = []; // Will store all friends
        let selectedFriends = []; // Will store selected friend IDs
        
        // Show share modal when share button is clicked
        if (shareSetBtn) {
            shareSetBtn.addEventListener('click', function() {
                // Reset state
                selectedFriends = [];
                shareErrorDiv.textContent = '';
                shareSuccessDiv.textContent = '';
                updateSelectedCount();
                
                // Show the modal
                shareSetModal.style.display = 'flex';
                
                // Load friends
                loadFriends();
            });
        }
        
        // Cancel share
        if (cancelShareBtn) {
            cancelShareBtn.addEventListener('click', function() {
                shareSetModal.style.display = 'none';
            });
        }
        
        // Search functionality
        if (friendSearch) {
            friendSearch.addEventListener('input', function() {
                loadFriends(this.value);
            });
        }
        
        // Confirm share
        if (confirmShareBtn) {
            confirmShareBtn.addEventListener('click', function() {
                if (selectedFriends.length === 0) {
                    shareErrorDiv.textContent = 'Please select at least one friend to share with.';
                    return;
                }
                
                shareErrorDiv.textContent = '';
                shareSuccessDiv.textContent = '';
                
                // Get the current set ID
                const urlParams = new URLSearchParams(window.location.search);
                const setId = urlParams.get('set_id');
                
                if (!setId) {
                    shareErrorDiv.textContent = 'Could not determine the current set ID.';
                    return;
                }
                
                // Send AJAX request to share the set
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'share_set.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const response = JSON.parse(this.responseText);
                            
                            if (response.success) {
                                shareSuccessDiv.textContent = response.message;
                                // Clear selection after successful share
                                selectedFriends = [];
                                updateSelectedCount();
                                renderFriendsList(friends);
                                
                                // Close modal after a delay
                                setTimeout(function() {
                                    shareSetModal.style.display = 'none';
                                }, 2000);
                            } else {
                                shareErrorDiv.textContent = response.message || 'An unknown error occurred.';
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            shareErrorDiv.textContent = 'Error processing server response.';
                        }
                    } else {
                        shareErrorDiv.textContent = `Error sharing set: ${this.statusText}`;
                    }
                };
                
                xhr.onerror = function() {
                    shareErrorDiv.textContent = 'Network error occurred while trying to share the set.';
                };
                
                // Format the friend IDs as a comma-separated list
                const friendIdsParam = selectedFriends.map(id => `friend_ids[]=${encodeURIComponent(id)}`).join('&');
                const params = `set_id=${encodeURIComponent(setId)}&${friendIdsParam}`;
                xhr.send(params);
            });
        }
        
        // Load friends from server
        function loadFriends(searchTerm = '') {
            // Show loading indicator
            friendsList.innerHTML = '<div class="loading-indicator"><i class="fa fa-spinner fa-spin"></i> Loading friends...</div>';
            // Send AJAX request to get friends
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `get_friends.php${searchTerm ? '?search=' + encodeURIComponent(searchTerm) : ''}`, true);
            
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.success) {
                            friends = response.friends;
                            renderFriendsList(friends);
                        } else {
                            friendsList.innerHTML = `<div class="no-friends">Error: ${response.message}</div>`;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                        friendsList.innerHTML = '<div class="no-friends">Error processing server response.</div>';
                    }
                } else {
                    friendsList.innerHTML = `<div class="no-friends">Error fetching friends: ${this.statusText}</div>`;
                }
            };
            
            xhr.onerror = function() {
                friendsList.innerHTML = '<div class="no-friends">Network error occurred while trying to fetch friends.</div>';
            };
            
            xhr.send();
        }
        
        // Render friends list
        function renderFriendsList(friendsArray) {
            if (friendsArray.length === 0) {
                friendsList.innerHTML = '<div class="no-friends">No friends found. Add friends to share your flashcard sets!</div>';
                return;
            }
            
            let html = '';
            
            friendsArray.forEach(friend => {
                const isSelected = selectedFriends.includes(friend.id);
                html += `
                    <div class="friend-item ${isSelected ? 'selected' : ''}" data-friend-id="${friend.id}">
                        <img src="${friend.profile_picture_url}" alt="${friend.username}" class="friend-avatar">
                        <div class="friend-name">${friend.username}</div>
                        <input type="checkbox" class="friend-checkbox" ${isSelected ? 'checked' : ''}>
                    </div>
                `;
            });
            
            friendsList.innerHTML = html;
            
            // Add click event to friend items
            document.querySelectorAll('.friend-item').forEach(item => {
                item.addEventListener('click', function() {
                    const friendId = this.dataset.friendId;
                    const checkbox = this.querySelector('.friend-checkbox');
                    
                    // Toggle selection
                    if (selectedFriends.includes(friendId)) {
                        selectedFriends = selectedFriends.filter(id => id !== friendId);
                        this.classList.remove('selected');
                        checkbox.checked = false;
                    } else {
                        selectedFriends.push(friendId);
                        this.classList.add('selected');
                        checkbox.checked = true;
                    }
                    
                    updateSelectedCount();
                });
            });
        }
        
        // Update selected friends count
        function updateSelectedCount() {
            selectedFriendsCount.textContent = `${selectedFriends.length} friend${selectedFriends.length !== 1 ? 's' : ''} selected`;
        }
        
        // Close share modal when clicking outside
        shareSetModal.addEventListener('click', function(e) {
            if (e.target === shareSetModal) {
                shareSetModal.style.display = 'none';
            }
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
// Replace your existing mobile sidebar toggle code with this improved version
document.addEventListener('DOMContentLoaded', function() {
    // Mobile sidebar toggle - improved
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-sidebar');
    const toggleIcon = toggleBtn.querySelector('i');
    
    function handleMobileView() {
        if (window.innerWidth <= 450) {
            // Reset any inline styles that might be causing issues
            sidebar.style.width = '';
            
            // For mobile view
            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Toggle expanded class
                sidebar.classList.toggle('expanded');
                
                // Update icon
                if (sidebar.classList.contains('expanded')) {
                    toggleIcon.classList.remove('fa-chevron-right');
                    toggleIcon.classList.add('fa-chevron-left');
                } else {
                    toggleIcon.classList.remove('fa-chevron-left');
                    toggleIcon.classList.add('fa-chevron-right');
                }
                
                // Force a reflow to ensure transitions work properly
                void sidebar.offsetWidth;
            });
            
            // Close sidebar when clicking elsewhere
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && sidebar.classList.contains('expanded')) {
                    sidebar.classList.remove('expanded');
                    toggleIcon.classList.remove('fa-chevron-left');
                    toggleIcon.classList.add('fa-chevron-right');
                }
            });
            
            // Prevent sidebar from closing when clicking inside it
            sidebar.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        } else {
            // For desktop view, ensure proper icon state
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            } else {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        }
    }
    
    // Run on load
    handleMobileView();
    
    // Run on resize with debounce
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(handleMobileView, 250);
    });
});
document.addEventListener('DOMContentLoaded', function() {
    // Fix flashcard flipping without breaking other functionality
    const flashcards = document.querySelectorAll('.flashcard');
    
    flashcards.forEach(card => {
        // Get the inner part that should trigger flipping
        const cardInner = card.querySelector('.flashcard-inner');
        
        if (cardInner) {
            // Add click event specifically to the inner part
            cardInner.addEventListener('click', function(event) {
                // Only flip if we're not clicking on a button
                if (!event.target.closest('button')) {
                    card.classList.toggle('flipped');
                    // Don't stop propagation completely, but prevent default
                    event.preventDefault();
                }
            });
        }
    });
    
    // Make sure buttons inside cards don't trigger flipping
    document.querySelectorAll('.card-actions button, .flashcard button').forEach(button => {
        button.addEventListener('click', function(event) {
            // Just prevent this click from bubbling to the card
            event.stopPropagation();
        });
    });
});
    </script>
</body>
</html>
