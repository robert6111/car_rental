<?php
/*
 * Go-live check from the command line:  php cron/check.php
 * Same checks as Settings > System check (the live web-protection test needs the
 * system address to be set). Exit code: 0 = ready, 1 = something must be fixed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../backend/includes/app.php';
require_once __DIR__ . '/../backend/includes/send_email.php';
require_once __DIR__ . '/../backend/includes/system_check.php';

mysqli_report(MYSQLI_REPORT_OFF);
$db = cr_config('db');
$conn = @new mysqli((string)$db['host'], (string)$db['user'], (string)$db['pass'], (string)$db['name'], (int)($db['port'] ?: 3306));
if ($conn->connect_errno) {
    fwrite(STDERR, 'Database connection failed: ' . $conn->connect_error . PHP_EOL);
    $conn = null;
    $GLOBALS['__cr_settings'] = []; // use defaults; don't let cr_setting() try to connect again
} else {
    $conn->set_charset('utf8mb4');
    cr_settings_load($conn, true);
}

$checks = cr_system_checks($conn);
$mark = ['ok' => '  OK  ', 'warn' => ' CHECK', 'fail' => '  FIX '];
$group = '';
foreach ($checks as $c) {
    if ($c['group'] !== $group) {
        $group = $c['group'];
        echo PHP_EOL . strtoupper($group) . PHP_EOL;
    }
    echo '[' . $mark[$c['status']] . '] ' . str_pad($c['label'], 28) . $c['detail'] . PHP_EOL;
}
$overall = cr_system_overall($checks);
echo PHP_EOL . ($overall === 'ok' ? 'Ready.' : ($overall === 'warn' ? 'Works - review the CHECK items.' : 'Fix the FIX items before going live.')) . PHP_EOL;
exit($overall === 'fail' ? 1 : 0);
