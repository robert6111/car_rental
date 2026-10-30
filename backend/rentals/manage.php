<?php
require_once __DIR__ . '/../includes/auth.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = '';
if (isset($_GET['added'])) $msg = 'Rental added successfully.';
if (isset($_GET['updated'])) $msg = 'Rental updated successfully.';
if (isset($_GET['deleted'])) $msg = 'Rental marked as Deleted.';
if (isset($_GET['completed'])) $msg = 'Rental marked as Completed / Returned.';

if (isset($_GET['action'], $_GET['rental_id'])) {
    stock_validate_action_token();
    $rental_id = intval($_GET['rental_id']);
    if ($_GET['action'] === 'del') {
        $stmt = $conn->prepare("UPDATE rentals SET status='Deleted' WHERE id=?");
        $stmt->bind_param("i", $rental_id);
        $stmt->execute(); $stmt->close();
        sync_rental_and_car_status($conn);
        header('Location: manage.php?deleted=1'); exit;
    }
    if ($_GET['action'] === 'complete') {
        $returned_by = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE rentals SET status='Completed', completed_at=NOW(), returned_by=? WHERE id=?");
        $stmt->bind_param("ii", $returned_by, $rental_id);
        $stmt->execute(); $stmt->close();
        sync_rental_and_car_status($conn);
        header('Location: manage.php?completed=1'); exit;
    }
}

$q = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$where = "WHERE 1";
if ($q !== '') { $safe = '%' . $conn->real_escape_string($q) . '%'; $where .= " AND (ca.reg_no LIKE '$safe' OR c.full_name LIKE '$safe' OR m.name LIKE '$safe' OR mo.name LIKE '$safe')"; }
if ($status_filter !== '') $where .= " AND r.status='" . $conn->real_escape_string($status_filter) . "'";

$sql = "SELECT r.id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status,
               c.full_name AS customer_name, ca.reg_no AS car_reg, m.name AS make_name, mo.name AS model_name, y.year AS car_year,
               IFNULL(SUM(p.amount), 0) AS total_paid
        FROM rentals r
        JOIN customers c ON r.customer_id = c.id
        JOIN cars ca ON r.cars_id = ca.id
        LEFT JOIN makes m ON ca.make_id = m.id
        LEFT JOIN models mo ON ca.model_id = mo.id
        LEFT JOIN car_years y ON ca.year_id = y.id
        LEFT JOIN payment p ON p.rental_id = r.id
        $where
        GROUP BY r.id, r.start_date, r.end_date, r.total_cost, r.status, c.full_name, ca.reg_no, m.name, mo.name, y.year
        ORDER BY r.id DESC";
$result = $conn->query($sql);
if (!$result) die('Query failed: ' . $conn->error);
?>
<head>
    <title>Manage Rentals</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <style>table{width:100%;border-collapse:collapse;margin-top:15px}th,td{padding:10px;border:1px solid #ddd;text-align:left}th{background-color:#333;color:#fff}tr:nth-child(even){background-color:#f2f2f2}.btn{padding:6px 10px;text-decoration:none;margin-right:5px;border-radius:3px;color:#fff;display:inline-block}.btn-warning{background-color:#f0ad4e}.btn-danger{background-color:#d9534f}.btn-success{background-color:#16a34a}.btn-primary{background-color:#0275d8;margin-bottom:10px}.success-msg{color:green;font-weight:700;margin:10px 0}.badge-action{background:#fef3c7;color:#92400e;padding:4px 8px;border-radius:999px;font-weight:bold;display:inline-block;margin-top:4px}.mini{display:block;color:#64748b;font-size:12px;margin-top:3px}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:end}</style>
</head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Manage Rentals</h2><?php if ($msg): ?><p class="success-msg"><?= h($msg); ?></p><?php endif; ?><a href="add.php" class="btn btn-primary">Add New Rental</a>
<form method="GET" class="filter-box"><div><label>Search by reg no / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><label>Status:</label><br><select name="status"><option value="">All</option><?php foreach(['Pending','Active','Completed','Canceled','Deleted'] as $s): ?><option value="<?= h($s) ?>" <?= $status_filter===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div><div><button type="submit" class="btn btn-primary">Search</button><a href="manage.php" class="btn btn-danger">Clear</a></div></form>
<div class="table-responsive"><table><thead><tr><th>#</th><th>Customer</th><th>Car</th><th>Registration No</th><th>Start</th><th>End</th><th>Cost</th><th>Paid</th><th>Balance</th><th>Status / Action Needed</th><th>Actions</th></tr></thead><tbody>
<?php if($result->num_rows > 0): $count=1; ?><?php while($row = $result->fetch_assoc()): ?>
<?php $statusColor = match($row['rental_status']) {'Active'=>'green','Completed'=>'blue','Deleted'=>'darkred','Canceled'=>'red','Pending'=>'orange',default=>'black'}; $carName=trim(($row['make_name']??'').' '.($row['model_name']??'').' '.($row['car_year']??'')); if($carName==='') $carName=$row['car_reg']; $bill=rental_bill_summary((float)$row['total_cost'], (float)$row['total_paid'], $row['end_date']); $sameDayNeedsComplete=overdue_same_day_completion_needed($row['rental_status'], $row['start_date'], $row['end_date']); ?>
<tr><td><?= $count++ ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($carName) ?></td><td><?= h($row['car_reg']) ?></td><td><?= h($row['start_date']) ?></td><td><?= h($row['end_date']) ?></td><td><?= number_format((float)$row['total_cost'],2) ?> TRY</td><td><?= number_format((float)$row['total_paid'],2) ?> TRY</td><td><?= number_format((float)$bill['remaining'],2) ?> TRY<?php if($bill['late_interest']>0): ?><span class="mini">Late interest: <?= number_format((float)$bill['late_interest'],2); ?> TRY</span><?php endif; ?></td><td><span style="color:<?= $statusColor ?>;font-weight:bold;"><?= h($row['rental_status']) ?></span><?php if($sameDayNeedsComplete): ?><span class="badge-action">Same-day rental: confirm return manually</span><?php endif; ?></td><td><a href="edit.php?id=<?= h($row['id']) ?>" class="btn btn-warning btn-sm">Edit</a><a href="contract.php?id=<?= h($row['id']) ?>" class="btn btn-primary btn-sm" target="_blank">Contract</a><?php if($row['rental_status']==='Active'): ?><a href="<?= h(stock_action_url('not_returned.php?create=' . (int)$row['id'])); ?>" class="btn btn-danger btn-sm">Not Returned</a><a href="<?= h(stock_action_url('manage.php?action=complete&rental_id=' . (int)$row['id'])); ?>" onclick="return confirm('Mark this rental as returned/completed?')" class="btn btn-success btn-sm">Complete</a><?php endif; ?><a href="<?= h(stock_action_url('manage.php?action=del&rental_id=' . (int)$row['id'])); ?>" onclick="return confirm('Mark this rental as deleted?')" class="btn btn-danger btn-sm">Delete</a></td></tr>
<?php endwhile; ?><?php else: ?><tr><td colspan="11" class="text-center">No rentals found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php $result->free(); $conn->close(); ?></body>
