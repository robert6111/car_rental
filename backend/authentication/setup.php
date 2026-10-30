<?php
/*
 * First-run setup: creates the first Admin account.
 * Only available while the system has no active Admin. Once an admin exists this page is closed.
 */
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

if (cr_active_admin_exists($conn)) {
    header('Location: login.php');
    exit;
}

$error = '';
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!stock_rate_limit('setup', 10, 3600)) {
        $error = 'Too many attempts. Please try again later.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $conn->begin_transaction();
        // Re-check inside the transaction so two people cannot both become the first admin.
        $locked = $conn->query("SELECT id FROM users WHERE role_id = 1 AND is_active = 1 LIMIT 1 FOR UPDATE");
        if ($locked && $locked->num_rows > 0) {
            $conn->rollback();
            header('Location: login.php');
            exit;
        }
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $check->bind_param('ss', $email, $username);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($existing) {
            // Re-activate an existing (disabled) account as the admin.
            $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ?, role_id = 1, is_active = 1, session_version = session_version + 1 WHERE id = ?');
            $stmt->bind_param('sssi', $username, $email, $hash, $existing['id']);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (username, email, password, role_id, is_active) VALUES (?, ?, ?, 1, 1)');
            $stmt->bind_param('sss', $username, $email, $hash);
        }
        if ($stmt->execute()) {
            $conn->commit();
            header('Location: login.php?setup_done=1');
            exit;
        }
        $conn->rollback();
        error_log('First-run setup failed: ' . $stmt->error);
        $error = 'The admin account could not be created. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-time Setup - Car Rental</title>
    <link rel="stylesheet" href="<?= h(app_url('includes/css/style.css')); ?>?v=customer-ready-1">
    <link rel="icon" type="image/png" href="<?= h(app_url('includes/images/logo.png')); ?>">
</head>
<body>
<div class="main-container centered-flex">
    <div class="form-container">
        <div class="icon fa fa-user"></div>
        <form class="centered-flex" method="post" autocomplete="off">
            <div class="title-register">CREATE ADMIN ACCOUNT</div>
            <div class="msg">
                <p class="alert success">Welcome! Create the main administrator account for this system.</p>
                <?php if ($error): ?><p class="alert error"><?= h($error); ?></p><?php endif; ?>
            </div>
            <div class="field"><input type="text" name="username" placeholder="Username" value="<?= h($username); ?>" required><span class="fa fa-user"></span></div>
            <div class="field"><input type="email" name="email" placeholder="Email" value="<?= h($email); ?>" required><span class="fa fa-envelope"></span></div>
            <div class="field"><input type="password" name="password" placeholder="Password (min. 10 characters)" minlength="10" required><span class="fa fa-lock"></span></div>
            <div class="field"><input type="password" name="confirm_password" placeholder="Confirm password" minlength="10" required><span class="fa fa-lock"></span></div>
            <div class="btn-container"><input type="submit" id="login-btn" value="Create Admin"></div>
        </form>
    </div>
</div>
</body>
</html>
