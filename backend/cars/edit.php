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
<?php cr_layout_start('Edit car'); ?>
<a class="back-link" href="all_cars.php">All cars</a>
<?= cr_page_header('Edit car', 'Update the details, price and papers of this car.'); ?>
<?= cr_alerts('', $error); ?>

<form method="POST" class="card">
    <div class="card-head">
        <h2>Car details</h2>
        <?= cr_status_badge($currentStatus); ?>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="reg_no">Registration number <span class="req">*</span></label>
            <input type="text" id="reg_no" name="reg_no" value="<?= h($car['reg_no']) ?>" required maxlength="20" pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$" style="text-transform: uppercase;" title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot.">
        </div>
        <div class="field">
            <label for="make_id">Make <span class="req">*</span></label>
            <select name="make_id" id="make_id" required>
                <option value="">Select make</option>
                <?php while ($row = $makes->fetch_assoc()): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (int)$car['make_id'] === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="model_id">Model <span class="req">*</span></label>
            <select name="model_id" id="model_id" required>
                <option value="">Select model</option>
                <?php while ($row = $models->fetch_assoc()): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (int)$car['model_id'] === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="year_id">Year <span class="req">*</span></label>
            <select name="year_id" id="year_id" required>
                <option value="">Select year</option>
                <?php while ($row = $years->fetch_assoc()): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (int)$car['year_id'] === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['year']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="color">Colour <span class="opt">(optional)</span></label>
            <input type="text" id="color" name="color" maxlength="50" value="<?= h($car['color'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="vin">VIN / chassis number <span class="opt">(optional)</span></label>
            <input type="text" id="vin" name="vin" maxlength="100" value="<?= h($car['vin'] ?? '') ?>">
        </div>
    </div>

    <div class="form-section">
        <h3>Price and status</h3>
        <div class="form-grid">
            <div class="field">
                <label for="price_per_day">Price per day <span class="req">*</span></label>
                <input type="number" id="price_per_day" step="0.01" min="0.01" name="price_per_day" value="<?= h($car['price_per_day'] ?? 0) ?>" required>
            </div>
            <div class="field">
                <label for="purchase_value">Purchase value <span class="opt">(optional)</span></label>
                <input type="number" id="purchase_value" step="0.01" min="0" name="purchase_value" value="<?= h($car['purchase_value'] ?? 0) ?>">
                <span class="hint">What the car cost (capital value).</span>
            </div>
            <div class="field">
                <label for="availability_status">Status</label>
                <?php if ($statusLocked): ?>
                    <input type="text" id="availability_status" value="<?= h($currentStatus) ?>" readonly disabled>
                    <span class="hint">This status is set automatically from the car's rentals and reservations and cannot be changed here.</span>
                <?php else: ?>
                    <select id="availability_status" name="availability_status" required>
                        <option value="Available" <?= $car['availability_status'] === 'Available' ? 'selected' : '' ?>>Available</option>
                        <option value="Maintenance" <?= $car['availability_status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    </select>
                    <span class="hint">Rented, Reserved and Not returned are set automatically from rentals and reservations.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h3>Mileage, papers and location</h3>
        <div class="form-grid">
            <div class="field">
                <label for="current_mileage">Current mileage <span class="opt">(optional)</span></label>
                <input type="number" id="current_mileage" name="current_mileage" min="0" step="1" value="<?= h($car['current_mileage'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="insurance_expiry">Insurance expiry</label>
                <input type="date" id="insurance_expiry" name="insurance_expiry" value="<?= h($car['insurance_expiry'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="inspection_expiry">Inspection expiry</label>
                <input type="date" id="inspection_expiry" name="inspection_expiry" value="<?= h($car['inspection_expiry'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="branch">Branch / location <span class="opt">(optional)</span></label>
                <input type="text" id="branch" name="branch" maxlength="120" value="<?= h($car['branch'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit">Save changes</button>
        <a class="btn btn-secondary" href="all_cars.php">Cancel</a>
    </div>
</form>
<script>
    // Load the models of the chosen make.
    function fetchModels(makeId) {
        fetch('get_model.php?make_id=' + encodeURIComponent(makeId))
            .then(response => response.json())
            .then(data => {
                const modelSelect = document.getElementById('model_id');
                modelSelect.innerHTML = '<option value="">Select model</option>';
                data.forEach(model => {
                    const option = document.createElement('option');
                    option.value = model.id;
                    option.text = model.name;
                    modelSelect.add(option);
                });
            });
    }
    document.getElementById('make_id').addEventListener('change', function () {
        fetchModels(this.value);
    });
</script>
<?php cr_layout_end(); ?>
