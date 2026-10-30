<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$msg = $error = '';
$roles = $conn->query("SELECT * FROM roles ORDER BY id ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $passwordRaw = (string)($_POST['password'] ?? '');
    $role_id = intval($_POST['role_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($passwordRaw) < 8 || !$role_id) {
        $error = 'Enter username, valid email, role, and a password of at least 8 characters.';
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
            } else $error = 'Error: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html><html><head><title>Add User</title></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Add New User</h2>
<?php if($msg): ?><p class="alert-success"><?= h($msg); ?></p><?php endif; ?>
<?php if($error): ?><p class="alert-error"><?= h($error); ?></p><?php endif; ?>
<form method="POST">
<label>Username:</label><br><input type="text" name="username" required><br><br>
<label>Email:</label><br><input type="email" name="email" required><br><br>
<label>Password:</label><br><input type="password" name="password" required><br><br>
<label>Role:</label><br><select name="role_id" required><option value="">Select Role</option><?php while($row = $roles->fetch_assoc()): ?><option value="<?= h($row['id']); ?>"><?= h($row['name']); ?></option><?php endwhile; ?></select><br><br>
<label>Active:</label> <input type="checkbox" name="is_active" checked><br><br>
<button type="submit">Add User</button>
</form></div></div></body></html>
