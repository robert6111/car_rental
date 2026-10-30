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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Payment History</title></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Payment History</h2>
<div class="payment-history-summary"><div><h3><?= h($summary['customer_name']); ?></h3><p><?= h($summary['phone_number']); ?> <?= $summary['email'] ? ' | ' . h($summary['email']) : ''; ?></p></div><div><strong>Rental #<?= h($summary['rental_id']); ?></strong><p><?= h($carName); ?> | Reg: <?= h($summary['reg_no']); ?></p></div><div><strong><?= h(cr_date($summary['start_date'])) ?> to <?= h(cr_date($summary['end_date'])) ?></strong><p>Status: <?= h($summary['rental_status']); ?></p></div></div>
<div class="payment-total-grid"><div class="card-box"><p>Rental Cost</p><h2><?= money($totalCost); ?></h2></div><div class="card-box"><p>Late Interest</p><h2><?= money((float)$bill['late_interest']); ?></h2><p><?= h($bill['late_days']); ?> late day(s)</p></div><div class="card-box"><p>Total Due</p><h2><?= money((float)$bill['grand_total']); ?></h2></div><div class="card-box"><p>Total Paid</p><h2><?= money((float)$summary['total_paid']); ?></h2></div><div class="card-box"><p>Remaining</p><h2><?= money((float)$bill['remaining']); ?></h2></div><div class="card-box"><p>Payment Status</p><h2><?= h($bill['status']); ?></h2></div></div>
<div style="margin:14px 0;"><a href="manage.php" class="btn btn-primary">Back to Payments</a></div>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><thead><tr><th>#</th><th>Payment Date</th><th>Method</th><th>Note</th><th>Amount Paid</th><th>Running Total</th><th>Balance After Payment</th></tr></thead><tbody>
<?php if ($payments): ?><?php $i=1; $running=0.0; foreach ($payments as $payment): $running+=(float)$payment['amount']; $balanceAfter=max(round($totalCost + $interestUpTo($payment['payment_date']) - $running, 2), 0); ?><tr><td><?= $i++; ?></td><td><?= h(cr_date($payment['payment_date'])) ?></td><td><?= h($payment['payment_method'] ?? ""); ?></td><td><?= h($payment['payment_note'] ?? ""); ?></td><td><?= money((float)$payment['amount']); ?></td><td><?= money($running); ?></td><td><?= money($balanceAfter); ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="7">No payment has been recorded for this rental yet.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($charges): ?>
<h3 style="margin-top:20px;">Late Interest Charges</h3>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><thead><tr><th>#</th><th>Date</th><th>Unpaid Base</th><th>Rate</th><th>Interest</th></tr></thead><tbody>
<?php $j=1; foreach ($charges as $charge): ?><tr><td><?= $j++; ?></td><td><?= h(cr_date($charge['charge_date'])) ?></td><td><?= money((float)$charge['base_amount']); ?></td><td><?= h(cr_number((float)$charge['rate'], 2)); ?>%</td><td><?= money((float)$charge['amount']); ?></td></tr><?php endforeach; ?>
<tr><th colspan="4">Total late interest</th><th><?= money((float)$bill['late_interest']); ?></th></tr>
</tbody></table></div>
<?php endif; ?>
</div></body></html>
