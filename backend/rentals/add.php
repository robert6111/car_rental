<?php
require_once __DIR__ . '/../includes/auth.php';

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

$msg = $error = "";

$cars = $conn->query("SELECT cars.id, cars.reg_no, cars.price_per_day, cars.availability_status,
           makes.name AS make, models.name AS model, car_years.year AS year
    FROM cars
    JOIN makes ON cars.make_id = makes.id
    JOIN models ON cars.model_id = models.id
    JOIN car_years ON cars.year_id = car_years.id
    WHERE cars.availability_status = 'Available'
    ORDER BY makes.name ASC, models.name ASC, car_years.year DESC, cars.reg_no ASC");

$customers = $conn->query("SELECT c.id, c.full_name, COUNT(r.id) AS completed_rentals
    FROM customers c
    LEFT JOIN rentals r ON r.customer_id = c.id AND r.status = 'Completed'
    GROUP BY c.id, c.full_name
    ORDER BY c.full_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $car_id = intval($_POST['car_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $pickup_location = trim($_POST['pickup_location'] ?? '');
    $dropoff_location = trim($_POST['dropoff_location'] ?? '');
    $deposit_amount = money_value($_POST['deposit_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$customer_id || !$car_id || !$start_date || !$end_date || $end_date < $start_date) {
        $error = "Please select a customer, car, and a valid rental date range.";
    } else {
        $pricing = calculate_rental_total($conn, $customer_id, $car_id, $start_date, $end_date);
        $total_cost = (float)$pricing['total'];
        $discount_amount = (float)$pricing['discount_amount'];

        $stmt = $conn->prepare("INSERT INTO rentals (customer_id, cars_id, start_date, end_date, pickup_location, dropoff_location, total_cost, deposit_amount, discount_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssddds", $customer_id, $car_id, $start_date, $end_date, $pickup_location, $dropoff_location, $total_cost, $deposit_amount, $discount_amount, $notes);

        if ($stmt->execute()) {
            $update_car = $conn->prepare("UPDATE cars SET availability_status='Rented' WHERE id=?");
            $update_car->bind_param("i", $car_id);
            $update_car->execute();
            $update_car->close();
            header("Location: manage.php?added=1");
            exit;
        } else {
            $error = cr_db_error($stmt->error);
        }
        $stmt->close();
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
                <option value="<?= h($row['id']) ?>" data-completed="<?= h($row['completed_rentals']) ?>">
                    <?= h($row['full_name']) ?><?= $discountRate > 0 ? ' - Loyal customer discount' : '' ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Car:</label><br>
        <select name="car_id" id="car_id" onchange="updateCarDetails()" required>
            <option value="">Select Car</option>
            <?php while($row = $cars->fetch_assoc()): ?>
                <option value="<?= h($row['id']) ?>" data-price="<?= h($row['price_per_day']) ?>" data-make="<?= h($row['make']) ?>" data-model="<?= h($row['model']) ?>" data-reg_no="<?= h($row['reg_no']) ?>" data-year="<?= h($row['year']) ?>">
                    <?= h($row['make'] . ' ' . $row['model'] . ' ' . $row['year'] . ' - ' . $row['reg_no']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <div id="car_details" style="margin-top: 10px; font-weight: bold;"></div><br>

        <label>Start Date:</label><br><input type="date" name="start_date" id="start_date" onchange="updateCost()" required><br><br>
        <label>End Date:</label><br><input type="date" name="end_date" id="end_date" onchange="updateCost()" required><br><br>
        <label>Pickup Location:</label><br><input type="text" name="pickup_location" placeholder="Office / airport / hotel"><br><br>
        <label>Dropoff Location:</label><br><input type="text" name="dropoff_location" placeholder="Office / airport / hotel"><br><br>
        <label>Deposit Amount:</label><br><input type="number" step="0.01" name="deposit_amount" value="0"><br><br>
        <label>Notes:</label><br><textarea name="notes" rows="3"></textarea><br><br>

        <div class="pricing-summary" id="pricing_summary" style="display:none;">
            <div><span>Rental Days</span><strong id="summary_days">0</strong></div>
            <div><span>Subtotal</span><strong id="summary_subtotal">-</strong></div>
            <div class="discount-line"><span>Loyalty Discount</span><strong id="summary_discount">-</strong></div>
            <div><span>Final Cost</span><strong id="summary_total">-</strong></div>
        </div>

        <label>Cost:</label><br><input type="number" step="0.01" name="total_cost" id="total_cost" readonly><br><br>
        <button type="submit">Add Rental</button>
    </form>
</div>
<script>
const MONEY = <?= cr_money_js_config() ?>;
function fmtMoney(n){const neg=n<0;n=Math.abs(n);let [i,f]=n.toFixed(MONEY.decimals).split('.');i=i.replace(/\B(?=(\d{3})+(?!\d))/g,MONEY.thou);const num=f?i+MONEY.dec+f:i;const out=MONEY.position==='after'?num+' '+MONEY.symbol:MONEY.symbol+(MONEY.symbol.length>1?' ':'')+num;return (neg?'-':'')+out;}
const loyaltyMinRentals = <?= (int)loyalty_min_rentals() ?>;
const loyaltyDiscountRate = <?= json_encode(loyalty_discount_fraction()) ?>;
function updateCarDetails(){const s=document.getElementById('car_id'),d=document.getElementById('car_details'),o=s.options[s.selectedIndex];d.innerHTML=(o&&o.value)?'Car: '+(o.dataset.make||'')+' '+(o.dataset.model||'')+' '+(o.dataset.year||'')+' - Reg No: '+(o.dataset.reg_no||''):'';updateCost();}
function rentalDays(a,b){const s=new Date(a),e=new Date(b);if(e<s)return 0;return Math.max(1,Math.ceil((e-s)/(1000*3600*24)));}
function updateCost(){const car=document.getElementById('car_id'),cust=document.getElementById('customer_id'),start=document.getElementById('start_date').value,end=document.getElementById('end_date').value,input=document.getElementById('total_cost'),sum=document.getElementById('pricing_summary');if(!car.value||!cust.value||!start||!end){input.value='';sum.style.display='none';return;}const days=rentalDays(start,end);if(!days){alert('End date cannot be before start date');input.value='';sum.style.display='none';return;}const price=parseFloat(car.options[car.selectedIndex].dataset.price||'0'),completed=parseInt(cust.options[cust.selectedIndex].dataset.completed||'0',10),rate=completed>=loyaltyMinRentals?loyaltyDiscountRate:0,subtotal=price*days,discount=subtotal*rate,total=Math.max(0,subtotal-discount);input.value=total.toFixed(2);document.getElementById('summary_days').textContent=days;document.getElementById('summary_subtotal').textContent=fmtMoney(subtotal);document.getElementById('summary_discount').textContent=fmtMoney(discount);document.getElementById('summary_total').textContent=fmtMoney(total);sum.style.display='block';}
</script>
</body>
