<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/send_email.php';

$error = '';
$success = '';
$resetLink = '';

function buildResetLink(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_url('authentication/reset_password.php') . '?token=' . urlencode($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_rate_limit('forgot_password', 5, 3600)) {
        $error = 'Too many password reset requests. Please try again later.';
    } else {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $success = 'If the email exists and is active, a password reset link has been prepared.';
        $stmt = $conn->prepare('SELECT id, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($user && (int)$user['is_active'] === 1) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $update = $conn->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
            $update->bind_param('ssi', $token, $expires, $user['id']);
            $update->execute();
            $update->close();
            $resetLink = buildResetLink($token);
            $subject = 'Car Rental Password Reset';
            $body = 'Use this link to reset your password:<br><a href="' . h($resetLink) . '">' . h($resetLink) . '</a><br>This link expires in 1 hour.';
            if (function_exists('sendEmail') && sendEmail($email, $subject, $body) === true) {
                $success = 'A reset link has been sent to your email.';
                $resetLink = '';
            } else {
                $success = 'Email is not configured, so use the reset link below. The link expires in 1 hour.';
            }
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
                <?php if ($error): ?><p class="alert error"><?= h($error); ?></p><?php endif; ?>
                <?php if ($success): ?><p class="alert success"><?= h($success); ?></p><?php endif; ?>
            </div>
            <?php if ($resetLink): ?>
                <p class="alert success" style="word-break:break-all;"><a href="<?= h($resetLink); ?>"><?= h($resetLink); ?></a></p>
            <?php endif; ?>
            <div class="field"><input type="email" name="email" placeholder="Email" required><span class="fa fa-envelope"></span></div>
            <div class="btn-container"><input type="submit" id="login-btn" value="Prepare Reset Link"></div>
            <div class="signup"><a href="login.php">Back to Login</a></div>
        </form>
    </div>
</div>
</body>
</html>
