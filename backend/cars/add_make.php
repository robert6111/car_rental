<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
if (isset($_GET['added'])) $msg = 'Make added successfully.';
error_reporting(0);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make = trim($_POST['make_name']);
    if (!empty($make)) {
         $check_stmt = $conn->prepare("SELECT id FROM makes WHERE name = ?");
        $check_stmt->bind_param("s", $make);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "This make already exists.";
        }
        else{
        $stmt = $conn->prepare("INSERT INTO makes (name) VALUES (?)");
        $stmt->bind_param("s", $make);
        if ($stmt->execute()) {
            header("Location: add_make.php?added=1");
            exit;
        } else {
            $error = cr_db_error($stmt->error);
        }
        $stmt->close();
    } } else {
        $error = "Make name cannot be empty.";
    }
}
?>
<head>
    <title>add make</title>
         <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
</head>
<body>
<?php    
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
?>
    <div class="content-page">
<h2>Add Make</h2>
<?php if ($msg) echo "<div style='color:green;'>$msg</div>"; ?>
<?php if ($error) echo "<div style='color:red;'>$error</div>"; ?>
<form method="post">
    <label>Make Name:</label>
    <input type="text" name="make_name" required>
    <button type="submit">Add Make</button>
</form>
