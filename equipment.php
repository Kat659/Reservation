<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$admin = is_admin();
$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';

$c = $conn->prepare("SELECT COUNT(*) FROM equipment WHERE equipment_name LIKE ? OR category LIKE ?");
$c->bind_param("ss", $like, $like);
$c->execute();
$total = (int)$c->get_result()->fetch_row()[0];
$c->close();
$p = paginate($total, 6);

$q = $conn->prepare("SELECT * FROM equipment WHERE equipment_name LIKE ? OR category LIKE ? ORDER BY equipment_name LIMIT ? OFFSET ?");
$q->bind_param("ssii", $like, $like, $p['per'], $p['offset']);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$reserved = equipment_reserved_map($conn);
$sched = equipment_schedule($conn);

page_header('Equipment', 'equipment', $admin ? '<a class="btn btn-light btn-sm" href="admin.php?tab=equipment">Manage equipment</a>' : '');
?>
<form class="toolbar" method="GET">
    <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name or category">
    <button class="btn btn-light" type="submit"><?= icon('search', 16) ?> Search</button>
</form>

<?php if (!$rows): ?>
    <div class="card"><p class="muted" style="margin:0">No equipment found.</p></div>
<?php else: ?>
<div class="grid g3">
<?php foreach ($rows as $it): ?>
    <div class="res">
        <?= resource_pic('equipment', $it) ?>
        <div class="res-body">
            <div class="res-top"><h3><?= e($it['equipment_name']) ?></h3><?= status_pill(equipment_status($it, $reserved, $sched)) ?></div>
            <div class="res-meta"><span><?= e($it['category']) ?></span><span class="stock <?= equipment_left($it, $reserved) === 0 ? 'out' : '' ?>"><b><?= equipment_left($it, $reserved) ?></b> of <?= (int)$it['quantity'] ?> available now</span><span>Condition: <?= e($it['condition_status']) ?></span></div>
            <?= equipment_until_html($it, $reserved, $sched) ?>
            <?= equipment_when($it, $sched) ?>
            <div class="res-foot">
                <?php if (!$admin && $it['status'] === 'available'): ?>
                    <a class="btn btn-sm" href="reserve.php?equipment=<?= (int)$it['id'] ?>">Check &amp; reserve</a>
                <?php elseif (!$admin): ?>
                    <span class="btn btn-sm disabled"><?= $it['status'] === 'available' ? 'None left' : 'Unavailable' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?= render_pager($p) ?>
<?php endif; ?>
<?php page_footer();
