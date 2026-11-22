<?php
require_once "auth.php";
require_manager();
require_once "db.php";
require_once "layout.php";

$adminId = (int)$_SESSION['user_id'];
$roles = ['student', 'faculty', 'assistant_admin', 'admin'];
$isHead = is_head_admin();
// Admin can give any role.
$assignable = $isHead ? $roles : ['student', 'faculty'];

// Admin-level accounts may only be touched by the Admin (or by yourself for your own details)
function can_touch($conn, $id, $isHead, $selfId) {
    if ($isHead || $id === $selfId) return true;
    $q = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $q->bind_param("i", $id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return $r && in_array($r['role'], ['student', 'faculty'], true);
}
function deny_touch() { flash('error', 'Only the Admin can change administrator accounts.'); header("Location: users.php"); exit(); }

function temp_notice($name, $username, $pw) {
    return 'Account ready for <b>' . e($name) . '</b>.<br>Username: <span class="tmp-pass">' . e($username) . '</span> &nbsp; Temporary password: <span class="tmp-pass">' . e($pw) . '</span><br><small>Shown only once. The user must change it at first login.</small>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Admin creates an account with a temporary password
    if (isset($_POST['add_user'])) {
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', $assignable, true) ? $_POST['role'] : 'student';
        $pw = trim($_POST['password'] ?? '');
        if ($pw === '') $pw = make_temp_password();

        // Course, year and block are only saved for students
        $course = null;
        $year = null;
        $block = null;
        if ($role == 'student') {
            $course = clean_text('course', 100);
            $year = clean_text('year_level', 20);
            $block = clean_text('block', 20);
        }

        if ($name === '' || $username === '') flash('error', 'Full name and username are required.');
        elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) flash('error', 'Username: 3–50 letters, numbers, dots, dashes or underscores.');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('error', 'That email address does not look right.');
        elseif (strlen($pw) < 6) flash('error', 'Temporary password must be at least 6 characters.');
        else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $emailVal = $email === '' ? null : $email;
            $q = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, course, year_level, block, must_change_password) VALUES (?,?,?,?,?,?,?,?,1)");
            $q->bind_param("ssssssss", $name, $username, $emailVal, $hash, $role, $course, $year, $block);
            try {
                $q->execute();
                flash('success', temp_notice($name, $username, $pw));
            } catch (mysqli_sql_exception $ex) {
                flash('error', 'That username or email is already in use.');
            }
            $q->close();
        }
        header("Location: users.php"); exit();
    }

    // Reset password: issue a new temporary password (shown once)
    if (isset($_POST['reset_user'])) {
        $id = (int)$_POST['reset_user'];
        if (!can_touch($conn, $id, $isHead, $adminId)) deny_touch();
        $q = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
        $q->bind_param("i", $id); $q->execute();
        $u = $q->get_result()->fetch_assoc(); $q->close();
        if ($u) {
            $pw = make_temp_password();
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $s2 = $conn->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
            $s2->bind_param("si", $hash, $id); $s2->execute(); $s2->close();
            $c = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $c->bind_param("i", $id); $c->execute(); $c->close();
            flash('success', temp_notice($u['full_name'], $u['username'], $pw));
        }
        header("Location: users.php"); exit();
    }

    // Delete an account (and its reservations)
    if (isset($_POST['delete_user'])) {
        $id = (int)$_POST['delete_user'];
        if (!can_touch($conn, $id, $isHead, $adminId)) deny_touch();
        if ($id === $adminId) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $q = $conn->prepare("DELETE FROM reservations WHERE user_id = ?");
            $q->bind_param("i", $id); $q->execute(); $q->close();
            $q = $conn->prepare("DELETE FROM users WHERE id = ?");
            $q->bind_param("i", $id); $q->execute(); $q->close();
            flash('success', 'Account deleted.');
        }
        header("Location: users.php"); exit();
    }

    // Edit name / email / role / status
    if (isset($_POST['edit_user'])) {
        $id = (int)$_POST['edit_user'];
        if (!can_touch($conn, $id, $isHead, $adminId)) deny_touch();
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', $assignable, true) ? $_POST['role'] : 'student';
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $newPw = (string)($_POST['new_password'] ?? '');
        if ($id === $adminId) { $role = $_SESSION['role']; $status = 'active'; } // protect yourself

        // Course, year and block are only saved for students
        $course = null;
        $year = null;
        $block = null;
        if ($role == 'student') {
            $course = clean_text('course', 100);
            $year = clean_text('year_level', 20);
            $block = clean_text('block', 20);
        }
        if ($name === '') flash('error', 'Name cannot be empty.');
        elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) flash('error', 'Username: 3–50 letters, numbers, dots, dashes or underscores.');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('error', 'That email address does not look right.');
        elseif ($newPw !== '' && ($pwErr = password_problem($newPw))) flash('error', $pwErr);
        else {
            $emailVal = $email === '' ? null : $email;
            $q = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, status = ?, course = ?, year_level = ?, block = ? WHERE id = ?");
            $q->bind_param("ssssssssi", $name, $username, $emailVal, $role, $status, $course, $year, $block, $id);
            try {
                $q->execute();
                if ($newPw !== '') {   // admin set a new password: the user must choose their own at next login
                    $hash = password_hash($newPw, PASSWORD_DEFAULT);
                    $pq = $conn->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
                    $pq->bind_param("si", $hash, $id); $pq->execute(); $pq->close();
                    $c = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $c->bind_param("i", $id); $c->execute(); $c->close();
                }
                flash('success', $newPw !== '' ? 'User updated and password changed.' : 'User updated.');
            }
            catch (mysqli_sql_exception $ex) { flash('error', 'That username or email is already used by another account.'); }
            $q->close();
        }
        header("Location: users.php" . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : '')); exit();
    }
}

// Password reset requests waiting for the admin
$requests = $conn->query("SELECT pr.user_id, pr.created_at, u.full_name, u.username, u.email
    FROM password_resets pr JOIN users u ON u.id = pr.user_id
    WHERE pr.used_at IS NULL AND pr.expires_at > NOW() ORDER BY pr.created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$search = trim($_GET['q'] ?? '');
$fr = $_GET['role'] ?? '';
$where = [];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $types .= 'sss';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($fr, $roles, true)) { $where[] = "role = ?"; $types .= 's'; $params[] = $fr; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$c = $conn->prepare("SELECT COUNT(*) FROM users $w");
if ($types) $c->bind_param($types, ...$params);
$c->execute();
$total = (int)$c->get_result()->fetch_row()[0];
$c->close();
$p = paginate($total);
$q = $conn->prepare("SELECT * FROM users $w ORDER BY full_name LIMIT ? OFFSET ?");
$p2 = array_merge($params, [$p['per'], $p['offset']]);
$q->bind_param($types . 'ii', ...$p2);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

page_header('Users', 'users');
?>
<?php if ($requests): ?>
<div class="card" style="margin-top:0">
    <div class="card-head"><div><h2>Password reset requests</h2><p>These users asked for a reset link. Click Reset password to give them a new temporary password.</p></div></div>
    <?php foreach ($requests as $r): ?>
        <div class="inline-form" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line2)">
            <span><b><?= e($r['full_name']) ?></b> <span class="muted">@<?= e($r['username']) ?> · requested <?= e(date('M j, g:i A', strtotime($r['created_at']))) ?><?= $r['email'] ? '' : ' · no email on file' ?></span></span>
            <form method="POST" data-confirm="Reset the password of <?= e($r['full_name']) ?>? A new temporary password is created." data-confirm-title="Reset password?" data-confirm-ok="Yes, reset"><?= csrf_field() ?><input type="hidden" name="reset_user" value="<?= (int)$r['user_id'] ?>"><button class="btn btn-sm" type="submit">Reset password</button></form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" <?= $requests ? '' : 'style="margin-top:0"' ?>>
    <details class="manage">
        <summary class="btn btn-sm"><?= icon('plus', 15) ?> Create account</summary>
        <form method="POST"><?= csrf_field() ?>
            <input type="hidden" name="add_user" value="1">
            <div class="row">
                <div class="field"><label>Full name</label><input name="full_name" required></div>
                <div class="field"><label>Username</label><input name="username" required autocomplete="off"></div>
            </div>
            <div class="row3">
                <div class="field"><label>Role</label><select name="role" class="role-select"><?php foreach ($assignable as $ro): ?><option value="<?= e($ro) ?>"><?= e(role_label($ro)) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Email</label><input type="email" name="email"></div>
                <div class="field"><label>Temporary password <small>(blank = auto-generate)</small></label><input name="password" autocomplete="off"></div>
            </div>
            <div class="row3 student-fields">
                <div class="field"><label>Course <small>(students)</small></label><input name="course" maxlength="100" placeholder="e.g. BSIT"></div>
                <div class="field"><label>Year <small>(students)</small></label><input name="year_level" maxlength="20" placeholder="e.g. 3"></div>
                <div class="field"><label>Block <small>(students)</small></label><input name="block" maxlength="20" placeholder="e.g. A"></div>
            </div>
            <button class="btn" type="submit">Create account</button>
            <small style="display:block;margin-top:8px">The temporary password is shown once after you create the account. The person must choose their own password the first time they log in.</small>
        </form>
    </details>
</div>

<form class="toolbar" method="GET" style="margin-top:18px">
    <input class="grow" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, username or email">
    <select name="role"><option value="">All roles</option><?php foreach ($roles as $ro): ?><option value="<?= e($ro) ?>" <?= $fr === $ro ? 'selected' : '' ?>><?= e(role_label($ro)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-light" type="submit">Filter</button>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Password</th><th>Manage</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td class="empty" colspan="5">No users found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $u): $me = (int)$u['id'] === $adminId; $locked = !$isHead && !$me && !in_array($u['role'], ['student', 'faculty'], true); ?>
            <tr>
                <td><div class="who"><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span><div><b><?= e($u['full_name']) ?></b><span class="sub">@<?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></span></div></div></td>
                <td><?= e(role_label($u['role'])) ?><?php if ($u['role'] === 'student'): ?><span class="sub"><?= e(requester_detail($u)) ?></span><?php endif; ?></td>
                <td><?= status_pill($u['status']) ?></td>
                <td><?= $u['must_change_password'] ? '<span class="pill pill-amber">Temporary</span>' : '<span class="muted">Set by user</span>' ?></td>
                <td style="min-width:260px">
                    <?php if ($locked): ?><span class="muted">Admin only</span><?php else: ?>
                    <div class="cell-actions">
                        <details class="manage" id="edit-<?= (int)$u['id'] ?>"><summary class="btn btn-light btn-sm">Edit</summary>
                            <form method="POST"><?= csrf_field() ?>
                                <input type="hidden" name="edit_user" value="<?= (int)$u['id'] ?>">
                                <div class="field"><label>Full name</label><input name="full_name" value="<?= e($u['full_name']) ?>" required></div>
                                <div class="field"><label>Username</label><input name="username" value="<?= e($u['username']) ?>" required pattern="[A-Za-z0-9._\-]{3,50}"></div>
                                <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($u['email']) ?>"></div>
                                <div class="student-fields">
                                <div class="row3">
                                    <div class="field"><label>Course</label><input name="course" maxlength="100" value="<?= e($u['course']) ?>"></div>
                                    <div class="field"><label>Year</label><input name="year_level" maxlength="20" value="<?= e($u['year_level']) ?>"></div>
                                    <div class="field"><label>Block</label><input name="block" maxlength="20" value="<?= e($u['block']) ?>"></div>
                                </div>
                                <small style="display:block;margin:-4px 0 10px">Course, year and block are kept for students only.</small>
                                </div>
                                <div class="row">
                                    <div class="field"><label>Role</label><select name="role" class="role-select" <?= $me ? 'disabled' : '' ?>><?php foreach (($me ? $roles : $assignable) as $ro): ?><option value="<?= e($ro) ?>" <?= $u['role'] === $ro ? 'selected' : '' ?>><?= e(role_label($ro)) ?></option><?php endforeach; ?></select></div>
                                    <div class="field"><label>Status</label><select name="status" <?= $me ? 'disabled' : '' ?>><option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $u['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                                </div>
                                <button class="btn btn-sm" type="submit">Save</button>
                            </form>
                            <?php if (!$me): ?>
                            <form method="POST" data-confirm="Reset the password of <?= e($u['full_name']) ?>? A new temporary password is created and the old one stops working." data-confirm-title="Reset password?" data-confirm-ok="Yes, reset"><?= csrf_field() ?><input type="hidden" name="reset_user" value="<?= (int)$u['id'] ?>"><button class="btn btn-light" type="submit"><?= icon('key', 14) ?> Reset password</button></form>
                            <?php endif; ?>
                        </details>
                        <?php if (!$me): ?>
                        <form method="POST" data-confirm="Delete <?= e($u['full_name']) ?>? Their account and reservations will be removed. This cannot be undone." data-confirm-title="Delete account?" data-confirm-ok="Yes, delete"><?= csrf_field() ?><input type="hidden" name="delete_user" value="<?= (int)$u['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= render_pager($p) ?>
<?php page_footer();
