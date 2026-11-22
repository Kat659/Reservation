<?php
require_once "auth.php";
require_admin();
require_once "db.php";
require_once "layout.php";

$tabs = ['reservations' => 'Reservations'];
if (can_manage()) $tabs += ['facilities' => 'Facilities', 'equipment' => 'Equipment'];   // Assistant Admin: reservations only
$tab = $_GET['tab'] ?? 'reservations';
if (!isset($tabs[$tab])) $tab = 'reservations';
$adminId = (int)$_SESSION['user_id'];

function go($tab) { header("Location: admin.php?tab=" . $tab); exit(); }

/* ================= POST actions ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // --- Reservation status change ---
    // The administrator decides: Approved, Declined (Rejected), Cancelled or back to Pending.
    // In Use and Completed are NOT set by hand: the system sets them when the time comes (see db.php).
    if (isset($_POST['set_status'], $_POST['res_id'])) {
        $rid = (int)$_POST['res_id'];
        $new = $_POST['new_status'] ?? '';
        $canSet = ['Pending', 'Approved', 'Rejected', 'Cancelled'];   // what the admin may choose
        if (in_array($new, $canSet, true)) {
            $g = $conn->prepare("SELECT * FROM reservations WHERE id = ?");
            $g->bind_param("i", $rid);
            $g->execute();
            $r = $g->get_result()->fetch_assoc();
            $g->close();
            if ($r) {
                $endsAt = strtotime($r['reservation_date'] . ' ' . $r['end_time']);
                if (!in_array($r['status'], $canSet, true)) {
                    flash('error', 'A reservation that is ' . e(status_label($r['status'])) . ' cannot be changed. In Use and Completed happen automatically.');
                } elseif ($new === $r['status']) {
                    flash('info', 'Nothing changed. It is already ' . e(status_label($new)) . '.');
                } elseif (!in_array($new, ['Rejected', 'Cancelled'], true) && $endsAt <= time()) {
                    flash('error', 'The time of this reservation has already passed, so it cannot be ' . e(status_label($new)) . ' any more.');
                } else {
                    $ok = true;
                    if (!in_array($new, ['Rejected', 'Cancelled'], true)) {
                        // approving (or reopening) must not clash with another reservation or run out of equipment
                        $c = check_request($conn, $r['facility_id'], reservation_items($conn, $rid),
                            $r['reservation_date'], $r['start_time'], $r['end_time'], $r['user_id'], $rid);
                        if ($c['blocked']) {
                            $ok = false;
                            flash('error', 'Cannot set to ' . e(status_label($new)) . ': ' . e($c['items'][0]['msg'] ?? 'conflict found.'));
                        }
                    }
                    if ($ok) {
                        $u = $conn->prepare("UPDATE reservations SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                        $u->bind_param("sii", $new, $adminId, $rid);
                        $u->execute();
                        $u->close();
                        flash('success', 'Reservation ' . e($r['reference_code']) . ' is now ' . e(status_label($new)) . '.');
                        $words = ['Approved' => 'approved', 'Rejected' => 'declined', 'Cancelled' => 'cancelled', 'Pending' => 'set back to pending'];
                        $msg = 'Your reservation ' . $r['reference_code'] . ' for ' . fmt_date($r['reservation_date']) . ', ' . fmt_time($r['start_time']) . ' – ' . fmt_time($r['end_time']) . ' was ' . $words[$new] . ' by the administrator.';
                        if ($new === 'Approved') $msg .= ' It will start automatically at the chosen time.';
                        notify($conn, (int)$r['user_id'], 'Reservation ' . $words[$new], $msg, 'reservations.php');
                    }
                }
            }
        }
        $back = $_POST['back'] ?? '';
        header("Location: " . (strpos($back, 'admin.php') === 0 ? $back : 'admin.php')); exit();
    }

    // Everything below changes facilities / equipment: Admin only
    if (!can_manage()) { http_response_code(403); die("Access denied."); }

    // --- Add facility ---
    if (isset($_POST['add_facility'])) {
        $name = trim($_POST['facility_name'] ?? '');
        $loc = trim($_POST['location'] ?? '');
        $cap = max(0, (int)($_POST['capacity'] ?? 0));
        $desc = trim($_POST['description'] ?? '');
        $err = '';
        $img = save_upload('image', $err);
        if ($name === '') flash('error', 'Facility name is required.');
        elseif ($err) flash('error', e($err));
        else {
            $q = $conn->prepare("INSERT INTO facilities (facility_name, location, capacity, description, image) VALUES (?,?,?,?,?)");
            $q->bind_param("ssiss", $name, $loc, $cap, $desc, $img);
            $q->execute();
            $q->close();
            flash('success', 'Facility added.');
        }
        go('facilities');
    }

    // --- Edit facility ---
    if (isset($_POST['edit_facility'], $_POST['facility_id'])) {
        $fid = (int)$_POST['facility_id'];
        $name = trim($_POST['facility_name'] ?? '');
        $loc = trim($_POST['location'] ?? '');
        $cap = max(0, (int)($_POST['capacity'] ?? 0));
        $desc = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['available', 'unavailable'], true) ? $_POST['status'] : 'available';
        $old = $conn->query("SELECT image FROM facilities WHERE id = $fid")->fetch_assoc();
        $err = '';
        $img = save_upload('image', $err);
        if ($name === '') flash('error', 'Facility name is required.');
        elseif ($err) flash('error', e($err));
        else {
            $keep = $old['image'] ?? null;
            if ($img) { delete_upload($keep); $keep = $img; }
            elseif (!empty($_POST['remove_image'])) { delete_upload($keep); $keep = null; }
            $q = $conn->prepare("UPDATE facilities SET facility_name=?, location=?, capacity=?, description=?, status=?, image=? WHERE id=?");
            $q->bind_param("ssisssi", $name, $loc, $cap, $desc, $status, $keep, $fid);
            $q->execute();
            $q->close();
            flash('success', 'Facility updated.');
        }
        go('facilities');
    }

    // --- Delete facility (only if it was never reserved) ---
    if (isset($_POST['delete_facility'], $_POST['facility_id'])) {
        $fid = (int)$_POST['facility_id'];
        $n = (int)$conn->query("SELECT COUNT(*) FROM reservations WHERE facility_id = $fid")->fetch_row()[0];
        if ($n > 0) {
            flash('error', 'This facility has reservation history, so it cannot be deleted. Set it to Unavailable instead.');
        } else {
            $old = $conn->query("SELECT image FROM facilities WHERE id = $fid")->fetch_assoc();
            delete_upload($old['image'] ?? null);
            $q = $conn->prepare("DELETE FROM facilities WHERE id = ?");
            $q->bind_param("i", $fid);
            $q->execute();
            $q->close();
            flash('success', 'Facility deleted.');
        }
        go('facilities');
    }

    // --- Add equipment ---
    if (isset($_POST['add_equipment'])) {
        $name = trim($_POST['equipment_name'] ?? '');
        $cat = trim($_POST['category'] ?? '');
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $cond = trim($_POST['condition_status'] ?? 'Good') ?: 'Good';
        $err = '';
        $img = save_upload('image', $err);
        if ($name === '') flash('error', 'Equipment name is required.');
        elseif ($err) flash('error', e($err));
        else {
            $q = $conn->prepare("INSERT INTO equipment (equipment_name, category, quantity, condition_status, image) VALUES (?,?,?,?,?)");
            $q->bind_param("ssiss", $name, $cat, $qty, $cond, $img);
            $q->execute();
            $q->close();
            flash('success', 'Equipment added.');
        }
        go('equipment');
    }

    // --- Edit equipment ---
    if (isset($_POST['edit_equipment'], $_POST['equipment_id'])) {
        $eid = (int)$_POST['equipment_id'];
        $name = trim($_POST['equipment_name'] ?? '');
        $cat = trim($_POST['category'] ?? '');
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $cond = trim($_POST['condition_status'] ?? 'Good') ?: 'Good';
        $status = in_array($_POST['status'] ?? '', ['available', 'unavailable'], true) ? $_POST['status'] : 'available';
        $old = $conn->query("SELECT image FROM equipment WHERE id = $eid")->fetch_assoc();
        $err = '';
        $img = save_upload('image', $err);
        if ($name === '') flash('error', 'Equipment name is required.');
        elseif ($err) flash('error', e($err));
        else {
            $keep = $old['image'] ?? null;
            if ($img) { delete_upload($keep); $keep = $img; }
            elseif (!empty($_POST['remove_image'])) { delete_upload($keep); $keep = null; }
            $q = $conn->prepare("UPDATE equipment SET equipment_name=?, category=?, quantity=?, condition_status=?, status=?, image=? WHERE id=?");
            $q->bind_param("ssisssi", $name, $cat, $qty, $cond, $status, $keep, $eid);
            $q->execute();
            $q->close();
            flash('success', 'Equipment updated.');
        }
        go('equipment');
    }

    // --- Delete equipment (only if never reserved) ---
    if (isset($_POST['delete_equipment'], $_POST['equipment_id'])) {
        $eid = (int)$_POST['equipment_id'];
        $n = (int)$conn->query("SELECT COUNT(*) FROM reservation_items WHERE equipment_id = $eid")->fetch_row()[0];
        if ($n > 0) {
            flash('error', 'This equipment has reservation history, so it cannot be deleted. Set it to Unavailable instead.');
        } else {
            $old = $conn->query("SELECT image FROM equipment WHERE id = $eid")->fetch_assoc();
            delete_upload($old['image'] ?? null);
            $q = $conn->prepare("DELETE FROM equipment WHERE id = ?");
            $q->bind_param("i", $eid);
            $q->execute();
            $q->close();
            flash('success', 'Equipment deleted.');
        }
        go('equipment');
    }
}

/* ================= Page ================= */
$pendingCount = (int)$conn->query("SELECT COUNT(*) FROM reservations WHERE status='Pending'")->fetch_row()[0];
page_header(['facilities' => 'Manage facilities', 'equipment' => 'Manage equipment'][$tab] ?? 'Reservations', ['facilities' => 'manage_fac', 'equipment' => 'manage_eq'][$tab] ?? 'admin');

echo '<div class="tabs">';
foreach ($tabs as $k => $label) {
    echo '<a class="' . ($tab === $k ? 'on' : '') . '" href="admin.php?tab=' . $k . '">' . e($label) . ($k === 'reservations' && $pendingCount ? ' (' . $pendingCount . ' pending)' : '') . '</a>';
}
echo '</div>';

/* ---------------- Reservations tab ---------------- */
if ($tab === 'reservations'):
    $statuses = ['Pending', 'Approved', 'In Use', 'Completed', 'Rejected', 'Cancelled'];
    $fs = $_GET['status'] ?? 'Pending';
    $search = trim($_GET['q'] ?? '');
    $where = [];
    $types = '';
    $params = [];
    if (in_array($fs, $statuses, true)) { $where[] = "r.status = ?"; $types .= 's'; $params[] = $fs; }
    if ($search !== '') {
        $where[] = "(r.reference_code LIKE ? OR r.purpose LIKE ? OR f.facility_name LIKE ? OR " . EQUIP_LIST . " LIKE ? OR u.full_name LIKE ?)";
        $types .= 'sssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $from = "FROM reservations r JOIN users u ON u.id = r.user_id
             LEFT JOIN facilities f ON f.id = r.facility_id $w";
    $c = $conn->prepare("SELECT COUNT(*) $from");
    if ($types) $c->bind_param($types, ...$params);
    $c->execute();
    $total = (int)$c->get_result()->fetch_row()[0];
    $c->close();
    $p = paginate($total);
    $q = $conn->prepare("SELECT r.*, u.full_name, u.role, u.course, u.year_level, u.block, f.facility_name, " . EQUIP_LIST . " AS equip_list $from
        ORDER BY (r.status = 'Pending') DESC, r.reservation_date ASC, r.start_time ASC LIMIT ? OFFSET ?");
    $p2 = array_merge($params, [$p['per'], $p['offset']]);
    $q->bind_param($types . 'ii', ...$p2);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    $back = 'admin.php?' . http_build_query($_GET);
?>
    <form class="toolbar" method="GET">
        <input type="hidden" name="tab" value="reservations">
        <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Search reference, requester, resource, purpose">
        <select name="status">
            <option value="all" <?= $fs === 'all' ? 'selected' : '' ?>>All statuses</option>
            <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $fs === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-light" type="submit">Filter</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Requester</th><th>Resource</th><th>Date &amp; time</th><th>Purpose</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td class="empty" colspan="6"><?= $fs === 'Pending' ? 'No pending requests. You are all caught up.' : 'No reservations found.' ?></td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= requester_cell($r, true) ?></td>
                    <td><?= resource_cell($r) ?></td>
                    <td><?= e(fmt_date($r['reservation_date'])) ?><span class="sub"><?= e(fmt_time($r['start_time'])) ?> – <?= e(fmt_time($r['end_time'])) ?></span></td>
                    <td><?= e($r['purpose']) ?></td>
                    <td><?= status_pill($r['status']) ?><?php if (!empty($r['edited_at'])): ?><span class="sub">Edited by requester <?= e(time_ago($r['edited_at'])) ?></span><?php endif; ?></td>
                    <td>
                        <div class="cell-actions">
                        <?php
                        // Admin decides Approved / Declined / Cancelled. Only In Use and Completed are automatic.
                        $canEdit = in_array($r['status'], ['Pending', 'Approved', 'Rejected', 'Cancelled'], true);
                        $next = [
                            'Pending' => [['Approved', 'Approve', ''], ['Rejected', 'Decline', 'btn-danger']],
                            'Approved' => [['Rejected', 'Decline', 'btn-danger'], ['Cancelled', 'Cancel', 'btn-danger']],
                            'Rejected' => [['Approved', 'Approve', '']],
                            'Cancelled' => [['Approved', 'Approve', '']],
                        ][$r['status']] ?? [];
                        foreach ($next as $n): ?>
                            <form method="POST"><?= csrf_field() ?>
                                <input type="hidden" name="set_status" value="1">
                                <input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= e($n[0]) ?>">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <button class="btn btn-sm <?= $n[2] ?: '' ?>" type="submit"><?= e($n[1]) ?></button>
                            </form>
                        <?php endforeach; ?>
                        <?php if ($canEdit): ?>
                        <details class="manage"><summary class="btn btn-light btn-sm">Edit</summary>
                            <form method="POST" class="inline-form"><?= csrf_field() ?>
                                <input type="hidden" name="set_status" value="1">
                                <input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="back" value="<?= e($back) ?>">
                                <select name="new_status">
                                    <?php foreach (['Pending', 'Approved', 'Rejected', 'Cancelled'] as $o): ?>
                                        <option value="<?= e($o) ?>" <?= $r['status'] === $o ? 'selected' : '' ?>><?= e(status_label($o)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm" type="submit">Save</button>
                            </form>
                        </details>
                        <?php elseif (in_array($r['status'], ['In Use', 'Completed'], true)): ?>
                            <span class="sub">Automatic (by time)</span>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pager($p) ?>

<?php
/* ---------------- Facilities tab ---------------- */
elseif ($tab === 'facilities'):
    $total = (int)$conn->query("SELECT COUNT(*) FROM facilities")->fetch_row()[0];
    $p = paginate($total, 6);
    $q = $conn->prepare("SELECT * FROM facilities ORDER BY facility_name LIMIT ? OFFSET ?");
    $q->bind_param("ii", $p['per'], $p['offset']);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
?>
    <div class="card" style="margin-top:0">
        <details class="manage">
            <summary class="btn btn-sm"><?= icon('plus', 15) ?> Add facility</summary>
            <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
                <input type="hidden" name="add_facility" value="1">
                <div class="row">
                    <div class="field"><label>Facility name</label><input name="facility_name" required></div>
                    <div class="field"><label>Location</label><input name="location"></div>
                </div>
                <div class="row">
                    <div class="field"><label>Capacity</label><input type="number" name="capacity" min="0" value="0"></div>
                    <div class="field"><label>Picture <small>(JPG/PNG/WEBP, max 3 MB)</small></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                </div>
                <div class="field"><label>Description</label><input name="description"></div>
                <button class="btn" type="submit">Save facility</button>
            </form>
        </details>
    </div>

    <div class="table-wrap" style="margin-top:18px">
        <table>
            <thead><tr><th>Facility</th><th>Capacity</th><th>Status</th><th>Manage</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td class="empty" colspan="4">No facilities yet.</td></tr><?php endif; ?>
            <?php $fsched = facility_schedule($conn); foreach ($rows as $f): ?>
                <tr>
                    <td><div class="who"><?= resource_pic('facility', $f, 'thumb') ?><div><b><?= e($f['facility_name']) ?></b><span class="sub"><?= e($f['location']) ?></span></div></div></td>
                    <td><?= (int)$f['capacity'] ?></td>
                    <td><?= status_pill(facility_status($f, $fsched)) ?></td>
                    <td style="min-width:280px">
                        <details class="manage"><summary class="btn btn-light btn-sm">Edit / delete</summary>
                            <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
                                <input type="hidden" name="edit_facility" value="1">
                                <input type="hidden" name="facility_id" value="<?= (int)$f['id'] ?>">
                                <div class="field"><label>Name</label><input name="facility_name" value="<?= e($f['facility_name']) ?>" required></div>
                                <div class="field"><label>Location</label><input name="location" value="<?= e($f['location']) ?>"></div>
                                <div class="row">
                                    <div class="field"><label>Capacity</label><input type="number" name="capacity" min="0" value="<?= (int)$f['capacity'] ?>"></div>
                                    <div class="field"><label>Status</label>
                                        <select name="status"><option value="available" <?= $f['status'] === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= $f['status'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></div>
                                </div>
                                <div class="field"><label>Description</label><input name="description" value="<?= e($f['description']) ?>"></div>
                                <div class="field"><label>New picture</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                                    <?php if ($f['image']): ?><small><label style="display:inline;font-weight:400"><input type="checkbox" name="remove_image" value="1" style="width:auto"> Remove current picture</label></small><?php endif; ?></div>
                                <button class="btn btn-sm" type="submit">Save changes</button>
                            </form>
                            <form method="POST" data-confirm="Delete this facility? This cannot be undone."><?= csrf_field() ?>
                                <input type="hidden" name="delete_facility" value="1">
                                <input type="hidden" name="facility_id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Delete facility</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pager($p) ?>

<?php
/* ---------------- Equipment tab ---------------- */
else:
    $total = (int)$conn->query("SELECT COUNT(*) FROM equipment")->fetch_row()[0];
    $p = paginate($total, 6);
    $q = $conn->prepare("SELECT * FROM equipment ORDER BY equipment_name LIMIT ? OFFSET ?");
    $q->bind_param("ii", $p['per'], $p['offset']);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    $reserved = equipment_reserved_map($conn);
    $sched = equipment_schedule($conn);
?>
    <div class="card" style="margin-top:0">
        <details class="manage">
            <summary class="btn btn-sm"><?= icon('plus', 15) ?> Add equipment</summary>
            <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
                <input type="hidden" name="add_equipment" value="1">
                <div class="row">
                    <div class="field"><label>Equipment name</label><input name="equipment_name" required></div>
                    <div class="field"><label>Category</label><input name="category"></div>
                </div>
                <div class="row3">
                    <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" value="1"></div>
                    <div class="field"><label>Condition</label><input name="condition_status" value="Good"></div>
                    <div class="field"><label>Picture</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                </div>
                <button class="btn" type="submit">Save equipment</button>
            </form>
        </details>
    </div>

    <div class="table-wrap" style="margin-top:18px">
        <table>
            <thead><tr><th>Equipment</th><th>Available / Total</th><th>Condition</th><th>Status</th><th>Manage</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td class="empty" colspan="5">No equipment yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $it): ?>
                <tr>
                    <td><div class="who"><?= resource_pic('equipment', $it, 'thumb') ?><div><b><?= e($it['equipment_name']) ?></b><span class="sub"><?= e($it['category']) ?></span></div></div></td>
                    <td><b><?= equipment_left($it, $reserved) ?></b> / <?= (int)$it['quantity'] ?></td>
                    <td><?= e($it['condition_status']) ?></td>
                    <td><?= status_pill(equipment_status($it, $reserved, $sched)) ?></td>
                    <td style="min-width:280px">
                        <details class="manage"><summary class="btn btn-light btn-sm">Edit / delete</summary>
                            <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
                                <input type="hidden" name="edit_equipment" value="1">
                                <input type="hidden" name="equipment_id" value="<?= (int)$it['id'] ?>">
                                <div class="field"><label>Name</label><input name="equipment_name" value="<?= e($it['equipment_name']) ?>" required></div>
                                <div class="field"><label>Category</label><input name="category" value="<?= e($it['category']) ?>"></div>
                                <div class="row">
                                    <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" value="<?= (int)$it['quantity'] ?>"></div>
                                    <div class="field"><label>Condition</label><input name="condition_status" value="<?= e($it['condition_status']) ?>"></div>
                                </div>
                                <div class="field"><label>Status</label>
                                    <select name="status"><option value="available" <?= $it['status'] === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= $it['status'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></div>
                                <div class="field"><label>New picture</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                                    <?php if ($it['image']): ?><small><label style="display:inline;font-weight:400"><input type="checkbox" name="remove_image" value="1" style="width:auto"> Remove current picture</label></small><?php endif; ?></div>
                                <button class="btn btn-sm" type="submit">Save changes</button>
                            </form>
                            <form method="POST" data-confirm="Delete this equipment? This cannot be undone."><?= csrf_field() ?>
                                <input type="hidden" name="delete_equipment" value="1">
                                <input type="hidden" name="equipment_id" value="<?= (int)$it['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Delete equipment</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pager($p) ?>
<?php endif;
page_footer();
