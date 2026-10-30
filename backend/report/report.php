<?php
require_once __DIR__ . '/../includes/admin_only.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

/**
 * Rental report filters. KEEP IDENTICAL to rental_report_filters() in export_pdf.php.
 * Returns [whereSql, bindTypes, bindParams, cleanFilters].
 */
function rental_report_filters(array $in): array {
    $status = (string)($in['status'] ?? '');
    $from = (string)($in['from_date'] ?? '');
    $to = (string)($in['to_date'] ?? '');
    $search = trim((string)($in['reg_no'] ?? ''));
    if (!in_array($status, ['Pending', 'Active', 'Completed', 'Canceled', 'Not returned'], true)) $status = '';
    if (!cr_valid_date($from)) $from = '';
    if (!cr_valid_date($to)) $to = '';

    $where = ["r.status <> 'Deleted'"];
    $types = '';
    $params = [];
    if ($status === 'Not returned') {
        $where[] = "r.status = 'Completed' AND r.returned_at IS NULL AND r.end_date < CURDATE()";
    } elseif ($status !== '') {
        $where[] = 'r.status = ?'; $types .= 's'; $params[] = $status;
    }
    // A rental is included when it overlaps the chosen period.
    if ($to !== '') { $where[] = 'r.start_date <= ?'; $types .= 's'; $params[] = $to; }
    if ($from !== '') { $where[] = 'r.end_date >= ?'; $types .= 's'; $params[] = $from; }
    if ($search !== '') {
        $where[] = '(ca.reg_no LIKE ? OR c.full_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ss'; $params[] = $like; $params[] = $like;
    }
    return ['WHERE ' . implode(' AND ', $where), $types, $params, ['status' => $status, 'from_date' => $from, 'to_date' => $to, 'reg_no' => $search]];
}

[$where, $types, $params, $filters] = rental_report_filters($_GET);
$status_filter = $filters['status'];
$from_date = $filters['from_date'];
$to_date = $filters['to_date'];
$reg_no = $filters['reg_no'];

// Row count and the total (canceled excluded) cover the whole filtered set, not just the visible page.
$stmt = $conn->prepare("SELECT COUNT(*) AS n, COUNT(CASE WHEN r.status <> 'Canceled' THEN 1 END) AS counted, IFNULL(SUM(CASE WHEN r.status <> 'Canceled' THEN r.total_cost ELSE 0 END),0) AS total
    FROM rentals r
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    $where");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$sumRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total = (float)$sumRow['total'];
$counted = (int)$sumRow['counted'];
$pg = cr_page_total(cr_page(), (int)$sumRow['n']);

$stmt = $conn->prepare("SELECT r.id, r.start_date, r.end_date, r.total_cost, r.status, r.returned_at, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM rentals r
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ca ON r.cars_id = ca.id
    LEFT JOIN makes m ON ca.make_id = m.id
    LEFT JOIN models mo ON ca.model_id = mo.id
    LEFT JOIN car_years y ON ca.year_id = y.id
    $where
    ORDER BY r.start_date DESC, r.id DESC" . cr_page_sql($pg));
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
$today = cr_today();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Rental Report</title></head>
<body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><div class="card-box"><h2>Rental Report</h2><br>
<form method="GET" style="margin-bottom:20px; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
<div><label>Status:</label><br><select name="status"><option value="">All</option><?php foreach(['Pending','Active','Completed','Canceled','Not returned'] as $s): ?><option value="<?= h($s); ?>" <?= $status_filter===$s?'selected':''; ?>><?= h($s); ?></option><?php endforeach; ?></select></div>
<div><label>From:</label><br><input type="date" name="from_date" value="<?= h($from_date); ?>"></div>
<div><label>To:</label><br><input type="date" name="to_date" value="<?= h($to_date); ?>"></div>
<div><label>Reg No / Customer:</label><br><input type="text" name="reg_no" value="<?= h($reg_no); ?>" placeholder="Example: 34 ABC 123"></div>
<div><button type="submit" class="btn btn-primary">Apply Filters</button>
<a href="export_pdf.php?status=<?= urlencode($status_filter); ?>&from_date=<?= urlencode($from_date); ?>&to_date=<?= urlencode($to_date); ?>&reg_no=<?= urlencode($reg_no); ?>" class="btn btn-danger" target="_blank">Download PDF</a></div>
</form>
<p style="color:#64748b;font-size:13px;">Rentals that overlap the chosen period are included. The total excludes canceled rentals.</p>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Customer</th><th>Car</th><th>Reg No</th><th>Start</th><th>End</th><th>Cost</th><th>Status</th></tr>
<?php if($result && $result->num_rows): $i=$pg['offset']+1; while($row=$result->fetch_assoc()): $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); $statusText = ($row['status'] === 'Completed' && $row['returned_at'] === null && $row['end_date'] < $today) ? 'Completed (not returned)' : $row['status']; ?>
<tr><td><?= $i++; ?></td><td><?= h($row['full_name']); ?></td><td><?= h($carName !== '' ? $carName : $row['reg_no']); ?></td><td><?= h($row['reg_no']); ?></td><td><?= h(cr_date($row['start_date'])) ?></td><td><?= h(cr_date($row['end_date'])) ?></td><td><?= money((float)$row['total_cost']); ?></td><td><?= h($statusText); ?></td></tr>
<?php endwhile; ?><tr><th colspan="6">Total (<?= (int)$counted; ?> rental<?= $counted === 1 ? '' : 's'; ?>, canceled excluded)</th><th><?= money($total); ?></th><th></th></tr><?php else: ?><tr><td colspan="8">No rentals found.</td></tr><?php endif; ?>
</table></div><?= cr_pagination($pg); ?></div></div></body></html>
