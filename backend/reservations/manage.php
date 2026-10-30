<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

function reservation_flash(string $type, string $text, string $location = 'manage.php'): void {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    header('Location: ' . $location);
    exit;
}

function reservation_load(mysqli $conn, int $id, bool $forUpdate = false): ?array {
    $stmt = $conn->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

$msg = '';
$error = '';
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    if (($_SESSION['flash']['type'] ?? '') === 'error') $error = (string)($_SESSION['flash']['text'] ?? '');
    else $msg = (string)($_SESSION['flash']['text'] ?? '');
    unset($_SESSION['flash']);
}

$form = ['customer_id' => 0, 'car_id' => 0, 'pickup_date' => '', 'dropoff_date' => '', 'pickup_location' => '', 'dropoff_location' => '', 'notes' => '', 'status' => 'Confirmed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reservation'])) {
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['car_id'] = (int)($_POST['car_id'] ?? 0);
    $form['pickup_date'] = trim((string)($_POST['pickup_date'] ?? ''));
    $form['dropoff_date'] = trim((string)($_POST['dropoff_date'] ?? ''));
    $form['pickup_location'] = mb_substr(trim((string)($_POST['pickup_location'] ?? '')), 0, 255);
    $form['dropoff_location'] = mb_substr(trim((string)($_POST['dropoff_location'] ?? '')), 0, 255);
    $form['notes'] = trim((string)($_POST['notes'] ?? ''));
    $form['status'] = in_array($_POST['status'] ?? '', ['Confirmed', 'Pending'], true) ? $_POST['status'] : 'Confirmed';

    $customer_id = $form['customer_id'];
    $car_id = $form['car_id'];
    $pickup_date = $form['pickup_date'];
    $dropoff_date = $form['dropoff_date'];

    $customerExists = false;
    if ($customer_id > 0) {
        $chk = $conn->prepare('SELECT id FROM customers WHERE id = ?');
        $chk->bind_param('i', $customer_id);
        $chk->execute();
        $customerExists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
    }
    $car = null;
    if ($car_id > 0) {
        $chk = $conn->prepare('SELECT id, availability_status FROM cars WHERE id = ?');
        $chk->bind_param('i', $car_id);
        $chk->execute();
        $car = $chk->get_result()->fetch_assoc() ?: null;
        $chk->close();
    }

    if (!$customerExists) {
        $error = 'Please select a valid customer.';
    } elseif (!$car) {
        $error = 'Please select a valid car.';
    } elseif ($car['availability_status'] === 'Maintenance') {
        $error = 'This car is in maintenance and cannot be reserved.';
    } elseif (!cr_valid_date($pickup_date) || !cr_valid_date($dropoff_date)) {
        $error = 'Please enter valid pickup and dropoff dates.';
    } elseif ($dateError = cr_rental_dates_error($pickup_date, $dropoff_date)) {
        $error = str_replace(['start date', 'end date'], ['pickup date', 'dropoff date'], $dateError);
    } else {
        $conn->begin_transaction();
        try {
            if (!cr_lock_car($conn, $car_id)) throw new RuntimeException('Car not found while locking.');
            $reason = cr_car_unavailable_reason($conn, $car_id, $pickup_date, $dropoff_date);
            $status = $reason !== null ? 'Waitlisted' : $form['status'];
            $source = 'Office';
            $stmt = $conn->prepare("INSERT INTO reservations (customer_id, car_id, pickup_date, dropoff_date, pickup_location, dropoff_location, status, source, notes) VALUES (?,?,?,?,?,?,?,?,?)");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('iisssssss', $customer_id, $car_id, $pickup_date, $dropoff_date, $form['pickup_location'], $form['dropoff_location'], $status, $source, $form['notes']);
            if (!$stmt->execute()) {
                $dbErr = $stmt->error;
                $stmt->close();
                $conn->rollback();
                $error = cr_db_error($dbErr);
            } else {
                $stmt->close();
                $conn->commit();
                cr_sync_car_statuses($conn, $car_id);
                if ($status === 'Waitlisted') {
                    reservation_flash('error', 'Reservation saved in the waitlist: ' . $reason);
                }
                reservation_flash('success', $status === 'Confirmed' ? 'Reservation confirmed successfully.' : 'Reservation saved as Pending.');
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $error = cr_db_error($e->getMessage());
        }
    }
}

if (isset($_GET['action'], $_GET['id'])) {
    stock_validate_action_token();
    $id = (int)$_GET['id'];
    $action = (string)$_GET['action'];

    if ($action === 'cancel') {
        $res = reservation_load($conn, $id);
        if (!$res) reservation_flash('error', 'Reservation not found.');
        if (in_array($res['status'], ['Cancelled', 'Converted'], true)) reservation_flash('error', 'This reservation is already ' . strtolower($res['status']) . '.');
        $stmt = $conn->prepare("UPDATE reservations SET status='Cancelled' WHERE id=? AND status NOT IN ('Cancelled','Converted')");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $dbErr = $stmt->error;
        $stmt->close();
        if (!$ok) reservation_flash('error', cr_db_error($dbErr));
        if ($res['car_id']) cr_sync_car_statuses($conn, (int)$res['car_id']);
        reservation_flash('success', 'Reservation cancelled.');
    }

    if ($action === 'confirm') {
        $res = reservation_load($conn, $id);
        if (!$res) reservation_flash('error', 'Reservation not found.');
        if (!in_array($res['status'], ['Waitlisted', 'Pending'], true)) reservation_flash('error', 'Only pending or waitlisted reservations can be confirmed.');
        $carId = (int)$res['car_id'];
        if ($carId <= 0) reservation_flash('error', 'This reservation has no car selected.');
        if ($res['dropoff_date'] < cr_today()) reservation_flash('error', 'The dates of this reservation have already passed.');

        $conn->begin_transaction();
        try {
            if (!cr_lock_car($conn, $carId)) throw new RuntimeException('Car not found while locking.');
            $reason = cr_car_unavailable_reason($conn, $carId, $res['pickup_date'], $res['dropoff_date'], 0, $id);
            if ($reason !== null) {
                $conn->rollback();
                reservation_flash('error', 'Cannot confirm yet: ' . $reason);
            }
            $stmt = $conn->prepare("UPDATE reservations SET status='Confirmed' WHERE id=? AND status IN ('Waitlisted','Pending')");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            reservation_flash('error', cr_db_error($e->getMessage()));
        }
        cr_sync_car_statuses($conn, $carId);
        reservation_flash('success', 'Reservation #' . $id . ' confirmed.');
    }

    if ($action === 'convert') {
        $res = reservation_load($conn, $id);
        if (!$res) reservation_flash('error', 'Reservation not found.');
        if (!in_array($res['status'], ['Confirmed', 'Pending'], true)) reservation_flash('error', 'Only pending or confirmed reservations can be converted to a rental.');
        $customerId = (int)($res['customer_id'] ?? 0);
        $carId = (int)($res['car_id'] ?? 0);
        $pickup = (string)$res['pickup_date'];
        $dropoff = (string)$res['dropoff_date'];

        if ($customerId <= 0) reservation_flash('error', 'This booking request is not linked to a customer yet. Use "Link customer" first, then convert it.');
        $chk = $conn->prepare('SELECT id FROM customers WHERE id = ?');
        $chk->bind_param('i', $customerId);
        $chk->execute();
        $customerExists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$customerExists) reservation_flash('error', 'The customer linked to this reservation no longer exists. Link or create the customer first.');
        if ($carId <= 0) reservation_flash('error', 'This reservation has no car selected.');
        if ($dateError = cr_rental_dates_error($pickup, $dropoff, true)) reservation_flash('error', $dateError);
        if ($dropoff < cr_today()) reservation_flash('error', 'The dropoff date of this reservation has already passed, so it cannot be converted. Update or cancel it.');

        $conn->begin_transaction();
        try {
            if (!cr_lock_car($conn, $carId)) throw new RuntimeException('Car not found while locking.');
            $locked = reservation_load($conn, $id, true);
            if (!$locked || !in_array($locked['status'], ['Confirmed', 'Pending'], true)) {
                $conn->rollback();
                reservation_flash('error', 'This reservation was changed by someone else. Please check it again.');
            }
            $reason = cr_car_unavailable_reason($conn, $carId, $pickup, $dropoff, 0, $id);
            if ($reason !== null) {
                $conn->rollback();
                reservation_flash('error', 'Cannot convert to a rental: ' . $reason);
            }

            $pricing = calculate_rental_total($conn, $customerId, $carId, $pickup, $dropoff);
            $total = (float)$pricing['total'];
            $discount = (float)$pricing['discount_amount'];
            $deposit = 0.0;
            $tax = 0.0;
            $status = cr_rental_initial_status($pickup);
            $notes = 'Converted from reservation #' . $id;
            $pickupLocation = (string)($res['pickup_location'] ?? '');
            $dropoffLocation = (string)($res['dropoff_location'] ?? '');
            $stmt = $conn->prepare("INSERT INTO rentals (customer_id, cars_id, start_date, end_date, pickup_location, dropoff_location, total_cost, deposit_amount, discount_amount, tax_amount, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('iissssddddss', $customerId, $carId, $pickup, $dropoff, $pickupLocation, $dropoffLocation, $total, $deposit, $discount, $tax, $notes, $status);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("UPDATE reservations SET status='Converted' WHERE id=?");
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            reservation_flash('error', cr_db_error($e->getMessage(), 'The reservation could not be converted. Please try again.'));
        }
        cr_sync_car_statuses($conn, $carId);
        header('Location: ../rentals/manage.php?added=1');
        exit;
    }

    reservation_flash('error', 'Unknown action.');
}

$customers = $conn->query("SELECT id, full_name, phone_number FROM customers ORDER BY full_name");
$cars = $conn->query("SELECT cars.id, cars.reg_no, cars.availability_status, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id WHERE cars.availability_status <> 'Maintenance' ORDER BY makes.name, models.name, cars.reg_no");
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$allStatuses = ['Pending', 'Confirmed', 'Waitlisted', 'Converted', 'Cancelled'];
if (!in_array($status, $allStatuses, true)) $status = '';
$where = 'WHERE 1';
$types = '';
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (COALESCE(c.full_name,r.customer_name) LIKE ? OR ca.reg_no LIKE ? OR r.phone_number LIKE ?)";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where .= ' AND r.status = ?'; $types .= 's'; $params[] = $status; }
$list = false;
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*) FROM reservations r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.car_id=ca.id $where", $types, $params);
$stmt = $conn->prepare("SELECT r.*, COALESCE(c.full_name, r.customer_name) AS display_customer, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM reservations r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.car_id=ca.id LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id $where ORDER BY r.id DESC" . cr_page_sql($pg));
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) $list = $stmt->get_result();
    else $error = cr_db_error($stmt->error, 'The reservations could not be loaded. Please try again.');
    $stmt->close();
}
?>
<?php cr_layout_start('Reservations and waitlist'); ?>
<?= cr_page_header('Reservations and waitlist', 'Book cars ahead of time, handle online requests and turn reservations into rentals.',
    '<a href="booking_link.php" class="btn btn-success">' . cr_icon('whatsapp') . ' Send booking link</a>'
    . '<a href="online_booking.php" class="btn btn-secondary" target="_blank" rel="noopener">' . cr_icon('external') . ' Public booking form</a>'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <h2 class="section-title">New office reservation</h2>
    <div class="form-grid">
        <div class="field">
            <label for="customer_id">Customer <span class="req">*</span></label>
            <select id="customer_id" name="customer_id" required>
                <option value="">Select a customer</option>
                <?php while ($c = $customers->fetch_assoc()): ?>
                    <option value="<?= h($c['id']); ?>" <?= (int)$c['id'] === $form['customer_id'] ? 'selected' : ''; ?>><?= h($c['full_name'] . ' - ' . $c['phone_number']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="car_id">Car <span class="req">*</span></label>
            <select id="car_id" name="car_id" required>
                <option value="">Select a car</option>
                <?php while ($car = $cars->fetch_assoc()): ?>
                    <option value="<?= h($car['id']); ?>" <?= (int)$car['id'] === $form['car_id'] ? 'selected' : ''; ?>><?= h(car_display_name($car) . ' [' . $car['availability_status'] . ']'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="pickup_date">Pickup date <span class="req">*</span></label>
            <input type="date" id="pickup_date" name="pickup_date" min="<?= h(cr_today()); ?>" value="<?= h($form['pickup_date']); ?>" required>
        </div>
        <div class="field">
            <label for="dropoff_date">Dropoff date <span class="req">*</span></label>
            <input type="date" id="dropoff_date" name="dropoff_date" min="<?= h(cr_today()); ?>" value="<?= h($form['dropoff_date']); ?>" required>
        </div>
        <div class="field">
            <label for="pickup_location">Pickup location <span class="opt">(optional)</span></label>
            <input type="text" id="pickup_location" name="pickup_location" value="<?= h($form['pickup_location']); ?>">
        </div>
        <div class="field">
            <label for="dropoff_location">Dropoff location <span class="opt">(optional)</span></label>
            <input type="text" id="dropoff_location" name="dropoff_location" value="<?= h($form['dropoff_location']); ?>">
        </div>
        <div class="field">
            <label for="res_status">Status</label>
            <select id="res_status" name="status">
                <?php foreach (['Confirmed', 'Pending'] as $s): ?>
                    <option value="<?= h($s); ?>" <?= $form['status'] === $s ? 'selected' : ''; ?>><?= h($s); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">If the car is not free on these dates, the reservation goes to the waitlist.</span>
        </div>
        <div class="field span-all">
            <label for="notes">Notes <span class="opt">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"><?= h($form['notes']); ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" name="save_reservation"><?= cr_icon('check'); ?> Save reservation</button>
    </div>
</form>

<div class="card">
    <div class="card-head"><h2>All reservations</h2></div>
    <form method="GET" class="filters">
        <div class="field grow">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= h($q); ?>" placeholder="Customer, reg no or phone">
        </div>
        <div class="field">
            <label for="status_filter">Status</label>
            <select id="status_filter" name="status">
                <option value="">All</option>
                <?php foreach ($allStatuses as $s): ?><option <?= $status === $s ? 'selected' : ''; ?>><?= h($s); ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit"><?= cr_icon('search'); ?> Filter</button>
        <a href="manage.php" class="btn btn-secondary">Clear</a>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Customer</th><th>Car</th><th>Dates</th><th>Locations</th><th>Source</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($r = $list->fetch_assoc()): ?>
                <tr>
                    <td class="muted"><?= h($r['id']); ?></td>
                    <td>
                        <span class="cell-title"><?= h($r['display_customer'] ?: 'Public request'); ?></span>
                        <?php if (!$r['customer_id']): ?><span class="cell-sub text-warning">Not linked to a customer</span><?php endif; ?>
                        <?php if (!empty($r['phone_number'])): ?><span class="cell-sub"><?= h($r['phone_number']); ?></span><?php endif; ?>
                        <?php if (!$r['customer_id'] && !empty($r['email'])): ?><span class="cell-sub"><?= h($r['email']); ?></span><?php endif; ?>
                    </td>
                    <td><?= h(car_display_name(array_merge($r, ['reg_no' => '']))); ?><?php if (!empty($r['reg_no'])): ?><span class="cell-sub"><span class="plate"><?= h($r['reg_no']); ?></span></span><?php endif; ?></td>
                    <td class="nowrap"><?= h(cr_date($r['pickup_date'])) ?><span class="cell-sub">to <?= h(cr_date($r['dropoff_date'])) ?></span></td>
                    <td><?= h($r['pickup_location']); ?><?php if ((string)$r['dropoff_location'] !== ''): ?><span class="cell-sub"><?= h($r['dropoff_location']); ?></span><?php endif; ?></td>
                    <td><?= cr_status_badge((string)$r['source']); ?></td>
                    <td><?= cr_status_badge((string)$r['status']); ?></td>
                    <td><div class="actions">
                        <?php if (!in_array($r['status'], ['Cancelled', 'Converted'], true)): ?>
                            <a class="btn btn-sm <?= $r['customer_id'] ? 'btn-secondary' : 'btn-warning'; ?>" href="link.php?id=<?= (int)$r['id']; ?>"><?= cr_icon('link', 'icon-sm'); ?> <?= $r['customer_id'] ? 'Change customer' : 'Link customer'; ?></a>
                        <?php endif; ?>
                        <?php if (in_array($r['status'], ['Waitlisted', 'Pending'], true)): ?>
                            <a class="btn btn-sm btn-success" href="<?= h(stock_action_url('manage.php?action=confirm&id=' . (int)$r['id'])); ?>"><?= cr_icon('check', 'icon-sm'); ?> Confirm</a>
                        <?php endif; ?>
                        <?php if (in_array($r['status'], ['Confirmed', 'Pending'], true)): ?>
                            <a class="btn btn-sm" href="<?= h(stock_action_url('manage.php?action=convert&id=' . (int)$r['id'])); ?>">Convert to rental</a>
                        <?php endif; ?>
                        <?php if (!in_array($r['status'], ['Cancelled', 'Converted'], true)): ?>
                            <a class="btn btn-sm btn-danger" data-confirm="Cancel reservation?" href="<?= h(stock_action_url('manage.php?action=cancel&id=' . (int)$r['id'])); ?>">Cancel</a>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="table-empty">No reservations found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
