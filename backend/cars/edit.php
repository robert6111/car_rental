<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/car_validation.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: all_cars.php');
    exit;
}

// Fetch car
$car_stmt = $conn->prepare("SELECT * FROM cars WHERE id = ?");
$car_stmt->bind_param("i", $id);
$car_stmt->execute();
$car = $car_stmt->get_result()->fetch_assoc();
$car_stmt->close();

if (!$car) {
    header('Location: all_cars.php');
    exit;
}

$error = '';
$currentStatus = (string)$car['availability_status'];
$statusLocked = in_array($currentStatus, CR_CAR_COMPUTED_STATUSES, true);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$data, $error] = cr_validate_car_post($conn, $_POST, $id);

    if ($statusLocked) {
        // Status comes from rentals / reservations; staff cannot change it here.
        $availability = $currentStatus;
    } else {
        $availability = trim((string)($_POST['availability_status'] ?? $currentStatus));
        if (!$error && !in_array($availability, CR_CAR_MANUAL_STATUSES, true)) {
            $error = 'Status must be Available or Maintenance.';
        }
    }

    if (!$error) {
        $stmt = $conn->prepare("UPDATE cars SET reg_no = ?, vin=?, color=?, current_mileage=?, insurance_expiry=?, inspection_expiry=?, branch=?, availability_status = ?, make_id = ?, model_id = ?, year_id = ?, price_per_day=?, purchase_value=? WHERE id = ?");
        $stmt->bind_param("sssissssiiiddi", $data['reg_no'], $data['vin'], $data['color'], $data['current_mileage'], $data['insurance_expiry'], $data['inspection_expiry'], $data['branch'], $availability, $data['make_id'], $data['model_id'], $data['year_id'], $data['price_per_day'], $data['purchase_value'], $id);

        if ($stmt->execute()) {
            $stmt->close();
            if ($availability !== 'Maintenance') {
                // Recalculate Rented / Reserved / Not Returned from the car's bookings.
                cr_sync_car_statuses($conn, $id);
            }
            header("Location: all_cars.php?updated=1");
            exit;
        }
        $error = cr_db_error($stmt->error);
        $stmt->close();
    }

    // Re-show what the user typed.
    foreach (['reg_no', 'vin', 'color', 'current_mileage', 'insurance_expiry', 'inspection_expiry', 'branch', 'price_per_day', 'purchase_value'] as $f) {
        $car[$f] = (string)($_POST[$f] ?? '');
    }
    foreach (['make_id', 'model_id', 'year_id'] as $f) {
        $car[$f] = (int)($_POST[$f] ?? 0);
    }
    if (!$statusLocked && in_array($_POST['availability_status'] ?? '', CR_CAR_MANUAL_STATUSES, true)) {
        $car['availability_status'] = $_POST['availability_status'];
    }
}

// Fetch makes and years
$makes = $conn->query("SELECT * FROM makes ORDER BY name ASC");
$years = $conn->query("SELECT * FROM car_years ORDER BY year DESC");

// Fetch models for the selected car's make
$makeForModels = (int)$car['make_id'];
$model_stmt = $conn->prepare("SELECT * FROM models WHERE make_id = ? ORDER BY name ASC");
$model_stmt->bind_param("i", $makeForModels);
$model_stmt->execute();
$models = $model_stmt->get_result();
$model_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Car</title>
    <script>
        function fetchModels(makeId) {
            fetch('get_model.php?make_id=' + encodeURIComponent(makeId))
                .then(response => response.json())
                .then(data => {
                    const modelSelect = document.getElementById('model_id');
                    modelSelect.innerHTML = '<option value="">Select Model</option>';
                    data.forEach(model => {
                        const option = document.createElement('option');
                        option.value = model.id;
                        option.text = model.name;
                        modelSelect.add(option);
                    });
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const makeSelect = document.getElementById('make_id');
            makeSelect.addEventListener('change', function() {
                fetchModels(this.value);
            });
        });
    </script>
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
    <h2>Edit Car</h2>
    <?php if ($error): ?><div style='color:red;'><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
        <label>Registration No:</label><br>
        <input type="text" name="reg_no" value="<?= h($car['reg_no']) ?>" required maxlength="20" pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$" style="text-transform: uppercase;" title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot."><br><br>

        <label>VIN / Chassis No:</label><br>
        <input type="text" name="vin" maxlength="100" value="<?= h($car['vin'] ?? '') ?>"><br><br>
        <label>Color:</label><br>
        <input type="text" name="color" maxlength="50" value="<?= h($car['color'] ?? '') ?>"><br><br>
        <label>Current Mileage:</label><br>
        <input type="number" name="current_mileage" min="0" step="1" value="<?= h($car['current_mileage'] ?? '') ?>"><br><br>
        <label>Insurance Expiry:</label><br>
        <input type="date" name="insurance_expiry" value="<?= h($car['insurance_expiry'] ?? '') ?>"><br><br>
        <label>Inspection Expiry:</label><br>
        <input type="date" name="inspection_expiry" value="<?= h($car['inspection_expiry'] ?? '') ?>"><br><br>
        <label>Branch / Location:</label><br>
        <input type="text" name="branch" maxlength="120" value="<?= h($car['branch'] ?? '') ?>"><br><br>
        <label>Availability Status:</label><br>
        <?php if ($statusLocked): ?>
            <input type="text" value="<?= h($currentStatus) ?>" readonly disabled>
            <br><small>This status is set automatically from the car's rentals / reservations and cannot be changed here.</small><br><br>
        <?php else: ?>
            <select name="availability_status" required>
                <option value="Available" <?= $car['availability_status'] === 'Available' ? 'selected' : '' ?>>Available</option>
                <option value="Maintenance" <?= $car['availability_status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
            </select>
            <br><small>Rented, Reserved and Not Returned are set automatically from rentals and reservations.</small><br><br>
        <?php endif; ?>

        <label>Make:</label><br>
        <select name="make_id" id="make_id" required>
            <option value="">Select Make</option>
            <?php while ($row = $makes->fetch_assoc()): ?>
                <option value="<?= (int)$row['id'] ?>" <?= (int)$car['make_id'] === (int)$row['id'] ? 'selected' : '' ?>>
                    <?= h($row['name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Model:</label><br>
        <select name="model_id" id="model_id" required>
            <option value="">Select Model</option>
            <?php while ($row = $models->fetch_assoc()): ?>
                <option value="<?= (int)$row['id'] ?>" <?= (int)$car['model_id'] === (int)$row['id'] ? 'selected' : '' ?>>
                    <?= h($row['name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Year:</label><br>
        <select name="year_id" required>
            <option value="">Select Year</option>
            <?php while ($row = $years->fetch_assoc()): ?>
                <option value="<?= (int)$row['id'] ?>" <?= (int)$car['year_id'] === (int)$row['id'] ? 'selected' : '' ?>>
                    <?= h($row['year']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Price Per Day:</label><br>
        <input type="number" step="0.01" min="0.01" name="price_per_day" value="<?= h($car['price_per_day'] ?? 0) ?>" required><br><br>
        <label>Purchase Value / Capital Value:</label><br>
        <input type="number" step="0.01" min="0" name="purchase_value" value="<?= h($car['purchase_value'] ?? 0) ?>"><br><br>

        <button type="submit">Update Car</button>
    </form>
    <a href="all_cars.php">Back to Car List</a>
</div>
</body>
</html>
