<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$next  = $_GET['next'] ?? $_POST['next'] ?? 'index.php';
if (!preg_match('#^/?[a-zA-Z0-9_./?=&%-]*$#', $next)) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $now = time();
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;
    if ($lockedUntil > $now) {
        $mins = ceil(($lockedUntil - $now) / 60);
        $error = "Too many failed attempts. Try again in {$mins} minute(s).";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $admin    = loadAdmin();

        usleep(random_int(200000, 600000)); // small jitter to slow brute force

        if ($admin
            && hash_equals(strtolower($admin['username']), strtolower($username))
            && password_verify($password, $admin['password_hash'])) {

            // Successful login: reset attempt counter, rotate session ID
            session_regenerate_id(true);
            $_SESSION['admin_user']   = $admin['username'];
            $_SESSION['login_time']   = $now;
            $_SESSION['failed_count'] = 0;
            $_SESSION['login_locked_until'] = 0;

            header('Location: ' . $next);
            exit;
        }

        $_SESSION['failed_count'] = ($_SESSION['failed_count'] ?? 0) + 1;
        if ($_SESSION['failed_count'] >= LOGIN_LOCK_AFTER) {
            $_SESSION['login_locked_until'] = $now + LOGIN_LOCK_MINUTES * 60;
            $_SESSION['failed_count'] = 0;
            $error = 'Too many failed attempts. Try again in ' . LOGIN_LOCK_MINUTES . ' minutes.';
        } else {
            $remaining = LOGIN_LOCK_AFTER - $_SESSION['failed_count'];
            $error = "Invalid username or password. {$remaining} attempt(s) remaining.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo-area">
            <img src="assets/logo.png" alt="Erika Media" class="login-logo">
            <div class="login-company">Erika Media</div>
            <div class="login-sub">HR Dashboard</div>
        </div>

        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="username" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-generate" style="width: 100%; justify-content: center;">
                Sign in
            </button>
        </form>
    </div>
    <div class="login-footer">
        Erika Media &copy; <?= date('Y') ?>
    </div>
</div>

</body>
</html>
