<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

$login_error = '';

if (isset($_GET['timeout'])) {
    $login_error = 'Your session expired due to inactivity. Please login again.';
}

$loginLockedUntil = (int)($_SESSION['login_lock_until'] ?? 0);
if ($loginLockedUntil > 0 && time() >= $loginLockedUntil) {
    unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
    $loginLockedUntil = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($loginLockedUntil > time()) {
        $remainingMinutes = max(1, (int)ceil(($loginLockedUntil - time()) / 60));
        $login_error = 'Too many failed login attempts. Please try again in ' . $remainingMinutes . ' minute(s).';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $login_error = 'Invalid email or password.';
        } else {
            $stmt = $conn->prepare("SELECT id, username, password, role_id, is_active FROM users WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $stmt->bind_result($id, $username, $hash, $role_id, $is_active);
                    $stmt->fetch();

                    if ((int)$is_active === 1 && password_verify($password, $hash)) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $id;
                        $_SESSION['username'] = $username;
                        $_SESSION['role_id'] = $role_id;
                        $_SESSION['LAST_ACTIVITY'] = time();
                        unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
                        header('Location: ' . app_url('dashboard.php'));
                        exit;
                    }
                }
                $stmt->close();
            }

            $login_error = 'Invalid email or password.';
            $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= CAR_RENTAL_LOGIN_MAX_ATTEMPTS) {
                $_SESSION['login_lock_until'] = time() + CAR_RENTAL_LOGIN_LOCK_SECONDS;
                $login_error = 'Too many failed login attempts. Please try again in 15 minutes.';
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
                    <label for="remember" class="centered-flex">
                        <input type="checkbox" id="remember"> Remember me
                    </label>
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
