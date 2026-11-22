<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$uid = (int)$_SESSION['user_id'];
$admin = is_admin();
$today = date('Y-m-d');

function scalar($conn, $sql, $types = '', $params = []) {
    $q = $conn->prepare($sql);
    if ($types) $q->bind_param($types, ...$params);
    $q->execute();
    $v = $q->get_result()->fetch_row()[0] ?? 0;
    $q->close();
    return (int)$v;
}

if ($admin) {
    $stats = [
        ['Pending requests', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE status='Pending'"), 'Waiting for review'],
        ['Approved upcoming', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE status='Approved' AND reservation_date >= ?", 's', [$today]), 'From today onward'],
        ['Facilities', scalar($conn, "SELECT COUNT(*) FROM facilities WHERE status='available'"), 'Available to book'],
        ['Active users', scalar($conn, "SELECT COUNT(*) FROM users WHERE status='active'"), 'Students, faculty, admins'],
    ];
    $listSql = "SELECT r.*, u.full_name, u.role, u.course, u.year_level, u.block, f.facility_name, " . EQUIP_LIST . " AS equip_list FROM reservations r JOIN users u ON u.id=r.user_id
        LEFT JOIN facilities f ON f.id=r.facility_id
        WHERE r.reservation_date >= ? AND r.status IN ('Pending','Approved','In Use') ORDER BY r.reservation_date, r.start_time LIMIT 6";
    $q = $conn->prepare($listSql);
    $q->bind_param("s", $today);
} else {
    $stats = [
        ['Pending', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE user_id=? AND status='Pending'", 'i', [$uid]), 'Awaiting approval'],
        ['Approved upcoming', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE user_id=? AND status='Approved' AND reservation_date >= '$today'", 'i', [$uid]), 'Ready to use'],
        ['Completed', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE user_id=? AND status='Completed'", 'i', [$uid]), 'All time'],
        ['Total requests', scalar($conn, "SELECT COUNT(*) FROM reservations WHERE user_id=?", 'i', [$uid]), 'All time'],
    ];
    $listSql = "SELECT r.*, f.facility_name, " . EQUIP_LIST . " AS equip_list FROM reservations r
        LEFT JOIN facilities f ON f.id=r.facility_id
        WHERE r.user_id = ? AND r.reservation_date >= ? AND r.status IN ('Pending','Approved','In Use') ORDER BY r.reservation_date, r.start_time LIMIT 6";
    $q = $conn->prepare($listSql);
    $q->bind_param("is", $uid, $today);
}
$q->execute();
$upcoming = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$facilities = $conn->query("SELECT * FROM facilities WHERE status='available' ORDER BY facility_name LIMIT 3")->fetch_all(MYSQLI_ASSOC);
$equipment = $conn->query("SELECT * FROM equipment WHERE status='available' ORDER BY equipment_name LIMIT 3")->fetch_all(MYSQLI_ASSOC);
$reserved = equipment_reserved_map($conn);
$sched = equipment_schedule($conn);
$fsched = facility_schedule($conn);

$actions = $admin ? '' : '<a class="btn btn-sm" href="reserve.php">' . icon('plus', 15) . ' New reservation</a>';
page_header('Dashboard', 'dashboard', $actions);
?>
<?php $hr = (int)date('G'); $greet = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening'); ?>
<div class="home-head">
    <div><p class="date"><?= e(date('l, F j')) ?></p><h2><?= $greet ?>, <?= e(explode(' ', $_SESSION['full_name'])[0]) ?></h2><p><?= $admin ? 'Here is what needs your attention today.' : 'Here is what you have coming up.' ?></p></div>
</div>
<div class="stats">
<?php foreach ($stats as $s): ?>
    <div class="stat"><span><?= e($s[0]) ?></span><b><?= (int)$s[1] ?></b><small><?= e($s[2]) ?></small></div>
<?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head">
        <div><h2><?= $admin ? 'Upcoming reservations' : 'Your upcoming reservations' ?></h2><p>Next six, soonest first.</p></div>
        <a class="btn btn-light btn-sm" href="<?= $admin ? 'admin.php' : 'reservations.php' ?>">View all</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><?php if ($admin): ?><th>Requester</th><?php endif; ?><th>Resource</th><th>Date</th><th>Time</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$upcoming): ?><tr><td class="empty" colspan="5">Nothing scheduled yet.</td></tr><?php endif; ?>
            <?php foreach ($upcoming as $r): ?>
                <tr>
                    <?php if ($admin): ?><td><?= requester_cell($r) ?></td><?php endif; ?>
                    <td><?= resource_cell($r) ?></td>
                    <td><?= e(fmt_date($r['reservation_date'])) ?></td>
                    <td><?= e(fmt_time($r['start_time'])) ?> – <?= e(fmt_time($r['end_time'])) ?></td>
                    <td><?= status_pill($r['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>Facilities</h2><p>Pick a room, check the day, reserve.</p></div><a class="btn btn-light btn-sm" href="facilities.php">See all</a></div>
    <div class="grid g3">
    <?php foreach ($facilities as $f): ?>
        <div class="res">
            <?= resource_pic('facility', $f) ?>
            <div class="res-body">
                <div class="res-top"><h3><?= e($f['facility_name']) ?></h3><?= status_pill(facility_status($f, $fsched)) ?></div>
                <div class="res-meta"><span><?= e($f['location']) ?></span><span><?= (int)$f['capacity'] ?> seats</span></div>
                <?php if (!$admin): ?><div class="res-foot"><a class="btn btn-sm" href="reserve.php?facility=<?= (int)$f['id'] ?>">Reserve</a></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-head"><div><h2>Equipment</h2><p>Borrow what you need for your activity.</p></div><a class="btn btn-light btn-sm" href="equipment.php">See all</a></div>
    <div class="grid g3">
    <?php foreach ($equipment as $e): ?>
        <div class="res">
            <?= resource_pic('equipment', $e) ?>
            <div class="res-body">
                <div class="res-top"><h3><?= e($e['equipment_name']) ?></h3><?= status_pill(equipment_status($e, $reserved, $sched)) ?></div>
                <div class="res-meta"><span><?= e($e['category']) ?></span><span><?= e(equipment_stock_text($e, $reserved)) ?></span></div>
                <?php if (!$admin): ?><div class="res-foot"><a class="btn btn-sm" href="reserve.php?equipment=<?= (int)$e['id'] ?>">Reserve</a></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php page_footer();
