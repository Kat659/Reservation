<?php
require_once "auth.php";
require_login();
require_once "db.php";
require_once "layout.php";

$uid = (int)$_SESSION['user_id'];
$force = !empty($_SESSION['must_change_password']);
$err = ['pw' => '', 'info' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['change_password'])) {
        $cur = $_POST['current'] ?? '';
        $new = $_POST['password'] ?? '';
        $new2 = $_POST['confirm'] ?? '';
        $q = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $q->bind_param("i", $uid);
        $q->execute();
        $hash = $q->get_result()->fetch_assoc()['password'];
        $q->close();

        if (!password_verify($cur, $hash)) $err['pw'] = "Your current (or temporary) password is incorrect.";
        elseif ($new !== $new2) $err['pw'] = "The two new passwords do not match.";
        elseif ($new === $cur) $err['pw'] = "Choose a password different from the current one.";
        elseif ($p = password_problem($new)) $err['pw'] = $p;
        else {
            $h = password_hash($new, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            $u->bind_param("si", $h, $uid);
            $u->execute();
            $u->close();
            $_SESSION['must_change_password'] = 0;
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
            header("Location: " . ($force ? "dashboard.php" : "profile.php"));
            exit();
        }
    }

    if (isset($_POST['save_info']) && !$force) {
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // Only students have a course, year and block
        $isStudent = false;
        if (($_SESSION['role'] ?? '') == 'student') {
            $isStudent = true;
        }
        $course = null;
        $year = null;
        $block = null;
        if ($isStudent == true) {
            $course = clean_text('course', 100);
            $year = clean_text('year_level', 20);
            $block = clean_text('block', 20);
        }
        if ($name === '') $err['info'] = "Name cannot be empty.";
        elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) $err['info'] = "Username: 3–50 letters, numbers, dots, dashes or underscores.";
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err['info'] = "That email address does not look right.";
        else {
            $emailVal = $email === '' ? null : $email;
            if ($isStudent == true) {
                $u = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, course = ?, year_level = ?, block = ? WHERE id = ?");
                $u->bind_param("ssssssi", $name, $username, $emailVal, $course, $year, $block, $uid);
            } else {
                $u = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?");
                $u->bind_param("sssi", $name, $username, $emailVal, $uid);
            }
            try {
                $u->execute();
                $_SESSION['full_name'] = $name;
                flash('success', 'Profile saved.');
                header("Location: profile.php");
                exit();
            } catch (mysqli_sql_exception $ex) {
                $err['info'] = "That username or email is already used by another account.";
            }
            $u->close();
        }
    }
}

$q = $conn->prepare("SELECT full_name, username, email, role, course, year_level, block, created_at FROM users WHERE id = ?");
$q->bind_param("i", $uid);
$q->execute();
$me = $q->get_result()->fetch_assoc();
$q->close();

page_header($force ? 'Set your new password' : 'Profile', 'profile');
if ($force): ?>
    <div class="alert alert-warn">You are using a temporary password. Please choose your own password to continue.</div>
<?php endif; ?>

<div class="split"<?= $force ? "" : " style=\"grid-template-columns:minmax(0,640px)\"" ?>>
    <?php if (!$force): ?>
    <div class="card">
        <div class="card-head"><div><h2>Your details</h2><p>You can change your name, username and email<?php if ($me['role'] === 'student') { echo ', plus your course, year and block'; } ?>. Your role is set by the administrator.</p></div></div>
        <?php if ($err['info']): ?><div class="alert alert-error"><?= e($err['info']) ?></div><?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="save_info" value="1">
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= e($me['full_name']) ?>" required></div>
            <div class="field"><label for="username">Username <small>(used to log in)</small></label><input id="username" name="username" value="<?= e($me['username']) ?>" required pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username"></div>
            <div class="field"><label for="email">Email <small>(used for password reset)</small></label><input id="email" type="email" name="email" value="<?= e($me['email']) ?>"></div>
            <?php if ($me['role'] === 'student'): ?>
            <div class="row3">
                <div class="field"><label for="course">Course</label><input id="course" name="course" maxlength="100" value="<?= e($me['course']) ?>" placeholder="e.g. BSIT"></div>
                <div class="field"><label for="year_level">Year</label><input id="year_level" name="year_level" maxlength="20" value="<?= e($me['year_level']) ?>" placeholder="e.g. 3"></div>
                <div class="field"><label for="block">Block</label><input id="block" name="block" maxlength="20" value="<?= e($me['block']) ?>" placeholder="e.g. A"></div>
            </div>
            <?php endif; ?>
            <dl class="kv" style="margin-bottom:16px">
                <dt>Role</dt><dd><?= e(role_label($me['role'])) ?></dd>
                <dt>Member since</dt><dd><?= e(fmt_date($me['created_at'])) ?></dd>
            </dl>
            <button class="btn btn-light" type="submit">Save details</button>
            <button class="btn" type="button" id="pwOpen" style="margin-left:8px">Change password</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$force): ?>
    <div class="rv-modal" id="pwModal" <?= $err['pw'] ? '' : 'hidden' ?>><div class="rv-box" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
    <?php else: ?>
    <div class="card">
    <?php endif; ?>
        <div class="card-head"><div><h2 id="pwTitle">Change password</h2><p>Use at least 8 characters with a letter and a number.</p></div></div>
        <?php if ($err['pw']): ?><div class="alert alert-error"><?= e($err['pw']) ?></div><?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="change_password" value="1">
            <div class="field">
                <label for="current"><?= $force ? 'Temporary password' : 'Current password' ?></label>
                <div class="pw"><input id="current" type="password" name="current" required autocomplete="current-password"><button type="button" aria-label="Show or hide"><?= icon('eye') ?></button></div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="password">New password</label>
                    <div class="pw"><input id="password" type="password" name="password" required autocomplete="new-password"><button type="button" aria-label="Show or hide"><?= icon('eye') ?></button></div>
                </div>
                <div class="field">
                    <label for="confirm">Confirm new password</label>
                    <div class="pw"><input id="confirm" type="password" name="confirm" required autocomplete="new-password"><button type="button" aria-label="Show or hide"><?= icon('eye') ?></button></div>
                </div>
            </div>
            <button class="btn" type="submit">Update password</button>
            <?php if (!$force): ?><button class="btn btn-light" type="button" id="pwCancel" style="margin-left:8px">Cancel</button><?php endif; ?>
        </form>
    <?php if (!$force): ?></div></div><?php else: ?></div><?php endif; ?>
</div>
<?php if (!$force): ?>
<script>
(function(){var m=document.getElementById('pwModal'),o=document.getElementById('pwOpen'),c=document.getElementById('pwCancel');
function show(){m.hidden=false;var i=document.getElementById('current');if(i)i.focus();}
function hide(){m.hidden=true;}
o.addEventListener('click',show);c.addEventListener('click',hide);
m.addEventListener('click',function(e){if(e.target===m)hide();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')hide();});})();
</script>
<?php endif; ?>
<?php page_footer();
