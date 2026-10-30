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
    if (file_put_contents($file, car_rental_export_database($conn)) !== false) $msg = 'Backup created successfully: ' . basename($file);
    else $error = 'Backup could not be created. Check folder permissions.';
    }
}
$files = glob(cr_storage_path('backups') . '/*.sql') ?: [];
rsort($files);
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Database Backup</title></head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="card-box">
        <h2>Database Backup</h2>
        <?php if($msg): ?><p class="alert-success"><?= h($msg); ?></p><?php endif; ?>
        <?php if($error): ?><p class="alert-error"><?= h($error); ?></p><?php endif; ?>
        <form method="post"><button type="submit" class="btn btn-primary">Backup Now</button></form>
        <br><h3>Available Backups</h3><br>
        <div class="table-responsive"><table class="table" border="1" cellpadding="10">
            <tr><th>#</th><th>File</th><th>Size</th><th>Date</th><th>Action</th></tr>
            <?php if($files): $i=1; foreach($files as $f): ?>
                <tr><td><?= $i++; ?></td><td><?= h(basename($f)); ?></td><td><?= number_format(filesize($f)/1024, 2); ?> KB</td><td><?= date('Y-m-d H:i:s', filemtime($f)); ?></td><td><a class="btn btn-primary" href="<?= h(stock_action_url('download.php?file=' . urlencode(basename($f)))); ?>">Download</a></td></tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5">No backups yet.</td></tr>
            <?php endif; ?>
        </table></div>
    </div>
</div>
</body></html>
