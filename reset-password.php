<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email          = strtolower(trim((string) ($_POST['email']            ?? '')));
    $code           = (string) ($_POST['code']             ?? '');
    $new            = (string) ($_POST['new_password']     ?? '');
    $confirm        = (string) ($_POST['confirm_password'] ?? '');
    $alsoResetTotp  = !empty($_POST['reset_totp']);

    $admin = loadAdmin();
    $now   = time();

    usleep(random_int(200000, 600000));

    if (!$admin
        || !hash_equals(strtolower($admin['username']), $email)
        || empty($admin['reset_code_hash'])
        || empty($admin['reset_expires'])
        || $admin['reset_expires'] < $now) {
        $error = 'Invalid or expired code. Request a new one.';

    } elseif (($admin['reset_attempts'] ?? 0) >= 5) {
        $error = 'Too many failed attempts. Request a new code.';

    } elseif (!password_verify($code, $admin['reset_code_hash'])) {
        $admin['reset_attempts'] = ($admin['reset_attempts'] ?? 0) + 1;
        saveAdmin($admin);
        $error = 'Invalid or expired code. Request a new one.';

    } elseif (strlen($new) < 12) {
        $error = 'New password must be at least 12 characters.';

    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';

    } else {
        $admin['password_hash'] = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        unset($admin['reset_code_hash'], $admin['reset_expires'], $admin['reset_attempts']);
        if ($alsoResetTotp) {
            unset($admin['totp_secret']);
        }
        saveAdmin($admin);
        $message = 'Password reset. You can now sign in with your new password.'
                 . ($alsoResetTotp ? ' 2FA has also been removed — set it up again from the dashboard.' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo-area">
            <img src="assets/logo.png" alt="Erika Media" class="login-logo">
            <div class="login-company">Reset Password</div>
            <div class="login-sub">Enter the code we emailed you</div>
        </div>

        <?php if ($message): ?>
            <div class="login-success"><?= htmlspecialchars($message) ?></div>
            <div style="margin-top:14px; text-align:center;">
                <a href="login.php" style="color:#4a90d9; text-decoration:none; font-size:13px;">
                    Sign in &rarr;
                </a>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$message): ?>
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
            </div>
            <div class="form-group">
                <label>6-digit Code</label>
                <input type="text" name="code" required pattern="\d{6}" maxlength="6" inputmode="numeric"
                       placeholder="123456"
                       style="font-size:18px; letter-spacing:6px; text-align:center;">
            </div>
            <div class="form-group">
                <label>New Password (min 12 chars)</label>
                <input type="password" name="new_password" required minlength="12">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="12">
            </div>
            <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#777; margin-bottom:14px;">
                <input type="checkbox" name="reset_totp" value="1">
                Also remove 2FA (only check this if you've lost your authenticator)
            </label>
            <button type="submit" class="btn-generate" style="width:100%; justify-content:center;">
                Reset Password
            </button>
        </form>
        <?php endif; ?>

        <div style="margin-top:14px; text-align:center;">
            <a href="login.php" style="color:#888; text-decoration:none; font-size:12px;">
                &larr; Back to sign in
            </a>
        </div>
    </div>
    <div class="login-footer">Erika Media &copy; <?= date('Y') ?></div>
</div>

</body>
</html>
