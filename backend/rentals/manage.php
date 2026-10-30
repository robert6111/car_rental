<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

/** Where to go back to after an action (only our own rental list pages). */
function rentals_back_url(): string {
    $back = (string)($_POST['back'] ?? $_GET['back'] ?? '');
    return in_array($back, ['active.php', 'not_returned.php'], true) ? $back : 'manage.php';
}

$msg = '';
$error = '';
if (isset($_GET['added'])) $msg = 'Rental added successfully.';
if (isset($_GET['updated'])) $msg = 'Rental updated successfully.';
if (isset($_GET['deleted'])) $msg = 'Rental marked as Deleted.';
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    if (($_SESSION['flash']['type'] ?? '') === 'error') $error = (string)($_SESSION['flash']['text'] ?? '');
    else $msg = (string)($_SESSION['flash']['text'] ?? '');
    unset($_SESSION['flash']);
}

// Confirm return (POST; the CSRF token is checked automatically for every POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_return'])) {
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    $mileageInput = trim((string)($_POST['return_mileage'] ?? ''));
    $notes = (string)($_POST['return_notes'] ?? '');
    $mileage = null;
    $err = null;
    if ($mileageInput !== '') {
        $digits = str_replace([',', '.', ' '], '', $mileageInput);
        if (!ctype_digit($digits) || strlen($digits) > 9) $err = 'Please enter the return mileage as a whole number (or leave it empty).';
        else $mileage = (int)$digits;
    }
    if ($err === null) $err = cr_confirm_return($conn, $rental_id, (int)($_SESSION['user_id'] ?? 0), $mileage, $notes);
    $_SESSION['flash'] = $err === null
        ? ['type' => 'success', 'text' => 'Return confirmed for rental #' . $rental_id . '. The car is available again.']
        : ['type' => 'error', 'text' => $err];
    header('Location: ' . rentals_back_url());
    exit;
}

if (isset($_GET['action'], $_GET['rental_id'])) {
    stock_validate_action_token();
    $rental_id = intval($_GET['rental_id']);
    if ($_GET['action'] === 'del') {
        $stmt = $conn->prepare("SELECT r.cars_id, r.status, (SELECT COUNT(*) FROM payment p WHERE p.rental_id = r.id) AS payment_count FROM rentals r WHERE r.id = ?");
        $stmt->bind_param("i", $rental_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'Rental not found.'];
        } elseif ((int)$row['payment_count'] > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'Rental #' . $rental_id . ' has payments recorded, so it cannot be deleted. Edit it and set the status to Canceled instead.'];
        } else {
            $stmt = $conn->prepare("UPDATE rentals SET status='Deleted', returned_at = COALESCE(returned_at, NOW()) WHERE id=?");
            $stmt->bind_param("i", $rental_id);
            if ($stmt->execute()) {
                $stmt->close();
                cr_sync_car_statuses($conn, (int)$row['cars_id']);
                header('Location: manage.php?deleted=1'); exit;
            }
            $_SESSION['flash'] = ['type' => 'error', 'text' => cr_db_error($stmt->error)];
            $stmt->close();
        }
        header('Location: manage.php'); exit;
    }
}

$q = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$all_statuses = ['Pending', 'Active', 'Completed', 'Canceled', 'Deleted'];
if (!in_array($status_filter, $all_statuses, true)) $status_filter = '';

$where = "WHERE 1";
$types = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (ca.reg_no LIKE ? OR c.full_name LIKE ? OR m.name LIKE ? OR mo.name LIKE ?)";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($status_filter !== '') {
    $where .= " AND r.status = ?";
    $types .= 's';
    $params[] = $status_filter;
}

$from = "FROM rentals r
        JOIN customers c ON r.customer_id = c.id
        JOIN cars ca ON r.cars_id = ca.id
        LEFT JOIN makes m ON ca.make_id = m.id
        LEFT JOIN models mo ON ca.model_id = mo.id
        LEFT JOIN car_years y ON ca.year_id = y.id
        $where";
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*) $from", $types, $params);
$sql = "SELECT r.id, r.start_date, r.end_date, r.total_cost, r.status AS rental_status, r.returned_at,
               c.full_name AS customer_name, ca.reg_no AS car_reg, m.name AS make_name, mo.name AS model_name, y.year AS car_year,
               " . cr_sql_total_paid('r') . " AS total_paid,
               " . cr_sql_late_interest('r') . " AS late_interest,
               " . cr_sql_late_days('r') . " AS late_days
        $from
        ORDER BY r.id DESC" . cr_page_sql($pg);
$result = false;
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) $result = $stmt->get_result();
    else $error = cr_db_error($stmt->error, 'The rentals could not be loaded. Please try again.');
    $stmt->close();
} else {
    $error = cr_db_error($conn->error, 'The rentals could not be loaded. Please try again.');
}
$today = cr_today();
?>
<head>
    <title>Manage Rentals</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <style>table{width:100%;border-collapse:collapse;margin-top:15px}th,td{padding:10px;border:1px solid #ddd;text-align:left}th{background-color:#333;color:#fff}tr:nth-child(even){background-color:#f2f2f2}.btn{padding:6px 10px;text-decoration:none;margin-right:5px;border-radius:3px;color:#fff;display:inline-block}.btn-warning{background-color:#f0ad4e}.btn-danger{background-color:#d9534f}.btn-success{background-color:#16a34a}.btn-primary{background-color:#0275d8;margin-bottom:10px}.success-msg{color:green;font-weight:700;margin:10px 0}.error-msg{color:#b91c1c;font-weight:700;margin:10px 0}.badge-action{background:#fef3c7;color:#92400e;padding:4px 8px;border-radius:999px;font-weight:bold;display:inline-block;margin-top:4px}.badge-danger{background:#fee2e2;color:#991b1b}.mini{display:block;color:#64748b;font-size:12px;margin-top:3px}.filter-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:end}.return-form{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}.return-form input{width:90px;padding:4px}.return-form button{padding:5px 8px;border:0;border-radius:3px;background:#16a34a;color:#fff;cursor:pointer}</style>
</head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Manage Rentals</h2><?php if ($msg): ?><p class="success-msg"><?= h($msg); ?></p><?php endif; ?><?php if ($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?><a href="add.php" class="btn btn-primary">Add New Rental</a>
<form method="GET" class="filter-box"><div><label>Search by reg no / customer:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Example: 34 ABC 123"></div><div><label>Status:</label><br><select name="status"><option value="">All</option><?php foreach($all_statuses as $s): ?><option value="<?= h($s) ?>" <?= $status_filter===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div><div><button type="submit" class="btn btn-primary">Search</button><a href="manage.php" class="btn btn-danger">Clear</a></div></form>
<div class="table-responsive"><table><thead><tr><th>#</th><th>Customer</th><th>Car</th><th>Registration No</th><th>Start</th><th>End</th><th>Cost</th><th>Paid</th><th>Balance</th><th>Status / Action Needed</th><th>Actions</th></tr></thead><tbody>
<?php if($result && $result->num_rows > 0): $count=$pg['offset']+1; ?><?php while($row = $result->fetch_assoc()): ?>
<?php
    $status = $row['rental_status'];
    $statusColor = match($status) {'Active'=>'green','Completed'=>'blue','Deleted'=>'darkred','Canceled'=>'red','Pending'=>'orange',default=>'black'};
    $carName = trim(($row['make_name']??'').' '.($row['model_name']??'').' '.($row['car_year']??'')); if($carName==='') $carName=$row['car_reg'];
    $isVoid = in_array($status, ['Canceled','Deleted'], true);
    $bill = $isVoid ? null : rental_bill_summary((float)$row['total_cost'], (float)$row['total_paid'], $row['end_date'], (float)$row['late_interest'], (int)$row['late_days']);
    $isOut = $row['returned_at'] === null && ($status === 'Active' || $status === 'Completed');
    $isOverdueOut = $isOut && $row['end_date'] < $today;
?>
<tr><td><?= $count++ ?></td><td><?= h($row['customer_name']) ?></td><td><?= h($carName) ?></td><td><?= h($row['car_reg']) ?></td><td><?= h(cr_date($row['start_date'])) ?></td><td><?= h(cr_date($row['end_date'])) ?></td><td><?= money((float)$row['total_cost']) ?></td><td><?= money((float)$row['total_paid']) ?></td>
<td><?php if($isVoid): ?>—<?php else: ?><?= money((float)$bill['remaining']) ?><?php if($bill['late_interest']>0): ?><span class="mini">Late interest: <?= money((float)$bill['late_interest']); ?></span><?php endif; ?><?php endif; ?></td>
<td><span style="color:<?= h($statusColor) ?>;font-weight:bold;"><?= h($status) ?></span>
<?php if($status === 'Pending'): ?><span class="badge-action">Pending – starts <?= h(cr_date($row['start_date'])) ?></span><?php endif; ?>
<?php if($status === 'Completed' && $isOverdueOut): ?><span class="badge-action badge-danger">Not returned</span><?php elseif($status === 'Active' && $isOverdueOut): ?><span class="badge-action badge-danger">Overdue</span><?php endif; ?>
<?php if($status === 'Completed' && $row['returned_at'] !== null): ?><span class="mini">Returned <?= h(cr_date($row['returned_at'], true)) ?></span><?php endif; ?>
</td>
<td><a href="edit.php?id=<?= h($row['id']) ?>" class="btn btn-warning btn-sm">Edit</a><a href="contract.php?id=<?= h($row['id']) ?>" class="btn btn-primary btn-sm" target="_blank">Contract</a>
<?php if($isOverdueOut): ?><a href="<?= h(stock_action_url('not_returned.php?create=' . (int)$row['id'])); ?>" class="btn btn-danger btn-sm">Not Returned</a><?php endif; ?>
<?php if($status !== 'Deleted'): ?><a href="<?= h(stock_action_url('manage.php?action=del&rental_id=' . (int)$row['id'])); ?>" onclick="return confirm('Mark this rental as deleted?')" class="btn btn-danger btn-sm">Delete</a><?php endif; ?>
<?php if($isOut): ?><form method="POST" action="manage.php" class="return-form" onsubmit="return confirm('Confirm that the car of rental #<?= (int)$row['id'] ?> has been returned?')"><input type="hidden" name="rental_id" value="<?= (int)$row['id'] ?>"><input type="text" inputmode="numeric" name="return_mileage" placeholder="Mileage"><input type="text" name="return_notes" placeholder="Notes" maxlength="1000"><button type="submit" name="confirm_return" value="1">Confirm return</button></form><?php endif; ?>
</td></tr>
<?php endwhile; ?><?php else: ?><tr><td colspan="11" class="text-center">No rentals found.</td></tr><?php endif; ?></tbody></table></div><?= cr_pagination($pg); ?></div>
<?php if($result) $result->free(); $conn->close(); ?></body>
