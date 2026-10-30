<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$pg = cr_page();
$pg = cr_page_count($conn, $pg, "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id");
$sql = "SELECT u.id, u.username, u.email, u.is_active, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC" . cr_page_sql($pg);
$result = $conn->query($sql);
?>
<?php cr_layout_start('Users'); ?>
<?= cr_page_header('Users', 'Everyone who can sign in to the back office.', '<a class="btn" href="add.php">Add user</a>'); ?>
<?php if (($_GET['error'] ?? '') === 'last_admin'): ?><?= cr_alert('error', 'The last active admin cannot be deleted.'); ?><?php endif; ?>

<div class="card card-flush">
    <div class="card-head"><h2>All users</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): $count = (int)$pg['offset'] + 1; while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td class="muted"><?= $count++; ?></td>
                    <td><span class="cell-title"><?= h($row['username']); ?></span><?php if ((int)$row['id'] === (int)$_SESSION['user_id']): ?><span class="cell-sub">This is you</span><?php endif; ?></td>
                    <td><?= h($row['email']); ?></td>
                    <td><span class="badge <?= strcasecmp((string)$row['role_name'], 'Admin') === 0 ? 'badge-purple' : 'badge-info'; ?>"><?= h($row['role_name']); ?></span></td>
                    <td><?= $row['is_active'] ? cr_status_badge('Active') : cr_status_badge('Inactive'); ?></td>
                    <td class="actions-cell">
                        <a href="edit.php?id=<?= h($row['id']); ?>" class="btn btn-sm btn-secondary">Edit</a>
                        <?php if ((int)$row['id'] !== (int)$_SESSION['user_id']): ?>
                        <a href="<?= h(stock_action_url('delete.php?id=' . (int)$row['id'])); ?>" data-confirm="Delete this user?" class="btn btn-sm btn-danger">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="6" class="table-empty">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
