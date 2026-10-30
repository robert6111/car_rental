<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die("Invalid customer ID");
}

// Fetch customer
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    die("Customer not found");
}

$msg = $error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $phone_number = $conn->real_escape_string($_POST['phone_number']);
    $email = $conn->real_escape_string($_POST['email']);
     if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        
    } else {
        echo "Please enter a valid email address.";
    }
    $license_no = $conn->real_escape_string($_POST['license_no']);
    $address = trim($_POST['address'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $passport_no = trim($_POST['passport_no'] ?? '');
    $passport_expiry = ($_POST['passport_expiry'] ?? '') ?: null;
    $license_expiry = ($_POST['license_expiry'] ?? '') ?: null;

    $stmt = $conn->prepare("UPDATE customers SET full_name = ?, phone_number = ?, email = ?, license_no = ?, address=?, id_number=?, passport_no=?, passport_expiry=?, license_expiry=? WHERE id = ?");
    $stmt->bind_param("sssssssssi", $full_name, $phone_number, $email, $license_no, $address, $id_number, $passport_no, $passport_expiry, $license_expiry, $id);

    if ($stmt->execute()) {
        $msg = "Customer updated successfully.";
        header("Location: customer.php?updated=1");
        exit;
    } else {
        $error = cr_db_error($stmt->error);
    }
    $stmt->close();
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Customer</title>
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
</head>
<body>
<div class="content-page">
    <div class="main-content">
        <div class="container mt-4">
            <h2>Edit Customer</h2>

            <?php if ($msg) echo "<div class='alert alert-success'>" . h($msg) . "</div>"; ?>
            <?php if ($error) echo "<div class='alert alert-danger'>" . h($error) . "</div>"; ?>

            <div class="card" style="padding:20px; max-width:600px;">
                <form method="POST">
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlentities($customer['full_name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="text" name="phone_number" class="form-control" value="<?= htmlentities($customer['phone_number']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" id="email" class="form-control" value="<?= htmlentities($customer['email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>License Number:</label>
                        <input type="text" name="license_no" class="form-control" value="<?= htmlentities($customer['license_no']) ?>" required>
                    </div>
                    <div class="form-group"><label>License Expiry:</label><input type="date" name="license_expiry" class="form-control" value="<?= htmlentities($customer['license_expiry'] ?? '') ?>"></div>
                    <div class="form-group"><label>ID Number:</label><input type="text" name="id_number" class="form-control" value="<?= htmlentities($customer['id_number'] ?? '') ?>"></div>
                    <div class="form-group"><label>Passport No:</label><input type="text" name="passport_no" class="form-control" value="<?= htmlentities($customer['passport_no'] ?? '') ?>"></div>
                    <div class="form-group"><label>Passport Expiry:</label><input type="date" name="passport_expiry" class="form-control" value="<?= htmlentities($customer['passport_expiry'] ?? '') ?>"></div>
                    <div class="form-group"><label>Address:</label><textarea name="address" class="form-control" rows="3"><?= htmlentities($customer['address'] ?? '') ?></textarea></div>

                    <button type="submit" class="btn btn-primary mt-3">Update Customer</button>
                    <a href="customer.php" class="btn btn-secondary mt-3">Back to List</a>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
