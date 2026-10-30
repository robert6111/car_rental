<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
$q = trim((string)($_GET['q'] ?? ''));
$where='WHERE 1'; $types=''; $params=[];
if($q!==''){ $like='%'.$q.'%'; $where .= " AND (cars.reg_no LIKE ? OR makes.name LIKE ? OR models.name LIKE ? OR cs.service_type LIKE ?)"; $types='ssss'; $params=[$like,$like,$like,$like]; }
$fromSql=" FROM car_service cs LEFT JOIN cars ON cs.car_id=cars.id LEFT JOIN makes ON cars.make_id=makes.id LEFT JOIN models ON cars.model_id=models.id $where";
$pg=cr_page_count($conn, cr_page(), "SELECT COUNT(*)".$fromSql, $types, $params);
$stmt=$conn->prepare("SELECT cs.*, cars.reg_no, makes.name AS make_name, models.name AS model_name".$fromSql." ORDER BY cs.service_date DESC, cs.id DESC".cr_page_sql($pg));
if($types!=='') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result=$stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html><html><head><title>Service Records</title><link rel="stylesheet" href="/../backend/includes/css/header.css"><link rel="stylesheet" href="/../backend/includes/css/dashboard.css"><link rel="stylesheet" href="/../backend/includes/css/common.css"><link rel="stylesheet" href="/../backend/includes/css/all_cars.css"></head><body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page"><h2>Service / Maintenance Records</h2><form method="GET" style="margin:12px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;"><div><label>Search car / reg no / service:</label><br><input type="text" name="q" value="<?= h($q); ?>" placeholder="Oil change, 34 ABC 123"></div><div><button type="submit" class="btn btn-primary">Search</button> <a href="service_list.php" class="btn btn-danger">Clear</a></div></form><a href="service.php" class="btn btn-primary">Add Service Record</a><table border="1" cellpadding="10" cellspacing="0" width="100%"><tr><th>#</th><th>Car</th><th>Reg No</th><th>Service Type</th><th>Description</th><th>Date</th><th>Mileage</th><th>Next Service Mileage</th><th>Cost</th></tr><?php if($result && $result->num_rows): $i=$pg['offset']+1; while($row=$result->fetch_assoc()): ?><tr><td><?= $i++; ?></td><td><?= h(trim(($row['make_name']??'').' '.($row['model_name']??''))) ?></td><td><?= h($row['reg_no']??'N/A') ?></td><td><?= h($row['service_type']) ?></td><td><?= h($row['description']) ?></td><td><?= h(cr_date($row['service_date'])) ?></td><td><?= h($row['mileage_at_service']) ?></td><td><?= h($row['next_service_mileage']) ?></td><td><?= money((float)$row['cost']) ?></td></tr><?php endwhile; else: ?><tr><td colspan="9">No service records found.</td></tr><?php endif; ?></table><?= cr_pagination($pg); ?></div></body></html>
