<?php
/*
 * Database backups (used by the Backup page, the scheduled task and the dashboard fallback).
 * Rows are streamed straight to the file, so large databases do not need much memory.
 */
require_once __DIR__ . '/../includes/db.php';

/** Tables whose rows are not worth keeping in a backup (structure is still saved). */
const CR_BACKUP_SKIP_DATA = ['rate_limits'];

/** Writes a full SQL backup to $file. Returns true on success. */
function cr_backup_write(mysqli $conn, string $file): bool {
    $tmp = $file . '.part';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return false;
    $ok = true;
    // TIMESTAMP columns are exported and restored in UTC so they come back unchanged on any server.
    $tzRow = $conn->query('SELECT @@session.time_zone AS tz');
    $sessionTz = $tzRow ? (string)$tzRow->fetch_assoc()['tz'] : '';
    $conn->query("SET time_zone = '+00:00'");
    try {
        fwrite($fh, "-- " . cr_company_name() . " car rental database backup\n-- Generated: " . date('Y-m-d H:i:s T') . "\n\n"
            . "SET NAMES utf8mb4;\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $tables = [];
        $res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $res->fetch_array()) $tables[] = $row[0];

        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $conn->query("SHOW CREATE TABLE $q")->fetch_assoc();
            fwrite($fh, "\nDROP TABLE IF EXISTS $q;\n" . $create['Create Table'] . ";\n");
            if (in_array($table, CR_BACKUP_SKIP_DATA, true)) continue;

            $rows = $conn->query("SELECT * FROM $q", MYSQLI_USE_RESULT);
            if (!$rows) throw new RuntimeException('Could not read ' . $table . ': ' . $conn->error);
            $cols = null;
            $batch = [];
            while ($r = $rows->fetch_assoc()) {
                if ($cols === null) $cols = implode(',', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($r)));
                $vals = [];
                foreach ($r as $v) $vals[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'";
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) fwrite($fh, "INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
            $rows->free();
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
    } catch (Throwable $e) {
        error_log('Backup failed: ' . $e->getMessage());
        $ok = false;
    } finally {
        if ($sessionTz !== '') {
            $stmt = $conn->prepare('SET time_zone = ?');
            $stmt->bind_param('s', $sessionTz);
            $stmt->execute();
            $stmt->close();
        }
    }
    $ok = fclose($fh) && $ok;
    if ($ok && @rename($tmp, $file)) return true;
    @unlink($tmp);
    return false;
}

/** Deletes automatic backups older than the "Keep automatic backups" setting. Returns how many were removed. */
function cr_backup_prune(): int {
    $keepDays = max(1, (int)cr_setting('backup_keep_days'));
    $cutoff = date('Y-m-d', strtotime(cr_today() . " -$keepDays days"));
    $removed = 0;
    foreach (glob(cr_storage_path('backups') . '/car_rental_auto_*.sql') ?: [] as $f) {
        if (preg_match('/car_rental_auto_(\d{4}-\d{2}-\d{2})\.sql$/', $f, $m) && $m[1] < $cutoff && @unlink($f)) $removed++;
    }
    foreach (glob(cr_storage_path('backups') . '/*.part') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
    return $removed;
}

/** Makes today's automatic backup once per day. Safe to call from several places at once. */
function run_daily_auto_backup(mysqli $conn): bool {
    $today = cr_today();
    if (cr_setting('auto_backup_on') === $today) return true;
    $lock = $conn->query("SELECT GET_LOCK('car_rental_backup', 0) AS l");
    if (!$lock || (int)$lock->fetch_assoc()['l'] !== 1) return false;
    try {
        $file = cr_storage_path('backups') . '/car_rental_auto_' . $today . '.sql';
        $ok = is_file($file) || cr_backup_write($conn, $file);
        if ($ok) {
            cr_settings_save($conn, ['auto_backup_on' => $today]);
            cr_backup_prune();
        }
        return $ok;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('car_rental_backup')");
    }
}
