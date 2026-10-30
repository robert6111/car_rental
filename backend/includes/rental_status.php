<?php
/*
 * Rental lifecycle rules (shared by every page that creates or changes rentals).
 *
 *  - A rental starting in the future is "Pending"; it becomes "Active" on its start date.
 *  - When the end date has passed, the rental is "Completed" automatically.
 *  - The car is only released when staff confirm the return (returned_at). Until then a car
 *    whose rental has ended shows as "Not Returned" and cannot be booked for overlapping dates.
 *  - Late-payment interest is recorded day by day in rental_charges, so it stays owed even
 *    after the original rental cost is paid.
 */
require_once __DIR__ . '/db.php';

const CR_RENTAL_OPEN_STATUSES = ['Pending', 'Active'];          // still to happen / happening
const CR_RENTAL_BILLABLE_STATUSES = ['Pending', 'Active', 'Completed']; // money is owed for these

function cr_today(): string {
    return date('Y-m-d');
}

function cr_valid_date(?string $value): bool {
    if (!$value) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

/** Status a new rental should get: Pending for a future start date, otherwise Active. */
function cr_rental_initial_status(string $startDate): string {
    return $startDate > cr_today() ? 'Pending' : 'Active';
}

/* ------------------------------------------------------------------ */
/* Status sync                                                         */
/* ------------------------------------------------------------------ */

function sync_rental_and_car_status(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Pending rentals start on their start date.
    $conn->query("UPDATE rentals SET status = 'Active' WHERE status = 'Pending' AND start_date <= CURDATE()");

    // Rentals finish automatically after their end date. The car is NOT released here.
    $conn->query("UPDATE rentals SET status = 'Completed', completed_at = COALESCE(completed_at, NOW()) WHERE status = 'Active' AND end_date < CURDATE()");

    cr_sync_car_statuses($conn);
    cr_accrue_late_interest_daily($conn);
}

/** Recalculates each car's current status. Cars set to Maintenance by staff are left alone. */
function cr_sync_car_statuses(mysqli $conn, ?int $carId = null): void {
    $where = "c.availability_status <> 'Maintenance'";
    if ($carId) $where .= ' AND c.id = ' . (int)$carId;
    $conn->query("UPDATE cars c SET c.availability_status = CASE
            WHEN EXISTS (SELECT 1 FROM rentals r WHERE r.cars_id = c.id AND r.status = 'Completed' AND r.returned_at IS NULL AND r.end_date < CURDATE()) THEN 'Not Returned'
            WHEN EXISTS (SELECT 1 FROM rentals r WHERE r.cars_id = c.id AND r.status IN ('Active','Completed') AND r.returned_at IS NULL) THEN 'Rented'
            WHEN EXISTS (SELECT 1 FROM rentals r WHERE r.cars_id = c.id AND r.status = 'Pending' AND r.start_date <= CURDATE() + INTERVAL 1 DAY)
              OR EXISTS (SELECT 1 FROM reservations rs WHERE rs.car_id = c.id AND rs.status = 'Confirmed' AND rs.pickup_date <= CURDATE() + INTERVAL 1 DAY AND rs.dropoff_date >= CURDATE()) THEN 'Reserved'
            ELSE 'Available' END
        WHERE $where");
}

/* ------------------------------------------------------------------ */
/* Availability                                                        */
/* ------------------------------------------------------------------ */

/** Locks the car row until the transaction ends, so two people cannot book it at the same moment. */
function cr_lock_car(mysqli $conn, int $carId): ?array {
    $stmt = $conn->prepare('SELECT id, availability_status, price_per_day FROM cars WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $carId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Returns a message explaining why the car cannot be booked for these dates, or null when it can.
 * A car that has not been returned yet is treated as busy until today (its rental "stretches").
 */
function cr_car_unavailable_reason(mysqli $conn, int $carId, string $startDate, string $endDate, int $excludeRentalId = 0, int $excludeReservationId = 0): ?string {
    $stmt = $conn->prepare('SELECT availability_status FROM cars WHERE id = ?');
    $stmt->bind_param('i', $carId);
    $stmt->execute();
    $car = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$car) return 'The selected car does not exist.';
    if ($car['availability_status'] === 'Maintenance' && $startDate <= cr_today()) {
        return 'This car is in maintenance. Move it back to Available first, or choose later dates.';
    }

    $sql = "SELECT id, start_date, end_date, status, returned_at FROM rentals
            WHERE cars_id = ? AND id <> ?
              AND (status IN ('Pending','Active') OR (status = 'Completed' AND returned_at IS NULL))
              AND start_date <= ?
              AND (CASE WHEN returned_at IS NULL AND status IN ('Active','Completed') THEN GREATEST(end_date, CURDATE()) ELSE end_date END) >= ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $carId, $excludeRentalId, $endDate, $startDate);
    $stmt->execute();
    $clash = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($clash) {
        if ($clash['status'] === 'Completed') {
            return 'This car has not been returned from rental #' . (int)$clash['id'] . ' yet. Confirm the return first.';
        }
        return 'This car is already booked from ' . cr_date($clash['start_date']) . ' to ' . cr_date($clash['end_date']) . ' (rental #' . (int)$clash['id'] . ').';
    }

    $stmt = $conn->prepare("SELECT id, pickup_date, dropoff_date FROM reservations
            WHERE car_id = ? AND id <> ? AND status = 'Confirmed' AND pickup_date <= ? AND dropoff_date >= ? LIMIT 1");
    $stmt->bind_param('iiss', $carId, $excludeReservationId, $endDate, $startDate);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($res) {
        return 'This car has a confirmed reservation from ' . cr_date($res['pickup_date']) . ' to ' . cr_date($res['dropoff_date']) . ' (reservation #' . (int)$res['id'] . ').';
    }
    return null;
}

/**
 * Validates a rental date range typed by a user. Returns an error message or null.
 * $allowPastStart lets existing rentals keep a start date in the past when edited.
 */
function cr_rental_dates_error(string $startDate, string $endDate, bool $allowPastStart = false): ?string {
    if (!cr_valid_date($startDate) || !cr_valid_date($endDate)) return 'Please enter valid start and end dates.';
    if ($endDate < $startDate) return 'The end date cannot be before the start date.';
    if (!$allowPastStart && $startDate < cr_today()) return 'The start date cannot be in the past.';
    if ((new DateTime($startDate))->diff(new DateTime($endDate))->days > 366) return 'A rental cannot be longer than one year.';
    return null;
}

/* ------------------------------------------------------------------ */
/* Returns                                                             */
/* ------------------------------------------------------------------ */

/** Staff confirm the car came back. Releases the car and closes open not-returned cases. */
function cr_confirm_return(mysqli $conn, int $rentalId, int $userId, ?int $mileage = null, string $notes = ''): ?string {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, cars_id, status, returned_at FROM rentals WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $rentalId);
        $stmt->execute();
        $rental = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rental) { $conn->rollback(); return 'Rental not found.'; }
        if ($rental['returned_at'] !== null) { $conn->rollback(); return 'This car was already returned.'; }
        if (!in_array($rental['status'], ['Active', 'Completed'], true)) { $conn->rollback(); return 'Only active or finished rentals can be returned.'; }

        $notes = mb_substr(trim($notes), 0, 1000);
        $stmt = $conn->prepare("UPDATE rentals SET returned_at = NOW(), returned_by = ?, return_mileage = ?, return_notes = ?,
                status = 'Completed', completed_at = COALESCE(completed_at, NOW()) WHERE id = ?");
        $stmt->bind_param('iisi', $userId, $mileage, $notes, $rentalId);
        $stmt->execute();
        $stmt->close();

        if ($mileage !== null && $mileage > 0) {
            $stmt = $conn->prepare('UPDATE cars SET current_mileage = GREATEST(COALESCE(current_mileage, 0), ?) WHERE id = ?');
            $carId = (int)$rental['cars_id'];
            $stmt->bind_param('ii', $mileage, $carId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE not_returned_cases SET status = 'Resolved', resolved_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), '\nCar returned on ', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'), '.')
                WHERE rental_id = ? AND status IN ('Open','Followed Up','Reported')");
        $stmt->bind_param('i', $rentalId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Confirm return failed: ' . $e->getMessage());
        return 'The return could not be saved. Please try again.';
    }
    cr_sync_car_statuses($conn, (int)$rental['cars_id']);
    return null;
}

/* ------------------------------------------------------------------ */
/* Late-payment interest (recorded, not recalculated)                  */
/* ------------------------------------------------------------------ */

/** Runs the daily interest accrual once per day (first page view of the day). */
function cr_accrue_late_interest_daily(mysqli $conn): void {
    $today = cr_today();
    if (cr_setting('interest_accrued_on') === $today) return;
    cr_accrue_late_interest($conn);
    cr_settings_save($conn, ['interest_accrued_on' => $today]);
}

/**
 * Records one late-interest charge per overdue day, up to and including today.
 * Each day's charge = unpaid rental cost at the end of that day x daily rate.
 * Existing charges are never changed, so interest that was owed stays owed.
 */
function cr_accrue_late_interest(mysqli $conn, ?int $rentalId = null): void {
    $rate = late_interest_daily_fraction();
    if ($rate <= 0) return;
    $today = cr_today();

    $sql = "SELECT r.id, r.total_cost, r.end_date,
                   (SELECT MAX(charge_date) FROM rental_charges rc WHERE rc.rental_id = r.id AND rc.charge_type = 'late_interest') AS last_charge
            FROM rentals r
            WHERE r.status IN ('Active','Completed') AND r.end_date < ? AND r.total_cost > 0";
    if ($rentalId) $sql .= ' AND r.id = ' . (int)$rentalId;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $rentals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$rentals) return;

    $pay = $conn->prepare('SELECT payment_date, SUM(amount) AS amount FROM payment WHERE rental_id = ? GROUP BY payment_date ORDER BY payment_date');
    $ins = $conn->prepare("INSERT IGNORE INTO rental_charges (rental_id, charge_type, charge_date, base_amount, rate, amount) VALUES (?, 'late_interest', ?, ?, ?, ?)");
    $ratePercent = round($rate * 100, 4);

    foreach ($rentals as $r) {
        $id = (int)$r['id'];
        $total = (float)$r['total_cost'];
        $from = new DateTime($r['last_charge'] ?: $r['end_date']);
        $from->modify('+1 day');
        $to = new DateTime($today);
        if ($from > $to) continue;

        $pay->bind_param('i', $id);
        $pay->execute();
        $payments = $pay->get_result()->fetch_all(MYSQLI_ASSOC);

        for ($day = clone $from; $day <= $to; $day->modify('+1 day')) {
            $d = $day->format('Y-m-d');
            $paid = 0.0;
            foreach ($payments as $p) if ($p['payment_date'] <= $d) $paid += (float)$p['amount'];
            $base = round(max($total - $paid, 0), 2);
            if ($base <= 0) break; // fully paid: no more interest from here on
            $amount = round($base * $rate, 2);
            if ($amount <= 0) continue;
            $ins->bind_param('isddd', $id, $d, $base, $ratePercent, $amount);
            $ins->execute();
        }
    }
    $pay->close();
    $ins->close();
}

/** SQL expressions for a rental's recorded late interest (use with a rentals alias, e.g. 'r'). */
function cr_sql_late_interest(string $alias = 'r'): string {
    return "(SELECT COALESCE(SUM(rc.amount), 0) FROM rental_charges rc WHERE rc.rental_id = $alias.id AND rc.charge_type = 'late_interest')";
}
function cr_sql_late_days(string $alias = 'r'): string {
    return "(SELECT COUNT(*) FROM rental_charges rc WHERE rc.rental_id = $alias.id AND rc.charge_type = 'late_interest')";
}
function cr_sql_total_paid(string $alias = 'r'): string {
    return "(SELECT COALESCE(SUM(p.amount), 0) FROM payment p WHERE p.rental_id = $alias.id)";
}

/** Full balance of one rental, read from the database. */
function cr_rental_bill(mysqli $conn, int $rentalId): ?array {
    $stmt = $conn->prepare('SELECT r.id, r.total_cost, r.status, r.end_date, ' . cr_sql_total_paid('r') . ' AS total_paid, '
        . cr_sql_late_interest('r') . ' AS late_interest, ' . cr_sql_late_days('r') . ' AS late_days FROM rentals r WHERE r.id = ?');
    $stmt->bind_param('i', $rentalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return rental_bill_summary((float)$row['total_cost'], (float)$row['total_paid'], $row['end_date'], (float)$row['late_interest'], (int)$row['late_days']) + ['rental_status' => $row['status']];
}

/* ------------------------------------------------------------------ */
/* Payments                                                            */
/* ------------------------------------------------------------------ */

function cr_payment_methods(): array {
    return ['Cash', 'Card', 'Bank Transfer', 'Mobile Money', 'Online', 'Cheque', 'Other'];
}

/**
 * Records a payment safely: the rental row is locked so a double click or two cashiers
 * cannot pay more than is owed. Returns null on success or an error message.
 */
function cr_record_payment(mysqli $conn, int $rentalId, $amountInput, string $method, string $note, int $userId, ?string $paymentDate = null): ?string {
    $amount = cr_parse_money($amountInput);
    if ($amount === null || $amount <= 0) return 'Enter a valid payment amount greater than zero.';
    if (!in_array($method, cr_payment_methods(), true)) $method = 'Other';
    $note = mb_substr(trim($note), 0, 255);
    // Payments are always dated today: interest already recorded for past days is final,
    // so a backdated payment would not reduce it and would leave the books inconsistent.
    $paymentDate = cr_today();

    cr_accrue_late_interest($conn, $rentalId);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, status FROM rentals WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $rentalId);
        $stmt->execute();
        $rental = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rental) { $conn->rollback(); return 'Rental not found.'; }
        if (!in_array($rental['status'], CR_RENTAL_BILLABLE_STATUSES, true)) { $conn->rollback(); return 'Payments cannot be added to a ' . strtolower($rental['status']) . ' rental.'; }

        $bill = cr_rental_bill($conn, $rentalId);
        if ($amount > round($bill['remaining'], 2) + 0.001) {
            $conn->rollback();
            return 'The amount is more than the remaining balance (' . money_text($bill['remaining']) . ').';
        }
        $stmt = $conn->prepare('INSERT INTO payment (payment_date, rental_id, amount, payment_method, payment_note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sidssi', $paymentDate, $rentalId, $amount, $method, $note, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Payment failed: ' . $e->getMessage());
        return 'The payment could not be saved. Please try again.';
    }
    return null;
}
