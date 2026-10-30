<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$pg = cr_page();
$pg = cr_page_count($conn, $pg, "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id");
$sql = "SELECT u.id, u.username, u.email, u.is_active, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC" . cr_page_sql($pg);
$result = $conn->query($sql);
?>
<!DOCTYPE html><html><head><title>Manage Users</title></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Manage Users</h2><?php if(($_GET['error'] ?? '') === 'last_admin'): ?><p class="alert-error">The last active admin cannot be deleted.</p><?php endif; ?><br><a href="add.php" class="btn btn-primary">Add New User</a><br><br>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>Actions</th></tr>
<?php if($result && $result->num_rows > 0): $count=(int)$pg['offset']+1; while($row=$result->fetch_assoc()): ?>
<tr><td><?= $count++; ?></td><td><?= h($row['username']); ?></td><td><?= h($row['email']); ?></td><td><?= h($row['role_name']); ?></td><td><?= $row['is_active'] ? 'Yes' : 'No'; ?></td><td><a href="edit.php?id=<?= h($row['id']); ?>">Edit</a><?php if((int)$row['id'] !== (int)$_SESSION['user_id']): ?> | <a href="<?= h(stock_action_url('delete.php?id=' . (int)$row['id'])); ?>" onclick="return confirm('Delete this user?')">Delete</a><?php endif; ?></td></tr>
<?php endwhile; else: ?><tr><td colspan="6">No users found.</td></tr><?php endif; ?>
</table></div><?= cr_pagination($pg); ?></div></div></body></html>
