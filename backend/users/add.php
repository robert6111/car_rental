<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$msg = $error = '';
$roles = $conn->query("SELECT * FROM roles ORDER BY id ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $passwordRaw = (string)($_POST['password'] ?? '');
    $role_id = intval($_POST['role_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $role_id < 1 || $role_id > 5) {
        $error = 'Enter a username, a valid email and a role.';
    } elseif (($passwordProblem = cr_password_problem($passwordRaw)) !== null) {
        $error = $passwordProblem;
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $check->bind_param('ss', $email, $username);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) $error = 'Email or username already exists.';
        else {
            $password = password_hash($passwordRaw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role_id, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssii", $username, $email, $password, $role_id, $is_active);
            if ($stmt->execute()) {
                $msg = 'User added successfully.';
                if (($_SESSION['username'] ?? '') === 'setup' && $role_id === 1 && $email !== 'setup@carrental.local') {
                    $conn->query("UPDATE users SET is_active = 0 WHERE email = 'setup@carrental.local'");
                    $msg .= ' Temporary setup account has been disabled.';
                }
            } else { error_log('User create failed: ' . $stmt->error); $error = 'The user could not be created. Please try again.'; }
            $stmt->close();
        }
    }
}
// After an error, show what was typed again (never the password).
$keep = $error !== '' ? $_POST : [];
$keepRole = (int)($keep['role_id'] ?? 0);
$keepActive = $keep ? isset($keep['is_active']) : true;
?>
<?php cr_layout_start('Add user'); ?>
<a class="back-link" href="manage.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All users</a>
<?= cr_page_header('Add user', 'Give a staff member their own sign-in.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card card-medium">
    <div class="form-grid">
        <div class="field">
            <label for="username">Username <span class="req">*</span></label>
            <input type="text" id="username" name="username" maxlength="50" autocomplete="off" value="<?= h((string)($keep['username'] ?? '')); ?>" required>
        </div>
        <div class="field">
            <label for="email">Email <span class="req">*</span></label>
            <input type="email" id="email" name="email" maxlength="100" autocomplete="off" value="<?= h((string)($keep['email'] ?? '')); ?>" required>
        </div>
        <div class="field">
            <label for="password">Password <span class="req">*</span></label>
            <div class="password-wrap">
                <input type="password" id="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password"><?= cr_icon('eye'); ?></button>
            </div>
            <span class="hint">At least 10 characters.</span>
        </div>
        <div class="field">
            <label for="role_id">Role <span class="req">*</span></label>
            <select id="role_id" name="role_id" required><option value="">Select role</option><?php while ($row = $roles->fetch_assoc()): ?><option value="<?= h($row['id']); ?>"<?= $keepRole === (int)$row['id'] ? ' selected' : ''; ?>><?= h($row['name']); ?></option><?php endwhile; ?></select>
        </div>
        <div class="field span-all">
            <label class="check"><input type="checkbox" name="is_active"<?= $keepActive ? ' checked' : ''; ?>> Active (can sign in)</label>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit"><?= cr_icon('user-plus'); ?> Add user</button>
        <a class="btn btn-secondary" href="manage.php">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
