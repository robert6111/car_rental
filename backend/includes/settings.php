<?php
/*
 * Business settings stored in the database and edited by the admin under Settings.
 * Everything country- or company-specific lives here (currency, timezone, phone code,
 * rates, company details, email), so the system can be set up for any country.
 */

function cr_settings_definitions(): array {
    return [
        // Business
        'company_name'        => ['default' => 'Car Rental', 'type' => 'text', 'max' => 120],
        'company_address'     => ['default' => '', 'type' => 'text', 'max' => 255],
        'company_phone'       => ['default' => '', 'type' => 'text', 'max' => 60],
        'company_email'       => ['default' => '', 'type' => 'email', 'max' => 190],
        'company_website'     => ['default' => '', 'type' => 'text', 'max' => 190],
        'company_tax_id'      => ['default' => '', 'type' => 'text', 'max' => 80],
        'company_logo'        => ['default' => '', 'type' => 'internal'],
        'letterhead_pdf'      => ['default' => '', 'type' => 'internal'],

        // Region and money
        'currency_code'       => ['default' => 'USD', 'type' => 'text', 'max' => 3],
        'currency_symbol'     => ['default' => '$', 'type' => 'text', 'max' => 8],
        'currency_position'   => ['default' => 'before', 'type' => 'choice', 'choices' => ['before', 'after']],
        'decimal_separator'   => ['default' => '.', 'type' => 'choice', 'choices' => ['.', ',']],
        'thousands_separator' => ['default' => ',', 'type' => 'choice', 'choices' => [',', '.', ' ', "'", '']],
        'currency_decimals'   => ['default' => '2', 'type' => 'int', 'min' => 0, 'max' => 3],
        'timezone'            => ['default' => '', 'type' => 'timezone'],
        'date_format'         => ['default' => 'Y-m-d', 'type' => 'choice', 'choices' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd-m-Y']],
        'phone_country_code'  => ['default' => '', 'type' => 'digits', 'max' => 4],

        // Rental rules
        'late_interest_enabled'    => ['default' => '1', 'type' => 'bool'],
        'late_interest_daily_rate' => ['default' => '1', 'type' => 'decimal', 'min' => 0, 'max' => 100],
        'loyalty_enabled'          => ['default' => '1', 'type' => 'bool'],
        'loyalty_min_rentals'      => ['default' => '3', 'type' => 'int', 'min' => 1, 'max' => 1000],
        'loyalty_discount_percent' => ['default' => '10', 'type' => 'decimal', 'min' => 0, 'max' => 100],
        'authority_report_name'    => ['default' => '', 'type' => 'text', 'max' => 60],
        'public_booking_enabled'   => ['default' => '1', 'type' => 'bool'],

        // Email (overrides config/config.php when a host is entered)
        'smtp_host'       => ['default' => '', 'type' => 'text', 'max' => 190],
        'smtp_port'       => ['default' => '587', 'type' => 'int', 'min' => 1, 'max' => 65535],
        'smtp_encryption' => ['default' => 'tls', 'type' => 'choice', 'choices' => ['tls', 'ssl', '']],
        'smtp_username'   => ['default' => '', 'type' => 'text', 'max' => 190],
        'smtp_password'   => ['default' => '', 'type' => 'secret', 'max' => 255],
        'mail_from_email' => ['default' => '', 'type' => 'email', 'max' => 190],
        'mail_from_name'  => ['default' => '', 'type' => 'text', 'max' => 120],

        // System
        'app_url'          => ['default' => '', 'type' => 'url', 'max' => 255],
        'rows_per_page'    => ['default' => '25', 'type' => 'choice', 'choices' => ['25', '50', '100', '200']],
        'backup_keep_days' => ['default' => '30', 'type' => 'int', 'min' => 1, 'max' => 3650],
        'settings_reviewed' => ['default' => '0', 'type' => 'internal'],
        'schema_version'   => ['default' => '0', 'type' => 'internal'],
        'interest_accrued_on' => ['default' => '', 'type' => 'internal'],
        'status_synced_at' => ['default' => '0', 'type' => 'internal'],
        'scheduled_run_at' => ['default' => '0', 'type' => 'internal'],
        'auto_backup_on'   => ['default' => '', 'type' => 'internal'],
    ];
}

/** Loads all settings once per request. Called by cr_db() right after connecting. */
function cr_settings_load(mysqli $conn, bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $values = [];
    $res = @$conn->query('SELECT setting_key, setting_value FROM settings');
    if ($res) {
        while ($row = $res->fetch_assoc()) $values[$row['setting_key']] = (string)$row['setting_value'];
    }
    $cache = $values;
    $GLOBALS['__cr_settings'] = $values;
    return $values;
}

function cr_setting(string $key, $fallback = null): string {
    if (!isset($GLOBALS['__cr_settings'])) {
        require_once __DIR__ . '/database.php';
        cr_db();
    }
    $values = $GLOBALS['__cr_settings'] ?? [];
    if (array_key_exists($key, $values)) return $values[$key];
    if ($fallback !== null) return (string)$fallback;
    $defs = cr_settings_definitions();
    return (string)($defs[$key]['default'] ?? '');
}

function cr_setting_bool(string $key): bool {
    return cr_setting($key) === '1';
}

function cr_settings_save(mysqli $conn, array $values): void {
    $stmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $key => $value) {
        $key = (string)$key;
        $value = (string)$value;
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
    cr_settings_load($conn, true);
}

/**
 * Validates submitted settings. Returns [cleanValues, errors].
 * Only keys present in $input and editable (not internal) are processed.
 */
function cr_settings_validate(array $input): array {
    $clean = [];
    $errors = [];
    foreach (cr_settings_definitions() as $key => $def) {
        if ($def['type'] === 'internal') continue;
        if ($def['type'] === 'bool') {
            if (array_key_exists($key . '__present', $input)) $clean[$key] = !empty($input[$key]) ? '1' : '0';
            continue;
        }
        if (!array_key_exists($key, $input)) continue;
        $v = trim((string)$input[$key]);
        if (isset($def['max']) && mb_strlen($v) > $def['max']) $v = mb_substr($v, 0, $def['max']);
        switch ($def['type']) {
            case 'email':
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[$key] = 'Enter a valid email address.'; continue 2; }
                break;
            case 'url':
                $v = rtrim($v, '/');
                if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v))) { $errors[$key] = 'Enter a full address starting with http:// or https://'; continue 2; }
                break;
            case 'choice':
                if (!in_array($v, $def['choices'], true)) { $errors[$key] = 'Choose one of the listed options.'; continue 2; }
                break;
            case 'int':
                if (!preg_match('/^\d+$/', $v) || (int)$v < $def['min'] || (int)$v > $def['max']) { $errors[$key] = 'Enter a whole number between ' . $def['min'] . ' and ' . $def['max'] . '.'; continue 2; }
                $v = (string)(int)$v;
                break;
            case 'decimal':
                $v = str_replace(',', '.', $v);
                if (!is_numeric($v) || (float)$v < $def['min'] || (float)$v > $def['max']) { $errors[$key] = 'Enter a number between ' . $def['min'] . ' and ' . $def['max'] . '.'; continue 2; }
                $v = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
                break;
            case 'digits':
                $v = preg_replace('/\D/', '', $v);
                break;
            case 'timezone':
                if (!in_array($v, DateTimeZone::listIdentifiers(), true)) { $errors[$key] = 'Choose a timezone from the list.'; continue 2; }
                break;
            case 'secret':
                if ($v === '') continue 2; // empty = keep the current password
                break;
        }
        $clean[$key] = $v;
    }
    if (isset($clean['currency_code'])) {
        $clean['currency_code'] = strtoupper($clean['currency_code']);
        if (!preg_match('/^[A-Z]{3}$/', $clean['currency_code'])) $errors['currency_code'] = 'Use the 3-letter currency code, e.g. USD, EUR, TRY, KES.';
    }
    if (isset($clean['decimal_separator'], $clean['thousands_separator']) && $clean['decimal_separator'] === $clean['thousands_separator']) {
        $errors['thousands_separator'] = 'The thousands separator must be different from the decimal separator.';
    }
    return [$clean, $errors];
}

/* ------------------------------------------------------------------ */
/* Formatting helpers used across the system                           */
/* ------------------------------------------------------------------ */

function cr_currency_decimals(): int {
    return max(0, min(3, (int)cr_setting('currency_decimals')));
}

/** Formats a number using the configured separators (no currency symbol). */
function cr_number($amount, ?int $decimals = null): string {
    $decimals = $decimals ?? cr_currency_decimals();
    return number_format((float)$amount, $decimals, cr_setting('decimal_separator'), cr_setting('thousands_separator'));
}

/** Money as plain text, e.g. "$1,250.00" or "1.250,00 TRY". Use money() inside HTML. */
function money_text($amount): string {
    $amount = (float)$amount;
    $formatted = cr_number(abs($amount));
    $symbol = cr_setting('currency_symbol');
    if ($symbol === '') $symbol = cr_setting('currency_code');
    $sign = $amount < 0 ? '-' : '';
    if (cr_setting('currency_position') === 'after') return $sign . $formatted . ' ' . $symbol;
    $space = (mb_strlen($symbol) > 1) ? ' ' : '';
    return $sign . $symbol . $space . $formatted;
}

/** Money, HTML-escaped and safe to print directly. */
function money($amount): string {
    return htmlspecialchars(money_text($amount), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Settings the browser needs to format money the same way (for live price previews). */
function cr_money_js_config(): string {
    return json_encode([
        'symbol' => cr_setting('currency_symbol') !== '' ? cr_setting('currency_symbol') : cr_setting('currency_code'),
        'position' => cr_setting('currency_position'),
        'decimals' => cr_currency_decimals(),
        'dec' => cr_setting('decimal_separator'),
        'thou' => cr_setting('thousands_separator'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/**
 * Strictly parses a money amount typed by a user. Accepts "1500", "1500.50", "1,500.50",
 * "1.500,50", "1 500,50". Returns null when the text is not a valid amount.
 */
function cr_parse_money($value): ?float {
    if (is_int($value) || is_float($value)) return round((float)$value, 2);
    $v = trim(str_replace(["\xc2\xa0", ' ', "'"], '', (string)$value));
    if ($v === '') return null;
    $negative = false;
    if ($v[0] === '-') { $negative = true; $v = substr($v, 1); }
    if (!preg_match('/^[0-9.,]+$/', $v)) return null;

    $lastDot = strrpos($v, '.');
    $lastComma = strrpos($v, ',');
    if ($lastDot !== false && $lastComma !== false) {
        // Both present: the right-most one is the decimal separator.
        $decimal = $lastDot > $lastComma ? '.' : ',';
    } elseif ($lastDot !== false || $lastComma !== false) {
        $sep = $lastDot !== false ? '.' : ',';
        $parts = explode($sep, $v);
        // A single separator followed by exactly 3 digits is a thousands separator ("1,500"),
        // unless it matches the configured decimal separator and the amount has a leading zero ("0.500").
        if (count($parts) > 2) $decimal = null;
        elseif (strlen(end($parts)) === 3 && $parts[0] !== '0' && $sep !== cr_setting('decimal_separator')) $decimal = null;
        else $decimal = $sep;
    } else {
        $decimal = null;
    }

    if ($decimal === null) {
        $normalized = str_replace(['.', ','], '', $v);
    } else {
        $thousands = $decimal === '.' ? ',' : '.';
        $intPart = substr($v, 0, strrpos($v, $decimal));
        $fracPart = substr($v, strrpos($v, $decimal) + 1);
        if (strpos($fracPart, ',') !== false || strpos($fracPart, '.') !== false) return null;
        if ($intPart !== '' && !preg_match('/^\d{1,3}(' . preg_quote($thousands, '/') . '\d{3})*$|^\d+$/', $intPart)) return null;
        $normalized = str_replace($thousands, '', $intPart) . '.' . $fracPart;
    }
    if (!is_numeric($normalized)) return null;
    $amount = round((float)$normalized, 2);
    return $negative ? -$amount : $amount;
}

/** Formats a date (Y-m-d or datetime) with the configured date format. */
function cr_date($date, bool $withTime = false): string {
    if ($date === null || $date === '' || $date === '0000-00-00') return '';
    try { $d = new DateTimeImmutable((string)$date); } catch (Exception $e) { return (string)$date; }
    return $d->format(cr_setting('date_format') . ($withTime ? ' H:i' : ''));
}

/** Converts a local phone number to international digits for WhatsApp/SMS links. */
function cr_phone_international(string $phone): string {
    $raw = trim($phone);
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '') return '';
    if (str_starts_with($raw, '+')) return $digits;
    if (str_starts_with($digits, '00')) return substr($digits, 2);
    $cc = cr_setting('phone_country_code');
    if ($cc !== '' && str_starts_with($digits, '0')) return $cc . ltrim($digits, '0');
    if ($cc !== '' && !str_starts_with($digits, $cc) && strlen($digits) <= 10) return $cc . $digits;
    return $digits;
}

/** Alternative spellings of a phone number, used to match a customer record. */
function cr_phone_variants(string $phone): array {
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return [];
    $variants = [$phone, $digits, '+' . $digits];
    $intl = cr_phone_international($phone);
    $variants[] = $intl;
    $variants[] = '+' . $intl;
    $cc = cr_setting('phone_country_code');
    if ($cc !== '' && str_starts_with($intl, $cc)) $variants[] = '0' . substr($intl, strlen($cc));
    return array_values(array_unique(array_filter($variants)));
}

function cr_company_name(): string {
    $name = trim(cr_setting('company_name'));
    return $name !== '' ? $name : 'Car Rental';
}

function cr_not_returned_title(): string {
    $authority = trim(cr_setting('authority_report_name'));
    return $authority !== '' ? 'Not Returned / ' . $authority . ' Cases' : 'Not Returned Cases';
}
