<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);
$msg = $_GET['msg'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reservation'])) {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $car_id = (int)($_POST['car_id'] ?? 0);
    $pickup_date = $_POST['pickup_date'] ?? '';
    $dropoff_date = $_POST['dropoff_date'] ?? '';
    $pickup_location = trim($_POST['pickup_location'] ?? '');
    $dropoff_location = trim($_POST['dropoff_location'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = 'Confirmed';
    if (!$customer_id || !$car_id || !$pickup_date || !$dropoff_date || $dropoff_date < $pickup_date) {
        $error = 'Please select customer, car, and valid dates.';
    } else {
        $conflict = rental_period_conflicts($conn, $car_id, $pickup_date, $dropoff_date) || reservation_period_conflicts($conn, $car_id, $pickup_date, $dropoff_date);
        if ($conflict) $status = 'Waitlisted';
        $stmt = $conn->prepare("INSERT INTO reservations (customer_id, car_id, pickup_date, dropoff_date, pickup_location, dropoff_location, status, source, notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $source = 'Office';
        $stmt->bind_param('iisssssss', $customer_id, $car_id, $pickup_date, $dropoff_date, $pickup_location, $dropoff_location, $status, $source, $notes);
        if ($stmt->execute()) {
            if ($status === 'Confirmed') $conn->query("UPDATE cars SET availability_status='Reserved' WHERE id=".(int)$car_id." AND availability_status='Available'");
            header('Location: manage.php?msg=' . urlencode($status === 'Waitlisted' ? 'Reservation saved in waitlist because the car has a date conflict.' : 'Reservation confirmed successfully.'));
            exit;
        }
        $error = 'Could not save reservation: ' . $stmt->error;
        $stmt->close();
    }
}

if (isset($_GET['action'], $_GET['id'])) {
    stock_validate_action_token();
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'cancel') {
        $conn->query("UPDATE reservations SET status='Cancelled' WHERE id=$id");
        sync_rental_and_car_status($conn);
        header('Location: manage.php?msg=Reservation cancelled.'); exit;
    }
    if ($_GET['action'] === 'confirm') {
        $res = $conn->query("SELECT * FROM reservations WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($res) {
            $conflict = rental_period_conflicts($conn, (int)$res['car_id'], $res['pickup_date'], $res['dropoff_date']) || reservation_period_conflicts($conn, (int)$res['car_id'], $res['pickup_date'], $res['dropoff_date'], $id);
            if ($conflict) header('Location: manage.php?msg=' . urlencode('Cannot confirm yet; selected car still has a date conflict.'));
            else {
                $conn->query("UPDATE reservations SET status='Confirmed' WHERE id=$id");
                $conn->query("UPDATE cars SET availability_status='Reserved' WHERE id=".(int)$res['car_id']." AND availability_status='Available'");
                header('Location: manage.php?msg=Waitlist item confirmed.');
            }
            exit;
        }
    }
    if ($_GET['action'] === 'convert') {
        $res = $conn->query("SELECT * FROM reservations WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($res && $res['status'] !== 'Converted') {
            if (!$res['customer_id']) { header('Location: manage.php?msg=' . urlencode('Public booking request has no registered customer yet. Link/create customer first.')); exit; }
            $pricing = calculate_rental_total($conn, (int)$res['customer_id'], (int)$res['car_id'], $res['pickup_date'], $res['dropoff_date']);
            $total = (float)$pricing['total']; $discount = (float)$pricing['discount_amount'];
            $stmt = $conn->prepare("INSERT INTO rentals (customer_id, cars_id, start_date, end_date, pickup_location, dropoff_location, total_cost, discount_amount, notes) VALUES (?,?,?,?,?,?,?,?,?)");
            $notes = 'Converted from reservation #' . $id;
            $stmt->bind_param('iissssdds', $res['customer_id'], $res['car_id'], $res['pickup_date'], $res['dropoff_date'], $res['pickup_location'], $res['dropoff_location'], $total, $discount, $notes);
            if ($stmt->execute()) {
                $conn->query("UPDATE reservations SET status='Converted' WHERE id=$id");
                $conn->query("UPDATE cars SET availability_status='Rented' WHERE id=".(int)$res['car_id']);
                header('Location: ../rentals/manage.php?added=1'); exit;
            }
        }
    }
}

$customers = $conn->query("SELECT id, full_name, phone_number FROM customers ORDER BY full_name");
$cars = $conn->query("SELECT cars.id, cars.reg_no, cars.availability_status, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name, models.name, cars.reg_no");
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$where = 'WHERE 1';
if ($q !== '') { $safe = '%' . $conn->real_escape_string($q) . '%'; $where .= " AND (COALESCE(c.full_name,r.customer_name) LIKE '$safe' OR ca.reg_no LIKE '$safe' OR r.phone_number LIKE '$safe')"; }
if ($status !== '') $where .= " AND r.status='".$conn->real_escape_string($status)."'";
$list = $conn->query("SELECT r.*, COALESCE(c.full_name, r.customer_name) AS display_customer, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM reservations r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.car_id=ca.id LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id $where ORDER BY r.id DESC");
?>
<!DOCTYPE html><html><head><title>Reservations & Waitlist</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Reservations & Waitlist</h2>
<?php if($msg): ?><p class="success-msg"><?= h($msg); ?></p><?php endif; ?><?php if($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?>
<div class="card-box"><h3>Create Office Reservation</h3><form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;">
<div><label>Customer</label><select name="customer_id" required><option value="">Select</option><?php while($c=$customers->fetch_assoc()): ?><option value="<?= h($c['id']); ?>"><?= h($c['full_name'].' - '.$c['phone_number']); ?></option><?php endwhile; ?></select></div>
<div><label>Car</label><select name="car_id" required><option value="">Select</option><?php while($car=$cars->fetch_assoc()): ?><option value="<?= h($car['id']); ?>"><?= h(car_display_name($car).' ['.$car['availability_status'].']'); ?></option><?php endwhile; ?></select></div>
<div><label>Pickup Date</label><input type="date" name="pickup_date" required></div><div><label>Dropoff Date</label><input type="date" name="dropoff_date" required></div>
<div><label>Pickup Location</label><input type="text" name="pickup_location"></div><div><label>Dropoff Location</label><input type="text" name="dropoff_location"></div>
<div style="grid-column:1/-1"><label>Notes</label><textarea name="notes" rows="2"></textarea></div><div><button type="submit" name="save_reservation">Save Reservation</button></div></form></div>
<form method="GET" class="filter-box"><div><label>Search</label><input type="text" name="q" value="<?= h($q); ?>" placeholder="Customer / reg no / phone"></div><div><label>Status</label><select name="status"><option value="">All</option><?php foreach(['Pending','Confirmed','Waitlisted','Converted','Cancelled'] as $s): ?><option <?= $status===$s?'selected':''; ?>><?= h($s); ?></option><?php endforeach; ?></select></div><button>Filter</button><a href="manage.php" class="btn btn-danger">Clear</a> <a href="booking_link.php" class="btn btn-success">Send Booking Link</a> <a href="online_booking.php" class="btn btn-primary" target="_blank">Public Booking Form</a></form>
<div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Customer</th><th>Car</th><th>Dates</th><th>Locations</th><th>Source</th><th>Status</th><th>Actions</th></tr>
<?php if($list && $list->num_rows): while($r=$list->fetch_assoc()): ?><tr><td><?= h($r['id']); ?></td><td><?= h($r['display_customer'] ?: 'Public request'); ?><br><small><?= h($r['phone_number'] ?? ''); ?></small></td><td><?= h(car_display_name($r)); ?></td><td><?= h($r['pickup_date']); ?> to <?= h($r['dropoff_date']); ?></td><td><?= h($r['pickup_location']); ?><br><?= h($r['dropoff_location']); ?></td><td><?= h($r['source']); ?></td><td><strong><?= h($r['status']); ?></strong></td><td><?php if($r['status']==='Waitlisted'): ?><a class="btn btn-success" href="<?= h(stock_action_url('?action=confirm&id=' . (int)$r['id'])); ?>">Confirm</a><?php endif; ?><?php if(in_array($r['status'],['Confirmed','Pending'])): ?><a class="btn btn-primary" href="<?= h(stock_action_url('?action=convert&id=' . (int)$r['id'])); ?>">Convert to Rental</a><?php endif; ?><?php if(!in_array($r['status'],['Cancelled','Converted'])): ?><a class="btn btn-danger" onclick="return confirm('Cancel reservation?')" href="<?= h(stock_action_url('?action=cancel&id=' . (int)$r['id'])); ?>">Cancel</a><?php endif; ?></td></tr><?php endwhile; else: ?><tr><td colspan="8">No reservations found.</td></tr><?php endif; ?></table></div>
</div></body></html>
