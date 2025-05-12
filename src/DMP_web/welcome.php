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

        // In the register logic section, update the catch block:
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
        if (strpos($e->getMessage(), 'username') !== false) {
            $register_error = "Username already exists!";
            // Add a JavaScript snippet to highlight the username field
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
            // Display message when no sets are found instead of examples
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
/* Add these styles to your existing CSS */
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
/* Add to your existing CSS section */
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
/* Add or update these styles in your CSS section */
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
/* Replace or update the loading overlay CSS */
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
/* Add to your existing CSS section */
input#reg_username.invalid {
    border-color: var(--error-color);
}

.username-validation-message.visible {
    display: block !important;
}
/* Add to your existing CSS section if not already present */
.password-input-container {
    position: relative;
    display: flex;
    align-items: center;
}

.password-input-container input {
    flex: 1;
    padding-right: 40px; /* Make room for the button */
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
/* Update the video container styling */
.video-container {
    flex: 1;
    max-width: 400px; /* Increased from 300px */
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
    font-size: 54px; /* Increased from 48px */
    color: white;
    opacity: 0.9;
    /* Add glow to the play button */
    text-shadow: 0 0 15px rgba(255, 255, 255, 0.7);
    transition: all 0.3s ease;
}

.video-play-button:hover i {
    opacity: 1;
    transform: scale(1.1);
    text-shadow: 0 0 20px rgba(255, 255, 255, 0.9);
}
/* Update the content layout to better handle the larger video */
.content-layout {
    display: flex;
    gap: 40px; /* Increased from 30px */
    align-items: center;
    justify-content: center;
    flex-wrap: wrap; /* Allow wrapping on smaller screens */
    margin: 30px 0;
}

/* Make sure the upload container balances with the larger video */
.upload-container {
    flex: 1;
    max-width: 400px; /* Match the video container */
}

/* Ensure the drag-drop area is tall enough to balance with the video */
.drag-drop-area {
    height: 225px; /* Increased height to better balance with larger video */
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
        <!-- Replace the content-area div in welcome.php with this -->
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
                // Clean up session variables after displaying the message
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
                <!-- In the register form, update the username input group: -->
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
<!-- Add this right before the closing </body> tag -->
<!-- Replace the existing loading-overlay div with this simplified version -->
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
            
            // Auto-resize textarea
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

// Add this to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Fix for auth tabs switching
    const authTabs = document.querySelectorAll('.auth-tab');
    const authForms = document.querySelectorAll('.auth-form');
    
    if (authTabs.length > 0) {
        authTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const targetFormId = this.getAttribute('data-form');
                
                // Deactivate all tabs and forms
                authTabs.forEach(t => t.classList.remove('active'));
                authForms.forEach(f => f.classList.remove('active'));
                
                // Activate clicked tab and corresponding form
                this.classList.add('active');
                document.getElementById(targetFormId).classList.add('active');
            });
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

// Add this JavaScript to replace the existing form handling code
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
            // Show the auth modal
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
            // Show the auth modal
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
            
            // Submit the form automatically when file is selected
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
            // Show the auth modal
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
            
            // Set the file input value and submit the form
            fileUpload.files = files;
            notesForm.submit();
            showLoading();
        }
    }
    
    function showLoading() {
        // Show loading overlay
        loadingOverlay.style.display = 'flex';
        
        // Simulate loading progress
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

// Add this to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Password validation
    const passwordInput = document.getElementById('reg_password');
    const lengthReq = document.getElementById('length-req');
    const capitalReq = document.getElementById('capital-req');
    const numberReq = document.getElementById('number-req');
    const registerBtn = document.querySelector('button[name="register_submit"]');
    
    if (passwordInput) {
        passwordInput.addEventListener('input', validatePassword);
        
        // Add check icons to requirements
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
        
        // Check length requirement
        if (password.length >= 8) {
            lengthReq.classList.add('valid');
        } else {
            lengthReq.classList.remove('valid');
        }
        
        // Check capital letter requirement
        if (/[A-Z]/.test(password)) {
            capitalReq.classList.add('valid');
        } else {
            capitalReq.classList.remove('valid');
        }
        
        // Check number requirement
        if (/[0-9]/.test(password)) {
            numberReq.classList.add('valid');
        } else {
            numberReq.classList.remove('valid');
        }
        
        // Update register button state
        if (password.length >= 8 && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
            registerBtn.disabled = false;
        } else {
            registerBtn.disabled = true;
        }
    }
});

// Add this to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Shared sets toggle functionality
    const sharedSetsToggle = document.getElementById('shared-sets-toggle');
    const sharedSetsSection = document.getElementById('shared-sets-section');
    
    if (sharedSetsToggle && sharedSetsSection) {
        sharedSetsToggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle visibility of shared sets section
            if (sharedSetsSection.style.display === 'block') {
                sharedSetsSection.style.display = 'none';
            } else {
                sharedSetsSection.style.display = 'block';
            }
            
            // Optional: Add visual indicator that the section is expanded
            this.classList.toggle('active');
        });
    }
});
// Add or update this in your JavaScript section
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
                
                // Show loading overlay before form submission
                showLoadingProgress();
                
                // Submit the form after a short delay to allow the loading overlay to appear
                setTimeout(() => {
                    notesForm.submit();
                }, 100);
            }
        });
    }
    
    // Also update the drag and drop handler to show loading progress
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
                
                // Set the file input value
                fileUpload.files = files;
                
                // Show loading overlay
                showLoadingProgress();
                
                // Submit the form after a short delay
                setTimeout(() => {
                    notesForm.submit();
                }, 100);
            }
            
            // Remove highlight
            this.classList.remove('active');
        });
    }
    
    // Replace your existing showLoadingProgress function with this simplified version
function showLoadingProgress() {
    // Show loading overlay
    loadingOverlay.style.display = 'flex';
    
    // Submit the form after a short delay to ensure the overlay is visible
    setTimeout(() => {
        notesForm.submit();
    }, 100);
}

// No need for updateProgress function anymore since we're using a simple spinner

    
    
});
// Add to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Username availability check
    const usernameInput = document.getElementById('reg_username');
    const usernameValidationMessage = document.querySelector('.username-validation-message');
    let usernameCheckTimeout;
    
    if (usernameInput) {
        usernameInput.addEventListener('input', function() {
            // Clear any existing timeout
            clearTimeout(usernameCheckTimeout);
            
            // Get the username value
            const username = this.value.trim();
            
            // Reset validation state
            this.classList.remove('invalid');
            usernameValidationMessage.classList.remove('visible');
            
            // Don't check if username is empty
            if (!username) return;
            
            // Set a timeout to avoid too many requests while typing
            usernameCheckTimeout = setTimeout(function() {
                checkUsernameAvailability(username);
            }, 500);
        });
    }
    
    function checkUsernameAvailability(username) {
        // Create form data
        const formData = new FormData();
        formData.append('username', username);
        
        // Send AJAX request
        fetch('check_username.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                // Username exists, show error
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
                
                // Re-enable register button if other conditions are met
                const registerBtn = document.querySelector('button[name="register_submit"]');
                if (registerBtn) {
                    // Only enable if password requirements are met
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
// Add to your existing JavaScript section
document.addEventListener('DOMContentLoaded', function() {
    // Password toggle functionality for all password fields
    const togglePasswordButtons = document.querySelectorAll('.toggle-password');
    
    togglePasswordButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent form submission
            
            // Find the password input that is a sibling of this button
            const passwordInput = this.previousElementSibling;
            const icon = this.querySelector('i');
            
            // Toggle password visibility
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
// Add to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const video = document.getElementById('demo-video');
    
    if (video) {
        // Add play button overlay
        const videoContainer = video.parentElement;
        const playButton = document.createElement('div');
        playButton.className = 'video-play-button';
        playButton.innerHTML = '<i class="fa fa-play"></i>';
        videoContainer.appendChild(playButton);
        
        // Play video when clicking the play button
        playButton.addEventListener('click', function() {
            video.play();
            playButton.style.display = 'none';
        });
        
        // Show play button when video is paused
        video.addEventListener('pause', function() {
            playButton.style.display = 'flex';
        });
        
        // Hide play button when video is playing
        video.addEventListener('play', function() {
            playButton.style.display = 'none';
        });
    }
});

    </script>
</body>
</html>
