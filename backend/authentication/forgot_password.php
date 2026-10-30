<?php
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/send_email.php';

$error = '';
$success = '';
$emailReady = cr_mail_configured() && cr_absolute_url('', true) !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $emailReady) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!stock_rate_limit('forgot_password', 5, 3600)) {
        $error = 'Too many password reset requests. Please try again later.';
    } else {
        // Same message whether or not the account exists, so emails cannot be discovered.
        $success = 'If an active account uses this email, a reset link has been sent. It expires in 1 hour.';

        $stmt = $conn->prepare('SELECT id, username FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && stock_rate_limit('forgot_password_acct|' . $user['id'], 3, 3600)) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token); // only the hash is stored
            $update = $conn->prepare('UPDATE users SET reset_token = ?, reset_expires = (NOW() + INTERVAL 1 HOUR) WHERE id = ?');
            $update->bind_param('si', $tokenHash, $user['id']);
            $update->execute();
            $update->close();

            $link = cr_absolute_url('authentication/reset_password.php?token=' . urlencode($token), true);
            $body = '<p>Hello ' . h($user['username']) . ',</p>'
                . '<p>A password reset was requested for your Car Rental account. Use the link below to choose a new password:</p>'
                . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
                . '<p>This link expires in 1 hour. If you did not request this, you can ignore this email.</p>';
            if (!sendEmail($email, 'Password reset', $body)) {
                error_log('Password reset email could not be sent for user #' . (int)$user['id']);
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
    <title>Forgot Password - Car Rental</title>
    <link rel="stylesheet" href="<?= h(app_url('includes/css/style.css')); ?>?v=customer-ready-1">
</head>
<body>
<div class="main-container centered-flex">
    <div class="form-container">
        <div class="icon fa fa-lock"></div>
        <form class="centered-flex" method="post">
            <div class="title-register">FORGOT PASSWORD</div>
            <div class="msg">
                <?php if (!$emailReady): ?>
                    <p class="alert error">Password reset by email is not available on this system. Please ask your administrator to set a new password for you.</p>
                <?php endif; ?>
                <?php if ($error): ?><p class="alert error"><?= h($error); ?></p><?php endif; ?>
                <?php if ($success): ?><p class="alert success"><?= h($success); ?></p><?php endif; ?>
            </div>
            <?php if ($emailReady): ?>
                <div class="field"><input type="email" name="email" placeholder="Email" required><span class="fa fa-envelope"></span></div>
                <div class="btn-container"><input type="submit" id="login-btn" value="Send Reset Link"></div>
            <?php endif; ?>
            <div class="signup"><a href="login.php">Back to Login</a></div>
        </form>
    </div>
</div>
</body>
</html>
