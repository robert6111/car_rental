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
<?php cr_layout_start('Edit damage'); ?>
<a class="back-link" href="list_damage.php">All damages</a>
<?= cr_page_header('Edit damage #' . (int)$damage['id'], 'Update the damage details and record the repair.', '<a class="btn btn-secondary" href="view_damage.php?id=' . (int)$damage['id'] . '">View</a>'); ?>
<?= cr_alert('error', (string)$formError); ?>

<form method="POST" class="card">
    <input type="hidden" name="id" value="<?= (int)$damage['id'] ?>">
    <div class="form-grid">
        <div class="field span-all">
            <label for="damage_description">Description <span class="req">*</span></label>
            <textarea id="damage_description" name="damage_description" rows="4" required><?= h($damage['damage_description']) ?></textarea>
        </div>
        <div class="field">
            <label for="severity">Severity</label>
            <select id="severity" name="severity">
                <?php foreach ($damageSeverities as $sev): ?>
                <option value="<?= h($sev) ?>" <?= $damage['severity'] === $sev ? 'selected' : '' ?>><?= h(ucfirst($sev)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="estimated_cost">Estimated cost <span class="opt">(optional)</span></label>
            <input type="number" id="estimated_cost" step="0.01" min="0" name="estimated_cost" value="<?= h($damage['estimated_cost']) ?>">
        </div>
    </div>

    <div class="form-section">
        <h3>Repair</h3>
        <div class="form-grid">
            <div class="field">
                <label for="repaired">Repaired?</label>
                <select id="repaired" name="repaired">
                    <option value="0" <?= (int)$damage['repaired'] === 0 ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= (int)$damage['repaired'] === 1 ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
            <div class="field">
                <label for="repair_cost">Repair cost <span class="opt">(optional)</span></label>
                <input type="number" id="repair_cost" step="0.01" min="0" name="repair_cost" value="<?= h($damage['repair_cost']) ?>">
            </div>
            <div class="field">
                <label for="repair_date">Repair date <span class="opt">(optional)</span></label>
                <input type="date" id="repair_date" name="repair_date" max="<?= h(cr_today()) ?>" value="<?= h(cr_valid_date((string)($damage['repair_date'] ?? '')) ? $damage['repair_date'] : '') ?>">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit">Save</button>
        <a class="btn btn-secondary" href="list_damage.php">Back</a>
    </div>
</form>
<?php cr_layout_end(); ?>
