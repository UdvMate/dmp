<?php
include 'includes/config.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: welcome.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
                $error = "Invalid username or password!";
            }
        } else {
            $error = "Invalid username or password!";
        }
    } catch (PDOException $e) {
        $error = "Login failed: " . $e->getMessage();
    }
}

include('includes/header.php');

?>


<body>
<title>Login</title>
<div class="dp">
        <div class="login-container">
            <h1>Login</h1>
            <?php if (!empty($error)): ?>
                <p style="color:red"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            
            <div class="myForm">
                <form action="" method="POST">
                    <div class="row">
                        <div class="col-1"></div>
                        <div class="col-10">
                            <!-- Username Field -->
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" name="username" id="username" placeholder="Your username" required/>
                                <label for="username">Username</label>
                            </div>

                            <!-- Password Field -->
                            <div class="form-floating mb-3 ">
                                <input type="password" class="form-control" name="password" id="password"
                                    placeholder="Your password" required/>
                                <label for="password">Password</label>
                            </div>
                            <!-- Submit Button -->
                            <br>
                            <div class="mb-3 text-center">
                                <button type="submit" class="button-27">Login</button>
                            </div>
                            <p>New member? <a class="links" href="register.php">Register</a></p>

                        </div>
                        <div class="col-1"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>    
</body>
</html>
