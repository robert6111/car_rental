<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$msg = $error = '';
if (isset($_GET['added'])) $msg = 'Make added successfully.';
$make = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make = trim(preg_replace('/\s+/u', ' ', (string)($_POST['make_name'] ?? '')));
    if ($make === '') {
        $error = "Make name cannot be empty.";
    } elseif (mb_strlen($make) > 50) {
        $error = "Make name is too long (max 50 characters).";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM makes WHERE LOWER(name) = LOWER(?)");
        $check_stmt->bind_param("s", $make);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();

        if ($exists) {
            $error = "This make already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO makes (name) VALUES (?)");
            $stmt->bind_param("s", $make);
            if ($stmt->execute()) {
                header("Location: add_make.php?added=1");
                exit;
            }
            $error = cr_db_error($stmt->error);
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Make</title>
</head>
<body>
<?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/leftsidebar.php';
?>
    <div class="content-page">
<h2>Add Make</h2>
<?php if ($msg): ?><div style='color:green;'><?= h($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div style='color:red;'><?= h($error) ?></div><?php endif; ?>
<form method="post">
    <label>Make Name:</label>
    <input type="text" name="make_name" maxlength="50" value="<?= h($error ? $make : '') ?>" required>
    <button type="submit">Add Make</button>
</form>
    </div>
</body>
</html>
