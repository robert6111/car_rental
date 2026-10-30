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
 * Helpers: cr_icon('car'), cr_alert('success', 'Saved.'), cr_alerts($msg, $error),
 *          cr_status_badge('Active'), cr_asset('includes/css/app.css').
 */
require_once __DIR__ . '/app.php';

/** URL of a file in the backend folder with a cache-busting version. */
function cr_asset(string $path): string {
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : '1';
    return app_url($path) . '?v=' . $v;
}

/** Inline SVG icon from includes/images/icons.svg. */
function cr_icon(string $name, string $class = ''): string {
    static $sprite = null;
    if ($sprite === null) $sprite = cr_asset('includes/images/icons.svg');
    return '<svg class="icon' . ($class !== '' ? ' ' . h($class) : '') . '" aria-hidden="true" focusable="false"><use href="' . h($sprite) . '#' . h($name) . '"></use></svg>';
}

/** One message box. $type: success, error, warning, info. */
function cr_alert(string $type, string $message, bool $isHtml = false): string {
    if (trim($message) === '') return '';
    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    $icon = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'][$type];
    $role = $type === 'error' ? 'alert' : 'status';
    return '<div class="alert alert-' . $type . '" role="' . $role . '">' . cr_icon($icon) . '<div class="alert-body">' . ($isHtml ? $message : h($message)) . '</div></div>';
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

/** "Car Rental" -> "CR", "Hertz" -> "HE". */
function cr_initials(string $name): string {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['CR'];
    $out = count($words) > 1 ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1) : mb_substr($words[0], 0, 2);
    return mb_strtoupper($out);
}

function cr_role_name(int $roleId): string {
    return [1 => 'Administrator', 2 => 'Staff', 3 => 'Manager', 4 => 'Cashier', 5 => 'Maintenance'][$roleId] ?? 'User';
}

/** Back-office menu. Each link: [label, path, permission|null]. */
function cr_nav_items(): array {
    return [
        ['type' => 'link', 'label' => 'Dashboard', 'icon' => 'dashboard', 'path' => 'dashboard.php'],
        ['type' => 'section', 'label' => 'Operations'],
        ['type' => 'group', 'label' => 'Rentals', 'icon' => 'calendar', 'perm' => 'rentals_manage', 'items' => [
            ['New rental', 'rentals/add.php'],
            ['Active rentals', 'rentals/active.php'],
            ['All rentals', 'rentals/manage.php', null, ['rentals/edit.php', 'rentals/contract.php']],
            [cr_not_returned_title(), 'rentals/not_returned.php'],
        ]],
        ['type' => 'group', 'label' => 'Reservations', 'icon' => 'calendar-check', 'perm' => 'rentals_manage', 'items' => [
            ['Reservations & waitlist', 'reservations/manage.php', null, ['reservations/link.php']],
            ['Send booking link', 'reservations/booking_link.php'],
            ['Online booking form', 'reservations/online_booking.php', null, [], true],
        ]],
        ['type' => 'group', 'label' => 'Customers', 'icon' => 'users', 'perm' => 'customers_manage', 'items' => [
            ['Add customer', 'customers/add.php'],
            ['All customers', 'customers/customer.php', null, ['customers/edit.php']],
        ]],
        ['type' => 'link', 'label' => 'Payments', 'icon' => 'wallet', 'path' => 'paying/manage.php', 'perm' => 'payments_manage', 'also' => ['paying/history.php']],
        ['type' => 'section', 'label' => 'Fleet'],
        ['type' => 'group', 'label' => 'Cars', 'icon' => 'car', 'items' => [
            ['Available cars', 'cars/cars_inventory.php'],
            ['Manage cars', 'cars/all_cars.php', 'cars_manage', ['cars/edit.php']],
            ['Add car', 'cars/add.php', 'cars_manage'],
            ['Makes', 'cars/add_make.php', 'cars_manage'],
            ['Models', 'cars/add_model.php', 'cars_manage'],
            ['Years', 'cars/add_years.php', 'cars_manage'],
        ]],
        ['type' => 'group', 'label' => 'Maintenance', 'icon' => 'wrench', 'perm' => 'maintenance_manage', 'items' => [
            ['Damages', 'maintainance_and_repair/list_damage.php', null, ['maintainance_and_repair/edit_damage.php', 'maintainance_and_repair/view_damage.php']],
            ['Report damage', 'maintainance_and_repair/damage.php'],
            ['Service records', 'maintainance_and_repair/service_list.php'],
            ['Add service record', 'maintainance_and_repair/service.php'],
        ]],
        ['type' => 'section', 'label' => 'Office', 'perm' => 'office_manage'],
        ['type' => 'group', 'label' => 'Office', 'icon' => 'folder', 'perm' => 'office_manage', 'items' => [
            ['Documents', 'documents/manage.php'],
            ['Fuel & mileage', 'fuel/manage.php'],
            ['Expenses', 'expenses/manage.php'],
            ['Staff tasks', 'tasks/manage.php'],
            ['Attendance', 'attendance/manage.php'],
            ['Notifications', 'notifications/alerts.php'],
        ]],
        ['type' => 'group', 'label' => 'Reports', 'icon' => 'chart', 'perm' => 'reports_view', 'items' => [
            ['Rental report', 'report/report.php'],
            ['Payment report', 'report/payment_report.php'],
            ['Analytics', 'report/analytics.php'],
        ]],
        ['type' => 'section', 'label' => 'Administration', 'perm' => 'users_manage'],
        ['type' => 'group', 'label' => 'Users', 'icon' => 'user', 'perm' => 'users_manage', 'items' => [
            ['All users', 'users/manage.php', null, ['users/edit.php']],
            ['Add user', 'users/add.php'],
        ]],
        ['type' => 'link', 'label' => 'Import data', 'icon' => 'upload', 'path' => 'import/import.php', 'perm' => 'import_manage'],
        ['type' => 'link', 'label' => 'Backups', 'icon' => 'database', 'path' => 'backup/backup.php', 'perm' => 'backup_manage'],
        ['type' => 'link', 'label' => 'Settings', 'icon' => 'settings', 'path' => 'settings/index.php', 'perm' => 'settings_manage'],
        ['type' => 'link', 'label' => 'System check', 'icon' => 'shield', 'path' => 'settings/system.php', 'perm' => 'settings_manage'],
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
    $html = '<nav aria-label="Main menu">';
    foreach (cr_nav_items() as $item) {
        if (!$can($item['perm'] ?? null)) continue;
        if ($item['type'] === 'section') {
            $html .= '<div class="nav-section">' . h($item['label']) . '</div>';
            continue;
        }
        if ($item['type'] === 'link') {
            $active = $current === $item['path'] || in_array($current, $item['also'] ?? [], true);
            $html .= '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . h(app_url($item['path'])) . '"' . ($active ? ' aria-current="page"' : '') . '>'
                . cr_icon($item['icon']) . '<span>' . h($item['label']) . '</span></a>';
            continue;
        }
        $links = '';
        $groupActive = false;
        foreach ($item['items'] as $sub) {
            [$label, $path] = $sub;
            if (!$can($sub[2] ?? null)) continue;
            $active = $current === $path || in_array($current, $sub[3] ?? [], true);
            $groupActive = $groupActive || $active;
            $newTab = !empty($sub[4]);
            $links .= '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . h(app_url($path)) . '"'
                . ($active ? ' aria-current="page"' : '') . ($newTab ? ' target="_blank" rel="noopener"' : '') . '>' . h($label)
                . ($newTab ? ' ' . cr_icon('external', 'icon-sm') : '') . '</a>';
        }
        if ($links === '') continue;
        $html .= '<details class="nav-group' . ($groupActive ? ' has-active' : '') . '"' . ($groupActive ? ' open' : '') . '>'
            . '<summary>' . cr_icon($item['icon']) . '<span>' . h($item['label']) . '</span>' . cr_icon('chevron-down', 'icon-sm chev') . '</summary>'
            . '<div class="nav-sub">' . $links . '</div></details>';
    }
    return $html . '</nav>';
}

/** <head> contents shared by every page. */
function cr_head(string $title, string $extraHead = ''): void {
    $company = cr_company_name();
    $fullTitle = $title !== '' ? $title . ' · ' . $company : $company;
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

/**
 * Starts a back-office page. Options:
 *   'head' => extra HTML for <head> (page-specific <style>),
 *   'body_class' => extra class on <body>.
 */
function cr_layout_start(string $title, array $opts = []): void {
    cr_head($title, (string)($opts['head'] ?? ''));
    $company = cr_company_name();
    $username = (string)($_SESSION['username'] ?? 'User');
    $roleName = cr_role_name((int)($_SESSION['role_id'] ?? 0));
    $initials = cr_initials($company);
    $userInitial = strtoupper(mb_substr($username, 0, 1));
    $hasLogo = cr_setting('company_logo') !== '';
    ?>
<body class="<?= h(trim('app ' . ($opts['body_class'] ?? ''))); ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="app-topbar">
    <button type="button" class="topbar-btn menu-toggle" id="navToggle" aria-label="Open menu" aria-controls="appSidebar" aria-expanded="false"><?= cr_icon('menu', 'icon-lg'); ?></button>
    <a class="app-brand" href="<?= h(app_url('dashboard.php')); ?>">
        <?php if ($hasLogo): ?><img src="<?= h(app_url('settings/logo.php')); ?>" alt=""><?php else: ?><span class="app-brand-mark"><?= h($initials); ?></span><?php endif; ?>
        <span class="app-brand-name"><?= h($company); ?></span>
    </a>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-btn" id="themeToggle" aria-label="Switch light or dark mode" title="Light / dark mode"><span class="theme-icon-dark"><?= cr_icon('moon'); ?></span><span class="theme-icon-light" hidden><?= cr_icon('sun'); ?></span></button>
    <div class="user-menu">
        <button type="button" class="topbar-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu">
            <span class="user-avatar"><?= h($userInitial); ?></span>
            <span class="user-meta"><strong><?= h($username); ?></strong><span><?= h($roleName); ?></span></span>
            <?= cr_icon('chevron-down', 'icon-sm'); ?>
        </button>
        <div class="dropdown" id="userMenu" role="menu">
            <a href="<?= h(app_url('profile.php')); ?>" role="menuitem"><?= cr_icon('user'); ?> My profile</a>
            <a href="<?= h(app_url('change_password.php')); ?>" role="menuitem"><?= cr_icon('key'); ?> Change password</a>
            <div class="divider"></div>
            <a href="<?= h(stock_action_url(app_url('authentication/logout.php'))); ?>" role="menuitem"><?= cr_icon('logout'); ?> Sign out</a>
        </div>
    </div>
</header>
<aside class="app-sidebar" id="appSidebar"><?= cr_render_nav(); ?></aside>
<div class="sidebar-overlay" id="navOverlay"></div>
<main class="app-main" id="main">
<div class="app-content">
<?php
}

function cr_layout_end(): void {
    ?>
</div>
<footer class="app-footer">&copy; <?= date('Y'); ?> <?= h(cr_company_name()); ?></footer>
</main>
<script src="<?= h(cr_asset('includes/js/app.js')); ?>" defer></script>
</body>
</html>
<?php
}

/** Starts a page without the menu (sign in, first setup, password reset). */
function cr_auth_start(string $title, bool $wide = false): void {
    cr_head($title);
    $company = cr_company_name();
    $initials = cr_initials($company);
    ?>
<body class="auth-body">
<button type="button" class="topbar-btn theme-float" id="themeToggle" aria-label="Switch light or dark mode"><span class="theme-icon-dark"><?= cr_icon('moon'); ?></span><span class="theme-icon-light" hidden><?= cr_icon('sun'); ?></span></button>
<main class="auth-shell<?= $wide ? ' wide' : ''; ?>" id="main">
    <div class="auth-brand">
        <?php if (cr_setting('company_logo') !== ''): ?><img src="<?= h(app_url('settings/logo.php')); ?>" alt=""><?php else: ?><span class="app-brand-mark"><?= h($initials); ?></span><?php endif; ?>
        <strong><?= h($company); ?></strong>
    </div>
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
