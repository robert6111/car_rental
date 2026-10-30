<?php
require_once __DIR__ . '/app.php';
$role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
?>

<div class="left side-menu" id="sidebarMenu">
    <div class="mobile-sidebar-head">
        <strong>Menu</strong>
        <button type="button" id="sidebarCloseBtn" class="sidebar-close-btn" aria-label="Close menu">×</button>
    </div>

    <div class="sidebar-inner slimscrollleft">
        <div id="sidebar-menu">
            <ul>
                <li class="menu-title">Navigation</li>

                <li class="has_sub">
                    <a href="<?= h(app_url('dashboard.php')); ?>" class="waves-effect">
                        <i class="mdi mdi-view-dashboard"></i> <span> Dashboard </span>
                    </a>
                </li>



                <?php if (stock_has_permission('office_manage')): ?>
                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-folder-file"></i> <span> Office Modules </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <li><a href="<?= h(app_url('documents/manage.php')); ?>">Documents</a></li>
                        <li><a href="<?= h(app_url('fuel/manage.php')); ?>">Fuel & Mileage</a></li>
                        <li><a href="<?= h(app_url('expenses/manage.php')); ?>">Expenses</a></li>
                        <li><a href="<?= h(app_url('tasks/manage.php')); ?>">Staff Tasks</a></li>
                        <li><a href="<?= h(app_url('attendance/manage.php')); ?>">Attendance</a></li>
                        <li><a href="<?= h(app_url('notifications/alerts.php')); ?>">Notifications & Alerts</a></li>
                        <li><a href="<?= h(app_url('rentals/not_returned.php')); ?>">Not Returned / KABIS Cases</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (stock_has_permission('users_manage')): ?>
                    <li class="has_sub">
                        <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                            <i class="mdi mdi-account-multiple"></i> <span> Manage Users</span>
                            <span class="menu-arrow"></span>
                        </a>
                        <ul class="list-unstyled">
                            <li><a href="<?= h(app_url('users/add.php')); ?>">Add User</a></li>
                            <li><a href="<?= h(app_url('users/manage.php')); ?>">Manage Users</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-car"></i> <span> Cars </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <?php if (stock_has_permission('cars_manage')): ?>
                            <li><a href="<?= h(app_url('cars/add.php')); ?>">Add Car</a></li>
                            <li><a href="<?= h(app_url('cars/all_cars.php')); ?>">Manage Cars</a></li>
                            <li><a href="<?= h(app_url('cars/add_make.php')); ?>">Add Make</a></li>
                            <li><a href="<?= h(app_url('cars/add_model.php')); ?>">Add Model</a></li>
                            <li><a href="<?= h(app_url('cars/add_years.php')); ?>">Add Years</a></li>
                        <?php endif; ?>
                        <?php if (stock_has_permission('maintenance_manage')): ?>
                            <li><a href="<?= h(app_url('maintainance_and_repair/damage.php')); ?>">Add Damage</a></li>
                            <li><a href="<?= h(app_url('maintainance_and_repair/service.php')); ?>">Add Service Records</a></li>
                            <li><a href="<?= h(app_url('maintainance_and_repair/service_list.php')); ?>">Service Records</a></li>
                            <li><a href="<?= h(app_url('maintainance_and_repair/list_damage.php')); ?>">List Damages</a></li>
                        <?php endif; ?>
                        <li><a href="<?= h(app_url('cars/cars_inventory.php')); ?>">Available Cars</a></li>
                    </ul>
                </li>



                <?php if (stock_has_permission('rentals_manage')): ?>
                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-calendar-check"></i> <span> Reservations </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <li><a href="<?= h(app_url('reservations/manage.php')); ?>">Reservations & Waitlist</a></li>
                        <li><a href="<?= h(app_url('reservations/booking_link.php')); ?>">Send Booking Link</a></li>
                        <li><a href="<?= h(app_url('reservations/online_booking.php')); ?>" target="_blank">Online Booking Form</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (stock_has_permission('rentals_manage')): ?>
                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-calendar"></i> <span> Rentals </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <li><a href="<?= h(app_url('rentals/add.php')); ?>">New Rental</a></li>
                        <li><a href="<?= h(app_url('rentals/active.php')); ?>">Active Rentals</a></li>
                        <li><a href="<?= h(app_url('rentals/manage.php')); ?>">Manage Rentals</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (stock_has_permission('customers_manage')): ?>
                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-account"></i> <span> Customers </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <li><a href="<?= h(app_url('customers/add.php')); ?>">Add Customer</a></li>
                        <li><a href="<?= h(app_url('customers/customer.php')); ?>">Manage Customers</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (stock_has_permission('payments_manage')): ?>
                <li class="has_sub">
                    <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                        <i class="mdi mdi-cash-multiple"></i> <span> Payments </span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="list-unstyled">
                        <li><a href="<?= h(app_url('paying/manage.php')); ?>">Manage Payments</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (stock_has_permission('reports_view')): ?>
                    <li class="has_sub">
                        <a href="javascript:void(0);" class="waves-effect sidebar-toggle-link">
                            <i class="mdi mdi-chart-bar"></i> <span> Reports </span>
                            <span class="menu-arrow"></span>
                        </a>
                        <ul class="list-unstyled">
                            <li><a href="<?= h(app_url('report/report.php')); ?>">Rental Reports</a></li>
                            <li><a href="<?= h(app_url('report/payment_report.php')); ?>">Payment Reports</a></li>
                            <li><a href="<?= h(app_url('report/analytics.php')); ?>">Analytics</a></li>
                        </ul>
                    </li>

                    <li class="has_sub">
                        <a href="<?= h(app_url('import/import.php')); ?>" class="waves-effect">
                            <i class="mdi mdi-file-import"></i> <span> Import Data </span>
                        </a>
                    </li>

                    <li class="has_sub">
                        <a href="<?= h(app_url('backup/backup.php')); ?>" class="waves-effect">
                            <i class="mdi mdi-database-arrow-down"></i> <span> Backup Database </span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="clearfix"></div>
    </div>
</div>
