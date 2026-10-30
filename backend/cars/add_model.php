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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Car Model</title>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
<h2>Add Car Model</h2>

<?php if ($msg !== ''): ?><p style='color:green;'><?= h($msg) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p style='color:red;'><?= h($error) ?></p><?php endif; ?>

<form method="post">
    <label for="make">Select Make:</label>
    <select name="make_id" id="make" required>
        <option value="">-- Select Make --</option>
        <?php while ($row = $makes->fetch_assoc()) : ?>
            <option value="<?= (int)$row['id'] ?>" <?= ($error && $make_id === (int)$row['id']) ? 'selected' : '' ?>><?= h($row['name']) ?></option>
        <?php endwhile; ?>
    </select>

    <br><br>
    <label for="model_name">Model Name:</label>
    <input type="text" name="model_name" id="model_name" maxlength="50" value="<?= h($error ? $model_name : '') ?>" required>

    <br><br>
    <button type="submit">Add Model</button>
</form>
        </div>
</body>
</html>
