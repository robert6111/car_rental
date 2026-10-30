<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = '';
$error = '';
if (isset($_GET['added'])) $msg = 'Model added successfully.';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make_id = intval($_POST['make_id']);
    $model_name = trim($_POST['model_name']);

    if (!empty($model_name) && $make_id > 0) {
        // Check if model already exists
        $check_stmt = $conn->prepare("SELECT id FROM models WHERE name = ?");
        $check_stmt->bind_param("s", $model_name);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "This model already exists.";
        } else {
            // Insert new model
            $stmt = $conn->prepare("INSERT INTO models (make_id, name) VALUES (?, ?)");
            $stmt->bind_param("is", $make_id, $model_name);

            if ($stmt->execute()) {
                header("Location: add_model.php?added=1");
                exit;
            } else {
                $error = cr_db_error($stmt->error);
            }
            $stmt->close();
        }

        $check_stmt->close();
    } else {
        $error = "Please select a make and enter a model name.";
    }
}



// Fetch all makes for dropdown
$makes = $conn->query("SELECT id, name FROM makes ORDER BY name ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Car Model</title>
             <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
</head>
<body>
    <?php    
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
<h2>Add Car Model</h2>

<?php if (!empty($msg)) echo "<p style='color:green;'>$msg</p>"; ?>
<?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>

<form method="post">
    <label for="make">Select Make:</label>
    <select name="make_id" id="make" required>
        <option value="">-- Select Make --</option>
        <?php while ($row = $makes->fetch_assoc()) : ?>
            <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
        <?php endwhile; ?>
    </select>

    <br><br>
    <label for="model_name">Model Name:</label>
    <input type="text" name="model_name" id="model_name" required>

    <br><br>
    <button type="submit">Add Model</button>
</form>
        </div>
</body>
</html>
