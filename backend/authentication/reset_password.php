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
    if (($pwProblem = cr_password_problem($password)) !== null) {
        $error = $pwProblem;
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
<?php cr_auth_start('Choose a new password'); ?>
<h1>Choose a new password</h1>
<?= cr_alert('error', $error) . cr_alert('success', $success); ?>
<?php if ($user): ?>
<form method="post">
    <input type="hidden" name="token" value="<?= h($token); ?>">
    <div class="field">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" minlength="10" autocomplete="new-password" required autofocus>
        <span class="hint">At least 10 characters.</span>
    </div>
    <div class="field">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    </div>
    <div class="form-actions mt-3">
        <button type="submit" class="btn-block">Save new password</button>
    </div>
</form>
<?php endif; ?>
<p class="auth-links"><a href="login.php">Back to sign in</a></p>
<?php cr_auth_end(); ?>
