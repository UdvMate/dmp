<?php
session_start();

// Initialize chat history if it doesn't exist
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// Handle quick questions
if (isset($_POST['type']) && $_POST['type'] === 'quick_question') {
    $question = $_POST['question'] ?? '';
    $answer = $_POST['answer'] ?? '';
    
    if (!empty($question) && !empty($answer)) {
        // Add the question (user message)
        $_SESSION['chat_history'][] = [
            'type' => 'user',
            'content' => $question
        ];
        
        // Add the answer (bot message)
        $_SESSION['chat_history'][] = [
            'type' => 'bot',
            'content' => $answer
        ];
    }
}

// No need to return anything
?>