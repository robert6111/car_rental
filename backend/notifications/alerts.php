<?php
require_once __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/db.php'; require_once __DIR__.'/../includes/rental_status.php';
$msg='';
if(isset($_GET['generate'])){ stock_validate_action_token();
    // Generate system alerts safely. Uses INSERT only when similar ready alert does not already exist today.
    $today=cr_today();
    // One prepared insert, reused for every alert. Skips an alert that was already created today.
    $stmt=$conn->prepare("INSERT INTO notifications (target_type,title,message,channel,status,related_table,related_id) SELECT 'Staff',?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE title=? AND DATE(created_at)=CURDATE())");
    $channel='System'; $status='Ready'; $title=''; $message=''; $related=''; $rid=0;
    $stmt->bind_param('sssssis',$title,$message,$channel,$status,$related,$rid,$title);
    // Overdue = the car has not come back and the rental end date has passed.
    $lateStmt=$conn->prepare("SELECT r.id,c.full_name,ca.reg_no,r.end_date FROM rentals r JOIN customers c ON r.customer_id=c.id JOIN cars ca ON r.cars_id=ca.id WHERE r.returned_at IS NULL AND r.status IN ('Active','Completed') AND r.end_date < ?");
    $lateStmt->bind_param('s',$today); $lateStmt->execute(); $late=$lateStmt->get_result(); $lateStmt->close();
    while($r=$late->fetch_assoc()){ $title='Late return: '.$r['reg_no']; $message='Customer '.$r['full_name'].' has not returned car '.$r['reg_no'].' due on '.cr_date($r['end_date']).'.'; $related='rentals'; $rid=(int)$r['id']; $stmt->execute(); }
    $docs=$conn->query("SELECT id,title,expiry_date FROM documents WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    while($d=$docs->fetch_assoc()){ $title='Document expiry: '.$d['title']; $message='Document '.$d['title'].' expires on '.cr_date($d['expiry_date']).'.'; $related='documents'; $rid=(int)$d['id']; $stmt->execute(); }
    $cars=$conn->query("SELECT id,reg_no,insurance_expiry,inspection_expiry FROM cars WHERE (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (inspection_expiry IS NOT NULL AND inspection_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    while($c=$cars->fetch_assoc()){ $title='Vehicle document alert: '.$c['reg_no']; $message='Check insurance/inspection dates for '.$c['reg_no'].'. Insurance: '.cr_date($c['insurance_expiry']).' Inspection: '.cr_date($c['inspection_expiry']); $related='cars'; $rid=(int)$c['id']; $stmt->execute(); }
    $stmt->close();
    $msg='Alerts generated. SMS/WhatsApp messages are prepared here; sending requires API credentials.';
}
if(isset($_GET['read'])){ stock_validate_action_token(); $id=(int)$_GET['read']; $readStmt=$conn->prepare("UPDATE notifications SET status='Read' WHERE id=?"); $readStmt->bind_param('i',$id); $readStmt->execute(); $readStmt->close(); $msg='Notification marked as read.'; }
// Keep one-time actions out of the pager links (they don't carry the action token).
unset($_GET['generate'], $_GET['read']);
$pg=cr_page();
$pg=cr_page_count($conn,$pg,"SELECT COUNT(*) FROM notifications");
$list=$conn->query("SELECT * FROM notifications ORDER BY FIELD(status,'Ready','Draft','Sent','Read'), id DESC".cr_page_sql($pg));
// "Mark Read" links stay on the current page.
$pageQs=($pg['page']>1?'&page='.(int)$pg['page']:'').(isset($_GET['per_page'])?'&per_page='.(int)$pg['per_page']:'');
?>
<?php cr_layout_start('Notifications'); ?>
<?= cr_page_header('Notifications', 'Booking reminders, overdue alerts, maintenance and document expiry reminders, and WhatsApp/SMS-ready messages.', '<a class="btn" href="' . h(stock_action_url('?generate=1')) . '">Generate alerts now</a>'); ?>
<?= cr_alert('success', $msg); ?>

<div class="card card-flush">
    <div class="card-head"><h2>All notifications</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Title</th><th>Message</th><th>Channel</th><th>Status</th><th>Created</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($n = $list->fetch_assoc()): ?>
                <tr>
                    <td><?= h($n['id']) ?></td>
                    <td><span class="cell-title"><?= h($n['title']) ?></span></td>
                    <td><?= h($n['message']) ?></td>
                    <td><?= h($n['channel']) ?></td>
                    <td><?= cr_status_badge((string)$n['status']) ?></td>
                    <td class="nowrap"><?= h(cr_date($n['created_at'])) ?></td>
                    <td class="actions-cell"><?php if ($n['status'] !== 'Read'): ?><a class="btn btn-sm btn-secondary" href="<?= h(stock_action_url('?read=' . (int)$n['id'] . $pageQs)) ?>">Mark read</a><?php endif; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7" class="table-empty">No notifications yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
