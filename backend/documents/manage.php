<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/uploads.php';
$msg=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_document'])) {
    $type = trim($_POST['document_type'] ?? ''); $title = trim($_POST['title'] ?? ''); $notes = trim($_POST['notes'] ?? '');
    $customer_id = (int)($_POST['customer_id'] ?? 0) ?: null; $car_id = (int)($_POST['car_id'] ?? 0) ?: null; $rental_id = (int)($_POST['rental_id'] ?? 0) ?: null;
    $expiryRaw = trim((string)($_POST['expiry_date'] ?? ''));
    $expiry = ($expiryRaw !== '' && DateTime::createFromFormat('Y-m-d', $expiryRaw)) ? $expiryRaw : null;
    $filePath = null; $originalName = null; $mimeType = null;
    if (!$type || !$title) {
        $error = 'Document type and title are required.';
    } elseif (!empty($_FILES['document_file']) && ($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $stored = cr_store_upload($_FILES['document_file'], 'documents');
        if (isset($stored['error'])) $error = $stored['error'];
        else { $filePath = $stored['path']; $originalName = $stored['original_name']; $mimeType = $stored['mime']; }
    }
    if (!$error) {
        $uploaded_by = $_SESSION['user_id'] ?? null;
        $stmt=$conn->prepare("INSERT INTO documents (customer_id, car_id, rental_id, document_type, title, file_path, original_name, mime_type, expiry_date, notes, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisssssssi',$customer_id,$car_id,$rental_id,$type,$title,$filePath,$originalName,$mimeType,$expiry,$notes,$uploaded_by);
        if($stmt->execute()) $msg='Document saved successfully.';
        else { error_log('Document insert failed: '.$stmt->error); $error='Document could not be saved.'; if ($filePath) @unlink(cr_storage_path($filePath)); }
    }
}
$q=trim($_GET['q']??''); $where='WHERE 1'; $types=''; $params=[];
if($q!==''){ $like='%'.$q.'%'; $where="WHERE (d.title LIKE ? OR d.document_type LIKE ? OR c.full_name LIKE ? OR ca.reg_no LIKE ?)"; $types='ssss'; $params=[$like,$like,$like,$like]; }
$docFrom="FROM documents d LEFT JOIN customers c ON d.customer_id=c.id LEFT JOIN cars ca ON d.car_id=ca.id $where";
$pg=cr_page();
$pg=cr_page_count($conn,$pg,"SELECT COUNT(*) $docFrom",$types,$params);
$customers=$conn->query("SELECT id, full_name FROM customers ORDER BY full_name");
$cars=$conn->query("SELECT cars.id, cars.reg_no, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name, models.name");
$rentals=$conn->query("SELECT r.id, c.full_name, ca.reg_no FROM rentals r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.cars_id=ca.id ORDER BY r.id DESC LIMIT 200");
$listStmt=$conn->prepare("SELECT d.*, c.full_name, ca.reg_no $docFrom ORDER BY d.id DESC".cr_page_sql($pg));
if($types!=='') $listStmt->bind_param($types,...$params);
$listStmt->execute(); $list=$listStmt->get_result();
?>
<?php cr_layout_start('Documents'); ?>
<?= cr_page_header('Documents', 'Store contracts, ID copies, insurance papers, invoices, licences and other legal documents.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" enctype="multipart/form-data" class="card">
    <input type="hidden" name="save_document" value="1">
    <div class="card-head"><h2>Add document</h2></div>
    <div class="form-grid">
        <div class="field">
            <label for="document_type">Type <span class="req">*</span></label>
            <select id="document_type" name="document_type" required><?php foreach (['Contract','ID Copy','Driving License','Passport','Insurance','Invoice','Maintenance Record','Other'] as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field">
            <label for="title">Title <span class="req">*</span></label>
            <input id="title" name="title" required>
        </div>
        <div class="field">
            <label for="expiry_date">Expiry date <span class="opt">(optional)</span></label>
            <input type="date" id="expiry_date" name="expiry_date">
        </div>
        <div class="field">
            <label for="customer_id">Customer <span class="opt">(optional)</span></label>
            <select id="customer_id" name="customer_id"><option value="">None</option><?php while ($c = $customers->fetch_assoc()): ?><option value="<?= h($c['id']) ?>"><?= h($c['full_name']) ?></option><?php endwhile; ?></select>
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
            <label for="document_file">File <span class="opt">(optional)</span></label>
            <input type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp">
            <span class="hint">PDF, JPG, PNG or WEBP, max 10 MB.</span>
        </div>
        <div class="field span-all">
            <label for="notes">Notes <span class="opt">(optional)</span></label>
            <textarea id="notes" name="notes" rows="3"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit"><?= cr_icon('upload'); ?> Save document</button>
    </div>
</form>

<form method="GET" class="filters">
    <div class="field grow">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= h($q) ?>" placeholder="Document, customer or reg no">
    </div>
    <button type="submit"><?= cr_icon('search'); ?> Search</button>
    <a class="btn btn-secondary" href="manage.php">Clear</a>
</form>

<div class="card card-flush">
    <div class="card-head"><h2>All documents</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Type</th><th>Title</th><th>Customer</th><th>Car</th><th>Expiry</th><th>File</th><th>Notes</th></tr>
            </thead>
            <tbody>
            <?php if ($list && $list->num_rows): while ($d = $list->fetch_assoc()): ?>
                <tr>
                    <td><?= h($d['id']) ?></td>
                    <td><?= h($d['document_type']) ?></td>
                    <td><span class="cell-title"><?= h($d['title']) ?></span></td>
                    <td><?= h($d['full_name']) ?></td>
                    <td><?php if (!empty($d['reg_no'])): ?><span class="plate"><?= h($d['reg_no']) ?></span><?php endif; ?></td>
                    <td class="nowrap"><?= h(cr_date($d['expiry_date'])) ?></td>
                    <td class="actions-cell"><?= $d['file_path'] ? '<a class="btn btn-sm btn-secondary" target="_blank" rel="noopener" href="' . h('download.php?id=' . (int)$d['id']) . '">' . cr_icon('file', 'icon-sm') . ' Open</a>' : '<span class="muted">No file</span>' ?></td>
                    <td><?= h($d['notes']) ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="table-empty">No documents found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= cr_pagination($pg); ?>
</div>
<?php cr_layout_end(); ?>
