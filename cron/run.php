<?php
/*
 * Scheduled jobs. Run from the command line only, ideally every hour:
 *   Windows (XAMPP):  cron\run-scheduled.bat   (add it to Task Scheduler)
 *   Linux:            0 * * * *  php /path/to/car_rental/cron/run.php
 *
 * Every job is safe to run again; the daily ones only do their work once per day.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$started = microtime(true);
require __DIR__ . '/../backend/includes/app.php';
require __DIR__ . '/../backend/includes/db.php';
require_once __DIR__ . '/../backend/includes/rental_status.php';
require_once __DIR__ . '/../backend/backup/auto_backup.php';

$failed = false;
function cron_log(string $line): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
}

// 1. Rental and car statuses, late interest.
try {
    sync_rental_and_car_status($conn, true);
    cron_log('Statuses and late interest updated.');
} catch (Throwable $e) {
    $failed = true;
    cron_log('Status update FAILED: ' . $e->getMessage());
}

// 2. Daily backup and clean-up of old automatic backups.
try {
    if (run_daily_auto_backup($conn)) {
        $removed = cr_backup_prune();
        cron_log('Daily backup OK (' . cr_setting('auto_backup_on') . ')' . ($removed ? ", removed $removed old backup(s)." : '.'));
    } else {
        $failed = true;
        cron_log('Daily backup FAILED or already running. Check storage/backups is writable.');
    }
} catch (Throwable $e) {
    $failed = true;
    cron_log('Daily backup FAILED: ' . $e->getMessage());
}

// 3. Old rate-limit records.
$conn->query('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)');

// 4. Keep the error log from growing without limit (keeps one previous file).
$log = cr_storage_path('logs') . '/php-error.log';
if (is_file($log) && filesize($log) > 10 * 1024 * 1024) {
    @rename($log, $log . '.1');
    cron_log('Error log rotated.');
}

cr_settings_save($conn, ['scheduled_run_at' => (string)time()]);
cron_log(sprintf('Done in %.1fs.', microtime(true) - $started));
exit($failed ? 1 : 0);
