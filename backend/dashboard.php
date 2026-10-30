<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rental_status.php';

sync_rental_and_car_status($conn);

require_once __DIR__ . '/backup/auto_backup.php';
run_daily_auto_backup($conn);

function count_rows(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    return $result ? (int)$result->num_rows : 0;
}

$totalCars      = count_rows($conn, "SELECT id FROM cars");
$availableCars  = count_rows($conn, "SELECT id FROM cars WHERE availability_status = 'Available'");
$rentedCars     = count_rows($conn, "SELECT id FROM cars WHERE availability_status = 'Rented'");
$totalCustomers = count_rows($conn, "SELECT id FROM customers");
$totalRentals   = count_rows($conn, "SELECT id FROM rentals");
$totalPayments  = count_rows($conn, "SELECT id FROM payment");
$totalUsers     = count_rows($conn, "SELECT id FROM users");
$sameDayToConfirm = count_rows($conn, "SELECT id FROM rentals WHERE status='Active' AND start_date=end_date AND end_date <= CURDATE()");
$totalReservations = count_rows($conn, "SELECT id FROM reservations WHERE status IN ('Pending','Confirmed','Waitlisted')");
$totalAlerts = count_rows($conn, "SELECT id FROM notifications WHERE status='Ready'");
$openTasks = count_rows($conn, "SELECT id FROM staff_tasks WHERE status IN ('Open','In Progress')");
$openCases = count_rows($conn, "SELECT id FROM not_returned_cases WHERE status IN ('Open','Followed Up','Reported')");

$recentRentals = $conn->query(" 
    SELECT r.*, c.full_name AS customer_name, ca.reg_no AS car_reg
    FROM rentals r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN cars ca ON r.cars_id = ca.id
    ORDER BY r.id DESC
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Car Rental</title>

    <style>
        .clean-dashboard {
            background: #f5f6f8;
            min-height: 100vh;
            color: #1f2933;
        }

        .dashboard-head {
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .dashboard-head h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            color: #1f2933;
        }

        .dashboard-head p {
            margin: 5px 0 0;
            font-size: 14px;
            color: #6b7280;
        }

        .dashboard-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .dash-btn {
            display: inline-block;
            padding: 9px 15px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #d1d5db;
            color: #374151;
            background: #ffffff;
        }

        .dash-btn.primary {
            background: #1f4e79;
            border-color: #1f4e79;
            color: #ffffff;
        }

        .dash-btn:hover {
            opacity: 0.9;
            text-decoration: none;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.035);
        }

        .summary-card .line {
            width: 35px;
            height: 3px;
            background: #1f4e79;
            border-radius: 10px;
            margin-bottom: 14px;
        }

        .summary-card.green .line {
            background: #2e7d32;
        }

        .summary-card.orange .line {
            background: #b45309;
        }

        .summary-card.purple .line {
            background: #5b4b8a;
        }

        .summary-card.red .line {
            background: #9f1239;
        }

        .summary-card.gray .line {
            background: #4b5563;
        }

        .summary-card p {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
            font-weight: 600;
        }

        .summary-card h2 {
            margin: 8px 0 0;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
        }

        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.035);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .card-head h2 {
            margin: 0;
            font-size: 18px;
            color: #1f2933;
            font-weight: 700;
        }

        .professional-table {
            width: 100%;
            border-collapse: collapse;
        }

        .professional-table th {
            text-align: left;
            padding: 12px 10px;
            font-size: 12px;
            color: #6b7280;
            font-weight: 700;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            white-space: nowrap;
        }

        .professional-table td {
            padding: 13px 10px;
            font-size: 14px;
            color: #374151;
            border-bottom: 1px solid #eef0f3;
            vertical-align: middle;
        }

        .professional-table tbody tr:hover {
            background: #fafafa;
        }

        .customer-title {
            font-weight: 700;
            color: #111827;
        }

        .plate-number {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-badge.active {
            background: #ecfdf3;
            color: #166534;
        }

        .status-badge.completed {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-badge.cancelled {
            background: #fef2f2;
            color: #991b1b;
        }

        .status-badge.pending {
            background: #fff7ed;
            color: #9a3412;
        }

        .status-badge.default {
            background: #f3f4f6;
            color: #374151;
        }

        .empty-message {
            text-align: center;
            color: #6b7280;
            padding: 22px !important;
        }

        @media screen and (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media screen and (max-width: 700px) {
            .dashboard-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .dashboard-actions {
                width: 100%;
            }

            .dash-btn {
                flex: 1;
                text-align: center;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-card {
                padding: 15px;
            }

            .card-head {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftsidebar.php'; ?>

<div class="content-page clean-dashboard">
    <div class="content">
        <div class="container-fluid">

            <div class="dashboard-head">
                <div>
                    <h1>Dashboard</h1>
                    <p>Cars, rentals, customers, payments, alerts, and operational follow-ups.</p>
                </div>

                <div class="dashboard-actions">
                    <a class="dash-btn primary" href="<?= h(app_url('rentals/manage.php')); ?>">Manage Rentals</a>
                    <a class="dash-btn" href="<?= h(app_url('cars/cars_inventory.php')); ?>">Manage Cars</a>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="line"></div>
                    <p>Total Cars</p>
                    <h2><?= h($totalCars); ?></h2>
                </div>

                <div class="summary-card green">
                    <div class="line"></div>
                    <p>Available Cars</p>
                    <h2><?= h($availableCars); ?></h2>
                </div>

                <div class="summary-card orange">
                    <div class="line"></div>
                    <p>Rented Cars</p>
                    <h2><?= h($rentedCars); ?></h2>
                </div>

                <div class="summary-card purple">
                    <div class="line"></div>
                    <p>Total Customers</p>
                    <h2><?= h($totalCustomers); ?></h2>
                </div>

                <div class="summary-card gray">
                    <div class="line"></div>
                    <p>Total Rentals</p>
                    <h2><?= h($totalRentals); ?></h2>
                </div>

                <div class="summary-card">
                    <div class="line"></div>
                    <p>Total Payments</p>
                    <h2><?= h($totalPayments); ?></h2>
                </div>

                <div class="summary-card purple">
                    <div class="line"></div>
                    <p>Reservations / Waitlist</p>
                    <h2><?= h($totalReservations); ?></h2>
                </div>

                <div class="summary-card orange">
                    <div class="line"></div>
                    <p>Ready Alerts</p>
                    <h2><?= h($totalAlerts); ?></h2>
                </div>

                <div class="summary-card gray">
                    <div class="line"></div>
                    <p>Open Tasks</p>
                    <h2><?= h($openTasks); ?></h2>
                </div>

                <div class="summary-card red">
                    <div class="line"></div>
                    <p>Not Returned Cases</p>
                    <h2><?= h($openCases); ?></h2>
                </div>

                <?php if ($sameDayToConfirm > 0): ?>
                    <div class="summary-card red">
                        <div class="line"></div>
                        <p>Same-Day Returns To Confirm</p>
                        <h2><?= h($sameDayToConfirm); ?></h2>
                    </div>
                <?php endif; ?>

                <?php if (is_admin_user()): ?>
                    <div class="summary-card gray">
                        <div class="line"></div>
                        <p>Total Users</p>
                        <h2><?= h($totalUsers); ?></h2>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-card">
                <div class="card-head">
                    <h2>Recent Rentals</h2>
                    <a class="dash-btn" href="<?= h(app_url('rentals/manage.php')); ?>">View All Rentals</a>
                </div>

                <div class="table-responsive">
                    <table class="professional-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Car</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($recentRentals && $recentRentals->num_rows > 0): $i = 1; ?>
                                <?php while ($rental = $recentRentals->fetch_assoc()): ?>
                                    <?php
                                        $statusText = $rental['status'] ?? 'N/A';
                                        $statusLower = strtolower($statusText);

                                        if ($statusLower === 'active') {
                                            $statusClass = 'active';
                                        } elseif ($statusLower === 'completed') {
                                            $statusClass = 'completed';
                                        } elseif ($statusLower === 'cancelled' || $statusLower === 'canceled') {
                                            $statusClass = 'cancelled';
                                        } elseif ($statusLower === 'pending') {
                                            $statusClass = 'pending';
                                        } else {
                                            $statusClass = 'default';
                                        }
                                    ?>

                                    <tr>
                                        <td><?= $i++; ?></td>

                                        <td>
                                            <span class="customer-title">
                                                <?= h($rental['customer_name'] ?? 'N/A'); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="plate-number">
                                                <?= h($rental['car_reg'] ?? 'N/A'); ?>
                                            </span>
                                        </td>

                                        <td><?= h($rental['start_date']); ?></td>

                                        <td><?= h($rental['end_date']); ?></td>

                                        <td><?= number_format((float)$rental['total_cost'], 2); ?> TRY</td>

                                        <td>
                                            <span class="status-badge <?= h($statusClass); ?>">
                                                <?= h($statusText); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-message">No recent rentals yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>