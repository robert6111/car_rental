<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = $error = "";
$canManage = stock_has_permission('cars_manage');

// Status actions: move a car to Maintenance, or make a Maintenance car available again.
if (isset($_GET['action'], $_GET['car_id']) && in_array($_GET['action'], ['maintenance', 'available'], true)) {
    stock_require_permission('cars_manage');
    stock_validate_action_token();
    $car_id = intval($_GET['car_id']);
    $action = $_GET['action'];

    $stmt = $conn->prepare("SELECT availability_status FROM cars WHERE id = ?");
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $carRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $result = 'not_found';
    if ($carRow) {
        $current = (string)$carRow['availability_status'];
        if ($action === 'maintenance') {
            if (in_array($current, ['Rented', 'Not Returned'], true)) {
                $result = 'busy';
            } elseif ($current === 'Maintenance') {
                $result = 'already';
            } else {
                $stmt = $conn->prepare("UPDATE cars SET availability_status = 'Maintenance' WHERE id = ?");
                $stmt->bind_param("i", $car_id);
                $result = $stmt->execute() ? 'maintenance_ok' : 'failed';
                if ($result === 'failed') cr_db_error($stmt->error);
                $stmt->close();
            }
        } else {
            if ($current !== 'Maintenance') {
                $result = 'not_maintenance';
            } else {
                $stmt = $conn->prepare("UPDATE cars SET availability_status = 'Available' WHERE id = ? AND availability_status = 'Maintenance'");
                $stmt->bind_param("i", $car_id);
                $result = $stmt->execute() ? 'available_ok' : 'failed';
                if ($result === 'failed') cr_db_error($stmt->error);
                $stmt->close();
                if ($result === 'available_ok') {
                    // The car may have bookings: recalculate Rented / Reserved / Not Returned.
                    cr_sync_car_statuses($conn, $car_id);
                }
            }
        }
    }
    header('Location: cars_inventory.php?result=' . urlencode($result));
    exit;
}

$resultMessages = [
    'maintenance_ok'  => ['msg', 'Car moved to maintenance. It cannot be rented until it is made available again.'],
    'available_ok'    => ['msg', 'Car is out of maintenance. Its status now follows its rentals and reservations.'],
    'busy'            => ['error', 'This car is currently rented or has not been returned, so it cannot be moved to maintenance. Confirm the return first.'],
    'already'         => ['error', 'This car is already in maintenance.'],
    'not_maintenance' => ['error', 'Only cars in maintenance can be made available here.'],
    'not_found'       => ['error', 'Car not found.'],
    'failed'          => ['error', 'The car status could not be changed. Please try again.'],
];
$resultCode = (string)($_GET['result'] ?? '');
if (isset($resultMessages[$resultCode])) {
    if ($resultMessages[$resultCode][0] === 'msg') $msg = $resultMessages[$resultCode][1];
    else $error = $resultMessages[$resultCode][1];
}

$pg = cr_page();
$pg = cr_page_count($conn, $pg, "SELECT COUNT(*) FROM cars");
$sql = "SELECT c.id, c.reg_no, c.availability_status,
               m.name AS make, mo.name AS model, y.year AS car_year,
               c.price_per_day
        FROM cars c
        LEFT JOIN makes m ON c.make_id = m.id
        LEFT JOIN models mo ON c.model_id = mo.id
        LEFT JOIN car_years y ON c.year_id = y.id
        ORDER BY c.id DESC" . cr_page_sql($pg);

$result = $conn->query($sql);
if (!$result) {
    error_log('Query failed: ' . $conn->error);
    http_response_code(500);
    exit('Something went wrong. Please try again.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cars Inventory</title>
    <style>
        .status-badge-NotReturned { color: #fff; background: #7f1d1d; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
    </style>
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
    <h2>Cars Inventory</h2>

    <?php if ($msg): ?>
        <div style="color:green;"><?= h($msg) ?></div>
    <?php elseif ($error): ?>
        <div style="color:red;"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <div style="margin-bottom:20px;">
        <a href="add.php" class="btn btn-primary">Add New Car</a>
    </div>
    <?php endif; ?>
<table border="1" cellpadding="10">
    <tr>
        <th>#</th>
        <th>Registration No</th>
        <th>Make</th>
        <th>Model</th>
        <th>Year</th>
        <th>Status</th>
        <th>Price per Day</th>
        <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
    </tr>
    <?php if ($result->num_rows > 0): ?>
        <?php $count = (int)$pg['offset'] + 1; ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <?php $status = (string)$row['availability_status']; ?>
            <tr>
                <td><?= $count++ ?></td>
                <td><?= h($row['reg_no']) ?></td>
                <td><?= h($row['make']) ?></td>
                <td><?= h($row['model']) ?></td>
                <td><?= h($row['car_year']) ?></td>
                <td>
                    <?php if ($status === 'Not Returned'): ?>
                        <span class="status-badge-NotReturned" title="Rental has ended but the return has not been confirmed">Not Returned</span>
                    <?php else: ?>
                        <?= h($status) ?>
                    <?php endif; ?>
                </td>
                <td><?= money($row['price_per_day']) ?></td>
                <?php if ($canManage): ?>
                <td>
                    <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-warning">Edit</a> <br><br>
                    <?php if ($status === 'Maintenance'): ?>
                        <a href="<?= h(stock_action_url('cars_inventory.php?action=available&car_id=' . (int)$row['id'])); ?>"
                           onclick="return confirm('Take this car out of maintenance?')"
                           class="btn btn-sm btn-primary">Make available</a>
                    <?php elseif (!in_array($status, ['Rented', 'Not Returned'], true)): ?>
                        <a href="<?= h(stock_action_url('cars_inventory.php?action=maintenance&car_id=' . (int)$row['id'])); ?>"
                           onclick="return confirm('Move this car to maintenance? It cannot be rented until it is made available again.')"
                           class="btn btn-sm btn-danger">Move to maintenance</a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="<?= $canManage ? 8 : 7 ?>" style="text-align:center;">No cars found.</td>
        </tr>
    <?php endif; ?>
</table>
<?= cr_pagination($pg); ?>

</div>

<?php
$result->free();
?>
</body>
</html>
