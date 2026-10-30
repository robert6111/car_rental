<?php
require_once __DIR__ . '/../includes/app.php';
require __DIR__ . '/../includes/db.php';

function car_public_phone_candidates(string $phone): array {
    return cr_phone_variants($phone);
}

function car_public_existing_customer_id(mysqli $conn, string $phone, string $email): int {
    if ($email !== '') {
        $stmt = $conn->prepare('SELECT id FROM customers WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int) $row['id'];
    }

    $candidates = car_public_phone_candidates($phone);
    if (!$candidates) return 0;
    $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number,' ',''),'-',''),'(',''),')',''),'+','')";
    if (count($candidates) === 1) {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE $phoneExpr=? LIMIT 1");
        $stmt->bind_param('s', $candidates[0]);
    } else {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE $phoneExpr IN (?,?) LIMIT 1");
        $stmt->bind_param('ss', $candidates[0], $candidates[1]);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['id'] : 0;
}

if (empty($_SESSION['car_public_booking_token'])) {
    $_SESSION['car_public_booking_token'] = bin2hex(random_bytes(24));
}
$formToken = (string) $_SESSION['car_public_booking_token'];
$msg = '';
$error = '';

if (isset($_GET['submitted']) && !empty($_SESSION['car_public_booking_success'])) {
    $msg = (string) $_SESSION['car_public_booking_success'];
    unset($_SESSION['car_public_booking_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['submission_token'] ?? '');
    $expectedToken = (string) ($_SESSION['car_public_booking_token'] ?? '');
    $usedTokens = $_SESSION['car_public_booking_used_tokens'] ?? [];
    if (!is_array($usedTokens)) $usedTokens = [];

    if ($postedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $postedToken) || isset($usedTokens[$postedToken])) {
        $error = 'This booking form has already been submitted or expired. Refresh the page and try again.';
    } else {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $car_id = (int) ($_POST['car_id'] ?? 0);
        $pickup_date = $_POST['pickup_date'] ?? '';
        $dropoff_date = $_POST['dropoff_date'] ?? '';
        $pickup_location = trim($_POST['pickup_location'] ?? '');
        $dropoff_location = trim($_POST['dropoff_location'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$customer_name || !$phone || !$car_id || !$pickup_date || !$dropoff_date || $dropoff_date < $pickup_date) {
            $error = 'Please fill all required fields correctly.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address or leave it blank.';
        } else {
            $usedTokens[$postedToken] = time();
            if (count($usedTokens) > 10) $usedTokens = array_slice($usedTokens, -10, null, true);
            $_SESSION['car_public_booking_used_tokens'] = $usedTokens;

            try {
                $conn->begin_transaction();
                $customer_id = car_public_existing_customer_id($conn, $phone, $email);
                $status = (rental_period_conflicts($conn, $car_id, $pickup_date, $dropoff_date) || reservation_period_conflicts($conn, $car_id, $pickup_date, $dropoff_date)) ? 'Waitlisted' : 'Pending';
                $source = 'Online';
                $stmt = $conn->prepare('INSERT INTO reservations (customer_id, car_id, customer_name, phone_number, email, pickup_date, dropoff_date, pickup_location, dropoff_location, status, source, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $customerIdValue = $customer_id > 0 ? $customer_id : null;
                $stmt->bind_param('iissssssssss', $customerIdValue, $car_id, $customer_name, $phone, $email, $pickup_date, $dropoff_date, $pickup_location, $dropoff_location, $status, $source, $notes);
                if (!$stmt->execute()) throw new RuntimeException('Could not submit request.');
                $stmt->close();
                $conn->commit();

                $_SESSION['car_public_booking_token'] = bin2hex(random_bytes(24));
                $_SESSION['car_public_booking_success'] = $status === 'Waitlisted'
                    ? 'Your request was received and placed on the waitlist. Staff will contact you.'
                    : 'Your booking request was received. Staff will confirm it soon.';
                header('Location: online_booking.php?submitted=1', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($conn->errno === 0) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                } else {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                }
                unset($usedTokens[$postedToken]);
                $_SESSION['car_public_booking_used_tokens'] = $usedTokens;
                $error = 'Could not submit request. Please try again.';
            }
        }
    }
}

$cars = $conn->query("SELECT cars.id, cars.reg_no, cars.price_per_day, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id WHERE cars.availability_status IN ('Available','Reserved') ORDER BY makes.name, models.name");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Online Booking Request</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:30px 14px;color:#1f2937}.box{width:min(920px,96vw);margin:auto;background:#fff;border-radius:16px;padding:26px;box-shadow:0 10px 25px rgba(15,23,42,.08)}h1{margin:0 0 8px;font-size:28px}.muted{color:#64748b;margin-top:0}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.form-group{min-width:0}.full{grid-column:1/-1}label{display:block;font-weight:700;margin-bottom:5px}input,select,textarea{width:100%;padding:11px 12px;margin:0 0 15px;border:1px solid #d1d5db;border-radius:8px;background:#fff;font:inherit}textarea{min-height:92px;resize:vertical}button{width:100%;background:#0f172a;color:white;border:0;border-radius:8px;padding:13px 18px;font-weight:700;font-size:15px;cursor:pointer}.success,.error{padding:11px 13px;border-radius:8px}.success{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0}.error{color:#991b1b;background:#fef2f2;border:1px solid #fecaca}@media(max-width:680px){body{padding:14px 8px}.box{padding:20px 16px}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}h1{font-size:24px}}
    </style>
</head>
<body>
<div class="box">
    <h1>Car Booking Request</h1>
    <p class="muted">Fill this form and the office will confirm availability.</p>
    <?php if ($msg): ?><p class="success"><?= h($msg); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= h($error); ?></p><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="submission_token" value="<?= h($formToken); ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>Full Name *</label>
                <input name="customer_name" required value="<?= h($_POST['customer_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Phone *</label>
                <input name="phone_number" required value="<?= h($_POST['phone_number'] ?? ''); ?>">
            </div>
            <div class="form-group full">
                <label>Email Optional</label>
                <input type="email" name="email" value="<?= h($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group full">
                <label>Car *</label>
                <select name="car_id" required>
                    <option value="">Select car</option>
                    <?php while ($c = $cars->fetch_assoc()): ?>
                        <option value="<?= h($c['id']); ?>" <?= (string)($_POST['car_id'] ?? '') === (string)$c['id'] ? 'selected' : ''; ?>><?= h(car_display_name($c) . ' - ' . money_text((float)$c['price_per_day']) . '/day'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Pickup Date *</label>
                <input type="date" name="pickup_date" required value="<?= h($_POST['pickup_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Dropoff Date *</label>
                <input type="date" name="dropoff_date" required value="<?= h($_POST['dropoff_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Pickup Location</label>
                <input name="pickup_location" value="<?= h($_POST['pickup_location'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Dropoff Location</label>
                <input name="dropoff_location" value="<?= h($_POST['dropoff_location'] ?? ''); ?>">
            </div>
            <div class="form-group full">
                <label>Notes</label>
                <textarea name="notes"><?= h($_POST['notes'] ?? ''); ?></textarea>
            </div>
        </div>
        <button type="submit">Send Booking Request</button>
    </form>
</div>
</body>
</html>
