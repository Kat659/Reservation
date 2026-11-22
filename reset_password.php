<?php
require_once "auth.php";
require_once "db.php";
require_once "functions.php";

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$hash = hash('sha256', $token);
$q = $conn->prepare("SELECT pr.id, pr.user_id, u.full_name FROM password_resets pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active'");
$q->bind_param("s", $hash);
$q->execute();
$row = $token ? $q->get_result()->fetch_assoc() : null;
$q->close();

$error = '';
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = $_POST['password'] ?? '';
    $pw2 = $_POST['confirm'] ?? '';
    if ($pw !== $pw2) $error = "The two passwords do not match.";
    elseif ($p = password_problem($pw)) $error = $p;
    else {
        $new = password_hash($pw, PASSWORD_DEFAULT);
        $u = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $u->bind_param("si", $new, $row['user_id']);
        $u->execute();
        $u->close();
        $m = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ?");
        $m->bind_param("i", $row['user_id']);
        $m->execute();
        $m->close();
        header("Location: login.php?reset=done");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New password · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Fraunces:opsz,wght@9..144,300;400;500;600&display=swap" rel="stylesheet"><script>try{var t=localStorage.getItem("theme");if(t&&t!=="system")document.documentElement.setAttribute("data-theme",t)}catch(e){}</script><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth auth-solo">
    <section class="auth-form-wrap">
        <a class="brand" href="login.php"><span class="brand-mark"><?= icon('grid', 16) ?></span><span>Facilities <b>Reservation</b></span></a>
        <div class="auth-form">
        <?php if (!$row): ?>
            <h1>Link not valid</h1>
            <p class="lead">This reset link is invalid, already used, or expired.</p>
            <a class="btn btn-block" href="forgot_password.php">Request a new link</a>
        <?php else: ?>
            <h1>New password</h1>
            <p class="lead">Hello <?= e($row['full_name']) ?>, set a new password for your account.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="password">New password</label>
                    <div class="pw"><input id="password" type="password" name="password" required autocomplete="new-password"><button type="button" aria-label="Show or hide password"><?= icon('eye') ?></button></div>
                </div>
                <div class="field">
                    <label for="confirm">Confirm new password</label>
                    <div class="pw"><input id="confirm" type="password" name="confirm" required autocomplete="new-password"><button type="button" aria-label="Show or hide password"><?= icon('eye') ?></button></div>
                </div>
                <button class="btn btn-block" type="submit">Save password</button>
            </form>
        <?php endif; ?>
        </div>
        <p class="auth-foot auth-school"><?= e(SCHOOL_NAME) ?></p>
    </section>
</div>
<script src="app.js?v=<?= @filemtime(__DIR__ . "/app.js") ?>"></script>
</body>
</html>
