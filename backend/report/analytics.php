<?php
require_once __DIR__ . '/../includes/admin_only.php'; require __DIR__ . '/../includes/db.php'; require_once __DIR__ . '/../includes/rental_status.php'; sync_rental_and_car_status($conn);

$from = (string)($_GET['from_date'] ?? '');
$to = (string)($_GET['to_date'] ?? '');
if (!cr_valid_date($from)) $from = date('Y-m-01');
if (!cr_valid_date($to)) $to = cr_today();
if ($from > $to) { [$from, $to] = [$to, $from]; }

/** Runs a prepared query and returns the mysqli_result. */
$run = function (string $sql, string $types = '', array $params = []) use ($conn) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
};

// Rentals that overlap the period; canceled and deleted rentals do not count as revenue.
$summary = $run("SELECT COUNT(*) rentals, IFNULL(SUM(total_cost),0) revenue FROM rentals WHERE status NOT IN ('Canceled','Deleted') AND start_date <= ? AND end_date >= ?", 'ss', [$to, $from])->fetch_assoc();
$interest = $run("SELECT IFNULL(SUM(rc.amount),0) charged FROM rental_charges rc JOIN rentals r ON r.id = rc.rental_id WHERE rc.charge_type = 'late_interest' AND r.status NOT IN ('Canceled','Deleted') AND rc.charge_date BETWEEN ? AND ?", 'ss', [$from, $to])->fetch_assoc();
$payments = $run("SELECT IFNULL(SUM(p.amount),0) paid FROM payment p JOIN rentals r ON r.id = p.rental_id WHERE r.status <> 'Deleted' AND p.payment_date BETWEEN ? AND ?", 'ss', [$from, $to])->fetch_assoc();
$expenses = $run("SELECT IFNULL(SUM(amount),0) total_expense FROM expenses WHERE expense_date BETWEEN ? AND ?", 'ss', [$from, $to])->fetch_assoc();
$util = $run("SELECT ca.reg_no,m.name AS make_name,mo.name AS model_name,y.year AS car_year,COUNT(r.id) rental_count,IFNULL(SUM(r.total_cost),0) revenue FROM cars ca LEFT JOIN rentals r ON r.cars_id=ca.id AND r.status NOT IN ('Canceled','Deleted') AND r.start_date <= ? AND r.end_date >= ? LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id GROUP BY ca.id,ca.reg_no,m.name,mo.name,y.year ORDER BY rental_count DESC,revenue DESC", 'ss', [$to, $from]);
$overdue = $run("SELECT r.id, r.end_date, DATEDIFF(CURDATE(), r.end_date) days_late, c.full_name, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM rentals r JOIN cars ca ON r.cars_id=ca.id LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id WHERE r.status='Completed' AND r.returned_at IS NULL AND r.end_date < CURDATE() ORDER BY r.end_date ASC");
$incidents = $run("SELECT ca.reg_no,COUNT(d.id) damage_count,IFNULL(SUM(COALESCE(d.repair_cost,d.estimated_cost,0)),0) damage_cost FROM car_damages d JOIN cars ca ON d.car_id=ca.id WHERE d.date_reported BETWEEN ? AND ? GROUP BY ca.id,ca.reg_no ORDER BY damage_count DESC", 'ss', [$from, $to]);
$profit = (float)$payments['paid'] - (float)$expenses['total_expense'];
?>
<?php
$utilRows = $util ? $util->fetch_all(MYSQLI_ASSOC) : [];
$maxCount = 0;
foreach ($utilRows as $u) $maxCount = max($maxCount, (int)$u['rental_count']);
cr_layout_start('Analytics');
?>
<?= cr_page_header('Reporting and analytics', 'Revenue, payments, expenses and fleet use for a period.'); ?>

<div class="card">
    <form method="GET" class="filters mb-0">
        <div class="field">
            <label for="from_date">From</label>
            <input type="date" id="from_date" name="from_date" value="<?= h($from); ?>">
        </div>
        <div class="field">
            <label for="to_date">To</label>
            <input type="date" id="to_date" name="to_date" value="<?= h($to); ?>">
        </div>
        <button type="submit">Apply</button>
        <a class="btn btn-secondary" href="analytics.php">Clear</a>
    </form>
    <p class="hint mb-0">Rentals overlapping <?= h(cr_date($from)); ?> to <?= h(cr_date($to)); ?> (canceled excluded). Payments, interest and expenses are counted by their own date.</p>
</div>

<div class="stats">
    <div class="stat"><div><div class="stat-label">Rentals</div><div class="stat-value"><?= h($summary['rentals']); ?></div></div></div>
    <div class="stat"><div><div class="stat-label">Rental revenue</div><div class="stat-value"><?= money((float)$summary['revenue']); ?></div></div></div>
    <div class="stat tone-warning"><div><div class="stat-label">Late interest charged</div><div class="stat-value"><?= money((float)$interest['charged']); ?></div></div></div>
    <div class="stat tone-success"><div><div class="stat-label">Payments collected</div><div class="stat-value"><?= money((float)$payments['paid']); ?></div></div></div>
    <div class="stat tone-danger"><div><div class="stat-label">Expenses</div><div class="stat-value"><?= money((float)$expenses['total_expense']); ?></div></div></div>
    <div class="stat tone-purple"><div><div class="stat-label">Estimated profit (payments − expenses)</div><div class="stat-value"><?= money($profit); ?></div></div></div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Vehicle utilization and profitability</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Car</th><th class="num">Rentals</th><th style="width:30%"><span class="sr-only">Share of rentals</span></th><th class="num">Revenue</th></tr></thead>
            <tbody>
            <?php if ($utilRows): foreach ($utilRows as $u): $pct = $maxCount > 0 ? round((int)$u['rental_count'] / $maxCount * 100) : 0; ?>
                <tr>
                    <td><?= h(car_display_name($u)); ?></td>
                    <td class="num"><?= h($u['rental_count']); ?></td>
                    <td><div class="progress"><span style="width:<?= (int)$pct; ?>%"></span></div></td>
                    <td class="num"><?= money((float)$u['revenue']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="table-empty">No cars yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Overdue cars not returned (as of today)</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Rental</th><th>Car</th><th>Customer</th><th>End date</th><th class="num">Days late</th></tr></thead>
            <tbody>
            <?php if ($overdue && $overdue->num_rows): while ($o = $overdue->fetch_assoc()): ?>
                <tr>
                    <td>#<?= (int)$o['id']; ?></td>
                    <td><?= h(car_display_name($o)); ?></td>
                    <td><?= h($o['full_name'] ?? 'N/A'); ?></td>
                    <td class="nowrap"><?= h(cr_date($o['end_date'])); ?></td>
                    <td class="num"><?= cr_status_badge('Overdue', (int)$o['days_late'] . ' days'); ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="5" class="table-empty">No overdue unreturned cars.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Damage incidents</h2></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Car</th><th class="num">Damages</th><th class="num">Damage cost</th></tr></thead>
            <tbody>
            <?php if ($incidents && $incidents->num_rows): while ($i = $incidents->fetch_assoc()): ?>
                <tr>
                    <td><span class="plate"><?= h($i['reg_no']); ?></span></td>
                    <td class="num"><?= h($i['damage_count']); ?></td>
                    <td class="num"><?= money((float)$i['damage_cost']); ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="3" class="table-empty">No damage incidents in this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php cr_layout_end(); ?>
