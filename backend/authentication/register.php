<?php
require_once __DIR__ . '/../includes/app.php';
?>
<?php cr_auth_start('Registration closed'); ?>
<h1>Registration is closed</h1>
<p class="lead">For security, only an administrator can create user accounts, from inside the system.</p>
<a class="btn btn-block" href="login.php">Back to sign in</a>
<?php cr_auth_end(); ?>
