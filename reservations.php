<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$uid = (int)$_SESSION['user_id'];
$admin = is_admin();

// Cancel own reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    csrf_check();
    $id = (int)$_POST['cancel_id'];
    $q = $conn->prepare("UPDATE reservations SET status='Cancelled' WHERE id = ? AND user_id = ? AND status IN ('Pending','Approved') AND reservation_date >= CURDATE()");
    $q->bind_param("ii", $id, $uid);
    $q->execute();
    if ($q->affected_rows) notify_admins($conn, 'Reservation cancelled', user_label($conn, $uid) . ' cancelled a reservation (#' . $id . ').', 'admin.php');
    flash($q->affected_rows ? 'success' : 'error', $q->affected_rows ? 'Reservation cancelled.' : 'That reservation can no longer be cancelled.');
    $q->close();
    header("Location: reservations.php");
    exit();
}

$statuses = ['Pending', 'Approved', 'In Use', 'Completed', 'Rejected', 'Cancelled'];
$fs = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$types = '';
$params = [];
if (!$admin) { $where[] = "r.user_id = ?"; $types .= 'i'; $params[] = $uid; }
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

$q = $conn->prepare("SELECT r.*, u.full_name, u.role, u.course, u.year_level, u.block, f.facility_name, " . EQUIP_LIST . " AS equip_list $from ORDER BY r.reservation_date DESC, r.start_time DESC LIMIT ? OFFSET ?");
$t2 = $types . 'ii';
$p2 = array_merge($params, [$p['per'], $p['offset']]);
$q->bind_param($t2, ...$p2);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

page_header($admin ? 'All reservations' : 'My reservations', 'reservations',
    $admin ? '' : '<a class="btn btn-sm" href="reserve.php">' . icon('plus', 15) . ' New reservation</a>');
?>
<form class="toolbar" method="GET">
    <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Search reference, resource, purpose<?= $admin ? ', requester' : '' ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $fs === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-light" type="submit">Filter</button>
</form>

<div class="table-wrap">
    <table>
        <thead><tr>
            <?php if ($admin): ?><th>Requester</th><?php else: ?><th>Reference</th><?php endif; ?><th>Resource</th><th>Date &amp; time</th><th>Purpose</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td class="empty" colspan="6">No reservations found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $canEdit = !$admin && edit_block_reason($r) === null;
            $canCancel = !$admin && in_array($r['status'], ['Pending', 'Approved'], true) && $r['reservation_date'] >= date('Y-m-d'); ?>
            <tr>
                <?php if ($admin): ?><td><?= requester_cell($r, true) ?></td><?php else: ?><td><b><?= e($r['reference_code']) ?></b></td><?php endif; ?>
                <td><?= resource_cell($r) ?></td>
                <td><?= e(fmt_date($r['reservation_date'])) ?><span class="sub"><?= e(fmt_time($r['start_time'])) ?> – <?= e(fmt_time($r['end_time'])) ?></span></td>
                <td><?= e($r['purpose']) ?></td>
                <td><?= status_pill($r['status']) ?><?php if (!empty($r['edited_at'])): ?><span class="sub">Edited <?= e(time_ago($r['edited_at'])) ?></span><?php endif; ?></td>
                <td><div class="cell-actions">
                    <?php if ($canEdit): ?><a class="btn btn-light btn-sm" href="reserve.php?edit=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?>
                    <?php if ($canCancel): ?>
                        <form method="POST" data-confirm="Are you sure you want to cancel reservation <?= e($r['reference_code']) ?>? This cannot be undone." data-confirm-title="Cancel this reservation?" data-confirm-ok="Yes, cancel it" data-confirm-cancel="Keep it"><?= csrf_field() ?><input type="hidden" name="cancel_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Cancel</button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= render_pager($p) ?>
<?php page_footer();
