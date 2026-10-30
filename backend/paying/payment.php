<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$result = $conn->query("
    SELECT 
        r.id AS rental_id,
        r.total_cost,
        IFNULL(SUM(p.amount), 0) AS total_paid,
        (r.total_cost - IFNULL(SUM(p.amount), 0)) AS remaining,
        CASE WHEN r.total_cost <= IFNULL(SUM(p.amount), 0) THEN 'finished' ELSE 'in_debt' END AS status
    FROM rentals r
    LEFT JOIN payment p ON r.id = p.rental_id
    GROUP BY r.id
    ORDER BY r.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Rental Payments</title></head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <div class="card-box">
        <h2>Rental Payments</h2>
        <div class="table-responsive"><table class="table" border="1" cellpadding="10">
            <tr><th>Rental ID</th><th>Total Cost</th><th>Total Paid</th><th>Remaining</th><th>Status</th></tr>
            <?php if($result && $result->num_rows > 0): while($rental = $result->fetch_assoc()): ?>
            <tr>
                <td><?= h($rental['rental_id']); ?></td>
                <td><?= number_format((float)$rental['total_cost'], 2); ?> TRY</td>
                <td><?= number_format((float)$rental['total_paid'], 2); ?> TRY</td>
                <td><?= number_format((float)$rental['remaining'], 2); ?> TRY</td>
                <td><?= h(ucfirst($rental['status'])); ?></td>
            </tr>
            <?php endwhile; else: ?><tr><td colspan="5">No payments found.</td></tr><?php endif; ?>
        </table></div>
    </div>
</div>
</body></html>
