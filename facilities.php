<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$admin = is_admin();
$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';

$c = $conn->prepare("SELECT COUNT(*) FROM facilities WHERE facility_name LIKE ? OR location LIKE ?");
$c->bind_param("ss", $like, $like);
$c->execute();
$total = (int)$c->get_result()->fetch_row()[0];
$c->close();
$p = paginate($total, 6);

$q = $conn->prepare("SELECT * FROM facilities WHERE facility_name LIKE ? OR location LIKE ? ORDER BY facility_name LIMIT ? OFFSET ?");
$q->bind_param("ssii", $like, $like, $p['per'], $p['offset']);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

page_header('Facilities', 'facilities', $admin ? '<a class="btn btn-light btn-sm" href="admin.php?tab=facilities">Manage facilities</a>' : '');
?>
<form class="toolbar" method="GET">
    <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name or location">
    <button class="btn btn-light" type="submit"><?= icon('search', 16) ?> Search</button>
</form>

<?php if (!$rows): ?>
    <div class="card"><p class="muted" style="margin:0">No facilities found.</p></div>
<?php else: ?>
<div class="grid g3">
<?php $sched = facility_schedule($conn); foreach ($rows as $f): ?>
    <div class="res">
        <?= resource_pic('facility', $f) ?>
        <div class="res-body">
            <div class="res-top"><h3><?= e($f['facility_name']) ?></h3><?= status_pill(facility_status($f, $sched)) ?></div>
            <div class="res-meta"><span><?= e($f['location']) ?></span><span><?= (int)$f['capacity'] ?> seats</span></div>
            <?= facility_until_html($f, $sched) ?>
            <?= facility_when($f, $sched) ?>
            <p><?= e($f['description']) ?></p>
            <div class="res-foot">
                <?php if (!$admin && $f['status'] === 'available'): ?>
                    <a class="btn btn-sm" href="reserve.php?facility=<?= (int)$f['id'] ?>">Check &amp; reserve</a>
                <?php elseif (!$admin): ?>
                    <span class="btn btn-sm disabled">Unavailable</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?= render_pager($p) ?>
<?php endif; ?>
<?php page_footer();
