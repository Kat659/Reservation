<?php
require_once __DIR__ . '/functions.php';

/**
 * page_header('Title', 'dashboard', '<a class="btn">Action</a>');
 * $active: dashboard | facilities | equipment | reserve | reservations | admin | users | reports | profile
 */
function page_header($title, $active = '', $actions = '') {
    $role = $_SESSION['role'] ?? 'student';
    $name = $_SESSION['full_name'] ?? '';
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) { if ($w !== '') $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
    $initials = mb_substr($initials, 0, 2);

    $items = [
        ['dashboard', 'dashboard.php', 'home', 'Dashboard'],
        ['facilities', 'facilities.php', 'grid', 'Facilities'],
        ['equipment', 'equipment.php', 'box', 'Equipment'],
    ];
    $isAdminRole = in_array($role, ADMIN_ROLES, true);
    if (!$isAdminRole) $items[] = ['reserve', 'reserve.php', 'plus', 'New reservation'];
    $items[] = ['reservations', 'reservations.php', 'calendar', $isAdminRole ? 'All reservations' : 'My reservations'];

    echo '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Fraunces:opsz,wght@9..144,300;400;500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="style.css?v=' . @filemtime(__DIR__ . '/style.css') . '"></head><body>';
    echo '<div class="shell">';
    echo '<aside class="sidebar" id="sidebar">';
    echo '<a class="brand" href="dashboard.php"><span class="brand-mark">' . icon('grid', 16) . '</span><span>Facilities <b>Reservation</b></span></a>';
    echo '<nav class="nav">';
    foreach ($items as $it) {
        echo '<a href="' . $it[1] . '" class="' . ($active === $it[0] ? 'on' : '') . '">' . icon($it[2]) . '<span>' . e($it[3]) . '</span></a>';
    }
    if ($isAdminRole) {
        echo '<div class="nav-label">Administration · ' . e(role_label($role)) . '</div>';
        $adm = [['admin', 'admin.php', 'clip', 'Reservations']];
        if (in_array($role, ['admin'], true)) {
            $adm[] = ['manage_fac', 'admin.php?tab=facilities', 'grid', 'Manage facilities'];
            $adm[] = ['manage_eq', 'admin.php?tab=equipment', 'box', 'Manage equipment'];
            $adm[] = ['users', 'users.php', 'users', 'Users'];
            $adm[] = ['reports', 'reports.php', 'chart', 'Reports'];
        }
        foreach ($adm as $it) {
            echo '<a href="' . $it[1] . '" class="' . ($active === $it[0] ? 'on' : '') . '">' . icon($it[2]) . '<span>' . e($it[3]) . '</span></a>';
        }
    }
    echo '</nav>';
    echo '<div class="side-foot">';
    echo '<a href="logout.php">' . icon('logout') . '<span>Log out</span></a>';
    echo '</div></aside>';
    echo '<div class="scrim" id="scrim"></div>';

    echo '<div class="main"><header class="topbar">';
    echo '<button class="burger" id="burger" aria-label="Menu">' . icon('menu', 20) . '</button>';
    echo '<h1 class="page-title">' . e($title) . '</h1><div class="topbar-right">' . $actions;
    global $conn;
    $unread = 0; $recent = [];
    if (isset($conn, $_SESSION['user_id'])) {
        $uidN = (int)$_SESSION['user_id'];
        $unread = unread_notifications($conn, $uidN);
        $qN = $conn->prepare("SELECT id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 8");
        $qN->bind_param("i", $uidN);
        $qN->execute();
        $recent = $qN->get_result()->fetch_all(MYSQLI_ASSOC);
        $qN->close();
    }
    echo '<div class="notif-wrap"><button type="button" class="me bell" id="bellBtn" title="Notifications" aria-label="Notifications" aria-expanded="false">' . icon('bell', 18) . ($unread ? '<span class="badge" id="bellBadge">' . ($unread > 9 ? '9+' : $unread) . '</span>' : '') . '</button>';
    echo '<div class="notif-pop" id="notifPop" hidden><div class="notif-pop-head"><strong>Notifications</strong><button type="button" id="notifClear">Clear all</button></div><div class="notif-pop-body" id="notifBody">';
    if (!$recent) echo '<p class="notif-empty">You have no notifications yet.</p>';
    foreach ($recent as $n) {
        echo '<a class="notif ' . ($n['is_read'] ? '' : 'unread') . '" href="notifications.php?open=' . (int)$n['id'] . '"><strong>' . ($n['is_read'] ? '' : '<span class="dot"></span>') . e($n['title']) . '</strong><span>' . e($n['message']) . '</span><small>' . e(time_ago($n['created_at'])) . '</small></a>';
    }
    echo '</div></div></div>';
    echo '<input type="hidden" id="csrfTok" value="' . e(csrf_token()) . '">';
    echo '<a class="me" href="profile.php" title="Profile" aria-label="Profile">' . icon('user', 18) . '</a>';
    echo '</div></header><main class="content">';
    echo render_flash();
}

function page_footer() {
    echo '</main></div></div><script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("#notifPop a.notif");if(a){e.preventDefault();e.stopPropagation();location.href=a.getAttribute("href")}},true)</script><script src="app.js?v=' . @filemtime(__DIR__ . '/app.js') . '"></script></body></html>';
}
