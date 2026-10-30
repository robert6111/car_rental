<?php
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
