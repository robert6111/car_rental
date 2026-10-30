<?php
require_once __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

$stmt = $conn->prepare("SELECT u.username, u.email, u.is_active, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<?php cr_layout_start('My profile'); ?>
<?= cr_page_header('My profile', 'Your sign-in details.', '<a class="btn" href="' . h(app_url('change_password.php')) . '">' . cr_icon('key') . ' Change password</a>'); ?>

<div class="card card-narrow">
    <dl class="details">
        <dt>Username</dt><dd><?= h($user['username'] ?? ''); ?></dd>
        <dt>Email</dt><dd><?= h($user['email'] ?? ''); ?></dd>
        <dt>Role</dt><dd><?= h($user['role_name'] ?? ''); ?></dd>
        <dt>Status</dt><dd><?= !empty($user['is_active']) ? cr_status_badge('Active') : cr_status_badge('Inactive'); ?></dd>
    </dl>
</div>
<?php cr_layout_end(); ?>
