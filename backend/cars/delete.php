<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

stock_require_permission('cars_manage');
stock_validate_action_token();
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: all_cars.php?error=not_found");
    exit;
}

/*
 * A car is only deleted when nothing refers to it. Cars with any history (rentals of any
 * status, reservations, damages, services, documents, fuel logs, expenses, tasks) are kept so
 * reports stay correct; staff should move them to Maintenance instead.
 */
$linkedTables = [
    'rentals'      => 'cars_id',
    'reservations' => 'car_id',
    'car_damages'  => 'car_id',
    'car_service'  => 'car_id',
    'documents'    => 'car_id',
    'fuel_logs'    => 'car_id',
    'expenses'     => 'car_id',
    'staff_tasks'  => 'car_id',
];

// Only check tables/columns that exist in this database.
$existing = [];
$colRes = $conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $existing[strtolower($c['TABLE_NAME']) . '.' . strtolower($c['COLUMN_NAME'])] = true;
    }
}

$hasHistory = false;
foreach ($linkedTables as $table => $column) {
    if (!isset($existing[$table . '.' . $column])) continue;
    $check = $conn->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
    if (!$check) continue;
    $check->bind_param("i", $id);
    if ($check->execute()) {
        $check->store_result();
        if ($check->num_rows > 0) $hasHistory = true;
    }
    $check->close();
    if ($hasHistory) break;
}

if ($hasHistory) {
    header("Location: all_cars.php?error=has_history");
    exit;
}

$stmt = $conn->prepare("DELETE FROM cars WHERE id = ?");
$stmt->bind_param("i", $id);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
if (!$ok) {
    cr_db_error($stmt->error); // logs the real error
}
$stmt->close();

if ($ok && $affected > 0) {
    header("Location: all_cars.php?deleted=1");
} elseif ($ok) {
    header("Location: all_cars.php?error=not_found");
} else {
    header("Location: all_cars.php?error=delete_failed");
}
exit;
