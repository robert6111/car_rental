<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';

/**
 * Payment report filters. KEEP IDENTICAL to payment_report_filters() in payment_report_export.php.
 * Returns [whereSql, bindTypes, bindParams, cleanFilters].
 */
function payment_report_filters(array $in): array {
    $from = (string)($in['from_date'] ?? '');
    $to = (string)($in['to_date'] ?? '');
    $search = trim((string)($in['reg_no'] ?? ''));
    if (!cr_valid_date($from)) $from = '';
    if (!cr_valid_date($to)) $to = '';

    $where = ["r.status <> 'Deleted'"];
    $types = '';
    $params = [];
    if ($from !== '') { $where[] = 'p.payment_date >= ?'; $types .= 's'; $params[] = $from; }
    if ($to !== '') { $where[] = 'p.payment_date <= ?'; $types .= 's'; $params[] = $to; }
    if ($search !== '') {
        $where[] = '(ca.reg_no LIKE ? OR c.full_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ss'; $params[] = $like; $params[] = $like;
    }
    return ['WHERE ' . implode(' AND ', $where), $types, $params, ['from_date' => $from, 'to_date' => $to, 'reg_no' => $search]];
}

[$where, $types, $params, $filters] = payment_report_filters($_GET);
$from_date = $filters['from_date'];
$to_date = $filters['to_date'];
$reg_no = $filters['reg_no'];

// Payment count and total cover the whole filtered set, not just the visible page.
$stmt = $conn->prepare("SELECT COUNT(*) AS n, IFNULL(SUM(p.amount),0) AS total
    FROM payment p
    JOIN rentals r ON p.rental_id = r.id
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    $where");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$sumRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total = (float)$sumRow['total'];
$paymentCount = (int)$sumRow['n'];
$pg = cr_page_total(cr_page(), $paymentCount);

$stmt = $conn->prepare("SELECT p.id, p.payment_date, p.amount, r.id AS rental_id, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM payment p
    JOIN rentals r ON p.rental_id = r.id
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    LEFT JOIN makes m ON ca.make_id = m.id
    LEFT JOIN models mo ON ca.model_id = mo.id
    LEFT JOIN car_years y ON ca.year_id = y.id
    $where
    ORDER BY p.payment_date DESC, p.id DESC" . cr_page_sql($pg));
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Payment Report</title></head>
<body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Payment Report</h2><br>
<form method="GET" style="margin-bottom:20px; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
<div><label>From:</label><br><input type="date" name="from_date" value="<?= h($from_date); ?>"></div>
<div><label>To:</label><br><input type="date" name="to_date" value="<?= h($to_date); ?>"></div>
<div><label>Reg No / Customer:</label><br><input type="text" name="reg_no" value="<?= h($reg_no); ?>" placeholder="Example: 34 ABC 123"></div>
<div><button type="submit" class="btn btn-primary">Apply Filters</button>
<a href="payment_report_export.php?from_date=<?= urlencode($from_date); ?>&to_date=<?= urlencode($to_date); ?>&reg_no=<?= urlencode($reg_no); ?>" class="btn btn-danger" target="_blank">Download PDF</a></div>
</form>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Date</th><th>Customer</th><th>Car</th><th>Reg No</th><th>Rental</th><th>Amount</th></tr>
<?php if($result && $result->num_rows): $i=$pg['offset']+1; while($row=$result->fetch_assoc()): $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); ?>
<tr><td><?= $i++; ?></td><td><?= h(cr_date($row['payment_date'])) ?></td><td><?= h($row['full_name']); ?></td><td><?= h($carName !== '' ? $carName : $row['reg_no']); ?></td><td><?= h($row['reg_no']); ?></td><td>#<?= h($row['rental_id']); ?></td><td><?= money((float)$row['amount']); ?></td></tr>
<?php endwhile; ?><tr><th colspan="6">Total (<?= $paymentCount; ?> payment<?= $paymentCount === 1 ? '' : 's'; ?>)</th><th><?= money($total); ?></th></tr><?php else: ?><tr><td colspan="7">No payment records found.</td></tr><?php endif; ?>
</table></div><?= cr_pagination($pg); ?></div></div></body></html>
