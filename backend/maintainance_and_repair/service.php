<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $error = '';
    $car_id = (int)($_POST['car_id'] ?? 0);
    $service_type = trim((string)($_POST['service_type'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $service_date = trim((string)($_POST['service_date'] ?? ''));
    $mileageInput = trim((string)($_POST['mileage_at_service'] ?? ''));
    $nextMileageInput = trim((string)($_POST['next_service_mileage'] ?? ''));
    $costInput = trim((string)($_POST['cost'] ?? ''));
    $mileage_at_service = null;
    $next_service_mileage = null;
    $cost = null;

    if ($car_id <= 0) {
        $error = 'Please select a car.';
    } elseif ($service_type === '' || mb_strlen($service_type) > 100) {
        $error = 'Service type is required (max 100 characters).';
    } elseif (!cr_valid_date($service_date)) {
        $error = 'Please enter a valid service date.';
    } elseif ($service_date > cr_today()) {
        $error = 'The service date cannot be in the future.';
    } elseif ($mileageInput !== '' && !preg_match('/^\d{1,9}$/', $mileageInput)) {
        $error = 'Mileage at service must be a whole number of 0 or more.';
    } elseif ($nextMileageInput !== '' && !preg_match('/^\d{1,9}$/', $nextMileageInput)) {
        $error = 'Next service mileage must be a whole number of 0 or more.';
    } elseif ($costInput !== '' && (($cost = cr_parse_money($costInput)) === null || $cost < 0)) {
        $error = 'Service cost must be a valid amount of 0 or more (for example 1500 or 1.500,50).';
    }

    if (!$error) {
        if ($mileageInput !== '') $mileage_at_service = (int)$mileageInput;
        if ($nextMileageInput !== '') $next_service_mileage = (int)$nextMileageInput;
        if ($mileage_at_service !== null && $next_service_mileage !== null && $next_service_mileage < $mileage_at_service) {
            $error = 'Next service mileage cannot be lower than the mileage at service.';
        }
    }

    if (!$error) {
        $chk = $conn->prepare("SELECT id FROM cars WHERE id = ?");
        $chk->bind_param("i", $car_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) $error = 'The selected car does not exist.';
        $chk->close();
    }

    if (!$error) {
        $sql = "INSERT INTO car_service
        (car_id, service_type, description, service_date, mileage_at_service, next_service_mileage, cost)
        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssiid", $car_id, $service_type, $description, $service_date, $mileage_at_service, $next_service_mileage, $cost);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Service record added successfully!";
        } else {
            $_SESSION['error'] = cr_db_error($stmt->error, 'The service record could not be saved.');
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = $error;
    }

    // Redirect to the same page to prevent form resubmission on refresh
    header("Location: service.php");
    exit;
}


// Fetch all cars for dropdown
$allCars = $conn->query("
    SELECT
        cars.id AS car_id,
        cars.reg_no,
        models.name AS model_name,
        makes.name AS make_name
    FROM cars
    JOIN models ON cars.model_id = models.id
    JOIN makes ON cars.make_id = makes.id
    ORDER BY makes.name, models.name
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Services</title>
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
    <?php
if (isset($_SESSION['success'])) {
    echo "<div style='text-align:center; padding:10px; color:green; font-weight:bold;'>"
         . h($_SESSION['success']) . "</div>";
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo "<div style='text-align:center; padding:10px; color:red; font-weight:bold;'>"
         . h($_SESSION['error']) . "</div>";
    unset($_SESSION['error']);
}
?>

   <h2>Add Vehicle Service / Maintenance Record</h2>

<form action="service.php" method="POST">

    <label>Select Car:</label><br>
    <select name="car_id" required>
        <option value="">-- Select Car --</option>

        <?php while ($car = $allCars->fetch_assoc()) : ?>
            <option value="<?= (int)$car['car_id'] ?>">
                <?= h($car['make_name'] . ' ' . $car['model_name'] . ' (' . $car['reg_no'] . ')') ?>
            </option>
        <?php endwhile; ?>

    </select>
    <br><br>

    <label>Service Type:</label><br>
    <input type="text" name="service_type" maxlength="100" placeholder="Oil change, Tire replacement, etc" required><br><br>

    <label>Description:</label><br>
    <textarea name="description" rows="4" cols="40"></textarea><br><br>

    <label>Service Date:</label><br>
    <input type="date" name="service_date" max="<?= h(cr_today()) ?>" required><br><br>


    <label>Mileage at Service:</label><br>
    <input type="number" id="mileage_at_service" name="mileage_at_service" min="0" step="1"><br><br>

    <label>Next Service Mileage:</label><br>
    <input type="number" id="next_service_mileage" name="next_service_mileage" min="0" step="1"><br><br>

   <label>Service Cost:</label><br>
<input type="text" id="cost" name="cost" inputmode="decimal" placeholder="e.g. 1500 or 1.500,50"><br>
<small>The amount is checked on the server; both 1,500.50 and 1.500,50 are accepted.</small>

 <br><br>

    <input type="submit" value="Save Service Record">

</form>

</div>
</body>
</html>
