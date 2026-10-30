<?php
/**
 * Central security helpers for the Car Rental system.
 * This file keeps security logic in one place without changing the visible UI.
 */

if (!defined('CAR_RENTAL_IDLE_TIMEOUT')) {
    define('CAR_RENTAL_IDLE_TIMEOUT', 180); // 3 minute. Change this value in seconds to reduce/extend idle logout.
}
if (!defined('CAR_RENTAL_LOGIN_MAX_ATTEMPTS')) {
    define('CAR_RENTAL_LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('CAR_RENTAL_LOGIN_LOCK_SECONDS')) {
    define('CAR_RENTAL_LOGIN_LOCK_SECONDS', 900); // 15 minutes
}

function stock_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
}

function stock_start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = stock_is_https();
    // Unique per-app cookie: sibling apps on this host must not share a session.
    session_name('car_rental_session');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secure) ini_set('session.cookie_secure', '1');
    session_start();
}

function stock_security_headers(): void {
    if (headers_sent() || PHP_SAPI === 'cli') return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function stock_security_app_base_url(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/backend/');
    if ($pos !== false) return substr($script, 0, $pos + strlen('/backend'));
    $pos = strpos($script, '/backend');
    if ($pos !== false) return substr($script, 0, $pos + strlen('/backend'));
    return '/backend';
}

function stock_security_app_url(string $path = ''): string {
    $base = rtrim(stock_security_app_base_url(), '/');
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

function stock_is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function stock_current_role_id(): int {
    return isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
}

function stock_destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function stock_require_login(): void {
    if (!stock_is_logged_in()) {
        header('Location: ' . stock_security_app_url('authentication/login.php'));
        exit;
    }
}

function stock_check_idle_timeout(): void {
    if (!stock_is_logged_in()) return;
    $last = (int)($_SESSION['LAST_ACTIVITY'] ?? time());
    if ((time() - $last) > CAR_RENTAL_IDLE_TIMEOUT) {
        stock_destroy_session();
        header('Location: ' . stock_security_app_url('authentication/login.php?timeout=1'));
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
}

function stock_permission_roles(): array {
    return [
        'users_manage'       => [1],
        'reports_view'       => [1],
        'backup_manage'      => [1],
        'import_manage'      => [1],
        'cars_manage'        => [1],
        'maintenance_manage' => [1],
        'office_manage'      => [1, 3],
        'rentals_manage'     => [1, 2, 3, 4],
        'customers_manage'   => [1, 2, 3, 4],
        'payments_manage'    => [1, 3, 4],
        'public_booking'     => [0, 1, 2, 3, 4, 5],
    ];
}

function stock_has_permission(string $permission): bool {
    $map = stock_permission_roles();
    if (!isset($map[$permission])) return false;
    return in_array(stock_current_role_id(), $map[$permission], true);
}

function stock_require_permission(string $permission): void {
    stock_require_login();
    if (!stock_has_permission($permission)) {
        http_response_code(403);
        echo '<h2 style="font-family:Arial;padding:30px;color:#991b1b;">Access denied. You do not have permission to open this page.</h2>';
        exit;
    }
}

function stock_normalized_script_path(): string {
    $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($path, '/backend/');
    if ($pos !== false) return substr($path, $pos);
    $pos = strpos($path, '/backend');
    if ($pos !== false) return substr($path, $pos);
    return $path;
}

function stock_enforce_route_access(): void {
    $path = stock_normalized_script_path();

    $prefixRules = [
        '/backend/users/' => 'users_manage',
        '/backend/report/' => 'reports_view',
        '/backend/import/' => 'import_manage',
        '/backend/backup/' => 'backup_manage',
        '/backend/maintainance_and_repair/' => 'maintenance_manage',
    ];
    foreach ($prefixRules as $prefix => $permission) {
        if (strpos($path, $prefix) === 0) stock_require_permission($permission);
    }

    $exactRules = [
        '/backend/cars/add.php' => 'cars_manage',
        '/backend/cars/add_make.php' => 'cars_manage',
        '/backend/cars/add_model.php' => 'cars_manage',
        '/backend/cars/add_years.php' => 'cars_manage',
        '/backend/cars/all_cars.php' => 'cars_manage',
        '/backend/cars/delete.php' => 'cars_manage',
        '/backend/cars/edit.php' => 'cars_manage',
        '/backend/attendance/manage.php' => 'office_manage',
        '/backend/expenses/manage.php' => 'office_manage',
        '/backend/fuel/manage.php' => 'office_manage',
        '/backend/documents/manage.php' => 'office_manage',
        '/backend/tasks/manage.php' => 'office_manage',
        '/backend/notifications/alerts.php' => 'office_manage',
    ];
    if (isset($exactRules[$path])) stock_require_permission($exactRules[$path]);
}

function stock_csrf_token(): string {
    if (empty($_SESSION['stock_csrf_token'])) {
        $_SESSION['stock_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['stock_csrf_token'];
}

function stock_csrf_input(): string {
    return '<input type="hidden" name="stock_csrf_token" value="' . htmlspecialchars(stock_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function stock_validate_csrf(): void {
    $expected = $_SESSION['stock_csrf_token'] ?? '';
    $submitted = $_POST['stock_csrf_token'] ?? '';
    if (!$expected || !$submitted || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<h2 style="font-family:Arial;padding:30px;color:#991b1b;">Security check failed. Please go back, refresh the page, and try again.</h2>';
        exit;
    }
}

function stock_action_token(): string {
    if (empty($_SESSION['stock_action_token'])) {
        $_SESSION['stock_action_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['stock_action_token'];
}

function stock_action_url(string $url): string {
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . '_token=' . urlencode(stock_action_token());
}

function stock_validate_action_token(): void {
    $expected = $_SESSION['stock_action_token'] ?? '';
    $submitted = $_GET['_token'] ?? '';
    if (!$expected || !$submitted || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<h2 style="font-family:Arial;padding:30px;color:#991b1b;">Security check failed. Please refresh the page and use the action button again.</h2>';
        exit;
    }
}

function stock_rate_limit(string $key, int $maxAttempts, int $seconds): bool {
    $now = time();
    $bucketKey = 'rate_limit_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
    if (!isset($_SESSION[$bucketKey]) || !is_array($_SESSION[$bucketKey])) {
        $_SESSION[$bucketKey] = [];
    }
    $_SESSION[$bucketKey] = array_values(array_filter($_SESSION[$bucketKey], fn($ts) => ($now - (int)$ts) < $seconds));
    if (count($_SESSION[$bucketKey]) >= $maxAttempts) return false;
    $_SESSION[$bucketKey][] = $now;
    return true;
}

function stock_start_csrf_injection(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    if (defined('STOCK_CSRF_BUFFER_STARTED')) return;
    define('STOCK_CSRF_BUFFER_STARTED', true);
    ob_start(function ($html) {
        if (stripos($html, '<form') === false || stripos($html, 'method') === false) return $html;
        $tokenInput = stock_csrf_input();
        return preg_replace_callback('/<form\b([^>]*)>/i', function ($matches) use ($tokenInput) {
            $attrs = $matches[1];
            if (!preg_match('/method\s*=\s*(["\']?)post\1/i', $attrs)) return $matches[0];
            if (stripos($matches[0], 'stock-no-csrf') !== false) return $matches[0];
            return $matches[0] . $tokenInput;
        }, $html);
    });
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

stock_start_secure_session();
stock_security_headers();
stock_check_idle_timeout();
stock_enforce_route_access();
stock_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stock_validate_csrf();
}
stock_start_csrf_injection();
?>
