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
<head><title>Manage Payments - Car Rental</title><link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css"><style>.status-paid{background:#dcfce7;color:#166534;font-weight:bold;text-align:center;border-radius:999px;padding:6px 10px;display:inline-block}.status-debt{background:#fee2e2;color:#991b1b;font-weight:bold;text-align:center;border-radius:999px;padding:6px 10px;display:inline-block}.inline-payment-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent!important;border:0!important;padding:0!important;box-shadow:none!important}.inline-payment-form input{max-width:130px}.success-msg{color:green;font-weight:700}.error-msg{color:red;font-weight:700}.payment-mini{color:#64748b;font-size:12px;display:block;margin-top:3px}.customer-payment-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 4px 14px rgba(15,23,42,.06);margin-bottom:16px}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap}</style></head>
<body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Manage Payments</h2><div class="customer-payment-card"><h3>Customer Payment Overview</h3><p>Track rental payment, balance, late-payment interest, and full payment history.</p><p><strong>Late interest:</strong> <?= $lateRate > 0 ? h(cr_number($lateRate * 100, 2)) . '% per day of the unpaid rental cost, for every day after the rental end date until it is paid. Interest already charged stays owed.' : 'switched off.' ?></p></div>
<?php if ($flash): ?><p class="<?= ($flash['type'] ?? '') === 'success' ? 'success-msg' : 'error-msg'; ?>"><?= h($flash['text'] ?? ''); ?></p><?php endif; ?>
<form method="GET" class="filter-box"><div><label>Search by reg no / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><button type="submit" class="btn btn-primary">Search</button> <a href="manage.php" class="btn btn-danger">Clear</a></div></form>
<div class="table-responsive"><table border="1" cellpadding="10" cellspacing="0" class="table"><thead><tr><th>Rental</th><th>Customer</th><th>Car</th><th>Period</th><th>Rental Cost</th><th>Late Interest</th><th>Total Due</th><th>Total Paid</th><th>Remaining</th><th>Status</th><th>History</th><th>Action</th></tr></thead><tbody>
<?php if($result && $result->num_rows > 0): while($rental=$result->fetch_assoc()): ?>
<?php $carName=trim(($rental['make_name']??'').' '.($rental['model_name']??'').' '.($rental['car_year']??'')); if($carName==='') $carName=$rental['reg_no']??'N/A'; $bill=rental_bill_summary((float)$rental['total_cost'], (float)$rental['total_paid'], $rental['end_date'], (float)$rental['late_interest'], (int)$rental['late_days']); $lastPayment=$rental['last_payment_date']?cr_date($rental['last_payment_date']):'No payment yet'; ?>
<tr><td><strong>#<?= h($rental['rental_id']); ?></strong><span class="payment-mini"><?= h($rental['rental_status']); ?></span></td><td><strong><?= h($rental['customer_name']??'N/A'); ?></strong><span class="payment-mini"><?= h($rental['customer_phone']??''); ?></span><span class="payment-mini"><?= h($rental['customer_email']??''); ?></span></td><td><?= h($carName); ?><span class="payment-mini">Reg: <?= h($rental['reg_no']??'N/A'); ?></span></td><td><?= h(cr_date($rental['start_date'])) ?><span class="payment-mini">to <?= h(cr_date($rental['end_date'])) ?></span></td><td><?= money((float)$rental['total_cost']); ?></td><td><?= money((float)$bill['late_interest']); ?><span class="payment-mini"><?= h($bill['late_days']); ?> late day(s)</span></td><td><?= money((float)$bill['grand_total']); ?></td><td><?= money((float)$rental['total_paid']); ?></td><td><?= money((float)$bill['remaining']); ?></td><td><span class="<?= $bill['status']==='Paid'?'status-paid':'status-debt'; ?>"><?= h($bill['status']); ?></span></td><td><strong><?= (int)$rental['payment_count']; ?> payment(s)</strong><span class="payment-mini">Last: <?= h($lastPayment); ?></span><a href="history.php?rental_id=<?= (int)$rental['rental_id']; ?>" class="btn btn-primary" style="margin-top:6px;">View History</a></td><td><?php if((float)$bill['remaining']>0): ?><form action="manage.php" method="POST" class="inline-payment-form"><input type="hidden" name="rental_id" value="<?= (int)$rental['rental_id']; ?>"><input type="hidden" name="q" value="<?= h($q); ?>"><input type="hidden" name="page" value="<?= (int)$pg['page']; ?>"><?php if(isset($_GET['per_page'])): ?><input type="hidden" name="per_page" value="<?= (int)$pg['per_page']; ?>"><?php endif; ?><input type="text" inputmode="decimal" name="amount_paid" autocomplete="off" data-max="<?= h(number_format((float)$bill['remaining'], 2, '.', '')); ?>" placeholder="Max <?= h(cr_number($bill['remaining'])); ?>" title="Up to <?= money($bill['remaining']); ?>" required><select name="payment_method"><?php foreach ($methods as $m): ?><option value="<?= h($m); ?>"><?= h($m); ?></option><?php endforeach; ?></select><input type="text" name="payment_note" maxlength="255" placeholder="Receipt/no. note"><button type="submit" name="add_payment">Add Payment</button></form><?php else: ?><span class="status-paid">Fully paid</span><?php endif; ?></td></tr>
<?php endwhile; else: ?><tr><td colspan="12">No rentals found.</td></tr><?php endif; ?></tbody></table></div><?= cr_pagination($pg); ?></div></body>
