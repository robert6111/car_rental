<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = '';
$error = '';
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    if (($_SESSION['flash']['type'] ?? '') === 'error') $error = (string)($_SESSION['flash']['text'] ?? '');
    else $msg = (string)($_SESSION['flash']['text'] ?? '');
    unset($_SESSION['flash']);
}

if (isset($_GET['create'])) {
    stock_validate_action_token();
    $rid = (int)$_GET['create'];
    // Only rentals that are overdue and whose car has not been returned can get a case.
    $stmt = $conn->prepare("SELECT id, customer_id, cars_id FROM rentals
        WHERE id = ? AND returned_at IS NULL AND status IN ('Active','Completed') AND end_date < CURDATE() LIMIT 1");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'Rental #' . $rid . ' is not overdue or its car has already been returned.'];
    } else {
        $ref = 'NR-' . date('Ymd') . '-' . $rid;
        $notes = 'Customer did not return the car by the agreed date. Use this internal case record for any external report.';
        $user = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $customerId = (int)$r['customer_id'];
        $carId = (int)$r['cars_id'];
        $stmt = $conn->prepare("INSERT INTO not_returned_cases (rental_id,customer_id,car_id,case_reference,notes,created_by)
            SELECT ?,?,?,?,?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM not_returned_cases WHERE rental_id=? AND status IN ('Open','Followed Up','Reported'))");
        $stmt->bind_param('iiissii', $rid, $customerId, $carId, $ref, $notes, $user, $rid);
        if (!$stmt->execute()) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => cr_db_error($stmt->error, 'The case could not be opened. Please try again.')];
        } elseif ($stmt->affected_rows > 0) {
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Not-returned case ' . $ref . ' opened.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'A case is already open for rental #' . $rid . '.'];
        }
        $stmt->close();
    }
    header('Location: not_returned.php');
    exit;
}

if (isset($_GET['resolve'])) {
    stock_validate_action_token();
    $id = (int)$_GET['resolve'];
    $stmt = $conn->prepare("UPDATE not_returned_cases SET status='Resolved', resolved_at=NOW() WHERE id=? AND status NOT IN ('Resolved','Closed')");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) $_SESSION['flash'] = ['type' => 'error', 'text' => cr_db_error($stmt->error)];
    elseif ($stmt->affected_rows > 0) $_SESSION['flash'] = ['type' => 'success', 'text' => 'Case resolved.'];
    else $_SESSION['flash'] = ['type' => 'error', 'text' => 'This case was not found or is already closed.'];
    $stmt->close();
    header('Location: not_returned.php');
    exit;
}

$overdue = $conn->query("SELECT r.id, r.status, c.full_name, ca.reg_no, r.end_date,
        (SELECT n.case_reference FROM not_returned_cases n WHERE n.rental_id = r.id AND n.status IN ('Open','Followed Up','Reported') ORDER BY n.id DESC LIMIT 1) AS open_case
    FROM rentals r JOIN customers c ON r.customer_id=c.id JOIN cars ca ON r.cars_id=ca.id
    WHERE r.returned_at IS NULL AND r.status IN ('Active','Completed') AND r.end_date < CURDATE()
    ORDER BY r.end_date ASC");
$cases = $conn->query("SELECT n.*,c.full_name,ca.reg_no,r.end_date FROM not_returned_cases n LEFT JOIN customers c ON n.customer_id=c.id LEFT JOIN cars ca ON n.car_id=ca.id LEFT JOIN rentals r ON n.rental_id=r.id ORDER BY n.id DESC");
if (!$overdue || !$cases) $error = cr_db_error($conn->error, 'The list could not be loaded. Please try again.');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title><?=h(cr_not_returned_title())?></title><style>.success-msg{color:green;font-weight:700;margin:10px 0}.error-msg{color:#b91c1c;font-weight:700;margin:10px 0}.return-form{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}.return-form input{width:90px;padding:4px}.return-form button{padding:5px 8px;border:0;border-radius:3px;background:#16a34a;color:#fff;cursor:pointer}</style></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php';?><div class="content-page"><h2><?=h(cr_not_returned_title())?></h2><p>Internal case log for cars not returned on time.<?php if(cr_setting('authority_report_name')!==''):?> Use the case reference when reporting to <?=h(cr_setting('authority_report_name'))?>.<?php endif;?></p><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?>
<h3>Overdue – Car Not Returned</h3><table class="table" border="1" cellpadding="10"><tr><th>Rental</th><th>Customer</th><th>Car</th><th>Due Date</th><th>Action</th></tr><?php if($overdue&&$overdue->num_rows):while($o=$overdue->fetch_assoc()):?><tr><td>#<?=h($o['id'])?></td><td><?=h($o['full_name'])?></td><td><?=h($o['reg_no'])?></td><td><?= h(cr_date($o['end_date'])) ?></td><td><?php if($o['open_case']):?>Case <?=h($o['open_case'])?> open<?php else:?><a class="btn btn-danger" href="<?=h(stock_action_url('not_returned.php?create=' . (int)$o['id']))?>">Open Case</a><?php endif;?><form method="POST" action="manage.php" class="return-form" onsubmit="return confirm('Confirm that the car of rental #<?= (int)$o['id'] ?> has been returned?')"><input type="hidden" name="back" value="not_returned.php"><input type="hidden" name="rental_id" value="<?= (int)$o['id'] ?>"><input type="text" inputmode="numeric" name="return_mileage" placeholder="Mileage"><input type="text" name="return_notes" placeholder="Notes" maxlength="1000"><button type="submit" name="confirm_return" value="1">Confirm return</button></form></td></tr><?php endwhile;else:?><tr><td colspan="5">No overdue rentals.</td></tr><?php endif;?></table>
<h3>Cases</h3><table class="table" border="1" cellpadding="10"><tr><th>Ref</th><th>Rental</th><th>Customer</th><th>Car</th><th>Status</th><th>Notes</th><th>Action</th></tr><?php if($cases&&$cases->num_rows):while($c=$cases->fetch_assoc()):?><tr><td><?=h($c['case_reference'])?></td><td>#<?=h($c['rental_id'])?></td><td><?=h($c['full_name'])?></td><td><?=h($c['reg_no'])?></td><td><?=h($c['status'])?></td><td><?=nl2br(h($c['notes']))?></td><td><?php if(!in_array($c['status'],['Resolved','Closed'],true)):?><a class="btn btn-success" href="<?=h(stock_action_url('not_returned.php?resolve=' . (int)$c['id']))?>">Resolve</a><?php endif;?></td></tr><?php endwhile;else:?><tr><td colspan="7">No cases yet.</td></tr><?php endif;?></table></div></body></html>
