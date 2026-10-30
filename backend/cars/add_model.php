<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = '';
$error = '';
if (isset($_GET['added'])) $msg = 'Model added successfully.';
$make_id = 0;
$model_name = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make_id = intval($_POST['make_id'] ?? 0);
    $model_name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['model_name'] ?? '')));

    if ($model_name === '' || $make_id <= 0) {
        $error = "Please select a make and enter a model name.";
    } elseif (mb_strlen($model_name) > 50) {
        $error = "Model name is too long (max 50 characters).";
    } else {
        $mk = $conn->prepare("SELECT id FROM makes WHERE id = ?");
        $mk->bind_param("i", $make_id);
        $mk->execute();
        $makeExists = $mk->get_result()->num_rows > 0;
        $mk->close();

        if (!$makeExists) {
            $error = "Please select a valid make.";
        } else {
            // The same model name may exist under a different make.
            $check_stmt = $conn->prepare("SELECT id FROM models WHERE make_id = ? AND LOWER(name) = LOWER(?)");
            $check_stmt->bind_param("is", $make_id, $model_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            $exists = $check_stmt->num_rows > 0;
            $check_stmt->close();

            if ($exists) {
                $error = "This model already exists for the selected make.";
            } else {
                $stmt = $conn->prepare("INSERT INTO models (make_id, name) VALUES (?, ?)");
                $stmt->bind_param("is", $make_id, $model_name);

                if ($stmt->execute()) {
                    header("Location: add_model.php?added=1");
                    exit;
                }
                $error = cr_db_error($stmt->error);
                $stmt->close();
            }
        }
    }
}

// Fetch all makes for dropdown
$makes = $conn->query("SELECT id, name FROM makes ORDER BY name ASC");
?>
<?php cr_layout_start('Add model'); ?>
<?= cr_page_header('Add model', 'Add a model under a make, such as Corolla under Toyota.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="post" class="card card-narrow">
    <div class="form-grid">
        <div class="field">
            <label for="make">Make <span class="req">*</span></label>
            <select name="make_id" id="make" required>
                <option value="">Select make</option>
                <?php while ($row = $makes->fetch_assoc()) : ?>
                    <option value="<?= (int)$row['id'] ?>" <?= ($error && $make_id === (int)$row['id']) ? 'selected' : '' ?>><?= h($row['name']) ?></option>
                <?php endwhile; ?>
            </select>
            <span class="hint">Make missing? <a href="add_make.php">Add the make first</a>.</span>
        </div>
        <div class="field">
            <label for="model_name">Model name <span class="req">*</span></label>
            <input type="text" name="model_name" id="model_name" maxlength="50" value="<?= h($error ? $model_name : '') ?>" required>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Add model</button>
    </div>
</form>
<?php cr_layout_end(); ?>
