<?php
/**
 * Loads server configuration from config/config.php (one level above backend/).
 * Environment variables CAR_RENTAL_DB_* still override database values, so hosts
 * that inject secrets through the environment keep working.
 */

if (!function_exists('cr_config')) {

    function cr_config_all(): array {
        static $config = null;
        if ($config !== null) return $config;

        $defaults = [
            'app' => [
                'url' => '',
                'debug' => false,
                'timezone' => 'UTC',
                'idle_timeout' => 1800,
                'trust_proxy' => false,
            ],
            'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'car_rental', 'user' => '', 'pass' => ''],
            'mail' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Car Rental'],
            'storage_path' => '',
        ];

        $file = dirname(__DIR__, 2) . '/config/config.php';
        $loaded = is_file($file) ? require $file : [];
        if (!is_array($loaded)) $loaded = [];
        $config = array_replace_recursive($defaults, $loaded);

        $env = [
            'host' => getenv('CAR_RENTAL_DB_HOST'),
            'name' => getenv('CAR_RENTAL_DB_NAME'),
            'user' => getenv('CAR_RENTAL_DB_USER'),
            'pass' => getenv('CAR_RENTAL_DB_PASS'),
        ];
        foreach ($env as $key => $value) {
            if ($value !== false && $value !== '') $config['db'][$key] = $value;
        }

        $config['_loaded_from_file'] = is_file($file);
        return $config;
    }

    /** Read a config value with dot notation, e.g. cr_config('db.host'). */
    function cr_config(string $key, $default = null) {
        $value = cr_config_all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }

    /** Absolute path inside the private storage folder (uploads, backups, logs). */
    function cr_storage_path(string $sub = ''): string {
        $base = trim((string)cr_config('storage_path', ''));
        if ($base === '') $base = dirname(__DIR__, 2) . '/storage';
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
        if ($sub !== '' && !is_dir($path)) @mkdir($path, 0770, true);
        return $path;
    }

    function cr_is_debug(): bool {
        return (bool)cr_config('app.debug', false);
    }

    // Error handling: never show errors to visitors on a live system, always log them.
    ini_set('display_errors', cr_is_debug() ? '1' : '0');
    ini_set('display_startup_errors', cr_is_debug() ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', cr_storage_path('logs') . '/php-error.log');
    error_reporting(E_ALL);

    // Unexpected errors: log the details, show visitors a friendly page (details only in debug mode).
    set_exception_handler(function (Throwable $e) {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) http_response_code(500);
        $detail = cr_is_debug() ? '<pre style="white-space:pre-wrap;background:#f3f4f6;padding:12px;border-radius:8px;">' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title></head>'
            . '<body style="font-family:system-ui,Arial;padding:30px;max-width:720px;margin:auto;"><h2 style="color:#991b1b;">Something went wrong</h2>'
            . '<p>The action could not be completed. Please go back and try again. If the problem continues, contact your administrator.</p>' . $detail
            . '<p><a href="javascript:history.back()">&larr; Go back</a></p></body></html>';
        exit;
    });

    $tz = (string)cr_config('app.timezone', 'UTC');
    if (!@date_default_timezone_set($tz)) date_default_timezone_set('UTC');
}
