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
<?php
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$pdfUrl = 'payment_report_export.php?from_date=' . urlencode($from_date) . '&to_date=' . urlencode($to_date) . '&reg_no=' . urlencode($reg_no);
cr_layout_start('Payment report');
?>
<?= cr_page_header('Payment report', 'Payments received in a period, with their total.', '<a class="btn btn-secondary" href="' . h($pdfUrl) . '" target="_blank">' . cr_icon('download') . ' Download PDF</a>'); ?>

<div class="card">
    <form method="GET" class="filters mb-0">
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
        <button type="submit"><?= cr_icon('search'); ?> Filter</button>
        <a class="btn btn-secondary" href="payment_report.php">Clear</a>
    </form>
</div>

<div class="stats">
    <div class="stat"><div class="stat-icon"><?= cr_icon('receipt'); ?></div><div><div class="stat-label">Payments</div><div class="stat-value"><?= (int)$paymentCount; ?></div></div></div>
    <div class="stat tone-success"><div class="stat-icon"><?= cr_icon('wallet'); ?></div><div><div class="stat-label">Total received</div><div class="stat-value"><?= money($total); ?></div></div></div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Payments</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Date</th><th>Customer</th><th>Car</th><th>Rental</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php if ($rows): $i = $pg['offset'] + 1; foreach ($rows as $row): $carName = trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? '') . ' ' . ($row['car_year'] ?? '')); ?>
                <tr>
                    <td class="muted"><?= $i++; ?></td>
                    <td class="nowrap"><?= h(cr_date($row['payment_date'])) ?></td>
                    <td><span class="cell-title"><?= h($row['full_name']); ?></span></td>
                    <td><?= h($carName !== '' ? $carName : $row['reg_no']); ?><span class="cell-sub"><span class="plate"><?= h($row['reg_no']); ?></span></span></td>
                    <td>#<?= h($row['rental_id']); ?></td>
                    <td class="num"><?= money((float)$row['amount']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="table-empty">No payment records found.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot><tr><td colspan="5">Total (<?= $paymentCount; ?> payment<?= $paymentCount === 1 ? '' : 's'; ?>)</td><td class="num"><?= money($total); ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
