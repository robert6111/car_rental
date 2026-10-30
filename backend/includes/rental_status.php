<?php
require_once __DIR__ . '/db.php';

function sync_rental_and_car_status(mysqli $conn): void
{
    // Normal rentals: when the end date has passed, automatically mark them completed and release the car.
    // Same-date rentals are left Active so the user can confirm completion manually on the rentals page.
    $conn->query("UPDATE rentals SET status = 'Completed', completed_at = COALESCE(completed_at, NOW()) WHERE status = 'Active' AND end_date < CURDATE() AND start_date <> end_date");

    // Cars with confirmed future reservations and no active rental can show as reserved.
    $conn->query("UPDATE cars c JOIN reservations r ON r.car_id = c.id AND r.status = 'Confirmed' AND r.pickup_date >= CURDATE() SET c.availability_status = 'Reserved' WHERE c.availability_status = 'Available'");

    // Cars with active rentals must show as rented.
    $conn->query("UPDATE cars c JOIN rentals r ON r.cars_id = c.id AND r.status = 'Active' SET c.availability_status = 'Rented'");

    // Cars that are marked rented but have no active rental become available again.
    // Maintenance cars are not touched.
    $conn->query("UPDATE cars c LEFT JOIN rentals r ON r.cars_id = c.id AND r.status = 'Active' SET c.availability_status = 'Available' WHERE r.id IS NULL AND c.availability_status IN ('Rented','Reserved') AND NOT EXISTS (SELECT 1 FROM reservations rr WHERE rr.car_id=c.id AND rr.status='Confirmed' AND rr.pickup_date >= CURDATE())");
}
?>
