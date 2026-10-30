<?php
/**
 * Central security layer for the Car Rental system. Runs on every page through includes/app.php:
 * secure session, security headers, idle timeout, live user/role check, route permissions,
 * CSRF protection for every POST form, and server-side rate limiting.
 */

require_once __DIR__ . '/config.php';

if (!defined('CAR_RENTAL_IDLE_TIMEOUT')) {
    define('CAR_RENTAL_IDLE_TIMEOUT', max(60, (int)cr_config('app.idle_timeout', 1800)));
}
if (!defined('CAR_RENTAL_LOGIN_MAX_ATTEMPTS')) {
    define('CAR_RENTAL_LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('CAR_RENTAL_LOGIN_LOCK_SECONDS')) {
    define('CAR_RENTAL_LOGIN_LOCK_SECONDS', 900); // 15 minutes
}

/* ------------------------------------------------------------------ */
/* Request helpers                                                     */
/* ------------------------------------------------------------------ */

function stock_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return true;
    if (cr_config('app.trust_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

function cr_client_ip(): string {
    if (cr_config('app.trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
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

/**
 * Full URL (with scheme and host) for links that leave the browser, e.g. emails.
 * Uses the System address from Settings, then app.url from config. When $requireConfigured is true and app.url is empty, returns null
 * instead of trusting the visitor-controlled Host header.
 */
function cr_absolute_url(string $path = '', bool $requireConfigured = false): ?string {
    $configured = function_exists('cr_setting') ? rtrim(cr_setting('app_url'), '/') : '';
    if ($configured === '') $configured = rtrim((string)cr_config('app.url', ''), '/');
    $path = ltrim($path, '/');
    if ($configured !== '') return $path === '' ? $configured : $configured . '/' . $path;
    if ($requireConfigured) return null;
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (stock_is_https() ? 'https' : 'http') . '://' . $host . stock_security_app_url($path);
}

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */

function stock_start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;

    $secure = stock_is_https();
    // Unique per-app cookie: sibling apps on this host must not share a session.
    session_name('car_rental_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)max(CAR_RENTAL_IDLE_TIMEOUT, 1440));
    if ($secure) ini_set('session.cookie_secure', '1');
    session_start();
}

/**
 * When app.url in config.php starts with https://, visitors who reach the same host over
 * plain http are sent to the https address. Other hosts (e.g. a LAN IP) are left alone.
 */
function stock_force_https(): void {
    if (PHP_SAPI === 'cli' || stock_is_https()) return;
    $url = (string)cr_config('app.url', '');
    if (stripos($url, 'https://') !== 0) return;
    $wantHost = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($wantHost === '' || $host !== $wantHost) return;
    $port = parse_url($url, PHP_URL_PORT);
    $target = 'https://' . $wantHost . ($port ? ':' . (int)$port : '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Location: ' . $target, true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
    exit;
}

function stock_security_headers(): void {
    if (headers_sent() || PHP_SAPI === 'cli') return;
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    if (stock_is_https()) header('Strict-Transport-Security: max-age=31536000');
    // Logged-in pages must not be shown again from browser cache after logout.
    if (!empty($_SESSION['user_id'])) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}

function stock_is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function stock_current_role_id(): int {
    return isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
}

function stock_destroy_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** Called right after a successful login. */
function stock_login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['session_version'] = (int)($user['session_version'] ?? 0);
    $_SESSION['LAST_ACTIVITY'] = time();
    unset($_SESSION['stock_csrf_token'], $_SESSION['stock_action_token']);
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

/**
 * Re-reads the logged-in user from the database on every request, so that
 * deactivation, deletion, role changes and password changes take effect immediately.
 */
function stock_refresh_session_user(): void {
    if (!stock_is_logged_in()) return;
    require_once __DIR__ . '/database.php';
    $conn = cr_db();
    $id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT username, role_id, is_active, session_version FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int)$row['is_active'] !== 1 || (int)$row['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        stock_destroy_session();
        header('Location: ' . stock_security_app_url('authentication/login.php?signed_out=1'));
        exit;
    }
    $_SESSION['role_id'] = (int)$row['role_id'];
    $_SESSION['username'] = (string)$row['username'];
}

/** Log a user out of every device (after password change / reset / role change). */
function cr_bump_session_version(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    // Keep the current browser signed in when the user changed their own account.
    if ((int)($_SESSION['user_id'] ?? 0) === $userId) {
        $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 0) + 1;
        session_regenerate_id(true);
    }
}

/* ------------------------------------------------------------------ */
/* Permissions                                                         */
/* Roles: 1 Admin, 2 Staff, 3 Manager, 4 Cashier, 5 Maintenance        */
/* ------------------------------------------------------------------ */

function stock_permission_roles(): array {
    return [
        'users_manage'       => [1],
        'settings_manage'    => [1],
        'reports_view'       => [1],
        'backup_manage'      => [1],
        'import_manage'      => [1],
        'cars_manage'        => [1],
        'maintenance_manage' => [1, 3, 5],
        'office_manage'      => [1, 3],
        'rentals_manage'     => [1, 2, 3, 4],
        'customers_manage'   => [1, 2, 3, 4],
        'payments_manage'    => [1, 3, 4],
    ];
}

function stock_has_permission(string $permission): bool {
    $map = stock_permission_roles();
    if (!isset($map[$permission])) return false;
    return in_array(stock_current_role_id(), $map[$permission], true);
}

/** Simple full-page message (used before the layout code is loaded). */
function stock_message_page(string $title, string $message, string $linkUrl, string $linkText): void {
    $e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title>'
        . '<link rel="stylesheet" href="' . $e(stock_security_app_url('includes/css/app.css')) . '"></head>'
        . '<body class="auth-body"><main class="auth-shell"><div class="auth-card"><h1>' . $e($title) . '</h1><p class="lead">' . $e($message) . '</p>'
        . '<a class="btn btn-block" href="' . $e($linkUrl) . '">' . $e($linkText) . '</a></div></main></body></html>';
}

function stock_deny_access(): void {
    http_response_code(403);
    stock_message_page('Access denied', 'Your account does not have permission to open this page. Ask an administrator if you need access.', stock_security_app_url('dashboard.php'), 'Back to dashboard');
    exit;
}

function stock_require_permission(string $permission): void {
    stock_require_login();
    if (!stock_has_permission($permission)) stock_deny_access();
}

function stock_normalized_script_path(): string {
    $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($path, '/backend/');
    if ($pos !== false) return substr($path, $pos);
    $pos = strpos($path, '/backend');
    if ($pos !== false) return substr($path, $pos);
    return $path;
}

/** Pages anyone may open without logging in. */
function stock_public_paths(): array {
    return [
        '/backend/authentication/login.php',
        '/backend/authentication/logout.php',
        '/backend/authentication/forgot_password.php',
        '/backend/authentication/reset_password.php',
        '/backend/authentication/register.php',
        '/backend/authentication/setup.php',
        '/backend/settings/logo.php',
        '/backend/reservations/online_booking.php',
    ];
}

function stock_enforce_route_access(): void {
    $path = stock_normalized_script_path();
    if (in_array($path, stock_public_paths(), true)) return;

    $prefixRules = [
        '/backend/users/' => 'users_manage',
        '/backend/settings/' => 'settings_manage',
        '/backend/report/' => 'reports_view',
        '/backend/import/' => 'import_manage',
        '/backend/backup/' => 'backup_manage',
        '/backend/maintainance_and_repair/' => 'maintenance_manage',
        '/backend/rentals/' => 'rentals_manage',
        '/backend/reservations/' => 'rentals_manage',
        '/backend/customers/' => 'customers_manage',
        '/backend/paying/' => 'payments_manage',
        '/backend/documents/' => 'office_manage',
        '/backend/attendance/' => 'office_manage',
        '/backend/expenses/' => 'office_manage',
        '/backend/fuel/' => 'office_manage',
        '/backend/tasks/' => 'office_manage',
        '/backend/notifications/' => 'office_manage',
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
    ];
    if (isset($exactRules[$path])) stock_require_permission($exactRules[$path]);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

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
    if (!$expected || !is_string($submitted) || !$submitted || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $sameSite = $back !== '' && preg_match('#^https?://#i', $back) && strcasecmp((string)parse_url($back, PHP_URL_HOST), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
        stock_message_page('Please try again', 'This form was open for too long or your session ended, so it was not saved. Go back, refresh the page and send it again.', $sameSite ? $back : stock_security_app_url('dashboard.php'), 'Go back');
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

function stock_action_token_valid(): bool {
    $expected = $_SESSION['stock_action_token'] ?? '';
    $submitted = $_GET['_token'] ?? '';
    return $expected && is_string($submitted) && $submitted && hash_equals($expected, $submitted);
}

function stock_validate_action_token(): void {
    if (!stock_action_token_valid()) {
        http_response_code(403);
        stock_message_page('Please try again', 'This link has expired. Refresh the page and use the button again.', stock_security_app_url('dashboard.php'), 'Back to dashboard');
        exit;
    }
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

/* ------------------------------------------------------------------ */
/* Rate limiting (stored in the database, keyed by visitor IP)         */
/* ------------------------------------------------------------------ */

function cr_rate_bucket(string $key, bool $perIp = true): string {
    $bucket = $key . ($perIp ? '|' . cr_client_ip() : '');
    return strlen($bucket) > 190 ? substr($bucket, 0, 150) . '|' . md5($bucket) : $bucket;
}

/** Number of recorded hits in the last $seconds. */
function cr_rate_count(string $bucket, int $seconds): int {
    require_once __DIR__ . '/database.php';
    $conn = cr_db();
    $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM rate_limits WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    $stmt->bind_param('si', $bucket, $seconds);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
}

function cr_rate_record(string $bucket): void {
    require_once __DIR__ . '/database.php';
    $conn = cr_db();
    $stmt = $conn->prepare('INSERT INTO rate_limits (bucket, created_at) VALUES (?, NOW())');
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    $stmt->close();
    if (random_int(1, 100) === 1) {
        $conn->query('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    }
}

function cr_rate_clear(string $bucket): void {
    require_once __DIR__ . '/database.php';
    $conn = cr_db();
    $stmt = $conn->prepare('DELETE FROM rate_limits WHERE bucket = ?');
    $stmt->bind_param('s', $bucket);
    $stmt->execute();
    $stmt->close();
}

/** Returns false when the limit is reached; otherwise records the attempt and returns true. */
function stock_rate_limit(string $key, int $maxAttempts, int $seconds): bool {
    $bucket = cr_rate_bucket($key);
    if (cr_rate_count($bucket, $seconds) >= $maxAttempts) return false;
    cr_rate_record($bucket);
    return true;
}

/* ------------------------------------------------------------------ */

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

stock_force_https();
stock_start_secure_session();
stock_check_idle_timeout();
stock_refresh_session_user();
stock_security_headers();
stock_enforce_route_access();
stock_csrf_token();
// The public booking form checks its own session-bound token and re-shows the form (with the
// visitor's details) when it has expired, instead of the bare "Security check failed" page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && stock_normalized_script_path() !== '/backend/reservations/online_booking.php') {
    stock_validate_csrf();
}
stock_start_csrf_injection();
