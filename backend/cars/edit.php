<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die("Invalid car ID");
}

// Fetch car
$car_stmt = $conn->prepare("SELECT * FROM cars WHERE id = ?");
$car_stmt->bind_param("i", $id);
$car_stmt->execute();
$car = $car_stmt->get_result()->fetch_assoc();
$car_stmt->close();

if (!$car) {
    die("Car not found");
}

// Fetch makes and years
$makes = $conn->query("SELECT * FROM makes ORDER BY name ASC");
$years = $conn->query("SELECT * FROM car_years ORDER BY year DESC");

// Fetch models for the selected car's make
$model_stmt = $conn->prepare("SELECT * FROM models WHERE make_id = ?");
$model_stmt->bind_param("i", $car['make_id']);
$model_stmt->execute();
$models = $model_stmt->get_result();
$model_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reg_no = normalize_plate($_POST['reg_no']);
    $availability = $_POST['availability_status'];
    $make_id = intval($_POST['make_id']);
    $model_id = intval($_POST['model_id']);
    $year_id = intval($_POST['year_id']);
    $price_per_day = floatval($_POST['price_per_day'] ?? 0);
    $purchase_value = floatval($_POST['purchase_value'] ?? 0);
    $vin = trim($_POST['vin'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $current_mileage = ($_POST['current_mileage'] ?? '') !== '' ? intval($_POST['current_mileage']) : null;
    $insurance_expiry = ($_POST['insurance_expiry'] ?? '') ?: null;
    $inspection_expiry = ($_POST['inspection_expiry'] ?? '') ?: null;
    $branch = trim($_POST['branch'] ?? '');

    $stmt = $conn->prepare("UPDATE cars SET reg_no = ?, vin=?, color=?, current_mileage=?, insurance_expiry=?, inspection_expiry=?, branch=?, availability_status = ?, make_id = ?, model_id = ?, year_id = ?, price_per_day=?, purchase_value=? WHERE id = ?");
    $stmt->bind_param("sssissssiiiddi", $reg_no, $vin, $color, $current_mileage, $insurance_expiry, $inspection_expiry, $branch, $availability, $make_id, $model_id, $year_id, $price_per_day, $purchase_value, $id);

    if ($stmt->execute()) {
        header("Location: all_cars.php?updated=1");
        exit;
    } else {
        echo cr_db_error($stmt->error);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Car</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <script>
        function fetchModels(makeId) {
            fetch('get_model.php?make_id=' + makeId)
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
    <form method="POST">
        <label>Registration No:</label><br>
        <input type="text" name="reg_no" value="<?= htmlentities($car['reg_no']) ?>" required pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$" style="text-transform: uppercase;" title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot."><br><br>

        <label>VIN / Chassis No:</label><br>
        <input type="text" name="vin" value="<?= htmlentities($car['vin'] ?? '') ?>"><br><br>
        <label>Color:</label><br>
        <input type="text" name="color" value="<?= htmlentities($car['color'] ?? '') ?>"><br><br>
        <label>Current Mileage:</label><br>
        <input type="number" name="current_mileage" value="<?= htmlentities($car['current_mileage'] ?? '') ?>"><br><br>
        <label>Insurance Expiry:</label><br>
        <input type="date" name="insurance_expiry" value="<?= htmlentities($car['insurance_expiry'] ?? '') ?>"><br><br>
        <label>Inspection Expiry:</label><br>
        <input type="date" name="inspection_expiry" value="<?= htmlentities($car['inspection_expiry'] ?? '') ?>"><br><br>
        <label>Branch / Location:</label><br>
        <input type="text" name="branch" value="<?= htmlentities($car['branch'] ?? '') ?>"><br><br>
        <label>Availability Status:</label><br>
        <select name="availability_status" required>
            <option value="Available" <?= $car['availability_status'] == 'Available' ? 'selected' : '' ?>>Available</option>
            <option value="Reserved" <?= $car['availability_status'] == 'Reserved' ? 'selected' : '' ?>>Reserved</option>
            <option value="Rented" <?= $car['availability_status'] == 'Rented' ? 'selected' : '' ?>>Rented</option>
            <option value="Maintenance" <?= $car['availability_status'] == 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
        </select><br><br>

        <label>Make:</label><br>
        <select name="make_id" id="make_id" required>
            <option value="">Select Make</option>
            <?php while ($row = $makes->fetch_assoc()): ?>
                <option value="<?= $row['id'] ?>" <?= $car['make_id'] == $row['id'] ? 'selected' : '' ?>>
                    <?= htmlentities($row['name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Model:</label><br>
        <select name="model_id" id="model_id" required>
            <option value="">Select Model</option>
            <?php while ($row = $models->fetch_assoc()): ?>
                <option value="<?= $row['id'] ?>" <?= $car['model_id'] == $row['id'] ? 'selected' : '' ?>>
                    <?= htmlentities($row['name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Year:</label><br>
        <select name="year_id" required>
            <option value="">Select Year</option>
            <?php while ($row = $years->fetch_assoc()): ?>
                <option value="<?= $row['id'] ?>" <?= $car['year_id'] == $row['id'] ? 'selected' : '' ?>>
                    <?= $row['year'] ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Price Per Day:</label><br>
        <input type="number" step="0.01" name="price_per_day" value="<?= htmlentities($car['price_per_day'] ?? 0) ?>" required><br><br>
        <label>Purchase Value / Capital Value:</label><br>
        <input type="number" step="0.01" name="purchase_value" value="<?= htmlentities($car['purchase_value'] ?? 0) ?>"><br><br>

        <button type="submit">Update Car</button>
    </form>
    <a href="all_cars.php">Back to Car List</a>
</div>
</body>
</html>
