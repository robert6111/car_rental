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
$today = cr_today();
$maxDate = date('Y-m-d', strtotime($today . ' +1 year'));
?>
<?php cr_auth_start('Book a car', true); ?>
<h1>Car booking request</h1>
<?php if ($msg): ?><?= cr_alert('success', $msg); ?><?php endif; ?>

<?php if (!$open): ?>
    <?= cr_alert('info', 'Online booking is currently closed. Please contact the office to book a car.'); ?>
<?php else: ?>
    <p class="lead">Send your details and the office will confirm availability. This is a request, not a confirmed booking.</p>
    <?= cr_alert('error', $error); ?>

    <form method="POST" autocomplete="on" class="stock-no-csrf">
        <input type="hidden" name="submission_token" value="<?= h($formToken); ?>">
        <div class="sr-only" aria-hidden="true"><label for="website">Leave this empty</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <div class="form-grid">
            <div class="field">
                <label for="customer_name">Full name <span class="req">*</span></label>
                <input id="customer_name" name="customer_name" required maxlength="100" autocomplete="name" value="<?= h($values['customer_name']); ?>">
            </div>
            <div class="field">
                <label for="phone_number">Phone <span class="req">*</span></label>
                <input id="phone_number" type="tel" name="phone_number" required maxlength="30" pattern="\+?[0-9 ()\-.]{6,30}" autocomplete="tel" value="<?= h($values['phone_number']); ?>">
            </div>
            <div class="field span-all">
                <label for="email">Email <span class="opt">(optional)</span></label>
                <input id="email" type="email" name="email" maxlength="190" autocomplete="email" value="<?= h($values['email']); ?>">
            </div>
            <div class="field span-all">
                <label for="car_id">Car <span class="req">*</span></label>
                <select id="car_id" name="car_id" required>
                    <option value="">Select a car</option>
                    <?php foreach ($cars as $c): ?>
                        <option value="<?= $c['id']; ?>" <?= $values['car_id'] === (string)$c['id'] ? 'selected' : ''; ?>><?= h($c['label'] . ' - ' . money_text($c['price']) . ' / day'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="pickup_date">Pickup date <span class="req">*</span></label>
                <input id="pickup_date" type="date" name="pickup_date" required min="<?= h($today); ?>" max="<?= h($maxDate); ?>" value="<?= h($values['pickup_date']); ?>">
            </div>
            <div class="field">
                <label for="dropoff_date">Return date <span class="req">*</span></label>
                <input id="dropoff_date" type="date" name="dropoff_date" required min="<?= h($today); ?>" value="<?= h($values['dropoff_date']); ?>">
            </div>
            <div class="field">
                <label for="pickup_location">Pickup location <span class="opt">(optional)</span></label>
                <input id="pickup_location" name="pickup_location" maxlength="150" value="<?= h($values['pickup_location']); ?>">
            </div>
            <div class="field">
                <label for="dropoff_location">Return location <span class="opt">(optional)</span></label>
                <input id="dropoff_location" name="dropoff_location" maxlength="150" value="<?= h($values['dropoff_location']); ?>">
            </div>
            <div class="field span-all">
                <label for="notes">Notes <span class="opt">(optional)</span></label>
                <textarea id="notes" name="notes" maxlength="1000" rows="3"><?= h($values['notes']); ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-block">Send booking request</button>
        </div>
    </form>
<?php endif; ?>
<?php
$contact = '';
if ($companyPhone !== '') $contact .= '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $companyPhone)) . '">' . h($companyPhone) . '</a>';
if ($companyEmail !== '') $contact .= ($contact ? ' &nbsp;·&nbsp; ' : '') . '<a href="mailto:' . h($companyEmail) . '">' . h($companyEmail) . '</a>';
cr_auth_end($contact !== '' ? 'Questions? ' . $contact : '');
