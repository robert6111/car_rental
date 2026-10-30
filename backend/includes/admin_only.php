<?php
require_once __DIR__ . '/auth.php';
if (!is_admin_user()) {
    http_response_code(403);
    echo '<h2 style="font-family:Arial;padding:30px;color:#991b1b;">Access denied. Admin only.</h2>';
    exit;
}
?>
