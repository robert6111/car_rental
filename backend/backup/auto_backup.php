<?php
require_once __DIR__ . '/../includes/db.php';

function car_rental_export_database(mysqli $conn): string {
    $sql = "-- Car Rental database backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = [];
    $result = $conn->query('SHOW TABLES');
    while ($row = $result->fetch_array()) $tables[] = $row[0];
    foreach ($tables as $table) {
        $create = $conn->query('SHOW CREATE TABLE `' . $conn->real_escape_string($table) . '`')->fetch_assoc();
        $sql .= "\nDROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n";
        $rows = $conn->query('SELECT * FROM `' . $conn->real_escape_string($table) . '`');
        while ($r = $rows->fetch_assoc()) {
            $cols = array_map(fn($c) => '`' . str_replace('`','``',$c) . '`', array_keys($r));
            $vals = [];
            foreach ($r as $v) $vals[] = is_null($v) ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'";
            $sql .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function run_daily_auto_backup(mysqli $conn): void {
    $dir = cr_storage_path('backups');
    $todayFile = $dir . '/car_rental_auto_' . date('Y-m-d') . '.sql';
    if (!file_exists($todayFile)) {
        @file_put_contents($todayFile, car_rental_export_database($conn));
    }
}
?>
