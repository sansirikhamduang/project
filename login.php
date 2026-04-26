<?php
session_start();
include "connect.php";

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$errorMessage = '';
$submittedUsername = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedUsername = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($submittedUsername === '' || $password === '') {
        $errorMessage = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        // Look up user record in the database (table: users)
        $stmt = $conn->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $submittedUsername);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($dbPassword);
                $stmt->fetch();

                // ใช้เฉพาะ password_verify สำหรับการเปรียบเทียบ hash password
                if (password_verify($password, $dbPassword)) {
                    $_SESSION['user'] = $submittedUsername;
                    header("Location: dashboard.php");
                    exit;
                }
            }

            $stmt->close();
        }

        $errorMessage = 'ชื่อผู้ใช้ หรือ รหัสผ่านไม่ถูกต้อง';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>

<body class="login">

<div class="login-container">

  <div class="login-box">

    <h2>ระบบตรวจจับป้ายทะเบียน</h2>
    <p>CONDO SYSTEM</p>

<form method="POST">

<?php if (!empty($errorMessage)): ?>
    <div class="error"><?php echo $errorMessage; ?></div>
<?php endif; ?>

<div class="input-group">
<label>Username</label>
<input type="text" name="username" value="<?php echo htmlspecialchars($submittedUsername, ENT_QUOTES); ?>" placeholder="Enter your username">
</div>

<div class="input-group">
<label>Password</label>
<input type="password" name="password" placeholder="Enter your password">
</div>

<button type="submit">เข้าสู่ระบบ</button>

</form>

</div>

</div>

</body>
</html>