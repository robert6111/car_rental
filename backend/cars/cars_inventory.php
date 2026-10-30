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

<?php cr_layout_start('Car inventory'); ?>
<?= cr_page_header('Car inventory', 'See which cars are free and move cars in and out of maintenance.', $canManage ? '<a class="btn" href="add.php">' . cr_icon('plus') . ' Add car</a>' : ''); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card">
    <div class="card-head"><h2>All cars</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reg no</th>
                    <th>Car</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th class="num">Price per day</th>
                    <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php $count = (int)$pg['offset'] + 1; ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php $status = (string)$row['availability_status']; ?>
                    <tr>
                        <td class="muted"><?= $count++ ?></td>
                        <td><span class="plate"><?= h($row['reg_no']) ?></span></td>
                        <td class="cell-title"><?= h(trim($row['make'] . ' ' . $row['model'])) ?></td>
                        <td><?= h($row['car_year']) ?></td>
                        <td>
                            <?php if ($status === 'Not Returned'): ?>
                                <span title="Rental has ended but the return has not been confirmed"><?= cr_status_badge($status, 'Not returned') ?></span>
                            <?php else: ?>
                                <?= cr_status_badge($status) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= money($row['price_per_day']) ?></td>
                        <?php if ($canManage): ?>
                        <td class="actions-cell">
                            <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-secondary"><?= cr_icon('edit', 'icon-sm') ?> Edit</a>
                            <?php if ($status === 'Maintenance'): ?>
                                <a href="<?= h(stock_action_url('cars_inventory.php?action=available&car_id=' . (int)$row['id'])); ?>"
                                   data-confirm="Take this car out of maintenance?"
                                   class="btn btn-sm btn-success"><?= cr_icon('check', 'icon-sm') ?> Make available</a>
                            <?php elseif (!in_array($status, ['Rented', 'Not Returned'], true)): ?>
                                <a href="<?= h(stock_action_url('cars_inventory.php?action=maintenance&car_id=' . (int)$row['id'])); ?>"
                                   data-confirm="Move this car to maintenance? It cannot be rented until it is made available again."
                                   class="btn btn-sm btn-warning"><?= cr_icon('wrench', 'icon-sm') ?> Move to maintenance</a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="table-empty">No cars found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
<?php
$result->free();
?>
