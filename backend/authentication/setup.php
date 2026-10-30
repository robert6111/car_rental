<?php
/*
 * First-run setup: creates the first Admin account.
 * Only available while the system has no active Admin. Once an admin exists this page is closed.
 */
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

if (cr_active_admin_exists($conn)) {
    header('Location: login.php');
    exit;
}

$error = '';
$username = '';
$email = '';
$business = ['company_name' => '', 'currency_code' => 'USD', 'currency_symbol' => '$', 'timezone' => date_default_timezone_get(), 'phone_country_code' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    foreach ($business as $k => $v) $business[$k] = trim((string)($_POST[$k] ?? $v));
    [$cleanSettings, $settingErrors] = cr_settings_validate($business);

    if (!stock_rate_limit('setup', 10, 3600)) {
        $error = 'Too many attempts. Please try again later.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (($pwProblem = cr_password_problem($password)) !== null) {
        $error = $pwProblem;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($business['company_name'] === '') {
        $error = 'Please enter the business name.';
    } elseif ($settingErrors) {
        $error = reset($settingErrors);
    } else {
        $conn->begin_transaction();
        // Re-check inside the transaction so two people cannot both become the first admin.
        $locked = $conn->query("SELECT id FROM users WHERE role_id = 1 AND is_active = 1 LIMIT 1 FOR UPDATE");
        if ($locked && $locked->num_rows > 0) {
            $conn->rollback();
            header('Location: login.php');
            exit;
        }
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $check->bind_param('ss', $email, $username);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($existing) {
            // Re-activate an existing (disabled) account as the admin.
            $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ?, role_id = 1, is_active = 1, session_version = session_version + 1 WHERE id = ?');
            $stmt->bind_param('sssi', $username, $email, $hash, $existing['id']);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (username, email, password, role_id, is_active) VALUES (?, ?, ?, 1, 1)');
            $stmt->bind_param('sss', $username, $email, $hash);
        }
        if ($stmt->execute()) {
            $conn->commit();
            cr_settings_save($conn, $cleanSettings);
            header('Location: login.php?setup_done=1');
            exit;
        }
        $conn->rollback();
        error_log('First-run setup failed: ' . $stmt->error);
        $error = 'The admin account could not be created. Please try again.';
    }
}
?>
<?php cr_auth_start('First-time setup', true); ?>
<h1>Welcome — let's set up your system</h1>
<p class="lead">Create the main administrator account and enter your business details. You can change the business details later in Settings.</p>
<?= cr_alert('error', $error); ?>
<form method="post" autocomplete="off">
    <div class="form-section mt-0">
        <h3>Administrator account</h3>
        <div class="form-grid">
            <div class="field">
                <label for="username">Username <span class="req">*</span></label>
                <input type="text" id="username" name="username" value="<?= h($username); ?>" autocomplete="username" pattern="[A-Za-z0-9._\-]{3,50}" required>
                <span class="hint">3–50 letters, numbers, dots, dashes or underscores.</span>
            </div>
            <div class="field">
                <label for="email">Email <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="<?= h($email); ?>" autocomplete="email" required>
            </div>
            <div class="field">
                <label for="password">Password <span class="req">*</span></label>
                <input type="password" id="password" name="password" minlength="10" autocomplete="new-password" required>
                <span class="hint">At least 10 characters.</span>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm password <span class="req">*</span></label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="10" autocomplete="new-password" required>
            </div>
        </div>
    </div>
    <div class="form-section">
        <h3>Business</h3>
        <div class="form-grid">
            <div class="field span-all">
                <label for="company_name">Business name <span class="req">*</span></label>
                <input type="text" id="company_name" name="company_name" value="<?= h($business['company_name']); ?>" maxlength="120" required>
            </div>
            <div class="field">
                <label for="currency_code">Currency code <span class="req">*</span></label>
                <input type="text" id="currency_code" name="currency_code" value="<?= h($business['currency_code']); ?>" maxlength="3" pattern="[A-Za-z]{3}" placeholder="USD" required>
                <span class="hint">Three letters, e.g. USD, EUR, KES, TRY.</span>
            </div>
            <div class="field">
                <label for="currency_symbol">Currency symbol</label>
                <input type="text" id="currency_symbol" name="currency_symbol" value="<?= h($business['currency_symbol']); ?>" maxlength="8" placeholder="$">
            </div>
            <div class="field">
                <label for="timezone">Time zone <span class="req">*</span></label>
                <select id="timezone" name="timezone" required><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= h($tz); ?>"<?= $tz === $business['timezone'] ? ' selected' : ''; ?>><?= h(str_replace('_', ' ', $tz)); ?></option><?php endforeach; ?></select>
            </div>
            <div class="field">
                <label for="phone_country_code">Phone country code</label>
                <input type="text" id="phone_country_code" name="phone_country_code" value="<?= h($business['phone_country_code']); ?>" maxlength="4" inputmode="numeric" placeholder="1">
                <span class="hint">Digits only, e.g. 1, 44, 254.</span>
            </div>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn-block">Create administrator</button>
    </div>
</form>
<?php cr_auth_end(); ?>
