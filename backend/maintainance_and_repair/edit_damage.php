

<?php
require_once __DIR__ . '/../includes/auth.php';
// ==============================
// edit_damage.php
// ==============================
?>
<?php


include __DIR__ . '/../includes/db.php';


if (!isset($_SESSION['user_id'])) {
header("Location: ../authentication/login.php");
exit;
}


// PROCESS UPDATE
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
$sql = "UPDATE car_damages SET
damage_description=?, severity=?, estimated_cost=?, repaired=?, repair_cost=?, repair_date=?
WHERE id=?";


$stmt = $conn->prepare($sql);
$stmt->bind_param(
"ssdidsi",
$_POST['damage_description'],
$_POST['severity'],
$_POST['estimated_cost'],
$_POST['repaired'],
$_POST['repair_cost'],
$_POST['repair_date'],
$_POST['id']
);



if ($stmt->execute()) {
$_SESSION['success'] = "Damage updated successfully!";
} else {
error_log('Damage update failed: ' . $stmt->error); $_SESSION['error'] = "The damage record could not be saved.";
}


header("Location: list_damage.php");
exit;
}


// LOAD DAMAGE
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) die("Invalid ID");


$result = $conn->query("SELECT * FROM car_damages WHERE id = $id");
if (!$result || $result->num_rows === 0) die("Damage not found.");


$damage = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Damage</title>
<link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/all_cars.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>

<div class="content-page">
<h2>Edit Damage (ID: <?= (int)$damage['id'] ?>)</h2>


<form method="POST">
<input type="hidden" name="id" value="<?= (int)$damage['id'] ?>">


<label>Description:</label><br>
<textarea name="damage_description" rows="4" cols="50"><?= h($damage['damage_description']) ?></textarea><br><br>


<label>Severity:</label>
<select name="severity">
<option value="low" <?= $damage['severity'] == 'low' ? 'selected' : '' ?>>Low</option>
<option value="medium" <?= $damage['severity'] == 'medium' ? 'selected' : '' ?>>Medium</option>
<option value="high" <?= $damage['severity'] == 'high' ? 'selected' : '' ?>>High</option>
</select><br><br>


<label>Estimated Cost:</label><br>
<input type="number" step="0.01" name="estimated_cost" value="<?= h($damage['estimated_cost']) ?>"><br><br>


<label>Repaired?</label>
<select name="repaired">
<option value="0" <?= $damage['repaired'] == 0 ? 'selected' : '' ?>>No</option>
<option value="1" <?= $damage['repaired'] == 1 ? 'selected' : '' ?>>Yes</option>
</select><br><br>


<label>Repair Cost:</label><br>
<input type="number" step="0.01" name="repair_cost" value="<?= h($damage['repair_cost']) ?>"><br><br>


<label>Repair Date:</label><br>
<input type="date" name="repair_date" 
       value="<?= ($damage['repair_date'] && strlen($damage['repair_date'])==10) ? $damage['repair_date'] : '' ?>">
<br><br>


<button type="submit" style="margin-top: 20px; border: 1px solid black; border-radius: 5px;">Save</button>
<br><br>
</form> 


<a href="view_damage.php?id=<?= (int)$damage['id'] ?>">View</a>
<br>
<a href="list_damage.php">Back</a>


</div>
</body>
</html>