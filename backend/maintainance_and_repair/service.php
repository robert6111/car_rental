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
<?php cr_layout_start('Add service record'); ?>
<a class="back-link" href="service_list.php">All service records</a>
<?= cr_page_header('Add service record', 'Record a service or maintenance job done on a car.'); ?>
<?php
if (isset($_SESSION['success'])) {
    echo cr_alert('success', (string)$_SESSION['success']);
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo cr_alert('error', (string)$_SESSION['error']);
    unset($_SESSION['error']);
}
?>

<form action="service.php" method="POST" class="card">
    <div class="form-grid">
        <div class="field">
            <label for="car_id">Car <span class="req">*</span></label>
            <select id="car_id" name="car_id" required>
                <option value="">Select car</option>
                <?php while ($car = $allCars->fetch_assoc()) : ?>
                <option value="<?= (int)$car['car_id'] ?>"><?= h($car['make_name'] . ' ' . $car['model_name'] . ' (' . $car['reg_no'] . ')') ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="service_type">Service type <span class="req">*</span></label>
            <input type="text" id="service_type" name="service_type" maxlength="100" placeholder="Oil change, tyre replacement, etc." required>
        </div>
        <div class="field">
            <label for="service_date">Service date <span class="req">*</span></label>
            <input type="date" id="service_date" name="service_date" max="<?= h(cr_today()) ?>" required>
        </div>
        <div class="field span-all">
            <label for="description">Description <span class="opt">(optional)</span></label>
            <textarea id="description" name="description" rows="3"></textarea>
        </div>
        <div class="field">
            <label for="mileage_at_service">Mileage at service <span class="opt">(optional)</span></label>
            <input type="number" id="mileage_at_service" name="mileage_at_service" min="0" step="1">
        </div>
        <div class="field">
            <label for="next_service_mileage">Next service mileage <span class="opt">(optional)</span></label>
            <input type="number" id="next_service_mileage" name="next_service_mileage" min="0" step="1">
        </div>
        <div class="field">
            <label for="cost">Service cost <span class="opt">(optional)</span></label>
            <input type="text" id="cost" name="cost" inputmode="decimal" placeholder="e.g. 1500 or 1.500,50">
            <span class="hint">Both 1,500.50 and 1.500,50 are accepted.</span>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Save service record</button>
        <a class="btn btn-secondary" href="service_list.php">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
