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

    $flashcardSets = [];
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM sets WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $flashcardSets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Silently log error but continue
            error_log("Error loading flashcard sets: " . $e->getMessage());
        }
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

// Load user flashcard sets for sidebar


// View specific flashcard set
if (isset($_GET['set_id']) && is_numeric($_GET['set_id'])) {
    try {
        // Verify the set belongs to the user
        $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ? AND user_id = ?");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = '';

    if (!empty($_FILES['message_file']['tmp_name'])) {//get notes from message box
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
            <div class="loader-container">
                <div class="loader"></div>
            </div>
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

// Decode Unicode escape sequences in JSON response
function decode_unicode_escape_sequences($string) {
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
    }, $string);
}

// Process API response into dictionary format with proper decoding
function processStringToDict($contentText) {
    // Decode Unicode characters
    $contentText = decode_unicode_escape_sequences($contentText);

    // Remove everything after first newline
    $newlinePos = strpos($contentText, "\n");
    if ($newlinePos !== false) {
        $contentText = substr($contentText, 0, $newlinePos);
    }

    // Remove all asterisks and trim
    $contentText = str_replace(['**', '*','\n'], '', trim($contentText));

    // Split into dictionary
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

    // Process last value to end at first period
    if (!empty($dict)) {
        $lastKey = array_key_last($dict);
        $lastValue = $dict[$lastKey];
        $periodPos = strpos($lastValue, '.');
        if ($periodPos !== false) {
            $dict[$lastKey] = substr($lastValue, 0, $periodPos + 1); // Include the period
        }
    }

    return $dict;
}

// Display results
if (isset($_SESSION['rawResponse'])) {
    try {
        // Decode and process the raw response into flashcards dictionary
        $flashcards = processStringToDict($_SESSION['rawResponse']);
        $firstQuestion = array_key_first($flashcards);
        $setTitle = substr($firstQuestion, 0, 7); // Get first 7 characters of the first question

        $stmt = $pdo->prepare("INSERT INTO sets (user_id, title, generated_at) VALUES (?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], $setTitle]);
        $setId = $pdo->lastInsertId(); // Get the ID of the newly created set
        $stmt = $pdo->prepare("INSERT INTO flashcards (set_id, question, answer) VALUES (?, ?, ?)");
        
        foreach ($flashcards as $q => $a) {
            $stmt->execute([$setId, $q, $a]);
        }
        
        // Store the set ID in a session variable for the success message
        $_SESSION['last_created_set_id'] = $setId;
        $_SESSION['success'] = "Flashcards created!";
        
        unset($_SESSION['rawResponse']);
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
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

        .message-container {
            margin-bottom: 20px;
        }

        .message {
            background-color: var(--secondary-color);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 10px;
            max-width: 80%;
        }

        .user-message {
            margin-left: auto;
            background-color: #304054;
        }

        .bot-message {
            margin-right: auto;
            background-color: var(--secondary-color);
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

        /* Input area styles */
        .input-area {
            padding: 16px;
            background-color: var(--secondary-color);
            border-top: 1px solid var(--border-color);
            position: relative;
        }

        .message-form {
            display: flex;
            align-items: center;
            background-color: var(--primary-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            padding: 8px 12px;
        }

        .message-input {
            flex: 1;
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 14px;
            padding: 8px;
            outline: none;
            resize: none;
            min-height: 20px;
            max-height: 150px;
        }

        .input-buttons {
            display: flex;
            align-items: center;
        }

        .file-upload {
            position: relative;
            margin-right: 8px;
        }

        .file-upload input {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-btn, .send-btn {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            font-size: 16px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .upload-btn:hover, .send-btn:hover {
            background-color: var(--hover-color);
        }

        /* For collapsible sidebar content */
        .sidebar.collapsed .logo span,
        .sidebar.collapsed .nav-item span,
        .sidebar.collapsed .account span,
        .sidebar.collapsed .library-item {
            display: none;
        }

        /* File name display */
        .file-name {
            display: none;
            font-size: 12px;
            color: #8b949e;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
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

        .message-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 8px;
        }

        .message-action-btn {
            background: none;
            border: none;
            color: #8b949e;
            font-size: 14px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .message-action-btn:hover {
            background-color: var(--hover-color);
            color: var(--text-color);
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
        /* Add to your CSS */
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }

        .typing::after {
            content: '|';
            margin-left: 2px;
            animation: blink 1s infinite;
        }

        .quick-questions {
            display: flex;
            gap: 10px;
            padding: 10px 16px;
            background-color: var(--secondary-color);
            border-top: 1px solid var(--border-color);
            overflow-x: auto;
        }

        .quick-question-btn {
            background: none;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            font-size: 14px;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .quick-question-btn i {
            margin-right: 6px;
        }

        .quick-question-btn:hover {
            background-color: rgba(88, 166, 255, 0.1);
        }

        /* Make sure horizontal scrolling works smoothly */
        .quick-questions::-webkit-scrollbar {
            height: 4px;
        }

        .quick-questions::-webkit-scrollbar-thumb {
            background: #3b4351;
            border-radius: 4px;
        }
        .user-message {
    margin-left: auto;
    margin-right: 0;
    background-color: #304054;
    align-self: flex-end;
    text-align: right;
}

.bot-message {
    margin-right: auto;
    margin-left: 0;
    background-color: var(--secondary-color);
    align-self: flex-start;
    text-align: left;
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

.loading-bar {
    height: 100%;
    width: 0%;
    background-color: var(--accent-color);
    border-radius: 2px;
    transition: width 0.5s ease;
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
            <a href="connect.php" class="nav-item">
                <i class="fa fa-users"></i>
                <span>Friends</span>
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
                
        <div class="message-container">
    <div class="message bot-message">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <?php if (isset($_SESSION['user_id'])): ?>
                <p id="welcome-message" 
                   data-text="Hello! How can I help you today? You can ask me questions or generate flashcards from your notes.">
                   <!-- Remove the conditional content display -->
                </p>
            <?php else: ?>
                <p id="welcome-message" 
                   data-text="Log in to get started with Flashcard.ai">
                   <!-- Remove the conditional content display -->
                </p>
            <?php endif; ?>
            
        </div>
    </div>
    <?php echo $loadingMessageHtml; ?>

</div>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message-container">
                <div class="message bot-message">
                    <?php if ($_SESSION['success'] === "Flashcards created!" && isset($_SESSION['last_created_set_id'])): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <p id="success-message" data-text="Flashcards created! Click the button to view them.  "></p>
                            <a href="flashcard.php?set_id=<?php echo $_SESSION['last_created_set_id']; ?>" class="create-flashcards-btn" style="margin-left: 10px;">
                                <i class="fa fa-eye"></i> View Flashcards
                            </a>
                        </div>
                    <?php else: ?>
                        <p id="success-message" data-text="<?php echo $_SESSION['success']; ?>"></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php 
            // Clean up session variables after displaying the message
            unset($_SESSION['success']); 
            if (isset($_SESSION['last_created_set_id'])) {
                unset($_SESSION['last_created_set_id']);
            }
            ?>
        <?php endif; ?>
                
                
            
        </div>
        <div class="quick-questions">
            <button class="quick-question-btn" data-question="What can you do?">
                <i class="fa fa-question-circle"></i> What can you do?
            </button>
            <button class="quick-question-btn" data-question="How do I create flashcards?">
                <i class="fa fa-magic"></i> How do I create flashcards?
            </button>
            <button class="quick-question-btn" data-question="How do I study effectively?">
                <i class="fa fa-brain"></i> How do I study effectively?
            </button>
        </div>
        
        <div class="input-area">
            <form class="message-form" id="message-form" method="POST" action="" enctype="multipart/form-data">
                <textarea class="message-input" id="message-input" name="message_text" placeholder="Paste or Upload your notes here . . ." rows="1"></textarea>
                <div class="input-buttons">
                    <div class="file-upload">
                        <input type="file" id="file-upload" name="message_file" accept=".txt">
                        <button type="button" class="upload-btn" id="uploadBtn">
                            <i class="fa fa-paperclip"></i>
                        </button>
                    </div>
                    <button type="submit" name="process_message" class="send-btn" id="sendBtn">
                        <i class="fa fa-paper-plane"></i>
                    </button>
                </div>
            </form>
            <div class="file-name" id="file-name"></div>
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
            
            // Auto-resize textarea
            const messageInput = document.getElementById('message-input');
            
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            
            // File upload handling
            const fileUpload = document.getElementById('file-upload');
            const fileName = document.getElementById('file-name');
            
            fileUpload.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    
                    if (fileExtension !== 'txt') {
                        alert('Only .txt files are allowed');
                        this.value = '';
                        fileName.textContent = '';
                        fileName.style.display = 'none';
                        return;
                    }
                    
                    fileName.textContent = file.name;
                    fileName.style.display = 'block';
                } else {
                    fileName.textContent = '';
                    fileName.style.display = 'none';
                }
            });
            
            // Check login status when clicking message box
            messageInput.addEventListener('click', function() {
                <?php if (!isset($_SESSION['user_id'])): ?>
                authModal.style.display = 'flex';
                <?php endif; ?>
            });
            FCbtn.addEventListener('click', function() {
                <?php if (!isset($_SESSION['user_id'])): ?>
                authModal.style.display = 'flex';
                <?php endif; ?>
            });
            
            // "Create Flashcards" button just focuses on input field
            const createFlashcardsBtn = document.querySelector('.create-flashcards-btn');
            if (createFlashcardsBtn) {
                createFlashcardsBtn.addEventListener('click', function() {
                    messageInput.focus();
                    messageInput.placeholder = 'Paste or upload your notes here';
                });
            }
            
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
    
    // Typing animation function
    function typeMessage(element, text, speed = 30) {
        let i = 0;
        element.textContent = '';
        element.classList.add('typing');
        
        function type() {
            if (i < text.length) {
                element.textContent += text.charAt(i);
                i++;
                setTimeout(type, speed);
            } else {
                // Remove the cursor when typing is complete
                element.classList.remove('typing');
            }
        }
        
        type();
    }

    document.addEventListener('DOMContentLoaded', function() {
    // Existing code...
    
    // Always animate welcome message (like in fff.php)
    const welcomeMessage = document.getElementById('welcome-message');
    if (welcomeMessage) {
        const text = welcomeMessage.getAttribute('data-text');
        typeMessage(welcomeMessage, text);
    }

    // Always animate success and error messages as they are new
    const successMessage = document.getElementById('success-message');
    if (successMessage) {
        const text = successMessage.getAttribute('data-text');
        typeMessage(successMessage, text);
    }

    const errorMessage = document.getElementById('error-message');
    if (errorMessage) {
        const text = errorMessage.getAttribute('data-text');
        typeMessage(errorMessage, text);
    }

    // Existing code...
});


    // Add to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Existing code...
    
    // Quick question buttons
    const quickQuestionBtns = document.querySelectorAll('.quick-question-btn');
    const contentArea = document.getElementById('content-area');
    
    quickQuestionBtns.forEach(button => {
        button.addEventListener('click', function() {
            const question = this.getAttribute('data-question');
            let answer = '';
            
            // Define answers for each question
            switch(question) {
                case 'What can you do?':
                    answer = "I can help you create flashcards from your notes, organize your study materials, and provide a platform for effective learning. Just paste your notes in the input box below or upload a text file, and I'll convert them into question-answer flashcards for you to study.";
                    break;
                case 'How do I create flashcards?':
                    answer = "To create flashcards, simply type or paste your notes in the text box below, or upload a text file using the paperclip icon. Then click the send button, and I'll analyze your content and generate flashcards automatically. You can view, edit, and organize these flashcards in your library.";
                    break;
                case 'How do I study effectively?':
                    answer = "Effective studying involves active recall and spaced repetition. Use the flashcards to test yourself regularly rather than just reading them. Space out your study sessions over time instead of cramming. Focus on the cards you find difficult, and review your material in different environments to strengthen memory associations.";
                    break;
                default:
                    answer = "I don't have a specific answer for that question. Please try one of the other options or ask me something else.";
            }
            
            // Create user message
            const userMessageContainer = document.createElement('div');
            userMessageContainer.className = 'message-container';
            userMessageContainer.innerHTML = `
                <div class="message user-message">
                    <p>${question}</p>
                </div>
            `;
            contentArea.appendChild(userMessageContainer);
            
            // Create bot message with typing effect
            const botMessageContainer = document.createElement('div');
            botMessageContainer.className = 'message-container';
            botMessageContainer.innerHTML = `
                <div class="message bot-message">
                    <p id="bot-response-${Date.now()}"></p>
                </div>
            `;
            contentArea.appendChild(botMessageContainer);
            
            // Scroll to the bottom
            contentArea.scrollTop = contentArea.scrollHeight;
            
            // Apply typing effect to the bot response
            const botResponseElement = botMessageContainer.querySelector('p');
            typeMessage(botResponseElement, answer);
        });
    });
    
    // Existing code...
});

// Add this to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Existing code...
    
    // Form submission handling with loading animation
    const messageForm = document.getElementById('message-form');
    const loadingContainer = document.getElementById('loading-message-container');
    const loadingStage = document.getElementById('loading-stage');
    const loadingBar = document.getElementById('loading-bar');
    
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
            // Only show loading if there's content to process
            const messageInput = document.getElementById('message-input');
            const fileUpload = document.getElementById('file-upload');
            
            if ((messageInput && messageInput.value.trim()) || 
                (fileUpload && fileUpload.files && fileUpload.files.length > 0)) {
                
                // Show loading message
                loadingContainer.style.display = 'block';
                
                // Scroll to the loading message
                const contentArea = document.getElementById('content-area');
                contentArea.scrollTop = contentArea.scrollHeight;
                
                // Simulate the loading stages
                updateLoadingStage('Processing your notes...', 20);
                
                setTimeout(() => {
                    updateLoadingStage('Sending to AI for analysis...', 40);
                    
                    setTimeout(() => {
                        updateLoadingStage('Generating flashcards...', 70);
                        
                        setTimeout(() => {
                            updateLoadingStage('Finalizing and saving...', 90);
                        }, 2000);
                    }, 2000);
                }, 1500);
                
                // The form will naturally submit and redirect, so the loading
                // animation will be visible until the page reloads
            }
        });
    }
    
    // Function to update loading stage text and progress bar
    function updateLoadingStage(text, progress) {
        loadingStage.textContent = text;
        loadingBar.style.width = progress + '%';
    }
    
    // Existing code...
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
                // Force layout recalculation before showing the modal
                void authModal.offsetWidth;
                
                // Show the modal
                authModal.style.display = 'flex';
                
                // Add debugging
                console.log('Auth modal opened');
                console.log('Modal style:', window.getComputedStyle(authModal).display);
                
                // Force the browser to repaint
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
});





    </script>
</body>
</html>
