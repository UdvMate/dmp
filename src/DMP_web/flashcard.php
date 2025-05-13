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
    $username = trim($_POST['reg_username']);
    $email = trim($_POST['reg_email']);
    $password = $_POST['reg_password'];
    $passwordConfirm = $_POST['reg_passwordConfirm'];

    if (empty($username) || empty($email) || empty($password)) {
        $register_error = "All fields are required!";
    } elseif ($password !== $passwordConfirm) {
        $register_error = "Passwords do not match!";
    } else {
        $hashedPassword = base64_encode(hash('sha256', $password, true));

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);

            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['success'] = "Registration successful!";
            header("Location: welcome.php");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
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
            $stmt = $pdo->prepare("
                SELECT s.* 
                FROM sets s
                JOIN shared_sets ss ON s.set_id = ss.set_id
                WHERE s.set_id = ? AND ss.user_id = ?
            ");
            $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM sets WHERE set_id = ? AND user_id = ?");
            $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        }
        
        $currentSet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentSet) {
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
        $stmt = $pdo->prepare("SELECT set_id, title FROM sets WHERE user_id = ? ORDER BY generated_at DESC");
        $stmt->execute([$userId]);
        
        $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            echo '<div class="library-item"><span>PHP Strings</span></div>';
            echo '<div class="library-item"><span>Server Requests</span></div>';
            echo '<div class="library-item"><span>Examples</span></div>';
        }
    } catch (PDOException $e) {
        error_log("Error fetching flashcard sets: " . $e->getMessage());
        echo '<p style="color:red;">Error loading library items.</p>';
    }
}

function displaySharedFlashcardSets($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.set_id, s.title, u.username as owner_name 
            FROM sets s
            JOIN shared_sets ss ON s.set_id = ss.set_id
            JOIN users u ON ss.owner_id = u.id
            WHERE ss.user_id = ? 
            ORDER BY ss.shared_at DESC
        ");
        $stmt->execute([$userId]);
        
        $sharedSets = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    -webkit-backface-visibility: hidden; 
}

.flashcard-back {
    transform: rotateY(180deg);
}



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

#shared-sets-section {
    display: none; 
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
.shared-set-notice {
    padding: 10px;
    background-color: rgba(88, 166, 255, 0.1);
    border: 1px solid var(--accent-color);
    border-radius: 4px;
    color: var(--accent-color);
    font-size: 14px;
    text-align: center;
}

.shared-by {
    margin-left: 10px;
    font-size: 14px;
    color: #8b949e;
    font-style: italic;
}

@media (max-width: 450px) {
    .sidebar {
        display: none;
    }
    
    .main-content {
        margin-top: 0;
        width: 100%;
        position: relative;
        z-index: 1;
    }
    
    .content-area {
        padding: 12px;
    }
}

/* Floating menu button */
.floating-menu-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background-color: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    z-index: 999;
    transition: transform 0.2s, background-color 0.2s;
}

.floating-menu-btn:hover {
    transform: scale(1.05);
    background-color: #4a8ede;
}

.floating-menu-btn:active {
    transform: scale(0.95);
}

/* Navigation modal */
.nav-modal {
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

.nav-modal-content {
    background-color: var(--secondary-color);
    border-radius: 8px;
    width: 90%;
    max-width: 350px;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
}

.nav-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
}

.nav-modal-header h3 {
    margin: 0;
    color: var(--text-color);
}

.close-nav-modal {
    background: none;
    border: none;
    color: var(--text-color);
    font-size: 24px;
    cursor: pointer;
}

.nav-modal-body {
    padding: 16px;
}

.nav-modal-item {
    display: flex;
    align-items: center;
    padding: 12px;
    color: var(--text-color);
    text-decoration: none;
    border-radius: 4px;
    margin-bottom: 8px;
    transition: background-color 0.2s;
}

.nav-modal-item:hover {
    background-color: var(--hover-color);
    text-decoration: none;
}

.nav-modal-item i {
    margin-right: 12px;
    font-size: 20px;
    width: 24px;
    text-align: center;
}

.nav-modal-account {
    display: flex;
    align-items: center;
    padding: 12px;
    margin-top: 16px;
    border-top: 1px solid var(--border-color);
    cursor: pointer;
}

.nav-modal-account img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    margin-right: 12px;
}

/* Only show floating button on mobile */
@media (min-width: 769px) {
    .floating-menu-btn {
        display: none;
    }
}

/* Navigation modal section styles */
.nav-modal-section {
    margin-bottom: 12px;
}

.nav-modal-section-header {
    display: flex;
    align-items: center;
    padding: 12px;
    color: var(--text-color);
    cursor: pointer;
    border-radius: 4px;
    transition: background-color 0.2s;
    position: relative;
}

.nav-modal-section-header:hover {
    background-color: var(--hover-color);
}

.nav-modal-section-header i:first-child {
    margin-right: 12px;
    font-size: 20px;
    width: 24px;
    text-align: center;
}

.toggle-icon {
    margin-left: auto;
    transition: transform 0.3s;
}

.nav-modal-section-header.active .toggle-icon {
    transform: rotate(180deg);
}

.nav-modal-section-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.nav-modal-section-content.active {
    max-height: 300px;
    overflow-y: auto;
}

.nav-modal-subitem {
    display: flex;
    flex-direction: column;
    padding: 10px 10px 10px 42px;
    color: #8b949e;
    text-decoration: none;
    font-size: 14px;
    border-left: 2px solid var(--border-color);
    margin-left: 24px;
    transition: all 0.2s;
}

.nav-modal-subitem:hover {
    color: var(--text-color);
    background-color: var(--hover-color);
    border-left-color: var(--accent-color);
    text-decoration: none;
}

.nav-modal-subitem.shared {
    position: relative;
}

.nav-modal-subitem.shared:before {
    content: '\f064';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    left: 24px;
    top: 10px;
    color: var(--accent-color);
    font-size: 12px;
}

.nav-modal-subitem .owner {
    font-size: 12px;
    color: #8b949e;
    margin-top: 2px;
}

.nav-modal-subitem-empty {
    padding: 10px 10px 10px 42px;
    color: #8b949e;
    font-size: 14px;
    font-style: italic;
    margin-left: 24px;
}

/* Adjust modal content for better scrolling with many items */
.nav-modal-content {
    max-height: 80vh;
    overflow-y: auto;
}

.nav-modal-body {
    padding: 16px;
    overflow-y: visible;
}

/* Fix for horizontal scrollbar */
html, body {
    max-width: 100%;
    overflow-x: hidden;
}

* {
    box-sizing: border-box;
}

.main-content {
    max-width: 100%;
    overflow-x: hidden;
}

.content-area {
    max-width: 100%;
    overflow-x: hidden;
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
            <input type="hidden" id="edit-card-id">
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
    <div class="auth-container" style="width: 450px;">
        <div class="auth-form active">
            <div class="form-group" style="text-align: center; margin-bottom: 20px;">
                <h3>Add New Flashcard</h3>
            </div>
            <input type="hidden" id="add-card-set-id">
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
<!-- Floating menu button for mobile -->
<div class="floating-menu-btn" id="floating-menu-btn">
    <i class="fa fa-bars"></i>
</div>

<!-- Add navigation modal -->
<div class="nav-modal" id="nav-modal">
    <div class="nav-modal-content">
        <div class="nav-modal-header">
            <h3>Navigation</h3>
            <button class="close-nav-modal" id="close-nav-modal">&times;</button>
        </div>
        <div class="nav-modal-body">
            <a href="welcome.php" class="nav-modal-item">
                <i class="fa fa-home"></i>
                <span>Home</span>
            </a>
            <a href="https://docs.google.com/document/d/1rvKo156DPou6UD3AZTfpJEa7ZuKD_uafZSG2bJSty6A/edit?pli=1&tab=t.0" class="nav-modal-item" target="_blank">
                <i class="fa fa-file-alt"></i>
                <span>Documentation</span>
            </a>
            <a href="connect.php" class="nav-modal-item">
                <i class="fa fa-users"></i>
                <span>Friends</span>
            </a>
            
            <!-- Library section with collapsible sets -->
            <div class="nav-modal-section">
                <div class="nav-modal-section-header" id="library-toggle">
                    <i class="fa fa-book"></i>
                    <span>Library</span>
                    <i class="fa fa-chevron-down toggle-icon"></i>
                </div>
                
                <div class="nav-modal-section-content" id="library-content">
                    <?php 
                    if (isset($_SESSION['user_id'])) {
                        try {
                            $stmt = $pdo->prepare("SELECT set_id, title FROM sets WHERE user_id = ? ORDER BY generated_at DESC");
                            $stmt->execute([$_SESSION['user_id']]);
                            $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (!empty($sets)) {
                                foreach ($sets as $set) {
                                    echo '<a href="flashcard.php?set_id=' . $set['set_id'] . '" class="nav-modal-subitem">';
                                    echo htmlspecialchars($set['title']);
                                    echo '</a>';
                                }
                            } else {
                                echo '<div class="nav-modal-subitem-empty">No sets found</div>';
                            }
                        } catch (PDOException $e) {
                            echo '<div class="nav-modal-subitem-empty">Error loading sets</div>';
                        }
                    } else {
                        echo '<div class="nav-modal-subitem-empty">Please log in to view sets</div>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- Shared Sets section -->
            <div class="nav-modal-section">
                <div class="nav-modal-section-header" id="shared-toggle">
                    <i class="fa fa-share-alt"></i>
                    <span>Shared Sets</span>
                    <i class="fa fa-chevron-down toggle-icon"></i>
                </div>
                
                <div class="nav-modal-section-content" id="shared-content">
                    <?php 
                    if (isset($_SESSION['user_id'])) {
                        try {
                            $stmt = $pdo->prepare("
                                SELECT s.set_id, s.title, u.username as owner_name 
                                FROM sets s
                                JOIN shared_sets ss ON s.set_id = ss.set_id
                                JOIN users u ON ss.owner_id = u.id
                                WHERE ss.user_id = ? 
                                ORDER BY ss.shared_at DESC
                            ");
                            $stmt->execute([$_SESSION['user_id']]);
                            $sharedSets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (!empty($sharedSets)) {
                                foreach ($sharedSets as $set) {
                                    echo '<a href="flashcard.php?set_id=' . $set['set_id'] . '&shared=1" class="nav-modal-subitem shared">';
                                    echo htmlspecialchars($set['title']) . ' <span class="owner">by ' . htmlspecialchars($set['owner_name']) . '</span>';
                                    echo '</a>';
                                }
                            } else {
                                echo '<div class="nav-modal-subitem-empty">No shared sets found</div>';
                            }
                        } catch (PDOException $e) {
                            echo '<div class="nav-modal-subitem-empty">Error loading shared sets</div>';
                        }
                    } else {
                        echo '<div class="nav-modal-subitem-empty">Please log in to view shared sets</div>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="nav-modal-account" id="nav-modal-account">
                <img src="<?php 
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
</div>



    <script>

        
 document.addEventListener('DOMContentLoaded', function() {

    const editCardModal = document.getElementById('edit-card-modal');
    const editCardBtn = document.getElementById('edit-current-card-btn');
    const cancelEditCardBtn = document.getElementById('cancel-edit-card');
    const confirmEditCardBtn = document.getElementById('confirm-edit-card');
    const editCardIdInput = document.getElementById('edit-card-id');
    const editCardQuestionTextarea = document.getElementById('edit-card-question');
    const editCardAnswerTextarea = document.getElementById('edit-card-answer');
    const editCardErrorDiv = document.getElementById('edit-card-error');
    const flashcardsWrapper = document.querySelector('.flashcards-wrapper'); 

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

            editCardIdInput.value = cardId;
            editCardQuestionTextarea.value = question;
            editCardAnswerTextarea.value = answer;
            editCardErrorDiv.textContent = ''; 

            editCardModal.style.display = 'flex';
            editCardQuestionTextarea.focus(); 
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
            editCardErrorDiv.textContent = ''; 

            if (!newQuestion || !newAnswer) {
                editCardErrorDiv.textContent = 'Question and Answer cannot be empty.';
                return;
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'edit_card.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        if (response.success) {
                            const cardElement = flashcardsWrapper.querySelector(`.flashcard[data-card-id="${cardId}"]`);
                            if (cardElement) {
                                cardElement.querySelector('.flashcard-front p').textContent = newQuestion;
                                cardElement.querySelector('.flashcard-back p').textContent = newAnswer;
                            }

                            editCardModal.style.display = 'none';
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

});

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
            
            document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            
            this.classList.add('active');
            document.getElementById(targetFormId).classList.add('active');
        });
    });
    
    window.addEventListener('click', function(e) {
        if (e.target === authModal) {
            authModal.style.display = 'none';
        }
    });
    
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
            e.stopPropagation(); 
            
            currentSetId = this.dataset.setId;
            setTitleToDelete.textContent = this.dataset.setTitle;
            
            confirmationModal.style.display = 'flex';
        });
    });
    
    // Add event listeners to edit buttons
    document.querySelectorAll('.edit-set-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); 
            
            currentSetId = this.dataset.setId;
            
            newSetTitleInput.value = this.dataset.setTitle;
            
            editModal.style.display = 'flex';
            newSetTitleInput.focus();
            newSetTitleInput.select();
        });
    });
    
    cancelDelete.addEventListener('click', function() {
        confirmationModal.style.display = 'none';
    });
    
    confirmDelete.addEventListener('click', function() {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'delete_set.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status === 200) {
                window.location.reload();
            } else {
                alert('Error deleting set: ' + this.responseText);
            }
        };
        xhr.send('set_id=' + currentSetId);
        
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
                window.location.reload();
            } else {
                alert('Error updating set title: ' + this.responseText);
            }
        };
        xhr.send('set_id=' + currentSetId + '&title=' + encodeURIComponent(newTitle));
        
        editModal.style.display = 'none';
    });
    
    newSetTitleInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmEdit.click();
        }
    });
    
    window.addEventListener('click', function(e) {
        if (e.target === confirmationModal) {
            confirmationModal.style.display = 'none';
        }
        if (e.target === editModal) {
            editModal.style.display = 'none';
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Flashcard navigation
    const flashcards = document.querySelectorAll('.flashcard');
    const prevBtn = document.getElementById('prev-card');
    const nextBtn = document.getElementById('next-card');
    const cardCounter = document.getElementById('card-counter');
    
    if (flashcards.length === 0) return;
    
    let currentIndex = 0;
    const totalCards = flashcards.length;
    
    updateNavButtons();
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentIndex > 0) {
                showCard(currentIndex - 1);
            }
        });
    }
    
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

        flashcards[currentIndex].classList.remove('active');

        flashcards[currentIndex].classList.remove('flipped');
        
        currentIndex = index;
        flashcards[currentIndex].classList.add('active');
        
        cardCounter.textContent = `Card ${currentIndex + 1} of ${totalCards}`;
        
        updateNavButtons();
    }
    
    // Update navigation buttons state
    function updateNavButtons() {
        if (prevBtn && nextBtn) {
            prevBtn.disabled = currentIndex === 0;
            nextBtn.disabled = currentIndex === totalCards - 1;
        }
    } 
});

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
    
    if (flashcards.length === 0) return;
    
    let currentIndex = 0;
    const totalCards = flashcards.length;
    let cardsToReview = [];
    let reviewMode = false;
    
    updateNavButtons();
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentIndex > 0) {
                showCard(currentIndex - 1);
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (currentIndex < totalCards - 1) {
                showCard(currentIndex + 1);
            }
        });
    }
    
    flashcards.forEach(card => {
        card.addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
    });
    
    progressToggle.addEventListener('change', function() {
        reviewMode = this.checked;
        
        if (reviewMode) {
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
            reviewButtons.style.display = 'flex';

            cardsToReview = Array.from({ length: totalCards }, (_, i) => i);
            showCard(0);
        } else {

            prevBtn.style.display = 'block';
            nextBtn.style.display = 'block';
            reviewButtons.style.display = 'none';
            againContainer.style.display = 'none';

            showCard(currentIndex);
        }
    });
    
    // "I know this" button
    knowBtn.addEventListener('click', function() {
        // Remove current card from review list
        cardsToReview = cardsToReview.filter(index => index !== currentIndex);
        
        // Move to next card or show completion
        if (cardsToReview.length > 0) {
            const nextReviewIndex = cardsToReview.find(index => index > currentIndex);
            if (nextReviewIndex !== undefined) {
                showCard(nextReviewIndex);
            } else {
                showCard(cardsToReview[0]);
            }
        } else {

            showReviewComplete();
        }
    });
    
    dontKnowBtn.addEventListener('click', function() {
        if (cardsToReview.length > 1) {

            let nextReviewIndex;
            const currentPosition = cardsToReview.indexOf(currentIndex);
            
            if (currentPosition < cardsToReview.length - 1) {
                nextReviewIndex = cardsToReview[currentPosition + 1];
            } else {
                nextReviewIndex = cardsToReview[0]; 
            }
            
            showCard(nextReviewIndex);
        } else if (cardsToReview.length === 1) {

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
        cardsToReview = Array.from({ length: totalCards }, (_, i) => i);
        againContainer.style.display = 'none';
        reviewButtons.style.display = 'flex';
        showCard(0);
    });
    
    // Function to show a specific card
    function showCard(index) {

        flashcards[currentIndex].classList.remove('active');

        flashcards[currentIndex].classList.remove('flipped');
        
        currentIndex = index;
        flashcards[currentIndex].classList.add('active');
        
        if (reviewMode) {
            cardCounter.textContent = `Card ${cardsToReview.indexOf(currentIndex) + 1} of ${cardsToReview.length}`;
        } else {
            cardCounter.textContent = `Card ${currentIndex + 1} of ${totalCards}`;
        }
        
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
        const isTypingInInput = targetElement.tagName === 'INPUT' || targetElement.tagName === 'TEXTAREA';

        if (!isTypingInInput) {
            if (!reviewMode) {
                if (e.key === 'ArrowLeft' && prevBtn && !prevBtn.disabled) {
                    prevBtn.click();
                } else if (e.key === 'ArrowRight' && nextBtn && !nextBtn.disabled) {
                    nextBtn.click();
                }
            } else {

                if ((e.key === 'ArrowRight' || e.key === 'y' || e.key === 'Y') && knowBtn && knowBtn.offsetParent !== null) {
                    knowBtn.click(); // "I know this"
                } else if ((e.key === 'ArrowLeft' || e.key === 'n' || e.key === 'N') && dontKnowBtn && dontKnowBtn.offsetParent !== null) {
                    dontKnowBtn.click(); // "Still learning"
                }
            }
        }

        // Handle spacebar: Flip card ONLY if not typing in an input/textarea
        if ((e.key === ' ' || e.key === 'Spacebar') && !isTypingInInput) {
            const currentCard = document.querySelector('.flashcard.active');
            if (currentCard) {
                currentCard.classList.toggle('flipped');
            }
            e.preventDefault();
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {

    const flashcards = document.querySelectorAll('.flashcard');
    
    flashcards.forEach(card => {

        card.removeEventListener('click', flipCard);
        
        card.addEventListener('click', flipCard);
    });
    
    function flipCard(event) {

        if (event.target.closest('button') === null) {
            console.log('Card clicked');
            this.classList.toggle('flipped');
            console.log('Flipped class toggled:', this.classList.contains('flipped'));
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {

    const editCardBtn = document.getElementById('edit-current-card-btn');
    
    const deleteCardBtn = document.getElementById('delete-current-card-btn');
    const addCardBtn = document.getElementById('add-new-card-btn');
    
    if (deleteCardBtn) {
        deleteCardBtn.addEventListener('click', function(event) {
            event.stopPropagation(); 
            
            const activeCard = document.querySelector('.flashcard.active');
            if (!activeCard) {
                alert('No active card selected.');
                return;
            }
            
            const deleteCardModal = document.getElementById('delete-card-modal');
            deleteCardModal.style.display = 'flex';
            
            const cardId = activeCard.dataset.cardId;
            const cardIndex = parseInt(activeCard.dataset.index);
            
            const confirmDeleteCardBtn = document.getElementById('confirm-delete-card');
            const cancelDeleteCardBtn = document.getElementById('cancel-delete-card');
            
            const newConfirmBtn = confirmDeleteCardBtn.cloneNode(true);
            confirmDeleteCardBtn.parentNode.replaceChild(newConfirmBtn, confirmDeleteCardBtn);
            
            const newCancelBtn = cancelDeleteCardBtn.cloneNode(true);
            cancelDeleteCardBtn.parentNode.replaceChild(newCancelBtn, cancelDeleteCardBtn);
            
            newCancelBtn.addEventListener('click', function() {
                deleteCardModal.style.display = 'none';
            });
            
            newConfirmBtn.addEventListener('click', function() {

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'delete_card.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const response = JSON.parse(this.responseText);
                            
                            if (response.success) {
                                const flashcards = document.querySelectorAll('.flashcard');
                                const totalCards = flashcards.length;
                                
                                if (totalCards <= 1) {
                                    alert('Last card deleted. Returning to set view.');
                                    window.location.reload();
                                    return;
                                }
                                
                                activeCard.remove();
                                
                                document.querySelectorAll('.flashcard').forEach((card, idx) => {
                                    card.dataset.index = idx;
                                });
                                
                                const newTotalCards = totalCards - 1;
                                let newIndex = cardIndex;
                                if (newIndex >= newTotalCards) {
                                    newIndex = newTotalCards - 1;
                                }
                                
                                const nextCard = document.querySelector(`.flashcard[data-index="${newIndex}"]`);
                                if (nextCard) {
                                    nextCard.classList.add('active');
                                }
                                
                                const cardCounter = document.getElementById('card-counter');
                                if (cardCounter) {
                                    cardCounter.textContent = `Card ${newIndex + 1} of ${newTotalCards}`;
                                }
                                
                                const prevBtn = document.getElementById('prev-card');
                                const nextBtn = document.getElementById('next-card');
                                if (prevBtn) prevBtn.disabled = newIndex === 0;
                                if (nextBtn) nextBtn.disabled = newIndex === newTotalCards - 1;
                                
                                const totalCardsElement = document.querySelector('.flashcard-set-header p');
                                if (totalCardsElement) {
                                    totalCardsElement.textContent = `Total cards: ${newTotalCards}`;
                                }
                                
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
        event.stopPropagation(); 
        
        let setId;
        const urlParams = new URLSearchParams(window.location.search);
        setId = urlParams.get('set_id');
        
        if (!setId) {
            alert('Could not determine the current set ID.');
            return;
        }
        
        const addCardModal = document.getElementById('add-card-modal');
        const addCardSetIdInput = document.getElementById('add-card-set-id');
        const addCardQuestionTextarea = document.getElementById('add-card-question');
        const addCardAnswerTextarea = document.getElementById('add-card-answer');
        const addCardErrorDiv = document.getElementById('add-card-error');
        
        addCardSetIdInput.value = setId;
        addCardQuestionTextarea.value = '';
        addCardAnswerTextarea.value = '';
        addCardErrorDiv.textContent = '';
        
        addCardModal.style.display = 'flex';
        addCardQuestionTextarea.focus();
        
        const confirmAddCardBtn = document.getElementById('confirm-add-card');
        const cancelAddCardBtn = document.getElementById('cancel-add-card');
        
        const newConfirmBtn = confirmAddCardBtn.cloneNode(true);
        confirmAddCardBtn.parentNode.replaceChild(newConfirmBtn, confirmAddCardBtn);
        
        const newCancelBtn = cancelAddCardBtn.cloneNode(true);
        cancelAddCardBtn.parentNode.replaceChild(newCancelBtn, cancelAddCardBtn);
        
        newCancelBtn.addEventListener('click', function() {
            addCardModal.style.display = 'none';
        });
        
        newConfirmBtn.addEventListener('click', function() {
            const question = addCardQuestionTextarea.value.trim();
            const answer = addCardAnswerTextarea.value.trim();
            addCardErrorDiv.textContent = '';
            
            if (!question || !answer) {
                addCardErrorDiv.textContent = 'Question and Answer cannot be empty.';
                return;
            }
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'add_card.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (this.status === 201) { 
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.success) {
                            const newCard = response.card;
                            
                            const flashcardsWrapper = document.querySelector('.flashcards-wrapper');
                            const existingCards = document.querySelectorAll('.flashcard');
                            const totalCards = existingCards.length;
                            const newIndex = totalCards;
                            
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
                            
                            flashcardsWrapper.insertAdjacentHTML('beforeend', newCardHTML);
                            
                            const newCardElement = flashcardsWrapper.querySelector(`.flashcard[data-index="${newIndex}"]`);
                            newCardElement.addEventListener('click', function() {
                                this.classList.toggle('flipped');
                            });
                            
                            const newTotalCards = totalCards + 1;
                            const totalCardsElement = document.querySelector('.flashcard-set-header p');
                            if (totalCardsElement) {
                                totalCardsElement.textContent = `Total cards: ${newTotalCards}`;
                            }
                            
                            addCardModal.style.display = 'none';
                            
                            const prevBtn = document.getElementById('prev-card');
                            const nextBtn = document.getElementById('next-card');
                            const cardCounter = document.getElementById('card-counter');
                            
                            document.querySelectorAll('.flashcard').forEach(card => {
                                card.classList.remove('active');
                            });
                            
                            newCardElement.classList.add('active');
                            
                            if (cardCounter) {
                                cardCounter.textContent = `Card ${newIndex + 1} of ${newTotalCards}`;
                            }
                            
                            if (prevBtn) prevBtn.disabled = newIndex === 0;
                            if (nextBtn) nextBtn.disabled = newIndex === newTotalCards - 1;
                                                       
                            currentIndex = newIndex;
                                                      
                            if (prevBtn) {
                                const newPrevBtn = prevBtn.cloneNode(true);
                                prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
                                
                                newPrevBtn.addEventListener('click', function() {
                                    if (currentIndex > 0) {
                                        showCard(currentIndex - 1);
                                    }
                                });
                            }
                            
                            if (nextBtn) {
                                const newNextBtn = nextBtn.cloneNode(true);
                                nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
                                
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
            event.stopPropagation();
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
                
                const fileType = file.type;
                if (!fileType.match('image.*')) {
                    alert('Please select an image file');
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size should be less than 5MB');
                    return;
                }
                
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
                void authModal.offsetWidth;
                
                authModal.style.display = 'flex';
                
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
            
            document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            
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
        
        let friends = []; 
        let selectedFriends = []; 
        
        // Show share modal when share button is clicked
        if (shareSetBtn) {
            shareSetBtn.addEventListener('click', function() {

                selectedFriends = [];
                shareErrorDiv.textContent = '';
                shareSuccessDiv.textContent = '';
                updateSelectedCount();
                
                shareSetModal.style.display = 'flex';
                
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
                
                const urlParams = new URLSearchParams(window.location.search);
                const setId = urlParams.get('set_id');
                
                if (!setId) {
                    shareErrorDiv.textContent = 'Could not determine the current set ID.';
                    return;
                }
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'share_set.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const response = JSON.parse(this.responseText);
                            
                            if (response.success) {
                                shareSuccessDiv.textContent = response.message;
                                selectedFriends = [];
                                updateSelectedCount();
                                renderFriendsList(friends);
                                
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
                
                const friendIdsParam = selectedFriends.map(id => `friend_ids[]=${encodeURIComponent(id)}`).join('&');
                const params = `set_id=${encodeURIComponent(setId)}&${friendIdsParam}`;
                xhr.send(params);
            });
        }
        
        // Load friends from server
        function loadFriends(searchTerm = '') {

            friendsList.innerHTML = '<div class="loading-indicator"><i class="fa fa-spinner fa-spin"></i> Loading friends...</div>';

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
            
            document.querySelectorAll('.friend-item').forEach(item => {
                item.addEventListener('click', function() {
                    const friendId = this.dataset.friendId;
                    const checkbox = this.querySelector('.friend-checkbox');
                    
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
        
        function updateSelectedCount() {
            selectedFriendsCount.textContent = `${selectedFriends.length} friend${selectedFriends.length !== 1 ? 's' : ''} selected`;
        }
        
        shareSetModal.addEventListener('click', function(e) {
            if (e.target === shareSetModal) {
                shareSetModal.style.display = 'none';
            }
        });
    });

document.addEventListener('DOMContentLoaded', function() {

    const sharedSetsToggle = document.getElementById('shared-sets-toggle');
    const sharedSetsSection = document.getElementById('shared-sets-section');
    
    if (sharedSetsToggle && sharedSetsSection) {
        sharedSetsToggle.addEventListener('click', function(e) {
            e.preventDefault();
            

            if (sharedSetsSection.style.display === 'none' || sharedSetsSection.style.display === '') {
                sharedSetsSection.style.display = 'block';
            } else {
                sharedSetsSection.style.display = 'none';
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {

    const flashcards = document.querySelectorAll('.flashcard');
    
    flashcards.forEach(card => {

        const cardInner = card.querySelector('.flashcard-inner');
        
        if (cardInner) {

            cardInner.addEventListener('click', function(event) {

                if (!event.target.closest('button')) {
                    card.classList.toggle('flipped');

                    event.preventDefault();
                }
            });
        }
    });
    
    document.querySelectorAll('.card-actions button, .flashcard button').forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {

    const floatingMenuBtn = document.getElementById('floating-menu-btn');
    const navModal = document.getElementById('nav-modal');
    const closeNavModal = document.getElementById('close-nav-modal');
    const navModalAccount = document.getElementById('nav-modal-account');
    const authModal = document.getElementById('auth-modal');
    
    if (floatingMenuBtn) {
        floatingMenuBtn.addEventListener('click', function() {
            navModal.style.display = 'flex';
            document.body.style.overflow = 'hidden'; 
        });
    }
    
    if (closeNavModal) {
        closeNavModal.addEventListener('click', function() {
            navModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    if (navModal) {
        navModal.addEventListener('click', function(e) {
            if (e.target === navModal) {
                navModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    }
    
    if (navModalAccount) {
        navModalAccount.addEventListener('click', function() {
            navModal.style.display = 'none'; 
            if (authModal) {
                authModal.style.display = 'flex';
            }
        });
    }
    
});

document.addEventListener('DOMContentLoaded', function() {

    const libraryToggle = document.getElementById('library-toggle');
    const libraryContent = document.getElementById('library-content');
    const sharedToggle = document.getElementById('shared-toggle');
    const sharedContent = document.getElementById('shared-content');
    
    function toggleSection(header, content) {
        header.classList.toggle('active');
        content.classList.toggle('active');
    }
    
    if (libraryToggle && libraryContent) {
        libraryToggle.classList.add('active');
        libraryContent.classList.add('active');
        
        libraryToggle.addEventListener('click', function() {
            toggleSection(libraryToggle, libraryContent);
        });
    }
    
    if (sharedToggle && sharedContent) {
        sharedToggle.addEventListener('click', function() {
            toggleSection(sharedToggle, sharedContent);
        });
    }
});

    </script>
</body>
</html>
