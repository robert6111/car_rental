<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = ''; $error = '';
if (isset($_GET['payment_added'])) $msg = 'Payment added successfully.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    $amount_paid = (float)($_POST['amount_paid'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_note = trim($_POST['payment_note'] ?? '');
    $created_by = $_SESSION['user_id'] ?? null;
    $payment_date = date('Y-m-d');
    $balanceStmt = $conn->prepare("SELECT r.total_cost, r.end_date, IFNULL(SUM(p.amount),0) AS total_paid FROM rentals r LEFT JOIN payment p ON r.id=p.rental_id WHERE r.id=? GROUP BY r.id,r.total_cost,r.end_date");
    $balanceStmt->bind_param("i", $rental_id); $balanceStmt->execute(); $balance = $balanceStmt->get_result()->fetch_assoc(); $balanceStmt->close();
    $bill = $balance ? rental_bill_summary((float)$balance['total_cost'], (float)$balance['total_paid'], $balance['end_date']) : ['remaining'=>0];
    $remainingBalance = (float)$bill['remaining'];
    if ($rental_id <= 0) $error = 'Invalid rental selected.';
    elseif ($amount_paid <= 0) $error = 'Payment amount must be greater than zero.';
    elseif ($amount_paid > $remainingBalance) $error = 'Payment cannot be more than the remaining balance including late interest.';
    else {
        $stmt = $conn->prepare("INSERT INTO payment (rental_id, amount, payment_date, payment_method, payment_note, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("idsssi", $rental_id, $amount_paid, $payment_date, $payment_method, $payment_note, $created_by);
        if ($stmt->execute()) { header('Location: manage.php?payment_added=1'); exit; }
        $error = 'Payment could not be added.'; $stmt->close();
    }
}

$q = trim($_GET['q'] ?? '');
$where = "WHERE r.status <> 'Deleted'";
if ($q !== '') { $safe = '%' . $conn->real_escape_string($q) . '%'; $where .= " AND (ca.reg_no LIKE '$safe' OR c.full_name LIKE '$safe')"; }
$result = $conn->query("SELECT r.id AS rental_id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status,
        IFNULL(SUM(p.amount), 0) AS total_paid, COUNT(p.id) AS payment_count, MAX(p.payment_date) AS last_payment_date,
        c.full_name AS customer_name, c.phone_number AS customer_phone, c.email AS customer_email,
        ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM rentals r
    LEFT JOIN payment p ON r.id=p.rental_id
    LEFT JOIN customers c ON r.customer_id=c.id
    LEFT JOIN cars ca ON r.cars_id=ca.id
    LEFT JOIN makes m ON ca.make_id=m.id
    LEFT JOIN models mo ON ca.model_id=mo.id
    LEFT JOIN car_years y ON ca.year_id=y.id
    $where
    GROUP BY r.id,r.start_date,r.end_date,r.total_cost,r.status,c.full_name,c.phone_number,c.email,ca.reg_no,m.name,mo.name,y.year
    ORDER BY r.id DESC");
?>
<head><title>Manage Payments - Car Rental</title><link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css"><style>.status-paid{background:#dcfce7;color:#166534;font-weight:bold;text-align:center;border-radius:999px;padding:6px 10px;display:inline-block}.status-debt{background:#fee2e2;color:#991b1b;font-weight:bold;text-align:center;border-radius:999px;padding:6px 10px;display:inline-block}.inline-payment-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:transparent!important;border:0!important;padding:0!important;box-shadow:none!important}.inline-payment-form input{max-width:130px}.success-msg{color:green;font-weight:700}.error-msg{color:red;font-weight:700}.payment-mini{color:#64748b;font-size:12px;display:block;margin-top:3px}.customer-payment-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 4px 14px rgba(15,23,42,.06);margin-bottom:16px}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap}</style></head>
<body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Manage Payments</h2><div class="customer-payment-card"><h3>Customer Payment Overview</h3><p>Track rental payment, balance, late-payment interest, and full payment history.</p><p><strong>Late interest:</strong> <?= late_interest_daily_fraction() > 0 ? h(late_interest_daily_fraction() * 100) . '% per day after rental end date while balance remains unpaid.' : 'switched off.' ?></p></div>
<?php if($msg): ?><p class="success-msg"><?= h($msg); ?></p><?php endif; ?><?php if($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?>
<form method="GET" class="filter-box"><div><label>Search by reg no / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><button type="submit" class="btn btn-primary">Search</button> <a href="manage.php" class="btn btn-danger">Clear</a></div></form>
<div class="table-responsive"><table border="1" cellpadding="10" cellspacing="0" class="table"><thead><tr><th>Rental</th><th>Customer</th><th>Car</th><th>Period</th><th>Rental Cost</th><th>Late Interest</th><th>Total Due</th><th>Total Paid</th><th>Remaining</th><th>Status</th><th>History</th><th>Action</th></tr></thead><tbody>
<?php if($result && $result->num_rows > 0): while($rental=$result->fetch_assoc()): ?>
<?php $carName=trim(($rental['make_name']??'').' '.($rental['model_name']??'').' '.($rental['car_year']??'')); if($carName==='') $carName=$rental['reg_no']??'N/A'; $bill=rental_bill_summary((float)$rental['total_cost'], (float)$rental['total_paid'], $rental['end_date']); $lastPayment=$rental['last_payment_date']?date('d M Y',strtotime($rental['last_payment_date'])):'No payment yet'; ?>
<tr><td><strong>#<?= h($rental['rental_id']); ?></strong></td><td><strong><?= h($rental['customer_name']??'N/A'); ?></strong><span class="payment-mini"><?= h($rental['customer_phone']??''); ?></span><span class="payment-mini"><?= h($rental['customer_email']??''); ?></span></td><td><?= h($carName); ?><span class="payment-mini">Reg: <?= h($rental['reg_no']??'N/A'); ?></span></td><td><?= h(cr_date($rental['start_date'])) ?><span class="payment-mini">to <?= h(cr_date($rental['end_date'])) ?></span></td><td><?= money((float)$rental['total_cost']); ?></td><td><?= money((float)$bill['late_interest']); ?><span class="payment-mini"><?= h($bill['late_days']); ?> late day(s)</span></td><td><?= money((float)$bill['grand_total']); ?></td><td><?= money((float)$rental['total_paid']); ?></td><td><?= money((float)$bill['remaining']); ?></td><td><span class="<?= $bill['status']==='Paid'?'status-paid':'status-debt'; ?>"><?= h($bill['status']); ?></span></td><td><strong><?= (int)$rental['payment_count']; ?> payment(s)</strong><span class="payment-mini">Last: <?= h($lastPayment); ?></span><a href="history.php?rental_id=<?= h($rental['rental_id']); ?>" class="btn btn-primary" style="margin-top:6px;">View History</a></td><td><?php if((float)$bill['remaining']>0): ?><form action="manage.php" method="POST" class="inline-payment-form"><input type="hidden" name="rental_id" value="<?= h($rental['rental_id']); ?>"><input type="number" name="amount_paid" min="0.01" max="<?= h($bill['remaining']); ?>" step="0.01" placeholder="Amount" required><select name="payment_method"><option>Cash</option><option>Card</option><option>Bank Transfer</option><option>Online</option></select><input type="text" name="payment_note" placeholder="Receipt/no. note"><button type="submit" name="add_payment">Add Payment</button></form><?php else: ?><span class="status-paid">Fully paid</span><?php endif; ?></td></tr>
<?php endwhile; else: ?><tr><td colspan="12">No rentals found.</td></tr><?php endif; ?></tbody></table></div></div></body>
