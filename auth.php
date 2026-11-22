<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    // Deactivated accounts lose access immediately
    global $conn;
    $q = $conn->prepare("SELECT status FROM users WHERE id = ?");
    $q->bind_param("i", $_SESSION['user_id']);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row || $row['status'] !== 'active') {
        header("Location: logout.php");
        exit();
    }
    // Temporary password must be changed first
    $self = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($_SESSION['must_change_password']) && !in_array($self, ['profile.php', 'logout.php'], true)) {
        header("Location: profile.php?force=1");
        exit();
    }
}

// Two administrator levels:
//   admin            - full control: approvals, facilities, equipment, reports, all accounts
//   assistant_admin  - approvals / reservations only
const ADMIN_ROLES = ['admin', 'assistant_admin'];

function role_label($role) {
    $map = ['admin' => 'Admin', 'assistant_admin' => 'Assistant Admin'];
    return $map[$role] ?? ucfirst((string)$role);
}

function is_admin() {            // either administrator level
    return in_array($_SESSION['role'] ?? '', ADMIN_ROLES, true);
}

function is_head_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';   // Admin is the top level
}

function can_manage() {          // facilities, equipment, reports, users: Admin
    return in_array($_SESSION['role'] ?? '', ['admin'], true);
}

function require_admin() {       // any administrator level
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die("Access denied.");
    }
}

function require_manager() {     // Admin
    require_login();
    if (!can_manage()) {
        http_response_code(403);
        die("Access denied. This page is for the Admin only.");
    }
}

function require_head_admin() {
    require_login();
    if (!is_head_admin()) {
        http_response_code(403);
        die("Access denied. This page is for the Admin only.");
    }
}
?>
