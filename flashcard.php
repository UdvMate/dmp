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

// View specific flashcard set
if (isset($_GET['set_id']) && is_numeric($_GET['set_id'])) {
    try {
        // Verify the set belongs to the user
        $stmt = $pdo->prepare("SELECT * FROM sets WHERE set_id = ? AND user_id = ?");
        $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        $currentSet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentSet) {
            // Get all flashcards in this set
            $stmt = $pdo->prepare("SELECT * FROM flashcards WHERE set_id = ?");
            $stmt->execute([$_GET['set_id']]);
            $_SESSION['current_flashcards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['current_set'] = $currentSet;
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
            <a href="#" class="nav-item">
                    <i class="fa fa-book"></i>
                    <span>Library</span>
                </a>
            
            <div class="library-section">
                
                
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
                <img src="media/images/pfp.png" alt="User">
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
                    <p>Total cards: <?php echo count($_SESSION['current_flashcards']); ?></p>
                </div>
            
                <div class="flashcard-container">
                    <div class="flashcards-wrapper">
                        <?php foreach ($_SESSION['current_flashcards'] as $index => $card): ?>
                            <div class="flashcard <?php echo ($index === 0) ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
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
// Add keyboard navigation
document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowLeft' && !prevBtn.disabled) {
        prevBtn.click();
    } else if (e.key === 'ArrowRight' && !nextBtn.disabled) {
        nextBtn.click();
    } else if (e.key === ' ' || e.key === 'Spacebar') {
        // Flip current card on spacebar
        const currentCard = document.querySelector('.flashcard.active');
        if (currentCard) {
            currentCard.classList.toggle('flipped');
        }
        e.preventDefault(); // Prevent page scrolling on spacebar
    }
});
const downloadBtn = document.getElementById('download-btn');
const downloadModal = document.getElementById('download-modal');
const cancelDownload = document.getElementById('cancel-download');
const confirmDownload = document.getElementById('confirm-download');

// Show download modal when download button is clicked
downloadBtn.addEventListener('click', function(e) {
    e.preventDefault();
    downloadModal.style.display = 'flex';
});

// Cancel download
cancelDownload.addEventListener('click', function() {
    downloadModal.style.display = 'none';
});

// Confirm download (currently does nothing)
confirmDownload.addEventListener('click', function() {
    // This is where you would add the actual download functionality in the future
    alert('Download functionality will be implemented in the future.');
    downloadModal.style.display = 'none';
});

// Close download modal when clicking outside
downloadModal.addEventListener('click', function(e) {
    if (e.target === downloadModal) {
        downloadModal.style.display = 'none';
    }
});

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
    
    // Add keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft' && prevBtn && !prevBtn.disabled) {
            prevBtn.click();
        } else if (e.key === 'ArrowRight' && nextBtn && !nextBtn.disabled) {
            nextBtn.click();
        } else if (e.key === ' ' || e.key === 'Spacebar') {
            // Flip current card on spacebar
            const currentCard = document.querySelector('.flashcard.active');
            if (currentCard) {
                currentCard.classList.toggle('flipped');
            }
            e.preventDefault(); // Prevent page scrolling on spacebar
        }
    });
});
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
    
    // Add keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (!reviewMode) {
            if (e.key === 'ArrowLeft' && prevBtn && !prevBtn.disabled) {
                prevBtn.click();
            } else if (e.key === 'ArrowRight' && nextBtn && !nextBtn.disabled) {
                nextBtn.click();
            }
        } else {
            if (e.key === 'ArrowRight' || e.key === 'y' || e.key === 'Y') {
                knowBtn.click(); // "I know this"
            } else if (e.key === 'ArrowLeft' || e.key === 'n' || e.key === 'N') {
                dontKnowBtn.click(); // "Still learning"
            }
        }
        
        if (e.key === ' ' || e.key === 'Spacebar') {
            // Flip current card on spacebar
            const currentCard = document.querySelector('.flashcard.active');
            if (currentCard) {
                currentCard.classList.toggle('flipped');
            }
            e.preventDefault(); // Prevent page scrolling on spacebar
        }
    });
});

// Add this to your JavaScript
document.querySelectorAll('.flashcard').forEach(card => {
    card.addEventListener('click', function() {
        console.log('Card clicked');
        this.classList.toggle('flipped');
        console.log('Flipped class toggled:', this.classList.contains('flipped'));
    });
});

    </script>
</body>
</html>
