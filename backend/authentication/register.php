<?php
require_once __DIR__ . '/../includes/app.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Registration Disabled - Car Rental</title>
    <link rel="stylesheet" href="<?= h(app_url('includes/css/style.css')); ?>?v=customer-ready-1" />
</head>
<body>
    <div class="main-container centered-flex">
        <div class="form-container">
            <div class="icon fa fa-lock"></div>
            <form class="centered-flex">
                <div class="title-register">REGISTRATION CLOSED</div>
                <div class="msg">
                    <p class="alert error">For security, only an admin can create user accounts from inside the system.</p>
                </div>
                <div class="btn-container">
                    <a href="login.php" id="login-btn" style="display:inline-block;text-align:center;">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
