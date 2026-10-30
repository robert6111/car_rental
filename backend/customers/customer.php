<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = '';
$error = '';
if (isset($_GET['added'])) $msg = 'Customer added successfully.';
if (isset($_GET['updated'])) $msg = 'Customer updated successfully.';
if (isset($_GET['deleted'])) $msg = 'Customer deleted successfully.';
$deleteErrors = [
    'has_history'   => 'This customer has rental history (rentals, reservations or documents) and cannot be deleted. Edit the customer instead.',
    'not_found'     => 'Customer not found (it may already have been deleted).',
    'delete_failed' => 'Customer could not be deleted. Please try again.',
];
$errorCode = (string)($_GET['error'] ?? '');
if (isset($deleteErrors[$errorCode])) $error = $deleteErrors[$errorCode];

// Delete a customer: only allowed when nothing refers to them.
if (isset($_GET['action']) && $_GET['action'] === 'del' && isset($_GET['customer_id'])) {
    stock_validate_action_token();
    $customer_id = intval($_GET['customer_id']);

    $hasHistory = false;
    foreach (['rentals', 'reservations', 'documents'] as $table) {
        $check = $conn->prepare("SELECT 1 FROM `$table` WHERE customer_id = ? LIMIT 1");
        if (!$check) continue; // table missing on an old installation
        $check->bind_param("i", $customer_id);
        if ($check->execute()) {
            $check->store_result();
            if ($check->num_rows > 0) $hasHistory = true;
        }
        $check->close();
        if ($hasHistory) break;
    }

    if ($hasHistory) {
        $result = 'error=has_history';
    } else {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customer_id);
        if ($stmt->execute()) {
            $result = $stmt->affected_rows > 0 ? 'deleted=1' : 'error=not_found';
        } else {
            cr_db_error($stmt->error); // logs the real error
            $result = 'error=delete_failed';
        }
        $stmt->close();
    }
    header('Location: customer.php?' . $result);
    exit;
}

// Fetch all customers with completed rental count for loyalty discount visibility
// Completed rentals are counted only for the customers shown on this page.
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*) FROM customers");
$result = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM rentals r WHERE r.customer_id=c.id AND r.status='Completed') AS completed_rentals FROM customers c ORDER BY c.id DESC" . cr_page_sql($pg));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Customers</title>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="main-content">
        <div class="container mt-4">
            <h2>Customer List</h2>
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><?= h($msg) ?></div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
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
                    <?php $count = $pg['offset'] + 1; ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $count++ ?></td>
                            <td><?= h($row['full_name']) ?></td>
                            <td><?= h($row['phone_number']) ?></td>
                            <td><?= h($row['email']) ?></td>
                            <td><?= h($row['license_no']) ?></td>
                            <td><?= h(trim(($row['id_number'] ?? '') . ' ' . ($row['passport_no'] ?? ''))) ?></td>
                            <td>License: <?= h(cr_date($row['license_expiry'] ?? '')) ?><br>Passport: <?= h(cr_date($row['passport_expiry'] ?? '')) ?></td>
                            <td><?= (loyalty_discount_fraction() > 0 && (int)$row['completed_rentals'] >= loyalty_min_rentals()) ? 'Loyal customer (' . h(loyalty_discount_fraction() * 100) . '% discount)' : h($row['completed_rentals'] . ' completed rentals') ?></td>
                            <td>
                                <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="<?= h(stock_action_url('customer.php?action=del&customer_id=' . (int)$row['id'])); ?>"
                                   onclick="return confirm('Delete this customer? Customers with rental history cannot be deleted.')"
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
            <?= cr_pagination($pg); ?>
        </div>
    </div>
</div>
</body>
</html>

<?php
$result->free();
?>
