<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

// Reachable only mid-login, after password verified but before 2FA cleared.
$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || !is_array($pending) || empty($pending['username']) || empty($pending['secret'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$next  = $pending['next'] ?? 'index.php';
if (!preg_match('#^/?[a-zA-Z0-9_./?=&%-]*$#', $next)) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $now = time();
    $lockedUntil = $_SESSION['twofa_locked_until'] ?? 0;
    if ($lockedUntil > $now) {
        $mins = ceil(($lockedUntil - $now) / 60);
        $error = "Too many failed attempts. Try again in {$mins} minute(s).";
    } else {
        $code = (string) ($_POST['code'] ?? '');
        usleep(random_int(200000, 600000));

        if (totpVerify($pending['secret'], $code)) {
            session_regenerate_id(true);
            $_SESSION['admin_user']         = $pending['username'];
            $_SESSION['login_time']         = $now;
            $_SESSION['failed_count']       = 0;
            $_SESSION['login_locked_until'] = 0;
            $_SESSION['twofa_failed_count'] = 0;
            $_SESSION['twofa_locked_until'] = 0;
            unset($_SESSION['pending_2fa']);
            header('Location: ' . $next);
            exit;
        }

        $_SESSION['twofa_failed_count'] = ($_SESSION['twofa_failed_count'] ?? 0) + 1;
        if ($_SESSION['twofa_failed_count'] >= LOGIN_LOCK_AFTER) {
            $_SESSION['twofa_locked_until'] = $now + LOGIN_LOCK_MINUTES * 60;
            $_SESSION['twofa_failed_count'] = 0;
            unset($_SESSION['pending_2fa']);  // force restart from login
            $error = 'Too many failed attempts. Sign in again in ' . LOGIN_LOCK_MINUTES . ' minutes.';
        } else {
            $remaining = LOGIN_LOCK_AFTER - $_SESSION['twofa_failed_count'];
            $error = "Incorrect code. {$remaining} attempt(s) remaining.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify code — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo-area">
            <img src="assets/logo.png" alt="Erika Media" class="login-logo">
            <div class="login-company">Two-Factor Verification</div>
            <div class="login-sub">Enter the 6-digit code from your authenticator</div>
        </div>

        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="form-group">
                <input type="text" name="code" required pattern="\d{6}" maxlength="6" inputmode="numeric"
                       placeholder="123456" autofocus
                       style="font-size:22px; letter-spacing:8px; text-align:center;">
            </div>
            <button type="submit" class="btn-generate" style="width:100%; justify-content:center;">
                Verify
            </button>
        </form>

        <div style="margin-top:14px; text-align:center;">
            <a href="logout.php" style="color:#888; text-decoration:none; font-size:12px;">
                Cancel and sign out
            </a>
        </div>
    </div>
    <div class="login-footer">Erika Media &copy; <?= date('Y') ?></div>
</div>

</body>
</html>
