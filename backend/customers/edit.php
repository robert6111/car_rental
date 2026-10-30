<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/customer_validation.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: customer.php');
    exit;
}

// Fetch customer
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    header('Location: customer.php');
    exit;
}

$msg = $error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$c, $error] = cr_validate_customer_post($conn, $_POST, $id);

    if (!$error) {
        $stmt = $conn->prepare("UPDATE customers SET full_name = ?, phone_number = ?, email = ?, license_no = ?, address=?, id_number=?, passport_no=?, passport_expiry=?, license_expiry=? WHERE id = ?");
        $stmt->bind_param("sssssssssi", $c['full_name'], $c['phone_number'], $c['email'], $c['license_no'], $c['address'], $c['id_number'], $c['passport_no'], $c['passport_expiry'], $c['license_expiry'], $id);

        if ($stmt->execute()) {
            header("Location: customer.php?updated=1");
            exit;
        }
        $error = cr_db_error($stmt->error);
        $stmt->close();
    }

    // Re-show what the user typed.
    foreach (['full_name', 'phone_number', 'email', 'license_no', 'address', 'id_number', 'passport_no', 'passport_expiry', 'license_expiry'] as $f) {
        $customer[$f] = (string)($_POST[$f] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer</title>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="main-content">
        <div class="container mt-4">
            <h2>Edit Customer</h2>

            <?php if ($msg): ?><div class='alert alert-success'><?= h($msg) ?></div><?php endif; ?>
            <?php if ($error): ?><div class='alert alert-danger'><?= h($error) ?></div><?php endif; ?>

            <div class="card" style="padding:20px; max-width:600px;">
                <form method="POST">
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="full_name" class="form-control" maxlength="255" value="<?= h($customer['full_name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="text" name="phone_number" class="form-control" maxlength="50" value="<?= h($customer['phone_number']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" id="email" class="form-control" maxlength="255" value="<?= h($customer['email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>License Number:</label>
                        <input type="text" name="license_no" class="form-control" maxlength="100" value="<?= h($customer['license_no']) ?>" required>
                    </div>
                    <div class="form-group"><label>License Expiry:</label><input type="date" name="license_expiry" class="form-control" value="<?= h($customer['license_expiry'] ?? '') ?>"></div>
                    <div class="form-group"><label>ID Number:</label><input type="text" name="id_number" class="form-control" maxlength="100" value="<?= h($customer['id_number'] ?? '') ?>"></div>
                    <div class="form-group"><label>Passport No:</label><input type="text" name="passport_no" class="form-control" maxlength="100" value="<?= h($customer['passport_no'] ?? '') ?>"></div>
                    <div class="form-group"><label>Passport Expiry:</label><input type="date" name="passport_expiry" class="form-control" value="<?= h($customer['passport_expiry'] ?? '') ?>"></div>
                    <div class="form-group"><label>Address:</label><textarea name="address" class="form-control" rows="3" maxlength="255"><?= h($customer['address'] ?? '') ?></textarea></div>

                    <button type="submit" class="btn btn-primary mt-3">Update Customer</button>
                    <a href="customer.php" class="btn btn-secondary mt-3">Back to List</a>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
