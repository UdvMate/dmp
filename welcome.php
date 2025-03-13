<?php
// Start a session only if one isn't already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: welcome.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Link to external CSS -->
</head>
<body>
    <!-- Header -->
    <header class="navbar">
        <div class="navbar-content">
            <div class="logo" id="logo">
                <img src="media/images/icon2.png" alt="Logo">
                <span>Flashcard.ai</span>
            </div>
            <nav class="nav-links">
                <div class="logo">
                    <a href="download.php">
                        <img src="media/images/dllogo.png" alt="Logo">
                    </a>
                    <?php if (isset($_SESSION['user_id'])) : ?>
                        <!-- Show Logout button if logged in -->
                        <a href="welcome.php?logout" class="btn">Logout</a>
                    <?php else : ?>
                        <!-- Show Login/Register buttons if not logged in -->
                        <a href="login.php" class="btn">Login</a>
                        <a href="register.php" class="btn">Register</a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <div class="wrapper">
        <main class="content">

            <section class="features">
                <h2>Key Features</h2>
                <div class="card-container" style="display: flex; flex-direction: column; width: 100%;">
                    <div class="flip-card" data-target="spaced-repetition">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <h3>AI-Powered Generation</h3>
                                <p>Generate flashcards from any text automatically.</p>
                            </div>
                            <div class="flip-card-back">
                                <h3>AI-Powered Generation</h3>
                                <p>Our AI algorithm analyzes your notes and creates effective flashcards, saving you time and effort.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flip-card" data-target="cross-platform-access" id="spaced-repetition">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <h3>Spaced Repetition</h3>
                                <p>Learn smarter with optimized review schedules.</p>
                            </div>
                            <div class="flip-card-back">
                                <h3>Spaced Repetition</h3>
                                <p>Our system uses spaced repetition to ensure you remember what you learn long-term. Reviews are scheduled based on your performance.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flip-card" id="cross-platform-access">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <h3>Cross-Platform Access</h3>
                                <p>Access your flashcards on desktop, mobile, and web.</p>
                            </div>
                            <div class="flip-card-back">
                                <h3>Cross-Platform Access</h3>
                                <p>Study anywhere, anytime. Our app is available on all major platforms, so you can seamlessly switch between devices.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Footer -->
    <footer>
        <p>&copy; 2025 Flashcard.ai | <a href="https://docs.google.com/document/d/1rvKo156DPou6UD3AZTfpJEa7ZuKD_uafZSG2bJSty6A/edit?usp=sharing">Documentation</a></p>
    </footer>

    <a class="scroll-down-arrow">
        <img src="media/images/downarrow.png" alt="Scroll Down">
    </a>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.flip-card');

            cards.forEach(card => {
                let timeoutId;

                // Click to flip
                card.addEventListener('click', function() {
                    const inner = this.querySelector('.flip-card-inner');
                    inner.classList.toggle('flipped');
                });

                // Mouse leave with delay
                card.addEventListener('mouseleave', function() {
                    const inner = this.querySelector('.flip-card-inner');
                    if (inner.classList.contains('flipped')) {
                        timeoutId = setTimeout(function() {
                            inner.classList.remove('flipped');
                        }, 1000); // 1 second delay
                    }
                });

                // Clear timeout if mouse returns
                card.addEventListener('mouseenter', function() {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                });
            });

            // Smooth Scrolling
            const arrow = document.querySelector('.scroll-down-arrow');
            let currentCard = 0;
            const cardElements = document.querySelectorAll('.flip-card');

            arrow.addEventListener('click', function(e) {
                e.preventDefault();

                currentCard++;
                if (currentCard >= cardElements.length) {
                    currentCard = 0; // Loop back to the first card
                }

                cardElements[currentCard].scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        });
    </script>
</body>
</html>
