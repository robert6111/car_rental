<?php
/*
 * Public booking request form (no login).
 * Requests are saved as unlinked reservations; staff link them to a customer
 * from Reservations before converting them into a rental.
 */
require_once __DIR__ . '/../includes/app.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/../includes/send_email.php';

const CR_PUBLIC_LIMITS = [
    'customer_name'    => ['Full name', 100],
    'phone_number'     => ['Phone', 30],
    'email'            => ['Email', 190],
    'pickup_location'  => ['Pickup location', 150],
    'dropoff_location' => ['Dropoff location', 150],
    'notes'            => ['Notes', 1000],
];
const CR_PUBLIC_MIN_FILL_SECONDS = 3;   // faster than this is treated as a bot
const CR_PUBLIC_PER_IP_HOUR = 5;        // successful requests per visitor per hour
const CR_PUBLIC_PER_IP_DAY = 15;        // successful requests per visitor per day
const CR_PUBLIC_ATTEMPTS_HOUR = 30;     // any submissions per visitor per hour
const CR_PUBLIC_GLOBAL_HOUR = 100;      // all visitors together per hour

$companyName = cr_company_name();
$open = cr_setting_bool('public_booking_enabled');
$msg = '';
$error = '';
$values = array_fill_keys(array_keys(CR_PUBLIC_LIMITS), '');
$values += ['car_id' => '', 'pickup_date' => '', 'dropoff_date' => ''];

if (isset($_GET['submitted']) && !empty($_SESSION['car_public_booking_success'])) {
    $msg = (string)$_SESSION['car_public_booking_success'];
    unset($_SESSION['car_public_booking_success']);
}

/** Notify the office by email when mail is set up; failures never block the booking. */
function cr_public_notify_office(array $v, string $carLabel, string $status, int $reservationId): void {
    $to = cr_setting('company_email');
    if ($to === '' || !cr_mail_configured()) return;
    $link = cr_absolute_url('reservations/manage.php?status=' . urlencode($status), true);
    $rows = [
        'Name' => $v['customer_name'], 'Phone' => $v['phone_number'], 'Email' => $v['email'] ?: '-',
        'Car' => $carLabel, 'Pickup' => cr_date($v['pickup_date']) . ($v['pickup_location'] ? ' (' . $v['pickup_location'] . ')' : ''),
        'Dropoff' => cr_date($v['dropoff_date']) . ($v['dropoff_location'] ? ' (' . $v['dropoff_location'] . ')' : ''),
        'Status' => $status, 'Notes' => $v['notes'] ?: '-',
    ];
    $html = '<p>A new online booking request (#' . $reservationId . ') was received.</p><table cellpadding="4">';
    foreach ($rows as $k => $val) $html .= '<tr><td><strong>' . h($k) . '</strong></td><td>' . nl2br(h($val)) . '</td></tr>';
    $html .= '</table>' . ($link ? '<p><a href="' . h($link) . '">Open reservations</a></p>' : '');
    try {
        sendEmail($to, 'New booking request #' . $reservationId, $html);
    } catch (Throwable $e) {
        error_log('Booking notification failed: ' . $e->getMessage());
    }
}

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $field) {
        $raw = $_POST[$field] ?? '';
        $values[$field] = is_string($raw) ? trim(str_replace("\0", '', $raw)) : '';
    }
    $values['customer_name'] = preg_replace('/\s+/u', ' ', $values['customer_name']);
    $car_id = (int)$values['car_id'];
    $honeypot = (string)($_POST['website'] ?? '');
    $renderedAt = (int)($_SESSION['car_public_form_at'] ?? 0);
    $postedToken = (string)($_POST['submission_token'] ?? '');
    $expectedToken = (string)($_SESSION['car_public_booking_token'] ?? '');

    if (!stock_rate_limit('public_booking_attempt', CR_PUBLIC_ATTEMPTS_HOUR, 3600)) {
        $error = 'Too many attempts. Please wait a while and try again, or call the office.';
    } elseif ($postedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $postedToken)) {
        $error = 'This form has expired or was already sent. Please check your details and send it again.';
    } elseif ($honeypot !== '' || $renderedAt === 0 || time() - $renderedAt < CR_PUBLIC_MIN_FILL_SECONDS) {
        // Bots: pretend it worked so they learn nothing.
        error_log('Public booking rejected as spam from ' . cr_client_ip());
        $_SESSION['car_public_booking_token'] = bin2hex(random_bytes(24));
        unset($_SESSION['car_public_form_at']);
        $_SESSION['car_public_booking_success'] = 'Thank you. Your booking request was received and the office will contact you to confirm it.';
        header('Location: online_booking.php?submitted=1', true, 303);
        exit;
    } else {
        foreach (CR_PUBLIC_LIMITS as $field => [$label, $max]) {
            if (mb_strlen($values[$field]) > $max) { $error = "$label is too long (max $max characters)."; break; }
        }
    }

    $selectedCar = null;
    if ($error === '' && $car_id > 0) {
        $carStmt = $conn->prepare("SELECT cars.id, cars.availability_status, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id WHERE cars.id = ?");
        $carStmt->bind_param('i', $car_id);
        $carStmt->execute();
        $selectedCar = $carStmt->get_result()->fetch_assoc() ?: null;
        $carStmt->close();
    }

    if ($error !== '') {
        // already set
    } elseif ($values['customer_name'] === '' || $values['phone_number'] === '' || !$car_id || $values['pickup_date'] === '' || $values['dropoff_date'] === '') {
        $error = 'Please fill in all required fields.';
    } elseif (mb_strlen($values['customer_name']) < 2) {
        $error = 'Please enter your full name.';
    } elseif (!preg_match('/^\+?[0-9 ()\-.]{6,30}$/', $values['phone_number']) || strlen(preg_replace('/\D/', '', $values['phone_number'])) < 6) {
        $error = 'Please enter a valid phone number (digits, spaces, + - ( ) only).';
    } elseif ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address or leave it blank.';
    } elseif (!$selectedCar || $selectedCar['availability_status'] === 'Maintenance') {
        $error = 'The selected car is not available for booking. Please choose another car.';
    } elseif (!cr_valid_date($values['pickup_date']) || !cr_valid_date($values['dropoff_date'])) {
        $error = 'Please enter valid pickup and dropoff dates.';
    } elseif ($dateError = cr_rental_dates_error($values['pickup_date'], $values['dropoff_date'])) {
        $error = str_replace(['start date', 'end date'], ['pickup date', 'dropoff date'], $dateError);
    } elseif ((new DateTime($values['pickup_date']))->diff(new DateTime($values['dropoff_date']))->days > 366) {
        $error = 'A booking cannot be longer than one year.';
    } elseif ($values['pickup_date'] > date('Y-m-d', strtotime(cr_today() . ' +1 year'))) {
        $error = 'Bookings can be made up to one year ahead.';
    } elseif (cr_rate_count(cr_rate_bucket('public_booking_ok'), 3600) >= CR_PUBLIC_PER_IP_HOUR
        || cr_rate_count(cr_rate_bucket('public_booking_ok'), 86400) >= CR_PUBLIC_PER_IP_DAY
        || cr_rate_count(cr_rate_bucket('public_booking_ok', false), 3600) >= CR_PUBLIC_GLOBAL_HOUR) {
        $error = 'We have received several requests from you already. Please wait before sending another, or call the office.';
    } else {
        // The same request sent twice (double click, back button) is stored once.
        $dup = $conn->prepare("SELECT id, status FROM reservations WHERE source='Online' AND car_id=? AND pickup_date=? AND dropoff_date=? AND phone_number=? AND status IN ('Pending','Waitlisted','Confirmed') AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1");
        $dup->bind_param('isss', $car_id, $values['pickup_date'], $values['dropoff_date'], $values['phone_number']);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();

        try {
            if ($existing) {
                $status = $existing['status'];
            } else {
                $conn->begin_transaction();
                cr_lock_car($conn, $car_id);
                $status = cr_car_unavailable_reason($conn, $car_id, $values['pickup_date'], $values['dropoff_date']) !== null ? 'Waitlisted' : 'Pending';
                $source = 'Online';
                $stmt = $conn->prepare('INSERT INTO reservations (customer_id, car_id, customer_name, phone_number, email, pickup_date, dropoff_date, pickup_location, dropoff_location, status, source, notes) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('issssssssss', $car_id, $values['customer_name'], $values['phone_number'], $values['email'], $values['pickup_date'], $values['dropoff_date'], $values['pickup_location'], $values['dropoff_location'], $status, $source, $values['notes']);
                if (!$stmt->execute()) throw new RuntimeException('Insert failed: ' . $stmt->error);
                $newId = (int)$stmt->insert_id;
                $stmt->close();
                $conn->commit();
                cr_rate_record(cr_rate_bucket('public_booking_ok'));
                cr_rate_record(cr_rate_bucket('public_booking_ok', false));
                cr_sync_car_statuses($conn, $car_id);
                cr_public_notify_office($values, car_display_name($selectedCar), $status, $newId);
            }

            $_SESSION['car_public_booking_token'] = bin2hex(random_bytes(24));
            unset($_SESSION['car_public_form_at']);
            $_SESSION['car_public_booking_success'] = $status === 'Waitlisted'
                ? 'Thank you. The car is already booked for some of those dates, so your request is on the waiting list. The office will contact you.'
                : 'Thank you. Your booking request was received and the office will contact you to confirm it.';
            header('Location: online_booking.php?submitted=1', true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Online booking failed: ' . $e->getMessage());
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $error = 'Your request could not be sent. Please try again or call the office.';
        }
    }
}

if ($open) {
    if (empty($_SESSION['car_public_booking_token'])) {
        $_SESSION['car_public_booking_token'] = bin2hex(random_bytes(24));
    }
    // Timer starts when the form is first opened, so fixing a typo and resending quickly is not treated as a bot.
    if (empty($_SESSION['car_public_form_at'])) $_SESSION['car_public_form_at'] = time();
    $formToken = (string)$_SESSION['car_public_booking_token'];

    // Public list: no registration plates. Identical models get a number so each option is distinct.
    $cars = [];
    $res = $conn->query("SELECT cars.id, cars.price_per_day, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id WHERE cars.availability_status <> 'Maintenance' ORDER BY makes.name, models.name, car_years.year, cars.id");
    $seen = [];
    while ($res && $c = $res->fetch_assoc()) {
        $label = car_display_name($c);
        $seen[$label] = ($seen[$label] ?? 0) + 1;
        $cars[] = ['id' => (int)$c['id'], 'label' => $label, 'n' => $seen[$label], 'price' => (float)$c['price_per_day']];
    }
    foreach ($cars as &$c) {
        if ($seen[$c['label']] > 1) $c['label'] .= ' (car ' . $c['n'] . ')';
    }
    unset($c);
}

$companyPhone = cr_setting('company_phone');
$companyEmail = cr_setting('company_email');
$hasLogo = cr_setting('company_logo') !== '';
$today = cr_today();
$maxDate = date('Y-m-d', strtotime($today . ' +1 year'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Book a Car - <?= h($companyName); ?></title>
    <style>
        *{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f6f9;margin:0;padding:30px 14px;color:#1f2937}.box{width:min(920px,96vw);margin:auto;background:#fff;border-radius:16px;padding:26px;box-shadow:0 10px 25px rgba(15,23,42,.08)}.brand{display:flex;align-items:center;gap:14px;margin-bottom:12px}.brand img{max-height:56px;max-width:160px;object-fit:contain}h1{margin:0 0 6px;font-size:26px}.muted{color:#64748b;margin-top:0}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.form-group{min-width:0}.full{grid-column:1/-1}label{display:block;font-weight:600;margin-bottom:5px}.opt{font-weight:400;color:#64748b}input,select,textarea{width:100%;padding:11px 12px;margin:0 0 15px;border:1px solid #d1d5db;border-radius:8px;background:#fff;font:inherit;color:inherit}input:focus,select:focus,textarea:focus{outline:2px solid #2563eb;outline-offset:1px}textarea{min-height:92px;resize:vertical}button{width:100%;background:#0f172a;color:#fff;border:0;border-radius:8px;padding:13px 18px;font-weight:700;font-size:15px;cursor:pointer}.success,.error{padding:11px 13px;border-radius:8px}.success{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0}.error{color:#991b1b;background:#fef2f2;border:1px solid #fecaca}.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}.contact{margin-top:18px;color:#64748b;font-size:14px}@media(max-width:680px){body{padding:14px 8px}.box{padding:20px 16px}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}h1{font-size:22px}}
    </style>
</head>
<body>
<main class="box">
    <div class="brand">
        <?php if ($hasLogo): ?><img src="../settings/logo.php" alt=""><?php endif; ?>
        <div><h1><?= h($companyName); ?></h1><p class="muted" style="margin:0">Car booking request</p></div>
    </div>

    <?php if ($msg): ?><p class="success" role="status"><?= h($msg); ?></p><?php endif; ?>

    <?php if (!$open): ?>
        <p class="error">Online booking is currently closed. Please contact the office to book a car.</p>
    <?php else: ?>
        <p class="muted">Send your details and the office will confirm availability. This is a request, not a confirmed booking.</p>
        <?php if ($error): ?><p class="error" role="alert"><?= h($error); ?></p><?php endif; ?>

        <form method="POST" autocomplete="on" class="stock-no-csrf">
            <input type="hidden" name="submission_token" value="<?= h($formToken); ?>">
            <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="customer_name">Full name *</label>
                    <input id="customer_name" name="customer_name" required maxlength="100" autocomplete="name" value="<?= h($values['customer_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone *</label>
                    <input id="phone_number" type="tel" name="phone_number" required maxlength="30" pattern="\+?[0-9 ()\-.]{6,30}" autocomplete="tel" value="<?= h($values['phone_number']); ?>">
                </div>
                <div class="form-group full">
                    <label for="email">Email <span class="opt">(optional)</span></label>
                    <input id="email" type="email" name="email" maxlength="190" autocomplete="email" value="<?= h($values['email']); ?>">
                </div>
                <div class="form-group full">
                    <label for="car_id">Car *</label>
                    <select id="car_id" name="car_id" required>
                        <option value="">Select a car</option>
                        <?php foreach ($cars as $c): ?>
                            <option value="<?= $c['id']; ?>" <?= $values['car_id'] === (string)$c['id'] ? 'selected' : ''; ?>><?= h($c['label'] . ' - ' . money_text($c['price']) . ' / day'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="pickup_date">Pickup date *</label>
                    <input id="pickup_date" type="date" name="pickup_date" required min="<?= h($today); ?>" max="<?= h($maxDate); ?>" value="<?= h($values['pickup_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="dropoff_date">Dropoff date *</label>
                    <input id="dropoff_date" type="date" name="dropoff_date" required min="<?= h($today); ?>" value="<?= h($values['dropoff_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="pickup_location">Pickup location <span class="opt">(optional)</span></label>
                    <input id="pickup_location" name="pickup_location" maxlength="150" value="<?= h($values['pickup_location']); ?>">
                </div>
                <div class="form-group">
                    <label for="dropoff_location">Dropoff location <span class="opt">(optional)</span></label>
                    <input id="dropoff_location" name="dropoff_location" maxlength="150" value="<?= h($values['dropoff_location']); ?>">
                </div>
                <div class="form-group full">
                    <label for="notes">Notes <span class="opt">(optional)</span></label>
                    <textarea id="notes" name="notes" maxlength="1000"><?= h($values['notes']); ?></textarea>
                </div>
            </div>
            <button type="submit">Send booking request</button>
        </form>
    <?php endif; ?>

    <?php if ($companyPhone || $companyEmail): ?>
        <p class="contact">Questions? <?= $companyPhone ? 'Call ' . h($companyPhone) : ''; ?><?= $companyPhone && $companyEmail ? ' or email ' : ($companyEmail ? 'Email ' : ''); ?><?= $companyEmail ? '<a href="mailto:' . h($companyEmail) . '">' . h($companyEmail) . '</a>' : ''; ?></p>
    <?php endif; ?>
</main>
</body>
</html>
