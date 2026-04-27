<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail_helper.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Throttle: at most one code request per 60 seconds.
    $lastRequest = $_SESSION['reset_last_request'] ?? 0;
    $now = time();
    if ($now - $lastRequest < 60) {
        $wait = 60 - ($now - $lastRequest);
        $error = "Please wait {$wait} second(s) before requesting another code.";
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $admin = loadAdmin();

        if ($admin && hash_equals(strtolower($admin['username']), $email)) {
            $code   = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = $now + 15 * 60;

            $admin['reset_code_hash'] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
            $admin['reset_expires']   = $expiry;
            $admin['reset_attempts']  = 0;
            saveAdmin($admin);

            $body  = "You requested a password reset for the Erika Media HR Dashboard.\n\n";
            $body .= "Your reset code is:  {$code}\n\n";
            $body .= "This code expires in 15 minutes.\n\n";
            $body .= "Open this page to use it:  https://erikamedia.com/dashboard/reset-password.php\n\n";
            $body .= "If you did not request this, you can ignore this email — your password will not change.\n";

            sendDashboardEmail($admin['username'], 'Password reset code — Erika Media HR Dashboard', $body);
            $_SESSION['reset_last_request'] = $now;
        }

        // Always show the same message — never leak whether the email is registered.
        $message = 'If that email is registered, a 6-digit reset code has been sent. Check your inbox (and spam).';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo-area">
            <img src="assets/logo.png" alt="Erika Media" class="login-logo">
            <div class="login-company">Forgot Password</div>
            <div class="login-sub">We'll email you a 6-digit reset code</div>
        </div>

        <?php if ($message): ?>
            <div class="login-success"><?= htmlspecialchars($message) ?></div>
            <div style="margin-top:14px; text-align:center;">
                <a href="reset-password.php" style="color:#4a90d9; text-decoration:none; font-size:13px;">
                    I have my code &rarr;
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
                <input type="email" name="email" required autofocus>
            </div>
            <button type="submit" class="btn-generate" style="width:100%; justify-content:center;">
                Send reset code
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
