-- School Facility Reservation and Equipment Booking Management System
-- Safe to import more than once: existing tables and data are kept.
CREATE DATABASE IF NOT EXISTS school_reservation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_reservation;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('student','faculty','assistant_admin','admin','head_admin') NOT NULL DEFAULT 'student',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    course VARCHAR(100) NULL,
    year_level VARCHAR(20) NULL,
    block VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS facilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(100) NOT NULL,
    location VARCHAR(150),
    capacity INT DEFAULT 0,
    description TEXT,
    image VARCHAR(255) NULL,
    status ENUM('available','unavailable') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_name VARCHAR(100) NOT NULL,
    category VARCHAR(100),
    quantity INT NOT NULL DEFAULT 1,
    condition_status VARCHAR(100) DEFAULT 'Good',
    image VARCHAR(255) NULL,
    status ENUM('available','unavailable') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(24) NULL UNIQUE,
    user_id INT NOT NULL,
    facility_id INT NULL,
    equipment_id INT NULL,
    equipment_quantity INT DEFAULT 0,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    remarks TEXT,
    status ENUM('Pending','Approved','Rejected','Cancelled','In Use','Completed') DEFAULT 'Pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    edited_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fac_slot (facility_id, reservation_date, status),
    INDEX idx_eq_slot (equipment_id, reservation_date, status),
    CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_res_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL,
    CONSTRAINT fk_res_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
    CONSTRAINT fk_res_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    equipment_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    INDEX idx_item_eq (equipment_id),
    INDEX idx_item_res (reservation_id),
    CONSTRAINT fk_item_res FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_eq FOREIGN KEY (equipment_id) REFERENCES equipment(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Demo accounts (admin/admin123, assistant1/assist123, assistant2/assist123, student1/student123, faculty1/faculty123) are
-- created automatically the first time login.php opens, with real bcrypt hashes.

INSERT INTO facilities (facility_name,location,capacity,description)
SELECT * FROM (SELECT 'Computer Laboratory 1' a,'Main Building - 2nd Floor' b,40 c,'Computer laboratory for programming and laboratory activities' d
 UNION ALL SELECT 'Conference Room','Administration Building',20,'Room for meetings and conferences'
 UNION ALL SELECT 'Audio-Visual Room','Main Building - 1st Floor',50,'Room for seminars and presentations') t
WHERE NOT EXISTS (SELECT 1 FROM facilities);
INSERT INTO equipment (equipment_name,category,quantity,condition_status)
SELECT * FROM (SELECT 'Laptop' a,'Computer' b,20 c,'Good' d
 UNION ALL SELECT 'Projector','Presentation',5,'Good'
 UNION ALL SELECT 'Camera','Media',3,'Good'
 UNION ALL SELECT 'Microphone','Audio',10,'Good'
 UNION ALL SELECT 'Speaker','Audio',5,'Good') t
WHERE NOT EXISTS (SELECT 1 FROM equipment);
