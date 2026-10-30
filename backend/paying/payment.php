<?php
// Legacy page: payments are managed in manage.php.
require_once __DIR__ . '/../includes/auth.php';
header('Location: manage.php');
exit;
