<?php
/*
 * Shared validation for the Add Customer and Edit Customer forms.
 * Included (require_once) by customers/add.php and customers/edit.php; defines functions only.
 * Values are only trimmed; prepared statements handle quoting (no real_escape_string).
 */
require_once __DIR__ . '/../includes/rental_status.php';

/**
 * Returns [array $data, ?string $error]. $excludeId = customer being edited (0 when adding).
 */
function cr_validate_customer_post(mysqli $conn, array $post, int $excludeId = 0): array {
    $limits = [
        'full_name'    => ['Full name', 255],
        'phone_number' => ['Phone number', 50],
        'email'        => ['Email', 255],
        'license_no'   => ['Driver licence number', 100],
        'address'      => ['Address', 255],
        'id_number'    => ['ID number', 100],
        'passport_no'  => ['Passport number', 100],
    ];
    $data = [];
    foreach ($limits as $field => [$label, $max]) {
        $data[$field] = trim((string)($post[$field] ?? ''));
    }
    $data['passport_expiry'] = null;
    $data['license_expiry'] = null;

    if ($data['full_name'] === '' || $data['phone_number'] === '' || $data['license_no'] === '') {
        return [$data, 'Full name, phone number and driver licence number are required.'];
    }
    foreach ($limits as $field => [$label, $max]) {
        if (mb_strlen($data[$field]) > $max) return [$data, "$label is too long (max $max characters)."];
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return [$data, 'Please enter a valid email address.'];
    }
    foreach (['license_expiry' => 'License expiry', 'passport_expiry' => 'Passport expiry'] as $field => $label) {
        $value = trim((string)($post[$field] ?? ''));
        if ($value === '') continue;
        if (!cr_valid_date($value)) return [$data, "$label is not a valid date."];
        $data[$field] = $value;
    }

    // Friendly duplicate checks.
    $stmt = $conn->prepare('SELECT id FROM customers WHERE license_no = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $data['license_no'], $excludeId);
    $stmt->execute();
    $dup = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($dup) return [$data, 'Another customer already has this driver licence number.'];

    if ($data['email'] !== '') {
        $stmt = $conn->prepare('SELECT id FROM customers WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $data['email'], $excludeId);
        $stmt->execute();
        $dup = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($dup) return [$data, 'Another customer already uses this email address.'];
    }

    return [$data, null];
}
