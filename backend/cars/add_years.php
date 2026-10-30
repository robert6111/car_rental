<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
if (isset($_GET['added'])) $msg = 'Year added successfully.';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = intval($_POST['year']);

    if ($year) {
        // Check if the year already exists
        $stmt = $conn->prepare("SELECT id FROM car_years WHERE year = ?");
        $stmt->bind_param("i", $year);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Year already exists.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO car_years (year) VALUES (?)");
            $stmt->bind_param("i", $year);
            if ($stmt->execute()) {
                header("Location: add_years.php?added=1");
                exit;
            } else {
                $error = "Error: " . $stmt->error;
            }
        }

        $stmt->close();
    } else {
        $error = "Please enter a valid year.";
    }
}

// Fetch existing years
$years_result = $conn->query("SELECT id, year FROM car_years ORDER BY year DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Car Year</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
</head>
<body>
    <?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
    ?>
    <div class="content-page">
        <h2>Add Car Year</h2>
        <?php if ($msg) echo "<div style='color:green;'>$msg</div>"; ?>
        <?php if ($error) echo "<div style='color:red;'>$error</div>"; ?>

        <form method="post">
            <label>Year:</label><br>
            <input type="number" name="year" min="1900" max="<?= date('Y')+1 ?>" required><br><br>
            <button type="submit">Add Year</button>
        </form>

        <h3>Existing Years</h3>
        <table border="1" cellpadding="5">
            <tr>
                <th>ID</th>
                <th>Year</th>
            </tr>
            <?php while ($row = $years_result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= $row['year'] ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</body>
</html>
