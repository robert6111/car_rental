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
<?php
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$pdfUrl = 'export_pdf.php?status=' . urlencode($status_filter) . '&from_date=' . urlencode($from_date) . '&to_date=' . urlencode($to_date) . '&reg_no=' . urlencode($reg_no);
cr_layout_start('Rental report');
?>
<?= cr_page_header('Rental report', 'Rentals by status and period, with their total value.', '<a class="btn btn-secondary" href="' . h($pdfUrl) . '" target="_blank">Download PDF</a>'); ?>

<div class="card">
    <form method="GET" class="filters mb-0">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status"><option value="">All</option><?php foreach (['Pending', 'Active', 'Completed', 'Canceled', 'Not returned'] as $s): ?><option value="<?= h($s); ?>" <?= $status_filter === $s ? 'selected' : ''; ?>><?= h($s); ?></option><?php endforeach; ?></select>
        </div>
        <div class="field">
            <label for="from_date">From</label>
            <input type="date" id="from_date" name="from_date" value="<?= h($from_date); ?>">
        </div>
        <div class="field">
            <label for="to_date">To</label>
            <input type="date" id="to_date" name="to_date" value="<?= h($to_date); ?>">
        </div>
        <div class="field grow">
            <label for="reg_no">Reg no or customer</label>
            <input type="text" id="reg_no" name="reg_no" value="<?= h($reg_no); ?>" placeholder="Example: 34 ABC 123">
        </div>
        <button type="submit">Filter</button>
        <a class="btn btn-secondary" href="report.php">Clear</a>
    </form>
    <p class="hint mb-0">Rentals that overlap the chosen period are included. The total excludes canceled rentals.</p>
</div>

<div class="stats">
    <div class="stat"><div><div class="stat-label">Rentals found</div><div class="stat-value"><?= (int)$pg['total']; ?></div></div></div>
    <div class="stat tone-neutral"><div><div class="stat-label">Counted in total</div><div class="stat-value"><?= (int)$counted; ?></div></div></div>
    <div class="stat tone-success"><div><div class="stat-label">Total value</div><div class="stat-value"><?= money($total); ?></div></div></div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Rentals</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Customer</th><th>Car</th><th>Start</th><th>End</th><th class="num">Cost</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($rows): $i = $pg['offset'] + 1; foreach ($rows as $row): $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); $notReturned = ($row['status'] === 'Completed' && $row['returned_at'] === null && $row['end_date'] < $today); ?>
                <tr>
                    <td class="muted"><?= $i++; ?></td>
                    <td><span class="cell-title"><?= h($row['full_name']); ?></span></td>
                    <td><?= h($carName !== '' ? $carName : $row['reg_no']); ?><span class="cell-sub"><span class="plate"><?= h($row['reg_no']); ?></span></span></td>
                    <td class="nowrap"><?= h(cr_date($row['start_date'])) ?></td>
                    <td class="nowrap"><?= h(cr_date($row['end_date'])) ?></td>
                    <td class="num"><?= money((float)$row['total_cost']); ?></td>
                    <td><?= $notReturned ? cr_status_badge('Not returned', 'Completed (not returned)') : cr_status_badge($row['status']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="table-empty">No rentals found.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot><tr><td colspan="5">Total (<?= (int)$counted; ?> rental<?= $counted === 1 ? '' : 's'; ?>, canceled excluded)</td><td class="num"><?= money($total); ?></td><td></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
