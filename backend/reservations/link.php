<?php
/*
 * Link an online booking request to a customer record (existing or new).
 * Online requests are never linked automatically: staff check the person first.
 */
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
require_once __DIR__ . '/../customers/customer_validation.php';

function link_flash(string $type, string $text, string $location = 'manage.php'): void {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    header('Location: ' . $location);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT r.*, c.full_name AS linked_name, c.phone_number AS linked_phone, ca.reg_no, m.name AS make_name, mo.name AS model_name, y.year AS car_year FROM reservations r LEFT JOIN customers c ON r.customer_id=c.id LEFT JOIN cars ca ON r.car_id=ca.id LEFT JOIN makes m ON ca.make_id=m.id LEFT JOIN models mo ON ca.model_id=mo.id LEFT JOIN car_years y ON ca.year_id=y.id WHERE r.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$res) link_flash('error', 'Reservation not found.');
if (in_array($res['status'], ['Converted', 'Cancelled'], true)) {
    link_flash('error', 'Reservation #' . $id . ' is ' . strtolower($res['status']) . ' and can no longer be changed.');
}

function link_reservation(mysqli $conn, int $reservationId, int $customerId): bool {
    $stmt = $conn->prepare("UPDATE reservations SET customer_id = ? WHERE id = ? AND status NOT IN ('Converted','Cancelled')");
    $stmt->bind_param('ii', $customerId, $reservationId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}

$error = '';
$new = [
    'full_name' => (string)($res['customer_name'] ?? ''),
    'phone_number' => (string)($res['phone_number'] ?? ''),
    'email' => (string)($res['email'] ?? ''),
    'license_no' => '', 'address' => '', 'id_number' => '', 'passport_no' => '',
    'license_expiry' => '', 'passport_expiry' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['link_existing'])) {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $chk = $conn->prepare('SELECT full_name FROM customers WHERE id = ?');
        $chk->bind_param('i', $customerId);
        $chk->execute();
        $cust = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cust) {
            $error = 'Please choose a customer.';
        } elseif (link_reservation($conn, $id, $customerId)) {
            link_flash('success', 'Reservation #' . $id . ' is now linked to ' . $cust['full_name'] . '.');
        } else {
            $error = 'The reservation could not be linked. Please try again.';
        }
    } elseif (isset($_POST['create_customer'])) {
        [$c, $error] = cr_validate_customer_post($conn, $_POST);
        foreach ($new as $k => $v) $new[$k] = (string)($_POST[$k] ?? '');
        if ($error === null) {
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare('INSERT INTO customers (full_name, phone_number, email, license_no, address, id_number, passport_no, passport_expiry, license_expiry) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->bind_param('sssssssss', $c['full_name'], $c['phone_number'], $c['email'], $c['license_no'], $c['address'], $c['id_number'], $c['passport_no'], $c['passport_expiry'], $c['license_expiry']);
                if (!$ins->execute()) throw new RuntimeException($ins->error);
                $customerId = (int)$ins->insert_id;
                $ins->close();
                if (!link_reservation($conn, $id, $customerId)) throw new RuntimeException('Link failed');
                $conn->commit();
                link_flash('success', 'Customer ' . $c['full_name'] . ' was created and linked to reservation #' . $id . '.');
            } catch (Throwable $e) {
                $conn->rollback();
                $error = cr_db_error($e->getMessage(), 'The customer could not be saved. Please try again.');
            }
        }
        $error = (string)$error;
    }
}

// Possible matches: same phone (in any local/international form), same email, or similar name.
$matches = [];
$phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number,' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
$where = [];
$types = '';
$params = [];
$digitVariants = array_unique(array_filter(array_map(fn($v) => preg_replace('/\D/', '', $v), cr_phone_variants((string)$res['phone_number']))));
foreach ($digitVariants as $variant) {
    $where[] = "$phoneExpr = ?"; $types .= 's'; $params[] = $variant;
}
if (!empty($res['email'])) { $where[] = 'LOWER(email) = LOWER(?)'; $types .= 's'; $params[] = $res['email']; }
if (mb_strlen(trim((string)$res['customer_name'])) >= 3) { $where[] = 'full_name LIKE ?'; $types .= 's'; $params[] = '%' . trim((string)$res['customer_name']) . '%'; }
if ($where) {
    $q = $conn->prepare('SELECT id, full_name, phone_number, email, license_no FROM customers WHERE ' . implode(' OR ', $where) . ' ORDER BY full_name LIMIT 20');
    $q->bind_param($types, ...$params);
    $q->execute();
    $matches = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
}
$allCustomers = $conn->query('SELECT id, full_name, phone_number FROM customers ORDER BY full_name');
?>
<!DOCTYPE html><html><head><title>Link Customer</title><style>.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end}.req{color:#991b1b}dl.details{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:0}dl.details dt{font-weight:600}dl.details dd{margin:0}</style></head><body><?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
<h2>Link Booking Request #<?= (int)$id; ?> to a Customer</h2>
<p><a href="manage.php">&larr; Back to reservations</a></p>
<?php if ($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?>

<div class="card-box"><h3>Request details</h3>
<dl class="details">
<dt>Name</dt><dd><?= h($res['customer_name'] ?: '-'); ?></dd>
<dt>Phone</dt><dd><?= h($res['phone_number'] ?: '-'); ?></dd>
<dt>Email</dt><dd><?= h($res['email'] ?: '-'); ?></dd>
<dt>Car</dt><dd><?= h(car_display_name($res)); ?></dd>
<dt>Dates</dt><dd><?= h(cr_date($res['pickup_date'])); ?> to <?= h(cr_date($res['dropoff_date'])); ?></dd>
<dt>Status</dt><dd><?= h($res['status']); ?> (<?= h($res['source']); ?>)</dd>
<?php if (!empty($res['notes'])): ?><dt>Notes</dt><dd><?= nl2br(h($res['notes'])); ?></dd><?php endif; ?>
<?php if ($res['customer_id']): ?><dt>Linked to</dt><dd><strong><?= h($res['linked_name'] ?? 'Deleted customer'); ?></strong> <?= h($res['linked_phone'] ?? ''); ?></dd><?php endif; ?>
</dl></div>

<div class="card-box"><h3>Link to an existing customer</h3>
<?php if ($matches): ?>
<p>Possible matches by phone, email or name. Check the details before linking.</p>
<table border="1" cellspacing="0" cellpadding="8" width="100%"><tr><th>Name</th><th>Phone</th><th>Email</th><th>Licence</th><th></th></tr>
<?php foreach ($matches as $m): ?><tr><td><?= h($m['full_name']); ?></td><td><?= h($m['phone_number']); ?></td><td><?= h($m['email']); ?></td><td><?= h($m['license_no']); ?></td><td><form method="POST" style="margin:0"><input type="hidden" name="customer_id" value="<?= (int)$m['id']; ?>"><button type="submit" name="link_existing" value="1">Link</button></form></td></tr><?php endforeach; ?>
</table>
<?php else: ?><p>No customer with the same phone, email or name was found.</p><?php endif; ?>
<form method="POST" class="grid2" style="margin-top:14px">
<div><label for="customer_id">Or choose any customer</label><select id="customer_id" name="customer_id" required><option value="">Select</option><?php while ($c = $allCustomers->fetch_assoc()): ?><option value="<?= (int)$c['id']; ?>"><?= h($c['full_name'] . ' - ' . $c['phone_number']); ?></option><?php endwhile; ?></select></div>
<div><button type="submit" name="link_existing" value="1">Link customer</button></div>
</form></div>

<div class="card-box"><h3>Create a new customer from this request</h3>
<form method="POST" class="grid2">
<div><label for="n_full_name">Full name <span class="req">*</span></label><input id="n_full_name" name="full_name" maxlength="255" required value="<?= h($new['full_name']); ?>"></div>
<div><label for="n_phone">Phone <span class="req">*</span></label><input id="n_phone" name="phone_number" maxlength="50" required value="<?= h($new['phone_number']); ?>"></div>
<div><label for="n_email">Email</label><input id="n_email" type="email" name="email" maxlength="255" value="<?= h($new['email']); ?>"></div>
<div><label for="n_license">Driver licence no. <span class="req">*</span></label><input id="n_license" name="license_no" maxlength="100" required value="<?= h($new['license_no']); ?>"></div>
<div><label for="n_license_expiry">Licence expiry</label><input id="n_license_expiry" type="date" name="license_expiry" value="<?= h($new['license_expiry']); ?>"></div>
<div><label for="n_id">ID number</label><input id="n_id" name="id_number" maxlength="100" value="<?= h($new['id_number']); ?>"></div>
<div><label for="n_passport">Passport no.</label><input id="n_passport" name="passport_no" maxlength="100" value="<?= h($new['passport_no']); ?>"></div>
<div><label for="n_passport_expiry">Passport expiry</label><input id="n_passport_expiry" type="date" name="passport_expiry" value="<?= h($new['passport_expiry']); ?>"></div>
<div style="grid-column:1/-1"><label for="n_address">Address</label><input id="n_address" name="address" maxlength="255" value="<?= h($new['address']); ?>"></div>
<div><button type="submit" name="create_customer" value="1">Create customer and link</button></div>
</form></div>
</div></body></html>
