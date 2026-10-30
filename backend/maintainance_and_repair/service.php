<?php
require_once __DIR__ . '/../includes/auth.php';
error_reporting(0);
ini_set('display_errors', 0);

require __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../authentication/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle empty mileage_at_service input
    $mileage_at_service = !empty($_POST['mileage_at_service']) ? $_POST['mileage_at_service'] : NULL;

    $sql = "INSERT INTO car_service
    (car_id, service_type, description, service_date, mileage_at_service, next_service_mileage, cost)
    VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issssid",
        $_POST['car_id'],
        $_POST['service_type'],
        $_POST['description'],
        $_POST['service_date'],
        $mileage_at_service,  // Use the validated mileage_at_service
        $_POST['next_service_mileage'],
        $_POST['cost'] // This is the raw value of cost (numeric)
    );

    if ($stmt->execute()) {
        // Set success message in session
        $_SESSION['success'] = "Service record added successfully!";
    } else {
        // Set error message in session
        $_SESSION['error'] = "Error: " . $stmt->error;
    }

    // Redirect to the same page to prevent form resubmission on refresh
    header("Location: service.php"); 
    exit; // Stop further script execution after redirect
}


// Fetch all cars for dropdown (no change to existing code)
$allCars = $conn->query("
    SELECT 
        cars.id AS car_id,
        cars.reg_no,
        models.name AS model_name,
        makes.name AS make_name
    FROM cars
    JOIN models ON cars.model_id = models.id
    JOIN makes ON cars.make_id = makes.id
    ORDER BY makes.name, models.name
");

?>

<!DOCTYPE html>
<html>
<head>
    <title>car services</title>

    <link rel="stylesheet" href="/../backend/includes/css/dashboard.css">
    <link rel="stylesheet" href="/../backend/includes/css/common.css">
    <link rel="stylesheet" href="/../backend/includes/css/header.css">
    <link rel="stylesheet" href="/../backend/includes/css/all_cars.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/leftsidebar.php';
?>
<div class="content-page">
    <?php
if (isset($_SESSION['success'])) {
    echo "<div style='text-align:center; padding:10px; color:green; font-weight:bold;'>"
         . $_SESSION['success'] . "</div>";
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo "<div style='text-align:center; padding:10px; color:red; font-weight:bold;'>"
         . $_SESSION['error'] . "</div>";
    unset($_SESSION['error']);
}
?>

   <h2>Add Vehicle Service / Maintenance Record</h2>

<form action="service.php" method="POST">

    <!-- REPLACED INPUT WITH DROPDOWN WITHOUT TOUCHING ANY OTHER CODE -->
    <label>Select Car:</label><br>
    <select name="car_id" required>
        <option value="">-- Select Car --</option>

        <?php while ($car = $allCars->fetch_assoc()) : ?>
            <option value="<?= $car['car_id'] ?>">
                <?= $car['make_name'] . ' ' . $car['model_name'] . ' (' . $car['reg_no'] . ')' ?>
            </option>
        <?php endwhile; ?>

    </select>
    <br><br>

    <label>Service Type:</label><br>
    <input type="text" name="service_type" placeholder="Oil change, Tire replacement, etc" required><br><br>

    <label>Description:</label><br>
    <textarea name="description" rows="4" cols="40"></textarea><br><br>

    <label>Service Date:</label><br>
    <input type="date" name="service_date" required><br><br>

    
    <label>Mileage at Service:</label><br>
    <input type="number" name="mileage_at_service"><br><br>

    <label>Next Service Mileage:</label><br>
    <input type="number" name="next_service_mileage"><br><br>
   
<script>
function formatMileage(inputId) {
    let input = document.getElementById(inputId);
    input.addEventListener("input", function() {
        let value = this.value.replace(/[^\d,]/g, ""); // Allow only digits and commas
        let parts = value.split(",");
        if (parts.length > 1) {
            parts[1] = parts[1].substring(0, 3); // Limit decimal places to 2
        }
        value = parts.join(","); // Join integer and decimal parts
        
        // Format as Turkish currency style
        if (value) {
            let splitValue = value.split(",");
            let integerPart = splitValue[0];
            let decimalPart = splitValue[1] ? "," + splitValue[1] : "";
            let formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            this.value = formattedInteger + decimalPart;
        }
    });
}

formatMileage("mileage_at_service");
formatMileage("next_service_mileage");
</script>

   <label>Service Cost:</label><br>
<input type="text" id="cost" name="cost"><br>

<script>
document.getElementById("cost").addEventListener("input", function() {
    let value = this.value.replace(/[^\d,]/g, ""); // Only allow digits and commas
    let parts = value.split(",");
    if (parts.length > 1) {
        parts[1] = parts[1].substring(0, 2); // Limit decimal places to 2
    }
    value = parts.join(",");
    
    // Format as Turkish currency style
    if (value) {
        let splitValue = value.split(",");
        let integerPart = splitValue[0];
        let decimalPart = splitValue[1] ? "," + splitValue[1] : "";
        let formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        this.value = formattedInteger + decimalPart;
    }
});

// Before form submission, clean the value to remove commas and send only the numeric part
document.querySelector('form').addEventListener('submit', function(event) {
    let costInput = document.getElementById("cost");
    let rawValue = costInput.value.replace(/[^\d,]/g, ""); // Remove commas
    rawValue = rawValue.replace(/\./g, ""); // Remove periods for numeric conversion

    costInput.value = rawValue; // Set the cleaned numeric value back to the input
});
</script>

 <br>

    <input type="submit" value="Save Service Record">

</form>

</div>
</body>
</html>
