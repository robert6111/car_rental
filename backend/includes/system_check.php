<?php
/*
 * Go-live checks: server, configuration, security, database and daily operations.
 * Used by Settings > System check (settings/system.php) and by "php cron/check.php".
 * Each check: ['group', 'label', 'status' => ok|warn|fail, 'detail'].
 */

require_once __DIR__ . '/schema.php'; // CR_SCHEMA_VERSION

function cr_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') return PHP_INT_MAX;
    $n = (int)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

/** True for localhost, plain IP addresses on private networks and *.local / *.lan names. */
function cr_is_lan_host(string $host): bool {
    $host = strtolower(trim($host, '[]'));
    if ($host === '' || $host === 'localhost' || preg_match('/\.(local|lan|internal|home)$/', $host)) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return strpos($host, '.') === false; // single-word office server name
}

/** HTTP status code of a URL on this server, or 0 when it cannot be reached. */
function cr_http_status(string $url): int {
    if (!ini_get('allow_url_fopen')) return 0;
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: CarRental-SystemCheck\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $http_response_header = [];
    @file_get_contents($url, false, $ctx, 0, 512);
    return preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
}

function cr_system_checks(?mysqli $conn): array {
    $checks = [];
    $add = function (string $group, string $label, string $status, string $detail) use (&$checks) {
        $checks[] = compact('group', 'label', 'status', 'detail');
    };
    $isWeb = PHP_SAPI !== 'cli';

    /* ---------------- Server ---------------- */
    $add('Server', 'PHP version', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail',
        'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0.0', '>=') ? '' : ' - PHP 8.0 or newer is required.'));
    $required = ['mysqli' => 'database', 'mbstring' => 'text handling', 'fileinfo' => 'checking uploaded files', 'json' => 'data exchange', 'session' => 'sign-in'];
    $missing = array_keys(array_filter($required, fn($why, $ext) => !extension_loaded($ext), ARRAY_FILTER_USE_BOTH));
    $add('Server', 'Required PHP extensions', $missing ? 'fail' : 'ok',
        $missing ? 'Missing: ' . implode(', ', $missing) . '. Enable them in php.ini.' : implode(', ', array_keys($required)) . ' are enabled.');
    $optional = ['openssl' => 'secure (TLS) email', 'zlib' => 'logos in PDF reports'];
    $missingOpt = array_keys(array_filter($optional, fn($why, $ext) => !extension_loaded($ext), ARRAY_FILTER_USE_BOTH));
    $add('Server', 'Recommended PHP extensions', $missingOpt ? 'warn' : 'ok',
        $missingOpt ? 'Missing: ' . implode(', ', array_map(fn($e) => "$e (" . $optional[$e] . ')', $missingOpt)) . '.' : 'openssl and zlib are enabled.');
    if ($isWeb) {
        $upload = min(cr_ini_bytes((string)ini_get('upload_max_filesize')), cr_ini_bytes((string)ini_get('post_max_size')));
        $add('Server', 'Upload size limit', $upload >= 10 * 1024 * 1024 ? 'ok' : 'warn',
            'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size')
            . ($upload >= 10 * 1024 * 1024 ? '.' : ' - documents up to 10 MB need at least 10M (post_max_size a little more).'));
        $opcache = function_exists('opcache_get_status') && @opcache_get_status(false);
        $add('Server', 'OPcache', $opcache ? 'ok' : 'warn',
            $opcache ? 'Enabled - pages load faster.' : 'Not enabled. Turn on opcache.enable in php.ini for faster pages.');
    }

    /* ---------------- Configuration ---------------- */
    $cfg = cr_config_all();
    $add('Configuration', 'config/config.php', $cfg['_loaded_from_file'] ? 'ok' : 'fail',
        $cfg['_loaded_from_file'] ? 'Found.' : 'Missing. Copy config/config.example.php to config/config.php and fill it in.');
    $add('Configuration', 'Error display', cr_is_debug() ? 'warn' : 'ok',
        cr_is_debug() ? "'debug' is true: errors are shown on screen. Set it to false on a live system." : 'Errors are logged, not shown to visitors.');
    $dbUser = (string)($cfg['db']['user'] ?? '');
    $dbPass = (string)($cfg['db']['pass'] ?? '');
    $dbProblem = strtolower($dbUser) === 'root' ? "uses the 'root' account" : ($dbPass === '' ? 'has no password' : ($dbPass === 'CHANGE_ME' ? 'still uses the example password' : ''));
    $add('Configuration', 'Database account', $dbProblem ? 'warn' : 'ok',
        $dbProblem ? "The database login $dbProblem. Create a separate MySQL user with a strong password for this system." : "Separate account '$dbUser' with a password.");

    $address = cr_absolute_url('', true);
    if ($address === null) {
        $add('Configuration', 'System address', 'warn', 'Not set. Password-reset emails and the public booking link need it: set app.url in config/config.php or the address in Settings.');
    } else {
        $scheme = strtolower((string)parse_url($address, PHP_URL_SCHEME));
        $host = (string)parse_url($address, PHP_URL_HOST);
        if ($scheme === 'https') $add('Configuration', 'System address', 'ok', $address . ' (HTTPS).');
        elseif (cr_is_lan_host($host)) $add('Configuration', 'System address', 'ok', $address . ' - office network address, HTTPS optional.');
        else $add('Configuration', 'System address', 'fail', $address . ' is a public address without HTTPS. Install a certificate and use https://.');
    }
    if ($isWeb) {
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (stock_is_https()) $add('Configuration', 'This connection', 'ok', 'HTTPS - passwords and data are encrypted.');
        elseif (cr_is_lan_host($host)) $add('Configuration', 'This connection', 'ok', 'Plain HTTP on the office network (' . $host . ').');
        else $add('Configuration', 'This connection', 'fail', 'Plain HTTP over the internet (' . $host . '). Passwords can be read on the way. Use HTTPS.');
    }
    $add('Configuration', 'Outgoing email', cr_mail_configured() ? 'ok' : 'warn',
        cr_mail_configured() ? 'SMTP is set up. Use "Send test email to me" in Settings to confirm it works.' : 'Not set up: forgotten passwords cannot be reset by email and no alert emails are sent.');

    /* ---------------- Files ---------------- */
    foreach (['' => 'Storage folder', 'logs' => 'Logs folder', 'backups' => 'Backups folder', 'documents' => 'Documents folder'] as $sub => $label) {
        $path = cr_storage_path($sub);
        $ok = is_dir($path) && is_writable($path);
        $add('Files', $label, $ok ? 'ok' : 'fail', $ok ? 'Writable.' : $path . ' is missing or not writable by the web server.');
    }

    /* ---------------- Web protection (live request to this server) ---------------- */
    if ($isWeb) {
        $root = (stock_is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))
            . preg_replace('#/backend$#', '', stock_security_app_base_url());
    } else {
        $root = $address !== null ? preg_replace('#/backend$#', '', $address) : '';
    }
    if ($root === '') {
        $add('Web protection', 'Private folders', 'warn', 'Not checked: set the system address first.');
    } else {
        $probe = [
            'config/index.html' => 'config folder',
            'storage/index.html' => 'storage folder (documents, backups)',
            'backend/database/database.sql' => 'database folder',
            'vendor/composer/installed.json' => 'vendor folder',
            'cron/run-scheduled.bat' => 'cron folder',
        ];
        $open = []; $reached = 0;
        foreach ($probe as $path => $label) {
            $code = cr_http_status($root . '/' . $path);
            if ($code > 0) $reached++;
            if ($code >= 200 && $code < 300) $open[] = $label;
        }
        if ($reached === 0) $add('Web protection', 'Private folders', 'warn', 'Could not reach ' . $root . ' from the server to test. Check by hand that /config/ and /storage/ are refused.');
        elseif ($open) $add('Web protection', 'Private folders', 'fail', 'Open to the web: ' . implode(', ', $open) . '. Apache: enable AllowOverride; nginx / IIS: see INSTALLATION_NOTES.');
        else $add('Web protection', 'Private folders', 'ok', 'config, storage, database, vendor and cron are refused.');
    }

    /* ---------------- Database ---------------- */
    if (!$conn) {
        $add('Database', 'Connection', 'fail', 'No database connection.');
        return $checks;
    }
    $add('Database', 'Connection', 'ok', 'MySQL / MariaDB ' . $conn->server_info . '.');
    $schema = (int)cr_setting('schema_version');
    $add('Database', 'Database version', $schema >= CR_SCHEMA_VERSION ? 'ok' : 'fail',
        $schema >= CR_SCHEMA_VERSION ? 'Up to date (version ' . $schema . ').' : "Version $schema, expected " . CR_SCHEMA_VERSION . '. Open any page once to upgrade it.');
    $r = $conn->query("SELECT COUNT(*) AS n FROM users WHERE role_id = 1 AND is_active = 1");
    $admins = $r ? (int)$r->fetch_assoc()['n'] : 0;
    $add('Database', 'Administrators', $admins >= 1 ? 'ok' : 'fail', $admins . ' active administrator account(s).');

    /* ---------------- Operations ---------------- */
    $add('Operations', 'Business settings', cr_setting('settings_reviewed') === '1' ? 'ok' : 'warn',
        cr_setting('settings_reviewed') === '1' ? 'Reviewed (' . cr_setting('currency_code') . ', ' . cr_setting('timezone') . ').' : 'Not reviewed yet: check the business name, currency, timezone and phone code in Settings.');
    $lastRun = (int)cr_setting('scheduled_run_at');
    $add('Operations', 'Scheduled task', $lastRun > time() - 3 * 3600 ? 'ok' : 'warn',
        $lastRun ? 'Last run ' . cr_date(date('Y-m-d H:i:s', $lastRun), true) . ($lastRun > time() - 3 * 3600 ? '.' : ' - it should run every hour (see INSTALLATION_NOTES).') : 'Has never run. Set it up to run every hour (see INSTALLATION_NOTES).');
    $newest = 0;
    foreach (glob(cr_storage_path('backups') . '/*.sql') ?: [] as $f) $newest = max($newest, (int)filemtime($f));
    $add('Operations', 'Backups', $newest > time() - 36 * 3600 ? 'ok' : 'warn',
        $newest ? 'Newest backup: ' . cr_date(date('Y-m-d H:i:s', $newest), true) . '. Copy backups to another device regularly.' : 'No backup yet.');

    return $checks;
}

/** 'fail' if any check failed, else 'warn' if any warned, else 'ok'. */
function cr_system_overall(array $checks): string {
    $statuses = array_column($checks, 'status');
    return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
}
