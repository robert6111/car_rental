<?php
require_once __DIR__ . '/../includes/auth.php';
error_reporting(0); ini_set('display_errors', 0);
require __DIR__ . '/../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $car_id = (int)($_POST['car_id'] ?? 0);
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    if ($car_id <= 0 || $rental_id <= 0) {
        $_SESSION['error'] = "Please select the completed rental connected to this damage.";
        header("Location: damage.php"); exit;
    }
    $sql = "INSERT INTO car_damages (car_id, rental_id, damage_description, severity, estimated_cost, date_reported) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iissds", $car_id, $rental_id, $_POST['damage_description'], $_POST['severity'], $estimated_cost, $_POST['date_reported']);
    if ($stmt->execute()) $_SESSION['success'] = "Damage report saved and linked to the customer rental."; else { error_log('Damage insert failed: ' . $stmt->error); $_SESSION['error'] = "The damage report could not be saved."; }
    $stmt->close(); header("Location: damage.php"); exit;
}

$completedCars = $conn->query("SELECT rentals.id AS rental_id, cars.id AS car_id, cars.reg_no, models.name AS model_name, makes.name AS make_name,
        customers.full_name AS customer_name, rentals.start_date, rentals.end_date
    FROM rentals
    JOIN cars ON rentals.cars_id = cars.id
    JOIN models ON cars.model_id = models.id
    JOIN makes ON cars.make_id = makes.id
    JOIN customers ON rentals.customer_id = customers.id
    WHERE rentals.status = 'Completed'
    ORDER BY rentals.end_date DESC, makes.name, models.name, cars.reg_no");
?>
<!DOCTYPE html><html><head><title>Report Car Damage</title><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
<?php if(isset($_SESSION['success'])){ echo "<div style='text-align:center; padding:10px; color:green; font-weight:bold;'>" . h($_SESSION['success']) . "</div>"; unset($_SESSION['success']); } if(isset($_SESSION['error'])){ echo "<div style='text-align:center; padding:10px; color:red; font-weight:bold;'>" . h($_SESSION['error']) . "</div>"; unset($_SESSION['error']); } ?>
<h2>Report Car Damage</h2><br><div class="mb-3"><a href="../maintainance_and_repair/list_damage.php" class="btn btn-primary">List car damages</a></div>
<form method="POST"><label>Select Completed Rental / Car:</label><br><select name="rental_picker" id="rentalPicker" required><option value="">-- Select completed rental --</option><?php while ($row = $completedCars->fetch_assoc()) : ?><option value="<?= h($row['rental_id']); ?>" data-car="<?= h($row['car_id']); ?>"><?= h($row['make_name'] . ' ' . $row['model_name'] . ' (' . $row['reg_no'] . ') - Renter: ' . $row['customer_name'] . ' - ' . $row['start_date'] . ' to ' . $row['end_date']) ?></option><?php endwhile; ?></select><br><br><input type="hidden" name="car_id" id="car_id"><input type="hidden" name="rental_id" id="rental_id"><label>Damage Description:</label><br><textarea name="damage_description" required></textarea><br><br><label>Severity:</label><br><select name="severity" required><option value="low">Low</option><option value="medium">Medium</option><option value="moderate">Moderate</option><option value="high">High</option></select><br><br><label>Estimated Cost:</label><br><input type="number" name="estimated_cost" step="0.01" min="0" required><br><br><label>Date Reported:</label><br><input type="date" name="date_reported" value="<?= h(date('Y-m-d')) ?>" required><br><br><button type="submit">Submit Damage Report</button></form>
<script>document.getElementById('rentalPicker').addEventListener('change',function(){const opt=this.options[this.selectedIndex];document.getElementById('rental_id').value=this.value||'';document.getElementById('car_id').value=opt?(opt.dataset.car||''):'';});</script>
</div></body></html>
