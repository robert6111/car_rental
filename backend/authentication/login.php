<?php
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

// Fresh installation with no active admin yet: go to the first-run setup screen.
if (!cr_active_admin_exists($conn)) {
    header('Location: setup.php');
    exit;
}
if (stock_is_logged_in()) {
    header('Location: ' . app_url('dashboard.php'));
    exit;
}

$login_error = '';
$login_notice = '';
if (isset($_GET['timeout'])) $login_notice = 'Your session expired due to inactivity. Please login again.';
if (isset($_GET['signed_out'])) $login_notice = 'You have been signed out because your account was changed. Please login again.';
if (isset($_GET['setup_done'])) $login_notice = 'Admin account created. You can now login.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $window = CAR_RENTAL_LOGIN_LOCK_SECONDS;
    $ipBucket = cr_rate_bucket('login_ip');
    $accountBucket = cr_rate_bucket('login_acct|' . $email, false);

    // Lock per account (stops password guessing on one user) and per IP (stops spraying many users).
    if (cr_rate_count($accountBucket, $window) >= CAR_RENTAL_LOGIN_MAX_ATTEMPTS || cr_rate_count($ipBucket, $window) >= CAR_RENTAL_LOGIN_MAX_ATTEMPTS * 4) {
        $login_error = 'Too many failed login attempts. Please try again in ' . (int)ceil($window / 60) . ' minutes.';
    } else {
        $user = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
            $stmt = $conn->prepare('SELECT id, username, password, role_id, is_active, session_version FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }

        // Always run password_verify so response time does not reveal whether the email exists.
        $hash = $user['password'] ?? '$2y$10$DUALGfg/EelgjK0Lk54a9.WgPjyXLO9Cifsn.KITyK8KZV/dnSi3i';
        $passwordOk = password_verify($password, $hash);

        if ($user && $passwordOk && (int)$user['is_active'] === 1) {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $up = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $up->bind_param('si', $newHash, $user['id']);
                $up->execute();
                $up->close();
            }
            cr_rate_clear($accountBucket);
            stock_login_user($user);
            header('Location: ' . app_url('dashboard.php'));
            exit;
        }

        cr_rate_record($accountBucket);
        cr_rate_record($ipBucket);
        $login_error = 'Invalid email or password.';
    }
}
?>
<?php cr_auth_start('Sign in'); ?>
<h1>Sign in</h1>
<p class="lead">Use the email and password your administrator gave you.</p>
<?= cr_alert('info', $login_notice) . cr_alert('error', $login_error); ?>
<form method="post" action="" novalidate>
    <div class="field">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" autocomplete="username" required autofocus value="<?= h((string)($_POST['email'] ?? '')); ?>">
    </div>
    <div class="field">
        <label for="password">Password</label>
        <div class="password-wrap">
            <input type="password" name="password" id="password" autocomplete="current-password" required>
            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password"><?= cr_icon('eye'); ?></button>
        </div>
    </div>
    <div class="form-actions mt-3">
        <button type="submit" name="login" value="1" class="btn-block">Sign in</button>
    </div>
</form>
<p class="auth-links"><a href="forgot_password.php">Forgot your password?</a></p>
<?php cr_auth_end('Accounts are created by an administrator.'); ?>
