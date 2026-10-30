<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
$msg=''; $error='';
$uploadDir = __DIR__ . '/../uploads/documents';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_document'])) {
    $type = trim($_POST['document_type'] ?? ''); $title = trim($_POST['title'] ?? ''); $notes = trim($_POST['notes'] ?? '');
    $customer_id = (int)($_POST['customer_id'] ?? 0) ?: null; $car_id = (int)($_POST['car_id'] ?? 0) ?: null; $rental_id = (int)($_POST['rental_id'] ?? 0) ?: null; $expiry = $_POST['expiry_date'] ?: null;
    $filePath = null;
    if (!empty($_FILES['document_file']['name'])) {
        $name = preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['document_file']['name']));
        $target = $uploadDir . '/' . time() . '_' . $name;
        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target)) $filePath = 'uploads/documents/' . basename($target); else $error = 'File upload failed.';
    }
    if (!$error && $type && $title) {
        $uploaded_by = $_SESSION['user_id'] ?? null;
        $stmt=$conn->prepare("INSERT INTO documents (customer_id, car_id, rental_id, document_type, title, file_path, expiry_date, notes, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisssssi',$customer_id,$car_id,$rental_id,$type,$title,$filePath,$expiry,$notes,$uploaded_by);
        if($stmt->execute()) $msg='Document saved successfully.'; else $error='Document could not be saved.';
    } elseif(!$error) $error='Document type and title are required.';
}
$q=trim($_GET['q']??''); $where='WHERE 1'; if($q!==''){ $safe='%'.$conn->real_escape_string($q).'%'; $where="WHERE (d.title LIKE '$safe' OR d.document_type LIKE '$safe' OR c.full_name LIKE '$safe' OR ca.reg_no LIKE '$safe')"; }
$customers=$conn->query("SELECT id, full_name FROM customers ORDER BY full_name");
$cars=$conn->query("SELECT cars.id, cars.reg_no, makes.name AS make_name, models.name AS model_name, car_years.year AS car_year FROM cars JOIN makes ON cars.make_id=makes.id JOIN models ON cars.model_id=models.id JOIN car_years ON cars.year_id=car_years.id ORDER BY makes.name, models.name");
$rentals=$conn->query("SELECT r.id, c.full_name, ca.reg_no FROM rentals r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.cars_id=ca.id ORDER BY r.id DESC LIMIT 200");
$list=$conn->query("SELECT d.*, c.full_name, ca.reg_no FROM documents d LEFT JOIN customers c ON d.customer_id=c.id LEFT JOIN cars ca ON d.car_id=ca.id $where ORDER BY d.id DESC");
?>
<!DOCTYPE html><html><head><title>Document Management</title></head><body><?php include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/leftsidebar.php'; ?><div class="content-page"><h2>Document Management</h2><p>Store contracts, ID copies, insurance papers, invoices, licenses and other legal documents.</p><?php if($msg):?><p class="success-msg"><?=h($msg)?></p><?php endif;?><?php if($error):?><p class="error-msg"><?=h($error)?></p><?php endif;?>
<div class="card-box"><form method="POST" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end"><input type="hidden" name="save_document" value="1"><div><label>Type</label><select name="document_type" required><?php foreach(['Contract','ID Copy','Driving License','Passport','Insurance','Invoice','Maintenance Record','Other'] as $t):?><option><?=h($t)?></option><?php endforeach;?></select></div><div><label>Title</label><input name="title" required></div><div><label>Customer</label><select name="customer_id"><option value="">None</option><?php while($c=$customers->fetch_assoc()):?><option value="<?=h($c['id'])?>"><?=h($c['full_name'])?></option><?php endwhile;?></select></div><div><label>Car</label><select name="car_id"><option value="">None</option><?php while($c=$cars->fetch_assoc()):?><option value="<?=h($c['id'])?>"><?=h(car_display_name($c))?></option><?php endwhile;?></select></div><div><label>Rental</label><select name="rental_id"><option value="">None</option><?php while($r=$rentals->fetch_assoc()):?><option value="<?=h($r['id'])?>">#<?=h($r['id'].' '.$r['full_name'].' '.$r['reg_no'])?></option><?php endwhile;?></select></div><div><label>Expiry Date</label><input type="date" name="expiry_date"></div><div><label>File</label><input type="file" name="document_file"></div><div style="grid-column:1/-1"><label>Notes</label><textarea name="notes"></textarea></div><button>Save Document</button></form></div>
<form class="filter-box" method="GET"><input name="q" value="<?=h($q)?>" placeholder="Search document/customer/reg no"><button>Search</button><a href="manage.php" class="btn btn-danger">Clear</a></form><div class="table-responsive"><table class="table" border="1" cellpadding="10"><tr><th>#</th><th>Type</th><th>Title</th><th>Customer</th><th>Car</th><th>Expiry</th><th>File</th><th>Notes</th></tr><?php if($list&&$list->num_rows): while($d=$list->fetch_assoc()):?><tr><td><?=h($d['id'])?></td><td><?=h($d['document_type'])?></td><td><?=h($d['title'])?></td><td><?=h($d['full_name'])?></td><td><?=h($d['reg_no'])?></td><td><?=h($d['expiry_date'])?></td><td><?= $d['file_path']?'<a target="_blank" href="../'.h($d['file_path']).'">Open</a>':'No file' ?></td><td><?=h($d['notes'])?></td></tr><?php endwhile;else:?><tr><td colspan="8">No documents found.</td></tr><?php endif;?></table></div></div></body></html>
