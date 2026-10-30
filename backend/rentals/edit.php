<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rental_status.php';
sync_rental_and_car_status($conn);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: manage.php');
    exit;
}

// Fetch rental
$rental_stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ?");
$rental_stmt->bind_param("i", $id);
$rental_stmt->execute();
$rental = $rental_stmt->get_result()->fetch_assoc();
$rental_stmt->close();

if (!$rental) {
    header('Location: manage.php');
    exit;
}

$msg = $error = "";
$allowed_statuses = ['Pending', 'Active', 'Completed', 'Canceled'];

// Values shown in the form (replaced by the posted values when saving fails).
$form = [
    'customer_id' => (int)$rental['customer_id'],
    'cars_id' => (int)$rental['cars_id'],
    'start_date' => (string)$rental['start_date'],
    'end_date' => (string)$rental['end_date'],
    'status' => (string)$rental['status'],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['cars_id'] = (int)($_POST['cars_id'] ?? 0);
    $form['start_date'] = trim((string)($_POST['start_date'] ?? ''));
    $form['end_date'] = trim((string)($_POST['end_date'] ?? ''));
    $form['status'] = (string)($_POST['status'] ?? '');

    $customer_id = $form['customer_id'];
    $cars_id = $form['cars_id'];
    $start_date = $form['start_date'];
    $end_date = $form['end_date'];
    $new_status = $form['status'];
    $old_car_id = (int)$rental['cars_id'];
    $today = cr_today();

    $customerExists = false;
    if ($customer_id > 0) {
        $chk = $conn->prepare("SELECT id FROM customers WHERE id = ?");
        $chk->bind_param('i', $customer_id);
        $chk->execute();
        $customerExists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
    }
    $carExists = false;
    if ($cars_id > 0) {
        $chk = $conn->prepare("SELECT id FROM cars WHERE id = ?");
        $chk->bind_param('i', $cars_id);
        $chk->execute();
        $carExists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
    }

    if ($rental['status'] === 'Deleted') {
        $error = "Deleted rentals cannot be edited.";
    } elseif (!in_array($new_status, $allowed_statuses, true)) {
        $error = "Please choose a valid status.";
    } elseif (!$customerExists) {
        $error = "Please select a valid customer.";
    } elseif (!$carExists) {
        $error = "Please select a valid car.";
    } elseif ($dateError = cr_rental_dates_error($start_date, $end_date, true)) {
        $error = $dateError;
    } elseif ($new_status === 'Completed' && $start_date > $today) {
        $error = "A rental that has not started yet cannot be marked as Completed.";
    } elseif ($new_status === 'Completed' && $rental['status'] !== 'Completed' && $rental['returned_at'] === null) {
        $error = "To finish this rental, use \"Confirm return\" on the Rentals list (it records the return and releases the car).";
    }

    if ($error === '') {
        // Pending/Active follow the start date (a future rental is Pending, a started one Active).
        if (in_array($new_status, ['Pending', 'Active'], true)) {
            $new_status = cr_rental_initial_status($start_date);
        }
        // A finished-but-not-returned rental whose end date was moved into the future is running again.
        if ($new_status === 'Completed' && $rental['returned_at'] === null && $end_date >= $today) {
            $new_status = 'Active';
        }

        $isReturned = $rental['returned_at'] !== null && !in_array($new_status, ['Pending', 'Active'], true);
        $occupiesCar = in_array($new_status, ['Pending', 'Active'], true)
            || ($new_status === 'Completed' && !$isReturned);
        $changed = $cars_id !== $old_car_id
            || $start_date !== (string)$rental['start_date']
            || $end_date !== (string)$rental['end_date']
            || $new_status !== (string)$rental['status'];

        $pricing = calculate_rental_total($conn, $customer_id, $cars_id, $start_date, $end_date, $id);
        $total_cost = (float)$pricing['total'];
        $discount_amount = (float)$pricing['discount_amount'];

        $conn->begin_transaction();
        try {
            $reason = null;
            if ($occupiesCar && $changed) {
                if (!cr_lock_car($conn, $cars_id)) throw new RuntimeException('Car not found while locking.');
                $reason = cr_car_unavailable_reason($conn, $cars_id, $start_date, $end_date, $id);
            }
            if ($reason !== null) {
                $conn->rollback();
                $error = $reason;
            } else {
                // Canceled: the car was never out or is back. Re-opened (Pending/Active): the car is not back yet.
                $stmt = $conn->prepare("UPDATE rentals SET customer_id=?, cars_id=?, start_date=?, end_date=?, total_cost=?, discount_amount=?, status=?,
                        returned_at = CASE WHEN ? = 'Canceled' THEN COALESCE(returned_at, NOW())
                                           WHEN ? IN ('Pending','Active') THEN NULL
                                           ELSE returned_at END,
                        completed_at = CASE WHEN ? IN ('Pending','Active') THEN NULL ELSE completed_at END
                    WHERE id=?");
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param("iissddssssi", $customer_id, $cars_id, $start_date, $end_date, $total_cost, $discount_amount, $new_status, $new_status, $new_status, $new_status, $id);
                if (!$stmt->execute()) {
                    $dbErr = $stmt->error;
                    $stmt->close();
                    $conn->rollback();
                    $error = cr_db_error($dbErr);
                } else {
                    $stmt->close();
                    $conn->commit();
                    cr_sync_car_statuses($conn, $old_car_id);
                    if ($cars_id !== $old_car_id) cr_sync_car_statuses($conn, $cars_id);
                    header("Location: manage.php?updated=1");
                    exit;
                }
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $error = cr_db_error($e->getMessage());
        }
    }
}

// Customers (completed rentals exclude this one, the same way the server calculates the discount).
$cstmt = $conn->prepare("SELECT c.id, c.full_name, COUNT(r.id) AS completed_rentals
    FROM customers c
    LEFT JOIN rentals r ON r.customer_id = c.id AND r.status = 'Completed' AND r.id <> ?
    GROUP BY c.id, c.full_name
    ORDER BY c.full_name ASC");
$cstmt->bind_param('i', $id);
$cstmt->execute();
$customers = $cstmt->get_result();
$cstmt->close();

// Cars: everything except maintenance, plus the rental's current car whatever its status.
$currentCarId = (int)$rental['cars_id'];
$carStmt = $conn->prepare("SELECT c.id, c.reg_no, c.price_per_day, c.availability_status, m.name AS make_name, mo.name AS model_name, y.year AS car_year
    FROM cars c
    LEFT JOIN makes m ON c.make_id = m.id
    LEFT JOIN models mo ON c.model_id = mo.id
    LEFT JOIN car_years y ON c.year_id = y.id
    WHERE c.availability_status <> 'Maintenance' OR c.id = ?
    ORDER BY m.name ASC, mo.name ASC, y.year DESC, c.reg_no ASC");
$carStmt->bind_param('i', $currentCarId);
$carStmt->execute();
$cars = $carStmt->get_result();
$carStmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Rental</title>
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>

<div class="content-page">
    <h2>Edit Rental</h2>

    <?php if($msg) echo "<p style='color:green;'>" . h($msg) . "</p>"; ?>
    <?php if($error) echo "<p style='color:red;'>" . h($error) . "</p>"; ?>

    <form method="POST">
        <label>Customer:</label><br>
        <select name="customer_id" id="customer_id" onchange="updateCost()" required>
            <option value="">Select Customer</option>
            <?php while($c = $customers->fetch_assoc()): ?>
                <option value="<?= h($c['id']) ?>" data-completed="<?= h($c['completed_rentals']) ?>" <?= $form['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['full_name']) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Car:</label><br>
        <select name="cars_id" id="cars_id" onchange="updateCost()" required>
            <option value="">Select Car</option>
            <?php while($car = $cars->fetch_assoc()): ?>
                <option value="<?= h($car['id']) ?>" data-price="<?= h($car['price_per_day']) ?>"
                    <?= $form['cars_id'] === (int)$car['id'] ? 'selected' : '' ?>>
                    <?= h(trim(($car['make_name'] ?? '') . ' ' . ($car['model_name'] ?? '') . ' ' . ($car['car_year'] ?? '') . ' - ' . $car['reg_no']) . ' [' . $car['availability_status'] . ']' . ((int)$car['id'] === $currentCarId ? ' (current)' : '')) ?>
                </option>
            <?php endwhile; ?>
        </select><br><br>

        <label>Start Date:</label><br>
        <input type="date" id="start_date" name="start_date" value="<?= h($form['start_date']) ?>" onchange="updateCost()" required><br><br>

        <label>End Date:</label><br>
        <input type="date" id="end_date" name="end_date" value="<?= h($form['end_date']) ?>" onchange="updateCost()" required><br><br>

        <label>Cost (final amount is calculated on save):</label><br>
        <input type="text" id="total_cost" value="<?= h(money_text((float)$rental['total_cost'])) ?>" readonly><br><br>

        <script>
        const MONEY = <?= cr_money_js_config() ?>;
        function fmtMoney(n){const neg=n<0;n=Math.abs(n);let [i,f]=n.toFixed(MONEY.decimals).split('.');i=i.replace(/\B(?=(\d{3})+(?!\d))/g,MONEY.thou);const num=f?i+MONEY.dec+f:i;const out=MONEY.position==='after'?num+' '+MONEY.symbol:MONEY.symbol+(MONEY.symbol.length>1?' ':'')+num;return (neg?'-':'')+out;}
        const loyaltyMinRentals = <?= (int)loyalty_min_rentals() ?>;
        const loyaltyDiscountRate = <?= json_encode(loyalty_discount_fraction()) ?>;
        function updateCost() {
            const carSelect = document.getElementById('cars_id');
            const custSelect = document.getElementById('customer_id');
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const costInput = document.getElementById('total_cost');

            if (!carSelect.value || !custSelect.value || !startDate || !endDate) {
                costInput.value = '';
                return;
            }
            const pricePerDay = parseFloat(carSelect.options[carSelect.selectedIndex].dataset.price);
            if (isNaN(pricePerDay)) {
                costInput.value = '';
                return;
            }
            const start = new Date(startDate);
            const end = new Date(endDate);
            if (end < start) {
                alert("End date cannot be before start date");
                costInput.value = '';
                return;
            }
            const days = Math.max(1, Math.ceil((end.getTime() - start.getTime()) / (1000 * 3600 * 24)));
            const completed = parseInt(custSelect.options[custSelect.selectedIndex].dataset.completed || '0', 10);
            const rate = completed >= loyaltyMinRentals ? loyaltyDiscountRate : 0;
            const subtotal = pricePerDay * days;
            const discount = subtotal * rate;
            const total = Math.max(0, subtotal - discount);
            costInput.value = fmtMoney(total) + (discount > 0 ? ' (incl. loyalty discount ' + fmtMoney(discount) + ')' : '');
        }
        window.onload = updateCost;
        </script>

        <label>Status:</label><br>
        <select name="status" required>
            <?php foreach ($allowed_statuses as $s): ?>
                <option value="<?= h($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select><br>
        <small>Future rentals are kept as Pending until their start date. To finish a rental, use "Confirm return" on the Rentals list.</small><br><br>

        <button type="submit">Update Rental</button>
    </form>

    <a href="manage.php">Back to Rentals</a>
</div>
</body>
</html>
