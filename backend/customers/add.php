<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: authentication/login.php");
    exit;
}

$msg = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $phone_number = $conn->real_escape_string($_POST['phone_number']);
    $email = $conn->real_escape_string($_POST['email']);
     if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        
    } else {
        echo "Please enter a valid email address.";
    }
    $license_no = $conn->real_escape_string($_POST['license_no'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $passport_no = trim($_POST['passport_no'] ?? '');
    $passport_expiry = ($_POST['passport_expiry'] ?? '') ?: null;
    $license_expiry = ($_POST['license_expiry'] ?? '') ?: null;

    $stmt = $conn->prepare("INSERT INTO customers (full_name, phone_number, email, license_no, address, id_number, passport_no, passport_expiry, license_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", $full_name, $phone_number, $email, $license_no, $address, $id_number, $passport_no, $passport_expiry, $license_expiry);

    if ($stmt->execute()) {
        header("Location: customer.php?added=1");
        exit;
    } else {
        $error = cr_db_error($stmt->error);
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Customer</title>
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
<h2>Add New Customer</h2>

<?php if($msg) echo "<p style='color:green;'>$msg</p>"; ?>
<?php if($error) echo "<p style='color:red;'>$error</p>"; ?>

<form method="POST">
    Full Name: <br><input type="text" name="full_name" required><br><br>
    Phone Number:<br> <input type="text" name="phone_number" required><br><br>
    Email:<br> <input type="email" name="email" id="email" required><br><br>
    <label>Driver Licence No (Ehliyet No):</label><br>
<input type="text" name="license_no" required title="Enter the customer's driving license number.">
<br><br>
<label>License Expiry:</label><br><input type="date" name="license_expiry"><br><br>
<label>ID Number:</label><br><input type="text" name="id_number"><br><br>
<label>Passport No:</label><br><input type="text" name="passport_no"><br><br>
<label>Passport Expiry:</label><br><input type="date" name="passport_expiry"><br><br>
<label>Address:</label><br><textarea name="address" rows="3"></textarea><br><br>

    <button type="submit">Add Customer</button>
</form>
</div>
</body>
</html>
