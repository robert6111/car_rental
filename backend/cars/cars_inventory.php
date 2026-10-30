<?php
require_once __DIR__ . '/../includes/auth.php';
error_reporting(0);
ini_set('display_errors', 0);
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit;
}

$msg = $error = "";

// Handle soft deletion (mark car as unavailable)
if (isset($_GET['action'], $_GET['car_id']) && $_GET['action'] === 'del') {
    stock_require_permission('cars_manage');
    stock_validate_action_token();
    $car_id = intval($_GET['car_id']);
    $stmt = $conn->prepare("UPDATE cars SET availability_status = 'Maintenance' WHERE id = ?");
    $stmt->bind_param("i", $car_id);
    if ($stmt->execute()) {
        $msg = "Car marked as maintenance/unavailable.";
    } else {
        $error = "Error updating car: " . $stmt->error;
    }
    $stmt->close();
}

$sql = "SELECT c.id, c.reg_no, c.availability_status,
               m.name AS make, mo.name AS model, y.year AS car_year,
               c.price_per_day  -- Add price_per_day column
        FROM cars c
        LEFT JOIN makes m ON c.make_id = m.id
        LEFT JOIN models mo ON c.model_id = mo.id
        LEFT JOIN car_years y ON c.year_id = y.id
        ORDER BY c.id DESC";



$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cars Inventory</title>
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/all_cars.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
    <h2>Cars Inventory</h2>

    <?php if ($msg): ?>
        <div style="color:green;"><?= htmlentities($msg) ?></div>
    <?php elseif ($error): ?>
        <div style="color:red;"><?= htmlentities($error) ?></div>
    <?php endif; ?>

    <div style="margin-bottom:20px;">
        <a href="add.php" class="btn btn-primary">Add New Car</a>
    </div>
<table border="1" cellpadding="10">
    <tr>
        <th>#</th>
        <th>Registration No</th>
        <th>Make</th>
        <th>Model</th>
        <th>Year</th>
        <th>Status</th>
        <th>Price per Day</th>  <!-- New column for price per day -->
        <th>Actions</th>
    </tr>
    <?php if ($result->num_rows > 0): ?>
        <?php $count = 1; ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $count++ ?></td>
                <td><?= htmlentities($row['reg_no']) ?></td>
                <td><?= htmlentities($row['make']) ?></td>
                <td><?= htmlentities($row['model']) ?></td>
                <td><?= htmlentities($row['car_year']) ?></td>
                <td><?= htmlentities($row['availability_status']) ?></td>
                <td><?= htmlentities($row['price_per_day']) ?> TRY</td> <!-- Display price per day -->
                <td>
                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Edit</a> <br><br>
                    <a href="<?= h(stock_action_url('cars_inventory.php?action=del&car_id=' . (int)$row['id'])); ?>"
                       onclick="return confirm('Are you sure you want to mark this car as unavailable?')"
                       class="btn btn-sm btn-danger">Delete</a>
                </td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="8" style="text-align:center;">No cars found.</td>  <!-- Adjust column span to 8 -->
        </tr>
    <?php endif; ?>
</table>

</div>

<?php
// Free result and close connection
$result->free();
$conn->close();
?>
</body>
</html>
