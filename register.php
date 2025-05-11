<?php
include 'includes/config.php';
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_SESSION['user_id'])) {
    header("Location: welcome.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $passwordConfirm = $_POST['passwordConfirm'];

    if (empty($username) || empty($email) || empty($password)) {
        $error = "All fields are required!";
    } elseif ($password !== $passwordConfirm) {
        $error = "Passwords do not match!";
    } else {
        // Hash password securely
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);

            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['success'] = "Registration successful!";
            header("Location: welcome.php");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $error = "Username or email already exists!";
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

include('includes/header.php');
?>



<body>
    <title>Register</title>
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
                                <button type="submit" class="button-27">Register</button>
                            </div>

                            <p>Already a member? <a href="login.php" class="links">Login</a></p>

                        </div>
                        <div class="col-1"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>       
</body>
</html>
