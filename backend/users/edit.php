<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$id = intval($_GET['id'] ?? 0);
if (!$id) die('Invalid user ID');
$msg = $error = '';
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) die('User not found');
$roles = $conn->query('SELECT * FROM roles ORDER BY id ASC');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = intval($_POST['role_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($id === (int)$_SESSION['user_id']) $is_active = 1;
    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$role_id) $error = 'Please fill all required fields correctly.';
    else {
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) $error = 'Password must be at least 8 characters.';
            else {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET username=?, email=?, password=?, role_id=?, is_active=? WHERE id=?');
                $stmt->bind_param('sssiii', $username, $email, $password, $role_id, $is_active, $id);
            }
        } else {
            $stmt = $conn->prepare('UPDATE users SET username=?, email=?, role_id=?, is_active=? WHERE id=?');
            $stmt->bind_param('ssiii', $username, $email, $role_id, $is_active, $id);
        }
        if (!$error) {
            if ($stmt->execute()) { $msg = 'User updated successfully.'; $user = array_merge($user, ['username'=>$username,'email'=>$email,'role_id'=>$role_id,'is_active'=>$is_active]); }
            else $error = 'Error: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html><html><head><title>Edit User</title></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Edit User</h2>
<?php if($msg): ?><p class="alert-success"><?= h($msg); ?></p><?php endif; ?><?php if($error): ?><p class="alert-error"><?= h($error); ?></p><?php endif; ?>
<form method="POST"><label>Username:</label><br><input type="text" name="username" value="<?= h($user['username']); ?>" required><br><br><label>Email:</label><br><input type="email" name="email" value="<?= h($user['email']); ?>" required><br><br><label>New Password (leave blank to keep current):</label><br><input type="password" name="password"><br><br><label>Role:</label><br><select name="role_id" required><?php while($row=$roles->fetch_assoc()): ?><option value="<?= h($row['id']); ?>" <?= $user['role_id']==$row['id']?'selected':''; ?>><?= h($row['name']); ?></option><?php endwhile; ?></select><br><br><label>Active:</label> <input type="checkbox" name="is_active" <?= $user['is_active'] ? 'checked' : ''; ?> <?= $id === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>><br><br><button type="submit">Update User</button></form>
</div></div></body></html>
