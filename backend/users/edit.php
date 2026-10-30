<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: manage.php'); exit; }
$msg = $error = '';
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: manage.php'); exit; }
$isSelf = $id === (int)$_SESSION['user_id'];
$roles = $conn->query('SELECT * FROM roles ORDER BY id ASC');
$validRoles = [];
foreach ($conn->query('SELECT id FROM roles') as $r) $validRoles[] = (int)$r['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role_id = intval($_POST['role_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $newPassword = (string)($_POST['password'] ?? '');
    if ($isSelf) { $is_active = 1; $role_id = (int)$user['role_id']; } // admins cannot lock themselves out

    $activeAdmins = (int)$conn->query('SELECT COUNT(*) AS total FROM users WHERE role_id = 1 AND is_active = 1')->fetch_assoc()['total'];
    $removesAdmin = (int)$user['role_id'] === 1 && (int)$user['is_active'] === 1 && ($role_id !== 1 || $is_active !== 1);

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role_id, $validRoles, true)) {
        $error = 'Please fill all required fields correctly.';
    } elseif ($newPassword !== '' && ($passwordProblem = cr_password_problem($newPassword)) !== null) {
        $error = $passwordProblem;
    } elseif ($removesAdmin && $activeAdmins <= 1) {
        $error = 'This is the last active admin. Create another admin before changing this account\'s role or status.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ? LIMIT 1');
        $check->bind_param('ssi', $email, $username, $id);
        $check->execute();
        $duplicate = $check->get_result()->fetch_assoc();
        $check->close();
        if ($duplicate) {
            $error = 'Another user already has this email or username.';
        } else {
            if ($newPassword !== '') {
                $password = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET username=?, email=?, password=?, role_id=?, is_active=?, reset_token=NULL, reset_expires=NULL WHERE id=?');
                $stmt->bind_param('sssiii', $username, $email, $password, $role_id, $is_active, $id);
            } else {
                $stmt = $conn->prepare('UPDATE users SET username=?, email=?, role_id=?, is_active=? WHERE id=?');
                $stmt->bind_param('ssiii', $username, $email, $role_id, $is_active, $id);
            }
            if ($stmt->execute()) {
                // Sign the user out everywhere when their password, role or status changed.
                if ($newPassword !== '' || $role_id !== (int)$user['role_id'] || $is_active !== (int)$user['is_active']) {
                    cr_bump_session_version($conn, $id);
                }
                $msg = 'User updated successfully.';
                $user = array_merge($user, ['username'=>$username,'email'=>$email,'role_id'=>$role_id,'is_active'=>$is_active]);
            } else {
                error_log('User update failed: ' . $stmt->error);
                $error = 'The user could not be updated. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>
<?php cr_layout_start('Edit user'); ?>
<a class="back-link" href="manage.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All users</a>
<?= cr_page_header('Edit user', 'Change sign-in details, role or status.'); ?>
<?= cr_alerts($msg, $error); ?>
<?php if ($isSelf): ?><?= cr_alert('info', 'This is your own account. You cannot change your own role or switch yourself off.'); ?><?php endif; ?>

<form method="POST" class="card card-medium">
    <div class="form-grid">
        <div class="field">
            <label for="username">Username <span class="req">*</span></label>
            <input type="text" id="username" name="username" maxlength="50" value="<?= h($user['username']); ?>" required>
        </div>
        <div class="field">
            <label for="email">Email <span class="req">*</span></label>
            <input type="email" id="email" name="email" maxlength="100" value="<?= h($user['email']); ?>" required>
        </div>
        <div class="field">
            <label for="password">New password <span class="opt">(optional)</span></label>
            <div class="password-wrap">
                <input type="password" id="password" name="password" minlength="10" maxlength="72" autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password"><?= cr_icon('eye'); ?></button>
            </div>
            <span class="hint">At least 10 characters. Leave empty to keep the current password.</span>
        </div>
        <div class="field">
            <label for="role_id">Role <span class="req">*</span></label>
            <select id="role_id" name="role_id" required <?= $isSelf ? 'disabled' : ''; ?>><?php while ($row = $roles->fetch_assoc()): ?><option value="<?= h($row['id']); ?>" <?= $user['role_id'] == $row['id'] ? 'selected' : ''; ?>><?= h($row['name']); ?></option><?php endwhile; ?></select>
        </div>
        <div class="field span-all">
            <label class="check"><input type="checkbox" name="is_active" <?= $user['is_active'] ? 'checked' : ''; ?> <?= $isSelf ? 'disabled' : ''; ?>> Active (can sign in)</label>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit"><?= cr_icon('check'); ?> Save changes</button>
        <a class="btn btn-secondary" href="manage.php">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
