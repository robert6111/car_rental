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
<?php cr_layout_start('Link customer'); ?>
<a class="back-link" href="manage.php">All reservations</a>
<?= cr_page_header('Link booking request #' . (int)$id . ' to a customer', 'Check the person first, then link an existing customer or create a new one.'); ?>
<?= cr_alerts('', $error); ?>

<div class="card">
    <div class="card-head"><h2>Request details</h2><?= cr_status_badge((string)$res['status']); ?></div>
    <dl class="details">
        <dt>Name</dt><dd><?= h($res['customer_name'] ?: '-'); ?></dd>
        <dt>Phone</dt><dd><?= h($res['phone_number'] ?: '-'); ?></dd>
        <dt>Email</dt><dd><?= h($res['email'] ?: '-'); ?></dd>
        <dt>Car</dt><dd><?= h(car_display_name(array_merge($res, ['reg_no' => '']))); ?><?php if (!empty($res['reg_no'])): ?> <span class="plate"><?= h($res['reg_no']); ?></span><?php endif; ?></dd>
        <dt>Dates</dt><dd><?= h(cr_date($res['pickup_date'])); ?> to <?= h(cr_date($res['dropoff_date'])); ?></dd>
        <dt>Source</dt><dd><?= h($res['source']); ?></dd>
        <?php if (!empty($res['notes'])): ?><dt>Notes</dt><dd><?= nl2br(h($res['notes'])); ?></dd><?php endif; ?>
        <?php if ($res['customer_id']): ?><dt>Linked to</dt><dd><span class="cell-title"><?= h($res['linked_name'] ?? 'Deleted customer'); ?></span> <span class="muted"><?= h($res['linked_phone'] ?? ''); ?></span></dd><?php endif; ?>
    </dl>
</div>

<div class="card">
    <div class="card-head"><h2>Link to an existing customer</h2></div>
    <?php if ($matches): ?>
        <p class="muted">Possible matches by phone, email or name. Check the details before linking.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Licence</th><th><span class="sr-only">Action</span></th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td class="cell-title"><?= h($m['full_name']); ?></td>
                        <td><?= h($m['phone_number']); ?></td>
                        <td><?= h($m['email']); ?></td>
                        <td><?= h($m['license_no']); ?></td>
                        <td class="actions-cell text-right">
                            <form method="POST" class="mb-0">
                                <input type="hidden" name="customer_id" value="<?= (int)$m['id']; ?>">
                                <button type="submit" name="link_existing" value="1" class="btn-sm">Link</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="muted">No customer with the same phone, email or name was found.</p>
    <?php endif; ?>
    <form method="POST" class="filters mt-4 mb-0">
        <div class="field grow">
            <label for="customer_id">Or choose any customer</label>
            <select id="customer_id" name="customer_id" required>
                <option value="">Select a customer</option>
                <?php while ($c = $allCustomers->fetch_assoc()): ?>
                    <option value="<?= (int)$c['id']; ?>"><?= h($c['full_name'] . ' - ' . $c['phone_number']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <button type="submit" name="link_existing" value="1">Link customer</button>
    </form>
</div>

<form method="POST" class="card">
    <h2 class="section-title">Create a new customer from this request</h2>
    <div class="form-grid">
        <div class="field">
            <label for="n_full_name">Full name <span class="req">*</span></label>
            <input type="text" id="n_full_name" name="full_name" maxlength="255" required value="<?= h($new['full_name']); ?>">
        </div>
        <div class="field">
            <label for="n_phone">Phone number <span class="req">*</span></label>
            <input type="tel" id="n_phone" name="phone_number" maxlength="50" required value="<?= h($new['phone_number']); ?>">
        </div>
        <div class="field">
            <label for="n_email">Email <span class="opt">(optional)</span></label>
            <input type="email" id="n_email" name="email" maxlength="255" value="<?= h($new['email']); ?>">
        </div>
        <div class="field span-all">
            <label for="n_address">Address <span class="opt">(optional)</span></label>
            <input type="text" id="n_address" name="address" maxlength="255" value="<?= h($new['address']); ?>">
        </div>
    </div>
    <div class="form-section">
        <h3>Licence and identity</h3>
        <div class="form-grid">
            <div class="field">
                <label for="n_license">Driver licence number <span class="req">*</span></label>
                <input type="text" id="n_license" name="license_no" maxlength="100" required value="<?= h($new['license_no']); ?>">
            </div>
            <div class="field">
                <label for="n_license_expiry">Licence expiry</label>
                <input type="date" id="n_license_expiry" name="license_expiry" value="<?= h($new['license_expiry']); ?>">
            </div>
            <div class="field">
                <label for="n_id">ID number</label>
                <input type="text" id="n_id" name="id_number" maxlength="100" value="<?= h($new['id_number']); ?>">
            </div>
            <div class="field">
                <label for="n_passport">Passport number</label>
                <input type="text" id="n_passport" name="passport_no" maxlength="100" value="<?= h($new['passport_no']); ?>">
            </div>
            <div class="field">
                <label for="n_passport_expiry">Passport expiry</label>
                <input type="date" id="n_passport_expiry" name="passport_expiry" value="<?= h($new['passport_expiry']); ?>">
            </div>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" name="create_customer" value="1">Create customer and link</button>
        <a class="btn btn-secondary" href="manage.php">Cancel</a>
    </div>
</form>
<?php cr_layout_end(); ?>
