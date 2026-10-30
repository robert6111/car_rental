<?php
require_once __DIR__ . '/../includes/auth.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die("Invalid rental ID.");
}

// Fetch rental
$rental_stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ?");
$rental_stmt->bind_param("i", $id);
$rental_stmt->execute();
$rental = $rental_stmt->get_result()->fetch_assoc();
$rental_stmt->close();

if (!$rental) {
    die("Rental not found.");
}

// Fetch customers and cars
$customers = $conn->query("SELECT * FROM customers ORDER BY full_name ASC");
$cars = $conn->query("SELECT c.id, c.reg_no, c.price_per_day, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM cars c LEFT JOIN makes m ON c.make_id = m.id LEFT JOIN models mo ON c.model_id = mo.id LEFT JOIN car_years y ON c.year_id = y.id WHERE c.availability_status IN ('Available','Rented') ORDER BY m.name ASC, mo.name ASC, y.year DESC, c.reg_no ASC");

$msg = $error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $cars_id = intval($_POST['cars_id']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $pricing = calculate_rental_total($conn, $customer_id, $cars_id, $start_date, $end_date, $id);
    $total_cost = (float)$pricing['total'];
    $status = $_POST['status'];

    // Respect the manually selected status. Automatic date completion is handled globally,
    // with same-date rentals requiring manual completion.
    $allowed_statuses = ['Pending', 'Active', 'Completed', 'Canceled', 'Deleted'];
    $new_status = in_array($status, $allowed_statuses, true) ? $status : 'Active';

    // Update rental with the correct status
    $stmt = $conn->prepare("UPDATE rentals SET customer_id=?, cars_id=?, start_date=?, end_date=?, total_cost=?, status=? WHERE id=?");
    $stmt->bind_param("iissdsi", $customer_id, $cars_id, $start_date, $end_date, $total_cost, $new_status, $id);

    if ($stmt->execute()) {
        $msg = "Rental updated successfully.";
        $conn->query("UPDATE cars c LEFT JOIN rentals r ON r.cars_id = c.id AND r.status = 'Active' SET c.availability_status = 'Available' WHERE r.id IS NULL AND c.availability_status = 'Rented'");
        $updateCar = $conn->prepare("UPDATE cars SET availability_status = 'Rented' WHERE id = ? AND ? = 'Active'");
        $updateCar->bind_param('is', $cars_id, $new_status);
        $updateCar->execute();
        $updateCar->close();

        sync_rental_and_car_status($conn);
        header("Location: manage.php?updated=1");
        exit;
    } else {
        $error = cr_db_error($stmt->error);
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Rental</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>

<div class="content-page">
    <h2>Edit Rental</h2>

    <?php if($msg) echo "<p style='color:green;'>$msg</p>"; ?>
    <?php if($error) echo "<p style='color:red;'>$error</p>"; ?>

    <form method="POST">
        <label>Customer:</label><br>
        <select name="customer_id" required>
            <option value="">Select Customer</option>
            <?php while($c = $customers->fetch_assoc()): ?>
                <option value="<?= $c['id'] ?>" <?= $rental['customer_id'] == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlentities($c['full_name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Car:</label><br>
        <select name="cars_id" id="cars_id" onchange="updateCost()" required>
            <option value="">Select Car</option>
            <?php while($car = $cars->fetch_assoc()): ?>
                <option value="<?= $car['id'] ?>" data-price="<?= $car['price_per_day'] ?>"
                    <?= $rental['cars_id'] == $car['id'] ? 'selected' : '' ?>>
                    <?= htmlentities(trim(($car['make_name'] ?? '') . ' ' . ($car['model_name'] ?? '') . ' ' . ($car['car_year'] ?? '') . ' - ' . $car['reg_no'])) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Start Date:</label><br>      
        <input type="date" id="start_date" name="start_date" value="<?= htmlentities($rental['start_date']) ?>" onchange="updateCost()" required><br><br>

        <label>End Date:</label><br>
        <input type="date" id="end_date" name="end_date" value="<?= htmlentities($rental['end_date']) ?>" onchange="updateCost()" required><br><br>

        <label>Cost:</label><br>
        <input type="number" id="total_cost" name="total_cost" value="<?= htmlentities($rental['total_cost']) ?>" readonly required><br><br>

        <script>
        function updateCost() {
            let carSelect = document.getElementById('cars_id');
            let startDate = document.getElementById('start_date').value;
            let endDate = document.getElementById('end_date').value;
            let costInput = document.getElementById('total_cost');

            if (!carSelect.value || !startDate || !endDate) {
                costInput.value = '';
                return;
            }

            let pricePerDay = parseFloat(carSelect.options[carSelect.selectedIndex].dataset.price);
            if (isNaN(pricePerDay)) {
                costInput.value = '';
                return;
            }

            let start = new Date(startDate);
            let end = new Date(endDate);

            if (end < start) {
                alert("End date cannot be before start date");
                costInput.value = '';
                return;
            }

            let timeDiff = end.getTime() - start.getTime();
            let days = Math.ceil(timeDiff / (1000 * 3600 * 24)); // remove +1
            if (days === 0) days = 1; // minimum 1 day

            let totalCost = pricePerDay * days;
            costInput.value = totalCost.toFixed(2);
        }

        // Optionally calculate cost on page load
        window.onload = updateCost;
        </script>

        <label>Status:</label><br>
        <select name="status" required>
            <option value="Pending"   <?= $rental['status']=='Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Active"    <?= $rental['status']=='Active' ? 'selected' : '' ?>>Active</option>
            <option value="Completed" <?= $rental['status']=='Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Canceled"  <?= $rental['status']=='Canceled' ? 'selected' : '' ?>>Canceled</option>
        </select><br><br>

        <button type="submit">Update Rental</button>
    </form>

    <a href="manage.php">Back to Rentals</a>
</div>
</body>
</html>
