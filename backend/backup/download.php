<?php
require_once __DIR__ . '/../includes/admin_only.php';

stock_validate_action_token();
if (!stock_rate_limit('backup_download_' . (int)($_SESSION['user_id'] ?? 0), 10, 3600)) {
    http_response_code(429);
    echo 'Too many backup download attempts. Please try again later.';
    exit;
}
$file = basename($_GET['file'] ?? '');
$path = cr_storage_path('backups') . '/' . $file;

if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file) || !is_file($path)) {
    http_response_code(404);
    echo 'Backup file not found.';
    exit;
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($path);
exit;
?>
