<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/send_email.php';

$msg = '';
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'save') {
        [$clean, $errors] = cr_settings_validate($_POST);

        // Logo (PNG/JPG only, so it also works in PDFs) and letterhead (PDF).
        $fileUpdates = [];
        if (!empty($_FILES['company_logo']['name'])) {
            $stored = cr_store_upload($_FILES['company_logo'], 'branding', ['image/png', 'image/jpeg']);
            if (isset($stored['error'])) $errors['company_logo'] = $stored['error'];
            else $fileUpdates['company_logo'] = $stored['path'];
        }
        if (!empty($_FILES['letterhead_pdf']['name'])) {
            $stored = cr_store_upload($_FILES['letterhead_pdf'], 'branding', ['application/pdf']);
            if (isset($stored['error'])) $errors['letterhead_pdf'] = $stored['error'];
            else $fileUpdates['letterhead_pdf'] = $stored['path'];
        }
        if (!empty($_POST['remove_logo'])) $fileUpdates['company_logo'] = '';
        if (!empty($_POST['remove_letterhead'])) $fileUpdates['letterhead_pdf'] = '';

        if ($errors) {
            // Do not keep newly uploaded files when the form has errors.
            foreach ($fileUpdates as $path) if ($path !== '') @unlink(cr_storage_path($path));
            $formError = 'Some settings need attention. Please check the highlighted fields.';
        } else {
            foreach ($fileUpdates as $key => $path) {
                $old = cr_setting($key);
                if ($old !== '' && $old !== $path) @unlink(cr_storage_path($old));
            }
            cr_settings_save($conn, $clean + $fileUpdates + ['settings_reviewed' => '1']);
            header('Location: index.php?saved=1');
            exit;
        }
    } elseif ($action === 'test_email') {
        $to = (string)($_SESSION['user_email'] ?? '');
        $stmt = $conn->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $to = (string)($stmt->get_result()->fetch_assoc()['email'] ?? '');
        $stmt->close();
        if (!cr_mail_configured()) {
            $formError = 'Email is not configured yet. Fill in the Email section and save first.';
        } elseif (!stock_rate_limit('test_email', 5, 600)) {
            $formError = 'Too many test emails. Please wait a few minutes.';
        } elseif (sendEmail($to, 'Test email from ' . cr_company_name(), '<p>This is a test email. Email sending is working.</p>')) {
            $msg = 'Test email sent to ' . $to . '.';
        } else {
            $formError = 'The test email could not be sent: ' . cr_mail_last_error();
        }
    }
}

if (isset($_GET['saved'])) $msg = 'Settings saved.';

// Values shown in the form: submitted values after an error, otherwise saved values.
function sv(string $key): string {
    global $errors;
    if ($errors && isset($_POST[$key]) && !is_array($_POST[$key])) return (string)$_POST[$key];
    return cr_setting($key);
}
function field_error(string $key): string {
    global $errors;
    return isset($errors[$key]) ? '<span class="field-error">' . h($errors[$key]) . '</span>' : '';
}
function select_options(array $options, string $current): string {
    $out = '';
    foreach ($options as $value => $label) {
        $out .= '<option value="' . h($value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

$timezones = DateTimeZone::listIdentifiers();
$currentTz = sv('timezone') !== '' ? sv('timezone') : date_default_timezone_get();
$commonCurrencies = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'TRY' => '₺', 'KES' => 'KSh', 'TZS' => 'TSh', 'UGX' => 'USh', 'RWF' => 'FRw', 'NGN' => '₦', 'GHS' => 'GH₵', 'ZAR' => 'R', 'EGP' => 'E£', 'MAD' => 'DH', 'AED' => 'AED', 'SAR' => 'SAR', 'QAR' => 'QAR', 'INR' => '₹', 'PKR' => 'Rs', 'CAD' => 'C$', 'AUD' => 'A$', 'CHF' => 'CHF', 'JPY' => '¥', 'CNY' => '¥', 'BRL' => 'R$', 'MXN' => 'MX$'];
$dateFormats = ['Y-m-d' => '2026-12-31', 'd/m/Y' => '31/12/2026', 'm/d/Y' => '12/31/2026', 'd.m.Y' => '31.12.2026', 'd-m-Y' => '31-12-2026'];
$thousands = [',' => 'Comma ( 1,000 )', '.' => 'Dot ( 1.000 )', ' ' => 'Space ( 1 000 )', "'" => "Apostrophe ( 1'000 )", '' => 'None ( 1000 )'];
?>
<?php cr_layout_start('Settings', ['head' => '<style>.logo-preview{max-height:60px;display:block;margin:0 0 8px;border:1px solid var(--border);border-radius:6px;padding:4px;background:#fff}.settings-actions{z-index:5;border-top:1px solid var(--border)}</style>']); ?>
<?= cr_page_header('Settings', 'Business details, money and date formats, rental rules, email and backups.'); ?>
<?= cr_alerts($msg, $formError); ?>

<form method="post" enctype="multipart/form-data" id="settingsForm">
    <input type="hidden" name="action" value="save">

    <section class="card">
        <h2 class="section-title mb-0">Business details</h2>
        <p class="page-sub mb-4">Shown on contracts, PDF reports, emails and the online booking page.</p>
        <div class="form-grid">
            <div class="field"><label for="company_name">Business name <span class="req">*</span></label><input type="text" id="company_name" name="company_name" value="<?= h(sv('company_name')); ?>" maxlength="120" required><?= field_error('company_name'); ?></div>
            <div class="field"><label for="company_phone">Phone</label><input type="text" id="company_phone" name="company_phone" value="<?= h(sv('company_phone')); ?>" maxlength="60"></div>
            <div class="field"><label for="company_email">Email</label><input type="email" id="company_email" name="company_email" value="<?= h(sv('company_email')); ?>" maxlength="190"><?= field_error('company_email'); ?></div>
            <div class="field"><label for="company_website">Website</label><input type="text" id="company_website" name="company_website" value="<?= h(sv('company_website')); ?>" maxlength="190"></div>
            <div class="field"><label for="company_tax_id">Tax / registration number</label><input type="text" id="company_tax_id" name="company_tax_id" value="<?= h(sv('company_tax_id')); ?>" maxlength="80"></div>
            <div class="field span-all"><label for="company_address">Address</label><input type="text" id="company_address" name="company_address" value="<?= h(sv('company_address')); ?>" maxlength="255"></div>
            <div class="field">
                <label for="company_logo">Logo</label>
                <?php if (cr_setting('company_logo') !== ''): ?>
                    <img src="<?= h(app_url('settings/logo.php')); ?>?v=<?= h(substr(md5(cr_setting('company_logo')), 0, 8)); ?>" alt="Current logo" class="logo-preview">
                    <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>
                <?php endif; ?>
                <input type="file" id="company_logo" name="company_logo" accept=".png,.jpg,.jpeg">
                <span class="hint">PNG or JPG, used on contracts and PDF reports.</span><?= field_error('company_logo'); ?>
            </div>
            <div class="field">
                <label for="letterhead_pdf">Letterhead PDF <span class="opt">(optional)</span></label>
                <?php if (cr_setting('letterhead_pdf') !== ''): ?>
                    <label class="check"><input type="checkbox" name="remove_letterhead" value="1"> Remove current letterhead</label>
                <?php endif; ?>
                <input type="file" id="letterhead_pdf" name="letterhead_pdf" accept=".pdf">
                <span class="hint">A4 page used as the background of PDF reports. Leave empty to use the business details above.</span><?= field_error('letterhead_pdf'); ?>
            </div>
        </div>
    </section>

    <section class="card">
        <h2 class="section-title mb-0">Region, currency and dates</h2>
        <p class="page-sub mb-4">Set these for the country where the business operates.</p>
        <div class="form-grid">
            <div class="field">
                <label for="currency_code">Currency code <span class="req">*</span></label>
                <input type="text" id="currency_code" name="currency_code" value="<?= h(sv('currency_code')); ?>" maxlength="3" list="currencyList" required style="text-transform:uppercase">
                <datalist id="currencyList"><?php foreach ($commonCurrencies as $code => $sym): ?><option value="<?= h($code); ?>"><?= h($sym); ?></option><?php endforeach; ?></datalist>
                <span class="hint">3 letters, e.g. USD, EUR, TRY, KES.</span><?= field_error('currency_code'); ?>
            </div>
            <div class="field"><label for="currency_symbol">Currency symbol</label><input type="text" id="currency_symbol" name="currency_symbol" value="<?= h(sv('currency_symbol')); ?>" maxlength="8"><span class="hint">e.g. $, €, ₺, KSh. Leave empty to show the code.</span></div>
            <div class="field"><label for="currency_position">Symbol position</label><select id="currency_position" name="currency_position"><?= select_options(['before' => 'Before the amount ($ 100)', 'after' => 'After the amount (100 $)'], sv('currency_position')); ?></select></div>
            <div class="field"><label for="currency_decimals">Decimal places</label><select id="currency_decimals" name="currency_decimals"><?= select_options(['0' => '0 (100)', '1' => '1 (100.5)', '2' => '2 (100.50)', '3' => '3 (100.500)'], sv('currency_decimals')); ?></select></div>
            <div class="field"><label for="decimal_separator">Decimal separator</label><select id="decimal_separator" name="decimal_separator"><?= select_options(['.' => 'Dot ( 10.50 )', ',' => 'Comma ( 10,50 )'], sv('decimal_separator')); ?></select></div>
            <div class="field"><label for="thousands_separator">Thousands separator</label><select id="thousands_separator" name="thousands_separator"><?= select_options($thousands, sv('thousands_separator')); ?></select><?= field_error('thousands_separator'); ?></div>
            <div class="field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach ($timezones as $tz): ?><option value="<?= h($tz); ?>"<?= $tz === $currentTz ? ' selected' : ''; ?>><?= h(str_replace('_', ' ', $tz)); ?></option><?php endforeach; ?></select><?= field_error('timezone'); ?></div>
            <div class="field"><label for="date_format">Date format</label><select id="date_format" name="date_format"><?= select_options($dateFormats, sv('date_format')); ?></select></div>
            <div class="field"><label for="phone_country_code">Phone country code</label><input type="text" id="phone_country_code" name="phone_country_code" value="<?= h(sv('phone_country_code')); ?>" maxlength="4" inputmode="numeric" placeholder="e.g. 90, 254, 1"><span class="hint">Digits only, used for WhatsApp links. Local numbers starting with 0 get this code.</span></div>
            <div class="field span-all"><?= cr_alert('info', 'Preview: <strong>' . money(1234567.891) . '</strong> · <strong>' . h(cr_date('2026-12-31')) . '</strong> · Current time: <strong>' . h(cr_date(date('Y-m-d H:i:s'), true)) . '</strong> <span class="muted">(updates after saving)</span>', true); ?></div>
        </div>
    </section>

    <section class="card">
        <h2 class="section-title mb-0">Rental rules</h2>
        <p class="page-sub mb-4">Applied to new rentals and to unpaid balances.</p>
        <div class="form-grid">
            <div class="field"><input type="hidden" name="late_interest_enabled__present" value="1"><label class="check"><input type="checkbox" name="late_interest_enabled" value="1"<?= sv('late_interest_enabled') === '1' ? ' checked' : ''; ?>> Charge late-payment interest</label></div>
            <div class="field"><label for="late_interest_daily_rate">Late interest per day (%)</label><input type="number" id="late_interest_daily_rate" name="late_interest_daily_rate" value="<?= h(sv('late_interest_daily_rate')); ?>" min="0" max="100" step="0.01"><span class="hint">Charged on the unpaid balance for each day after the rental end date.</span><?= field_error('late_interest_daily_rate'); ?></div>
            <div class="field"><input type="hidden" name="loyalty_enabled__present" value="1"><label class="check"><input type="checkbox" name="loyalty_enabled" value="1"<?= sv('loyalty_enabled') === '1' ? ' checked' : ''; ?>> Give loyalty discount</label></div>
            <div class="field"><label for="loyalty_min_rentals">Completed rentals needed</label><input type="number" id="loyalty_min_rentals" name="loyalty_min_rentals" value="<?= h(sv('loyalty_min_rentals')); ?>" min="1" max="1000" step="1"><?= field_error('loyalty_min_rentals'); ?></div>
            <div class="field"><label for="loyalty_discount_percent">Loyalty discount (%)</label><input type="number" id="loyalty_discount_percent" name="loyalty_discount_percent" value="<?= h(sv('loyalty_discount_percent')); ?>" min="0" max="100" step="0.01"><?= field_error('loyalty_discount_percent'); ?></div>
            <div class="field"><input type="hidden" name="public_booking_enabled__present" value="1"><label class="check"><input type="checkbox" name="public_booking_enabled" value="1"<?= sv('public_booking_enabled') === '1' ? ' checked' : ''; ?>> Online booking form is open to customers</label></div>
            <div class="field"><label for="authority_report_name">Authority for unreturned cars <span class="opt">(optional)</span></label><input type="text" id="authority_report_name" name="authority_report_name" value="<?= h(sv('authority_report_name')); ?>" maxlength="60" placeholder="e.g. KABIS, Police"><span class="hint">Shown in the "Not returned" case pages.</span></div>
        </div>
    </section>

    <section class="card">
        <div class="card-head mb-0">
            <h2>Email</h2>
            <div class="actions"><?= cr_mail_configured() ? cr_status_badge('Active', 'Configured') : cr_status_badge('Pending', 'Not configured'); ?></div>
        </div>
        <p class="page-sub mb-4">Used for password-reset links and notifications. Ask your email or hosting provider for these SMTP details.</p>
        <div class="form-grid">
            <div class="field"><label for="smtp_host">SMTP server</label><input type="text" id="smtp_host" name="smtp_host" value="<?= h(sv('smtp_host')); ?>" placeholder="e.g. smtp.gmail.com"></div>
            <div class="field"><label for="smtp_port">Port</label><input type="number" id="smtp_port" name="smtp_port" value="<?= h(sv('smtp_port')); ?>" min="1" max="65535"><?= field_error('smtp_port'); ?></div>
            <div class="field"><label for="smtp_encryption">Security</label><select id="smtp_encryption" name="smtp_encryption"><?= select_options(['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', '' => 'None'], sv('smtp_encryption')); ?></select></div>
            <div class="field"><label for="smtp_username">Username</label><input type="text" id="smtp_username" name="smtp_username" value="<?= h(sv('smtp_username')); ?>" autocomplete="off"></div>
            <div class="field"><label for="smtp_password">Password</label><input type="password" id="smtp_password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= cr_setting('smtp_password') !== '' ? 'Saved - leave empty to keep' : ''; ?>"></div>
            <div class="field"><label for="mail_from_email">Sender email</label><input type="email" id="mail_from_email" name="mail_from_email" value="<?= h(sv('mail_from_email')); ?>"><?= field_error('mail_from_email'); ?></div>
            <div class="field"><label for="mail_from_name">Sender name</label><input type="text" id="mail_from_name" name="mail_from_name" value="<?= h(sv('mail_from_name')); ?>" placeholder="<?= h(cr_company_name()); ?>"></div>
        </div>
        <div class="row-flex mt-4">
            <button type="submit" form="testEmailForm" class="btn btn-secondary"><?= cr_icon('mail'); ?> Send test email to me</button>
            <span class="hint mt-0">Save your changes first. Password-reset emails also need the System address below.</span>
        </div>
    </section>

    <section class="card">
        <h2 class="section-title mb-0">System address</h2>
        <p class="page-sub mb-4">The web address people use to open this system. Needed for links in emails and for sending the online booking link to customers.</p>
        <div class="form-grid">
            <div class="field span-all"><label for="app_url">Address of the backend folder</label><input type="url" id="app_url" name="app_url" value="<?= h(sv('app_url')); ?>" placeholder="e.g. https://rent.example.com/backend or http://192.168.1.20/car_rental/backend"><span class="hint">This browser is using: <?= h(cr_absolute_url('', false)); ?></span><?= field_error('app_url'); ?></div>
        </div>
    </section>

    <section class="card">
        <h2 class="section-title mb-0">Lists and backups</h2>
        <?php $lastRun = (int)cr_setting('scheduled_run_at'); ?>
        <p class="page-sub mb-4">Daily jobs (status updates, late interest, backup): <?php if ($lastRun > 0): ?>last run by the scheduled task on <strong><?= h(cr_date(date('Y-m-d', $lastRun)) . ' ' . date('H:i', $lastRun)); ?></strong>.<?php else: ?><strong>the scheduled task has not run yet</strong>. The system still works, but the daily backup then runs when someone opens the dashboard. See <code>backend/database/INSTALLATION_NOTES.txt</code> to set it up.<?php endif; ?></p>
        <div class="form-grid">
            <div class="field"><label for="rows_per_page">Rows per page in lists</label><select id="rows_per_page" name="rows_per_page"><?php foreach (['25', '50', '100', '200'] as $n): ?><option value="<?= $n; ?>"<?= sv('rows_per_page') === $n ? ' selected' : ''; ?>><?= $n; ?></option><?php endforeach; ?></select><?= field_error('rows_per_page'); ?></div>
            <div class="field"><label for="backup_keep_days">Keep automatic backups for (days)</label><input type="number" id="backup_keep_days" name="backup_keep_days" value="<?= h(sv('backup_keep_days')); ?>" min="1" max="3650" step="1"><span class="hint">Older automatic backups are deleted. Manual backups are kept.</span><?= field_error('backup_keep_days'); ?></div>
        </div>
    </section>

    <div class="settings-actions">
        <button type="submit"><?= cr_icon('check'); ?> Save settings</button>
    </div>
</form>

<form method="post" id="testEmailForm">
    <input type="hidden" name="action" value="test_email">
</form>
<?php cr_layout_end(); ?>
