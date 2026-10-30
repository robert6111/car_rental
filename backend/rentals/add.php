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
<?php cr_layout_start('New rental', ['head' => '<style>
.pricing-summary { max-width: 440px; margin-top: 18px; padding: 12px 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-2); font-size: 14px; }
.pricing-summary div { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; font-variant-numeric: tabular-nums; }
.pricing-summary .discount-line { color: var(--success); }
.pricing-summary .total-line { border-top: 1px solid var(--border); margin-top: 6px; padding-top: 8px; font-size: 15px; }
</style>']); ?>
<a class="back-link" href="manage.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All rentals</a>
<?= cr_page_header('New rental', 'Book a car for a customer. The price is worked out from the dates and the car\'s daily rate.'); ?>
<?= cr_alerts($msg, $error); ?>

<form method="POST" class="card">
    <h2 class="section-title">Customer and car</h2>
    <div class="form-grid">
        <div class="field">
            <label for="customer_id">Customer <span class="req">*</span></label>
            <select name="customer_id" id="customer_id" onchange="updateCost()" required>
                <option value="">Select customer</option>
                <?php while($row = $customers->fetch_assoc()): ?>
                    <?php $discountRate = loyalty_discount_rate((int)$row['completed_rentals']); ?>
                    <option value="<?= h($row['id']) ?>" data-completed="<?= h($row['completed_rentals']) ?>" <?= (int)$row['id'] === $form['customer_id'] ? 'selected' : '' ?>>
                        <?= h($row['full_name']) ?><?= $discountRate > 0 ? ' - Loyal customer discount' : '' ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="car_id">Car <span class="req">*</span></label>
            <select name="car_id" id="car_id" onchange="updateCarDetails()" required>
                <option value="">Select car</option>
                <?php while($row = $cars->fetch_assoc()): ?>
                    <option value="<?= h($row['id']) ?>" data-price="<?= h($row['price_per_day']) ?>" data-make="<?= h($row['make']) ?>" data-model="<?= h($row['model']) ?>" data-reg_no="<?= h($row['reg_no']) ?>" data-year="<?= h($row['year']) ?>" <?= (int)$row['id'] === $form['car_id'] ? 'selected' : '' ?>>
                        <?= h($row['make'] . ' ' . $row['model'] . ' ' . $row['year'] . ' - ' . $row['reg_no'] . ' [' . $row['availability_status'] . ']') ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <span class="hint" id="car_details"></span>
        </div>
    </div>

    <div class="form-section">
        <h3>Dates and places</h3>
        <div class="form-grid">
            <div class="field">
                <label for="start_date">Start date <span class="req">*</span></label>
                <input type="date" name="start_date" id="start_date" min="<?= h(cr_today()) ?>" value="<?= h($form['start_date']) ?>" onchange="updateCost()" required>
            </div>
            <div class="field">
                <label for="end_date">End date <span class="req">*</span></label>
                <input type="date" name="end_date" id="end_date" min="<?= h(cr_today()) ?>" value="<?= h($form['end_date']) ?>" onchange="updateCost()" required>
            </div>
            <div class="field">
                <label for="pickup_location">Pickup location <span class="opt">(optional)</span></label>
                <input type="text" name="pickup_location" id="pickup_location" maxlength="255" placeholder="Office / airport / hotel" value="<?= h($form['pickup_location']) ?>">
            </div>
            <div class="field">
                <label for="dropoff_location">Drop-off location <span class="opt">(optional)</span></label>
                <input type="text" name="dropoff_location" id="dropoff_location" maxlength="255" placeholder="Office / airport / hotel" value="<?= h($form['dropoff_location']) ?>">
            </div>
        </div>
    </div>

    <div class="form-section">
        <h3>Money and notes</h3>
        <div class="form-grid">
            <div class="field">
                <label for="deposit_amount">Deposit amount</label>
                <input type="text" inputmode="decimal" name="deposit_amount" id="deposit_amount" value="<?= h($form['deposit_amount']) ?>">
            </div>
            <div class="field">
                <label for="total_cost">Estimated cost</label>
                <input type="number" step="0.01" id="total_cost" readonly>
                <span class="hint">The final cost is calculated when you save.</span>
            </div>
            <div class="field span-all">
                <label for="notes">Notes <span class="opt">(optional)</span></label>
                <textarea name="notes" id="notes" rows="3"><?= h($form['notes']) ?></textarea>
            </div>
        </div>

        <div class="pricing-summary" id="pricing_summary" style="display:none;" aria-live="polite">
            <div><span>Rental days</span><strong id="summary_days">0</strong></div>
            <div><span>Subtotal</span><strong id="summary_subtotal">-</strong></div>
            <div class="discount-line"><span>Loyalty discount</span><strong id="summary_discount">-</strong></div>
            <div class="total-line"><span>Final cost</span><strong id="summary_total">-</strong></div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit"><?= cr_icon('check'); ?> Add rental</button>
        <a class="btn btn-secondary" href="manage.php">Cancel</a>
    </div>
</form>
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
<?php cr_layout_end(); ?>
