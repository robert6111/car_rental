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
<?php cr_layout_start('Add customer'); ?>
<a class="back-link" href="customer.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All customers</a>
<?= cr_page_header('Add customer', 'Register a customer before creating a rental for them.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <h2 class="section-title">Personal details</h2>
    <div class="form-grid">
        <div class="field">
            <label for="full_name">Full name <span class="req">*</span></label>
            <input type="text" id="full_name" name="full_name" maxlength="255" autocomplete="name" value="<?= $v('full_name') ?>" required>
        </div>
        <div class="field">
            <label for="phone_number">Phone number <span class="req">*</span></label>
            <input type="tel" id="phone_number" name="phone_number" maxlength="50" autocomplete="tel" value="<?= $v('phone_number') ?>" required>
        </div>
        <div class="field">
            <label for="email">Email <span class="opt">(optional)</span></label>
            <input type="email" id="email" name="email" maxlength="255" autocomplete="email" value="<?= $v('email') ?>">
        </div>
        <div class="field span-all">
            <label for="address">Address <span class="opt">(optional)</span></label>
            <textarea id="address" name="address" rows="2" maxlength="255"><?= $v('address') ?></textarea>
        </div>
    </div>

    <div class="form-section">
        <h3>Licence and identity</h3>
        <div class="form-grid">
            <div class="field">
                <label for="license_no">Driver licence number <span class="req">*</span></label>
                <input type="text" id="license_no" name="license_no" maxlength="100" value="<?= $v('license_no') ?>" required>
            </div>
            <div class="field">
                <label for="license_expiry">Licence expiry</label>
                <input type="date" id="license_expiry" name="license_expiry" value="<?= $v('license_expiry') ?>">
            </div>
            <div class="field">
                <label for="id_number">ID number</label>
                <input type="text" id="id_number" name="id_number" maxlength="100" value="<?= $v('id_number') ?>">
            </div>
            <div class="field">
                <label for="passport_no">Passport number</label>
                <input type="text" id="passport_no" name="passport_no" maxlength="100" value="<?= $v('passport_no') ?>">
            </div>
            <div class="field">
                <label for="passport_expiry">Passport expiry</label>
                <input type="date" id="passport_expiry" name="passport_expiry" value="<?= $v('passport_expiry') ?>">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit"><?= cr_icon('check'); ?> Save customer</button>
        <a class="btn btn-secondary" href="customer.php">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
