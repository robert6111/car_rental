<?php
require_once __DIR__ . '/../includes/auth.php';
include __DIR__ . '/../includes/db.php';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0; if ($id <= 0) die("Invalid damage ID.");
$result = $conn->query("SELECT cd.*, cars.reg_no, makes.name AS make_name, models.name AS model_name, r.id AS rental_no, r.start_date, r.end_date, r.status AS rental_status, c.full_name AS renter_name, c.phone_number AS renter_phone, c.email AS renter_email FROM car_damages cd LEFT JOIN cars ON cd.car_id=cars.id LEFT JOIN makes ON cars.make_id=makes.id LEFT JOIN models ON cars.model_id=models.id LEFT JOIN rentals r ON cd.rental_id=r.id LEFT JOIN customers c ON r.customer_id=c.id WHERE cd.id=$id");
if (!$result || $result->num_rows === 0) { $_SESSION['error'] = "Damage record not found."; header("Location: list_damage.php"); exit; }
$damage = $result->fetch_assoc();
?>
<?php cr_layout_start('Damage details'); ?>
<a class="back-link" href="list_damage.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All damages</a>
<?= cr_page_header('Damage #' . (int)$damage['id'], 'Damage report details and repair status.', '<a class="btn" href="edit_damage.php?id=' . (int)$damage['id'] . '">' . cr_icon('edit') . ' Edit</a>'); ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Damage</h2></div>
        <dl class="details">
            <dt>Car</dt><dd><?= h(trim($damage['make_name'] . ' ' . $damage['model_name'])) ?> <span class="plate"><?= h($damage['reg_no']) ?></span></dd>
            <dt>Description</dt><dd><?= nl2br(h($damage['damage_description'])) ?></dd>
            <dt>Severity</dt><dd><?= cr_status_badge((string)$damage['severity'], ucfirst((string)$damage['severity'])) ?></dd>
            <dt>Estimated cost</dt><dd><?= money((float)$damage['estimated_cost']) ?></dd>
            <dt>Date reported</dt><dd><?= h(cr_date($damage['date_reported'])) ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h2>Renter and repair</h2></div>
        <dl class="details">
            <dt>Connected renter</dt><dd><?= h($damage['renter_name'] ?? 'Not linked') ?><?php if (!empty($damage['renter_phone'])): ?><span class="cell-sub"><?= h($damage['renter_phone']) ?></span><?php endif; ?><?php if (!empty($damage['renter_email'])): ?><span class="cell-sub"><?= h($damage['renter_email']) ?></span><?php endif; ?></dd>
            <?php if ($damage['rental_no']): ?>
            <dt>Rental</dt><dd>#<?= h($damage['rental_no']) ?> (<?= h(cr_date($damage['start_date'])) ?> to <?= h(cr_date($damage['end_date'])) ?>) <?= cr_status_badge((string)$damage['rental_status']) ?></dd>
            <?php endif; ?>
            <dt>Repaired</dt><dd><?= cr_status_badge($damage['repaired'] ? 'Yes' : 'No') ?></dd>
            <?php if ($damage['repaired']): ?>
            <dt>Repair cost</dt><dd><?= money((float)$damage['repair_cost']) ?></dd>
            <dt>Repair date</dt><dd><?= h(cr_date($damage['repair_date'])) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>
<?php cr_layout_end(); ?>
