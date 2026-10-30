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
    // Keep the one-time action out of the pager links (they don't carry the action token).
    unset($_GET['complete']);
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
$pg=cr_page();
$pg=cr_page_count($conn,$pg,"SELECT COUNT(*) FROM staff_tasks");
$list=$conn->query("SELECT t.*, u.username, ca.reg_no FROM staff_tasks t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN cars ca ON t.car_id=ca.id ORDER BY FIELD(t.status,'Open','In Progress','Completed','Cancelled'), t.due_date IS NULL, t.due_date ASC, t.id DESC".cr_page_sql($pg));
// "Complete" links stay on the current page.
$pageQs=($pg['page']>1?'&page='.(int)$pg['page']:'').(isset($_GET['per_page'])?'&per_page='.(int)$pg['per_page']:'');
?>
<?php cr_layout_start('Staff tasks'); ?>
<?= cr_page_header('Staff tasks', 'Assign jobs such as car preparation, cleaning and customer follow-ups to staff.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <div class="card-head"><h2>Assign task</h2></div>
    <div class="form-grid">
        <div class="field span-2">
            <label for="title">Title <span class="req">*</span></label>
            <input id="title" name="title" maxlength="255" required>
        </div>
        <div class="field">
            <label for="task_type">Task type</label>
            <select id="task_type" name="task_type"><?php foreach ($taskTypes as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field">
            <label for="assigned_to">Assign to <span class="opt">(optional)</span></label>
            <select id="assigned_to" name="assigned_to"><option value="">Unassigned</option><?php while ($u = $users->fetch_assoc()): ?><option value="<?= h($u['id']) ?>"><?= h($u['username']) ?></option><?php endwhile; ?></select>
        </div>
        <div class="field">
            <label for="due_date">Due date <span class="opt">(optional)</span></label>
            <input type="date" id="due_date" name="due_date">
        </div>
        <div class="field">
            <label for="car_id">Car <span class="opt">(optional)</span></label>
            <select id="car_id" name="car_id"><option value="">None</option><?php while ($c = $cars->fetch_assoc()): ?><option value="<?= h($c['id']) ?>"><?= h(car_display_name($c)) ?></option><?php endwhile; ?></select>
        </div>
        <div class="field">
            <label for="rental_id">Rental <span class="opt">(optional)</span></label>
            <select id="rental_id" name="rental_id"><option value="">None</option><?php while ($r = $rentals->fetch_assoc()): ?><option value="<?= h($r['id']) ?>">#<?= h($r['id'] . ' ' . $r['full_name'] . ' ' . $r['reg_no']) ?></option><?php endwhile; ?></select>
        </div>
        <div class="field span-all">
            <label for="description">Description <span class="opt">(optional)</span></label>
            <textarea id="description" name="description" rows="2"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Assign task</button>
    </div>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>Tasks</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Task</th><th>Type</th><th>Assigned to</th><th>Car</th><th>Due</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($t = $list->fetch_assoc()): ?>
                <tr>
                    <td><?= h($t['id']) ?></td>
                    <td><span class="cell-title"><?= h($t['title']) ?></span><?php if ((string)$t['description'] !== ''): ?><span class="cell-sub"><?= h($t['description']) ?></span><?php endif; ?></td>
                    <td><?= h($t['task_type']) ?></td>
                    <td><?= $t['username'] !== null ? h($t['username']) : '<span class="muted">Unassigned</span>' ?></td>
                    <td><?php if (!empty($t['reg_no'])): ?><span class="plate"><?= h($t['reg_no']) ?></span><?php endif; ?></td>
                    <td class="nowrap"><?= h(cr_date($t['due_date'])) ?></td>
                    <td><?= cr_status_badge((string)$t['status']) ?></td>
                    <td class="actions-cell"><?php if ($t['status'] !== 'Completed'): ?><a class="btn btn-sm btn-success" href="<?= h(stock_action_url('?complete=' . (int)$t['id'] . $pageQs)) ?>">Complete</a><?php endif; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="table-empty">No tasks yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
