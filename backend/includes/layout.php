<?php
/*
 * Shared page layout and small UI helpers.
 *
 * Back-office page:
 *     cr_layout_start('Customers');                 // <head>, top bar, menu, opens <main>
 *     echo cr_page_header('Customers', 'All registered customers', '<a class="btn" href="add.php">Add</a>');
 *     ... page content ...
 *     cr_layout_end();
 *
 * Login-style page (no menu): cr_auth_start('Sign in'); ... cr_auth_end();
 *
 * Helpers: cr_alert('success', 'Saved.'), cr_alerts($msg, $error),
 *          cr_status_badge('Active'), cr_asset('includes/css/app.css').
 */
require_once __DIR__ . '/app.php';

/** URL of a file in the backend folder with a cache-busting version. */
function cr_asset(string $path): string {
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return app_url($path) . '?v=' . $v;
}

/** One message box. $type: success, error, warning, info. */
function cr_alert(string $type, string $message, bool $isHtml = false): string {
    if (trim($message) === '') return '';
    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    $role = $type === 'error' ? 'alert' : 'status';
    return '<div class="alert alert-' . $type . '" role="' . $role . '"><div class="alert-body">' . ($isHtml ? $message : h($message)) . '</div></div>';
}

/** Success + error message boxes for the common $msg / $error page variables. */
function cr_alerts(?string $success = '', ?string $error = ''): string {
    return cr_alert('success', (string)$success) . cr_alert('error', (string)$error);
}

/** Colour for a status word (rentals, cars, reservations, tasks, cases, payments...). */
function cr_status_tone(string $status): string {
    $s = strtolower(trim($status));
    $map = [
        'success' => ['available', 'active', 'confirmed', 'paid', 'resolved', 'done', 'present', 'repaired', 'yes', 'returned', 'sent', 'on time', 'low'],
        'info'    => ['completed', 'rented', 'reserved', 'in progress', 'online', 'office'],
        'warning' => ['pending', 'waitlisted', 'maintenance', 'open', 'followed up', 'partial', 'draft', 'ready', 'late', 'medium', 'moderate', 'due'],
        'danger'  => ['not returned', 'canceled', 'cancelled', 'overdue', 'unpaid', 'debt', 'in debt','reported', 'absent', 'deleted', 'inactive', 'high', 'no', 'expired'],
        'purple'  => ['converted'],
    ];
    foreach ($map as $tone => $words) {
        if (in_array($s, $words, true)) return $tone;
    }
    return 'neutral';
}

function cr_status_badge(string $status, ?string $label = null): string {
    // Stored statuses like "Not Returned" are shown in sentence case: "Not returned".
    $label = $label ?? (mb_strtoupper(mb_substr($status, 0, 1)) . mb_strtolower(mb_substr($status, 1)));
    return '<span class="badge badge-' . cr_status_tone($status) . '">' . h($label) . '</span>';
}

/** Title row of a page. $actionsHtml is trusted HTML (buttons/links). */
function cr_page_header(string $title, string $subtitle = '', string $actionsHtml = ''): string {
    $html = '<div class="page-head"><div><h1>' . h($title) . '</h1>';
    if ($subtitle !== '') $html .= '<p>' . h($subtitle) . '</p>';
    $html .= '</div>';
    if ($actionsHtml !== '') $html .= '<div class="page-actions">' . $actionsHtml . '</div>';
    return $html . '</div>';
}

function cr_role_name(int $roleId): string {
    return [1 => 'Administrator', 2 => 'Staff', 3 => 'Manager', 4 => 'Cashier', 5 => 'Maintenance'][$roleId] ?? 'User';
}

/**
 * Back-office menu: top-level entries shown across the top bar.
 * A 'menu' entry opens a dropdown. Its items are [label, path, permission|null, [also-active paths], new tab]
 * or ['heading' => 'Title'] to start a small section inside the dropdown.
 */
function cr_nav_items(): array {
    return [
        ['label' => 'Overview', 'path' => 'dashboard.php'],
        ['label' => 'Rentals', 'perm' => 'rentals_manage', 'items' => [
            ['New rental', 'rentals/add.php'],
            ['Active rentals', 'rentals/active.php'],
            ['All rentals', 'rentals/manage.php', null, ['rentals/edit.php', 'rentals/contract.php']],
            [cr_not_returned_title(), 'rentals/not_returned.php'],
            ['heading' => 'Reservations'],
            ['Reservations and waitlist', 'reservations/manage.php', null, ['reservations/link.php']],
            ['Send booking link', 'reservations/booking_link.php'],
            ['Online booking form (new tab)', 'reservations/online_booking.php', null, [], true],
        ]],
        ['label' => 'Customers', 'perm' => 'customers_manage', 'items' => [
            ['All customers', 'customers/customer.php', null, ['customers/edit.php']],
            ['Add customer', 'customers/add.php'],
        ]],
        ['label' => 'Payments', 'path' => 'paying/manage.php', 'perm' => 'payments_manage', 'also' => ['paying/history.php']],
        ['label' => 'Fleet', 'items' => [
            ['Available cars', 'cars/cars_inventory.php'],
            ['Manage cars', 'cars/all_cars.php', 'cars_manage', ['cars/edit.php']],
            ['Add car', 'cars/add.php', 'cars_manage'],
            ['Makes', 'cars/add_make.php', 'cars_manage'],
            ['Models', 'cars/add_model.php', 'cars_manage'],
            ['Years', 'cars/add_years.php', 'cars_manage'],
            ['heading' => 'Maintenance'],
            ['Damages', 'maintainance_and_repair/list_damage.php', 'maintenance_manage', ['maintainance_and_repair/edit_damage.php', 'maintainance_and_repair/view_damage.php']],
            ['Report damage', 'maintainance_and_repair/damage.php', 'maintenance_manage'],
            ['Service records', 'maintainance_and_repair/service_list.php', 'maintenance_manage'],
            ['Add service record', 'maintainance_and_repair/service.php', 'maintenance_manage'],
        ]],
        ['label' => 'Office', 'perm' => 'office_manage', 'items' => [
            ['Documents', 'documents/manage.php'],
            ['Fuel and mileage', 'fuel/manage.php'],
            ['Expenses', 'expenses/manage.php'],
            ['Staff tasks', 'tasks/manage.php'],
            ['Attendance', 'attendance/manage.php'],
            ['Notifications', 'notifications/alerts.php'],
        ]],
        ['label' => 'Reports', 'perm' => 'reports_view', 'items' => [
            ['Rental report', 'report/report.php'],
            ['Payment report', 'report/payment_report.php'],
            ['Analytics', 'report/analytics.php'],
        ]],
        ['label' => 'Admin', 'items' => [
            ['Users', 'users/manage.php', 'users_manage', ['users/edit.php']],
            ['Add user', 'users/add.php', 'users_manage'],
            ['Import data', 'import/import.php', 'import_manage'],
            ['Backups', 'backup/backup.php', 'backup_manage'],
            ['Settings', 'settings/index.php', 'settings_manage'],
            ['System check', 'settings/system.php', 'settings_manage'],
        ]],
    ];
}

function cr_current_backend_path(): string {
    $p = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($p, '/backend/');
    return $pos === false ? ltrim($p, '/') : substr($p, $pos + 9);
}

function cr_render_nav(): string {
    $current = cr_current_backend_path();
    $can = fn($perm) => $perm === null || stock_has_permission($perm);
    $html = '<nav class="app-nav" id="appNav" aria-label="Main menu"><ul class="nav-list">';
    $n = 0;
    foreach (cr_nav_items() as $item) {
        if (!$can($item['perm'] ?? null)) continue;
        if (isset($item['path'])) {
            $active = $current === $item['path'] || in_array($current, $item['also'] ?? [], true);
            $html .= '<li><a class="nav-top' . ($active ? ' active' : '') . '" href="' . h(app_url($item['path'])) . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . h($item['label']) . '</a></li>';
            continue;
        }
        $links = '';
        $groupActive = false;
        $heading = '';
        foreach ($item['items'] as $sub) {
            if (isset($sub['heading'])) { $heading = $sub['heading']; continue; }
            [$label, $path] = $sub;
            if (!$can($sub[2] ?? null)) continue;
            if ($heading !== '') {
                $links .= '<li class="menu-heading">' . h($heading) . '</li>';
                $heading = '';
            }
            $active = $current === $path || in_array($current, $sub[3] ?? [], true);
            $groupActive = $groupActive || $active;
            $newTab = !empty($sub[4]);
            $links .= '<li><a' . ($active ? ' class="active" aria-current="page"' : '') . ' href="' . h(app_url($path)) . '"'
                . ($newTab ? ' target="_blank" rel="noopener"' : '') . '>' . h($label) . '</a></li>';
        }
        if ($links === '') continue;
        $id = 'navMenu' . (++$n);
        $html .= '<li class="nav-menu"><button type="button" class="nav-top' . ($groupActive ? ' active' : '') . '" data-menu="' . $id . '" aria-expanded="false" aria-controls="' . $id . '">'
            . h($item['label']) . '</button><ul class="menu" id="' . $id . '">' . $links . '</ul></li>';
    }
    return $html . '</ul></nav>';
}

/** <head> contents shared by every page. */
function cr_head(string $title, string $extraHead = ''): void {
    $company = cr_company_name();
    $fullTitle = $title !== '' ? $title . ' - ' . $company : $company;
    $favicon = cr_setting('company_logo') !== '' ? app_url('settings/logo.php') : cr_asset('includes/images/logo.png');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($fullTitle); ?></title>
<script>(function(){try{var t=localStorage.getItem('cr-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="icon" href="<?= h($favicon); ?>">
<link rel="stylesheet" href="<?= h(cr_asset('includes/css/app.css')); ?>">
<?= $extraHead; ?>
</head>
<?php
}

/** Business name, with the logo in front of it when one is uploaded. */
function cr_brand_html(): string {
    $logo = cr_setting('company_logo') !== '' ? '<img src="' . h(app_url('settings/logo.php')) . '" alt="">' : '';
    return $logo . '<span>' . h(cr_company_name()) . '</span>';
}

/**
 * Starts a back-office page. Options:
 *   'head' => extra HTML for <head> (page-specific <style>),
 *   'body_class' => extra class on <body>.
 */
function cr_layout_start(string $title, array $opts = []): void {
    cr_head($title, (string)($opts['head'] ?? ''));
    $username = (string)($_SESSION['username'] ?? 'User');
    $roleName = cr_role_name((int)($_SESSION['role_id'] ?? 0));
    ?>
<body class="<?= h(trim('app ' . ($opts['body_class'] ?? ''))); ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="app-header">
    <div class="app-header-inner">
        <a class="app-brand" href="<?= h(app_url('dashboard.php')); ?>"><?= cr_brand_html(); ?></a>
        <button type="button" class="nav-toggle" id="navToggle" aria-controls="appNav" aria-expanded="false">Menu</button>
        <?= cr_render_nav(); ?>
        <div class="account nav-menu">
            <button type="button" class="account-btn" data-menu="accountMenu" aria-expanded="false" aria-controls="accountMenu">
                <span class="account-name"><?= h($username); ?></span>
                <span class="account-role"><?= h($roleName); ?></span>
            </button>
            <ul class="menu menu-right" id="accountMenu">
                <li><a href="<?= h(app_url('profile.php')); ?>">My profile</a></li>
                <li><a href="<?= h(app_url('change_password.php')); ?>">Change password</a></li>
                <li><button type="button" id="themeToggle">Dark mode</button></li>
                <li class="menu-divider"></li>
                <li><a href="<?= h(stock_action_url(app_url('authentication/logout.php'))); ?>">Sign out</a></li>
            </ul>
        </div>
    </div>
</header>
<main class="app-main" id="main">
<div class="app-content">
<?php
}

function cr_layout_end(): void {
    ?>
</div>
<footer class="app-footer"><?= h(cr_company_name()); ?> &middot; <?= date('Y'); ?></footer>
</main>
<script src="<?= h(cr_asset('includes/js/app.js')); ?>" defer></script>
</body>
</html>
<?php
}

/** Starts a page without the menu (sign in, first setup, password reset). */
function cr_auth_start(string $title, bool $wide = false): void {
    cr_head($title);
    ?>
<body class="auth-body">
<main class="auth-shell<?= $wide ? ' wide' : ''; ?>" id="main">
    <div class="auth-brand"><?= cr_brand_html(); ?></div>
    <div class="auth-card">
<?php
}

function cr_auth_end(string $footer = ''): void {
    ?>
    </div>
    <?php if ($footer !== ''): ?><p class="auth-foot"><?= $footer; ?></p><?php endif; ?>
</main>
<script src="<?= h(cr_asset('includes/js/app.js')); ?>" defer></script>
</body>
</html>
<?php
}
