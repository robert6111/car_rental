<?php
require_once __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

$stmt = $conn->prepare("SELECT u.username, u.email, u.is_active, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Profile</title></head>
<body>
<?php include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="card-box" style="max-width:650px;">
        <h2>Profile</h2><br>
        <p><strong>Username:</strong> <?= h($user['username'] ?? ''); ?></p><br>
        <p><strong>Email:</strong> <?= h($user['email'] ?? ''); ?></p><br>
        <p><strong>Role:</strong> <?= h($user['role_name'] ?? ''); ?></p><br>
        <p><strong>Status:</strong> <?= !empty($user['is_active']) ? 'Active' : 'Inactive'; ?></p><br>
        <a class="btn btn-primary" href="<?= h(app_url('change_password.php')); ?>">Change Password</a>
    </div>
</div>
</body></html>
