<?php
require_once __DIR__ . '/../includes/app.php';
stock_destroy_session();
header('Location: ' . app_url('authentication/login.php'));
exit;
?>
