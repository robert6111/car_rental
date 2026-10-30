<?php
require_once __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
$msg = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_rate_limit('change_password_' . (int)($_SESSION['user_id'] ?? 0), 5, 3600)) {
        $error = 'Too many password change attempts. Please try again later.';
    } else {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) $error = 'Current password is incorrect.';
    elseif (strlen($new) < 8) $error = 'New password must be at least 8 characters.';
    elseif ($new !== $confirm) $error = 'New passwords do not match.';
    else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $update->bind_param('si', $hash, $_SESSION['user_id']);
        if ($update->execute()) {
            cr_bump_session_version($conn, (int)$_SESSION['user_id']); // signs out other devices
            $msg = 'Password changed successfully. Other devices have been signed out.';
        }
        else $error = 'Password could not be changed.';
        $update->close();
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Change Password</title></head>
<body>
<?php include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="card-box" style="max-width:650px;">
        <h2>Change Password</h2>
        <?php if($msg): ?><p class="alert-success"><?= h($msg); ?></p><?php endif; ?>
        <?php if($error): ?><p class="alert-error"><?= h($error); ?></p><?php endif; ?>
        <form method="post">
            <label>Current Password</label><br><input type="password" name="current_password" required><br><br>
            <label>New Password</label><br><input type="password" name="new_password" required><br><br>
            <label>Confirm New Password</label><br><input type="password" name="confirm_password" required><br><br>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div>
</div>
</body></html>
