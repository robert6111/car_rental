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
$pg=cr_page();
$pg=cr_page_count($conn,$pg,"SELECT COUNT(*) FROM attendance a JOIN users u ON a.user_id=u.id");
$list=$conn->query("SELECT a.*,u.username FROM attendance a JOIN users u ON a.user_id=u.id ORDER BY a.work_date DESC,a.id DESC".cr_page_sql($pg));
?>
<?php cr_layout_start('Attendance'); ?>
<?= cr_page_header('Attendance', 'Record staff shifts and clock in / clock out times.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <div class="card-head"><h2>Record attendance</h2></div>
    <div class="form-grid">
        <div class="field">
            <label for="user_id">User <span class="req">*</span></label>
            <select id="user_id" name="user_id" required><option value="">Select user</option><?php while ($u = $users->fetch_assoc()): ?><option value="<?= h($u['id']) ?>"><?= h($u['username']) ?></option><?php endwhile; ?></select>
        </div>
        <div class="field">
            <label for="work_date">Date <span class="req">*</span></label>
            <input type="date" id="work_date" name="work_date" value="<?= h(cr_today()) ?>" max="<?= h(cr_today()) ?>" required>
        </div>
        <div class="field">
            <label for="shift_name">Shift <span class="opt">(optional)</span></label>
            <input id="shift_name" name="shift_name" maxlength="80" placeholder="Morning / Evening">
        </div>
        <div class="field">
            <label for="clock_in">Clock in <span class="opt">(optional)</span></label>
            <input type="time" id="clock_in" name="clock_in">
        </div>
        <div class="field">
            <label for="clock_out">Clock out <span class="opt">(optional)</span></label>
            <input type="time" id="clock_out" name="clock_out">
        </div>
        <div class="field span-all">
            <label for="notes">Notes <span class="opt">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Save attendance</button>
    </div>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>Attendance records</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>User</th><th>Shift</th><th>Clock in</th><th>Clock out</th><th>Notes</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($a = $list->fetch_assoc()): ?>
                <tr>
                    <td class="nowrap"><?= h(cr_date($a['work_date'])) ?></td>
                    <td><span class="cell-title"><?= h($a['username']) ?></span></td>
                    <td><?= h($a['shift_name']) ?></td>
                    <td class="nowrap"><?= h(substr((string)$a['clock_in'], 0, 5)) ?></td>
                    <td class="nowrap"><?= h(substr((string)$a['clock_out'], 0, 5)) ?></td>
                    <td><?= h($a['notes']) ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="6" class="table-empty">No attendance records.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
