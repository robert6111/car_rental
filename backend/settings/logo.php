<?php
// Serves the business logo (public: it is shown on contracts and the booking page).
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/uploads.php';

$logo = cr_setting('company_logo');
if ($logo === '') {
    http_response_code(404);
    exit;
}
$mime = str_ends_with($logo, '.png') ? 'image/png' : 'image/jpeg';
$full = realpath(cr_storage_path($logo));
$base = realpath(cr_storage_path());
if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit;
}
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
