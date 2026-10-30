<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
if (isset($_GET['added'])) $msg = 'Year added successfully.';

$minYear = 1950;
$maxYear = (int)date('Y') + 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $yearInput = trim((string)($_POST['year'] ?? ''));
    $year = preg_match('/^\d{4}$/', $yearInput) ? (int)$yearInput : 0;

    if ($year < $minYear || $year > $maxYear) {
        $error = "Please enter a valid year between $minYear and $maxYear.";
    } else {
        // Check if the year already exists
        $stmt = $conn->prepare("SELECT id FROM car_years WHERE year = ?");
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = "Year already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO car_years (year) VALUES (?)");
            $stmt->bind_param("i", $year);
            if ($stmt->execute()) {
                header("Location: add_years.php?added=1");
                exit;
            }
            $error = cr_db_error($stmt->error);
            $stmt->close();
        }
    }
}

// Fetch existing years
$years_result = $conn->query("SELECT id, year FROM car_years ORDER BY year DESC");
?>
<?php cr_layout_start('Add year'); ?>
<?= cr_page_header('Add year', 'Add a model year so cars from that year can be added.'); ?>
<?= cr_alerts($msg, $error); ?>

<div class="grid grid-2">
    <form method="post" class="card" style="align-self: start;">
        <h2 class="section-title">New year</h2>
        <div class="field">
            <label for="year">Year <span class="req">*</span></label>
            <input type="number" id="year" name="year" min="<?= $minYear ?>" max="<?= $maxYear ?>" step="1" required>
            <span class="hint">Between <?= $minYear ?> and <?= $maxYear ?>.</span>
        </div>
        <div class="form-actions">
            <button type="submit"><?= cr_icon('plus'); ?> Add year</button>
        </div>
    </form>

    <div class="card card-flush">
        <div class="card-head"><h2>Existing years</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Year</th></tr></thead>
                <tbody>
                <?php if ($years_result->num_rows > 0): ?>
                    <?php while ($row = $years_result->fetch_assoc()): ?>
                    <tr>
                        <td class="muted"><?= (int)$row['id'] ?></td>
                        <td class="cell-title"><?= h($row['year']) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="2" class="table-empty">No years yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php cr_layout_end(); ?>
