<?php
require_once __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rental_status.php';
$msg=''; $error='';
/** Returns "HH:MM:SS" for a valid time input, null when empty, false when invalid. */
$parseTime=function(string $v){
    $v=trim($v);
    if($v==='') return null;
    if(!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/',$v,$m)) return false;
    return $m[1].':'.$m[2].':'.($m[3]??'00');
};
if($_SERVER['REQUEST_METHOD']==='POST'){
    $user_id=(int)($_POST['user_id']??0);
    $date=trim((string)($_POST['work_date']??''));
    $shift=trim((string)($_POST['shift_name']??''));
    $in=$parseTime((string)($_POST['clock_in']??''));
    $out=$parseTime((string)($_POST['clock_out']??''));
    $notes=trim((string)($_POST['notes']??''));
    if(!$user_id) $error='User is required.';
    elseif(!cr_valid_date($date)) $error='Please enter a valid date.';
    elseif($date>cr_today()) $error='Attendance cannot be recorded for a future date.';
    elseif(mb_strlen($shift)>80) $error='Shift name is too long (max 80 characters).';
    elseif($in===false||$out===false) $error='Please enter valid clock in / clock out times (HH:MM).';
    elseif($out!==null&&$in===null) $error='Enter the clock-in time together with the clock-out time.';
    elseif($in!==null&&$out!==null&&$out<$in) $error='Clock out cannot be before clock in on the same day.';
    if(!$error){
        $chk=$conn->prepare("SELECT id FROM users WHERE id=?"); $chk->bind_param('i',$user_id); $chk->execute();
        if($chk->get_result()->num_rows===0) $error='The selected user does not exist.';
        $chk->close();
    }
    if(!$error){
        $stmt=$conn->prepare("INSERT INTO attendance (user_id,work_date,shift_name,clock_in,clock_out,notes) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shift_name=VALUES(shift_name), clock_in=VALUES(clock_in), clock_out=VALUES(clock_out), notes=VALUES(notes)");
        $stmt->bind_param('isssss',$user_id,$date,$shift,$in,$out,$notes);
        if($stmt->execute()) $msg='Attendance saved.'; else $error=cr_db_error($stmt->error);
        $stmt->close();
    }
}
$users=$conn->query("SELECT id,username FROM users WHERE is_active=1 ORDER BY username");
$list=$conn->query("SELECT a.*,u.username FROM attendance a JOIN users u ON a.user_id=u.id ORDER BY a.work_date DESC,a.id DESC LIMIT 200");
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Attendance & Shifts</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2>Attendance & Shift Tracking</h2><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?><div class="card-box"><form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end"><div><label>User</label><select name="user_id" required><option value="">Select</option><?php while($u=$users->fetch_assoc()):?><option value="<?=h($u['id'])?>"><?=h($u['username'])?></option><?php endwhile;?></select></div><div><label>Date</label><input type="date" name="work_date" value="<?=h(cr_today())?>" max="<?=h(cr_today())?>" required></div><div><label>Shift</label><input name="shift_name" maxlength="80" placeholder="Morning / Evening"></div><div><label>Clock In</label><input type="time" name="clock_in"></div><div><label>Clock Out</label><input type="time" name="clock_out"></div><div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div><button>Save Attendance</button></form></div><table class="table" border="1" cellpadding="10"><tr><th>Date</th><th>User</th><th>Shift</th><th>Clock In</th><th>Clock Out</th><th>Notes</th></tr><?php if($list&&$list->num_rows): while($a=$list->fetch_assoc()):?><tr><td><?= h(cr_date($a['work_date'])) ?></td><td><?=h($a['username'])?></td><td><?=h($a['shift_name'])?></td><td><?=h($a['clock_in'])?></td><td><?=h($a['clock_out'])?></td><td><?=h($a['notes'])?></td></tr><?php endwhile;else:?><tr><td colspan="6">No attendance records.</td></tr><?php endif;?></table></div></body></html>
