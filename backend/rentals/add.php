<?php
require_once __DIR__ . '/../includes/auth.php';

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = $error = "";

// Availability depends on the dates, so every car that is not in maintenance can be chosen.
$cars = $conn->query("SELECT cars.id, cars.reg_no, cars.price_per_day, cars.availability_status,
           makes.name AS make, models.name AS model, car_years.year AS year
    FROM cars
    JOIN makes ON cars.make_id = makes.id
    JOIN models ON cars.model_id = models.id
    JOIN car_years ON cars.year_id = car_years.id
    WHERE cars.availability_status <> 'Maintenance'
    ORDER BY makes.name ASC, models.name ASC, car_years.year DESC, cars.reg_no ASC");

$customers = $conn->query("SELECT c.id, c.full_name, COUNT(r.id) AS completed_rentals
    FROM customers c
    LEFT JOIN rentals r ON r.customer_id = c.id AND r.status = 'Completed'
    GROUP BY c.id, c.full_name
    ORDER BY c.full_name ASC");

$form = [
    'customer_id' => 0,
    'car_id' => 0,
    'start_date' => '',
    'end_date' => '',
    'pickup_location' => '',
    'dropoff_location' => '',
    'deposit_amount' => '0',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['car_id'] = (int)($_POST['car_id'] ?? 0);
    $form['start_date'] = trim((string)($_POST['start_date'] ?? ''));
    $form['end_date'] = trim((string)($_POST['end_date'] ?? ''));
    $form['pickup_location'] = mb_substr(trim((string)($_POST['pickup_location'] ?? '')), 0, 255);
    $form['dropoff_location'] = mb_substr(trim((string)($_POST['dropoff_location'] ?? '')), 0, 255);
    $form['deposit_amount'] = trim((string)($_POST['deposit_amount'] ?? ''));
    $form['notes'] = trim((string)($_POST['notes'] ?? ''));

    $customer_id = $form['customer_id'];
    $car_id = $form['car_id'];
    $start_date = $form['start_date'];
    $end_date = $form['end_date'];
    $pickup_location = $form['pickup_location'];
    $dropoff_location = $form['dropoff_location'];
    $notes = $form['notes'];
    $deposit_amount = $form['deposit_amount'] === '' ? 0.0 : cr_parse_money($form['deposit_amount']);

    $customerExists = false;
    if ($customer_id > 0) {
        $chk = $conn->prepare("SELECT id FROM customers WHERE id = ?");
        $chk->bind_param('i', $customer_id);
        $chk->execute();
        $customerExists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
    }
    $carRow = null;
    if ($car_id > 0) {
        $chk = $conn->prepare("SELECT id, availability_status FROM cars WHERE id = ?");
        $chk->bind_param('i', $car_id);
        $chk->execute();
        $carRow = $chk->get_result()->fetch_assoc() ?: null;
        $chk->close();
    }

    if (!$customerExists) {
        $error = "Please select a valid customer.";
    } elseif (!$carRow) {
        $error = "Please select a valid car.";
    } elseif ($dateError = cr_rental_dates_error($start_date, $end_date)) {
        $error = $dateError;
    } elseif ($deposit_amount === null || $deposit_amount < 0) {
        $error = "Please enter a valid deposit amount (0 or more).";
    } else {
        $conn->begin_transaction();
        try {
            if (!cr_lock_car($conn, $car_id)) {
                throw new RuntimeException('Car not found while locking.');
            }
            $reason = cr_car_unavailable_reason($conn, $car_id, $start_date, $end_date);
            if ($reason !== null) {
                $conn->rollback();
                $error = $reason;
            } else {
                $pricing = calculate_rental_total($conn, $customer_id, $car_id, $start_date, $end_date);
                $total_cost = (float)$pricing['total'];
                $discount_amount = (float)$pricing['discount_amount'];
                $status = cr_rental_initial_status($start_date);

                $stmt = $conn->prepare("INSERT INTO rentals (customer_id, cars_id, start_date, end_date, pickup_location, dropoff_location, total_cost, deposit_amount, discount_amount, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param("iissssdddss", $customer_id, $car_id, $start_date, $end_date, $pickup_location, $dropoff_location, $total_cost, $deposit_amount, $discount_amount, $notes, $status);
                if (!$stmt->execute()) {
                    $dbErr = $stmt->error;
                    $stmt->close();
                    $conn->rollback();
                    $error = cr_db_error($dbErr);
                } else {
                    $stmt->close();
                    $conn->commit();
                    cr_sync_car_statuses($conn, $car_id);
                    header("Location: manage.php?added=1");
                    exit;
                }
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $error = cr_db_error($e->getMessage());
        }
    }
}
?>
<head>
    <title>Add Rental</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/all_cars.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <style>.pricing-summary{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:10px 0 18px;max-width:520px;box-shadow:0 4px 12px rgba(15,23,42,.05)}.pricing-summary div{display:flex;justify-content:space-between;gap:12px;margin:4px 0}.discount-line{color:#166534;font-weight:700}</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <h2>Add New Rental</h2>
    <?php if($msg) echo "<p style='color:green;'>" . h($msg) . "</p>"; ?>
    <?php if($error) echo "<p style='color:red;'>" . h($error) . "</p>"; ?>

    <form method="POST">
        <label>Customer:</label><br>
        <select name="customer_id" id="customer_id" onchange="updateCost()" required>
            <option value="">Select Customer</option>
            <?php while($row = $customers->fetch_assoc()): ?>
                <?php $discountRate = loyalty_discount_rate((int)$row['completed_rentals']); ?>
                <option value="<?= h($row['id']) ?>" data-completed="<?= h($row['completed_rentals']) ?>" <?= (int)$row['id'] === $form['customer_id'] ? 'selected' : '' ?>>
                    <?= h($row['full_name']) ?><?= $discountRate > 0 ? ' - Loyal customer discount' : '' ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Car:</label><br>
        <select name="car_id" id="car_id" onchange="updateCarDetails()" required>
            <option value="">Select Car</option>
            <?php while($row = $cars->fetch_assoc()): ?>
                <option value="<?= h($row['id']) ?>" data-price="<?= h($row['price_per_day']) ?>" data-make="<?= h($row['make']) ?>" data-model="<?= h($row['model']) ?>" data-reg_no="<?= h($row['reg_no']) ?>" data-year="<?= h($row['year']) ?>" <?= (int)$row['id'] === $form['car_id'] ? 'selected' : '' ?>>
                    <?= h($row['make'] . ' ' . $row['model'] . ' ' . $row['year'] . ' - ' . $row['reg_no'] . ' [' . $row['availability_status'] . ']') ?>
                </option>
            <?php endwhile; ?>
        </select>
        <div id="car_details" style="margin-top: 10px; font-weight: bold;"></div><br>

        <label>Start Date:</label><br><input type="date" name="start_date" id="start_date" min="<?= h(cr_today()) ?>" value="<?= h($form['start_date']) ?>" onchange="updateCost()" required><br><br>
        <label>End Date:</label><br><input type="date" name="end_date" id="end_date" min="<?= h(cr_today()) ?>" value="<?= h($form['end_date']) ?>" onchange="updateCost()" required><br><br>
        <label>Pickup Location:</label><br><input type="text" name="pickup_location" placeholder="Office / airport / hotel" value="<?= h($form['pickup_location']) ?>"><br><br>
        <label>Dropoff Location:</label><br><input type="text" name="dropoff_location" placeholder="Office / airport / hotel" value="<?= h($form['dropoff_location']) ?>"><br><br>
        <label>Deposit Amount:</label><br><input type="text" inputmode="decimal" name="deposit_amount" value="<?= h($form['deposit_amount']) ?>"><br><br>
        <label>Notes:</label><br><textarea name="notes" rows="3"><?= h($form['notes']) ?></textarea><br><br>

        <div class="pricing-summary" id="pricing_summary" style="display:none;">
            <div><span>Rental Days</span><strong id="summary_days">0</strong></div>
            <div><span>Subtotal</span><strong id="summary_subtotal">-</strong></div>
            <div class="discount-line"><span>Loyalty Discount</span><strong id="summary_discount">-</strong></div>
            <div><span>Final Cost</span><strong id="summary_total">-</strong></div>
        </div>

        <label>Cost (calculated on save):</label><br><input type="number" step="0.01" id="total_cost" readonly><br><br>
        <button type="submit">Add Rental</button>
    </form>
</div>
<script>
const MONEY = <?= cr_money_js_config() ?>;
function fmtMoney(n){const neg=n<0;n=Math.abs(n);let [i,f]=n.toFixed(MONEY.decimals).split('.');i=i.replace(/\B(?=(\d{3})+(?!\d))/g,MONEY.thou);const num=f?i+MONEY.dec+f:i;const out=MONEY.position==='after'?num+' '+MONEY.symbol:MONEY.symbol+(MONEY.symbol.length>1?' ':'')+num;return (neg?'-':'')+out;}
const loyaltyMinRentals = <?= (int)loyalty_min_rentals() ?>;
const loyaltyDiscountRate = <?= json_encode(loyalty_discount_fraction()) ?>;
function updateCarDetails(){const s=document.getElementById('car_id'),d=document.getElementById('car_details'),o=s.options[s.selectedIndex];d.textContent=(o&&o.value)?'Car: '+(o.dataset.make||'')+' '+(o.dataset.model||'')+' '+(o.dataset.year||'')+' - Reg No: '+(o.dataset.reg_no||''):'';updateCost();}
function rentalDays(a,b){const s=new Date(a),e=new Date(b);if(e<s)return 0;return Math.max(1,Math.ceil((e-s)/(1000*3600*24)));}
function updateCost(){const car=document.getElementById('car_id'),cust=document.getElementById('customer_id'),start=document.getElementById('start_date').value,end=document.getElementById('end_date').value,input=document.getElementById('total_cost'),sum=document.getElementById('pricing_summary');if(!car.value||!cust.value||!start||!end){input.value='';sum.style.display='none';return;}const days=rentalDays(start,end);if(!days){alert('End date cannot be before start date');input.value='';sum.style.display='none';return;}const price=parseFloat(car.options[car.selectedIndex].dataset.price||'0'),completed=parseInt(cust.options[cust.selectedIndex].dataset.completed||'0',10),rate=completed>=loyaltyMinRentals?loyaltyDiscountRate:0,subtotal=price*days,discount=subtotal*rate,total=Math.max(0,subtotal-discount);input.value=total.toFixed(2);document.getElementById('summary_days').textContent=days;document.getElementById('summary_subtotal').textContent=fmtMoney(subtotal);document.getElementById('summary_discount').textContent=fmtMoney(discount);document.getElementById('summary_total').textContent=fmtMoney(total);sum.style.display='block';}
document.addEventListener('DOMContentLoaded', updateCarDetails);
</script>
</body>
