<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';

// Fetch makes and years for dropdowns
$makes_result = $conn->query("SELECT id, name FROM makes ORDER BY name ASC");
$years_result = $conn->query("SELECT id, year FROM car_years ORDER BY year DESC");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reg_no = normalize_plate($_POST['reg_no']);
    $make_id = intval($_POST['make_id']);
    $model_id = intval($_POST['model_id']);
    $year_id = intval($_POST['year_id']);
    $price_per_day = floatval($_POST['price_per_day']);
    $purchase_value = floatval($_POST['purchase_value'] ?? 0);
    $status = trim($_POST['status']);
    $vin = trim($_POST['vin'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $current_mileage = ($_POST['current_mileage'] ?? '') !== '' ? intval($_POST['current_mileage']) : null;
    $insurance_expiry = ($_POST['insurance_expiry'] ?? '') ?: null;
    $inspection_expiry = ($_POST['inspection_expiry'] ?? '') ?: null;
    $branch = trim($_POST['branch'] ?? '');

    if ($reg_no && $make_id && $model_id && $year_id && $status) {
        $check_stmt = $conn->prepare("SELECT id FROM cars WHERE reg_no = ?");
        $check_stmt->bind_param("s", $reg_no);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "A car with this registration number already exists.";
        }
        else{

        $stmt = $conn->prepare("INSERT INTO cars (reg_no, vin, color, current_mileage, insurance_expiry, inspection_expiry, branch, make_id, model_id, year_id, availability_status, price_per_day, purchase_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssisssiiisdd", $reg_no, $vin, $color, $current_mileage, $insurance_expiry, $inspection_expiry, $branch, $make_id, $model_id, $year_id, $status, $price_per_day, $purchase_value);


        if ($stmt->execute()) {
            header("Location: all_cars.php?added=1");
            exit;
        } else {
            $error = "Error: " . $stmt->error;
        }

        $stmt->close();
    } 
}
else {
        $error = "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Car</title>
         <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">

    <script>
        // JS to fetch models dynamically when make changes
        function fetchModels(makeId) {
            fetch('get_model.php?make_id=' + makeId)
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
<?php if ($msg) echo "<div style='color:green;'>$msg</div>"; ?>
<?php if ($error) echo "<div style='color:red;'>$error</div>"; ?>

<form method="post">
    <label>Registration No:</label><br>
<input type="text" name="reg_no" 
       required
       pattern="^[A-Za-z0-9][A-Za-z0-9\s\-\.]{1,19}$"
       style="text-transform: uppercase;"
       title="Enter a valid registration number using letters, numbers, spaces, hyphen or dot.">
<br><br>

    <label>Make:</label><br>
    <select name="make_id" id="make_id" onchange="fetchModels(this.value)" required>
        <option value="">Select Make</option>
        <?php while ($row = $makes_result->fetch_assoc()): ?>
            <option value="<?= $row['id'] ?>"><?= htmlentities($row['name']) ?></option>
        <?php endwhile; ?>
    </select><br><br>

    <label>Model:</label><br>
    <select name="model_id" id="model_id" required>
        <option value="">Select Model</option>
    </select><br><br>

    <label>Year:</label><br>
    <select name="year_id" required>
        <option value="">Select Year</option>
        <?php while ($row = $years_result->fetch_assoc()): ?>
            <option value="<?= $row['id'] ?>"><?= $row['year'] ?></option>
        <?php endwhile; ?>
    </select><br><br>

    <label>VIN / Chassis No:</label><br><input type="text" name="vin" placeholder="Optional"><br><br>
    <label>Color:</label><br><input type="text" name="color" placeholder="Example: White"><br><br>
    <label>Current Mileage:</label><br><input type="number" name="current_mileage" min="0"><br><br>
    <label>Insurance Expiry:</label><br><input type="date" name="insurance_expiry"><br><br>
    <label>Inspection Expiry:</label><br><input type="date" name="inspection_expiry"><br><br>
    <label>Branch / Location:</label><br><input type="text" name="branch" placeholder="Main office / Airport"><br><br>
    <label>Price Per Day:</label><br>
<input type="number" step="0.01" name="price_per_day" value="0" required><br><br>
    <label>Purchase Value / Capital Value:</label><br><input type="number" step="0.01" name="purchase_value" value="0"><br><br>


    <label>Status:</label><br>
    <select name="status" required>
        <option value="Available">Available</option>
        <option value="Reserved">Reserved</option>
        <option value="Rented">Rented</option>
        <option value="Maintenance">Maintenance</option>
    </select><br><br>

    <button type="submit">Add Car</button>
</form>
        </div>
</body>
</html>
