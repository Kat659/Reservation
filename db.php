<?php
require_once __DIR__ . '/config.php';

$host = "localhost";
$dbname = "school_reservation";
$user = "root";
$pass = "";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $dbname);
} catch (mysqli_sql_exception $ex) {
    die("Database connection failed: " . $ex->getMessage() . " — did you import database.sql?");
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

// Self-repair for older databases: add any missing column automatically.
$needed = [
    'users' => ['email' => 'VARCHAR(150) NULL', 'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'course' => 'VARCHAR(100) NULL', 'year_level' => 'VARCHAR(20) NULL', 'block' => 'VARCHAR(20) NULL'],
    'facilities' => ['image' => 'VARCHAR(255) NULL'],
    'equipment' => ['image' => 'VARCHAR(255) NULL'],
    'reservations' => ['reference_code' => 'VARCHAR(24) NULL', 'edited_at' => 'DATETIME NULL'],
];
foreach ($needed as $table => $cols) {
    foreach ($cols as $col => $def) {
        if ($conn->query("SHOW COLUMNS FROM $table LIKE '$col'")->num_rows === 0) $conn->query("ALTER TABLE $table ADD COLUMN $col $def");
    }
}
// Three administrator levels: widen the role list on older databases.
$roleCol = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
if ($roleCol && strpos($roleCol['Type'], 'head_admin') === false) {
    $conn->query("ALTER TABLE users MODIFY role ENUM('student','faculty','assistant_admin','admin','head_admin') NOT NULL DEFAULT 'student'");
}
// The Head Admin level was removed: switch off any old head admin account (Admin is now the top level).
// Only Admin + Assistant Admin 1 & 2 remain: remove the old Head Admin and the old single 'assistant' account.
foreach (["role='head_admin'", "(username='assistant' AND role='assistant_admin')"] as $cond) {
    $conn->query("DELETE FROM reservations WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE $cond) t)");
    $conn->query("DELETE FROM users WHERE $cond");
}
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL, link VARCHAR(255) NOT NULL DEFAULT '', is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$hadItems = $conn->query("SHOW TABLES LIKE 'reservation_items'")->num_rows > 0;
$conn->query("CREATE TABLE IF NOT EXISTS reservation_items (
    id INT AUTO_INCREMENT PRIMARY KEY, reservation_id INT NOT NULL, equipment_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1,
    INDEX idx_item_eq (equipment_id), INDEX idx_item_res (reservation_id),
    CONSTRAINT fk_item_res FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_eq FOREIGN KEY (equipment_id) REFERENCES equipment(id)
) ENGINE=InnoDB");
if (!$hadItems) { // first run: copy equipment from older reservations
    $conn->query("INSERT INTO reservation_items (reservation_id, equipment_id, quantity)
        SELECT id, equipment_id, GREATEST(equipment_quantity, 1) FROM reservations WHERE equipment_id IS NOT NULL");
}

// Automatic status (ONLY these two; Approved / Declined / Cancelled are always decided by the administrator):
//   Approved -> In Use     when the start time comes
//   In Use   -> Completed  when the end time passes
$nowPhp = $conn->real_escape_string(date('Y-m-d H:i:s'));   // PHP clock, so the app's time zone is used
$conn->query("UPDATE reservations SET status='Completed' WHERE status IN ('Approved','In Use') AND TIMESTAMP(reservation_date, end_time) <= '$nowPhp'");
$conn->query("UPDATE reservations SET status='In Use' WHERE status='Approved' AND TIMESTAMP(reservation_date, start_time) <= '$nowPhp' AND TIMESTAMP(reservation_date, end_time) > '$nowPhp'");
