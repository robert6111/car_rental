<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    $payment_method = trim((string)($_POST['payment_method'] ?? 'Cash'));
    $payment_note = trim((string)($_POST['payment_note'] ?? ''));
    if ($rental_id <= 0) {
        $err = 'Invalid rental selected.';
    } else {
        $err = cr_record_payment($conn, $rental_id, (string)($_POST['amount_paid'] ?? ''), $payment_method, $payment_note, (int)($_SESSION['user_id'] ?? 0));
    }
    $_SESSION['flash'] = $err === null
        ? ['type' => 'success', 'text' => 'Payment added successfully.']
        : ['type' => 'error', 'text' => $err];
    // Return to the same search and page of the list.
    $backQuery = [];
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') $backQuery['q'] = $q;
    $backPage = (int)($_POST['page'] ?? 1);
    if ($backPage > 1) $backQuery['page'] = $backPage;
    $backPerPage = (int)($_POST['per_page'] ?? 0);
    if (in_array($backPerPage, CR_PAGE_SIZES, true)) $backQuery['per_page'] = $backPerPage;
    header('Location: manage.php' . ($backQuery ? '?' . http_build_query($backQuery) : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$q = trim((string)($_GET['q'] ?? ''));
$billable = "'" . implode("','", CR_RENTAL_BILLABLE_STATUSES) . "'";
$like = '%' . $q . '%';
$countSql = "SELECT COUNT(*) FROM rentals r
    LEFT JOIN customers c ON r.customer_id=c.id
    LEFT JOIN cars ca ON r.cars_id=ca.id
    WHERE r.status IN ($billable)";
if ($q !== '') $countSql .= " AND (ca.reg_no LIKE ? OR c.full_name LIKE ?)";
$pg = $q !== ''
    ? cr_page_count($conn, cr_page(), $countSql, 'ss', [$like, $like])
    : cr_page_count($conn, cr_page(), $countSql);
$sql = "SELECT r.id AS rental_id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status,
        " . cr_sql_total_paid('r') . " AS total_paid,
        " . cr_sql_late_interest('r') . " AS late_interest,
        " . cr_sql_late_days('r') . " AS late_days,
        (SELECT COUNT(*) FROM payment p2 WHERE p2.rental_id = r.id) AS payment_count,
        (SELECT MAX(p3.payment_date) FROM payment p3 WHERE p3.rental_id = r.id) AS last_payment_date,
        c.full_name AS customer_name, c.phone_number AS customer_phone, c.email AS customer_email,
        ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM rentals r
    LEFT JOIN customers c ON r.customer_id=c.id
    LEFT JOIN cars ca ON r.cars_id=ca.id
    LEFT JOIN makes m ON ca.make_id=m.id
    LEFT JOIN models mo ON ca.model_id=mo.id
    LEFT JOIN car_years y ON ca.year_id=y.id
    WHERE r.status IN ($billable)";
if ($q !== '') $sql .= " AND (ca.reg_no LIKE ? OR c.full_name LIKE ?)";
$sql .= " ORDER BY r.id DESC" . cr_page_sql($pg);
$stmt = $conn->prepare($sql);
if ($q !== '') {
    $stmt->bind_param('ss', $like, $like);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
$methods = cr_payment_methods();
$lateRate = late_interest_daily_fraction();
?>
<?php cr_layout_start('Payments', ['head' => '<style>
.pay-box { display: inline-block; vertical-align: top; }
.pay-box > summary { list-style: none; }
.pay-box > summary::-webkit-details-marker { display: none; }
.pay-box[open] > summary { background: var(--primary-hover); }
.pay-form { display: grid; gap: 4px; width: 230px; margin-top: 8px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); white-space: normal; text-align: left; }
.pay-form label { margin: 4px 0 0; font-size: 12.5px; }
.pay-form input, .pay-form select { min-height: 32px !important; padding: 4px 8px !important; font-size: 13.5px !important; }
.pay-form button { margin-top: 6px; }
.card-head > .filters { flex: 1; }
</style>']); ?>
<?= cr_page_header('Payments', 'Track rental payments, balances, late-payment interest and payment history.'); ?>
<?php if ($flash): ?><?= cr_alert(($flash['type'] ?? '') === 'success' ? 'success' : 'error', (string)($flash['text'] ?? '')); ?><?php endif; ?>
<?= cr_alert('info', 'Late interest: ' . ($lateRate > 0 ? cr_number($lateRate * 100, 2) . '% per day of the unpaid rental cost, for every day after the rental end date until it is paid. Interest already charged stays owed.' : 'switched off.')); ?>

<div class="card card-flush">
    <div class="card-head">
        <form method="GET" class="filters mb-0">
            <div class="field grow">
                <label for="q">Search</label>
                <input type="text" id="q" name="q" value="<?= h($q); ?>" placeholder="Reg no or customer">
            </div>
            <button type="submit"><?= cr_icon('search'); ?> Filter</button>
            <a class="btn btn-secondary" href="manage.php">Clear</a>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Rental</th>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Period</th>
                    <th class="num">Total due</th>
                    <th class="num">Paid</th>
                    <th class="num">Remaining</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if($result && $result->num_rows > 0): while($rental=$result->fetch_assoc()): ?>
            <?php $carName=trim(($rental['make_name']??'').' '.($rental['model_name']??'').' '.($rental['car_year']??'')); if($carName==='') $carName=$rental['reg_no']??'N/A'; $bill=rental_bill_summary((float)$rental['total_cost'], (float)$rental['total_paid'], $rental['end_date'], (float)$rental['late_interest'], (int)$rental['late_days']); $lastPayment=$rental['last_payment_date']?cr_date($rental['last_payment_date']):'No payment yet'; $rid=(int)$rental['rental_id']; ?>
                <tr>
                    <td class="nowrap"><span class="cell-title">#<?= h($rental['rental_id']); ?></span><span class="cell-sub"><?= h($rental['rental_status']); ?></span></td>
                    <td><span class="cell-title"><?= h($rental['customer_name']??'N/A'); ?></span><?php if(($rental['customer_phone']??'') !== ''): ?><span class="cell-sub nowrap"><?= h($rental['customer_phone']); ?></span><?php endif; ?></td>
                    <td><?= h($carName); ?><span class="cell-sub"><span class="plate"><?= h($rental['reg_no']??'N/A'); ?></span></span></td>
                    <td class="nowrap"><?= h(cr_date($rental['start_date'])) ?><span class="cell-sub">to <?= h(cr_date($rental['end_date'])) ?></span></td>
                    <td class="num">
                        <?= money((float)$bill['grand_total']); ?>
                        <span class="cell-sub">Rental <?= money((float)$rental['total_cost']); ?></span>
                        <?php if((float)$bill['late_interest'] > 0): ?><span class="cell-sub text-danger">+ <?= money((float)$bill['late_interest']); ?> interest</span><span class="cell-sub text-danger"><?= h($bill['late_days']); ?> late day(s)</span><?php endif; ?>
                    </td>
                    <td class="num"><?= money((float)$rental['total_paid']); ?><span class="cell-sub"><?= (int)$rental['payment_count']; ?> payment(s)</span><span class="cell-sub">Last: <?= h($lastPayment); ?></span></td>
                    <td class="num"><?php if((float)$bill['remaining'] > 0): ?><strong class="text-danger"><?= money((float)$bill['remaining']); ?></strong><?php else: ?><span class="muted"><?= money((float)$bill['remaining']); ?></span><?php endif; ?><span class="cell-sub"><?= cr_status_badge($bill['status'] === 'Paid' ? 'Paid' : 'Debt', $bill['status']); ?></span></td>
                    <td class="actions-cell">
                        <a href="history.php?rental_id=<?= $rid; ?>" class="btn btn-sm btn-secondary"><?= cr_icon('clock', 'icon-sm'); ?> History</a>
                        <?php if((float)$bill['remaining']>0): ?>
                        <details class="pay-box">
                            <summary class="btn btn-sm"><?= cr_icon('plus', 'icon-sm'); ?> Add payment</summary>
                            <form action="manage.php" method="POST" class="pay-form">
                                <input type="hidden" name="rental_id" value="<?= $rid; ?>">
                                <input type="hidden" name="q" value="<?= h($q); ?>">
                                <input type="hidden" name="page" value="<?= (int)$pg['page']; ?>">
                                <?php if(isset($_GET['per_page'])): ?><input type="hidden" name="per_page" value="<?= (int)$pg['per_page']; ?>"><?php endif; ?>
                                <label for="amount_paid_<?= $rid; ?>">Amount <span class="req">*</span></label>
                                <input type="text" inputmode="decimal" id="amount_paid_<?= $rid; ?>" name="amount_paid" autocomplete="off" data-max="<?= h(number_format((float)$bill['remaining'], 2, '.', '')); ?>" placeholder="Max <?= h(cr_number($bill['remaining'])); ?>" title="Up to <?= money($bill['remaining']); ?>" required>
                                <label for="payment_method_<?= $rid; ?>">Method</label>
                                <select id="payment_method_<?= $rid; ?>" name="payment_method"><?php foreach ($methods as $m): ?><option value="<?= h($m); ?>"><?= h($m); ?></option><?php endforeach; ?></select>
                                <label for="payment_note_<?= $rid; ?>">Note <span class="opt">(optional)</span></label>
                                <input type="text" id="payment_note_<?= $rid; ?>" name="payment_note" maxlength="255" placeholder="Receipt no. or note">
                                <button type="submit" name="add_payment" class="btn btn-sm"><?= cr_icon('check', 'icon-sm'); ?> Add payment</button>
                            </form>
                        </details>
                        <?php else: ?>
                        <?= cr_status_badge('Paid', 'Fully paid'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="table-empty">No rentals found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
