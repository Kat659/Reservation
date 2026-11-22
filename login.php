<?php
require_once "auth.php";
require_once "db.php";
require_once "functions.php";

if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }

// Create the demo accounts the first time (real bcrypt hashes made by your server)
$demo = [
    ['Administrator', 'admin', 'admin123', 'admin'],
    ['Assistant Administrator 1', 'assistant1', 'assist123', 'assistant_admin'],
    ['Assistant Administrator 2', 'assistant2', 'assist123', 'assistant_admin'],
    ['Student One', 'student1', 'student123', 'student'],
    ['Faculty One', 'faculty1', 'faculty123', 'faculty'],
];
foreach ($demo as $d) {
    $c = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $c->bind_param("s", $d[1]);
    $c->execute();
    $exists = $c->get_result()->num_rows > 0;
    $c->close();
    if (!$exists) {
        $hash = password_hash($d[2], PASSWORD_DEFAULT);
        // Only the demo student gets a course, year and block
        $dc = null;
        $dy = null;
        $db = null;
        if ($d[3] == 'student') {
            $dc = 'BSIT';
            $dy = '3';
            $db = 'A';
        }
        $i = $conn->prepare("INSERT INTO users (full_name, username, password, role, course, year_level, block, must_change_password) VALUES (?,?,?,?,?,?,?,0)");
        $i->bind_param("sssssss", $d[0], $d[1], $hash, $d[3], $dc, $dy, $db);
        $i->execute();
        $i->close();
    }
}

$error = "";
$username = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($_SESSION['lock_until']) && time() < $_SESSION['lock_until']) {
        $error = "Too many attempts. Please wait " . ($_SESSION['lock_until'] - time()) . " seconds and try again.";
    } else {
        $q = $conn->prepare("SELECT id, full_name, password, role, status, must_change_password FROM users WHERE username = ?");
        $q->bind_param("s", $username);
        $q->execute();
        $u = $q->get_result()->fetch_assoc();
        $q->close();

        if ($u && password_verify($password, $u['password'])) {
            if ($u['status'] !== 'active') {
                $error = "This account is inactive. Please contact the administrator.";
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                $_SESSION['full_name'] = $u['full_name'];
                $_SESSION['role'] = $u['role'];
                $_SESSION['must_change_password'] = (int)$u['must_change_password'];
                unset($_SESSION['fails'], $_SESSION['lock_until']);
                header("Location: " . ($u['must_change_password'] ? "profile.php?force=1" : "dashboard.php"));
                exit();
            }
        } else {
            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= 5) {
                $_SESSION['lock_until'] = time() + 60;
                $_SESSION['fails'] = 0;
            }
            $error = "Incorrect username or password.";
        }
    }
}
$notice = $_GET['reset'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Fraunces:opsz,wght@9..144,300;400;500;600&display=swap" rel="stylesheet"><script>try{var t=localStorage.getItem("theme");if(t&&t!=="system")document.documentElement.setAttribute("data-theme",t)}catch(e){}</script><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth">
    <section class="auth-form-wrap">
        <a class="brand" href="login.php"><span class="brand-mark"><?= icon('grid', 16) ?></span><span>Facilities <b>Reservation</b></span></a>
        <form class="auth-form" method="POST" autocomplete="on">
            <h1>Welcome back</h1>
            <p class="lead">Log in with the account given to you by the school administrator.</p>

            <?php if ($notice === 'done'): ?><div class="alert alert-success">Password updated. You can log in now.</div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

            <?= csrf_field() ?>
            <div class="field">
                <label for="username">Username</label>
                <div class="with-icon"><?= icon('user', 17) ?><input id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username"></div>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="pw with-icon"><?= icon('lock', 17) ?>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                    <button type="button" aria-label="Show or hide password"><?= icon('eye') ?></button>
                </div>
            </div>
            <div class="auth-links">
                <a href="forgot_password.php">Forgot password?</a>
            </div>
            <button class="btn btn-block" type="submit">Log in</button>
        </form>
        <p class="auth-foot"><?= e(SCHOOL_NAME) ?></p>
    </section>

    <section class="board" aria-hidden="true">
        <div class="board-head">
            <p class="eyebrow">School facility reservations</p>
            <h2>See what is free. Book it in a minute.</h2>
            <p>Rooms and equipment, one shared schedule, and clear approval for every booking.</p>
        </div>
        <div class="tt">
            <span></span><span class="d">Mon</span><span class="d">Tue</span><span class="d">Wed</span><span class="d">Thu</span><span class="d">Fri</span>
            <?php
            $hrs = ['8 AM','9 AM','10 AM','11 AM','12 PM','1 PM'];
            $blocks = ['0-1'=>['c1','Computer Lab'],'1-3'=>['c2','Conference'],'2-0'=>['c3','Projector'],'3-4'=>['c1','Computer Lab'],'4-2'=>['c2','AV Room'],'0-4'=>['c3','Laptops ×3']];
            foreach ($hrs as $r => $h) {
                echo '<span class="h">' . $h . '</span>';
                for ($c = 0; $c < 5; $c++) {
                    $k = $c . '-' . $r;
                    echo isset($blocks[$k]) ? '<span class="b ' . $blocks[$k][0] . '">' . $blocks[$k][1] . '</span>' : '<span></span>';
                }
            }
            ?>
        </div>
        <div class="board-note"><span><i style="background:#f1e3b8"></i>Rooms</span><span><i style="background:#c9dbe8"></i>Meetings</span><span><i style="background:#d8e3cf"></i>Equipment</span><span>Reserve any time, until <?= e(fmt_time(CLOSE_TIME)) ?></span></div>
    </section>
</div>
<script src="app.js?v=<?= @filemtime(__DIR__ . "/app.js") ?>"></script>
</body>
</html>
