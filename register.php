<?php
include 'includes/config.php';
session_start();

// Redirect logged-in users to welcome page
if (isset($_SESSION['user_id'])) {
    header("Location: welcome.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $password]);
        
        // Set session and redirect to welcome page
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['username'] = $username;
        $_SESSION['success'] = "Registration successful!";
        header("Location: welcome.php");
        exit();
    } catch (PDOException $e) {
        $error = "Registration failed: " . $e->getMessage();
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
            
            <br>
            <form action="" method="POST">
                <div class="row">
                    <div class="col-1"></div>
                    <div class="col-10">
                        <div class="form-floating mb-3">
                            <input type="username" class="form-control" name="form_username" id="username"
                                placeholder="Your username" required/>
                            <label for="floatingInput">Username</label>
                        </div>
                        <div class="form-floating mb-3 ">
                            <input type="password" class="form-control" name="password" id="password"
                                placeholder="Your password" required/>
                            <label for="" class="form-label">Password</label>
                        </div>
                        <div class="form-floating mb-3 ">
                            <input type="password" class="form-control" name="passwordConfirm" id="passwordConfirm"
                                placeholder="Your password again" required/>
                            <label for="" class="form-label">Password confirm</label>

                        </div>
                        <div class="form-floating mb-3 ">
                            <input type="text" class="form-control" name="email" id="email" placeholder="Your E-mail" required/>
                            <label for="" class="form-label">E-mail</label>
                        </div>
                        <br>
                        <div class="mb-3 text-center">
                            <button role="button" class="button-27" name="submit" id="submit" required>Register</button>

                        </div>
                    </div>
                    <div class="col-1"></div>

                </div>

            </form>
        </div>
    </div>
    </div>
    
    </div>
    
</body>
</html>
