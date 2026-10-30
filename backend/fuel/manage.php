<?php
require_once __DIR__ . '/../includes/auth.php'; require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
$msg=''; $error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $car_id=(int)($_POST['car_id']??0);
    $date=trim((string)($_POST['log_date']??''));
    $mileageInput=trim((string)($_POST['mileage']??''));
    $mileage=null;
    $liters=cr_parse_money($_POST['liters']??'');
    $cost=cr_parse_money($_POST['cost']??'');
    $station=trim((string)($_POST['fuel_station']??''));
    $notes=trim((string)($_POST['notes']??''));
    $user=isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
    if($car_id<=0) $error='Please select a car.';
    elseif(!cr_valid_date($date)) $error='Please enter a valid date.';
    elseif($date>cr_today()) $error='The fuel log date cannot be in the future.';
    elseif($mileageInput!==''&&!preg_match('/^\d{1,9}$/',$mileageInput)) $error='Mileage must be a whole number of 0 or more.';
    elseif($liters===null||$liters<=0) $error='Liters must be a valid number greater than 0.';
    elseif($cost===null||$cost<=0) $error='Cost must be a valid amount greater than 0.';
    elseif(mb_strlen($station)>150) $error='Fuel station is too long (max 150 characters).';
    if(!$error){
        if($mileageInput!=='') $mileage=(int)$mileageInput;
        $chk=$conn->prepare("SELECT id FROM cars WHERE id=?"); $chk->bind_param('i',$car_id); $chk->execute();
        if($chk->get_result()->num_rows===0) $error='The selected car does not exist.';
        $chk->close();
    }
    if(!$error){
        $stmt=$conn->prepare("INSERT INTO fuel_logs (car_id,log_date,mileage,liters,cost,fuel_station,notes,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isiddssi',$car_id,$date,$mileage,$liters,$cost,$station,$notes,$user);
        if($stmt->execute()){
            if($mileage!==null){
                $up=$conn->prepare("UPDATE cars SET current_mileage=GREATEST(IFNULL(current_mileage,0),?) WHERE id=?");
                $up->bind_param('ii',$mileage,$car_id); $up->execute(); $up->close();
            }
            $msg='Fuel log saved.';
        } else $error=cr_db_error($stmt->error);
        $stmt->close();
    }
}
$cars=$conn->query("SELECT cars.id,cars.reg_no,makes.name AS make_name,models.name AS model_name,car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name,models.name");
$pg=cr_page_count($conn,cr_page(),"SELECT COUNT(*) FROM fuel_logs f JOIN cars ca ON f.car_id=ca.id");
$list=$conn->query("SELECT f.*, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM fuel_logs f JOIN cars ca ON f.car_id=ca.id LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id ORDER BY f.log_date DESC,f.id DESC".cr_page_sql($pg));
?>
<?php cr_layout_start('Fuel & mileage'); ?>
<?= cr_page_header('Fuel & mileage', 'Log fuel purchases and keep car mileage up to date.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <div class="card-head"><h2>Add fuel log</h2></div>
    <div class="form-grid">
        <div class="field">
            <label for="car_id">Car <span class="req">*</span></label>
            <select id="car_id" name="car_id" required><option value="">Select car</option><?php while ($c = $cars->fetch_assoc()): ?><option value="<?= h($c['id']) ?>"><?= h(car_display_name($c)) ?></option><?php endwhile; ?></select>
        </div>
        <div class="field">
            <label for="log_date">Date <span class="req">*</span></label>
            <input type="date" id="log_date" name="log_date" value="<?= h(cr_today()) ?>" max="<?= h(cr_today()) ?>" required>
        </div>
        <div class="field">
            <label for="mileage">Mileage <span class="opt">(optional)</span></label>
            <input type="number" id="mileage" name="mileage" min="0" step="1">
        </div>
        <div class="field">
            <label for="liters">Litres <span class="req">*</span></label>
            <input type="number" id="liters" step="0.01" min="0.01" name="liters" required>
        </div>
        <div class="field">
            <label for="cost">Cost <span class="req">*</span></label>
            <input type="number" id="cost" step="0.01" min="0.01" name="cost" required>
        </div>
        <div class="field">
            <label for="fuel_station">Fuel station <span class="opt">(optional)</span></label>
            <input id="fuel_station" name="fuel_station" maxlength="150">
        </div>
        <div class="field span-all">
            <label for="notes">Notes <span class="opt">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Save fuel log</button>
    </div>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>Fuel logs</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Car</th><th class="num">Mileage</th><th class="num">Litres</th><th class="num">Cost</th><th>Station</th><th>Notes</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($r = $list->fetch_assoc()): ?>
                <tr>
                    <td class="nowrap"><?= h(cr_date($r['log_date'])) ?></td>
                    <td><?= h(car_display_name($r)) ?></td>
                    <td class="num"><?= h($r['mileage']) ?></td>
                    <td class="num"><?= number_format((float)$r['liters'], 2) ?></td>
                    <td class="num"><?= money((float)$r['cost']) ?></td>
                    <td><?= h($r['fuel_station']) ?></td>
                    <td><?= h($r['notes']) ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7" class="table-empty">No fuel logs yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
