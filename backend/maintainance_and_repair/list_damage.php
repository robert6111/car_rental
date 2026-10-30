<?php
require_once __DIR__ . '/../includes/auth.php';
include __DIR__ . '/../includes/db.php';
$filter_severity = (string)($_GET['severity'] ?? ''); $filter_repaired = (string)($_GET['repaired'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
$from = " FROM car_damages cd LEFT JOIN cars ON cd.car_id=cars.id LEFT JOIN makes ON cars.make_id=makes.id LEFT JOIN models ON cars.model_id=models.id LEFT JOIN rentals r ON cd.rental_id=r.id LEFT JOIN customers c ON r.customer_id=c.id WHERE 1";
$types = ''; $params = [];
if ($filter_severity !== '') { $from .= " AND cd.severity=?"; $types .= 's'; $params[] = $filter_severity; }
if ($filter_repaired !== '') { $from .= " AND cd.repaired=?"; $types .= 's'; $params[] = $filter_repaired; }
if ($q !== '') { $like = '%' . $q . '%'; $from .= " AND (cars.reg_no LIKE ? OR c.full_name LIKE ?)"; $types .= 'ss'; $params[] = $like; $params[] = $like; }
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*)" . $from, $types, $params);
$stmt = $conn->prepare("SELECT cd.*, cars.reg_no, makes.name AS make_name, models.name AS model_name, r.id AS rental_no, r.start_date, r.end_date, c.full_name AS renter_name, c.phone_number AS renter_phone" . $from . " ORDER BY cd.id DESC" . cr_page_sql($pg));
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$damages = $stmt->get_result();
$stmt->close();
?>
<?php cr_layout_start('Damages'); ?>
<?= cr_page_header('Damages', 'Damage reports linked to cars and the rentals they came back from.', '<a class="btn" href="damage.php">' . cr_icon('plus') . ' Report damage</a>'); ?>
<?php foreach (['success' => 'success', 'error' => 'error'] as $flashKey => $flashType): if (!empty($_SESSION[$flashKey])): ?><?= cr_alert($flashType, (string)$_SESSION[$flashKey]); ?><?php unset($_SESSION[$flashKey]); endif; endforeach; ?>

<form method="GET" class="filters">
    <div class="field grow">
        <label for="q">Search reg no / renter</label>
        <input type="text" id="q" name="q" value="<?= h($q); ?>" placeholder="Reg no or customer name">
    </div>
    <div class="field">
        <label for="f_severity">Severity</label>
        <select id="f_severity" name="severity">
            <option value="">All</option>
            <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'moderate' => 'Moderate', 'high' => 'High'] as $v => $label): ?>
            <option value="<?= h($v) ?>" <?= $filter_severity === $v ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="f_repaired">Repaired?</label>
        <select id="f_repaired" name="repaired">
            <option value="">All</option>
            <option value="0" <?= $filter_repaired === '0' ? 'selected' : '' ?>>No</option>
            <option value="1" <?= $filter_repaired === '1' ? 'selected' : '' ?>>Yes</option>
        </select>
    </div>
    <button type="submit"><?= cr_icon('search'); ?> Filter</button>
    <a class="btn btn-secondary" href="list_damage.php">Clear</a>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>All damages</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Car</th><th>Renter / rental</th><th>Severity</th><th class="num">Estimated cost</th><th>Repaired</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($damages && $damages->num_rows > 0): while ($row = $damages->fetch_assoc()): ?>
                <tr>
                    <td><?= h($row['id']) ?></td>
                    <td><span class="cell-title"><?= h(trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? ''))) ?></span> <span class="plate"><?= h($row['reg_no'] ?? 'N/A') ?></span></td>
                    <td><?= h($row['renter_name'] ?? 'Not linked') ?><?php if ($row['rental_no']): ?><span class="cell-sub">Rental #<?= h($row['rental_no']) ?>: <?= h(cr_date($row['start_date'])) ?> to <?= h(cr_date($row['end_date'])) ?></span><?php endif; ?></td>
                    <td><?= cr_status_badge((string)$row['severity'], ucfirst((string)$row['severity'])) ?></td>
                    <td class="num"><?= money((float)$row['estimated_cost']) ?></td>
                    <td><?= cr_status_badge($row['repaired'] ? 'Yes' : 'No') ?></td>
                    <td class="actions-cell">
                        <a class="btn btn-sm btn-secondary" href="view_damage.php?id=<?= h($row['id']) ?>"><?= cr_icon('eye', 'icon-sm'); ?> View</a>
                        <a class="btn btn-sm btn-secondary" href="edit_damage.php?id=<?= h($row['id']) ?>"><?= cr_icon('edit', 'icon-sm'); ?> Edit</a>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7" class="table-empty">No damages found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
