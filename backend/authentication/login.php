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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Car Rental</title>
    <link rel="stylesheet" href="<?= h(app_url('includes/css/style.css')); ?>?v=customer-ready-1">
    <link rel="icon" type="image/png" href="<?= h(app_url('includes/images/logo.png')); ?>?v=customer-ready-1">
</head>
<body>
    <div class="main-container centered-flex">
        <div class="form-container">
            <div class="icon fa fa-user"></div>

            <form class="centered-flex" method="post" action="" autocomplete="off">
                <div class="title">LOGIN</div>

                <div class="msg">
                    <?php if ($login_notice): ?>
                        <p class="alert success"><?= h($login_notice); ?></p>
                    <?php endif; ?>
                    <?php if ($login_error): ?>
                        <p class="alert error"><?= h($login_error); ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <input type="email" name="email" id="email" placeholder="Email" required>
                    <span class="fa fa-user"></span>
                </div>

                <div class="field">
                    <input type="password" name="password" placeholder="Password" required>
                    <span class="fa fa-lock"></span>
                </div>

                <div class="action centered-flex">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <div class="btn-container">
                    <input type="submit" name="login" id="login-btn" value="Login">
                </div>

                <div class="signup">Accounts are created by the admin only.</div>
            </form>
        </div>
    </div>
</body>
</html>
