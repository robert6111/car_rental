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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Car Year</title>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
    ?>
    <div class="content-page">
        <h2>Add Car Year</h2>
        <?php if ($msg): ?><div style='color:green;'><?= h($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div style='color:red;'><?= h($error) ?></div><?php endif; ?>

        <form method="post">
            <label>Year:</label><br>
            <input type="number" name="year" min="<?= $minYear ?>" max="<?= $maxYear ?>" step="1" required><br><br>
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
                <td><?= (int)$row['id'] ?></td>
                <td><?= h($row['year']) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</body>
</html>
