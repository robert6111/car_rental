-- Car Rental - fresh installation.
-- Create an empty database and a database user first (see INSTALLATION_NOTES.txt),
-- then import this file INTO that database. It does not create users or pick a database itself.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- DROP TABLE IF EXISTS expenses;
-- DROP TABLE IF EXISTS not_returned_cases;
-- DROP TABLE IF EXISTS notifications;
-- DROP TABLE IF EXISTS attendance;
-- DROP TABLE IF EXISTS staff_tasks;
-- DROP TABLE IF EXISTS fuel_logs;
-- DROP TABLE IF EXISTS documents;
-- DROP TABLE IF EXISTS reservations;
-- DROP TABLE IF EXISTS car_service;
-- DROP TABLE IF EXISTS car_damages;
-- DROP TABLE IF EXISTS payment;
-- DROP TABLE IF EXISTS payment_method;
-- DROP TABLE IF EXISTS rentals;
-- DROP TABLE IF EXISTS cars;
-- DROP TABLE IF EXISTS models;
-- DROP TABLE IF EXISTS car_years;
-- DROP TABLE IF EXISTS makes;
-- DROP TABLE IF EXISTS users;
-- DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE roles (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) DEFAULT 1,
  session_version INT NOT NULL DEFAULT 0,
  reset_token VARCHAR(255) NULL,
  reset_expires DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY username (username),
  UNIQUE KEY email (email),
  KEY fk_role (role_id),
  CONSTRAINT fk_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(255) NOT NULL,
  phone_number VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL,
  license_no VARCHAR(100) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE makes (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_make (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE car_years (
  id INT NOT NULL AUTO_INCREMENT,
  year INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE models (
  id INT NOT NULL AUTO_INCREMENT,
  make_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY fk_model_make (make_id),
  CONSTRAINT fk_model_make FOREIGN KEY (make_id) REFERENCES makes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cars (
  id INT NOT NULL AUTO_INCREMENT,
  reg_no VARCHAR(255) NOT NULL,
  availability_status VARCHAR(50) NOT NULL DEFAULT 'Available',
  make_id INT NOT NULL,
  model_id INT NOT NULL,
  year_id INT NOT NULL,
  price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY unique_reg_no (reg_no),
  KEY fk_car_make (make_id),
  KEY fk_car_model (model_id),
  KEY fk_car_year (year_id),
  CONSTRAINT fk_car_make FOREIGN KEY (make_id) REFERENCES makes (id),
  CONSTRAINT fk_car_model FOREIGN KEY (model_id) REFERENCES models (id),
  CONSTRAINT fk_car_year FOREIGN KEY (year_id) REFERENCES car_years (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rentals (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  cars_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('Pending','Active','Completed','Canceled','Deleted') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_rental_customer (customer_id),
  KEY fk_rental_car (cars_id),
  CONSTRAINT fk_rental_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_rental_car FOREIGN KEY (cars_id) REFERENCES cars (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment (
  id INT NOT NULL AUTO_INCREMENT,
  payment_date DATE NOT NULL,
  rental_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY fk_payment_rental (rental_id),
  CONSTRAINT fk_payment_rental FOREIGN KEY (rental_id) REFERENCES rentals (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_method (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  card_number VARCHAR(16) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  expiry_date DATE DEFAULT NULL,
  CVV INT DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE car_damages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  car_id INT NOT NULL,
  reported_by INT NULL,
  rental_id INT NULL,
  damage_description TEXT NOT NULL,
  severity ENUM('low','medium','moderate','high') DEFAULT 'low',
  estimated_cost DECIMAL(10,2) NULL,
  date_reported DATE NOT NULL,
  repaired TINYINT(1) DEFAULT 0,
  repair_cost DECIMAL(10,2) NULL,
  repair_date DATE NULL,
  KEY fk_damage_car (car_id),
  KEY fk_damage_rental (rental_id),
  CONSTRAINT fk_damage_car FOREIGN KEY (car_id) REFERENCES cars(id),
  CONSTRAINT fk_damage_rental FOREIGN KEY (rental_id) REFERENCES rentals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE car_service (
  id INT AUTO_INCREMENT PRIMARY KEY,
  car_id INT NOT NULL,
  service_type VARCHAR(100),
  description TEXT,
  service_date DATE NOT NULL,
  next_service_mileage INT NULL,
  mileage_at_service INT NULL,
  cost DECIMAL(10,2),
  KEY fk_service_car (car_id),
  CONSTRAINT fk_service_car FOREIGN KEY (car_id) REFERENCES cars(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Staff'), (3, 'Manager'), (4, 'Cashier'), (5, 'Maintenance');
-- No default user: on first visit the system opens a setup screen to create the admin account.

INSERT INTO makes (id, name) VALUES (1,'Toyota'),(2,'Nissan'),(3,'Honda'),(4,'Hyundai');
INSERT INTO models (make_id, name) VALUES (1,'Corolla'),(1,'RAV4'),(2,'X-Trail'),(3,'Civic'),(4,'Tucson');
INSERT INTO car_years (year) VALUES (2026),(2025),(2024),(2023),(2022),(2021),(2020),(2019),(2018);


-- Customer-ready full system upgrades from the back-office blueprint/checklist
ALTER TABLE customers ADD COLUMN address VARCHAR(255) NULL AFTER email;
ALTER TABLE customers ADD COLUMN id_number VARCHAR(100) NULL AFTER address;
ALTER TABLE customers ADD COLUMN passport_no VARCHAR(100) NULL AFTER id_number;
ALTER TABLE customers ADD COLUMN passport_expiry DATE NULL AFTER passport_no;
ALTER TABLE customers ADD COLUMN license_expiry DATE NULL AFTER license_no;
ALTER TABLE customers ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER license_expiry;

ALTER TABLE cars ADD COLUMN vin VARCHAR(100) NULL AFTER reg_no;
ALTER TABLE cars ADD COLUMN color VARCHAR(50) NULL AFTER vin;
ALTER TABLE cars ADD COLUMN current_mileage INT NULL AFTER color;
ALTER TABLE cars ADD COLUMN insurance_expiry DATE NULL AFTER current_mileage;
ALTER TABLE cars ADD COLUMN inspection_expiry DATE NULL AFTER insurance_expiry;
ALTER TABLE cars ADD COLUMN branch VARCHAR(120) NULL AFTER inspection_expiry;
ALTER TABLE cars ADD COLUMN purchase_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price_per_day;

ALTER TABLE rentals ADD COLUMN pickup_location VARCHAR(255) NULL AFTER end_date;
ALTER TABLE rentals ADD COLUMN dropoff_location VARCHAR(255) NULL AFTER pickup_location;
ALTER TABLE rentals ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_cost;
ALTER TABLE rentals ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_amount;
ALTER TABLE rentals ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount;
ALTER TABLE rentals ADD COLUMN notes TEXT NULL AFTER tax_amount;
ALTER TABLE rentals ADD COLUMN completed_at DATETIME NULL AFTER status;
ALTER TABLE rentals ADD COLUMN returned_by INT NULL AFTER completed_at;

ALTER TABLE payment ADD COLUMN payment_method VARCHAR(80) NULL AFTER amount;
ALTER TABLE payment ADD COLUMN payment_note VARCHAR(255) NULL AFTER payment_method;
ALTER TABLE payment ADD COLUMN created_by INT NULL AFTER payment_note;
ALTER TABLE payment ADD COLUMN is_refund TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by;

ALTER TABLE car_service ADD COLUMN next_service_date DATE NULL AFTER service_date;
ALTER TABLE car_service ADD COLUMN supplier VARCHAR(150) NULL AFTER cost;
ALTER TABLE car_service ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'Completed' AFTER supplier;

CREATE TABLE reservations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fuel_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staff_tasks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  work_date DATE NOT NULL,
  shift_name VARCHAR(80) NULL,
  clock_in TIME NULL,
  clock_out TIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_work_date (user_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE not_returned_cases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rate_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Car returns and recorded late-payment interest
ALTER TABLE rentals ADD COLUMN returned_at DATETIME NULL AFTER completed_at;
ALTER TABLE rentals ADD COLUMN return_mileage INT NULL AFTER returned_by;
ALTER TABLE rentals ADD COLUMN return_notes TEXT NULL AFTER return_mileage;
ALTER TABLE rentals ADD INDEX idx_rental_status (status);
ALTER TABLE rentals ADD INDEX idx_rental_car_dates (cars_id, start_date, end_date);
ALTER TABLE reservations ADD INDEX idx_res_status_car (status, car_id);
ALTER TABLE payment ADD INDEX idx_payment_date (payment_date);

CREATE TABLE rental_charges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Indexes for list pages and searches
ALTER TABLE rentals ADD INDEX idx_rental_status_end (status, end_date);
ALTER TABLE rentals ADD INDEX idx_rental_car_returned (cars_id, returned_at);
ALTER TABLE customers ADD INDEX idx_customer_name (full_name);
ALTER TABLE customers ADD INDEX idx_customer_phone (phone_number);
ALTER TABLE customers ADD INDEX idx_customer_license (license_no);
ALTER TABLE customers ADD INDEX idx_customer_email (email);
ALTER TABLE cars ADD INDEX idx_car_status (availability_status);
ALTER TABLE notifications ADD INDEX idx_notification_created (created_at);

INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '5');
