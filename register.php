<?php
include 'includes/config.php';
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_SESSION['user_id'])) {
    header("Location: welcome.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve and sanitize inputs
    $username = trim($_POST['username']); // Changed from 'form_username'
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $passwordConfirm = $_POST['passwordConfirm'];

    // Validate required fields
    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required!";
    } elseif ($password !== $passwordConfirm) {
        $error = "Passwords do not match!";
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
                $error = "Username or email already exists!";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <!-- Bootstrap -->    

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/registerstyle.css">
</head>
<body>
    <div class="dp">
        <div class="login-container">
            <h1>Registration</h1>
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

                            <!-- Password Confirmation Field -->
                            <div class="form-floating mb-3 ">
                                <input type="password" class="form-control" name="passwordConfirm" id="passwordConfirm"
                                    placeholder="Your password again" required/>
                                <label for="passwordConfirm">Password Confirm</label>
                            </div>

                            <!-- Email Field -->
                            <div class="form-floating mb-3 ">
                                <input type="email" class="form-control" name="email" id="email"
                                    placeholder="Your E-mail" required/>
                                <label for="email">E-mail</label>
                            </div>

                            <!-- Submit Button -->
                            <br>
                            <div class="mb-3 text-center">
                                <button type="submit" class="btn btn-primary">Register</button>
                            </div>
                        </div>
                        <div class="col-1"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>    
</body>
</html>
