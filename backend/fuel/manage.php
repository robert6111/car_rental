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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Fuel & Mileage Tracking</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2>Fuel & Mileage Tracking</h2><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?><div class="card-box"><form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end"><div><label>Car</label><select name="car_id" required><option value="">Select</option><?php while($c=$cars->fetch_assoc()):?><option value="<?=h($c['id'])?>"><?=h(car_display_name($c))?></option><?php endwhile;?></select></div><div><label>Date</label><input type="date" name="log_date" value="<?=h(cr_today())?>" max="<?=h(cr_today())?>" required></div><div><label>Mileage</label><input type="number" name="mileage" min="0" step="1"></div><div><label>Liters</label><input type="number" step="0.01" min="0.01" name="liters" required></div><div><label>Cost</label><input type="number" step="0.01" min="0.01" name="cost" required></div><div><label>Fuel Station</label><input name="fuel_station" maxlength="150"></div><div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div><button>Save Fuel Log</button></form></div><div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>Date</th><th>Car</th><th>Mileage</th><th>Liters</th><th>Cost</th><th>Station</th><th>Notes</th></tr><?php if($list&&$list->num_rows): while($r=$list->fetch_assoc()):?><tr><td><?= h(cr_date($r['log_date'])) ?></td><td><?=h(car_display_name($r))?></td><td><?=h($r['mileage'])?></td><td><?=number_format((float)$r['liters'],2)?></td><td><?=money((float)$r['cost'])?></td><td><?=h($r['fuel_station'])?></td><td><?=h($r['notes'])?></td></tr><?php endwhile;else:?><tr><td colspan="7">No fuel logs yet.</td></tr><?php endif;?></table></div><?= cr_pagination($pg); ?></div></body></html>
