<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $current  = (string) ($_POST['current_password'] ?? '');
    $new      = (string) ($_POST['new_password']     ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    $admin = loadAdmin();

    if (!password_verify($current, $admin['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 12) {
        $error = 'New password must be at least 12 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif ($new === $current) {
        $error = 'New password must be different from current password.';
    } else {
        $admin['password_hash'] = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        saveAdmin($admin);
        $success = 'Password changed successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change password — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo-area">
            <img src="assets/logo.png" alt="Erika Media" class="login-logo">
            <div class="login-company">Change Password</div>
            <div class="login-sub"><?= htmlspecialchars($_SESSION['admin_user']) ?></div>
        </div>

        <?php if ($success): ?>
            <div class="login-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password (min 12 chars)</label>
                <input type="password" name="new_password" required minlength="12">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="12">
            </div>
            <button type="submit" class="btn-generate" style="width: 100%; justify-content: center;">
                Change Password
            </button>
            <div style="margin-top: 14px; text-align: center;">
                <a href="index.php" style="color: #4a90d9; text-decoration: none; font-size: 13px;">
                    &larr; Back to dashboard
                </a>
            </div>
        </form>
    </div>
    <div class="login-footer">Erika Media &copy; <?= date('Y') ?></div>
</div>

</body>
</html>
