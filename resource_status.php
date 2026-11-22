<?php
// JSON endpoint: availability of every facility / equipment for a date (and time range)
require_once "auth.php";
require_login();
require_once "db.php";
require_once "functions.php";
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$start = substr($_GET['start'] ?? '', 0, 5);
$end = substr($_GET['end'] ?? '', 0, 5);
$exclude = (int)($_GET['exclude'] ?? 0); // reservation being edited
$out = ['timed' => false, 'facilities' => new stdClass(), 'equipment' => new stdClass()];

$validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
$timed = $validDate && preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end) && $start < $end;
$in = "'" . implode("','", ACTIVE_STATUSES) . "'";
$fac = []; $eq = [];

if ($timed) {
    $out['timed'] = true;
    $s = $start . ':00'; $e = $end . ':00';
    $q = $conn->prepare("SELECT facility_id, MAX(status = 'Approved' OR status = 'In Use') AS hard FROM reservations
        WHERE facility_id IS NOT NULL AND reservation_date = ? AND status IN ($in) AND start_time < ? AND end_time > ? AND id <> ? GROUP BY facility_id");
    $q->bind_param("sssi", $date, $e, $s, $exclude);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $fac[(int)$r['facility_id']] = $r['hard'] ? 'booked' : 'pending';
    $q->close();

    $q = $conn->prepare("SELECT ri.equipment_id, SUM(ri.quantity) AS used FROM reservations r JOIN reservation_items ri ON ri.reservation_id = r.id
        WHERE r.reservation_date = ? AND r.status IN ($in) AND r.start_time < ? AND r.end_time > ? AND r.id <> ? GROUP BY ri.equipment_id");
    $q->bind_param("sssi", $date, $e, $s, $exclude);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $eq[(int)$r['equipment_id']] = (int)$r['used'];
    $q->close();
}

$overall = equipment_reserved_map($conn);
$f = []; foreach ($conn->query("SELECT id FROM facilities WHERE status='available'") as $r) $f[(int)$r['id']] = $fac[(int)$r['id']] ?? 'free';
$e = []; foreach ($conn->query("SELECT id, quantity FROM equipment WHERE status='available'") as $r) {
    $total = (int)$r['quantity'];
    // with a time chosen: free units for that time; otherwise: units not yet reserved at all
    $used = $timed ? ($eq[(int)$r['id']] ?? 0) : ($overall[(int)$r['id']] ?? 0);
    $e[(int)$r['id']] = ['total' => $total, 'left' => max(0, $total - $used)];
}
$out['facilities'] = $f ?: new stdClass();
$out['equipment'] = $e ?: new stdClass();
echo json_encode($out);
