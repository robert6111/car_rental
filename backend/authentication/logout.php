<?php
require_once __DIR__ . '/../includes/app.php';
// Only log out from our own logout link (stops other websites from logging users out).
if (stock_is_logged_in() && !stock_action_token_valid()) {
    header('Location: ' . app_url('dashboard.php'));
    exit;
}
stock_destroy_session();
header('Location: ' . app_url('authentication/login.php'));
exit;
