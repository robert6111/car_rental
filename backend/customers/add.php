<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/customer_validation.php';

$msg = $error = "";
$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST;
    [$c, $error] = cr_validate_customer_post($conn, $_POST, 0);

    if (!$error) {
        $stmt = $conn->prepare("INSERT INTO customers (full_name, phone_number, email, license_no, address, id_number, passport_no, passport_expiry, license_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $c['full_name'], $c['phone_number'], $c['email'], $c['license_no'], $c['address'], $c['id_number'], $c['passport_no'], $c['passport_expiry'], $c['license_expiry']);

        if ($stmt->execute()) {
            header("Location: customer.php?added=1");
            exit;
        }
        $error = cr_db_error($stmt->error);
        $stmt->close();
    }
}
$v = function (string $field) use ($form): string {
    return h($form[$field] ?? '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Customer</title>
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
<h2>Add New Customer</h2>

<?php if ($msg): ?><p style='color:green;'><?= h($msg) ?></p><?php endif; ?>
<?php if ($error): ?><p style='color:red;'><?= h($error) ?></p><?php endif; ?>

<form method="POST">
    Full Name: <br><input type="text" name="full_name" maxlength="255" value="<?= $v('full_name') ?>" required><br><br>
    Phone Number:<br> <input type="text" name="phone_number" maxlength="50" value="<?= $v('phone_number') ?>" required><br><br>
    Email:<br> <input type="email" name="email" id="email" maxlength="255" value="<?= $v('email') ?>" required><br><br>
    <label>Driver Licence No (Ehliyet No):</label><br>
<input type="text" name="license_no" maxlength="100" value="<?= $v('license_no') ?>" required title="Enter the customer's driving license number.">
<br><br>
<label>License Expiry:</label><br><input type="date" name="license_expiry" value="<?= $v('license_expiry') ?>"><br><br>
<label>ID Number:</label><br><input type="text" name="id_number" maxlength="100" value="<?= $v('id_number') ?>"><br><br>
<label>Passport No:</label><br><input type="text" name="passport_no" maxlength="100" value="<?= $v('passport_no') ?>"><br><br>
<label>Passport Expiry:</label><br><input type="date" name="passport_expiry" value="<?= $v('passport_expiry') ?>"><br><br>
<label>Address:</label><br><textarea name="address" rows="3" maxlength="255"><?= $v('address') ?></textarea><br><br>

    <button type="submit">Add Customer</button>
</form>
</div>
</body>
</html>
