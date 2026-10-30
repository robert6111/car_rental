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
<?php cr_layout_start('Customers'); ?>
<?= cr_page_header('Customers', 'Everyone registered to rent a car.', '<a class="btn" href="add.php">' . cr_icon('user-plus') . ' Add customer</a>'); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card">
    <div class="card-head"><h2>All customers</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Licence no.</th>
                    <th>ID / passport</th>
                    <th>Expiry dates</th>
                    <th>Loyalty</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php $count = $pg['offset'] + 1; ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="muted"><?= $count++ ?></td>
                        <td class="nowrap"><span class="cell-title"><?= h($row['full_name']) ?></span><span class="cell-sub"><?= h($row['phone_number']) ?></span><?php if ((string)$row['email'] !== ''): ?><span class="cell-sub"><?= h($row['email']) ?></span><?php endif; ?></td>
                        <td><?= h($row['license_no']) ?></td>
                        <td><?= h(trim(($row['id_number'] ?? '') . ' ' . ($row['passport_no'] ?? ''))) ?></td>
                        <td class="nowrap">Licence: <?= h(cr_date($row['license_expiry'] ?? '') ?: '—') ?><span class="cell-sub">Passport: <?= h(cr_date($row['passport_expiry'] ?? '') ?: '—') ?></span></td>
                        <td><?php if (loyalty_discount_fraction() > 0 && (int)$row['completed_rentals'] >= loyalty_min_rentals()): ?><?= cr_status_badge('Active', 'Loyal (' . (loyalty_discount_fraction() * 100) . '% discount)') ?><?php else: ?><span class="muted"><?= h($row['completed_rentals'] . ' completed rentals') ?></span><?php endif; ?></td>
                        <td class="actions-cell">
                            <a href="edit.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-secondary"><?= cr_icon('edit', 'icon-sm') ?> Edit</a>
                            <a href="<?= h(stock_action_url('customer.php?action=del&customer_id=' . (int)$row['id'])); ?>"
                               data-confirm="Delete this customer? Customers with rental history cannot be deleted."
                               class="btn btn-sm btn-danger"><?= cr_icon('trash', 'icon-sm') ?> Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No customers yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
<?php
$result->free();
?>
