<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: authentication/login.php");
    exit;
}

$msg = '';
$error = '';
if (isset($_GET['added'])) $msg = 'Customer added successfully.';
if (isset($_GET['updated'])) $msg = 'Customer updated successfully.';

// Handle soft delete (optional)
if (isset($_GET['action']) && $_GET['action'] === 'del' && isset($_GET['customer_id'])) {
    stock_validate_action_token();
    $customer_id = intval($_GET['customer_id']);
    $check = $conn->prepare("SELECT id FROM rentals WHERE customer_id = ? LIMIT 1");
    $check->bind_param("i", $customer_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $error = "This customer has rental records and cannot be deleted. Edit the customer instead.";
    } else {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customer_id);
        if ($stmt->execute()) {
            $msg = "Customer deleted successfully.";
        } else {
            $error = "Customer could not be deleted.";
        }
        $stmt->close();
    }
    $check->close();
}

// Fetch all customers with completed rental count for loyalty discount visibility
$result = $conn->query("SELECT c.*, COUNT(r.id) AS completed_rentals FROM customers c LEFT JOIN rentals r ON r.customer_id=c.id AND r.status='Completed' GROUP BY c.id ORDER BY c.id DESC");
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Customers</title>
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="content-page">
    <div class="main-content">
        <div class="container mt-4">
            <h2>Customer List</h2>
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><?= htmlentities($msg) ?></div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlentities($error) ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <a href="add.php" class="btn btn-primary">Add New Customer</a>
            </div>

            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>License No</th>
                        <th>ID / Passport</th>
                        <th>Expiry Alerts</th>
                        <th>Loyalty</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php $count = 1; ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $count++ ?></td>
                            <td><?= htmlentities($row['full_name']) ?></td>
                            <td><?= htmlentities($row['phone_number']) ?></td>
                            <td><?= htmlentities($row['email']) ?></td>
                            <td><?= htmlentities($row['license_no']) ?></td>
                            <td><?= h(trim(($row['id_number'] ?? '') . ' ' . ($row['passport_no'] ?? ''))) ?></td>
                            <td>License: <?= h($row['license_expiry'] ?? '') ?><br>Passport: <?= h($row['passport_expiry'] ?? '') ?></td>
                            <td><?= (loyalty_discount_fraction() > 0 && (int)$row['completed_rentals'] >= loyalty_min_rentals()) ? 'Loyal customer (' . h(loyalty_discount_fraction() * 100) . '% discount)' : htmlentities($row['completed_rentals'] . ' completed rentals') ?></td>
                            <td>
                                <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="<?= h(stock_action_url('customer.php?action=del&customer_id=' . (int)$row['id'])); ?>"
                                   onclick="return confirm('Are you sure you want to delete this customer?')"
                                   class="btn btn-sm btn-danger">Delete</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center">No customers found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>

<?php
$result->free();
$conn->close();
?>
