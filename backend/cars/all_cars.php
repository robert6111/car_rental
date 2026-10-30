<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);
// Fetch cars with make, model, year, with optional registration/status search
$q = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$where = 'WHERE 1';
if ($q !== '') {
    $safe = '%' . $conn->real_escape_string($q) . '%';
    $where .= " AND (c.reg_no LIKE '$safe' OR m.name LIKE '$safe' OR mo.name LIKE '$safe')";
}
if ($status_filter !== '') {
    $where .= " AND c.availability_status='" . $conn->real_escape_string(ucfirst(strtolower($status_filter))) . "'";
}
$sql = "SELECT c.id, c.reg_no, c.vin, c.color, c.current_mileage, c.insurance_expiry, c.inspection_expiry, c.branch, c.price_per_day, c.availability_status, m.name AS make, mo.name AS model, y.year AS car_year
        FROM cars c
        JOIN makes m ON c.make_id = m.id
        JOIN models mo ON c.model_id = mo.id
        JOIN car_years y ON c.year_id = y.id
        $where
        ORDER BY c.id DESC";

$result = $conn->query($sql);
$msg = '';
if (isset($_GET['added'])) $msg = 'Car added successfully.';
if (isset($_GET['updated'])) $msg = 'Car updated successfully.';
if (isset($_GET['deleted'])) $msg = 'Car deleted successfully.';
$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'has_rentals') $error = 'This car has rental records and cannot be deleted.';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Car Management</title>
    <link rel="stylesheet" href="style.css">
             <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/backend/includes/css/all_cars.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
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
<form method="GET" style="margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;"><div><label>Search Reg No / Car:</label><br><input type="text" name="q" value="<?= h($q ?? ''); ?>" placeholder="Example: 34 ABC 123"></div><div><label>Status:</label><br><select name="status"><option value="">All</option><?php foreach(['Available','Reserved','Rented','Maintenance'] as $s): ?><option value="<?= h($s) ?>" <?= ($status_filter ?? '')===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div><div><button type="submit">Search</button> <a href="all_cars.php">Clear</a></div></form>
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
        <td><?= h($row['insurance_expiry'] ?? '') ?></td>
        <td><?= h($row['inspection_expiry'] ?? '') ?></td>
        <td><?= h($row['branch'] ?? '') ?></td>
        <td><?= number_format((float)($row['price_per_day'] ?? 0),2) ?> TRY</td>

        <td>
            <a href="edit.php?id=<?= $row['id'] ?>">Edit</a> |
            <a href="<?= h(stock_action_url('delete.php?id=' . (int)$row['id'])); ?>" onclick="return confirm('Delete this car?')">Delete</a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>
    </div>
</body>
</html>
