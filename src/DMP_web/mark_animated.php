<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Mark message as animated
if (isset($_POST['message_id']) && is_numeric($_POST['message_id']) && isset($_SESSION['messages'][$_POST['message_id']])) {
    $_SESSION['messages'][$_POST['message_id']]['animated'] = true;
    echo "Message marked as animated";
} else {
    http_response_code(400);
    echo "Invalid message ID";
}
?>
