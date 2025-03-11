<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
include 'includes/header.php';
?>
<h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
<!-- List downloadable files -->
<a href="downloads/file.zip" class="btn">Download File</a>
<?php include 'includes/footer.php'; ?>
