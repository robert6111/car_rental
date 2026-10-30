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
<!DOCTYPE html><html><head><title>Analytics</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2>Reporting & Analytics</h2><form method="GET" class="filter-box"><div><label>From</label><input type="date" name="from_date" value="<?=h($from)?>"></div><div><label>To</label><input type="date" name="to_date" value="<?=h($to)?>"></div><button>Apply</button></form><p style="color:#64748b;font-size:13px;">Rentals overlapping <?=h(cr_date($from))?> to <?=h(cr_date($to))?> (canceled excluded). Payments, interest and expenses are counted by their own date.</p><div class="row"><div class="card-box"><p>Rentals</p><h2><?=h($summary['rentals'])?></h2></div><div class="card-box"><p>Rental Revenue</p><h2><?=money((float)$summary['revenue'])?></h2></div><div class="card-box"><p>Late Interest Charged</p><h2><?=money((float)$interest['charged'])?></h2></div><div class="card-box"><p>Payments Collected</p><h2><?=money((float)$payments['paid'])?></h2></div><div class="card-box"><p>Expenses</p><h2><?=money((float)$expenses['total_expense'])?></h2></div><div class="card-box"><p>Estimated Profit (payments - expenses)</p><h2><?=money($profit)?></h2></div></div><h3>Vehicle Utilization & Profitability</h3><table class="table" border="1" cellpadding="10"><tr><th>Car</th><th>Rental Count</th><th>Revenue</th></tr><?php if($util&&$util->num_rows):while($u=$util->fetch_assoc()):?><tr><td><?=h(car_display_name($u))?></td><td><?=h($u['rental_count'])?></td><td><?=money((float)$u['revenue'])?></td></tr><?php endwhile;endif;?></table><h3>Overdue Cars Not Returned (as of today)</h3><table class="table" border="1" cellpadding="10"><tr><th>Rental</th><th>Car</th><th>Customer</th><th>End Date</th><th>Days Late</th></tr><?php if($overdue&&$overdue->num_rows):while($o=$overdue->fetch_assoc()):?><tr><td>#<?=(int)$o['id']?></td><td><?=h(car_display_name($o))?></td><td><?=h($o['full_name'] ?? 'N/A')?></td><td><?=h(cr_date($o['end_date']))?></td><td><?=(int)$o['days_late']?></td></tr><?php endwhile;else:?><tr><td colspan="5">No overdue unreturned cars.</td></tr><?php endif;?></table><h3>Damage Incidents</h3><table class="table" border="1" cellpadding="10"><tr><th>Car</th><th>Damage Count</th><th>Damage Cost</th></tr><?php if($incidents&&$incidents->num_rows):while($i=$incidents->fetch_assoc()):?><tr><td><?=h($i['reg_no'])?></td><td><?=h($i['damage_count'])?></td><td><?=money((float)$i['damage_cost'])?></td></tr><?php endwhile;else:?><tr><td colspan="3">No damage incidents in this period.</td></tr><?php endif;?></table></div></body></html>
