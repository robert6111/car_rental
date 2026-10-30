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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Active Rentals</title>
<link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><style>.badge-action{background:#fef3c7;color:#92400e;padding:4px 8px;border-radius:999px;font-weight:bold}.badge-danger{background:#fee2e2;color:#991b1b}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap}.return-form{display:flex;gap:4px;flex-wrap:wrap}.return-form input{width:90px;padding:4px}.return-form button{padding:5px 8px;border:0;border-radius:3px;background:#16a34a;color:#fff;cursor:pointer}.success-msg{color:green;font-weight:700;margin:10px 0}.error-msg{color:#b91c1c;font-weight:700;margin:10px 0}</style>
</head><body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Active Rentals</h2><?php if ($msg): ?><p class="success-msg"><?= h($msg); ?></p><?php endif; ?><?php if ($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?><form method="GET" class="filter-box"><div><label>Search by registration number / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><button type="submit" class="btn btn-primary">Search</button> <a href="active.php" class="btn btn-danger">Clear</a></div></form>
<table border="1" cellpadding="10"><tr><th>#</th><th>Customer</th><th>Car</th><th>Registration No</th><th>Start Date</th><th>End Date</th><th>Cost</th><th>Status</th><th>Action</th></tr>
<?php if($result && $result->num_rows > 0): $count = $pg['offset'] + 1; ?><?php while($row = $result->fetch_assoc()): ?>
<?php $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); $overdue = $row['end_date'] < $today; ?>
<tr><td><?= $count++ ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($carName !== '' ? $carName : $row['car_reg']) ?></td><td><?= h($row['car_reg']) ?></td><td><?= h(cr_date($row['start_date'])) ?></td><td><?= h(cr_date($row['end_date'])) ?></td><td><?= money((float)$row['total_cost']) ?></td>
<td><?php if($overdue): ?><span class="badge-action badge-danger">Overdue – not returned</span><?php elseif($row['end_date'] === $today): ?><span class="badge-action">Due back today</span><?php else: ?><?= h($row['rental_status']) ?><?php endif; ?></td>
<td><form method="POST" action="manage.php" class="return-form" onsubmit="return confirm('Confirm that the car of rental #<?= (int)$row['id'] ?> has been returned?')"><input type="hidden" name="back" value="active.php"><input type="hidden" name="rental_id" value="<?= (int)$row['id'] ?>"><input type="text" inputmode="numeric" name="return_mileage" placeholder="Mileage"><input type="text" name="return_notes" placeholder="Notes" maxlength="1000"><button type="submit" name="confirm_return" value="1">Confirm return</button></form></td></tr>
<?php endwhile; ?><?php else: ?><tr><td colspan="9" class="text-center">No rentals are out right now.</td></tr><?php endif; ?></table><?= cr_pagination($pg); ?><a href="manage.php">Back to All Rentals</a></div></body></html>
<?php if($result) $result->free(); $conn->close(); ?>
