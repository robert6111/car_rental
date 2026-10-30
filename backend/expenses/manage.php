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
<?php cr_layout_start('Expenses'); ?>
<?= cr_page_header('Expenses', 'Record business expenses such as fuel, maintenance, insurance and office costs.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <div class="card-head"><h2>Add expense</h2></div>
    <div class="form-grid">
        <div class="field">
            <label for="expense_date">Date <span class="req">*</span></label>
            <input type="date" id="expense_date" name="expense_date" value="<?= h(cr_today()) ?>" max="<?= h(cr_today()) ?>" required>
        </div>
        <div class="field">
            <label for="category">Category <span class="req">*</span></label>
            <select id="category" name="category"><?php foreach ($expenseCategories as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field">
            <label for="amount">Amount <span class="req">*</span></label>
            <input type="number" id="amount" step="0.01" min="0.01" name="amount" required>
        </div>
        <div class="field span-2">
            <label for="description">Description <span class="req">*</span></label>
            <input id="description" name="description" maxlength="255" required>
        </div>
        <div class="field">
            <label for="car_id">Car <span class="opt">(optional)</span></label>
            <select id="car_id" name="car_id"><option value="">General</option><?php while ($c = $cars->fetch_assoc()): ?><option value="<?= h($c['id']) ?>"><?= h(car_display_name($c)) ?></option><?php endwhile; ?></select>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit">Save expense</button>
    </div>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>All expenses</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Category</th><th>Description</th><th>Car</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($e = $list->fetch_assoc()): ?>
                <tr>
                    <td class="nowrap"><?= h(cr_date($e['expense_date'])) ?></td>
                    <td><?= h($e['category']) ?></td>
                    <td><?= h($e['description']) ?></td>
                    <td><?php if (!empty($e['reg_no'])): ?><span class="plate"><?= h($e['reg_no']) ?></span><?php else: ?><span class="muted">General</span><?php endif; ?></td>
                    <td class="num"><?= money((float)$e['amount']) ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4">Total</td><td class="num"><?= money($total) ?></td></tr>
            </tfoot>
            <?php else: ?>
                <tr><td colspan="5" class="table-empty">No expenses yet.</td></tr>
            </tbody>
            <?php endif; ?>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
