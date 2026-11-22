<?php
// JSON endpoint used by reserve.php for the live conflict warning
require_once "auth.php";
require_login();
require_once "db.php";
require_once "functions.php";
header('Content-Type: application/json');

$fid = (int)($_GET['facility'] ?? 0);
$eqs = parse_equipment_param($_GET['eq'] ?? '');
$date = $_GET['date'] ?? '';
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$exclude = (int)($_GET['exclude'] ?? 0); // reservation being edited

$out = ['status' => 'idle', 'items' => [], 'slots' => []];

if (!$fid && !$eqs) { echo json_encode($out); exit; }

// Booked slots for the chosen day (timeline)
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $pairs = [['facility', $fid]];
    foreach ($eqs as $id => $q) $pairs[] = ['equipment', $id];
    $names = [];
    foreach ($conn->query("SELECT id, equipment_name FROM equipment") as $r) $names[(int)$r['id']] = $r['equipment_name'];
    foreach ($pairs as $pair) {
        if (!$pair[1]) continue;
        foreach (booked_slots($conn, $pair[0], $pair[1], $date, $exclude) as $s) {
            $label = fmt_time($s['start_time']) . ' – ' . fmt_time($s['end_time']);
            if ($pair[0] === 'equipment') $label = ($names[$pair[1]] ?? 'Equipment') . ': ' . $label . ' (' . (int)$s['equipment_quantity'] . ' unit' . ((int)$s['equipment_quantity'] === 1 ? '' : 's') . ')';
            $out['slots'][] = ['type' => $pair[0], 'label' => $label, 'status' => $s['status']];
        }
    }
}

// Full check once date + times are filled in
if ($date && $start && $end) {
    $problem = validate_slot($date, $start, $end);
    if ($problem) {
        $out['status'] = 'invalid';
        $out['items'][] = ['level' => 'booked', 'msg' => $problem];
    } else {
        $r = check_request($conn, $fid, $eqs, $date, $start, $end, (int)$_SESSION['user_id'], $exclude);
        $out['items'] = $r['items'];
        $out['status'] = 'ok';
        if ($r['blocked']) {
            $out['status'] = 'pending';
            foreach ($r['items'] as $i) if ($i['level'] === 'booked') $out['status'] = 'booked';
        }
    }
}
echo json_encode($out);
