<?php
/*
 * Database connection. Credentials come from config/config.php
 * (or CAR_RENTAL_DB_* environment variables). Nothing is hardcoded here.
 * Defines cr_db(); include db.php (not this file) on pages to get $conn.
 */
require_once __DIR__ . '/config.php';

if (!function_exists('cr_db')) {
    function cr_db(): mysqli {
        static $conn = null;
        if ($conn instanceof mysqli) return $conn;

        mysqli_report(MYSQLI_REPORT_OFF);
        $db = cr_config('db');
        $conn = @new mysqli(
            (string)$db['host'],
            (string)$db['user'],
            (string)$db['pass'],
            (string)$db['name'],
            (int)($db['port'] ?: 3306)
        );

        if ($conn->connect_errno) {
            error_log('Database connection failed: ' . $conn->connect_error);
            http_response_code(503);
            $hint = cr_config_all()['_loaded_from_file']
                ? 'Please check the database settings in config/config.php and that MySQL is running.'
                : 'config/config.php is missing. Copy config/config.example.php to config/config.php and enter the database details.';
            exit('<h2 style="font-family:system-ui,Arial;padding:30px;color:#991b1b;">The system is temporarily unavailable.</h2><p style="font-family:system-ui,Arial;padding:0 30px;">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>');
        }

        $conn->set_charset('utf8mb4');

        require_once __DIR__ . '/settings.php';
        require_once __DIR__ . '/schema.php';
        cr_settings_load($conn);
        cr_migrate_if_needed($conn); // runs only after an upgrade, not on every page

        // Business timezone from Settings (falls back to config). MySQL uses the same clock.
        $tz = cr_setting('timezone');
        if ($tz === '' || !@date_default_timezone_set($tz)) date_default_timezone_set((string)cr_config('app.timezone', 'UTC') ?: 'UTC');
        $conn->query("SET time_zone = '" . (new DateTime())->format('P') . "'");
        return $conn;
    }
}

