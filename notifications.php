<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['mark_all'])) {
        $q = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $q->bind_param("i", $uid);
        $q->execute();
        $q->close();
    }
    if (isset($_POST['clear_all'])) {
        $q = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $q->bind_param("i", $uid);
        $q->execute();
        $q->close();
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit(); }
    header("Location: dashboard.php");
    exit();
}

// Live check used by the bell: returns the unread count, the latest list and any NEW notifications to pop up
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $maxRow = $conn->prepare("SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id = ?");
    $maxRow->bind_param("i", $uid); $maxRow->execute();
    $maxId = (int)$maxRow->get_result()->fetch_row()[0]; $maxRow->close();
    if (!isset($_SESSION['notif_seen'])) $_SESSION['notif_seen'] = max(0, $maxId - (int)($_GET['init'] ?? 0)); // first call: toast the newest 'init' ones
    $seen = (int)$_SESSION['notif_seen'];
    $q = $conn->prepare("SELECT id, title, message, link FROM notifications WHERE user_id = ? AND id > ? AND is_read = 0 ORDER BY id");
    $q->bind_param("ii", $uid, $seen); $q->execute();
    $new = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    $_SESSION['notif_seen'] = max($seen, $maxId);
    $q = $conn->prepare("SELECT id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 8");
    $q->bind_param("i", $uid); $q->execute();
    $list = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    foreach ($list as &$l) { $l['ago'] = time_ago($l['created_at']); $l['is_read'] = (int)$l['is_read']; } unset($l);
    echo json_encode(['unread' => unread_notifications($conn, $uid), 'new' => $new, 'list' => $list]);
    exit();
}

// Opening one notification marks it read, then goes to its page
if (isset($_GET['open'])) {
    $id = (int)$_GET['open'];
    $q = $conn->prepare("SELECT link FROM notifications WHERE id = ? AND user_id = ?");
    $q->bind_param("ii", $id, $uid);
    $q->execute();
    $n = $q->get_result()->fetch_assoc();
    $q->close();
    if ($n) {
        $u = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $u->bind_param("i", $id);
        $u->execute();
        $u->close();
        $dest = $n['link'];
        if ($dest === '' || preg_match('#^(https?:)?//#', $dest) || strpos($dest, '..') !== false) $dest = 'dashboard.php';
        header("Location: " . $dest);
        exit();
    }
}

// The list now lives in the bell pop-up; nothing to show on its own page.
header("Location: dashboard.php");
exit();
