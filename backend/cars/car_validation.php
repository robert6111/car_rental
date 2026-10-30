<?php
/*
 * Shared validation for the Add Car and Edit Car forms.
 * Included (require_once) by cars/add.php and cars/edit.php; defines functions only.
 */
require_once __DIR__ . '/../includes/rental_status.php'; // cr_valid_date()

/** Same rule as the reg_no input's pattern attribute (checked after normalize_plate()). */
const CR_CAR_REG_PATTERN = '/^[A-Z0-9][A-Z0-9\s\-\.]{1,19}$/';

/** Statuses staff may choose by hand. Rented / Reserved / Not Returned are computed automatically. */
const CR_CAR_MANUAL_STATUSES = ['Available', 'Maintenance'];
const CR_CAR_COMPUTED_STATUSES = ['Rented', 'Reserved', 'Not Returned'];

/**
 * Validates the posted car fields.
 * Returns [array $data, ?string $error]. $data holds clean values ready for binding.
 * $excludeId = the car being edited (0 when adding).
 */
function cr_validate_car_post(mysqli $conn, array $post, int $excludeId = 0): array {
    $data = [
        'reg_no'            => normalize_plate((string)($post['reg_no'] ?? '')),
        'make_id'           => (int)($post['make_id'] ?? 0),
        'model_id'          => (int)($post['model_id'] ?? 0),
        'year_id'           => (int)($post['year_id'] ?? 0),
        'vin'               => trim((string)($post['vin'] ?? '')),
        'color'             => trim((string)($post['color'] ?? '')),
        'branch'            => trim((string)($post['branch'] ?? '')),
        'current_mileage'   => null,
        'insurance_expiry'  => null,
        'inspection_expiry' => null,
        'price_per_day'     => null,
        'purchase_value'    => 0.0,
    ];

    if ($data['reg_no'] === '' || !$data['make_id'] || !$data['model_id'] || !$data['year_id']) {
        return [$data, 'Registration number, make, model and year are required.'];
    }
    if (!preg_match(CR_CAR_REG_PATTERN, $data['reg_no'])) {
        return [$data, 'Enter a valid registration number (2-20 characters: letters, numbers, spaces, hyphen or dot).'];
    }
    if (mb_strlen($data['vin']) > 100)   return [$data, 'VIN / chassis number is too long (max 100 characters).'];
    if (mb_strlen($data['color']) > 50)  return [$data, 'Color is too long (max 50 characters).'];
    if (mb_strlen($data['branch']) > 120) return [$data, 'Branch / location is too long (max 120 characters).'];

    // Model must belong to the selected make.
    $stmt = $conn->prepare('SELECT id FROM models WHERE id = ? AND make_id = ?');
    $stmt->bind_param('ii', $data['model_id'], $data['make_id']);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$ok) return [$data, 'The selected model does not belong to the selected make.'];

    $stmt = $conn->prepare('SELECT id FROM car_years WHERE id = ?');
    $stmt->bind_param('i', $data['year_id']);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$ok) return [$data, 'Please select a valid year.'];

    $mileage = trim((string)($post['current_mileage'] ?? ''));
    if ($mileage !== '') {
        if (!preg_match('/^\d{1,9}$/', $mileage)) return [$data, 'Current mileage must be a whole number of 0 or more.'];
        $data['current_mileage'] = (int)$mileage;
    }

    foreach (['insurance_expiry' => 'Insurance expiry', 'inspection_expiry' => 'Inspection expiry'] as $field => $label) {
        $value = trim((string)($post[$field] ?? ''));
        if ($value === '') continue;
        if (!cr_valid_date($value)) return [$data, $label . ' is not a valid date.'];
        $data[$field] = $value;
    }

    $price = cr_parse_money($post['price_per_day'] ?? '');
    if ($price === null || $price <= 0) return [$data, 'Price per day must be a valid amount greater than 0.'];
    $data['price_per_day'] = $price;

    $purchaseInput = trim((string)($post['purchase_value'] ?? ''));
    if ($purchaseInput !== '') {
        $purchase = cr_parse_money($purchaseInput);
        if ($purchase === null || $purchase < 0) return [$data, 'Purchase value must be a valid amount of 0 or more.'];
        $data['purchase_value'] = $purchase;
    }

    // Friendly duplicate check (the UNIQUE index still protects against races).
    $stmt = $conn->prepare('SELECT id FROM cars WHERE reg_no = ? AND id <> ?');
    $stmt->bind_param('si', $data['reg_no'], $excludeId);
    $stmt->execute();
    $dup = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($dup) return [$data, 'A car with this registration number already exists.'];

    return [$data, null];
}
