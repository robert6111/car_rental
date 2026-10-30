<?php
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$user = null;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token = '';
} elseif (!stock_rate_limit('reset_password_lookup', 20, 3600)) {
    $error = 'Too many attempts. Please try again later.';
    $token = '';
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if (!$user && !$error) {
    $error = 'This reset link is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
        $stmt->bind_param('si', $hash, $user['id']);
        if ($stmt->execute()) {
            cr_bump_session_version($conn, (int)$user['id']); // sign out all existing sessions
            $success = 'Password changed successfully. You can now login.';
        } else {
            $error = 'Password could not be changed.';
        }
        $stmt->close();
        $user = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Car Rental</title>
    <link rel="stylesheet" href="<?= h(app_url('includes/css/style.css')); ?>?v=customer-ready-1">
</head>
<body>
<div class="main-container centered-flex">
    <div class="form-container">
        <div class="icon fa fa-key"></div>
        <form class="centered-flex" method="post">
            <div class="title-register">RESET PASSWORD</div>
            <div class="msg">
                <?php if ($error): ?><p class="alert error"><?= h($error); ?></p><?php endif; ?>
                <?php if ($success): ?><p class="alert success"><?= h($success); ?></p><?php endif; ?>
            </div>
            <?php if ($user): ?>
                <input type="hidden" name="token" value="<?= h($token); ?>">
                <div class="field"><input type="password" name="password" placeholder="New Password" required><span class="fa fa-lock"></span></div>
                <div class="field"><input type="password" name="confirm_password" placeholder="Confirm Password" required><span class="fa fa-lock"></span></div>
                <div class="btn-container"><input type="submit" id="login-btn" value="Reset Password"></div>
            <?php endif; ?>
            <div class="signup"><a href="login.php">Back to Login</a></div>
        </form>
    </div>
</div>
</body>
</html>
