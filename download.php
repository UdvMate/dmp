<?php
session_start();
const PERPLEXITY_API_KEY = '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';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = '';

    if (!empty($_FILES['textfile']['tmp_name'])) {
        $content = file_get_contents($_FILES['textfile']['tmp_name']);
    } elseif (!empty($_POST['notes'])) {
        $content = $_POST['notes'];
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

function processStringToDict($contentText) {
    // Remove everything after first newline
    $newlinePos = strpos($contentText, "\n");
    if ($newlinePos !== false) {
        $contentText = substr($contentText, 0, $newlinePos);
    }

    // Remove all asterisks and trim
    $contentText = str_replace(['**', '*'], '', trim($contentText));

    // Split into dictionary
    $dict = [];
    $remaining = substr($contentText, strpos($contentText, 'QQQ') + 3);
    $parts = preg_split('/(QQQ|AAA)/', $remaining, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $currentKey = null;
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === 'QQQ') {
            $currentKey = null;
        } elseif ($part === 'AAA') {
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
            $dict[$lastKey] = substr($lastValue, 0, $periodPos);
        }
    }

    return $dict;
}

// Display results
if (isset($_SESSION['rawResponse'])) {
    try {
        $flashcards = processStringToDict($_SESSION['rawResponse']);
        
        echo "<h1>Flashcards</h1>";
        foreach ($flashcards as $q => $a) {
            echo "<div style='margin:20px; padding:10px; border:1px solid #ccc'>
                    <h3>Q: {$q}</h3>
                    <p>A: {$a}</p>
                  </div>";
        }
        
        unset($_SESSION['rawResponse']);
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>


<!-- Keep your existing HTML form and display code here -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flashcard Generator</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1 class="mb-4">Flashcard Generator</h1>
    
    <form method="POST" enctype="multipart/form-data" class="mb-5">
        <div class="mb-3">
            <textarea class="form-control" name="notes" rows="5" placeholder="Paste your notes here"></textarea>
        </div>
        <div class="mb-3">
            <input type="file" class="form-control" name="textfile" accept=".txt">
        </div>
        <button type="submit" class="btn btn-primary">Generate Flashcards</button>
    </form>

    <?php if (!empty($_SESSION['flashcards'])): ?>
        <div class="row row-cols-1 row-cols-md-3 g-4">
            <?php foreach ($_SESSION['flashcards'] as $card): ?>
                <div class="col">
                    <div class="card h-100 shadow">
                        <div class="card-header bg-primary text-white">
                            Question
                        </div>
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($card['question']) ?></h5>
                            <button class="btn btn-secondary show-answer">Reveal Answer</button>
                            <div class="answer d-none mt-3">
                                <div class="card-text bg-light p-3 rounded">
                                    <?= htmlspecialchars($card['answer']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
            document.querySelectorAll('.show-answer').forEach(button => {
                button.addEventListener('click', () => {
                    button.nextElementSibling.classList.toggle('d-none');
                });
            });
        </script>

        <?php unset($_SESSION['flashcards']); ?>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
