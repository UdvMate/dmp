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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $hashedInput = base64_encode(hash('sha256', $password, true));
            
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

    $flashcardSets = [];
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM sets WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $flashcardSets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error loading flashcard sets: " . $e->getMessage());
        }
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
    // Insert into database
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $hashedPassword]);

    $_SESSION['user_id'] = $pdo->lastInsertId();
    $_SESSION['username'] = $username;
    $_SESSION['success'] = "Registration successful!";
    header("Location: welcome.php");
    exit();
} catch (PDOException $e) {
    // Handle duplicate entries or other errors
    if ($e->getCode() == '23000') {
        if (strpos($e->getMessage(), 'username') !== false) {
            $register_error = "Username already exists!";
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    const usernameInput = document.getElementById('reg_username');
                    const usernameValidationMessage = document.querySelector('.username-validation-message');
                    if (usernameInput) {
                        usernameInput.classList.add('invalid');
                        usernameInput.focus();
                    }
                    if (usernameValidationMessage) {
                        usernameValidationMessage.classList.add('visible');
                    }
                });
            </script>";
        } else {
            $register_error = "Email already exists!";
        }
    } else {
        $register_error = "Registration failed: " . $e->getMessage();
    }
}

    }
}

// View specific flashcard set
if (isset($_GET['set_id']) && is_numeric($_GET['set_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['set_id'], $_SESSION['user_id']]);
        $currentSet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentSet) {
            $stmt = $pdo->prepare("SELECT * FROM flashcards WHERE set_id = ?");
            $stmt->execute([$_GET['set_id']]);
            $_SESSION['current_flashcards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION['current_set'] = $currentSet;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error loading flashcard set: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = '';

    if (!empty($_FILES['message_file']['tmp_name'])) {
        $content = file_get_contents($_FILES['message_file']['tmp_name']);
    } elseif (!empty($_POST['message_text'])) {
        $content = $_POST['message_text'];
    }

    if (!empty($content)) {
        try {
            $rawResponse = generate_flashcards($content);
            $_SESSION['rawResponse'] = $rawResponse;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            die("Error: " . $e->getMessage());
        }
    }
}

$loadingMessageHtml = '
<div class="message-container" id="loading-message-container" style="display: none;">
    <div class="message bot-message">
        <div class="loading-content">
            <p id="loading-stage">Initializing...</p>
            <div class="loading-progress">
                <div id="loading-bar" class="loading-bar"></div>
            </div>
        </div>
    </div>
</div>';
    

function generate_flashcards($content) {
    $prompt = "Convert these notes into Q&A flashcards. Format strictly as: QQQ:questionAAA:answer" . $content;

    $ch = curl_init('https://api.perplexity.ai/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PERPLEXITY_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'sonar',
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]),
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception('API Error: ' . curl_error($ch));
    curl_close($ch);
    return $response;
}

function decode_unicode_escape_sequences($string) {
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
    }, $string);
}

function processStringToDict($contentText) {
    $contentText = decode_unicode_escape_sequences($contentText);

    $newlinePos = strpos($contentText, "\n");
    if ($newlinePos !== false) {
        $contentText = substr($contentText, 0, $newlinePos);
    }

    $contentText = str_replace(['**', '*','\n'], '', trim($contentText));

    $dict = [];
    $remaining = substr($contentText, strpos($contentText, 'QQQ:') + 4);
    $parts = preg_split('/(QQQ:|AAA:)/', $remaining, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $currentKey = null;
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === 'QQQ:') {
            $currentKey = null;
        } elseif ($part === 'AAA:') {
            continue;
        } else {
            if ($currentKey === null) {
                $currentKey = $part;
            } else {
                $dict[$currentKey] = $part;
                $currentKey = null;
            }
        }
    }

    if (!empty($dict)) {
        $lastKey = array_key_last($dict);
        $lastValue = $dict[$lastKey];
        $periodPos = strpos($lastValue, '.');
        if ($periodPos !== false) {
            $dict[$lastKey] = substr($lastValue, 0, $periodPos + 1);
        }
    }

    return $dict;
}

// Display results
if (isset($_SESSION['rawResponse'])) {
    try {
        $flashcards = processStringToDict($_SESSION['rawResponse']);
        $firstQuestion = array_key_first($flashcards);
        $setTitle = substr($firstQuestion, 0, 7);

        $stmt = $pdo->prepare("INSERT INTO sets (user_id, title, generated_at) VALUES (?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], $setTitle]);
        $setId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO flashcards (set_id, question, answer) VALUES (?, ?, ?)");
        
        foreach ($flashcards as $q => $a) {
            $stmt->execute([$setId, $q, $a]);
        }
        
        $_SESSION['last_created_set_id'] = $setId;
        $_SESSION['success'] = "Flashcards created!";
        
        unset($_SESSION['rawResponse']);
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
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
            echo '<div class="library-item"><span>Your sets will appear here</span></div>';
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
    
    /* Mobile styles */
    @media (max-width: 768px) {
        body {
            padding-top: 60px;
        }
        
        /* Mobile header */
        .mobile-header {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background-color: var(--secondary-color);
            z-index: 1000;
            align-items: center;
            justify-content: space-between;
            padding: 0 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .mobile-menu-btn {
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 22px;
            cursor: pointer;
        }
        
        .mobile-logo {
            display: flex;
            align-items: center;
        }
        
        .mobile-logo img {
            height: 30px;
            width: auto;
            margin-right: 10px;
        }
        
        /* Hide desktop sidebar by default on mobile */
        .sidebar {
            position: fixed;
            top: 60px;
            left: -280px;
            width: 280px;
            height: calc(100vh - 60px);
            z-index: 999;
            transition: left 0.3s ease;
            overflow-y: auto;
        }
        
        /* Show sidebar when active */
        .sidebar.mobile-active {
            left: 0;
        }
        
        /* Overlay for when sidebar is open */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 998;
        }
        
        .mobile-overlay.active {
            display: block;
        }
        
        /* Adjust main content */
        .main-content {
            margin-left: 0;
            width: 100%;
        }
    }
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

        

        .create-flashcards-btn {
            background: none;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            margin-left: auto;
            display: flex;
            align-items: center;
            font-size: 14px;
            transition: all 0.2s;
        }

        .create-flashcards-btn i {
            margin-right: 6px;
        }

        .create-flashcards-btn:hover {
            background-color: rgba(88, 166, 255, 0.1);
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

        .loader {
            border: 16px solid #f3f3f3;
            border-radius: 50%;
            border-top: 16px solid #3498db;
            width: 120px;
            height: 120px;
            -webkit-animation: spin 2s linear infinite; /* Safari */
            animation: spin 2s linear infinite;
        }

        /* Safari */
        @-webkit-keyframes spin {
          0% { -webkit-transform: rotate(0deg); }
          100% { -webkit-transform: rotate(360deg); }
        }

        @keyframes spin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
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
        .create-flashcards-btn, 
        a.create-flashcards-btn {
            background: none;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            margin-left: auto;
            display: flex;
            align-items: center;
            font-size: 14px;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .create-flashcards-btn i, 
        a.create-flashcards-btn i {
            margin-right: 6px;
        }
        
        .create-flashcards-btn:hover,
        a.create-flashcards-btn:hover {
            background-color: rgba(88, 166, 255, 0.1);
            text-decoration: none;
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
        

/* Make sure the message container uses flexbox */
.message-container {
    display: flex;
    flex-direction: column;
    margin-bottom: 20px;
    width: 100%;
}

// Add this to your existing CSS section
.loader-container {
    display: flex;
    justify-content: center;
    margin-bottom: 15px;
}

.loader {
    border: 3px solid var(--border-color);
    border-radius: 50%;
    border-top: 3px solid var(--accent-color);
    width: 30px;
    height: 30px;
    -webkit-animation: spin 1.5s linear infinite;
    animation: spin 1.5s linear infinite;
}

.loading-content {
    width: 100%;
}

.loading-progress {
    height: 4px;
    width: 100%;
    background-color: var(--border-color);
    border-radius: 2px;
    margin-top: 10px;
}

/* Update the loading-bar CSS to ensure transitions work properly */
.loading-bar {
    height: 100%;
    width: 0%; /* Start at 0% */
    background-color: var(--accent-color);
    border-radius: 4px;
    transition: width 0.5s ease-in-out !important; /* Ensure transition is applied */
    will-change: width; /* Optimize for animations */
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
        padding-top: 0; 
    }
    
    /* Adjust main content positioning */
    .main-content {
        margin-top: 60px;
        width: 100%;
        position: relative;
        z-index: 1;
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
        padding-top: 15px;
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
        padding-bottom: 70px;
    }

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

/* Add these styles to your existing CSS */
.main-container {
    display: flex;
    flex-direction: row;
    gap: 30px;
    height: 100%;
    padding: 20px;
}

.upload-container {
    flex: 3;
    display: flex;
    flex-direction: column;
}

.video-container {
    flex: 2;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drag-drop-area {
    background-color: var(--secondary-color);
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    padding: 30px;
    text-align: center;
    transition: all 0.3s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.drag-drop-area.active {
    border-color: var(--accent-color);
    background-color: rgba(88, 166, 255, 0.05);
}

.drag-drop-area i {
    font-size: 48px;
    color: var(--accent-color);
    margin-bottom: 15px;
}

.drag-drop-area h2 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--text-color);
}

.instructions {
    color: #8b949e;
    margin-bottom: 20px;
    font-size: 14px;
}

#notes-input {
    width: 100%;
    min-height: 150px;
    background-color: var(--primary-color);
    border: 1px solid var(--border-color);
    border-radius: 5px;
    padding: 12px;
    color: var(--text-color);
    font-size: 14px;
    resize: vertical;
    margin-bottom: 20px;
}

.upload-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.action-button {
    background: none;
    border: 1px solid var(--border-color);
    color: var(--text-color);
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    transition: all 0.2s;
}

.action-button i {
    margin-right: 8px;
}

.action-button:hover {
    background-color: var(--hover-color);
}

.primary-button {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
    color: #fff;
}

.primary-button:hover {
    background-color: #4a8ede;
}

.video-placeholder {
    background-color: var(--secondary-color);
    border-radius: 10px;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #8b949e;
}

.video-placeholder i {
    font-size: 48px;
    margin-bottom: 15px;
}

.success-message {
    background-color: rgba(86, 211, 100, 0.1);
    border: 1px solid var(--success-color);
    border-radius: 5px;
    padding: 15px;
    margin-top: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.view-flashcards-btn {
    background-color: var(--success-color);
    color: var(--primary-color);
    padding: 8px 12px;
    border-radius: 4px;
    text-decoration: none;
    display: flex;
    align-items: center;
}

.view-flashcards-btn i {
    margin-right: 5px;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(13, 17, 23, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.loading-content {
    background-color: var(--secondary-color);
    border-radius: 8px;
    padding: 20px;
    width: 80%;
    max-width: 400px;
    text-align: center;
}

.loading-progress {
    height: 6px;
    width: 100%;
    background-color: var(--border-color);
    border-radius: 3px;
    margin-top: 15px;
    overflow: hidden;
}

.loading-bar {
    height: 100%;
    width: 0%;
    background-color: var(--accent-color);
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .main-container {
        flex-direction: column;
    }
    
    .video-container {
        height: 200px;
    }
}

.main-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    max-width: 900px;
    margin: 0 auto;
}

.upload-section {
    width: 100%;
    text-align: center;
}

.main-heading {
    font-size: 28px;
    font-weight: bold;
    color: var(--text-color);
    margin-bottom: 10px;
}

.sub-heading {
    font-size: 16px;
    color: #8b949e;
    margin-bottom: 30px;
}

.content-layout {
    display: flex;
    gap: 30px;
    align-items: center;
    justify-content: center;
}

.video-container {
    flex: 1;
    max-width: 300px;
}

.upload-container {
    flex: 1;
    max-width: 350px;
}

.video-placeholder {
    background-color: var(--secondary-color);
    border-radius: 10px;
    width: 100%;
    height: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #8b949e;
}

.video-placeholder i {
    font-size: 48px;
    margin-bottom: 15px;
}

.drag-drop-area {
    background-color: var(--secondary-color);
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    padding: 25px;
    text-align: center;
    transition: all 0.3s ease;
    height: 200px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.drag-drop-area.active {
    border-color: var(--accent-color);
    background-color: rgba(88, 166, 255, 0.05);
}

.drag-drop-area i {
    font-size: 36px;
    color: var(--accent-color);
    margin-bottom: 15px;
}

.drop-instructions {
    color: #8b949e;
    margin-bottom: 15px;
    font-size: 14px;
}

.action-button {
    background: none;
    border: 1px solid var(--border-color);
    color: var(--text-color);
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    transition: all 0.2s;
}

.action-button i {
    margin-right: 8px;
}

.action-button:hover {
    background-color: var(--hover-color);
}

.success-message {
    background-color: rgba(86, 211, 100, 0.1);
    border: 1px solid var(--success-color);
    border-radius: 5px;
    padding: 15px;
    margin-top: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.view-flashcards-btn {
    background-color: var(--success-color);
    color: var(--primary-color);
    padding: 8px 12px;
    border-radius: 4px;
    text-decoration: none;
    display: flex;
    align-items: center;
}

.view-flashcards-btn i {
    margin-right: 5px;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(13, 17, 23, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.loading-content {
    background-color: var(--secondary-color);
    border-radius: 8px;
    padding: 20px;
    width: 80%;
    max-width: 400px;
    text-align: center;
}

.loading-progress {
    height: 6px;
    width: 100%;
    background-color: var(--border-color);
    border-radius: 3px;
    margin-top: 15px;
    overflow: hidden;
}

.loading-bar {
    height: 100%;
    width: 0%;
    background-color: var(--accent-color);
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .content-layout {
        flex-direction: column;
    }
    
    .video-container, .upload-container {
        max-width: 100%;
    }
}

.password-requirements {
    margin-top: 8px;
    font-size: 12px;
    background-color: var(--primary-color);
    padding: 8px;
    border-radius: 4px;
}

.password-requirements p {
    margin-bottom: 5px;
    color: var(--text-color);
}

.requirement {
    margin-bottom: 3px;
    color: var(--error-color);
    transition: color 0.2s;
}

.requirement.valid {
    color: var(--success-color);
}

.requirement i {
    margin-right: 5px;
}

.requirement.valid i.fa-times-circle {
    display: none;
}

.requirement.valid i.fa-check-circle {
    display: inline;
}

.requirement i.fa-check-circle {
    display: none;
}

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


.nav-item i {
    min-width: 24px;
    text-align: center;
    margin-right: 10px;
    font-size: 18px;
}


#shared-sets-toggle i {
    min-width: 24px;
    text-align: center;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(13, 17, 23, 0.9);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.loading-content {
    background-color: var(--secondary-color);
    border-radius: 8px;
    padding: 25px;
    width: 90%;
    max-width: 400px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.loading-content h3 {
    margin-bottom: 15px;
    color: var(--text-color);
}

#loading-stage {
    margin-bottom: 20px;
    color: var(--accent-color);
    font-size: 16px;
}

.loading-progress {
    height: 8px;
    width: 100%;
    background-color: var(--border-color);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 15px;
}

.loading-bar {
    height: 100%;
    width: 0%;
    background-color: var(--accent-color);
    border-radius: 4px;
    transition: width 0.5s ease;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(13, 17, 23, 0.9);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.loading-content {
    background-color: var(--secondary-color);
    border-radius: 8px;
    padding: 30px;
    width: 200px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
    align-items: center;
}

.loading-content p {
    margin-top: 20px;
    color: var(--text-color);
    font-size: 16px;
}

/* Loading spinner animation */
.loading-spinner {
    width: 60px;
    height: 60px;
    border: 5px solid var(--border-color);
    border-radius: 50%;
    border-top-color: var(--accent-color);
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

input#reg_username.invalid {
    border-color: var(--error-color);
}

.username-validation-message.visible {
    display: block !important;
}

.password-input-container {
    position: relative;
    display: flex;
    align-items: center;
}

.password-input-container input {
    flex: 1;
    padding-right: 40px;
}

.toggle-password {
    position: absolute;
    right: 5px;
    background: none;
    border: none;
    color: var(--text-color);
    cursor: pointer;
    padding: 5px 10px;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toggle-password:hover {
    opacity: 1;
}
/* Video styling */
.video-container {
    flex: 1;
    max-width: 300px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.video-container video {
    width: 100%;
    height: auto;
    display: block;
    background-color: var(--secondary-color);
    border-radius: 10px;
}
/* Video play button overlay */
.video-container {
    position: relative;
}

.video-play-button {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    background-color: rgba(0, 0, 0, 0.3);
    cursor: pointer;
    border-radius: 10px;
}

.video-play-button i {
    font-size: 48px;
    color: white;
    opacity: 0.9;
}

.video-play-button:hover i {
    opacity: 1;
    transform: scale(1.1);
    transition: all 0.2s ease;
}
.video-container {
    flex: 1;
    max-width: 400px; 
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    position: relative;
    /* Add glow effect */
    filter: drop-shadow(0 0 15px rgba(88, 166, 255, 0.4));
    transition: all 0.3s ease;
}

/* Add hover effect to enhance the glow */
.video-container:hover {
    filter: drop-shadow(0 0 20px rgba(88, 166, 255, 0.6));
    transform: translateY(-3px);
}

.video-container video {
    width: 100%;
    height: auto;
    display: block;
    background-color: var(--secondary-color);
    border-radius: 10px;
}

/* Enhance the play button with glow */
.video-play-button i {
    font-size: 54px; 
    color: white;
    opacity: 0.9;
    text-shadow: 0 0 15px rgba(255, 255, 255, 0.7);
    transition: all 0.3s ease;
}

.video-play-button:hover i {
    opacity: 1;
    transform: scale(1.1);
    text-shadow: 0 0 20px rgba(255, 255, 255, 0.9);
}
.content-layout {
    display: flex;
    gap: 40px;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap; 
    margin: 30px 0;
}

.upload-container {
    flex: 1;
    max-width: 400px;
}

.drag-drop-area {
    height: 225px; 
}

/* Responsive adjustments */
@media (max-width: 900px) {
    .content-layout {
        flex-direction: column;
    }
    
    .video-container, .upload-container {
        max-width: 100%;
        width: 100%;
    }
}
.mobile-navbar {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 60px;
    background-color: var(--secondary-color);
    z-index: 1000;
    padding: 0 15px;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border-color);
}

.mobile-menu-btn {
    background: none;
    border: none;
    color: var(--text-color);
    font-size: 20px;
    cursor: pointer;
    padding: 8px;
}

.mobile-logo {
    display: flex;
    align-items: center;
}

.mobile-logo a {
    display: flex;
    align-items: center;
    text-decoration: none;
    color: var(--text-color);
}

.mobile-logo img {
    width: 24px;
    height: 24px;
    margin-right: 8px;
}

.mobile-account {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    cursor: pointer;
}

.mobile-account img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: -250px;
        width: 250px;
        height: 100vh;
        z-index: 1001;
        transition: left 0.3s ease;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
    }
    
    .sidebar.open {
        left: 0;
    }
    
    .mobile-navbar {
        display: flex;
    }
    
    .main-content {
        margin-top: 60px;
        width: 100%;
    }
    
    .content-area {
        padding: 15px;
    }
    
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
    }
    
    .sidebar-overlay.active {
        display: block;
    }
    
    .sidebar-content {
        max-height: calc(100vh - 120px);
        overflow-y: auto;
    }
}
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

/* Hide the mobile navbar */
.mobile-navbar {
    display: none !important;
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


    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="sidebar" id="sidebar">
    <div class="sidebar-top">
        
        <div class="logo">
            <a href="welcome.php">    
                <img src="media/images/icon2.png" alt="Logo">
            </a>
            <span class="logo-text">Flashcard.ai</span>
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

<div class="floating-menu-btn" id="floating-menu-btn">
    <i class="fa fa-bars"></i>
</div>

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




    <!-- Main Content -->
    <div class="main-content">
        <div class="content-area" id="content-area">
            <div class="main-container">
                <div class="upload-section">
                    <h1 class="main-heading">Turn your notes into flashcards with Flashcard.ai</h1>
                    <p class="sub-heading">Upload your study materials and we'll automatically generate flashcards to help you learn</p>

                    <div class="content-layout">
                        <div class="video-container">
                            <video id="demo-video" controls poster="media/images/thumbnailll.png">
                                <source src="media/videos/tut.mp4" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>


                        <div class="upload-container">
                            <div class="drag-drop-area" id="drag-drop-area">
                                <i class="fa fa-cloud-upload-alt"></i>
                                <p class="drop-instructions">Drag & drop a text file here</p>
                                <form id="notes-form" method="POST" action="" enctype="multipart/form-data">
                                    <input type="file" id="file-upload" name="message_file" accept=".txt" style="display: none;">
                                    <button type="button" id="browse-btn" class="action-button">
                                        <i class="fa fa-file-alt"></i> Browse Files
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="success-message">
                            <?php if ($_SESSION['success'] === "Flashcards created!" && isset($_SESSION['last_created_set_id'])): ?>
                                <p><?php echo $_SESSION['success']; ?></p>
                                <a href="flashcard.php?set_id=<?php echo $_SESSION['last_created_set_id']; ?>" class="view-flashcards-btn">
                                    <i class="fa fa-eye"></i> View Flashcards
                                </a>
                            <?php else: ?>
                                <p><?php echo $_SESSION['success']; ?></p>
                            <?php endif; ?>
                        </div>
                        <?php 
                        unset($_SESSION['success']); 
                        if (isset($_SESSION['last_created_set_id'])) {
                            unset($_SESSION['last_created_set_id']);
                        }
                        ?>
                    <?php endif; ?>
                </div>
            </div>
                    
            <?php echo $loadingMessageHtml; ?>
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
                    <div class="password-input-container">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="toggle-password" aria-label="Toggle password visibility">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
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
    <div class="username-validation-message" style="display: none; color: var(--error-color); font-size: 12px; margin-top: 5px;">
        <i class="fa fa-exclamation-circle"></i> Username already exists
    </div>
</div>

                <div class="form-group">
                    <label for="reg_email">Email</label>
                    <input type="email" id="reg_email" name="reg_email" required>
                </div>
                <div class="form-group">
    <label for="reg_password">Password</label>
    <div class="password-input-container">
        <input type="password" id="reg_password" name="reg_password" required>
        <button type="button" class="toggle-password" aria-label="Toggle password visibility">
            <i class="fa fa-eye"></i>
        </button>
    </div>
    <div class="password-requirements">
        <p>Password must contain:</p>
        <div class="requirement" id="length-req"><i class="fa fa-times-circle"></i> At least 8 characters</div>
        <div class="requirement" id="capital-req"><i class="fa fa-times-circle"></i> At least one capital letter</div>
        <div class="requirement" id="number-req"><i class="fa fa-times-circle"></i> At least one number</div>
    </div>
</div>

<div class="form-group">
    <label for="reg_passwordConfirm">Confirm Password</label>
    <div class="password-input-container">
        <input type="password" id="reg_passwordConfirm" name="reg_passwordConfirm" required>
        <button type="button" class="toggle-password" aria-label="Toggle password visibility">
            <i class="fa fa-eye"></i>
        </button>
    </div>
</div>
                <?php if (isset($register_error)): ?>
                    <div class="error-message"><?php echo $register_error; ?></div>
                <?php endif; ?>
                <button type="submit" name="register_submit" class="auth-btn">Register</button>
            </form>
        <?php endif; ?>
    </div>
</div>


    <div id="context-menu" class="context-menu" style="display: none; position: absolute; z-index: 1000; background-color: var(--secondary-color); border: 1px solid var(--border-color); border-radius: 4px; padding: 5px 0;">
    <div class="context-menu-item" id="delete-set" style="padding: 8px 12px; cursor: pointer; color: var(--error-color);">
        <i class="fa fa-trash"></i> Delete Set
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
<div class="loading-overlay" id="loading-overlay" style="display: none;">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <p>Generating flashcards...</p>
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
            
            const messageInput = document.getElementById('message-input');
            
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
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
    // Delete set functionality
    document.querySelectorAll('.delete-set-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); 
            
            currentSetId = this.dataset.setId;
            setTitleToDelete.textContent = this.dataset.setTitle;
            
            confirmationModal.style.display = 'flex';
        });
    });
    
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
    
    // Cancel deletion
    cancelDelete.addEventListener('click', function() {
        confirmationModal.style.display = 'none';
    });
    
    // Confirm deletion
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

// Debug modal structure
document.addEventListener('DOMContentLoaded', function() {
    const authModal = document.getElementById('auth-modal');
    if (authModal) {
        console.log('Auth modal found in DOM');
        console.log('Auth modal children:', authModal.children.length);
        
        const authContainer = authModal.querySelector('.auth-container');
        if (authContainer) {
            console.log('Auth container found');
            console.log('Auth container children:', authContainer.children.length);
        } else {
            console.error('Auth container not found inside modal');
        }
    } else {
        console.error('Auth modal not found in DOM');
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
                
                console.log('Auth modal opened');
                console.log('Modal style:', window.getComputedStyle(authModal).display);
                
                setTimeout(function() {
                    authModal.style.opacity = '1';
                }, 10);
            } else {
                console.error('Auth modal element not found');
            }
        });
    } else {
        console.error('Account button element not found');
    }
    
    if (closeAuthModal) {
        closeAuthModal.addEventListener('click', function() {
            authModal.style.display = 'none';
        });
    }
    
    window.addEventListener('click', function(e) {
        if (e.target === authModal) {
            authModal.style.display = 'none';
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const authTabs = document.querySelectorAll('.auth-tab');
    const authForms = document.querySelectorAll('.auth-form');
    
    if (authTabs.length > 0) {
        authTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const targetFormId = this.getAttribute('data-form');
                
                authTabs.forEach(t => t.classList.remove('active'));
                authForms.forEach(f => f.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(targetFormId).classList.add('active');
            });
        });
    }
});
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggle-sidebar');
    const toggleIcon = toggleBtn.querySelector('i');
    
    function handleMobileView() {
        if (window.innerWidth <= 450) {
            sidebar.style.width = '';
            
            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                
                sidebar.classList.toggle('expanded');
                
                if (sidebar.classList.contains('expanded')) {
                    toggleIcon.classList.remove('fa-chevron-right');
                    toggleIcon.classList.add('fa-chevron-left');
                } else {
                    toggleIcon.classList.remove('fa-chevron-left');
                    toggleIcon.classList.add('fa-chevron-right');
                }
                
                void sidebar.offsetWidth;
            });
            
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && sidebar.classList.contains('expanded')) {
                    sidebar.classList.remove('expanded');
                    toggleIcon.classList.remove('fa-chevron-left');
                    toggleIcon.classList.add('fa-chevron-right');
                }
            });
            
            sidebar.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        } else {
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            } else {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        }
    }
    
    handleMobileView();
    
    // Run on resize with debounce
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(handleMobileView, 250);
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const dragDropArea = document.getElementById('drag-drop-area');
    const fileUpload = document.getElementById('file-upload');
    const browseBtn = document.getElementById('browse-btn');
    const notesForm = document.getElementById('notes-form');
    const loadingOverlay = document.getElementById('loading-overlay');
    const loadingStage = document.getElementById('loading-stage');
    const loadingBar = document.getElementById('loading-bar');
    const authModal = document.getElementById('auth-modal');
    
    // Check if user is logged in
    const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
    
    // Browse button functionality
    browseBtn.addEventListener('click', function(e) {
        if (!isLoggedIn) {
            e.preventDefault();
            alert('Please log in to upload files and create flashcards.');
            if (authModal) {
                authModal.style.display = 'flex';
            }
            return;
        }
        fileUpload.click();
    });
    
    // File upload change event
    fileUpload.addEventListener('change', function() {
        if (!isLoggedIn) {
            alert('Please log in to upload files and create flashcards.');
            this.value = '';
            if (authModal) {
                authModal.style.display = 'flex';
            }
            return;
        }
        
        if (this.files && this.files[0]) {
            const file = this.files[0];
            if (file.type !== 'text/plain') {
                alert('Please select a text (.txt) file');
                this.value = '';
                return;
            }
            
            notesForm.submit();
            showLoading();
        }
    });
    
    // Drag and drop functionality
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dragDropArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        dragDropArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dragDropArea.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        if (isLoggedIn) {
            dragDropArea.classList.add('active');
        }
    }
    
    function unhighlight() {
        dragDropArea.classList.remove('active');
    }
    
    dragDropArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        if (!isLoggedIn) {
            alert('Please log in to upload files and create flashcards.');
            if (authModal) {
                authModal.style.display = 'flex';
            }
            return;
        }
        
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length) {
            const file = files[0];
            if (file.type !== 'text/plain') {
                alert('Please drop a text (.txt) file');
                return;
            }
            
            fileUpload.files = files;
            notesForm.submit();
            showLoading();
        }
    }
    
    function showLoading() {
        loadingOverlay.style.display = 'flex';
        
        updateLoadingStage('Processing your notes...', 20);
        
        setTimeout(() => {
            updateLoadingStage('Analyzing content...', 40);
            
            setTimeout(() => {
                updateLoadingStage('Generating flashcards...', 70);
                
                setTimeout(() => {
                    updateLoadingStage('Finalizing...', 90);
                }, 1500);
            }, 1500);
        }, 1000);
    }
    
    function updateLoadingStage(text, progress) {
        loadingStage.textContent = text;
        loadingBar.style.width = progress + '%';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Password validation
    const passwordInput = document.getElementById('reg_password');
    const lengthReq = document.getElementById('length-req');
    const capitalReq = document.getElementById('capital-req');
    const numberReq = document.getElementById('number-req');
    const registerBtn = document.querySelector('button[name="register_submit"]');
    
    if (passwordInput) {
        passwordInput.addEventListener('input', validatePassword);
        
        document.querySelectorAll('.requirement').forEach(req => {
            const icon = req.querySelector('i');
            if (icon) {
                const checkIcon = document.createElement('i');
                checkIcon.className = 'fa fa-check-circle';
                req.insertBefore(checkIcon, icon.nextSibling);
            }
        });
    }
    
    function validatePassword() {
        const password = passwordInput.value;
        
        if (password.length >= 8) {
            lengthReq.classList.add('valid');
        } else {
            lengthReq.classList.remove('valid');
        }
        
        if (/[A-Z]/.test(password)) {
            capitalReq.classList.add('valid');
        } else {
            capitalReq.classList.remove('valid');
        }
        
        if (/[0-9]/.test(password)) {
            numberReq.classList.add('valid');
        } else {
            numberReq.classList.remove('valid');
        }
        
        if (password.length >= 8 && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
            registerBtn.disabled = false;
        } else {
            registerBtn.disabled = true;
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Shared sets toggle functionality
    const sharedSetsToggle = document.getElementById('shared-sets-toggle');
    const sharedSetsSection = document.getElementById('shared-sets-section');
    
    if (sharedSetsToggle && sharedSetsSection) {
        sharedSetsToggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (sharedSetsSection.style.display === 'block') {
                sharedSetsSection.style.display = 'none';
            } else {
                sharedSetsSection.style.display = 'block';
            }
            
            this.classList.toggle('active');
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const fileUpload = document.getElementById('file-upload');
    const notesForm = document.getElementById('notes-form');
    const loadingOverlay = document.getElementById('loading-overlay');
    const loadingStage = document.getElementById('loading-stage');
    const loadingBar = document.getElementById('loading-bar');
    
    // File upload change event
    if (fileUpload) {
        fileUpload.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                if (file.type !== 'text/plain') {
                    alert('Please select a text (.txt) file');
                    this.value = '';
                    return;
                }
                
                showLoadingProgress();
                
                setTimeout(() => {
                    notesForm.submit();
                }, 100);
            }
        });
    }
    
    const dragDropArea = document.getElementById('drag-drop-area');
    if (dragDropArea) {
        dragDropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length) {
                const file = files[0];
                if (file.type !== 'text/plain') {
                    alert('Please drop a text (.txt) file');
                    return;
                }
                
                fileUpload.files = files;
                
                showLoadingProgress();
                
                setTimeout(() => {
                    notesForm.submit();
                }, 100);
            }
            
            this.classList.remove('active');
        });
    }
    
function showLoadingProgress() {
    loadingOverlay.style.display = 'flex';
    
    setTimeout(() => {
        notesForm.submit();
    }, 100);
}  
});

document.addEventListener('DOMContentLoaded', function() {
    // Username availability check
    const usernameInput = document.getElementById('reg_username');
    const usernameValidationMessage = document.querySelector('.username-validation-message');
    let usernameCheckTimeout;
    
    if (usernameInput) {
        usernameInput.addEventListener('input', function() {
            clearTimeout(usernameCheckTimeout);
            
            const username = this.value.trim();
            
            this.classList.remove('invalid');
            usernameValidationMessage.classList.remove('visible');
            
            if (!username) return;
            
            usernameCheckTimeout = setTimeout(function() {
                checkUsernameAvailability(username);
            }, 500);
        });
    }
    
    function checkUsernameAvailability(username) {
        const formData = new FormData();
        formData.append('username', username);    
        fetch('check_username.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                usernameInput.classList.add('invalid');
                usernameValidationMessage.classList.add('visible');
                
                // Disable register button if username exists
                const registerBtn = document.querySelector('button[name="register_submit"]');
                if (registerBtn) {
                    registerBtn.disabled = true;
                }
            } else {
                // Username is available
                usernameInput.classList.remove('invalid');
                usernameValidationMessage.classList.remove('visible');
                
                const registerBtn = document.querySelector('button[name="register_submit"]');
                if (registerBtn) {
                    const passwordInput = document.getElementById('reg_password');
                    if (passwordInput && passwordInput.value.length >= 8 && 
                        /[A-Z]/.test(passwordInput.value) && 
                        /[0-9]/.test(passwordInput.value)) {
                        registerBtn.disabled = false;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error checking username:', error);
        });
    }
});
document.addEventListener('DOMContentLoaded', function() {
    // Password toggle functionality for all password fields
    const togglePasswordButtons = document.querySelectorAll('.toggle-password');
    
    togglePasswordButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();        
            const passwordInput = this.previousElementSibling;
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const video = document.getElementById('demo-video');
    
    if (video) {
        const videoContainer = video.parentElement;
        const playButton = document.createElement('div');
        playButton.className = 'video-play-button';
        playButton.innerHTML = '<i class="fa fa-play"></i>';
        videoContainer.appendChild(playButton);
        
        playButton.addEventListener('click', function() {
            video.play();
            playButton.style.display = 'none';
        });
        
        video.addEventListener('pause', function() {
            playButton.style.display = 'flex';
        });
        
        video.addEventListener('play', function() {
            playButton.style.display = 'none';
        });
    }
});
// Floating Menu Functionality
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
