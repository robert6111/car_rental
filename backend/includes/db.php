<?php
/*
 * Car Rental database connection.
 *
 * Keep the project's existing dedicated database account as the first choice.
 * For a normal local XAMPP installation, also allow the standard local
 * root-with-no-password account as a fallback when the dedicated account has
 * not been created yet. Production/hosted credentials can be supplied through
 * CAR_RENTAL_DB_HOST, CAR_RENTAL_DB_USER, CAR_RENTAL_DB_PASS and
 * CAR_RENTAL_DB_NAME environment variables without changing application code.
 */

$servername = getenv('CAR_RENTAL_DB_HOST') ?: 'localhost';
$database   = getenv('CAR_RENTAL_DB_NAME') ?: 'car_rental';
$envUser    = getenv('CAR_RENTAL_DB_USER');
$envPass    = getenv('CAR_RENTAL_DB_PASS');

$connectionAttempts = [];

// If explicit environment credentials are configured, use them first.
if ($envUser !== false && $envUser !== '') {
    $connectionAttempts[] = [
        'user' => $envUser,
        'pass' => ($envPass !== false ? $envPass : ''),
        'label' => 'environment credentials',
    ];
}

// Preserve the account used by the original car-rental system.
$connectionAttempts[] = [
    'user' => 'car_rental_admin',
    'pass' => 'car_rental_admin',
    'label' => 'car_rental_admin',
];

// Default local XAMPP fallback only. Never add this fallback for a remote DB host.
if (in_array(strtolower($servername), ['localhost', '127.0.0.1', '::1'], true)) {
    $connectionAttempts[] = [
        'user' => 'root',
        'pass' => '',
        'label' => 'local XAMPP root',
    ];
}

$conn = null;
$connectionErrors = [];

foreach ($connectionAttempts as $attempt) {
    try {
        $candidate = new mysqli($servername, $attempt['user'], $attempt['pass'], $database);
    } catch (mysqli_sql_exception $e) {
        $connectionErrors[] = $attempt['label'] . ': ' . $e->getMessage();
        continue;
    }

    if (!$candidate->connect_errno) {
        $conn = $candidate;
        break;
    }

    $connectionErrors[] = $attempt['label'] . ': ' . $candidate->connect_error;
}

if (!$conn instanceof mysqli) {
    error_log('Database connection failed. ' . implode(' | ', $connectionErrors));
    die('Database connection failed. Make sure MySQL is running and the car_rental database exists.');
}

$conn->set_charset('utf8mb4');
require_once __DIR__ . '/schema.php';
ensure_full_car_rental_schema($conn);
?>
