-- DotCliq Studio Management System — database schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','frontdesk','photographer','editor') NOT NULL DEFAULT 'frontdesk',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(160) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_clients_name (name),
  KEY ix_clients_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  category ENUM('photo','video','combo','other') NOT NULL DEFAULT 'photo',
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no VARCHAR(30) NULL,
  client_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL,
  sold_by INT UNSIGNED NULL,
  sale_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_receipt (receipt_no),
  KEY ix_sales_date (sale_date),
  KEY ix_sales_client (client_id),
  KEY ix_sales_sold_by (sold_by),
  CONSTRAINT fk_sales_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NULL,
  description VARCHAR(200) NOT NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY ix_items_sale (sale_id),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','mpesa','bank','card','other') NOT NULL DEFAULT 'cash',
  reference VARCHAR(80) NULL,
  paid_at DATETIME NOT NULL,
  received_by INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pay_sale (sale_id),
  KEY ix_pay_date (paid_at),
  CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  shoot_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  location VARCHAR(200) NULL,
  assigned_to INT UNSIGNED NULL,
  status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  sale_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_book_date (shoot_date),
  KEY ix_book_client (client_id),
  KEY ix_book_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  job_type ENUM('retouch','video_edit','album','print','other') NOT NULL DEFAULT 'retouch',
  assigned_to INT UNSIGNED NULL,
  status ENUM('queued','in_progress','review','delivered') NOT NULL DEFAULT 'queued',
  priority ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  due_date DATE NULL,
  delivery_link VARCHAR(255) NULL,
  notes TEXT NULL,
  delivered_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jobs_status (status),
  KEY ix_jobs_assigned (assigned_to),
  KEY ix_jobs_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','mpesa','bank','card','other') NOT NULL DEFAULT 'cash',
  recorded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS equipment (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  category VARCHAR(80) NULL,
  serial_no VARCHAR(80) NULL,
  status ENUM('available','in_use','maintenance','retired') NOT NULL DEFAULT 'available',
  purchase_date DATE NULL,
  cost DECIMAL(12,2) NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_log_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(60) NULL,
  created_at DATETIME NOT NULL,
  KEY ix_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
