<?php
/*
 * Database upgrades. The stored schema_version (settings table) tells which upgrades
 * already ran, so on a normal page load this costs nothing. Raise CR_SCHEMA_VERSION and
 * add a block in cr_run_migrations() whenever the database structure changes.
 */
if (!defined('CR_SCHEMA_VERSION')) define('CR_SCHEMA_VERSION', 4);

function cr_migrate_if_needed(mysqli $conn): void {
    $current = (int)($GLOBALS['__cr_settings']['schema_version'] ?? 0);
    if ($current >= CR_SCHEMA_VERSION) return;

    // Only one request runs the upgrade; others wait for it to finish.
    $conn->query("SELECT GET_LOCK('car_rental_migrate', 60)");
    try {
        $settingsExisted = cr_table_exists($conn, 'settings');
        $current = (int)(cr_settings_load($conn, true)['schema_version'] ?? 0);
        if ($current < CR_SCHEMA_VERSION) {
            cr_run_migrations($conn, $current, $settingsExisted);
            cr_settings_save($conn, ['schema_version' => (string)CR_SCHEMA_VERSION]);
        }
    } catch (Throwable $e) {
        error_log('Database upgrade failed: ' . $e->getMessage());
    } finally {
        $conn->query("SELECT RELEASE_LOCK('car_rental_migrate')");
    }
}

function cr_run_migrations(mysqli $conn, int $from, bool $settingsExisted): void {
    if ($from < 1) {
        $conn->query("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        ensure_full_car_rental_schema($conn);
    }

    if ($from < 2 && !$settingsExisted) {
        // Existing installations upgraded from the old version keep the values that used to be
        // hardcoded (TRY prices, +255 phone numbers) until the admin reviews Settings.
        $hasData = false;
        $res = $conn->query('SELECT (SELECT COUNT(*) FROM rentals) + (SELECT COUNT(*) FROM cars) AS n');
        if ($res) $hasData = (int)$res->fetch_assoc()['n'] > 0;
        $seed = ['timezone' => (string)cr_config('app.timezone', 'UTC') ?: 'UTC', 'settings_reviewed' => '0'];
        if ($hasData) {
            $seed += ['currency_code' => 'TRY', 'currency_symbol' => 'TRY', 'currency_position' => 'after', 'phone_country_code' => '255'];
        }
        cr_settings_save($conn, $seed);
    }

    if ($from < 3) {
        // Car returns are confirmed by staff (the rental itself finishes automatically).
        cr_add_column_if_missing($conn, 'rentals', 'returned_at', "returned_at DATETIME NULL AFTER completed_at");
        cr_add_column_if_missing($conn, 'rentals', 'return_mileage', "return_mileage INT NULL AFTER returned_by");
        cr_add_column_if_missing($conn, 'rentals', 'return_notes', "return_notes TEXT NULL AFTER return_mileage");
        // Everything that finished before this upgrade counts as returned.
        $conn->query("UPDATE rentals SET returned_at = COALESCE(completed_at, CONCAT(end_date, ' 23:59:59'))
                      WHERE returned_at IS NULL AND status IN ('Completed','Canceled','Deleted')");

        // Recorded late-payment interest: one row per overdue day, never recalculated.
        $conn->query("CREATE TABLE IF NOT EXISTS rental_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rental_id INT NOT NULL,
            charge_type VARCHAR(30) NOT NULL DEFAULT 'late_interest',
            charge_date DATE NOT NULL,
            base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_charge_day (rental_id, charge_type, charge_date),
            KEY idx_charge_rental (rental_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        cr_add_index_if_missing($conn, 'rentals', 'idx_rental_status', 'INDEX idx_rental_status (status)');
        cr_add_index_if_missing($conn, 'rentals', 'idx_rental_car_dates', 'INDEX idx_rental_car_dates (cars_id, start_date, end_date)');
        cr_add_index_if_missing($conn, 'reservations', 'idx_res_status_car', 'INDEX idx_res_status_car (status, car_id)');
        cr_add_index_if_missing($conn, 'payment', 'idx_payment_date', 'INDEX idx_payment_date (payment_date)');

        // Customer forms used to escape text twice, saving "O\'Brien". Repair those values.
        $bs = chr(92); // backslash
        $fixes = [$bs . "'" => "'", $bs . '"' => '"', $bs . $bs => $bs];
        foreach (['full_name', 'phone_number', 'email', 'license_no', 'address', 'id_number', 'passport_no'] as $col) {
            if (!cr_column_exists($conn, 'customers', $col)) continue;
            $rows = $conn->query("SELECT id, `$col` AS v FROM customers WHERE LOCATE(CHAR(92), `$col`) > 0");
            if (!$rows) continue;
            $upd = $conn->prepare("UPDATE customers SET `$col` = ? WHERE id = ?");
            while ($row = $rows->fetch_assoc()) {
                $fixed = strtr((string)$row['v'], $fixes);
                $id = (int)$row['id'];
                $upd->bind_param('si', $fixed, $id);
                $upd->execute();
            }
            $upd->close();
        }
    }

    if ($from < 4) {
        // Indexes for list pages, searches and the status sync.
        cr_add_index_if_missing($conn, 'rentals', 'idx_rental_status_end', 'INDEX idx_rental_status_end (status, end_date)');
        cr_add_index_if_missing($conn, 'rentals', 'idx_rental_car_returned', 'INDEX idx_rental_car_returned (cars_id, returned_at)');
        cr_add_index_if_missing($conn, 'customers', 'idx_customer_name', 'INDEX idx_customer_name (full_name)');
        cr_add_index_if_missing($conn, 'customers', 'idx_customer_phone', 'INDEX idx_customer_phone (phone_number)');
        cr_add_index_if_missing($conn, 'customers', 'idx_customer_license', 'INDEX idx_customer_license (license_no)');
        cr_add_index_if_missing($conn, 'customers', 'idx_customer_email', 'INDEX idx_customer_email (email)');
        cr_add_index_if_missing($conn, 'cars', 'idx_car_status', 'INDEX idx_car_status (availability_status)');
        cr_add_index_if_missing($conn, 'notifications', 'idx_notification_created', 'INDEX idx_notification_created (created_at)');
    }
}

function cr_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    return $result && $result->num_rows > 0;
}

function cr_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $result && $result->num_rows > 0;
}

function cr_add_column_if_missing(mysqli $conn, string $table, string $column, string $definition): void {
    if (cr_table_exists($conn, $table) && !cr_column_exists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function cr_add_index_if_missing(mysqli $conn, string $table, string $index, string $definition): void {
    if (!cr_table_exists($conn, $table)) return;
    $safeTable = $conn->real_escape_string($table);
    $safeIndex = $conn->real_escape_string($index);
    $exists = $conn->query("SHOW INDEX FROM `$safeTable` WHERE Key_name='$safeIndex'");
    if (!$exists || $exists->num_rows === 0) {
        @$conn->query("ALTER TABLE `$table` ADD $definition");
    }
}

function ensure_full_car_rental_schema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Existing table upgrades. These are additive only, so current working logic remains safe.
    cr_add_column_if_missing($conn, 'customers', 'address', "address VARCHAR(255) NULL AFTER email");
    cr_add_column_if_missing($conn, 'customers', 'id_number', "id_number VARCHAR(100) NULL AFTER address");
    cr_add_column_if_missing($conn, 'customers', 'passport_no', "passport_no VARCHAR(100) NULL AFTER id_number");
    cr_add_column_if_missing($conn, 'customers', 'passport_expiry', "passport_expiry DATE NULL AFTER passport_no");
    cr_add_column_if_missing($conn, 'customers', 'license_expiry', "license_expiry DATE NULL AFTER license_no");
    cr_add_column_if_missing($conn, 'customers', 'loyalty_points', "loyalty_points INT NOT NULL DEFAULT 0 AFTER license_expiry");

    cr_add_column_if_missing($conn, 'cars', 'vin', "vin VARCHAR(100) NULL AFTER reg_no");
    cr_add_column_if_missing($conn, 'cars', 'color', "color VARCHAR(50) NULL AFTER vin");
    cr_add_column_if_missing($conn, 'cars', 'current_mileage', "current_mileage INT NULL AFTER color");
    cr_add_column_if_missing($conn, 'cars', 'insurance_expiry', "insurance_expiry DATE NULL AFTER current_mileage");
    cr_add_column_if_missing($conn, 'cars', 'inspection_expiry', "inspection_expiry DATE NULL AFTER insurance_expiry");
    cr_add_column_if_missing($conn, 'cars', 'branch', "branch VARCHAR(120) NULL AFTER inspection_expiry");
    cr_add_column_if_missing($conn, 'cars', 'purchase_value', "purchase_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price_per_day");

    cr_add_column_if_missing($conn, 'rentals', 'pickup_location', "pickup_location VARCHAR(255) NULL AFTER end_date");
    cr_add_column_if_missing($conn, 'rentals', 'dropoff_location', "dropoff_location VARCHAR(255) NULL AFTER pickup_location");
    cr_add_column_if_missing($conn, 'rentals', 'deposit_amount', "deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_cost");
    cr_add_column_if_missing($conn, 'rentals', 'discount_amount', "discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_amount");
    cr_add_column_if_missing($conn, 'rentals', 'tax_amount', "tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount");
    cr_add_column_if_missing($conn, 'rentals', 'notes', "notes TEXT NULL AFTER tax_amount");
    cr_add_column_if_missing($conn, 'rentals', 'completed_at', "completed_at DATETIME NULL AFTER status");
    cr_add_column_if_missing($conn, 'rentals', 'returned_by', "returned_by INT NULL AFTER completed_at");

    cr_add_column_if_missing($conn, 'payment', 'payment_method', "payment_method VARCHAR(80) NULL AFTER amount");
    cr_add_column_if_missing($conn, 'payment', 'payment_note', "payment_note VARCHAR(255) NULL AFTER payment_method");
    cr_add_column_if_missing($conn, 'payment', 'created_by', "created_by INT NULL AFTER payment_note");
    cr_add_column_if_missing($conn, 'payment', 'is_refund', "is_refund TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by");

    cr_add_column_if_missing($conn, 'car_service', 'next_service_date', "next_service_date DATE NULL AFTER service_date");
    cr_add_column_if_missing($conn, 'car_service', 'supplier', "supplier VARCHAR(150) NULL AFTER cost");
    cr_add_column_if_missing($conn, 'car_service', 'status', "status VARCHAR(40) NOT NULL DEFAULT 'Completed' AFTER supplier");

    // Security: bumping session_version logs a user out everywhere (password change, role change, deactivation).
    cr_add_column_if_missing($conn, 'users', 'session_version', "session_version INT NOT NULL DEFAULT 0 AFTER is_active");
    cr_add_column_if_missing($conn, 'documents', 'original_name', "original_name VARCHAR(255) NULL AFTER file_path");
    cr_add_column_if_missing($conn, 'documents', 'mime_type', "mime_type VARCHAR(100) NULL AFTER original_name");

    // Server-side rate limiting (login, password reset, public booking). Not bypassable by clearing cookies.
    @$conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_rate_bucket (bucket, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("INSERT IGNORE INTO roles (id, name) VALUES (1,'Admin'),(2,'Staff'),(3,'Manager'),(4,'Cashier'),(5,'Maintenance')");

    @$conn->query("CREATE TABLE IF NOT EXISTS reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NULL,
        car_id INT NULL,
        customer_name VARCHAR(255) NULL,
        phone_number VARCHAR(50) NULL,
        email VARCHAR(255) NULL,
        pickup_date DATE NOT NULL,
        dropoff_date DATE NOT NULL,
        pickup_location VARCHAR(255) NULL,
        dropoff_location VARCHAR(255) NULL,
        status ENUM('Pending','Confirmed','Waitlisted','Converted','Cancelled') NOT NULL DEFAULT 'Pending',
        source VARCHAR(50) NOT NULL DEFAULT 'Office',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_res_customer (customer_id),
        KEY idx_res_car (car_id),
        KEY idx_res_dates (pickup_date, dropoff_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NULL,
        car_id INT NULL,
        rental_id INT NULL,
        reservation_id INT NULL,
        document_type VARCHAR(80) NOT NULL,
        title VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NULL,
        original_name VARCHAR(255) NULL,
        mime_type VARCHAR(100) NULL,
        expiry_date DATE NULL,
        notes TEXT NULL,
        uploaded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_doc_customer (customer_id),
        KEY idx_doc_car (car_id),
        KEY idx_doc_rental (rental_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS fuel_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        car_id INT NOT NULL,
        log_date DATE NOT NULL,
        mileage INT NULL,
        liters DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        fuel_station VARCHAR(150) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fuel_car (car_id),
        KEY idx_fuel_date (log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS staff_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        assigned_to INT NULL,
        car_id INT NULL,
        rental_id INT NULL,
        task_type VARCHAR(80) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        due_date DATE NULL,
        status ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        KEY idx_task_assigned (assigned_to),
        KEY idx_task_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        work_date DATE NOT NULL,
        shift_name VARCHAR(80) NULL,
        clock_in TIME NULL,
        clock_out TIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_work_date (user_id, work_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        target_type VARCHAR(40) NOT NULL DEFAULT 'Staff',
        target_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        channel VARCHAR(40) NOT NULL DEFAULT 'System',
        status ENUM('Draft','Ready','Sent','Read') NOT NULL DEFAULT 'Ready',
        related_table VARCHAR(80) NULL,
        related_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        KEY idx_notification_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS not_returned_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rental_id INT NOT NULL,
        customer_id INT NULL,
        car_id INT NULL,
        case_reference VARCHAR(80) NULL,
        status ENUM('Open','Followed Up','Reported','Resolved','Closed') NOT NULL DEFAULT 'Open',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        KEY idx_not_returned_rental (rental_id),
        KEY idx_not_returned_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        car_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_expense_date (expense_date),
        KEY idx_expense_car (car_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
?>
