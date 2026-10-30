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
// The overdue list above is naturally small (at most one unreturned rental per car); the case log grows forever, so it is paginated.
$pg = cr_page_count($conn, cr_page(), "SELECT COUNT(*) FROM not_returned_cases");
$cases = $conn->query("SELECT n.*,c.full_name,ca.reg_no,r.end_date FROM not_returned_cases n LEFT JOIN customers c ON n.customer_id=c.id LEFT JOIN cars ca ON n.car_id=ca.id LEFT JOIN rentals r ON n.rental_id=r.id ORDER BY n.id DESC" . cr_page_sql($pg));
if (!$overdue || !$cases) $error = cr_db_error($conn->error, 'The list could not be loaded. Please try again.');
?>
<?php
$nrSubtitle = 'Internal case log for cars not returned on time.';
if (cr_setting('authority_report_name') !== '') $nrSubtitle .= ' Use the case reference when reporting to ' . cr_setting('authority_report_name') . '.';
cr_layout_start(cr_not_returned_title());
?>
<?= cr_page_header(cr_not_returned_title(), $nrSubtitle); ?>
<?= cr_alerts($msg, $error); ?>

<div class="card card-flush">
    <div class="card-head"><h2>Overdue – car not returned</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Rental</th>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Due date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if($overdue && $overdue->num_rows): while($o = $overdue->fetch_assoc()): ?>
                <tr>
                    <td class="muted">#<?= h($o['id']) ?></td>
                    <td><span class="cell-title"><?= h($o['full_name']) ?></span></td>
                    <td><span class="plate"><?= h($o['reg_no']) ?></span></td>
                    <td class="nowrap text-danger"><?= h(cr_date($o['end_date'])) ?></td>
                    <td class="actions-cell">
                        <?php if($o['open_case']): ?>
                            <?= cr_status_badge('Open', 'Case ' . $o['open_case'] . ' open') ?>
                        <?php else: ?>
                            <a class="btn btn-sm btn-danger" href="<?= h(stock_action_url('not_returned.php?create=' . (int)$o['id'])) ?>"><?= cr_icon('alert', 'icon-sm') ?> Open case</a>
                        <?php endif; ?>
                        <details class="row-form">
                            <summary class="btn btn-sm btn-success"><?= cr_icon('check', 'icon-sm') ?> Confirm return</summary>
                            <form method="POST" action="manage.php" class="row-form-body">
                                <input type="hidden" name="back" value="not_returned.php">
                                <input type="hidden" name="rental_id" value="<?= (int)$o['id'] ?>">
                                <label for="return_mileage_<?= (int)$o['id'] ?>">Return mileage <span class="opt">(optional)</span></label>
                                <input type="text" inputmode="numeric" id="return_mileage_<?= (int)$o['id'] ?>" name="return_mileage" placeholder="Mileage">
                                <label for="return_notes_<?= (int)$o['id'] ?>">Notes <span class="opt">(optional)</span></label>
                                <input type="text" id="return_notes_<?= (int)$o['id'] ?>" name="return_notes" placeholder="Notes" maxlength="1000">
                                <button type="submit" name="confirm_return" value="1" class="btn btn-sm btn-success" data-confirm="Confirm that the car of rental #<?= (int)$o['id'] ?> has been returned?">Confirm return</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="5" class="table-empty">No overdue rentals.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Cases</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Rental</th>
                    <th>Customer</th>
                    <th>Car</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if($cases && $cases->num_rows): while($c = $cases->fetch_assoc()): ?>
                <tr>
                    <td class="nowrap"><span class="cell-title"><?= h($c['case_reference']) ?></span></td>
                    <td class="muted">#<?= h($c['rental_id']) ?></td>
                    <td><?= h($c['full_name']) ?></td>
                    <td><?php if((string)$c['reg_no'] !== ''): ?><span class="plate"><?= h($c['reg_no']) ?></span><?php endif; ?></td>
                    <td><?= cr_status_badge((string)$c['status']) ?></td>
                    <td><?= nl2br(h($c['notes'])) ?></td>
                    <td class="actions-cell">
                        <?php if(!in_array($c['status'], ['Resolved','Closed'], true)): ?>
                            <a class="btn btn-sm btn-success" href="<?= h(stock_action_url('not_returned.php?resolve=' . (int)$c['id'])) ?>"><?= cr_icon('check', 'icon-sm') ?> Resolve</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7" class="table-empty">No cases yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
