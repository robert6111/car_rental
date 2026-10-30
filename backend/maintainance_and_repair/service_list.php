<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
$q = trim((string)($_GET['q'] ?? ''));
$where='WHERE 1'; $types=''; $params=[];
if($q!==''){ $like='%'.$q.'%'; $where .= " AND (cars.reg_no LIKE ? OR makes.name LIKE ? OR models.name LIKE ? OR cs.service_type LIKE ?)"; $types='ssss'; $params=[$like,$like,$like,$like]; }
$fromSql=" FROM car_service cs LEFT JOIN cars ON cs.car_id=cars.id LEFT JOIN makes ON cars.make_id=makes.id LEFT JOIN models ON cars.model_id=models.id $where";
$pg=cr_page_count($conn, cr_page(), "SELECT COUNT(*)".$fromSql, $types, $params);
$stmt=$conn->prepare("SELECT cs.*, cars.reg_no, makes.name AS make_name, models.name AS model_name".$fromSql." ORDER BY cs.service_date DESC, cs.id DESC".cr_page_sql($pg));
if($types!=='') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result=$stmt->get_result();
$stmt->close();
?>
<?php cr_layout_start('Service records'); ?>
<?= cr_page_header('Service records', 'Service and maintenance history for every car.', '<a class="btn" href="service.php">' . cr_icon('plus') . ' Add service record</a>'); ?>

<form method="GET" class="filters">
    <div class="field grow">
        <label for="q">Search car / reg no / service</label>
        <input type="text" id="q" name="q" value="<?= h($q); ?>" placeholder="Oil change, 34 ABC 123">
    </div>
    <button type="submit"><?= cr_icon('search'); ?> Search</button>
    <a class="btn btn-secondary" href="service_list.php">Clear</a>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>All service records</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Car</th><th>Service type</th><th>Description</th><th>Date</th><th class="num">Mileage</th><th class="num">Next service</th><th class="num">Cost</th></tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows): $i = $pg['offset'] + 1; while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $i++; ?></td>
                    <td><span class="cell-title"><?= h(trim(($row['make_name'] ?? '') . ' ' . ($row['model_name'] ?? ''))) ?></span><span class="cell-sub"><span class="plate"><?= h($row['reg_no'] ?? 'N/A') ?></span></span></td>
                    <td><?= h($row['service_type']) ?></td>
                    <td><?= h($row['description']) ?></td>
                    <td class="nowrap"><?= h(cr_date($row['service_date'])) ?></td>
                    <td class="num"><?= h($row['mileage_at_service']) ?></td>
                    <td class="num"><?= h($row['next_service_mileage']) ?></td>
                    <td class="num"><?= money((float)$row['cost']) ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="table-empty">No service records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
