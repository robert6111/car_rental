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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?= h(cr_company_name()); ?></title>
    <style>
        .settings-wrap{max-width:980px}
        .settings-section{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 22px;margin-bottom:18px}
        .settings-section h3{margin:0 0 4px;font-size:1.1rem}
        .settings-section > p{margin:0 0 14px;color:#6b7280;font-size:.9rem}
        .settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px 18px}
        .settings-grid label{display:block;font-weight:600;font-size:.88rem;margin-bottom:5px}
        .settings-grid input[type=text],.settings-grid input[type=email],.settings-grid input[type=url],.settings-grid input[type=number],.settings-grid input[type=password],.settings-grid select{width:100%;box-sizing:border-box}
        .settings-grid .hint{display:block;color:#6b7280;font-size:.8rem;margin-top:4px;font-weight:400}
        .settings-grid .wide{grid-column:1/-1}
        .check{display:flex;align-items:center;gap:8px;font-weight:600}
        .field-error{display:block;color:#b91c1c;font-size:.82rem;margin-top:4px}
        .preview{background:#f3f4f6;border-radius:8px;padding:10px 12px;font-size:.9rem}
        .settings-actions{display:flex;gap:10px;flex-wrap:wrap;position:sticky;bottom:0;background:rgba(255,255,255,.95);padding:12px 0;border-top:1px solid #e5e7eb}
        .logo-preview{max-height:60px;display:block;margin:6px 0;border:1px solid #e5e7eb;border-radius:6px;padding:4px;background:#fff}
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
<div class="settings-wrap">
    <h2>System Settings</h2>
    <?php if ($msg): ?><p class="alert-success"><?= h($msg); ?></p><?php endif; ?>
    <?php if ($formError): ?><p class="alert-error"><?= h($formError); ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="inline-form" id="settingsForm">
        <input type="hidden" name="action" value="save">

        <section class="settings-section">
            <h3>Business details</h3>
            <p>Shown on contracts, PDF reports, emails and the online booking page.</p>
            <div class="settings-grid">
                <div><label for="company_name">Business name</label><input type="text" id="company_name" name="company_name" value="<?= h(sv('company_name')); ?>" maxlength="120" required><?= field_error('company_name'); ?></div>
                <div><label for="company_phone">Phone</label><input type="text" id="company_phone" name="company_phone" value="<?= h(sv('company_phone')); ?>" maxlength="60"></div>
                <div><label for="company_email">Email</label><input type="email" id="company_email" name="company_email" value="<?= h(sv('company_email')); ?>" maxlength="190"><?= field_error('company_email'); ?></div>
                <div><label for="company_website">Website</label><input type="text" id="company_website" name="company_website" value="<?= h(sv('company_website')); ?>" maxlength="190"></div>
                <div class="wide"><label for="company_address">Address</label><input type="text" id="company_address" name="company_address" value="<?= h(sv('company_address')); ?>" maxlength="255"></div>
                <div><label for="company_tax_id">Tax / registration number</label><input type="text" id="company_tax_id" name="company_tax_id" value="<?= h(sv('company_tax_id')); ?>" maxlength="80"></div>
                <div>
                    <label for="company_logo">Logo <span class="hint">PNG or JPG, used on contracts and PDF reports</span></label>
                    <?php if (cr_setting('company_logo') !== ''): ?>
                        <img src="<?= h(app_url('settings/logo.php')); ?>?v=<?= h(substr(md5(cr_setting('company_logo')), 0, 8)); ?>" alt="Current logo" class="logo-preview">
                        <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>
                    <?php endif; ?>
                    <input type="file" id="company_logo" name="company_logo" accept=".png,.jpg,.jpeg"><?= field_error('company_logo'); ?>
                </div>
                <div>
                    <label for="letterhead_pdf">Letterhead PDF (optional) <span class="hint">A4 page used as the background of PDF reports. Leave empty to use the business details above.</span></label>
                    <?php if (cr_setting('letterhead_pdf') !== ''): ?>
                        <label class="check"><input type="checkbox" name="remove_letterhead" value="1"> Remove current letterhead</label>
                    <?php endif; ?>
                    <input type="file" id="letterhead_pdf" name="letterhead_pdf" accept=".pdf"><?= field_error('letterhead_pdf'); ?>
                </div>
            </div>
        </section>

        <section class="settings-section">
            <h3>Region, currency and dates</h3>
            <p>Set these for the country where the business operates.</p>
            <div class="settings-grid">
                <div>
                    <label for="currency_code">Currency code</label>
                    <input type="text" id="currency_code" name="currency_code" value="<?= h(sv('currency_code')); ?>" maxlength="3" list="currencyList" required style="text-transform:uppercase">
                    <datalist id="currencyList"><?php foreach ($commonCurrencies as $code => $sym): ?><option value="<?= h($code); ?>"><?= h($sym); ?></option><?php endforeach; ?></datalist>
                    <span class="hint">3 letters, e.g. USD, EUR, TRY, KES</span><?= field_error('currency_code'); ?>
                </div>
                <div><label for="currency_symbol">Currency symbol</label><input type="text" id="currency_symbol" name="currency_symbol" value="<?= h(sv('currency_symbol')); ?>" maxlength="8"><span class="hint">e.g. $, €, ₺, KSh. Leave empty to show the code.</span></div>
                <div><label for="currency_position">Symbol position</label><select id="currency_position" name="currency_position"><?= select_options(['before' => 'Before the amount ($ 100)', 'after' => 'After the amount (100 $)'], sv('currency_position')); ?></select></div>
                <div><label for="currency_decimals">Decimal places</label><select id="currency_decimals" name="currency_decimals"><?= select_options(['0' => '0 (100)', '1' => '1 (100.5)', '2' => '2 (100.50)', '3' => '3 (100.500)'], sv('currency_decimals')); ?></select></div>
                <div><label for="decimal_separator">Decimal separator</label><select id="decimal_separator" name="decimal_separator"><?= select_options(['.' => 'Dot ( 10.50 )', ',' => 'Comma ( 10,50 )'], sv('decimal_separator')); ?></select></div>
                <div><label for="thousands_separator">Thousands separator</label><select id="thousands_separator" name="thousands_separator"><?= select_options($thousands, sv('thousands_separator')); ?></select><?= field_error('thousands_separator'); ?></div>
                <div><label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach ($timezones as $tz): ?><option value="<?= h($tz); ?>"<?= $tz === $currentTz ? ' selected' : ''; ?>><?= h(str_replace('_', ' ', $tz)); ?></option><?php endforeach; ?></select><?= field_error('timezone'); ?></div>
                <div><label for="date_format">Date format</label><select id="date_format" name="date_format"><?= select_options($dateFormats, sv('date_format')); ?></select></div>
                <div><label for="phone_country_code">Phone country code</label><input type="text" id="phone_country_code" name="phone_country_code" value="<?= h(sv('phone_country_code')); ?>" maxlength="4" inputmode="numeric" placeholder="e.g. 90, 254, 1"><span class="hint">Digits only, used for WhatsApp links. Local numbers starting with 0 get this code.</span></div>
                <div class="wide preview">Preview: <strong><?= money(1234567.891); ?></strong> &nbsp;·&nbsp; <strong><?= h(cr_date('2026-12-31')); ?></strong> &nbsp;·&nbsp; Current time: <strong><?= h(cr_date(date('Y-m-d H:i:s'), true)); ?></strong> <span class="hint">(updates after saving)</span></div>
            </div>
        </section>

        <section class="settings-section">
            <h3>Rental rules</h3>
            <p>Applied to new rentals and to unpaid balances.</p>
            <div class="settings-grid">
                <div><input type="hidden" name="late_interest_enabled__present" value="1"><label class="check"><input type="checkbox" name="late_interest_enabled" value="1"<?= sv('late_interest_enabled') === '1' ? ' checked' : ''; ?>> Charge late-payment interest</label></div>
                <div><label for="late_interest_daily_rate">Late interest per day (%)</label><input type="number" id="late_interest_daily_rate" name="late_interest_daily_rate" value="<?= h(sv('late_interest_daily_rate')); ?>" min="0" max="100" step="0.01"><span class="hint">Charged on the unpaid balance for each day after the rental end date.</span><?= field_error('late_interest_daily_rate'); ?></div>
                <div><input type="hidden" name="loyalty_enabled__present" value="1"><label class="check"><input type="checkbox" name="loyalty_enabled" value="1"<?= sv('loyalty_enabled') === '1' ? ' checked' : ''; ?>> Give loyalty discount</label></div>
                <div><label for="loyalty_min_rentals">Completed rentals needed</label><input type="number" id="loyalty_min_rentals" name="loyalty_min_rentals" value="<?= h(sv('loyalty_min_rentals')); ?>" min="1" max="1000" step="1"><?= field_error('loyalty_min_rentals'); ?></div>
                <div><label for="loyalty_discount_percent">Loyalty discount (%)</label><input type="number" id="loyalty_discount_percent" name="loyalty_discount_percent" value="<?= h(sv('loyalty_discount_percent')); ?>" min="0" max="100" step="0.01"><?= field_error('loyalty_discount_percent'); ?></div>
                <div><input type="hidden" name="public_booking_enabled__present" value="1"><label class="check"><input type="checkbox" name="public_booking_enabled" value="1"<?= sv('public_booking_enabled') === '1' ? ' checked' : ''; ?>> Online booking form is open to customers</label></div>
                <div><label for="authority_report_name">Authority for unreturned cars (optional)</label><input type="text" id="authority_report_name" name="authority_report_name" value="<?= h(sv('authority_report_name')); ?>" maxlength="60" placeholder="e.g. KABIS, Police"><span class="hint">Shown in the "Not Returned" case pages.</span></div>
            </div>
        </section>

        <section class="settings-section">
            <h3>Email</h3>
            <p>Used for password-reset links and notifications. Ask your email or hosting provider for these SMTP details.</p>
            <div class="settings-grid">
                <div><label for="smtp_host">SMTP server</label><input type="text" id="smtp_host" name="smtp_host" value="<?= h(sv('smtp_host')); ?>" placeholder="e.g. smtp.gmail.com"></div>
                <div><label for="smtp_port">Port</label><input type="number" id="smtp_port" name="smtp_port" value="<?= h(sv('smtp_port')); ?>" min="1" max="65535"><?= field_error('smtp_port'); ?></div>
                <div><label for="smtp_encryption">Security</label><select id="smtp_encryption" name="smtp_encryption"><?= select_options(['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', '' => 'None'], sv('smtp_encryption')); ?></select></div>
                <div><label for="smtp_username">Username</label><input type="text" id="smtp_username" name="smtp_username" value="<?= h(sv('smtp_username')); ?>" autocomplete="off"></div>
                <div><label for="smtp_password">Password</label><input type="password" id="smtp_password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= cr_setting('smtp_password') !== '' ? 'Saved - leave empty to keep' : ''; ?>"></div>
                <div><label for="mail_from_email">Sender email</label><input type="email" id="mail_from_email" name="mail_from_email" value="<?= h(sv('mail_from_email')); ?>"><?= field_error('mail_from_email'); ?></div>
                <div><label for="mail_from_name">Sender name</label><input type="text" id="mail_from_name" name="mail_from_name" value="<?= h(sv('mail_from_name')); ?>" placeholder="<?= h(cr_company_name()); ?>"></div>
                <div class="wide"><span class="hint">Email status: <strong><?= cr_mail_configured() ? 'configured' : 'not configured'; ?></strong>. Password-reset emails also need the System address below.</span></div>
            </div>
        </section>

        <section class="settings-section">
            <h3>System address</h3>
            <p>The web address people use to open this system. Needed for links in emails and for sending the online booking link to customers.</p>
            <div class="settings-grid">
                <div class="wide"><label for="app_url">Address of the backend folder</label><input type="url" id="app_url" name="app_url" value="<?= h(sv('app_url')); ?>" placeholder="e.g. https://rent.example.com/backend or http://192.168.1.20/car_rental/backend"><span class="hint">This browser is using: <?= h(cr_absolute_url('', false)); ?></span><?= field_error('app_url'); ?></div>
            </div>
        </section>

        <div class="settings-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>
    </form>

    <form method="post" class="inline-form" style="margin-top:8px">
        <input type="hidden" name="action" value="test_email">
        <button type="submit" class="btn">Send test email to me</button>
    </form>
</div>
</div>
</body>
</html>
