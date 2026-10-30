<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
if (isset($_GET['added'])) $msg = 'Make added successfully.';
$make = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make = trim(preg_replace('/\s+/u', ' ', (string)($_POST['make_name'] ?? '')));
    if ($make === '') {
        $error = "Make name cannot be empty.";
    } elseif (mb_strlen($make) > 50) {
        $error = "Make name is too long (max 50 characters).";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM makes WHERE LOWER(name) = LOWER(?)");
        $check_stmt->bind_param("s", $make);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();

        if ($exists) {
            $error = "This make already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO makes (name) VALUES (?)");
            $stmt->bind_param("s", $make);
            if ($stmt->execute()) {
                header("Location: add_make.php?added=1");
                exit;
            }
            $error = cr_db_error($stmt->error);
            $stmt->close();
        }
    }
}
?>
<?php cr_layout_start('Add make'); ?>
<?= cr_page_header('Add make', 'Add a car brand, such as Toyota, so cars of that make can be added.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="post" class="card card-narrow">
    <div class="field">
        <label for="make_name">Make name <span class="req">*</span></label>
        <input type="text" id="make_name" name="make_name" maxlength="50" value="<?= h($error ? $make : '') ?>" required>
    </div>
    <div class="form-actions">
        <button type="submit">Add make</button>
    </div>
</form>
<?php cr_layout_end(); ?>
