<?php
/*
 * Gives the page its database connection in $conn.
 * Credentials come from config/config.php (see includes/database.php).
 */
require_once __DIR__ . '/database.php';
$conn = cr_db();
