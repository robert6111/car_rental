<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

stock_require_permission('cars_manage');
stock_validate_action_token();
$id = intval($_GET['id'] ?? 0);

// // Delete car
// $stmt = $conn->prepare("DELETE FROM cars WHERE id=?");
// $stmt->bind_param("i", $id);
// $stmt->execute();
// Start the transaction
// $conn->begin_transaction();

// try {
//     // Delete rentals associated with the car
//     $deleteRentals = $conn->prepare("DELETE FROM rentals WHERE cars_id = ?");
//     $deleteRentals->bind_param("i", $id);
//     $deleteRentals->execute();
    
//     // Now check if any rentals still exist
//     $check = $conn->prepare("SELECT id FROM rentals WHERE cars_id = ?");
//     $check->bind_param("i", $id);
//     $check->execute();
//     $check->store_result();
    
//     if ($check->num_rows > 0) {
//         header("Location: all_cars.php?error=has_rentals"); exit;
//     }
    
//     // Delete the car
//     $stmt = $conn->prepare("DELETE FROM cars WHERE id = ?");
//     $stmt->bind_param("i", $id);
//     $stmt->execute();
    
//     // Commit the transaction
//     $conn->commit();
// } catch (Exception $e) {
//     // If any error occurs, rollback the transaction
//     $conn->rollback();
//     die("Error: " . $e->getMessage());
// }

// // Close statements
// $deleteRentals->close();
// $stmt->close();
// $check->close();
//activate above codes if you want to delete the car and its associated rentals but below codes keep the car with rentals even when the car is deleted

// Check if there are any rentals associated with the car here you only mark the car as unavailable but keep the rental records associated with the car

$check = $conn->prepare("SELECT id FROM rentals WHERE cars_id = ?");
$check->bind_param("i", $id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    header("Location: all_cars.php?error=has_rentals"); exit;
}
$check->close();

// Then delete car
$stmt = $conn->prepare("DELETE FROM cars WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

// Check if the car was deleted (check the affected rows)
if ($stmt->affected_rows > 0) {
    
} else {
    
}
$stmt->close();


header("Location: all_cars.php?deleted=1");
exit;
?>
