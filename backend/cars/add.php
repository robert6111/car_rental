<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Car</title>

    <script>
        // JS to fetch models dynamically when make changes
        function fetchModels(makeId) {
            fetch('get_model.php?make_id=' + encodeURIComponent(makeId))
                .then(response => response.json())
                .then(data => {
                    let modelSelect = document.getElementById('model_id');
                    modelSelect.innerHTML = '<option value="">Select Model</option>';
                    data.forEach(model => {
                        let option = document.createElement('option');
                        option.value = model.id;
                        option.text = model.name;
                        modelSelect.add(option);
                    });
                });
        }
    </script>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
    ?>
    <div class="content-page">
<h2>Add New Car</h2>
<?php if ($msg): ?><div style='color:green;'><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div style='color:red;'><?= h($error) ?></div><?php endif; ?>

<form method="post">
    <label>Registration No:</label><br>
<input type="text" name="reg_no"
       required maxlength="20"
       pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$"
       style="text-transform: uppercase;"
       value="<?= h($form['reg_no'] ?? '') ?>"
       title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot.">
<br><br>

    <label>Make:</label><br>
    <select name="make_id" id="make_id" onchange="fetchModels(this.value)" required>
        <option value="">Select Make</option>
        <?php while ($row = $makes_result->fetch_assoc()): ?>
            <option value="<?= (int)$row['id'] ?>" <?= $selMake === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
        <?php endwhile; ?>
    </select><br><br>

    <label>Model:</label><br>
    <select name="model_id" id="model_id" required>
        <option value="">Select Model</option>
        <?php foreach ($models_for_make as $row): ?>
            <option value="<?= (int)$row['id'] ?>" <?= $selModel === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['name']) ?></option>
        <?php endforeach; ?>
    </select><br><br>

    <label>Year:</label><br>
    <select name="year_id" required>
        <option value="">Select Year</option>
        <?php while ($row = $years_result->fetch_assoc()): ?>
            <option value="<?= (int)$row['id'] ?>" <?= $selYear === (int)$row['id'] ? 'selected' : '' ?>><?= h($row['year']) ?></option>
        <?php endwhile; ?>
    </select><br><br>

    <label>VIN / Chassis No:</label><br><input type="text" name="vin" maxlength="100" placeholder="Optional" value="<?= h($form['vin'] ?? '') ?>"><br><br>
    <label>Color:</label><br><input type="text" name="color" maxlength="50" placeholder="Example: White" value="<?= h($form['color'] ?? '') ?>"><br><br>
    <label>Current Mileage:</label><br><input type="number" name="current_mileage" min="0" step="1" value="<?= h($form['current_mileage'] ?? '') ?>"><br><br>
    <label>Insurance Expiry:</label><br><input type="date" name="insurance_expiry" value="<?= h($form['insurance_expiry'] ?? '') ?>"><br><br>
    <label>Inspection Expiry:</label><br><input type="date" name="inspection_expiry" value="<?= h($form['inspection_expiry'] ?? '') ?>"><br><br>
    <label>Branch / Location:</label><br><input type="text" name="branch" maxlength="120" placeholder="Main office / Airport" value="<?= h($form['branch'] ?? '') ?>"><br><br>
    <label>Price Per Day:</label><br>
<input type="number" step="0.01" min="0.01" name="price_per_day" value="<?= h($form['price_per_day'] ?? '') ?>" required><br><br>
    <label>Purchase Value / Capital Value:</label><br><input type="number" step="0.01" min="0" name="purchase_value" value="<?= h($form['purchase_value'] ?? '0') ?>"><br><br>


    <label>Status:</label><br>
    <select name="status" required>
        <option value="Available" <?= $selStatus === 'Available' ? 'selected' : '' ?>>Available</option>
        <option value="Maintenance" <?= $selStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
    </select>
    <small>Rented, Reserved and Not Returned are set automatically from rentals and reservations.</small><br><br>

    <button type="submit">Add Car</button>
</form>
        </div>
</body>
</html>
