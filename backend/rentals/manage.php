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
<?php
// Small page styles for the collapsible "Confirm return" form in table rows.
$returnFormStyle = '<style>
.return-box { display: inline-block; vertical-align: top; }
.return-box > summary { list-style: none; }
.return-box > summary::-webkit-details-marker { display: none; }
.return-box[open] > summary { background: var(--surface-3); color: var(--text); border-color: var(--border-strong); }
.return-form { display: grid; gap: 4px; width: 230px; margin-top: 8px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); white-space: normal; text-align: left; }
.return-form label { margin: 4px 0 0; font-size: 12.5px; }
.return-form input { min-height: 32px !important; padding: 4px 8px !important; font-size: 13.5px !important; }
.return-form button { margin-top: 6px; }
.card-head > .filters { flex: 1; }
.return-actions { display: flex; flex-wrap: wrap; gap: 4px; align-items: flex-start; margin-top: 6px; white-space: normal; }
.return-actions .btn { margin: 0 !important; }
</style>';
?>
<?php cr_layout_start('All rentals', ['head' => $returnFormStyle]); ?>
<?= cr_page_header('All rentals', 'Every rental with its payments and return status.', '<a class="btn" href="add.php">' . cr_icon('plus') . ' New rental</a>'); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card card-flush">
    <div class="card-head">
        <form method="GET" class="filters mb-0">
            <div class="field grow">
                <label for="q">Search</label>
                <input type="text" id="q" name="q" value="<?= h($q); ?>" placeholder="Reg no, customer, make or model">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <?php foreach($all_statuses as $s): ?><option value="<?= h($s) ?>" <?= $status_filter===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit"><?= cr_icon('search'); ?> Filter</button>
            <a class="btn btn-secondary" href="manage.php">Clear</a>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Period</th>
                    <th class="num">Cost / paid</th>
                    <th class="num">Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if($result && $result->num_rows > 0): $count=$pg['offset']+1; ?>
                <?php while($row = $result->fetch_assoc()): ?>
                <?php
                    $status = $row['rental_status'];
                    $carName = trim(($row['make_name']??'').' '.($row['model_name']??'').' '.($row['car_year']??'')); if($carName==='') $carName=$row['car_reg'];
                    $isVoid = in_array($status, ['Canceled','Deleted'], true);
                    $bill = $isVoid ? null : rental_bill_summary((float)$row['total_cost'], (float)$row['total_paid'], $row['end_date'], (float)$row['late_interest'], (int)$row['late_days']);
                    $isOut = $row['returned_at'] === null && ($status === 'Active' || $status === 'Completed');
                    $isOverdueOut = $isOut && $row['end_date'] < $today;
                ?>
                <tr>
                    <td><span class="cell-title"><?= h($row['customer_name']) ?></span><span class="cell-sub">Rental #<?= (int)$row['id'] ?></span></td>
                    <td><?= h($carName) ?><span class="cell-sub"><span class="plate"><?= h($row['car_reg']) ?></span></span></td>
                    <td class="nowrap"><?= h(cr_date($row['start_date'])) ?><span class="cell-sub">to <?= h(cr_date($row['end_date'])) ?></span></td>
                    <td class="num"><?= money((float)$row['total_cost']) ?><span class="cell-sub">Paid <?= money((float)$row['total_paid']) ?></span></td>
                    <td class="num">
                        <?php if($isVoid): ?><span class="muted">—</span>
                        <?php elseif((float)$bill['remaining'] > 0): ?><strong class="text-danger"><?= money((float)$bill['remaining']) ?></strong>
                        <?php else: ?><span class="muted"><?= money((float)$bill['remaining']) ?></span><?php endif; ?>
                        <?php if(!$isVoid && $bill['late_interest']>0): ?><span class="cell-sub text-danger">Late interest: <?= money((float)$bill['late_interest']); ?></span><?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if($status === 'Completed' && $isOverdueOut): ?>
                            <?= cr_status_badge('Not returned') ?><span class="cell-sub">Was due <?= h(cr_date($row['end_date'])) ?></span>
                        <?php elseif($status === 'Active' && $isOverdueOut): ?>
                            <?= cr_status_badge('Overdue') ?><span class="cell-sub">Was due <?= h(cr_date($row['end_date'])) ?></span>
                        <?php else: ?>
                            <?= cr_status_badge($status) ?>
                            <?php if($status === 'Pending'): ?><span class="cell-sub">Starts <?= h(cr_date($row['start_date'])) ?></span><?php endif; ?>
                            <?php if($status === 'Completed' && $row['returned_at'] !== null): ?><span class="cell-sub">Returned <?= h(cr_date($row['returned_at'], true)) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="actions-cell">
                        <a href="edit.php?id=<?= h($row['id']) ?>" class="btn btn-sm btn-secondary"><?= cr_icon('edit', 'icon-sm') ?> Edit</a>
                        <a href="contract.php?id=<?= h($row['id']) ?>" class="btn btn-sm btn-secondary" target="_blank" title="Print contract" aria-label="Print contract for rental #<?= (int)$row['id'] ?>"><?= cr_icon('printer', 'icon-sm') ?></a>
                        <?php if($status !== 'Deleted'): ?><a href="<?= h(stock_action_url('manage.php?action=del&rental_id=' . (int)$row['id'])); ?>" data-confirm="Mark this rental as deleted?" class="btn btn-sm btn-danger" title="Delete rental" aria-label="Delete rental #<?= (int)$row['id'] ?>"><?= cr_icon('trash', 'icon-sm') ?></a><?php endif; ?>
                        <?php if($isOut): ?>
                        <div class="return-actions">
                            <?php if($isOverdueOut): ?><a href="<?= h(stock_action_url('not_returned.php?create=' . (int)$row['id'])); ?>" class="btn btn-sm btn-danger" title="Open a not-returned case"><?= cr_icon('alert', 'icon-sm') ?> Open case</a><?php endif; ?>
                        <details class="return-box">
                            <summary class="btn btn-sm btn-success"><?= cr_icon('check', 'icon-sm') ?> Confirm return</summary>
                            <form method="POST" action="manage.php" class="return-form" onsubmit="return confirm('Confirm that the car of rental #<?= (int)$row['id'] ?> has been returned?')">
                                <input type="hidden" name="rental_id" value="<?= (int)$row['id'] ?>">
                                <label for="return_mileage_<?= (int)$row['id'] ?>">Return mileage <span class="opt">(optional)</span></label>
                                <input type="text" inputmode="numeric" id="return_mileage_<?= (int)$row['id'] ?>" name="return_mileage" placeholder="Mileage">
                                <label for="return_notes_<?= (int)$row['id'] ?>">Notes <span class="opt">(optional)</span></label>
                                <input type="text" id="return_notes_<?= (int)$row['id'] ?>" name="return_notes" placeholder="Notes" maxlength="1000">
                                <button type="submit" name="confirm_return" value="1" class="btn btn-sm btn-success">Confirm return</button>
                            </form>
                        </details>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No rentals found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
<?php if($result) $result->free(); $conn->close(); ?>
