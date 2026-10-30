<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php'; // cr_valid_date() used by car_validation.php
require_once __DIR__ . '/car_validation.php';

$msg = $error = '';
$form = $_POST;

// Fetch makes and years for dropdowns
$makes_result = $conn->query("SELECT id, name FROM makes ORDER BY name ASC");
$years_result = $conn->query("SELECT id, year FROM car_years ORDER BY year DESC");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$car, $error] = cr_validate_car_post($conn, $_POST, 0);
    $status = trim((string)($_POST['status'] ?? 'Available'));
    if (!$error && !in_array($status, CR_CAR_MANUAL_STATUSES, true)) {
        $error = 'Status must be Available or Maintenance. Rented and Reserved are set automatically by rentals and reservations.';
    }

    if (!$error) {
        $stmt = $conn->prepare("INSERT INTO cars (reg_no, vin, color, current_mileage, insurance_expiry, inspection_expiry, branch, make_id, model_id, year_id, availability_status, price_per_day, purchase_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssisssiiisdd", $car['reg_no'], $car['vin'], $car['color'], $car['current_mileage'], $car['insurance_expiry'], $car['inspection_expiry'], $car['branch'], $car['make_id'], $car['model_id'], $car['year_id'], $status, $car['price_per_day'], $car['purchase_value']);

        if ($stmt->execute()) {
            header("Location: all_cars.php?added=1");
            exit;
        }
        $error = cr_db_error($stmt->error);
        $stmt->close();
    }
}

$selMake = (int)($form['make_id'] ?? 0);
$selModel = (int)($form['model_id'] ?? 0);
$selYear = (int)($form['year_id'] ?? 0);
$selStatus = $form['status'] ?? 'Available';
$models_for_make = [];
if ($selMake > 0) {
    $ms = $conn->prepare("SELECT id, name FROM models WHERE make_id = ? ORDER BY name ASC");
    $ms->bind_param('i', $selMake);
    $ms->execute();
    $models_for_make = $ms->get_result()->fetch_all(MYSQLI_ASSOC);
    $ms->close();
}
?>
<?php cr_layout_start('Add car'); ?>
<a class="back-link" href="all_cars.php">All cars</a>
<?= cr_page_header('Add car', 'Add a car to the fleet so it can be rented out.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="post" class="card">
    <h2 class="section-title">Car details</h2>
    <div class="form-grid">
        <div class="field">
            <label for="reg_no">Registration number <span class="req">*</span></label>
            <input type="text" id="reg_no" name="reg_no"
                   required maxlength="20"
                   pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$"
                   style="text-transform: uppercase;"
                   value="<?= h($form['reg_no'] ?? '') ?>"
                   title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot.">
        </div>
        <div class="field">
            <label for="make_id">Make <span class="req">*</span></label>
            <select name="make_id" id="make_id" required>
                <option value="">Select make</option>
                <?php while ($row = $makes_result->fetch_assoc()): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= $selMake === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="model_id">Model <span class="req">*</span></label>
            <select name="model_id" id="model_id" required>
                <option value="">Select model</option>
                <?php foreach ($models_for_make as $row): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= $selModel === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Choose the make first.</span>
        </div>
        <div class="field">
            <label for="year_id">Year <span class="req">*</span></label>
            <select name="year_id" id="year_id" required>
                <option value="">Select year</option>
                <?php while ($row = $years_result->fetch_assoc()): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= $selYear === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['year']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="color">Colour <span class="opt">(optional)</span></label>
            <input type="text" id="color" name="color" maxlength="50" placeholder="Example: White" value="<?= h($form['color'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="vin">VIN / chassis number <span class="opt">(optional)</span></label>
            <input type="text" id="vin" name="vin" maxlength="100" value="<?= h($form['vin'] ?? '') ?>">
        </div>
    </div>

    <div class="form-section">
        <h3>Price and status</h3>
        <div class="form-grid">
            <div class="field">
                <label for="price_per_day">Price per day <span class="req">*</span></label>
                <input type="number" id="price_per_day" step="0.01" min="0.01" name="price_per_day" value="<?= h($form['price_per_day'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="purchase_value">Purchase value <span class="opt">(optional)</span></label>
                <input type="number" id="purchase_value" step="0.01" min="0" name="purchase_value" value="<?= h($form['purchase_value'] ?? '0') ?>">
                <span class="hint">What the car cost (capital value).</span>
            </div>
            <div class="field">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status" required>
                    <option value="Available" <?= $selStatus === 'Available' ? 'selected' : '' ?>>Available</option>
                    <option value="Maintenance" <?= $selStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                </select>
                <span class="hint">Rented, Reserved and Not returned are set automatically from rentals and reservations.</span>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h3>Mileage, papers and location</h3>
        <div class="form-grid">
            <div class="field">
                <label for="current_mileage">Current mileage <span class="opt">(optional)</span></label>
                <input type="number" id="current_mileage" name="current_mileage" min="0" step="1" value="<?= h($form['current_mileage'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="insurance_expiry">Insurance expiry</label>
                <input type="date" id="insurance_expiry" name="insurance_expiry" value="<?= h($form['insurance_expiry'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="inspection_expiry">Inspection expiry</label>
                <input type="date" id="inspection_expiry" name="inspection_expiry" value="<?= h($form['inspection_expiry'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="branch">Branch / location <span class="opt">(optional)</span></label>
                <input type="text" id="branch" name="branch" maxlength="120" placeholder="Main office / Airport" value="<?= h($form['branch'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit">Add car</button>
        <a class="btn btn-secondary" href="all_cars.php">Cancel</a>
    </div>
</form>
<script>
    // Load the models of the chosen make.
    function fetchModels(makeId) {
        fetch('get_model.php?make_id=' + encodeURIComponent(makeId))
            .then(response => response.json())
            .then(data => {
                let modelSelect = document.getElementById('model_id');
                modelSelect.innerHTML = '<option value="">Select model</option>';
                data.forEach(model => {
                    let option = document.createElement('option');
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
