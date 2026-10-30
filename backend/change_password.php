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
    elseif (($passwordProblem = cr_password_problem($new)) !== null) $error = $passwordProblem;
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
<?php cr_layout_start('Change password'); ?>
<a class="back-link" href="<?= h(app_url('profile.php')); ?>">My profile</a>
<?= cr_page_header('Change password', 'Other devices are signed out after the change.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="post" class="card card-narrow">
    <div class="field">
        <label for="current_password">Current password <span class="req">*</span></label>
        <div class="password-wrap">
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            <button type="button" class="password-toggle" data-password-toggle="current_password" aria-pressed="false">Show</button>
        </div>
    </div>
    <div class="field mt-4">
        <label for="new_password">New password <span class="req">*</span></label>
        <div class="password-wrap">
            <input type="password" id="new_password" name="new_password" minlength="10" maxlength="72" autocomplete="new-password" required>
            <button type="button" class="password-toggle" data-password-toggle="new_password" aria-pressed="false">Show</button>
        </div>
        <span class="hint">At least 10 characters.</span>
    </div>
    <div class="field mt-4">
        <label for="confirm_password">Confirm new password <span class="req">*</span></label>
        <div class="password-wrap">
            <input type="password" id="confirm_password" name="confirm_password" minlength="10" maxlength="72" autocomplete="new-password" required>
            <button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-pressed="false">Show</button>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Update password</button>
        <a class="btn btn-secondary" href="<?= h(app_url('profile.php')); ?>">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
