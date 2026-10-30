<?php
require_once __DIR__ . '/security.php';

function app_base_url(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/backend/');
    if ($pos !== false) {
        return substr($script, 0, $pos + strlen('/backend'));
    }
    $pos = strpos($script, '/backend');
    if ($pos !== false) {
        return substr($script, 0, $pos + strlen('/backend'));
    }
    return '/backend';
}

function app_url(string $path = ''): string {
    $base = rtrim(app_base_url(), '/');
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** True when at least one active Admin account exists (false on a fresh installation). */
function cr_active_admin_exists(mysqli $conn): bool {
    $res = $conn->query("SELECT 1 FROM users WHERE role_id = 1 AND is_active = 1 LIMIT 1");
    return $res && $res->num_rows > 0;
}

/** Logs a database error and returns a message that is safe to show to users. */
function cr_db_error(string $dbError, string $fallback = 'The record could not be saved. Please try again.'): string {
    error_log('Database error: ' . $dbError);
    if (stripos($dbError, 'Duplicate entry') !== false) return 'This record already exists (a value that must be unique is already used).';
    if (stripos($dbError, 'foreign key') !== false) return 'This record is linked to other records, so this change is not allowed.';
    return $fallback;
}

function is_admin_user(): bool {
    return isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1;
}


// Customer-ready business helpers
if (!defined('LOYALTY_DISCOUNT_MIN_RENTALS')) define('LOYALTY_DISCOUNT_MIN_RENTALS', 3);
if (!defined('LOYALTY_DISCOUNT_RATE')) define('LOYALTY_DISCOUNT_RATE', 0.10); // 10% after 3 completed rentals
if (!defined('LATE_PAYMENT_DAILY_RATE')) define('LATE_PAYMENT_DAILY_RATE', 0.01); // 1% per day after due date

function rental_day_count(string $startDate, string $endDate): int {
    try { $start = new DateTimeImmutable($startDate); $end = new DateTimeImmutable($endDate); }
    catch (Exception $e) { return 0; }
    if ($end < $start) return 0;
    return max(1, (int)$start->diff($end)->days);
}

function customer_completed_rental_count(mysqli $conn, int $customerId, int $excludeRentalId = 0): int {
    if ($customerId <= 0) return 0;
    $sql = "SELECT COUNT(*) AS total FROM rentals WHERE customer_id=? AND status='Completed'";
    if ($excludeRentalId > 0) {
        $sql .= " AND id<>?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $customerId, $excludeRentalId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $customerId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function loyalty_discount_rate(int $completedRentals): float {
    return $completedRentals >= (int)LOYALTY_DISCOUNT_MIN_RENTALS ? (float)LOYALTY_DISCOUNT_RATE : 0.0;
}

function calculate_rental_total(mysqli $conn, int $customerId, int $carId, string $startDate, string $endDate, int $excludeRentalId = 0): array {
    $days = rental_day_count($startDate, $endDate);
    $pricePerDay = 0.0;
    if ($carId > 0) {
        $stmt = $conn->prepare("SELECT price_per_day FROM cars WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $carId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pricePerDay = (float)($row['price_per_day'] ?? 0);
    }
    $subtotal = round($pricePerDay * $days, 2);
    $completed = customer_completed_rental_count($conn, $customerId, $excludeRentalId);
    $discountRate = loyalty_discount_rate($completed);
    $discountAmount = round($subtotal * $discountRate, 2);
    return [
        'days'=>$days,
        'price_per_day'=>$pricePerDay,
        'subtotal'=>$subtotal,
        'completed_rentals'=>$completed,
        'discount_rate'=>$discountRate,
        'discount_amount'=>$discountAmount,
        'total'=>max(0, round($subtotal - $discountAmount, 2)),
    ];
}

function date_days_after_today(?string $date): int {
    if (!$date) return 0;
    try { $end = new DateTimeImmutable($date); $today = new DateTimeImmutable('today'); }
    catch (Exception $e) { return 0; }
    if ($today <= $end) return 0;
    return (int)$end->diff($today)->days;
}

function overdue_same_day_completion_needed(?string $status, ?string $startDate, ?string $endDate): bool {
    if ($status !== 'Active' || !$startDate || !$endDate || $startDate !== $endDate) return false;
    try { $end = new DateTimeImmutable($endDate); $today = new DateTimeImmutable('today'); }
    catch (Exception $e) { return false; }
    return $today >= $end;
}

function rental_bill_summary(float $rentalTotal, float $totalPaid, ?string $endDate): array {
    $baseRemaining = max($rentalTotal - $totalPaid, 0);
    $lateDays = $baseRemaining > 0 ? date_days_after_today($endDate) : 0;
    $lateInterest = round($baseRemaining * (float)LATE_PAYMENT_DAILY_RATE * $lateDays, 2);
    $grandTotal = round($rentalTotal + $lateInterest, 2);
    $remaining = max(round($grandTotal - $totalPaid, 2), 0);
    return ['base_remaining'=>round($baseRemaining,2),'late_days'=>$lateDays,'late_interest'=>$lateInterest,'grand_total'=>$grandTotal,'remaining'=>$remaining,'status'=>$remaining<=0?'Paid':'In Debt'];
}

function normalize_plate(string $plate): string {
    return strtoupper(preg_replace('/\s+/', ' ', trim($plate)));
}



function money_value($value): float {
    if ($value === null || $value === '') return 0.0;
    $clean = str_replace([' ', ','], ['', '.'], (string)$value);
    $clean = preg_replace('/[^0-9.\-]/', '', $clean);
    return (float)$clean;
}

function rental_period_conflicts(mysqli $conn, int $carId, string $startDate, string $endDate, int $excludeRentalId = 0): bool {
    if ($carId <= 0 || !$startDate || !$endDate) return false;
    $sql = "SELECT id FROM rentals WHERE cars_id=? AND status IN ('Pending','Active') AND NOT (end_date < ? OR start_date > ?)";
    if ($excludeRentalId > 0) $sql .= " AND id<>?";
    $stmt = $conn->prepare($sql);
    if ($excludeRentalId > 0) $stmt->bind_param('issi', $carId, $startDate, $endDate, $excludeRentalId);
    else $stmt->bind_param('iss', $carId, $startDate, $endDate);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function reservation_period_conflicts(mysqli $conn, int $carId, string $startDate, string $endDate, int $excludeReservationId = 0): bool {
    if ($carId <= 0 || !$startDate || !$endDate) return false;
    $sql = "SELECT id FROM reservations WHERE car_id=? AND status IN ('Pending','Confirmed') AND NOT (dropoff_date < ? OR pickup_date > ?)";
    if ($excludeReservationId > 0) $sql .= " AND id<>?";
    $stmt = $conn->prepare($sql);
    if ($excludeReservationId > 0) $stmt->bind_param('issi', $carId, $startDate, $endDate, $excludeReservationId);
    else $stmt->bind_param('iss', $carId, $startDate, $endDate);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function car_display_name(array $row): string {
    $name = trim(($row['make_name'] ?? $row['make'] ?? '') . ' ' . ($row['model_name'] ?? $row['model'] ?? '') . ' ' . ($row['car_year'] ?? $row['year'] ?? ''));
    $reg = $row['reg_no'] ?? $row['car_reg'] ?? '';
    return trim($name . ($reg ? ' - ' . $reg : '')) ?: 'N/A';
}

?>
