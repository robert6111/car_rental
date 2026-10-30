<?php
require_once __DIR__ . '/auth.php';
if (!is_admin_user()) {
    stock_deny_access();
}
?>
