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

/** Runs a fixed SELECT query (no user input) and returns the first value as a number. */
function count_rows(mysqli $conn, string $sql): float {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_row();
    return (float)($row[0] ?? 0);
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

$activeRentals     = (int)count_rows($conn, "SELECT COUNT(*) FROM rentals WHERE status = 'Active'");
$totalReservations = (int)count_rows($conn, "SELECT COUNT(*) FROM reservations WHERE status IN ('Pending','Confirmed','Waitlisted')");
$unlinkedRequests  = (int)count_rows($conn, "SELECT COUNT(*) FROM reservations WHERE source = 'Online' AND customer_id IS NULL AND status IN ('Pending','Waitlisted','Confirmed')");
$totalAlerts       = (int)count_rows($conn, "SELECT COUNT(*) FROM notifications WHERE status = 'Ready'");
$openTasks         = (int)count_rows($conn, "SELECT COUNT(*) FROM staff_tasks WHERE status IN ('Open','In Progress')");
$openCases         = (int)count_rows($conn, "SELECT COUNT(*) FROM not_returned_cases WHERE status IN ('Open','Followed Up','Reported')");
// Same-day rentals whose car has not been confirmed as returned yet.
$sameDayToConfirm  = (int)count_rows($conn, "SELECT COUNT(*) FROM rentals WHERE status IN ('Active','Completed') AND returned_at IS NULL AND start_date = end_date AND end_date <= CURDATE()");

// Money still owed on open and finished rentals (rental cost + late interest - payments).
$outstanding = 0.0;
if ($canPayments) {
    $billable = "'" . implode("','", CR_RENTAL_BILLABLE_STATUSES) . "'";
    $outstanding = count_rows($conn, "SELECT COALESCE(SUM(GREATEST(r.total_cost + " . cr_sql_late_interest('r') . " - " . cr_sql_total_paid('r') . ", 0)), 0)
        FROM rentals r WHERE r.status IN ($billable)");
}

// Today: cars going out and cars due back.
$today = cr_today();
$todayList = function (string $where) use ($conn): array {
    $sql = "SELECT r.id, r.start_date, r.end_date, r.status, r.returned_at, c.full_name, ca.reg_no
            FROM rentals r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN cars ca ON ca.id = r.cars_id
            WHERE $where ORDER BY r.end_date, r.id LIMIT 8";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
};
$goingOut = $canRentals ? $todayList("r.start_date = CURDATE() AND r.status IN ('Pending','Active')") : [];
$dueBack  = $canRentals ? $todayList("r.returned_at IS NULL AND r.status IN ('Active','Completed') AND r.end_date <= CURDATE()") : [];

// Last 6 months: rentals started and money received.
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
    LIMIT 8
") : false;

/** Status shown for a rental: a finished rental whose car is still out reads "Not returned". */
function dashboard_rental_status(array $r): string {
    if (in_array($r['status'], ['Active', 'Completed'], true) && empty($r['returned_at']) && $r['end_date'] < cr_today()) return 'Not returned';
    return (string)$r['status'];
}

// Short list of things someone should act on. Only items with a count above zero are shown.
$attention = [];
if ($canRentals) {
    $attention[] = [$unlinkedRequests, 'Online booking requests to link to a customer', 'reservations/manage.php'];
    $attention[] = [$sameDayToConfirm, 'Same-day returns to confirm', 'rentals/manage.php'];
}
if ($canOffice) {
    $attention[] = [$openCases, 'Open not-returned cases', 'rentals/not_returned.php'];
    $attention[] = [$openTasks, 'Open staff tasks', 'tasks/manage.php'];
    $attention[] = [$totalAlerts, 'Alerts ready to send', 'notifications/alerts.php'];
}
$attention = array_filter($attention, fn($a) => $a[0] > 0);

$headActions = '';
if ($canRentals) $headActions .= '<a class="btn" href="' . h(app_url('rentals/add.php')) . '">New rental</a>';
$headActions .= '<a class="btn btn-secondary" href="' . h(app_url('cars/cars_inventory.php')) . '">Available cars</a>';

cr_layout_start('Overview', ['head' => '<style>
.overview { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 24px; align-items: start; }
.overview > div > .card:last-child { margin-bottom: 0; }
@media (max-width: 991px) { .overview { grid-template-columns: minmax(0, 1fr); } }
</style>']);
?>

<?php if (stock_has_permission('settings_manage') && cr_setting('settings_reviewed') !== '1'): ?>
    <?= cr_alert('warning', '<strong>Finish setting up your system.</strong> Check the business name, currency, timezone and phone code in <a href="' . h(app_url('settings/index.php')) . '">Settings</a>. This message disappears after you save them.', true); ?>
<?php endif; ?>
<?php if (stock_has_permission('settings_manage') && !$scheduledTaskRunning): ?>
    <?= cr_alert('info', '<strong>The scheduled task is not set up.</strong> Daily backups currently run when someone opens this page. Set up <code>cron/run-scheduled.bat</code> (Windows) or <code>cron/run.php</code> (Linux) to run every hour, as described in <code>backend/database/INSTALLATION_NOTES.txt</code>.', true); ?>
<?php endif; ?>

<?= cr_page_header('Overview', date('l', strtotime($today)) . ', ' . cr_date($today), $headActions); ?>

<div class="stats">
    <a class="stat" href="<?= h(app_url('cars/cars_inventory.php')); ?>">
        <div class="stat-label">Cars available</div>
        <div class="stat-value"><?= cr_number($fleet['Available'], 0); ?></div>
        <div class="stat-note">of <?= cr_number($totalCars, 0); ?> in the fleet</div>
    </a>
    <?php if ($canRentals): ?>
    <a class="stat" href="<?= h(app_url('rentals/active.php')); ?>">
        <div class="stat-label">Active rentals</div>
        <div class="stat-value"><?= cr_number($activeRentals, 0); ?></div>
        <div class="stat-note"><?= count($goingOut); ?> going out today</div>
    </a>
    <a class="stat<?= $fleet['Not Returned'] > 0 ? ' tone-danger' : ''; ?>" href="<?= h(app_url('rentals/not_returned.php')); ?>">
        <div class="stat-label">Not returned</div>
        <div class="stat-value"><?= cr_number($fleet['Not Returned'], 0); ?></div>
        <div class="stat-note">past the end date</div>
    </a>
    <a class="stat" href="<?= h(app_url('reservations/manage.php')); ?>">
        <div class="stat-label">Open reservations</div>
        <div class="stat-value"><?= cr_number($totalReservations, 0); ?></div>
        <div class="stat-note">pending, confirmed or waitlisted</div>
    </a>
    <?php endif; ?>
    <?php if ($canPayments): ?>
    <a class="stat" href="<?= h(app_url(stock_has_permission('payments_manage') ? 'paying/manage.php' : 'report/payment_report.php')); ?>">
        <div class="stat-label">Outstanding (<?= h(cr_setting('currency_code')); ?>)</div>
        <div class="stat-value"><?= cr_number($outstanding); ?></div>
        <div class="stat-note">owed on open rentals</div>
    </a>
    <?php endif; ?>
</div>

<div class="overview">
    <div>
        <?php if ($canRentals): ?>
        <section class="card card-flush">
            <div class="card-head"><h2>Due back</h2><span class="muted"><?= count($dueBack); ?> <?= count($dueBack) === 1 ? 'car' : 'cars'; ?></span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Customer</th><th>Car</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($dueBack as $r): $late = $r['end_date'] < $today; ?>
                        <tr>
                            <td><a class="cell-title" href="<?= h(app_url('rentals/edit.php?id=' . (int)$r['id'])); ?>"><?= h($r['full_name'] ?? 'N/A'); ?></a></td>
                            <td><span class="plate"><?= h($r['reg_no'] ?? '-'); ?></span></td>
                            <td class="nowrap"><?= h(cr_date($r['end_date'])); ?></td>
                            <td><?= $late ? cr_status_badge('Not returned') : '<span class="muted">Due today</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$dueBack): ?><tr><td colspan="4" class="table-empty">Nothing due back today.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($dueBack): ?><div class="cr-pager"><a href="<?= h(app_url('rentals/manage.php')); ?>">Confirm returns in All rentals</a></div><?php endif; ?>
        </section>

        <section class="card card-flush">
            <div class="card-head"><h2>Going out today</h2><span class="muted"><?= count($goingOut); ?> <?= count($goingOut) === 1 ? 'car' : 'cars'; ?></span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Customer</th><th>Car</th><th>Return</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($goingOut as $r): ?>
                        <tr>
                            <td><a class="cell-title" href="<?= h(app_url('rentals/edit.php?id=' . (int)$r['id'])); ?>"><?= h($r['full_name'] ?? 'N/A'); ?></a></td>
                            <td><span class="plate"><?= h($r['reg_no'] ?? '-'); ?></span></td>
                            <td class="nowrap"><?= h(cr_date($r['end_date'])); ?></td>
                            <td><?= cr_status_badge((string)$r['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$goingOut): ?><tr><td colspan="4" class="table-empty">No pickups today.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card card-flush">
            <div class="card-head"><h2>Recent rentals</h2><a href="<?= h(app_url('rentals/manage.php')); ?>">View all</a></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>No.</th><th>Customer</th><th>Car</th><th>Period</th><th class="num">Cost</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($recentRentals && $recentRentals->num_rows > 0): ?>
                        <?php while ($rental = $recentRentals->fetch_assoc()): ?>
                            <tr>
                                <td class="muted"><?= (int)$rental['id']; ?></td>
                                <td><span class="cell-title"><?= h($rental['customer_name'] ?? 'N/A'); ?></span></td>
                                <td><span class="plate"><?= h($rental['car_reg'] ?? 'N/A'); ?></span></td>
                                <td class="nowrap"><?= h(cr_date($rental['start_date'])); ?> to <?= h(cr_date($rental['end_date'])); ?></td>
                                <td class="num"><?= money((float)$rental['total_cost']); ?></td>
                                <td><?= cr_status_badge(dashboard_rental_status($rental)); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="table-empty">No rentals yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php else: ?>
        <section class="card">
            <div class="card-head"><h2>Rentals started</h2><span class="muted">Last 6 months</span></div>
            <ul class="plain-list">
                <?php foreach ($months as $ym => $m): ?>
                    <li><span><?= h(date('F Y', strtotime($ym . '-01'))); ?></span><span class="count"><?= cr_number($m['rentals'], 0); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($canRentals || $canOffice): ?>
        <section class="card">
            <div class="card-head"><h2>Needs attention</h2></div>
            <?php if ($attention): ?>
                <ul class="plain-list">
                    <?php foreach ($attention as [$n, $label, $path]): ?>
                        <li><a href="<?= h(app_url($path)); ?>"><?= h($label); ?></a><span class="count"><?= cr_number($n, 0); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted mb-0">Nothing needs attention right now.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head"><h2>Fleet</h2><span class="muted"><?= cr_number($totalCars, 0); ?> <?= $totalCars === 1 ? 'car' : 'cars'; ?></span></div>
            <?php if ($totalCars > 0): ?>
                <ul class="plain-list">
                    <?php foreach ($fleet as $status => $n): ?>
                        <li><span><?= h($status === 'Not Returned' ? 'Not returned' : $status); ?></span><span class="count"><?= cr_number($n, 0); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted mb-0">No cars yet.<?php if (stock_has_permission('cars_manage')): ?> <a href="<?= h(app_url('cars/add.php')); ?>">Add your first car</a>.<?php endif; ?></p>
            <?php endif; ?>
        </section>

        <?php if ($canRentals): ?>
        <section class="card card-flush">
            <div class="card-head"><h2>Last 6 months</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Month</th><th class="num">Rentals</th><?php if ($canPayments): ?><th class="num">Received</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($months, true) as $ym => $m): ?>
                        <tr>
                            <td class="nowrap"><?= h(date('M Y', strtotime($ym . '-01'))); ?></td>
                            <td class="num"><?= cr_number($m['rentals'], 0); ?></td>
                            <?php if ($canPayments): ?>
                            <td class="num"><?= cr_number($m['paid']); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php cr_layout_end(); ?>
