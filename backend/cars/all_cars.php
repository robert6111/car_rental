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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Management</title>
    <style>
        .status-NotReturned { background-color: #7f1d1d; color: #fff; font-weight: bold; }
        .status-Reserved { background-color: #0d6efd; color: #fff; }
    </style>
</head>
<body>
    <?php    
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
?>
    <div class="content-page">
<h2>Car List</h2>
<?php if ($msg): ?><p style="color:green;font-weight:bold;"><?= h($msg); ?></p><?php endif; ?>
<?php if ($error): ?><p style="color:red;font-weight:bold;"><?= h($error); ?></p><?php endif; ?>
<a href="add.php">Add New Car</a>
<form method="GET" style="margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;"><div><label>Search Reg No / Car:</label><br><input type="text" name="q" value="<?= h($q ?? ''); ?>" placeholder="Example: 34 ABC 123"></div><div><label>Status:</label><br><select name="status"><option value="">All</option><?php foreach($car_statuses as $s): ?><option value="<?= h($s) ?>" <?= ($status_filter ?? '')===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div><div><button type="submit">Search</button> <a href="all_cars.php">Clear</a></div></form>
<table border="1" cellpadding="10">
    <tr>
        <th>ID</th>
        <th>Registration No</th>
        <th>Make</th>
        <th>Model</th>
        <th>Year</th>
        <th>Status</th>
        <th>Color</th>
        <th>Mileage</th>
        <th>Insurance</th>
        <th>Inspection</th>
        <th>Branch</th>
        <th>Price/Day</th>
        <th>Actions</th>
    </tr>
    <?php while($row = $result->fetch_assoc()) : ?>
    <tr>
        <td><?= (int)$row['id'] ?></td>
        <td><?= h($row['reg_no']) ?></td>
        <td><?= h($row['make']) ?></td>
        <td><?= h($row['model']) ?></td>
        <td><?= h($row['car_year']) ?></td>
        <td class="status status-<?= h(preg_replace('/[^A-Za-z]/', '', (string)$row['availability_status'])) ?>"><?= h($row['availability_status']) ?></td>
        <td><?= h($row['color'] ?? '') ?></td>
        <td><?= h($row['current_mileage'] ?? '') ?></td>
        <td><?= h(cr_date($row['insurance_expiry'] ?? '')) ?></td>
        <td><?= h(cr_date($row['inspection_expiry'] ?? '')) ?></td>
        <td><?= h($row['branch'] ?? '') ?></td>
        <td><?= money((float)($row['price_per_day'] ?? 0)) ?></td>

        <td>
            <a href="edit.php?id=<?= (int)$row['id'] ?>">Edit</a> |
            <a href="<?= h(stock_action_url('delete.php?id=' . (int)$row['id'])); ?>" onclick="return confirm('Delete this car? Cars with any history cannot be deleted.')">Delete</a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>
<?= cr_pagination($pg); ?>
    </div>
</body>
</html>
