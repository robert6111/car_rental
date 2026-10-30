<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);
// Fetch cars with make, model, year, with optional registration/status search
$q = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$where = 'WHERE 1';
$types = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (c.reg_no LIKE ? OR m.name LIKE ? OR mo.name LIKE ?)";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
$car_statuses = ['Available', 'Reserved', 'Rented', 'Not Returned', 'Maintenance'];
if (!in_array($status_filter, $car_statuses, true)) $status_filter = '';
if ($status_filter !== '') {
    $where .= " AND c.availability_status=?";
    $types .= 's';
    $params[] = $status_filter;
}
$from = "FROM cars c
        JOIN makes m ON c.make_id = m.id
        JOIN models mo ON c.model_id = mo.id
        JOIN car_years y ON c.year_id = y.id
        $where";
$pg = cr_page();
$pg = cr_page_count($conn, $pg, "SELECT COUNT(*) $from", $types, $params);
$sql = "SELECT c.id, c.reg_no, c.vin, c.color, c.current_mileage, c.insurance_expiry, c.inspection_expiry, c.branch, c.price_per_day, c.availability_status, m.name AS make, mo.name AS model, y.year AS car_year
        $from
        ORDER BY c.id DESC" . cr_page_sql($pg);

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$msg = '';
if (isset($_GET['added'])) $msg = 'Car added successfully.';
if (isset($_GET['updated'])) $msg = 'Car updated successfully.';
if (isset($_GET['deleted'])) $msg = 'Car deleted successfully.';
$error = '';
$deleteErrors = [
    'has_history'   => 'This car has history (rentals, reservations, damages, services, documents, fuel logs, expenses or tasks) and cannot be deleted. Move it to Maintenance instead to take it out of service.',
    'has_rentals'   => 'This car has rental records and cannot be deleted. Move it to Maintenance instead.',
    'not_found'     => 'The car was not found (it may already have been deleted).',
    'delete_failed' => 'The car could not be deleted. Please try again.',
];
$errorCode = (string)($_GET['error'] ?? '');
if (isset($deleteErrors[$errorCode])) $error = $deleteErrors[$errorCode];
?>

<?php cr_layout_start('Manage cars'); ?>
<?= cr_page_header('Manage cars', 'All cars in the fleet with their papers, price and status.', '<a class="btn" href="add.php">Add car</a>'); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card">
    <form method="GET" class="filters">
        <div class="field grow">
            <label for="q">Search reg no or car</label>
            <input type="search" id="q" name="q" value="<?= h($q ?? ''); ?>" placeholder="Example: 34 ABC 123">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($car_statuses as $s): ?><option value="<?= h($s) ?>" <?= ($status_filter ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Filter</button>
        <a class="btn btn-secondary" href="all_cars.php">Clear</a>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reg no</th>
                    <th>Car</th>
                    <th>Status</th>
                    <th class="num">Mileage</th>
                    <th>Papers expire</th>
                    <th>Branch</th>
                    <th class="num">Price / day</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()) : ?>
                <tr>
                    <td class="muted"><?= (int)$row['id'] ?></td>
                    <td><span class="plate"><?= h($row['reg_no']) ?></span></td>
                    <td><span class="cell-title"><?= h($row['make'] . ' ' . $row['model']) ?></span><span class="cell-sub"><?= h(trim($row['car_year'] . ((string)($row['color'] ?? '') !== '' ? ' · ' . $row['color'] : ''))) ?></span></td>
                    <td><?= cr_status_badge((string)$row['availability_status'], ucfirst(strtolower((string)$row['availability_status']))) ?></td>
                    <td class="num"><?= h($row['current_mileage'] ?? '') ?></td>
                    <td class="nowrap">Insurance: <?= h(cr_date($row['insurance_expiry'] ?? '') ?: '—') ?><span class="cell-sub">Inspection: <?= h(cr_date($row['inspection_expiry'] ?? '') ?: '—') ?></span></td>
                    <td><?= h($row['branch'] ?? '') ?></td>
                    <td class="num"><?= money((float)($row['price_per_day'] ?? 0)) ?></td>
                    <td><div class="actions">
                        <a class="btn btn-sm btn-secondary" href="edit.php?id=<?= (int)$row['id'] ?>">Edit</a>
                        <a class="btn btn-sm btn-danger" href="<?= h(stock_action_url('delete.php?id=' . (int)$row['id'])); ?>" data-confirm="Delete this car? Cars with any history cannot be deleted.">Delete</a>
                    </div></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="9" class="table-empty">No cars found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
