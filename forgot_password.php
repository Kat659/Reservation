<?php
require_once "auth.php";
require_once "db.php";
require_once "functions.php";

if (isset($_SESSION['user_id'])) { header("Location: profile.php"); exit(); }

$done = false;
$devLink = '';
$mailError = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = trim($_POST['identity'] ?? '');
    if ($id === '') {
        $error = "Please type your email address.";
    } else {
        $q = $conn->prepare("SELECT id, full_name, email FROM users WHERE (email = ? OR username = ?) AND status = 'active' LIMIT 1");
        $q->bind_param("ss", $id, $id);
        $q->execute();
        $u = $q->get_result()->fetch_assoc();
        $q->close();

        if ($u) {
            // At most one new link per minute per account
            $r = $conn->prepare("SELECT id FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
            $r->bind_param("i", $u['id']);
            $r->execute();
            $recent = $r->get_result()->num_rows > 0;
            $r->close();

            if (!$recent) {
                $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL");
                $del->bind_param("i", $u['id']);
                $del->execute();
                $del->close();

                $token = bin2hex(random_bytes(24));
                $hash = hash('sha256', $token);
                $mins = (int)RESET_TOKEN_MINUTES;
                $ins = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $mins MINUTE))");
                $ins->bind_param("is", $u['id'], $hash);
                $ins->execute();
                $ins->close();

                $link = base_url() . '/reset_password.php?token=' . $token;
                $text = "Hello {$u['full_name']},\n\nUse this link to set a new password (valid for $mins minutes, works once):\n$link\n\nIf you did not ask for this, ignore this message.";
                $html = '<div style="font-family:Arial,sans-serif;max-width:460px;margin:auto;padding:24px;border:1px solid #e5e5e5;border-radius:12px">'
                      . '<h2 style="margin:0 0 12px">' . e(APP_NAME) . '</h2><p style="margin:0 0 14px;color:#777;font-size:13px">' . e(SCHOOL_NAME) . '</p>'
                      . '<p>Hello ' . e($u['full_name']) . ',</p><p>Click the button to set a new password. The link works once and expires in ' . $mins . ' minutes.</p>'
                      . '<p style="margin:22px 0"><a href="' . e($link) . '" style="background:#111;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;display:inline-block">Reset my password</a></p>'
                      . '<p style="color:#777;font-size:13px">If you did not ask for this, you can ignore this email.</p></div>';
                $res = send_mail($u['email'] ?? '', APP_NAME . ' - password reset', $text, $html);
                if (!$res['ok']) $mailError = $res['error'];
                if (SHOW_RESET_LINK_ON_SCREEN) $devLink = $link;
            }
        }
        $done = true; // same answer whether or not the account exists
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Fraunces:opsz,wght@9..144,300;400;500;600&display=swap" rel="stylesheet"><script>try{var t=localStorage.getItem("theme");if(t&&t!=="system")document.documentElement.setAttribute("data-theme",t)}catch(e){}</script><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth auth-solo">
    <section class="auth-form-wrap">
        <a class="brand" href="login.php"><span class="brand-mark"><?= icon('grid', 16) ?></span><span>Facilities <b>Reservation</b></span></a>
        <div class="auth-form">
            <h1>Reset your password</h1>
            <p class="lead">Type the email saved on your account and we will send you a reset link.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

            <?php if ($done): ?>
                <div class="alert alert-success">If that account exists, a reset link was sent to its email. Check your inbox and spam folder. It works once and expires in <?= (int)RESET_TOKEN_MINUTES ?> minutes. (You can ask again after 1 minute.)</div>
                <?php if ($mailError && SHOW_RESET_LINK_ON_SCREEN): ?>
                    <div class="alert alert-warn"><b>Email not sent:</b> <?= e($mailError) ?></div>
                <?php endif; ?>
                <?php if ($devLink): ?>
                    <div class="alert alert-warn"><b>Demo mode:</b> backup link (turn off SHOW_RESET_LINK_ON_SCREEN before going live):<br><a href="<?= e($devLink) ?>"><?= e($devLink) ?></a></div>
                <?php endif; ?>
                <p class="muted">No email on your account? Ask the administrator to reset it, or add one in Profile after logging in.</p>
                <a class="btn btn-light btn-block" href="login.php">Back to log in</a>
            <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="identity">Email address</label>
                        <input id="identity" name="identity" type="text" placeholder="you@school.edu" required autofocus autocomplete="email">
                    </div>
                    <button class="btn btn-block" type="submit">Send reset link</button>
                </form>
                <p style="text-align:center;margin-top:18px"><a href="login.php" class="muted">Back to log in</a></p>
            <?php endif; ?>
        </div>
        <p class="auth-foot auth-school"><?= e(SCHOOL_NAME) ?></p>
    </section>
</div>
</body>
</html>
