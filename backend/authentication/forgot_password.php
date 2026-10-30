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
<?php cr_auth_start('Forgot password'); ?>
<h1>Forgot your password?</h1>
<?php if ($emailReady): ?>
    <p class="lead">Enter your email address. If it belongs to an account, we will send you a link to choose a new password.</p>
<?php else: ?>
    <?= cr_alert('info', 'Password reset by email is not set up on this system. Please ask your administrator to set a new password for you.'); ?>
<?php endif; ?>
<?= cr_alert('error', $error) . cr_alert('success', $success); ?>
<?php if ($emailReady): ?>
<form method="post">
    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="username" required autofocus>
    </div>
    <div class="form-actions mt-3">
        <button type="submit" class="btn-block">Send reset link</button>
    </div>
</form>
<?php endif; ?>
<p class="auth-links"><a href="login.php">Back to sign in</a></p>
<?php cr_auth_end(); ?>
