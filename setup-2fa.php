<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';
requireLogin();

$admin   = loadAdmin();
$message = '';
$error   = '';

// Pending secret lives in the session until the user proves they can read codes from it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $_SESSION['pending_totp_secret'] = totpGenerateSecret();

    } elseif ($action === 'confirm') {
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        $code   = $_POST['code'] ?? '';
        if (!$secret) {
            $error = 'No setup in progress. Click "Generate" first.';
        } elseif (!totpVerify($secret, $code)) {
            $error = 'That code did not match. Make sure your authenticator clock is correct and try again.';
        } else {
            $admin['totp_secret'] = $secret;
            saveAdmin($admin);
            unset($_SESSION['pending_totp_secret']);
            $message = '2FA enabled. From now on, sign-in will require a code from your authenticator app.';
        }

    } elseif ($action === 'disable') {
        unset($admin['totp_secret']);
        saveAdmin($admin);
        unset($_SESSION['pending_totp_secret']);
        $message = '2FA disabled.';
    }
}

$enabled       = !empty($admin['totp_secret']);
$pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
$uri           = $pendingSecret ? totpUri($pendingSecret, $admin['username'] ?? 'admin') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication — Erika Media HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .twofa-wrap { max-width: 540px; margin: 40px auto; }
        .twofa-card { background:#fff; border-radius:8px; padding:32px; box-shadow:0 4px 24px rgba(0,0,0,0.08); }
        .twofa-card h2 { margin: 0 0 6px; color:#0d1b3e; }
        .twofa-status { font-size:13px; padding:6px 12px; border-radius:4px; display:inline-block; margin-bottom:16px; }
        .twofa-on  { background:#e6f7ec; color:#1e7c3a; }
        .twofa-off { background:#fdecec; color:#9b1c1c; }
        .secret-box { font-family: 'Courier New', monospace; font-size:18px; letter-spacing:2px;
                      background:#f4f6fa; border:1px solid #d6dce6; padding:14px; border-radius:6px;
                      text-align:center; margin:14px 0; user-select:all; word-break: break-all; }
        .qr-box { text-align:center; margin:20px 0; padding:20px; background:#fff; border:1px solid #eee; border-radius:6px; }
        #qrcode { display:inline-block; padding:10px; background:#fff; }
        .twofa-step { margin: 18px 0; padding-left: 14px; border-left: 3px solid #4a90d9; }
        .twofa-step h4 { margin: 0 0 6px; color:#0d1b3e; font-size:14px; }
        .twofa-step p  { margin: 0; font-size:13px; color:#555; }
        .otpauth-link { display:inline-block; word-break:break-all; font-size:11px; color:#4a90d9; margin-top:6px; }
        .danger-btn   { background:#c0392b; }
        .danger-btn:hover { background:#922b21; }
    </style>
</head>
<body class="login-body">

<div class="twofa-wrap">
    <div class="twofa-card">
        <h2>Two-Factor Authentication</h2>
        <p style="color:#777; font-size:13px; margin-top:0;">
            Adds a second step at sign-in: a 6-digit code from your authenticator app.
        </p>

        <div class="twofa-status <?= $enabled ? 'twofa-on' : 'twofa-off' ?>">
            Status: <?= $enabled ? '2FA is ENABLED' : '2FA is NOT enabled' ?>
        </div>

        <?php if ($message): ?>
            <div class="login-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($enabled && !$pendingSecret): ?>
            <p style="font-size:13px; color:#555;">
                If you've lost your authenticator, disable 2FA below and set it up again on your new device.
                You'll still need your password to do this.
            </p>
            <form method="POST" onsubmit="return confirm('Disable 2FA? You\'ll only need your password to sign in until you re-enable it.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="disable">
                <button type="submit" class="btn-generate danger-btn" style="width:100%; justify-content:center;">
                    Disable 2FA
                </button>
            </form>

        <?php elseif (!$pendingSecret): ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn-generate" style="width:100%; justify-content:center;">
                    Set up 2FA
                </button>
            </form>

        <?php else: ?>
            <div class="twofa-step">
                <h4>Step 1 — Open your authenticator app</h4>
                <p>Google Authenticator, Authy, 1Password, Microsoft Authenticator, etc. all work.</p>
            </div>

            <div class="twofa-step">
                <h4>Step 2 — Add this account</h4>
                <p>Scan the QR code below, OR tap "Add account → Manual entry" and paste the secret.</p>
            </div>

            <div class="qr-box">
                <div id="qrcode"></div>
                <div style="font-size:12px; color:#888; margin-top:10px;">Scan with your authenticator app</div>
            </div>

            <div style="font-size:12px; color:#777;">Or enter this secret manually:</div>
            <div class="secret-box"><?= htmlspecialchars($pendingSecret) ?></div>

            <a class="otpauth-link" href="<?= htmlspecialchars($uri, ENT_QUOTES) ?>">
                <?= htmlspecialchars($uri) ?>
            </a>

            <div class="twofa-step" style="margin-top:24px;">
                <h4>Step 3 — Enter the current 6-digit code to confirm</h4>
                <p>Your authenticator will show a code that changes every 30 seconds.</p>
            </div>

            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirm">
                <div class="form-group">
                    <input type="text" name="code" required pattern="\d{6}" maxlength="6" inputmode="numeric"
                           placeholder="123456" autofocus
                           style="font-size:22px; letter-spacing:8px; text-align:center;">
                </div>
                <button type="submit" class="btn-generate" style="width:100%; justify-content:center;">
                    Confirm and enable 2FA
                </button>
            </form>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <script>
                new QRCode(document.getElementById('qrcode'), {
                    text: <?= json_encode($uri) ?>,
                    width: 192, height: 192,
                    correctLevel: QRCode.CorrectLevel.M
                });
            </script>
        <?php endif; ?>

        <div style="margin-top:22px; text-align:center;">
            <a href="index.php" style="color:#4a90d9; text-decoration:none; font-size:13px;">
                &larr; Back to dashboard
            </a>
        </div>
    </div>
</div>

</body>
</html>
