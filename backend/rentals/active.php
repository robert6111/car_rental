<?php
require_once __DIR__ . '/../includes/auth.php';
error_reporting(0);
ini_set('display_errors', 0);
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$q = trim($_GET['q'] ?? '');
$where = "WHERE r.status = 'Active'";
if ($q !== '') { $safe = '%' . $conn->real_escape_string($q) . '%'; $where .= " AND (ca.reg_no LIKE '$safe' OR c.full_name LIKE '$safe' OR m.name LIKE '$safe' OR mo.name LIKE '$safe')"; }

$sql = "SELECT r.id, r.start_date, r.end_date, r.total_cost, c.full_name AS customer_name, ca.reg_no AS car_reg,
    m.name AS make_name, mo.name AS model_name, y.year AS car_year, r.status AS rental_status
FROM rentals r
JOIN customers c ON r.customer_id = c.id
JOIN cars ca ON r.cars_id = ca.id
LEFT JOIN makes m ON ca.make_id = m.id
LEFT JOIN models mo ON ca.model_id = mo.id
LEFT JOIN car_years y ON ca.year_id = y.id
$where
ORDER BY r.id DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Active Rentals</title>
<link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><style>.badge-action{background:#fef3c7;color:#92400e;padding:4px 8px;border-radius:999px;font-weight:bold}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap}</style>
</head><body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Active Rentals</h2><form method="GET" class="filter-box"><div><label>Search by registration number / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><button type="submit" class="btn btn-primary">Search</button> <a href="active.php" class="btn btn-danger">Clear</a></div></form>
<table border="1" cellpadding="10"><tr><th>#</th><th>Customer</th><th>Car</th><th>Registration No</th><th>Start Date</th><th>End Date</th><th>Cost</th><th>Action Needed</th><th>Status</th></tr>
<?php if($result && $result->num_rows > 0): $count = 1; ?><?php while($row = $result->fetch_assoc()): ?>
<?php $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); $sameDay=overdue_same_day_completion_needed($row['rental_status'], $row['start_date'], $row['end_date']); ?>
<tr><td><?= $count++ ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($carName !== '' ? $carName : $row['car_reg']) ?></td><td><?= h($row['car_reg']) ?></td><td><?= h($row['start_date']) ?></td><td><?= h($row['end_date']) ?></td><td><?= number_format((float)$row['total_cost'], 2) ?> TRY</td><td><?= $sameDay ? '<span class="badge-action">Same-day rental: confirm return manually</span>' : 'Auto-completes after end date' ?></td><td><?= h($row['rental_status']) ?></td></tr>
<?php endwhile; ?><?php else: ?><tr><td colspan="9" class="text-center">No active rentals found.</td></tr><?php endif; ?></table><a href="manage.php">Back to All Rentals</a></div></body></html>
<?php if($result) $result->free(); $conn->close(); ?>
