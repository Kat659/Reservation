<?php
require_once "auth.php";
require_manager();
require_once "db.php";
require_once "layout.php";

$types = [
    'facility'  => 'Facility reservations',
    'equipment' => 'Equipment bookings',
    'approved'  => 'Approved reservations',
    'declined'  => 'Cancelled / declined',
    'usage'     => 'Facility usage',
];
$type = $_GET['type'] ?? 'facility';
if (!isset($types[$type])) $type = 'facility';

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$validDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$validDate($from)) $from = '';
if (!$validDate($to)) $to = '';

$where = [];
$bt = '';
$bp = [];
if ($from) { $where[] = "r.reservation_date >= ?"; $bt .= 's'; $bp[] = $from; }
if ($to)   { $where[] = "r.reservation_date <= ?"; $bt .= 's'; $bp[] = $to; }

if ($type === 'facility')  $where[] = "r.facility_id IS NOT NULL";
if ($type === 'equipment') $where[] = "EXISTS (SELECT 1 FROM reservation_items x WHERE x.reservation_id = r.id)";
if ($type === 'approved')  $where[] = "r.status IN ('Approved','In Use','Completed')";
if ($type === 'declined')  $where[] = "r.status IN ('Cancelled','Rejected')";
if ($type === 'usage')     { $where[] = "r.facility_id IS NOT NULL"; $where[] = "r.status IN ('Approved','In Use','Completed')"; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

function run($conn, $sql, $bt, $bp) {
    $q = $conn->prepare($sql);
    if ($bt) $q->bind_param($bt, ...$bp);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    return $rows;
}

$base = "FROM reservations r JOIN users u ON u.id = r.user_id
         LEFT JOIN facilities f ON f.id = r.facility_id $w";

/* ---- headline numbers for the range ---- */
$whereRange = [];
if ($from) $whereRange[] = "reservation_date >= '" . $conn->real_escape_string($from) . "'";
if ($to)   $whereRange[] = "reservation_date <= '" . $conn->real_escape_string($to) . "'";
$wr = $whereRange ? 'WHERE ' . implode(' AND ', $whereRange) : '';
$sum = $conn->query("SELECT COUNT(*) total,
    SUM(status IN ('Approved','In Use','Completed')) ok,
    SUM(status = 'Pending') pend,
    SUM(status IN ('Cancelled','Rejected')) bad FROM reservations $wr")->fetch_assoc();

/* ---- the report itself ---- */
if ($type === 'usage') {
    $sql = "SELECT f.facility_name, f.location, COUNT(*) bookings,
            ROUND(SUM(TIME_TO_SEC(TIMEDIFF(r.end_time, r.start_time))) / 3600, 1) hours,
            SUM(r.status = 'Completed') completed, MAX(r.reservation_date) last_used
            $base GROUP BY f.id, f.facility_name, f.location ORDER BY bookings DESC";
    $all = run($conn, $sql, $bt, $bp);
    $total = count($all);
} else {
    $total = (int)run($conn, "SELECT COUNT(*) c $base", $bt, $bp)[0]['c'];
}

/* ---- CSV export ---- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($type === 'usage') {
        fputcsv($out, ['Facility', 'Location', 'Bookings', 'Hours', 'Completed', 'Last used']);
        foreach ($all as $r) fputcsv($out, [$r['facility_name'], $r['location'], $r['bookings'], $r['hours'], $r['completed'], $r['last_used']]);
    } else {
        fputcsv($out, ['Reference', 'Requester', 'Course / Year / Block or Role', 'Facility', 'Equipment', 'Date', 'Start', 'End', 'Purpose', 'Status']);
        $rows = run($conn, "SELECT r.*, u.full_name, u.role, u.course, u.year_level, u.block, f.facility_name, " . EQUIP_LIST . " AS equip_list $base ORDER BY r.reservation_date DESC, r.start_time DESC", $bt, $bp);
        foreach ($rows as $r) fputcsv($out, [$r['reference_code'], $r['full_name'], requester_detail($r), $r['facility_name'], $r['equip_list'], $r['reservation_date'], $r['start_time'], $r['end_time'], $r['purpose'], $r['status']]);
    }
    exit();
}

$showAll = isset($_GET['all']);
if ($type === 'usage') {
    $rows = $all;
    $p = ['total' => 0];
} else {
    $p = paginate($total, $showAll ? max(1, $total) : PER_PAGE);
    $rows = run($conn, "SELECT r.*, u.full_name, u.role, u.course, u.year_level, u.block, f.facility_name, " . EQUIP_LIST . " AS equip_list $base
        ORDER BY r.reservation_date DESC, r.start_time DESC LIMIT " . (int)$p['per'] . " OFFSET " . (int)$p['offset'], $bt, $bp);
}

$qs = http_build_query(array_filter(['type' => $type, 'from' => $from, 'to' => $to]));
page_header('Reports', 'reports',
    '<a class="btn btn-light btn-sm no-print" href="?' . e($qs) . '&export=1">' . icon('download', 15) . ' CSV</a>' .
    '<button class="btn btn-sm no-print" data-print type="button">' . icon('print', 15) . ' Print</button>');
?>
<div class="tabs">
<?php foreach ($types as $k => $label): ?>
    <a class="<?= $type === $k ? 'on' : '' ?>" href="?<?= e(http_build_query(array_filter(['type' => $k, 'from' => $from, 'to' => $to]))) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
</div>

<form class="toolbar" method="GET">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label style="margin:0">From</label><input type="date" name="from" value="<?= e($from) ?>">
    <label style="margin:0">To</label><input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn btn-light" type="submit">Apply</button>
    <?php if ($from || $to): ?><a class="btn btn-light" href="?type=<?= e($type) ?>">Clear</a><?php endif; ?>
</form>

<div class="stats" style="margin-bottom:18px">
    <div class="stat"><span>Total requests</span><b><?= (int)$sum['total'] ?></b><small><?= $from || $to ? 'In selected range' : 'All time' ?></small></div>
    <div class="stat"><span>Approved / used</span><b><?= (int)$sum['ok'] ?></b></div>
    <div class="stat"><span>Pending</span><b><?= (int)$sum['pend'] ?></b></div>
    <div class="stat"><span>Cancelled / declined</span><b><?= (int)$sum['bad'] ?></b></div>
</div>

<h2 style="margin:0 0 12px"><?= e($types[$type]) ?><?= ($from || $to) ? ' <span class="muted" style="font-weight:400;font-size:13px">' . e(($from ?: 'start') . ' to ' . ($to ?: 'today')) . '</span>' : '' ?></h2>

<div class="table-wrap">
<?php if ($type === 'usage'): ?>
    <table>
        <thead><tr><th>Facility</th><th>Bookings</th><th>Hours used</th><th>Completed</th><th>Last used</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td class="empty" colspan="5">No usage data for this range.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td><b><?= e($r['facility_name']) ?></b><span class="sub"><?= e($r['location']) ?></span></td><td><?= (int)$r['bookings'] ?></td><td><?= e($r['hours']) ?></td><td><?= (int)$r['completed'] ?></td><td><?= e(fmt_date($r['last_used'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <table>
        <thead><tr><th>Reference</th><th>Requester</th><th>Resource</th><th>Date &amp; time</th><th>Purpose</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td class="empty" colspan="6">No records for this report.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['reference_code']) ?></b></td>
                <td><?= requester_cell($r) ?></td>
                <td><?= resource_cell($r) ?></td>
                <td><?= e(fmt_date($r['reservation_date'])) ?><span class="sub"><?= e(fmt_time($r['start_time'])) ?> – <?= e(fmt_time($r['end_time'])) ?></span></td>
                <td><?= e($r['purpose']) ?></td>
                <td><?= status_pill($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
<?php if ($type !== 'usage'): ?>
    <?= render_pager($p) ?>
    <?php if ($total > PER_PAGE && !$showAll): ?><p class="no-print"><a class="muted" href="?<?= e($qs) ?>&all=1">Show everything on one page (for printing)</a></p><?php endif; ?>
<?php endif; ?>
<?php page_footer();
