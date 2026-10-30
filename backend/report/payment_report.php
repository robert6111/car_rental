<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$reg_no = $_GET['reg_no'] ?? '';
$where = 'WHERE 1';
if ($from_date !== '') $where .= " AND p.payment_date >= '" . $conn->real_escape_string($from_date) . "'";
if ($to_date !== '') $where .= " AND p.payment_date <= '" . $conn->real_escape_string($to_date) . "'";
if ($reg_no !== '') $where .= " AND ca.reg_no LIKE '%" . $conn->real_escape_string($reg_no) . "%'";
$result = $conn->query("SELECT p.id, p.payment_date, p.amount, r.id AS rental_id, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM payment p
    JOIN rentals r ON p.rental_id = r.id
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    LEFT JOIN makes m ON ca.make_id = m.id
    LEFT JOIN models mo ON ca.model_id = mo.id
    LEFT JOIN car_years y ON ca.year_id = y.id
    $where
    ORDER BY p.payment_date DESC, p.id DESC");
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Payment Report</title></head>
<body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Payment Report</h2><br>
<form method="GET" style="margin-bottom:20px; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
<div><label>From:</label><br><input type="date" name="from_date" value="<?= h($from_date); ?>"></div>
<div><label>To:</label><br><input type="date" name="to_date" value="<?= h($to_date); ?>"></div>
<div><label>Reg No:</label><br><input type="text" name="reg_no" value="<?= h($reg_no); ?>" placeholder="Example: 34 ABC 123"></div>
<div><button type="submit" class="btn btn-primary">Apply Filters</button>
<a href="payment_report_export.php?from_date=<?= urlencode($from_date); ?>&to_date=<?= urlencode($to_date); ?>&reg_no=<?= urlencode($reg_no); ?>" class="btn btn-danger" target="_blank">Download PDF</a></div>
</form>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Date</th><th>Customer</th><th>Car</th><th>Reg No</th><th>Rental</th><th>Amount</th></tr>
<?php if($result && $result->num_rows): $i=1; $total=0; while($row=$result->fetch_assoc()): $total += (float)$row['amount']; $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); ?>
<tr><td><?= $i++; ?></td><td><?= h($row['payment_date']); ?></td><td><?= h($row['full_name']); ?></td><td><?= h($carName !== '' ? $carName : $row['reg_no']); ?></td><td><?= h($row['reg_no']); ?></td><td>#<?= h($row['rental_id']); ?></td><td><?= number_format((float)$row['amount'],2); ?> TRY</td></tr>
<?php endwhile; ?><tr><th colspan="6">Total</th><th><?= number_format($total,2); ?> TRY</th></tr><?php else: ?><tr><td colspan="7">No payment records found.</td></tr><?php endif; ?>
</table></div></div></div></body></html>
