<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';

// Must match damage.php and the car_damages.severity ENUM.
$damageSeverities = ['low', 'medium', 'moderate', 'high'];

// PROCESS UPDATE
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        header("Location: list_damage.php");
        exit;
    }
    $description = trim((string)($_POST['damage_description'] ?? ''));
    $severity = (string)($_POST['severity'] ?? '');
    $repaired = ((string)($_POST['repaired'] ?? '0')) === '1' ? 1 : 0;
    $estimatedInput = trim((string)($_POST['estimated_cost'] ?? ''));
    $repairCostInput = trim((string)($_POST['repair_cost'] ?? ''));
    $repairDateInput = trim((string)($_POST['repair_date'] ?? ''));
    $estimated_cost = null;
    $repair_cost = null;
    $repair_date = null; // empty date is stored as NULL, never ''
    $error = '';

    if ($description === '') {
        $error = 'Please describe the damage.';
    } elseif (!in_array($severity, $damageSeverities, true)) {
        $error = 'Please choose a valid severity.';
    } elseif ($estimatedInput !== '' && (($estimated_cost = cr_parse_money($estimatedInput)) === null || $estimated_cost < 0)) {
        $error = 'Estimated cost must be a valid amount of 0 or more.';
    } elseif ($repairCostInput !== '' && (($repair_cost = cr_parse_money($repairCostInput)) === null || $repair_cost < 0)) {
        $error = 'Repair cost must be a valid amount of 0 or more.';
    } elseif ($repairDateInput !== '' && !cr_valid_date($repairDateInput)) {
        $error = 'Please enter a valid repair date.';
    } elseif ($repairDateInput !== '' && $repairDateInput > cr_today()) {
        $error = 'The repair date cannot be in the future.';
    }
    if ($repairDateInput !== '' && !$error) $repair_date = $repairDateInput;

    if ($error) {
        $_SESSION['error'] = $error;
        header("Location: edit_damage.php?id=" . $id);
        exit;
    }

    $sql = "UPDATE car_damages SET
        damage_description=?, severity=?, estimated_cost=?, repaired=?, repair_cost=?, repair_date=?
        WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssdidsi", $description, $severity, $estimated_cost, $repaired, $repair_cost, $repair_date, $id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Damage updated successfully!";
    } else {
        $_SESSION['error'] = cr_db_error($stmt->error, "The damage record could not be saved.");
    }
    $stmt->close();

    header("Location: list_damage.php");
    exit;
}


// LOAD DAMAGE
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header("Location: list_damage.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM car_damages WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$damage = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$damage) {
    header("Location: list_damage.php");
    exit;
}
$formError = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Damage</title>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>

<div class="content-page">
<h2>Edit Damage (ID: <?= (int)$damage['id'] ?>)</h2>
<?php if ($formError): ?><div style="color:red; font-weight:bold;"><?= h($formError) ?></div><?php endif; ?>


<form method="POST">
<input type="hidden" name="id" value="<?= (int)$damage['id'] ?>">


<label>Description:</label><br>
<textarea name="damage_description" rows="4" cols="50" required><?= h($damage['damage_description']) ?></textarea><br><br>


<label>Severity:</label>
<select name="severity">
<?php foreach ($damageSeverities as $sev): ?>
<option value="<?= h($sev) ?>" <?= $damage['severity'] === $sev ? 'selected' : '' ?>><?= h(ucfirst($sev)) ?></option>
<?php endforeach; ?>
</select><br><br>


<label>Estimated Cost:</label><br>
<input type="number" step="0.01" min="0" name="estimated_cost" value="<?= h($damage['estimated_cost']) ?>"><br><br>


<label>Repaired?</label>
<select name="repaired">
<option value="0" <?= (int)$damage['repaired'] === 0 ? 'selected' : '' ?>>No</option>
<option value="1" <?= (int)$damage['repaired'] === 1 ? 'selected' : '' ?>>Yes</option>
</select><br><br>


<label>Repair Cost:</label><br>
<input type="number" step="0.01" min="0" name="repair_cost" value="<?= h($damage['repair_cost']) ?>"><br><br>


<label>Repair Date:</label><br>
<input type="date" name="repair_date" max="<?= h(cr_today()) ?>"
       value="<?= h(cr_valid_date((string)($damage['repair_date'] ?? '')) ? $damage['repair_date'] : '') ?>">
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
