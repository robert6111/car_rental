<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rental_status.php';

sync_rental_and_car_status($conn);

// The scheduled task (cron/run.php) normally makes the daily backup. If it has not run for
// a day and a half, the dashboard makes it instead so backups never stop.
$scheduledTaskRunning = (int)cr_setting('scheduled_run_at') > time() - 36 * 3600;
if (!$scheduledTaskRunning && cr_setting('auto_backup_on') !== cr_today()) {
    require_once __DIR__ . '/backup/auto_backup.php';
    run_daily_auto_backup($conn);
}

/** Runs a fixed SELECT COUNT(*) query (no user input) and returns the number. */
function count_rows(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_row();
    return (int)($row[0] ?? 0);
}

$canRentals  = stock_has_permission('rentals_manage');
$canPayments = stock_has_permission('payments_manage') || stock_has_permission('reports_view');
$canOffice   = stock_has_permission('office_manage');

// Fleet by status (one query instead of one per status).
$fleet = ['Available' => 0, 'Rented' => 0, 'Reserved' => 0, 'Maintenance' => 0, 'Not Returned' => 0];
$res = $conn->query("SELECT availability_status AS s, COUNT(*) AS n FROM cars GROUP BY availability_status");
while ($res && $row = $res->fetch_assoc()) {
    $fleet[$row['s']] = ($fleet[$row['s']] ?? 0) + (int)$row['n'];
}
$totalCars = array_sum($fleet);

$totalCustomers    = count_rows($conn, "SELECT COUNT(*) FROM customers");
$activeRentals     = count_rows($conn, "SELECT COUNT(*) FROM rentals WHERE status = 'Active'");
$totalReservations = count_rows($conn, "SELECT COUNT(*) FROM reservations WHERE status IN ('Pending','Confirmed','Waitlisted')");
$unlinkedRequests  = count_rows($conn, "SELECT COUNT(*) FROM reservations WHERE source = 'Online' AND customer_id IS NULL AND status IN ('Pending','Waitlisted','Confirmed')");
$totalAlerts       = count_rows($conn, "SELECT COUNT(*) FROM notifications WHERE status = 'Ready'");
$openTasks         = count_rows($conn, "SELECT COUNT(*) FROM staff_tasks WHERE status IN ('Open','In Progress')");
$openCases         = count_rows($conn, "SELECT COUNT(*) FROM not_returned_cases WHERE status IN ('Open','Followed Up','Reported')");
// Same-day rentals whose car has not been confirmed as returned yet.
$sameDayToConfirm  = count_rows($conn, "SELECT COUNT(*) FROM rentals WHERE status IN ('Active','Completed') AND returned_at IS NULL AND start_date = end_date AND end_date <= CURDATE()");

// Today: cars going out and cars due back.
$today = cr_today();
$todayList = function (string $where) use ($conn): array {
    $sql = "SELECT r.id, r.start_date, r.end_date, r.status, r.returned_at, c.full_name, ca.reg_no
            FROM rentals r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN cars ca ON ca.id = r.cars_id
            WHERE $where ORDER BY r.id LIMIT 8";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
};
$goingOut = $todayList("r.start_date = CURDATE() AND r.status IN ('Pending','Active')");
$dueBack  = $todayList("r.returned_at IS NULL AND r.status IN ('Active','Completed') AND r.end_date <= CURDATE()");

// Last 6 months: rentals started and money received (two separate charts, never one dual-axis chart).
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime(date('Y-m-01', strtotime($today)) . " -$i months"));
    $months[$key] = ['rentals' => 0, 'paid' => 0.0];
}
$from = array_key_first($months) . '-01';
$stmt = $conn->prepare("SELECT DATE_FORMAT(start_date, '%Y-%m') AS ym, COUNT(*) AS n FROM rentals WHERE start_date >= ? AND status NOT IN ('Canceled','Deleted') GROUP BY ym");
$stmt->bind_param('s', $from);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if (isset($months[$row['ym']])) $months[$row['ym']]['rentals'] = (int)$row['n'];
}
$stmt->close();
if ($canPayments) {
    $stmt = $conn->prepare("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount) AS total FROM payment p JOIN rentals r ON r.id = p.rental_id WHERE p.payment_date >= ? AND r.status <> 'Deleted' GROUP BY ym");
    $stmt->bind_param('s', $from);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (isset($months[$row['ym']])) $months[$row['ym']]['paid'] = (float)$row['total'];
    }
    $stmt->close();
}

$recentRentals = $canRentals ? $conn->query("
    SELECT r.*, c.full_name AS customer_name, ca.reg_no AS car_reg
    FROM rentals r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN cars ca ON r.cars_id = ca.id
    WHERE r.status <> 'Deleted'
    ORDER BY r.id DESC
    LIMIT 6
") : false;

/** Status shown for a rental: a finished rental whose car is still out reads "Not returned". */
function dashboard_rental_status(array $r): string {
    if (in_array($r['status'], ['Active', 'Completed'], true) && empty($r['returned_at']) && $r['end_date'] < cr_today()) return 'Not returned';
    return (string)$r['status'];
}

/** Single-series bar chart (CSS only). $values: label => number. Latest value is labelled; others show on hover. */
function dashboard_bar_chart(array $values, callable $format, string $caption): string {
    if (array_sum($values) <= 0) {
        return '<div class="empty-state">' . cr_icon('chart') . '<p class="mb-0">Nothing recorded in the last 6 months yet.</p></div>';
    }
    $max = max(1, max($values));
    $html = '<figure class="mb-0"><div class="bar-chart" style="--bars:' . count($values) . '" role="img" aria-label="' . h($caption) . '">';
    $keys = array_keys($values);
    $last = end($keys);
    foreach ($values as $label => $v) {
        $pct = $v > 0 ? max(2, round($v / $max * 100, 1)) : 0;
        $text = $format($v);
        $html .= '<div class="bar-col" title="' . h($label . ': ' . $text) . '">'
            . '<span class="bar-value' . ($label === $last ? '' : ' on-hover') . '">' . h($text) . '</span>'
            . '<div class="bar" style="height:' . $pct . '%"></div>'
            . '<span class="bar-label">' . h($label) . '</span></div>';
    }
    return $html . '</div></figure>';
}

$monthLabel = fn(string $ym) => date('M', strtotime($ym . '-01'));
$rentalSeries = [];
$paidSeries = [];
foreach ($months as $ym => $m) {
    $rentalSeries[$monthLabel($ym)] = $m['rentals'];
    $paidSeries[$monthLabel($ym)] = $m['paid'];
}

$fleetTones = ['Available' => 'success', 'Rented' => 'info', 'Reserved' => 'purple', 'Maintenance' => 'warning', 'Not Returned' => 'danger'];

$headActions = '';
if ($canRentals) $headActions .= '<a class="btn" href="' . h(app_url('rentals/add.php')) . '">' . cr_icon('plus') . ' New rental</a>';
$headActions .= '<a class="btn btn-secondary" href="' . h(app_url('cars/cars_inventory.php')) . '">' . cr_icon('car') . ' Available cars</a>';

cr_layout_start('Dashboard', ['head' => '<style>
.bar-value.on-hover{visibility:hidden}.bar-col:hover .bar-value.on-hover{visibility:visible}
.bar-col:hover .bar{filter:brightness(1.12)}
.fleet-bar > span.t-success{background:var(--success)}.fleet-bar > span.t-info{background:var(--info)}.fleet-bar > span.t-purple{background:var(--purple)}.fleet-bar > span.t-warning{background:var(--warning)}.fleet-bar > span.t-danger{background:var(--danger)}
.legend i.t-success{background:var(--success)}.legend i.t-info{background:var(--info)}.legend i.t-purple{background:var(--purple)}.legend i.t-warning{background:var(--warning)}.legend i.t-danger{background:var(--danger)}
.today-list{list-style:none;margin:0;padding:0}.today-list li{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border);font-size:14px}.today-list li:last-child{border-bottom:0}
.fleet-total{font-size:2rem;font-weight:700;line-height:1;font-variant-numeric:tabular-nums}
</style>']);
?>

<?php if (stock_has_permission('settings_manage') && cr_setting('settings_reviewed') !== '1'): ?>
    <?= cr_alert('warning', '<strong>Finish setting up your system.</strong> Check the business name, currency, timezone and phone code in <a href="' . h(app_url('settings/index.php')) . '">Settings</a>. This message disappears after you save them.', true); ?>
<?php endif; ?>
<?php if (stock_has_permission('settings_manage') && !$scheduledTaskRunning): ?>
    <?= cr_alert('info', '<strong>The scheduled task is not set up.</strong> Daily backups currently run when someone opens this dashboard. Set up <code>cron/run-scheduled.bat</code> (Windows) or <code>cron/run.php</code> (Linux) to run every hour, as described in <code>backend/database/INSTALLATION_NOTES.txt</code>.', true); ?>
<?php endif; ?>
<?php if ($canRentals && $unlinkedRequests > 0): ?>
    <?= cr_alert('info', '<strong>' . $unlinkedRequests . ' online booking request' . ($unlinkedRequests === 1 ? '' : 's') . '</strong> waiting to be linked to a customer. <a href="' . h(app_url('reservations/manage.php')) . '">Open reservations</a>', true); ?>
<?php endif; ?>

<?= cr_page_header('Dashboard', cr_company_name() . ' · ' . cr_date($today), $headActions); ?>

<div class="stats">
    <a class="stat tone-success" href="<?= h(app_url('cars/cars_inventory.php')); ?>"><div class="stat-icon"><?= cr_icon('car'); ?></div><div><div class="stat-label">Cars available</div><div class="stat-value"><?= cr_number($fleet['Available'], 0); ?></div></div></a>
    <?php if ($canRentals): ?>
    <a class="stat" href="<?= h(app_url('rentals/active.php')); ?>"><div class="stat-icon"><?= cr_icon('calendar'); ?></div><div><div class="stat-label">Active rentals</div><div class="stat-value"><?= cr_number($activeRentals, 0); ?></div></div></a>
    <a class="stat <?= $fleet['Not Returned'] > 0 ? 'tone-danger' : 'tone-neutral'; ?>" href="<?= h(app_url('rentals/not_returned.php')); ?>"><div class="stat-icon"><?= cr_icon('alert'); ?></div><div><div class="stat-label">Cars not returned</div><div class="stat-value"><?= cr_number($fleet['Not Returned'], 0); ?></div></div></a>
    <a class="stat tone-purple" href="<?= h(app_url('reservations/manage.php')); ?>"><div class="stat-icon"><?= cr_icon('calendar-check'); ?></div><div><div class="stat-label">Open reservations</div><div class="stat-value"><?= cr_number($totalReservations, 0); ?></div></div></a>
    <?php endif; ?>
    <?php if (stock_has_permission('customers_manage')): ?>
    <a class="stat tone-neutral" href="<?= h(app_url('customers/customer.php')); ?>"><div class="stat-icon"><?= cr_icon('users'); ?></div><div><div class="stat-label">Customers</div><div class="stat-value"><?= cr_number($totalCustomers, 0); ?></div></div></a>
    <?php endif; ?>
    <?php if ($canOffice): ?>
    <a class="stat <?= $openCases > 0 ? 'tone-danger' : 'tone-neutral'; ?>" href="<?= h(app_url('rentals/not_returned.php')); ?>"><div class="stat-icon"><?= cr_icon('shield'); ?></div><div><div class="stat-label">Open not-returned cases</div><div class="stat-value"><?= cr_number($openCases, 0); ?></div></div></a>
    <a class="stat tone-warning" href="<?= h(app_url('tasks/manage.php')); ?>"><div class="stat-icon"><?= cr_icon('clipboard'); ?></div><div><div class="stat-label">Open tasks</div><div class="stat-value"><?= cr_number($openTasks, 0); ?></div></div></a>
    <a class="stat tone-warning" href="<?= h(app_url('notifications/alerts.php')); ?>"><div class="stat-icon"><?= cr_icon('bell'); ?></div><div><div class="stat-label">Alerts ready to send</div><div class="stat-value"><?= cr_number($totalAlerts, 0); ?></div></div></a>
    <?php endif; ?>
    <?php if ($canRentals && $sameDayToConfirm > 0): ?>
    <a class="stat tone-danger" href="<?= h(app_url('rentals/manage.php')); ?>"><div class="stat-icon"><?= cr_icon('clock'); ?></div><div><div class="stat-label">Same-day returns to confirm</div><div class="stat-value"><?= cr_number($sameDayToConfirm, 0); ?></div></div></a>
    <?php endif; ?>
</div>

<div class="grid grid-2 mb-4">
    <section class="card">
        <div class="card-head"><h2>Fleet status</h2><span class="muted"><?= cr_number($totalCars, 0); ?> <?= $totalCars === 1 ? 'car' : 'cars'; ?></span></div>
        <?php if ($totalCars > 0): ?>
            <div class="split-bar fleet-bar" role="img" aria-label="Fleet by status">
                <?php foreach ($fleet as $status => $n): if ($n <= 0) continue; ?>
                    <span class="t-<?= $fleetTones[$status] ?? 'info'; ?>" style="width:<?= round($n / $totalCars * 100, 2); ?>%" title="<?= h($status . ': ' . $n); ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="legend">
                <?php foreach ($fleet as $status => $n): ?>
                    <span><i class="t-<?= $fleetTones[$status] ?? 'info'; ?>"></i><?= h($status === 'Not Returned' ? 'Not returned' : $status); ?> <b><?= cr_number($n, 0); ?></b></span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><?= cr_icon('car'); ?><p class="mb-0">No cars yet. <?php if (stock_has_permission('cars_manage')): ?><a href="<?= h(app_url('cars/add.php')); ?>">Add your first car</a>.<?php endif; ?></p></div>
        <?php endif; ?>
    </section>

    <?php if ($canRentals): ?>
    <section class="card">
        <div class="card-head"><h2>Today</h2><span class="muted"><?= h(cr_date($today)); ?></span></div>
        <div class="grid grid-2">
            <div>
                <h3 class="section-title">Going out (<?= count($goingOut); ?>)</h3>
                <?php if ($goingOut): ?><ul class="today-list"><?php foreach ($goingOut as $r): ?>
                    <li><span><span class="cell-title"><?= h($r['full_name'] ?? 'N/A'); ?></span><span class="cell-sub">Rental #<?= (int)$r['id']; ?></span></span><span class="plate"><?= h($r['reg_no'] ?? '-'); ?></span></li>
                <?php endforeach; ?></ul><?php else: ?><p class="muted mb-0">No pickups today.</p><?php endif; ?>
            </div>
            <div>
                <h3 class="section-title">Due back (<?= count($dueBack); ?>)</h3>
                <?php if ($dueBack): ?><ul class="today-list"><?php foreach ($dueBack as $r): $late = $r['end_date'] < $today; ?>
                    <li><span><span class="cell-title"><?= h($r['full_name'] ?? 'N/A'); ?></span><span class="cell-sub"><?= $late ? '<span class="text-danger">Overdue since ' . h(cr_date($r['end_date'])) . '</span>' : 'Due today'; ?></span></span><span class="plate"><?= h($r['reg_no'] ?? '-'); ?></span></li>
                <?php endforeach; ?></ul><?php else: ?><p class="muted mb-0">Nothing due back.</p><?php endif; ?>
            </div>
        </div>
        <?php if ($dueBack): ?><div class="mt-3"><a href="<?= h(app_url('rentals/manage.php')); ?>">Confirm returns in All rentals &rarr;</a></div><?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<div class="grid <?= $canPayments ? 'grid-2' : ''; ?> mb-4">
    <section class="card">
        <div class="card-head"><h2>Rentals started</h2><span class="muted">Last 6 months</span></div>
        <?= dashboard_bar_chart($rentalSeries, fn($v) => cr_number($v, 0), 'Rentals started per month, last 6 months'); ?>
    </section>
    <?php if ($canPayments): ?>
    <section class="card">
        <div class="card-head"><h2>Payments received</h2><span class="muted">Last 6 months</span></div>
        <?= dashboard_bar_chart($paidSeries, fn($v) => money_text($v), 'Payments received per month, last 6 months'); ?>
    </section>
    <?php endif; ?>
</div>

<?php if ($canRentals): ?>
<section class="card card-flush">
    <div class="card-head"><h2>Recent rentals</h2><a class="btn btn-secondary btn-sm" href="<?= h(app_url('rentals/manage.php')); ?>">View all</a></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Customer</th><th>Car</th><th>Start</th><th>End</th><th class="num">Cost</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($recentRentals && $recentRentals->num_rows > 0): ?>
                <?php while ($rental = $recentRentals->fetch_assoc()): $st = dashboard_rental_status($rental); ?>
                    <tr>
                        <td class="muted"><?= (int)$rental['id']; ?></td>
                        <td><span class="cell-title"><?= h($rental['customer_name'] ?? 'N/A'); ?></span></td>
                        <td><span class="plate"><?= h($rental['car_reg'] ?? 'N/A'); ?></span></td>
                        <td class="nowrap"><?= h(cr_date($rental['start_date'])); ?></td>
                        <td class="nowrap"><?= h(cr_date($rental['end_date'])); ?></td>
                        <td class="num"><?= money((float)$rental['total_cost']); ?></td>
                        <td><?= cr_status_badge($st); ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="table-empty">No rentals yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php cr_layout_end(); ?>
