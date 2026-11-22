<?php
require_once __DIR__ . '/config.php';

/* ---------- Basics ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_time($t) { return date('g:i A', strtotime($t)); }
function fmt_date($d) { return date('M j, Y', strtotime($d)); }

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            http_response_code(419);
            die("Your session expired. Please go back, refresh the page and try again.");
        }
    }
}

/* ---------- Flash messages ---------- */
function flash($type, $msg) { $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg]; }
function render_flash() {
    if (empty($_SESSION['flash'])) return '';
    $out = '';
    foreach ($_SESSION['flash'] as $f) {
        $out .= '<div class="alert alert-' . e($f['type']) . '">' . $f['msg'] . '</div>';
    }
    unset($_SESSION['flash']);
    return $out; // callers must escape user data before calling flash()
}

/* ---------- Pagination (Back / 1 2 3 ... / Next) ---------- */
function current_page() { return max(1, (int)($_GET['page'] ?? 1)); }

function paginate($total, $perPage = PER_PAGE) {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(current_page(), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per' => $perPage, 'total' => $total];
}

function page_url($n) {
    $q = $_GET;
    $q['page'] = $n;
    return '?' . http_build_query($q);
}

function render_pager($p) {
    if ($p['total'] == 0) return '';
    $from = $p['offset'] + 1;
    $to = min($p['total'], $p['offset'] + $p['per']);
    $html = '<div class="pager"><div class="pager-info">Showing ' . $from . '–' . $to . ' of ' . $p['total'] . '</div>';
    if ($p['pages'] > 1) {
        $html .= '<nav class="pager-nav" aria-label="Pagination">';
        $html .= $p['page'] > 1
            ? '<a class="pg pg-text" href="' . e(page_url($p['page'] - 1)) . '">Back</a>'
            : '<span class="pg pg-text disabled">Back</span>';
        $show = [];
        for ($i = 1; $i <= $p['pages']; $i++) {
            if ($i == 1 || $i == $p['pages'] || abs($i - $p['page']) <= 1) $show[] = $i;
        }
        $prev = 0;
        foreach ($show as $i) {
            if ($i - $prev > 1) $html .= '<span class="pg dots">…</span>';
            $html .= $i == $p['page']
                ? '<span class="pg active" aria-current="page">' . $i . '</span>'
                : '<a class="pg" href="' . e(page_url($i)) . '">' . $i . '</a>';
            $prev = $i;
        }
        $html .= $p['page'] < $p['pages']
            ? '<a class="pg pg-text" href="' . e(page_url($p['page'] + 1)) . '">Next</a>'
            : '<span class="pg pg-text disabled">Next</span>';
        $html .= '</nav>';
    }
    return $html . '</div>';
}

/* ---------- Status pill ---------- */
// The database keeps the word 'Rejected'; people see 'Declined'.
function status_label($s) {
    return $s === 'Rejected' ? 'Declined' : ucfirst((string)$s);
}

function status_pill($s) {
    $map = [
        'Pending' => 'amber', 'Approved' => 'green', 'In Use' => 'blue', 'Completed' => 'gray',
        'Rejected' => 'red', 'Cancelled' => 'red', 'available' => 'green', 'unavailable' => 'red',
        'active' => 'green', 'inactive' => 'red',
    ];
    $c = $map[$s] ?? 'gray';
    return '<span class="pill pill-' . $c . '">' . e(status_label($s)) . '</span>';
}

/* ---------- Passwords ---------- */
function make_temp_password($len = 8) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function password_problem($pw) {
    if (strlen($pw) < 8) return "Password must be at least 8 characters.";
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return "Use at least one letter and one number.";
    return null;
}

/* ---------- Mail (SMTP if configured, always logged to logs/outbox.log) ---------- */
// Returns ['ok' => bool, 'error' => string]
function send_mail($to, $subject, $body, $html = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] TO: $to\nSUBJECT: $subject\n$body\n" . str_repeat('-', 60) . "\n";
    @file_put_contents(__DIR__ . '/logs/outbox.log', $log, FILE_APPEND);
    if (!$to) return ['ok' => false, 'error' => 'This account has no email address.'];
    $html = $html ?: '<pre style="font-family:inherit">' . e($body) . '</pre>';
    if (SMTP_USER !== '') {
        require_once __DIR__ . '/mailer.php';
        $ok = smtp_send($to, $subject, $body, $html, $err);
        return ['ok' => $ok, 'error' => $err];
    }
    $ok = @mail($to, $subject, $body, "From: " . MAIL_FROM);
    return ['ok' => $ok, 'error' => $ok ? '' : 'No mail server. Fill in SMTP_USER and SMTP_PASS in config.php.'];
}

function base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

/* ---------- Reservation validation + conflict detection ---------- */

// Returns an error message, or null when the date/time itself is acceptable.
function validate_slot($date, $start, $end) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return "Please choose a valid date.";
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) return "Please choose a start and end time.";
    if ($end <= $start) return "End time must be later than start time.";
    // You can start at any time of the day. The only limit: the reservation must finish by closing time.
    if ($end > CLOSE_TIME) {
        return "Reservations must end by " . fmt_time(CLOSE_TIME) . " (closing time).";
    }
    $today = date('Y-m-d');
    if ($date < $today) return "You cannot reserve a date that has already passed.";
    if ($date === $today && $start <= date('H:i')) return "That start time has already passed today.";
    return null;
}

/**
 * Checks every rule for a facility and/or equipment request.
 * Returns ['blocked' => bool, 'items' => [ ['level'=>'booked|pending|info', 'msg'=>...], ... ]]
 *  - booked  : already approved / in use  -> cannot reserve
 *  - pending : someone else asked first and is waiting for approval -> cannot reserve
 */
function find_conflicts($conn, $facility_id, $equipment_id, $qty, $date, $start, $end, $user_id, $exclude_id = 0) {
    $items = [];
    $blocked = false;
    $start = substr($start, 0, 5);
    $end = substr($end, 0, 5);
    $in = "'" . implode("','", CONFLICT_STATUSES) . "'";

    if ($facility_id) {
        $f = $conn->prepare("SELECT facility_name, status FROM facilities WHERE id = ?");
        $f->bind_param("i", $facility_id);
        $f->execute();
        $fac = $f->get_result()->fetch_assoc();
        $f->close();
        if (!$fac) {
            $items[] = ['level' => 'booked', 'msg' => 'That facility does not exist.'];
            $blocked = true;
        } elseif ($fac['status'] !== 'available') {
            $items[] = ['level' => 'booked', 'msg' => $fac['facility_name'] . ' is currently unavailable for reservation.'];
            $blocked = true;
        } else {
            $q = $conn->prepare("SELECT status, start_time, end_time, user_id FROM reservations
                WHERE facility_id = ? AND reservation_date = ? AND status IN ($in)
                AND start_time < ? AND end_time > ? AND id <> ?
                ORDER BY start_time");
            $s = $start . ':00'; $e = $end . ':00';
            $q->bind_param("isssi", $facility_id, $date, $e, $s, $exclude_id);
            $q->execute();
            $res = $q->get_result();
            while ($r = $res->fetch_assoc()) {
                $range = fmt_time($r['start_time']) . ' to ' . fmt_time($r['end_time']);
                if ($r['status'] === 'Pending') {
                    if ((int)$r['user_id'] === (int)$user_id) {
                        $items[] = ['level' => 'pending', 'msg' => "You already have a pending request for {$fac['facility_name']} from $range (duplicate reservation)."];
                    } else {
                        $items[] = ['level' => 'pending', 'msg' => "Another user already requested {$fac['facility_name']} from $range and is waiting for approval. Please pick a different time."];
                    }
                } else {
                    $items[] = ['level' => 'booked', 'msg' => "{$fac['facility_name']} is already reserved from $range."];
                }
                $blocked = true;
            }
            $q->close();
        }
    }

    if ($equipment_id) {
        $f = $conn->prepare("SELECT equipment_name, quantity, status FROM equipment WHERE id = ?");
        $f->bind_param("i", $equipment_id);
        $f->execute();
        $eq = $f->get_result()->fetch_assoc();
        $f->close();
        if (!$eq) {
            $items[] = ['level' => 'booked', 'msg' => 'That equipment does not exist.'];
            $blocked = true;
        } elseif ($eq['status'] !== 'available') {
            $items[] = ['level' => 'booked', 'msg' => $eq['equipment_name'] . ' is currently unavailable for reservation.'];
            $blocked = true;
        } else {
            $s = $start . ':00'; $e = $end . ':00';
            // Quantity check (the same person may reserve again as long as units are free)
            $q = $conn->prepare("SELECT COALESCE(SUM(ri.quantity),0) used FROM reservations r JOIN reservation_items ri ON ri.reservation_id = r.id
                WHERE ri.equipment_id = ? AND r.reservation_date = ? AND r.status IN ($in)
                AND r.start_time < ? AND r.end_time > ? AND r.id <> ?");
            $q->bind_param("isssi", $equipment_id, $date, $e, $s, $exclude_id);
            $q->execute();
            $used = (int)$q->get_result()->fetch_assoc()['used'];
            $q->close();
            $left = (int)$eq['quantity'] - $used;
            $qty = max(1, (int)$qty);
            if ($left <= 0) {
                $items[] = ['level' => 'booked', 'msg' => "All {$eq['quantity']} {$eq['equipment_name']} are already reserved for that time."];
                $blocked = true;
            } elseif ($qty > $left) {
                $items[] = ['level' => 'booked', 'msg' => "Only $left of {$eq['quantity']} {$eq['equipment_name']} are free for that time, but you asked for $qty."];
                $blocked = true;
            }
        }
    }
    return ['blocked' => $blocked, 'items' => $items];
}

// Slots already taken for one resource on one date (for the day timeline)
function booked_slots($conn, $type, $id, $date, $exclude_id = 0) {
    $exclude_id = (int)$exclude_id;
    $in = "'" . implode("','", CONFLICT_STATUSES) . "'";
    if ($type === 'facility') {
        $sql = "SELECT start_time, end_time, status, 0 AS equipment_quantity FROM reservations
            WHERE facility_id = ? AND reservation_date = ? AND status IN ($in) AND id <> $exclude_id ORDER BY start_time";
    } else {
        $sql = "SELECT r.start_time, r.end_time, r.status, ri.quantity AS equipment_quantity FROM reservations r
            JOIN reservation_items ri ON ri.reservation_id = r.id
            WHERE ri.equipment_id = ? AND r.reservation_date = ? AND r.status IN ($in) AND r.id <> $exclude_id ORDER BY r.start_time";
    }
    $q = $conn->prepare($sql);
    $q->bind_param("is", $id, $date);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    return $rows;
}

/* ---------- Editing a reservation ---------- */
// Returns null when the owner may still edit this reservation, otherwise the reason why not.
// The student can edit while it is Pending or Approved and has not started yet.
// If an Approved reservation is edited, it goes back to Pending and the administrator must approve it again.
function edit_block_reason($r) {
    if (!in_array($r['status'], ['Pending', 'Approved'], true)) return 'This reservation can no longer be edited.';
    $startTime = strtotime($r['reservation_date'] . ' ' . $r['start_time']);
    if ($startTime <= time()) return 'This reservation has already started, so it can no longer be edited.';
    return null;
}

/* ---------- Receipts (anti-fake) ---------- */
function make_reference_code($conn) {
    for ($i = 0; $i < 10; $i++) {
        $code = 'SFR-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $q = $conn->prepare("SELECT id FROM reservations WHERE reference_code = ?");
        $q->bind_param("s", $code);
        $q->execute();
        $exists = $q->get_result()->num_rows > 0;
        $q->close();
        if (!$exists) return $code;
    }
    return 'SFR-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// Shows whether a reservation is a valid booking right now (decided by the database record, so it cannot be faked).
function valid_pill($status) {
    if (receipt_valid_status($status)) return '<span class="pill pill-green">Valid</span>';
    if ($status === 'Pending') return '<span class="pill pill-amber">Awaiting approval</span>';
    return '<span class="pill pill-red">Not valid</span>';
}

function receipt_valid_status($status) {
    return in_array($status, ['Approved', 'In Use', 'Completed'], true);
}

/* ---------- Picture uploads ---------- */
function save_upload($field, &$error) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) { $error = "The picture could not be uploaded."; return null; }
    if ($f['size'] > 3 * 1024 * 1024) { $error = "Picture is too large (max 3 MB)."; return null; }
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) { $error = "Only JPG, PNG or WEBP pictures are allowed."; return null; }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/' . $name)) {
        $error = "Could not save the picture. Check that the uploads folder is writable.";
        return null;
    }
    return $name;
}

function delete_upload($name) {
    if ($name && preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', $name)) {
        @unlink(__DIR__ . '/uploads/' . $name);
    }
}

/* ---------- Pictures ---------- */
require_once __DIR__ . '/art.php';

// Shows the uploaded picture if there is one, otherwise a built-in illustration.
function resource_pic($kind, $row, $class = 'pic') {
    $name = $kind === 'facility' ? ($row['facility_name'] ?? '') : ($row['equipment_name'] ?? '');
    if (!empty($row['image']) && is_file(__DIR__ . '/uploads/' . $row['image'])) {
        return '<div class="' . e($class) . '"><img src="uploads/' . e($row['image']) . '" alt="' . e($name) . '"></div>';
    }
    return '<div class="' . e($class) . '">' . art_svg($kind, $name) . '</div>';
}


/* ---------- Notifications (bell in the top bar) ---------- */
function notify($conn, $user_id, $title, $message, $link = '') {
    if (!$user_id) return;
    $q = $conn->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)");
    $q->bind_param("isss", $user_id, $title, $message, $link);
    $q->execute();
    $q->close();
}
function notify_admins($conn, $title, $message, $link = '') {
    foreach ($conn->query("SELECT id FROM users WHERE role IN ('admin','assistant_admin') AND status='active'")->fetch_all(MYSQLI_ASSOC) as $a) {
        notify($conn, (int)$a['id'], $title, $message, $link);
    }
}
function unread_notifications($conn, $user_id) {
    $q = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $q->bind_param("i", $user_id);
    $q->execute();
    $n = (int)$q->get_result()->fetch_row()[0];
    $q->close();
    return $n;
}
function time_ago($ts) {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    return fmt_date($ts);
}


// Checks a facility plus several equipment items at once. $eqs = [equipment_id => quantity]
// Returns ['blocked' => bool, 'items' => [messages...]]
function check_request($conn, $fid, $eqs, $date, $start, $end, $uid, $exclude_id = 0) {
    $items = [];
    $blocked = false;
    if ($fid) {
        $r = find_conflicts($conn, $fid, null, 0, $date, $start, $end, $uid, $exclude_id);
        $items = $r['items']; $blocked = $r['blocked'];
    }
    foreach ($eqs as $eid => $qty) {
        $r = find_conflicts($conn, null, $eid, $qty, $date, $start, $end, $uid, $exclude_id);
        $items = array_merge($items, $r['items']);
        $blocked = $blocked || $r['blocked'];
    }
    return ['blocked' => $blocked, 'items' => $items];
}

// "3:2,5:1" -> [3 => 2, 5 => 1]
function parse_equipment_param($text) {
    $out = [];
    foreach (explode(',', (string)$text) as $part) {
        $bits = explode(':', $part);
        if ((int)$bits[0] > 0) $out[(int)$bits[0]] = max(1, (int)($bits[1] ?? 1));
    }
    return $out;
}


/* ---------- Equipment on a reservation (one reservation can hold many items) ---------- */
// SQL snippet: "2 × Laptop, 1 × Projector" for reservation alias r
define('EQUIP_LIST', "(SELECT GROUP_CONCAT(CONCAT(ri.quantity, ' × ', e2.equipment_name) ORDER BY e2.equipment_name SEPARATOR ', ')
    FROM reservation_items ri JOIN equipment e2 ON e2.id = ri.equipment_id WHERE ri.reservation_id = r.id)");

// Units being used RIGHT NOW (reservations that are In Use). Returns [equipment_id => units in use].
// A booking for tomorrow does not reduce today's stock. When the reservation turns Completed
// (or is Declined / Cancelled) the units come back by themselves.
function equipment_reserved_map($conn) {
    $out = [];
    $res = $conn->query("SELECT ri.equipment_id, SUM(ri.quantity) AS used
        FROM reservation_items ri JOIN reservations r ON r.id = ri.reservation_id
        WHERE r.status = 'In Use'
        AND TIMESTAMP(r.reservation_date, r.start_time) <= NOW() AND TIMESTAMP(r.reservation_date, r.end_time) > NOW()
        GROUP BY ri.equipment_id");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $out[(int)$r['equipment_id']] = (int)$r['used'];
    return $out;
}

// Free units of one equipment row: total minus what is reserved ("available" in the equipment list)
function equipment_left($row, $reserved) {
    if (($row['status'] ?? 'available') !== 'available') return 0;
    return max(0, (int)$row['quantity'] - (int)($reserved[(int)$row['id']] ?? 0));
}

// Approved / In Use reservations that have not ended yet, per equipment: [equipment_id => [rows]]
function equipment_schedule($conn) {
    $out = [];
    $res = $conn->query("SELECT ri.equipment_id, ri.quantity, r.reservation_date, r.start_time, r.end_time, r.status
        FROM reservation_items ri JOIN reservations r ON r.id = ri.reservation_id
        WHERE r.status IN ('Approved','In Use') AND TIMESTAMP(r.reservation_date, r.end_time) > NOW()
        ORDER BY r.reservation_date, r.start_time");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $out[(int)$r['equipment_id']][] = $r;
    return $out;
}

// 'unavailable' when switched off, or when every unit is in use right now
function equipment_status($row, $reserved, $sched = []) {
    if (($row['status'] ?? 'available') !== 'available') return 'unavailable';
    return equipment_left($row, $reserved) > 0 ? 'available' : 'unavailable';
}

// Approved / In Use reservations of facilities that are not over yet: [facility_id => [rows]]
function facility_schedule($conn) {
    $out = [];
    $res = $conn->query("SELECT facility_id, reservation_date, start_time, end_time, status FROM reservations
        WHERE facility_id IS NOT NULL AND status IN ('Approved','In Use') AND TIMESTAMP(reservation_date, end_time) > NOW()
        ORDER BY reservation_date, start_time");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $out[(int)$r['facility_id']][] = $r;
    return $out;
}

// If the facility is in use right now: the time it becomes free (back-to-back bookings are joined). Otherwise null.
function facility_busy_until($row, $sched = []) {
    $until = null;
    foreach ($sched[(int)$row['id']] ?? [] as $x) {
        $startAt = strtotime($x['reservation_date'] . ' ' . $x['start_time']);
        $endAt = strtotime($x['reservation_date'] . ' ' . $x['end_time']);
        if ($until === null) {
            if ($x['status'] === 'In Use') $until = $endAt;
        } elseif ($startAt <= $until && $endAt > $until) {
            $until = $endAt;
        }
    }
    return $until;
}

// "3:00 PM" for today, "Nov 19, 9:00 AM" for another day
function until_text($timestamp) {
    if (date('Y-m-d', $timestamp) === date('Y-m-d')) return date('g:i A', $timestamp);
    return date('M j, g:i A', $timestamp);
}

// 'unavailable' when the admin switched it off or someone is using it right now, otherwise 'available'.
// Future bookings do not make it unavailable today (they are listed under it instead).
function facility_status($row, $sched = []) {
    if (($row['status'] ?? 'available') !== 'available') return 'unavailable';
    return facility_busy_until($row, $sched) === null ? 'available' : 'unavailable';
}

// "Unavailable until 3:00 PM" line for a facility that is in use right now
function facility_until_html($row, $sched) {
    $until = facility_busy_until($row, $sched);
    if (($row['status'] ?? 'available') !== 'available' || $until === null) return '';
    return '<div class="when-list"><small><b>Unavailable until ' . e(until_text($until)) . '</b></small></div>';
}

// "Unavailable until 3:00 PM" for equipment whose units are all in use right now (the first time a unit comes back)
function equipment_until_html($row, $reserved, $sched) {
    if (($row['status'] ?? 'available') !== 'available' || equipment_left($row, $reserved) > 0) return '';
    $first = null;
    foreach ($sched[(int)$row['id']] ?? [] as $x) {
        if ($x['status'] !== 'In Use') continue;
        $endAt = strtotime($x['reservation_date'] . ' ' . $x['end_time']);
        if ($first === null || $endAt < $first) $first = $endAt;
    }
    if ($first === null) return '';
    return '<div class="when-list"><small><b>Unavailable until ' . e(until_text($first)) . '</b></small></div>';
}

function facility_when($row, $sched, $max = 3) {
    $list = [];
    foreach ($sched[(int)$row['id']] ?? [] as $x) if ($x['status'] !== 'In Use') $list[] = $x;
    if (!$list) return '';
    $html = '<div class="when-list">';
    foreach (array_slice($list, 0, $max) as $x) {
        $html .= '<small>Reserved ' . e(fmt_date($x['reservation_date'])) . ' · ' . e(fmt_time($x['start_time'])) . ' – ' . e(fmt_time($x['end_time'])) . '</small>';
    }
    if (count($list) > $max) $html .= '<small>+ ' . (count($list) - $max) . ' more</small>';
    return $html . '</div>';
}

// "Reserved: Oct 22, 2026 · 9:00 AM – 11:00 AM (2 units)" lines for the dates and times it is taken
function equipment_when($row, $sched, $max = 3) {
    $list = [];
    foreach ($sched[(int)$row['id']] ?? [] as $x) if ($x['status'] !== 'In Use') $list[] = $x;
    if (!$list) return '';
    $html = '<div class="when-list">';
    foreach (array_slice($list, 0, $max) as $s) {
        $html .= '<small>Reserved ' . e(fmt_date($s['reservation_date'])) . ' · ' . e(fmt_time($s['start_time'])) . ' – ' . e(fmt_time($s['end_time'])) . ' (' . (int)$s['quantity'] . ' unit' . ((int)$s['quantity'] === 1 ? '' : 's') . ')</small>';
    }
    if (count($list) > $max) $html .= '<small>+ ' . (count($list) - $max) . ' more</small>';
    return $html . '</div>';
}

// "3 of 5 available" text for cards
function equipment_stock_text($row, $reserved) {
    $left = equipment_left($row, $reserved);
    return $left . ' of ' . (int)$row['quantity'] . ' available now';
}

// [equipment_id => quantity] for one reservation
function reservation_items($conn, $rid) {
    $q = $conn->prepare("SELECT equipment_id, quantity FROM reservation_items WHERE reservation_id = ?");
    $q->bind_param("i", $rid);
    $q->execute();
    $out = [];
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[(int)$r['equipment_id']] = (int)$r['quantity'];
    $q->close();
    return $out;
}

// Table cell content: facility on top, equipment underneath (or equipment alone)
function resource_cell($r) {
    $list = $r['equip_list'] ?? '';
    if (!empty($r['facility_name'])) {
        return e($r['facility_name']) . ($list !== '' ? '<span class="sub">+ ' . e($list) . '</span>' : '');
    }
    return e($list);
}

/* ---------- Who reserved it ---------- */
// Student: full name + course, year, block.   Faculty: full name + "Faculty".

// Gets a text box value from the form. Returns NULL when the box is empty.
function clean_text($field, $max_length) {
    $text = trim($_POST[$field] ?? '');
    $text = mb_substr($text, 0, $max_length);
    if ($text == '') {
        return null;
    }
    return $text;
}

// 3 becomes "3rd Year". Anything that is not a number is shown as typed.
function year_label($year) {
    $year = trim((string)$year);
    if ($year == '') {
        return '';
    }
    if (!ctype_digit($year)) {
        return $year;
    }
    if ($year == '1') {
        return '1st Year';
    }
    if ($year == '2') {
        return '2nd Year';
    }
    if ($year == '3') {
        return '3rd Year';
    }
    return $year . 'th Year';
}

// "A" becomes "Block A".
function block_label($block) {
    $block = trim((string)$block);
    if ($block == '') {
        return '';
    }
    if (stripos($block, 'block') === 0) {
        return $block;
    }
    return 'Block ' . $block;
}

// Returns "BSIT · 3rd Year · Block A" for a student, "Faculty" for faculty.
function requester_detail($row) {
    $role = $row['role'] ?? '';

    if ($role == 'faculty') {
        return 'Faculty';
    }

    if ($role == 'student') {
        $text = '';
        $course = trim((string)($row['course'] ?? ''));
        $year = year_label($row['year_level'] ?? '');
        $block = block_label($row['block'] ?? '');

        if ($course != '') {
            $text = $course;
        }
        if ($year != '') {
            if ($text != '') { $text = $text . ' · '; }
            $text = $text . $year;
        }
        if ($block != '') {
            if ($text != '') { $text = $text . ' · '; }
            $text = $text . $block;
        }
        if ($text == '') {
            $text = 'Student';
        }
        return $text;
    }

    return ucfirst($role);
}

// Table cell: full name on top, details below. Pass true to also show the reference code.
function requester_cell($row, $show_reference = false) {
    $html = '<b>' . e($row['full_name']) . '</b>';

    $detail = requester_detail($row);
    if ($detail != '') {
        $html = $html . '<span class="sub">' . e($detail) . '</span>';
    }

    if ($show_reference == true && !empty($row['reference_code'])) {
        $html = $html . '<span class="sub">' . e($row['reference_code']) . '</span>';
    }
    return $html;
}

// Plain text version, e.g. "Juan Dela Cruz (BSIT · 3rd Year · Block A)"
function requester_text($row) {
    $detail = requester_detail($row);
    if ($detail == '') {
        return $row['full_name'];
    }
    return $row['full_name'] . ' (' . $detail . ')';
}

// Looks up a user by id and returns their name with details (used in notifications).
function user_label($conn, $user_id) {
    $q = $conn->prepare("SELECT full_name, role, course, year_level, block FROM users WHERE id = ?");
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if ($row) {
        return requester_text($row);
    }
    return 'A user';
}
