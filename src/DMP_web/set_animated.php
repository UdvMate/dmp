<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Set the animation flag based on the request
if (isset($_POST['flag']) && $_POST['flag'] === 'welcome_animated') {
    $_SESSION['welcome_animated'] = true;
}

// Return a success response
echo "Flag set";
?>
