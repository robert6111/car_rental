<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';

$damageSeverities = ['low', 'medium', 'moderate', 'high'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $car_id = (int)($_POST['car_id'] ?? 0);
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    if ($rental_id <= 0) $rental_id = (int)($_POST['rental_picker'] ?? 0); // works without JavaScript too
    $description = trim((string)($_POST['damage_description'] ?? ''));
    $severity = (string)($_POST['severity'] ?? '');
    $date_reported = trim((string)($_POST['date_reported'] ?? ''));
    $estimated_cost = cr_parse_money($_POST['estimated_cost'] ?? '');
    $error = '';

    if ($rental_id <= 0) {
        $error = "Please select the completed rental connected to this damage.";
    } elseif ($description === '') {
        $error = "Please describe the damage.";
    } elseif (!in_array($severity, $damageSeverities, true)) {
        $error = "Please choose a valid severity.";
    } elseif (!cr_valid_date($date_reported)) {
        $error = "Please enter a valid report date.";
    } elseif ($date_reported > cr_today()) {
        $error = "The report date cannot be in the future.";
    } elseif ($estimated_cost === null || $estimated_cost < 0) {
        $error = "Estimated cost must be a valid amount of 0 or more.";
    }

    if (!$error) {
        // The car must be the car of the selected rental.
        $rs = $conn->prepare("SELECT cars_id FROM rentals WHERE id = ?");
        $rs->bind_param("i", $rental_id);
        $rs->execute();
        $rentalRow = $rs->get_result()->fetch_assoc();
        $rs->close();
        if (!$rentalRow) {
            $error = "The selected rental was not found.";
        } elseif ($car_id > 0 && $car_id !== (int)$rentalRow['cars_id']) {
            $error = "The selected car does not match the rental. Please select the rental again.";
        } else {
            $car_id = (int)$rentalRow['cars_id'];
        }
    }

    if ($error) {
        $_SESSION['error'] = $error;
        header("Location: damage.php"); exit;
    }

    $sql = "INSERT INTO car_damages (car_id, rental_id, damage_description, severity, estimated_cost, date_reported) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iissds", $car_id, $rental_id, $description, $severity, $estimated_cost, $date_reported);
    if ($stmt->execute()) $_SESSION['success'] = "Damage report saved and linked to the customer rental.";
    else $_SESSION['error'] = cr_db_error($stmt->error, "The damage report could not be saved.");
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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Report Car Damage</title></head><body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
<?php if(isset($_SESSION['success'])){ echo "<div style='text-align:center; padding:10px; color:green; font-weight:bold;'>" . h($_SESSION['success']) . "</div>"; unset($_SESSION['success']); } if(isset($_SESSION['error'])){ echo "<div style='text-align:center; padding:10px; color:red; font-weight:bold;'>" . h($_SESSION['error']) . "</div>"; unset($_SESSION['error']); } ?>
<h2>Report Car Damage</h2><br><div class="mb-3"><a href="../maintainance_and_repair/list_damage.php" class="btn btn-primary">List car damages</a></div>
<form method="POST"><label>Select Completed Rental / Car:</label><br><select name="rental_picker" id="rentalPicker" required><option value="">-- Select completed rental --</option><?php while ($row = $completedCars->fetch_assoc()) : ?><option value="<?= h($row['rental_id']); ?>" data-car="<?= h($row['car_id']); ?>"><?= h($row['make_name'] . ' ' . $row['model_name'] . ' (' . $row['reg_no'] . ') - Renter: ' . $row['customer_name'] . ' - ' . cr_date($row['start_date']) . ' to ' . cr_date($row['end_date'])) ?></option><?php endwhile; ?></select><br><br><input type="hidden" name="car_id" id="car_id"><input type="hidden" name="rental_id" id="rental_id"><label>Damage Description:</label><br><textarea name="damage_description" required></textarea><br><br><label>Severity:</label><br><select name="severity" required><?php foreach ($damageSeverities as $sev): ?><option value="<?= h($sev) ?>"><?= h(ucfirst($sev)) ?></option><?php endforeach; ?></select><br><br><label>Estimated Cost:</label><br><input type="number" name="estimated_cost" step="0.01" min="0" required><br><br><label>Date Reported:</label><br><input type="date" name="date_reported" value="<?= h(cr_today()) ?>" max="<?= h(cr_today()) ?>" required><br><br><button type="submit">Submit Damage Report</button></form>
<script>document.getElementById('rentalPicker').addEventListener('change',function(){const opt=this.options[this.selectedIndex];document.getElementById('rental_id').value=this.value||'';document.getElementById('car_id').value=opt?(opt.dataset.car||''):'';});</script>
</div></body></html>
