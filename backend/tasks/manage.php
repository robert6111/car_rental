<?php
require_once __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rental_status.php';
$msg=''; $error='';
$taskTypes=['Vehicle Prep','Maintenance','Cleaning','Customer Follow-up','Document Check','General'];
if(isset($_GET['complete'])){
    stock_validate_action_token();
    $id=(int)$_GET['complete'];
    $stmt=$conn->prepare("UPDATE staff_tasks SET status='Completed',completed_at=NOW() WHERE id=? AND status<>'Completed'");
    $stmt->bind_param('i',$id);
    if(!$stmt->execute()) $error=cr_db_error($stmt->error);
    elseif($stmt->affected_rows>0) $msg='Task completed.';
    else $error='Task not found or already completed.';
    $stmt->close();
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    $assigned=(int)($_POST['assigned_to']??0)?:null;
    $car=(int)($_POST['car_id']??0)?:null;
    $rental=(int)($_POST['rental_id']??0)?:null;
    $type=trim((string)($_POST['task_type']??'General'));
    $title=trim((string)($_POST['title']??''));
    $desc=trim((string)($_POST['description']??''));
    $due=($_POST['due_date']??'')!==''?trim((string)$_POST['due_date']):null;
    $user=isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
    if($title==='') $error='Title is required.';
    elseif(mb_strlen($title)>255) $error='Title is too long (max 255 characters).';
    elseif(!in_array($type,$taskTypes,true)) $error='Please choose a valid task type.';
    elseif($due!==null&&!cr_valid_date($due)) $error='Please enter a valid due date.';
    if(!$error){
        foreach([['users',$assigned,'assigned user'],['cars',$car,'car'],['rentals',$rental,'rental']] as [$tbl,$refId,$label]){
            if($refId===null) continue;
            $chk=$conn->prepare("SELECT id FROM `$tbl` WHERE id=?"); $chk->bind_param('i',$refId); $chk->execute();
            $found=$chk->get_result()->num_rows>0; $chk->close();
            if(!$found){ $error="The selected $label does not exist."; break; }
        }
    }
    if(!$error){
        $stmt=$conn->prepare("INSERT INTO staff_tasks (assigned_to,car_id,rental_id,task_type,title,description,due_date,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiissssi',$assigned,$car,$rental,$type,$title,$desc,$due,$user);
        if($stmt->execute()) $msg='Task assigned.'; else $error=cr_db_error($stmt->error);
        $stmt->close();
    }
}
$users=$conn->query("SELECT id,username FROM users WHERE is_active=1 ORDER BY username"); $cars=$conn->query("SELECT cars.id,cars.reg_no,makes.name AS make_name,models.name AS model_name,car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name,models.name");
$rentals=$conn->query("SELECT r.id,c.full_name,ca.reg_no FROM rentals r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.cars_id=ca.id ORDER BY r.id DESC LIMIT 200");
$list=$conn->query("SELECT t.*, u.username, ca.reg_no FROM staff_tasks t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN cars ca ON t.car_id=ca.id ORDER BY FIELD(t.status,'Open','In Progress','Completed','Cancelled'), t.due_date IS NULL, t.due_date ASC, t.id DESC");
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Staff Tasks</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2>Staff Task Assignment</h2><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?><div class="card-box"><form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end"><div><label>Assign To</label><select name="assigned_to"><option value="">Unassigned</option><?php while($u=$users->fetch_assoc()):?><option value="<?=h($u['id'])?>"><?=h($u['username'])?></option><?php endwhile;?></select></div><div><label>Task Type</label><select name="task_type"><?php foreach($taskTypes as $t):?><option><?=h($t)?></option><?php endforeach;?></select></div><div><label>Title</label><input name="title" maxlength="255" required></div><div><label>Due Date</label><input type="date" name="due_date"></div><div><label>Car</label><select name="car_id"><option value="">None</option><?php while($c=$cars->fetch_assoc()):?><option value="<?=h($c['id'])?>"><?=h(car_display_name($c))?></option><?php endwhile;?></select></div><div><label>Rental</label><select name="rental_id"><option value="">None</option><?php while($r=$rentals->fetch_assoc()):?><option value="<?=h($r['id'])?>">#<?=h($r['id'].' '.$r['full_name'].' '.$r['reg_no'])?></option><?php endwhile;?></select></div><div style="grid-column:1/-1"><label>Description</label><textarea name="description"></textarea></div><button>Assign Task</button></form></div><div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Task</th><th>Type</th><th>Assigned To</th><th>Car</th><th>Due</th><th>Status</th><th>Action</th></tr><?php if($list&&$list->num_rows): while($t=$list->fetch_assoc()):?><tr><td><?=h($t['id'])?></td><td><strong><?=h($t['title'])?></strong><br><small><?=h($t['description'])?></small></td><td><?=h($t['task_type'])?></td><td><?=h($t['username'])?></td><td><?=h($t['reg_no'])?></td><td><?= h(cr_date($t['due_date'])) ?></td><td><?=h($t['status'])?></td><td><?php if($t['status']!=='Completed'):?><a class="btn btn-success" href="<?=h(stock_action_url('?complete=' . (int)$t['id']))?>">Complete</a><?php endif;?></td></tr><?php endwhile;else:?><tr><td colspan="8">No tasks yet.</td></tr><?php endif;?></table></div></div></body></html>
