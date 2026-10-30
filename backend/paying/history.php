<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);
$rental_id = (int)($_GET['rental_id'] ?? 0);
if ($rental_id <= 0) { header('Location: manage.php'); exit; }
$summaryStmt = $conn->prepare("SELECT r.id AS rental_id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status, c.full_name AS customer_name, c.phone_number, c.email, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year, " . cr_sql_total_paid('r') . " AS total_paid FROM rentals r LEFT JOIN customers c ON r.customer_id = c.id LEFT JOIN cars ca ON r.cars_id = ca.id LEFT JOIN makes m ON ca.make_id = m.id LEFT JOIN models mo ON ca.model_id = mo.id LEFT JOIN car_years y ON ca.year_id = y.id WHERE r.id = ? AND r.status <> 'Deleted' LIMIT 1");
$summaryStmt->bind_param('i', $rental_id); $summaryStmt->execute(); $summary = $summaryStmt->get_result()->fetch_assoc(); $summaryStmt->close();
if (!$summary) { header('Location: manage.php'); exit; }
$bill = cr_rental_bill($conn, $rental_id);
if (!$bill) { header('Location: manage.php'); exit; }

$paymentsStmt = $conn->prepare("SELECT id, payment_date, amount, payment_method, payment_note FROM payment WHERE rental_id = ? ORDER BY payment_date ASC, id ASC");
$paymentsStmt->bind_param('i', $rental_id); $paymentsStmt->execute(); $payments = $paymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC); $paymentsStmt->close();

$chargesStmt = $conn->prepare("SELECT charge_date, base_amount, rate, amount FROM rental_charges WHERE rental_id = ? AND charge_type = 'late_interest' ORDER BY charge_date ASC, id ASC");
$chargesStmt->bind_param('i', $rental_id); $chargesStmt->execute(); $charges = $chargesStmt->get_result()->fetch_all(MYSQLI_ASSOC); $chargesStmt->close();

/** Late interest recorded up to and including $date. */
$interestUpTo = function (string $date) use ($charges): float {
    $sum = 0.0;
    foreach ($charges as $c) { if ($c['charge_date'] <= $date) $sum += (float)$c['amount']; }
    return $sum;
};

$carName = trim(($summary['make_name'] ?? '') . ' ' . ($summary['model_name'] ?? '') . ' ' . ($summary['car_year'] ?? '')); if ($carName === '') $carName = $summary['reg_no'] ?? 'N/A';
$totalCost = (float)$summary['total_cost'];
?>
<?php $owed = (float)$bill['remaining'] > 0; ?>
<?php cr_layout_start('Payment history'); ?>
<a class="back-link" href="manage.php">All payments</a>
<?= cr_page_header('Payment history – rental #' . $summary['rental_id'], 'Every payment and late-interest charge for this rental.'); ?>

<div class="stats">
    <div class="stat <?= (float)$bill['late_interest'] > 0 ? 'tone-warning' : 'tone-neutral'; ?>"><div><div class="stat-label">Late interest · <?= h($bill['late_days']); ?> late day(s)</div><div class="stat-value"><?= money((float)$bill['late_interest']); ?></div></div></div>
    <div class="stat"><div><div class="stat-label">Total due</div><div class="stat-value"><?= money((float)$bill['grand_total']); ?></div></div></div>
    <div class="stat tone-success"><div><div class="stat-label">Total paid</div><div class="stat-value"><?= money((float)$summary['total_paid']); ?></div></div></div>
    <div class="stat <?= $owed ? 'tone-danger' : 'tone-success'; ?>"><div><div class="stat-label">Remaining</div><div class="stat-value<?= $owed ? ' text-danger' : ''; ?>"><?= money((float)$bill['remaining']); ?></div></div></div>
</div>

<div class="card">
    <dl class="details">
        <dt>Customer</dt>
        <dd><span class="cell-title"><?= h($summary['customer_name']); ?></span><?php if(($summary['phone_number'] ?? '') !== '' || ($summary['email'] ?? '') !== ''): ?><span class="cell-sub"><?= h($summary['phone_number']); ?><?= $summary['email'] ? ' · ' . h($summary['email']) : ''; ?></span><?php endif; ?></dd>
        <dt>Car</dt>
        <dd><?= h($carName); ?> <span class="plate"><?= h($summary['reg_no']); ?></span></dd>
        <dt>Period</dt>
        <dd><?= h(cr_date($summary['start_date'])) ?> to <?= h(cr_date($summary['end_date'])) ?></dd>
        <dt>Rental cost</dt>
        <dd><?= money($totalCost); ?></dd>
        <dt>Rental status</dt>
        <dd><?= cr_status_badge((string)$summary['rental_status']); ?></dd>
        <dt>Payment status</dt>
        <dd><?= cr_status_badge($bill['status'] === 'Paid' ? 'Paid' : 'Debt', $bill['status']); ?></dd>
    </dl>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Payments</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Payment date</th>
                    <th>Method</th>
                    <th>Note</th>
                    <th class="num">Amount paid</th>
                    <th class="num">Running total</th>
                    <th class="num">Balance after payment</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($payments): ?>
                <?php $i=1; $running=0.0; foreach ($payments as $payment): $running+=(float)$payment['amount']; $balanceAfter=max(round($totalCost + $interestUpTo($payment['payment_date']) - $running, 2), 0); ?>
                <tr>
                    <td class="muted"><?= $i++; ?></td>
                    <td class="nowrap"><?= h(cr_date($payment['payment_date'])) ?></td>
                    <td><?= h($payment['payment_method'] ?? ""); ?></td>
                    <td><?= h($payment['payment_note'] ?? ""); ?></td>
                    <td class="num"><?= money((float)$payment['amount']); ?></td>
                    <td class="num"><?= money($running); ?></td>
                    <td class="num<?= $balanceAfter > 0 ? ' text-danger' : ' muted'; ?>"><?= money($balanceAfter); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No payment has been recorded for this rental yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($charges): ?>
<div class="card card-flush">
    <div class="card-head"><h2>Late interest charges</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th class="num">Unpaid base</th>
                    <th class="num">Rate</th>
                    <th class="num">Interest</th>
                </tr>
            </thead>
            <tbody>
            <?php $j=1; foreach ($charges as $charge): ?>
                <tr>
                    <td class="muted"><?= $j++; ?></td>
                    <td class="nowrap"><?= h(cr_date($charge['charge_date'])) ?></td>
                    <td class="num"><?= money((float)$charge['base_amount']); ?></td>
                    <td class="num"><?= h(cr_number((float)$charge['rate'], 2)); ?>%</td>
                    <td class="num"><?= money((float)$charge['amount']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4">Total late interest</td><td class="num"><?= money((float)$bill['late_interest']); ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>
<?php cr_layout_end(); ?>
