<?php
require_once __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rental_status.php';
$msg=''; $error='';
$expenseCategories=['Fuel','Maintenance','Insurance','Staff','Office','Other'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $date=trim((string)($_POST['expense_date']??''));
    $cat=trim((string)($_POST['category']??''));
    $desc=trim((string)($_POST['description']??''));
    $amount=cr_parse_money($_POST['amount']??'');
    $car=(int)($_POST['car_id']??0)?:null;
    $user=isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
    if(!cr_valid_date($date)) $error='Please enter a valid date.';
    elseif($date>cr_today()) $error='The expense date cannot be in the future.';
    elseif(!in_array($cat,$expenseCategories,true)) $error='Please choose a valid category.';
    elseif($desc==='') $error='Description is required.';
    elseif(mb_strlen($desc)>255) $error='Description is too long (max 255 characters).';
    elseif($amount===null||$amount<=0) $error='Amount must be a valid number greater than 0.';
    if(!$error&&$car!==null){
        $chk=$conn->prepare("SELECT id FROM cars WHERE id=?"); $chk->bind_param('i',$car); $chk->execute();
        if($chk->get_result()->num_rows===0) $error='The selected car does not exist.';
        $chk->close();
    }
    if(!$error){
        $stmt=$conn->prepare("INSERT INTO expenses (expense_date,category,description,amount,car_id,created_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('sssdii',$date,$cat,$desc,$amount,$car,$user);
        if($stmt->execute()) $msg='Expense saved.'; else $error=cr_db_error($stmt->error);
        $stmt->close();
    }
}
$cars=$conn->query("SELECT cars.id,cars.reg_no,makes.name AS make_name,models.name AS model_name,car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name,models.name");
// The total covers every expense, not only the visible page.
$sumRow=$conn->query("SELECT COUNT(*) AS n, IFNULL(SUM(amount),0) AS total FROM expenses")->fetch_assoc();
$total=(float)$sumRow['total'];
$pg=cr_page_total(cr_page(),(int)$sumRow['n']);
$list=$conn->query("SELECT e.*,ca.reg_no FROM expenses e LEFT JOIN cars ca ON e.car_id=ca.id ORDER BY e.expense_date DESC,e.id DESC".cr_page_sql($pg));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Expense Tracking</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2>Expense Tracking</h2><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?><div class="card-box"><form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end"><div><label>Date</label><input type="date" name="expense_date" value="<?=h(cr_today())?>" max="<?=h(cr_today())?>" required></div><div><label>Category</label><select name="category"><?php foreach($expenseCategories as $c):?><option><?=h($c)?></option><?php endforeach;?></select></div><div><label>Description</label><input name="description" maxlength="255" required></div><div><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" required></div><div><label>Car</label><select name="car_id"><option value="">General</option><?php while($c=$cars->fetch_assoc()):?><option value="<?=h($c['id'])?>"><?=h(car_display_name($c))?></option><?php endwhile;?></select></div><button>Save Expense</button></form></div><table class="table" border="1" cellpadding="10"><tr><th>Date</th><th>Category</th><th>Description</th><th>Car</th><th>Amount</th></tr><?php if($list&&$list->num_rows):while($e=$list->fetch_assoc()):?><tr><td><?= h(cr_date($e['expense_date'])) ?></td><td><?=h($e['category'])?></td><td><?=h($e['description'])?></td><td><?=h($e['reg_no'])?></td><td><?=money((float)$e['amount'])?></td></tr><?php endwhile; ?><tr><th colspan="4">Total</th><th><?=money($total)?></th></tr><?php else:?><tr><td colspan="5">No expenses yet.</td></tr><?php endif;?></table><?= cr_pagination($pg); ?></div></body></html>
