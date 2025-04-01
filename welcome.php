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
                echo '<a href="welcome.php?set_id=' . htmlspecialchars($set['set_id']) . '" class="library-item">';
                echo '<span>' . htmlspecialchars($set['title']) . '</span>';
                echo '</a>';
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
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo">
                <img src="media/images/icon2.png" alt="Logo">
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
            <a href="#" class="nav-item">
                <i class="fa fa-compass"></i>
                <span>Discover</span>
            </a>
            <a href="#" class="nav-item">
                <i class="fa fa-folder"></i>
                <span>Spaces</span>
            </a>
            
            <div class="library-section">
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
            <?php if (isset($_SESSION['current_set']) && isset($_SESSION['current_flashcards'])): ?>
                <!-- Display specific flashcard set -->
                <div class="flashcard-set-header">
                    <h2><?php echo htmlspecialchars($_SESSION['current_set']['title']); ?></h2>
                    <p>Created: <?php echo date('F j, Y', strtotime($_SESSION['current_set']['created_at'])); ?></p>
                    <p>Click on each question to reveal the answer</p>
                </div>
                
                <div id="flashcardsContainer">
                    <?php foreach ($_SESSION['current_flashcards'] as $card): ?>
                        <div class="flashcard">
                            <div class="flashcard-question">
                                <h3>Q: <?php echo htmlspecialchars($card['question']); ?></h3>
                            </div>
                            <div class="flashcard-content">
                                <p>A: <?php echo htmlspecialchars($card['answer']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php 
                // Clear the session variables after displaying
                unset($_SESSION['current_set']);
                unset($_SESSION['current_flashcards']);
                ?>
                
            <?php else: ?>
                <!-- Default welcome message -->
                <div class="message-container">
                    <div class="message bot-message">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <p>Hello! How can I help you today? You can ask me questions or generate flashcards from your notes.</p>
                            <button class="create-flashcards-btn" id="FCbtn">
                                <i class="fa fa-plus"></i> Create Flashcards
                            </button>
                        </div>
                        
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="message-container">
                        <div class="message bot-message">
                            <p><?php echo $_SESSION['success']; ?></p>
                        </div>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="message-container">
                        <div class="message bot-message">
                            <p style="color: var(--error-color);"><?php echo $_SESSION['error']; ?></p>
                        </div>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['flashcards']) && !empty($_SESSION['flashcards'])): ?>
                    <div class="message-container">
                        <div class="message bot-message" style="width: 100%; max-width: 100%;">
                            <h3>Generated Flashcards</h3>
                            <p>Click on a question to reveal the answer. <a href="welcome.php?set_id=<?php echo $_SESSION['latest_set_id']; ?>">View in Library</a></p>
                            
                            <div id="flashcardsContainer">
                                <?php foreach ($_SESSION['flashcards'] as $question => $answer): ?>
                                    <div class="flashcard">
                                        <div class="flashcard-question">
                                            <h3>Q: <?php echo htmlspecialchars($question); ?></h3>
                                        </div>
                                        <div class="flashcard-content">
                                            <p>A: <?php echo htmlspecialchars($answer); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php 
                    unset($_SESSION['flashcards']);
                    unset($_SESSION['latest_set_id']);
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="input-area">
            <form class="message-form" id="message-form" method="POST" action="" enctype="multipart/form-data">
                <textarea class="message-input" id="message-input" name="message_text" placeholder="Ask follow-up" rows="1"></textarea>
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
            /*sendBtn.addEventListener('click', function() {
                <?php if (!isset($_SESSION['user_id'])): ?>
                authModal.style.display = 'flex';
                <?php endif; ?>
            });
            uploadBtn.addEventListener('click', function() {
                <?php if (!isset($_SESSION['user_id'])): ?>
                authModal.style.display = 'flex';
                <?php endif; ?>
            });*/
            
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
    </script>
</body>
</html>
