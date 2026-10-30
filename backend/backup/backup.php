<?php
require_once __DIR__ . '/../includes/admin_only.php';
require_once __DIR__ . '/auto_backup.php';
$msg = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!stock_rate_limit('backup_create_' . (int)($_SESSION['user_id'] ?? 0), 3, 3600)) {
        $error = 'Backup limit reached. Please try again later.';
    } else {
    $dir = cr_storage_path('backups');
    $file = $dir . '/car_rental_manual_' . date('Y-m-d_H-i-s') . '.sql';
    if (cr_backup_write($conn, $file)) $msg = 'Backup created successfully: ' . basename($file);
    else $error = 'Backup could not be created. Check folder permissions.';
    }
}
$files = glob(cr_storage_path('backups') . '/*.sql') ?: [];
rsort($files);
?>
<?php cr_layout_start('Backups'); ?>
<?= cr_page_header('Database backups', 'Make a copy of the database and download earlier copies.', '<form method="post" class="mb-0"><button type="submit">Back up now</button></form>'); ?>
<?= cr_alerts($msg, $error); ?>
<?= cr_alert('info', 'Automatic backups are made once a day and kept for ' . (int)cr_setting('backup_keep_days') . ' days (change this in Settings). Manual backups are kept until you delete the file. Copy backups to another computer or drive regularly.'); ?>

<div class="card card-flush">
    <div class="card-head"><h2>Available backups</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>File</th><th class="num">Size</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($files): $i = 1; foreach ($files as $f): ?>
                <tr>
                    <td class="muted"><?= $i++; ?></td>
                    <td><span class="cell-title"><?= h(basename($f)); ?></span></td>
                    <td class="num"><?php $kb = filesize($f) / 1024; echo h($kb >= 1024 ? cr_number($kb / 1024, 1) . ' MB' : cr_number($kb, 0) . ' KB'); ?></td>
                    <td class="nowrap"><?= h(cr_date(date('Y-m-d', filemtime($f))) . ' ' . date('H:i', filemtime($f))); ?></td>
                    <td class="actions-cell"><a class="btn btn-sm btn-secondary" href="<?= h(stock_action_url('download.php?file=' . urlencode(basename($f)))); ?>">Download</a></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="table-empty">No backups yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php cr_layout_end(); ?>
