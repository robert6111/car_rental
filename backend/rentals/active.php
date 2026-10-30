<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = '';
$error = '';
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    if (($_SESSION['flash']['type'] ?? '') === 'error') $error = (string)($_SESSION['flash']['text'] ?? '');
    else $msg = (string)($_SESSION['flash']['text'] ?? '');
    unset($_SESSION['flash']);
}

$q = trim($_GET['q'] ?? '');
// Rentals whose car is out: running now, or finished but the car has not been brought back yet.
$where = "WHERE r.returned_at IS NULL AND r.status IN ('Active','Completed')";
$types = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (ca.reg_no LIKE ? OR c.full_name LIKE ? OR m.name LIKE ? OR mo.name LIKE ?)";
    $types = 'ssss';
    $params = [$like, $like, $like, $like];
}

$from = "FROM rentals r
JOIN customers c ON r.customer_id = c.id
JOIN cars ca ON r.cars_id = ca.id
LEFT JOIN makes m ON ca.make_id = m.id
LEFT JOIN models mo ON ca.model_id = mo.id
LEFT JOIN car_years y ON ca.year_id = y.id
$where";
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*) $from", $types, $params);
$sql = "SELECT r.id, r.start_date, r.end_date, r.total_cost, c.full_name AS customer_name, ca.reg_no AS car_reg,
    m.name AS make_name, mo.name AS model_name, y.year AS car_year, r.status AS rental_status
$from
ORDER BY r.end_date ASC, r.id DESC" . cr_page_sql($pg);
$result = false;
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) $result = $stmt->get_result();
    else $error = cr_db_error($stmt->error, 'The rentals could not be loaded. Please try again.');
    $stmt->close();
} else {
    $error = cr_db_error($conn->error, 'The rentals could not be loaded. Please try again.');
}
$today = cr_today();
?>
<?php cr_layout_start('Active rentals'); ?>
<?= cr_page_header('Active rentals', 'Cars that are out with customers right now. Confirm the return when a car comes back.', '<a class="btn btn-secondary" href="manage.php">' . cr_icon('list') . ' All rentals</a>'); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card card-flush">
    <div class="card-head">
        <form method="GET" class="filters mb-0">
            <div class="field grow">
                <label for="q">Search</label>
                <input type="text" id="q" name="q" value="<?= h($q); ?>" placeholder="Reg no, customer, make or model">
            </div>
            <button type="submit"><?= cr_icon('search'); ?> Filter</button>
            <a class="btn btn-secondary" href="active.php">Clear</a>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Start date</th>
                    <th>End date</th>
                    <th class="num">Cost</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if($result && $result->num_rows > 0): $count = $pg['offset'] + 1; ?>
                <?php while($row = $result->fetch_assoc()): ?>
                <?php $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); $overdue = $row['end_date'] < $today; ?>
                <tr>
                    <td class="muted"><?= $count++ ?></td>
                    <td><span class="cell-title"><?= h($row['customer_name']) ?></span><span class="cell-sub">Rental #<?= (int)$row['id'] ?></span></td>
                    <td><?= h($carName !== '' ? $carName : $row['car_reg']) ?><span class="cell-sub"><span class="plate"><?= h($row['car_reg']) ?></span></span></td>
                    <td class="nowrap"><?= h(cr_date($row['start_date'])) ?></td>
                    <td class="nowrap<?= $overdue ? ' text-danger' : '' ?>"><?= h(cr_date($row['end_date'])) ?></td>
                    <td class="num"><?= money((float)$row['total_cost']) ?></td>
                    <td>
                        <?php if($overdue): ?><?= cr_status_badge('Overdue', 'Overdue – not returned') ?>
                        <?php elseif($row['end_date'] === $today): ?><?= cr_status_badge('Due', 'Due back today') ?>
                        <?php else: ?><?= cr_status_badge($row['rental_status']) ?><?php endif; ?>
                    </td>
                    <td class="actions-cell">
                        <details class="row-form">
                            <summary class="btn btn-sm btn-success"><?= cr_icon('check', 'icon-sm') ?> Confirm return</summary>
                            <form method="POST" action="manage.php" class="row-form-body">
                                <input type="hidden" name="back" value="active.php">
                                <input type="hidden" name="rental_id" value="<?= (int)$row['id'] ?>">
                                <label for="return_mileage_<?= (int)$row['id'] ?>">Return mileage <span class="opt">(optional)</span></label>
                                <input type="text" inputmode="numeric" id="return_mileage_<?= (int)$row['id'] ?>" name="return_mileage" placeholder="Mileage">
                                <label for="return_notes_<?= (int)$row['id'] ?>">Notes <span class="opt">(optional)</span></label>
                                <input type="text" id="return_notes_<?= (int)$row['id'] ?>" name="return_notes" placeholder="Notes" maxlength="1000">
                                <button type="submit" name="confirm_return" value="1" class="btn btn-sm btn-success" data-confirm="Confirm that the car of rental #<?= (int)$row['id'] ?> has been returned?">Confirm return</button>
                            </form>
                        </details>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" class="table-empty">No rentals are out right now.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
<?php if($result) $result->free(); $conn->close(); ?>
