<?php
require_once __DIR__ . '/auth.php';
requireLogin();

// Looks up a history record by ID and auto-submits it to the correct generator.
$id   = trim($_GET['id'] ?? '');
$file = __DIR__ . '/history.json';

if (!$id || !file_exists($file)) {
    header('Location: index.php');
    exit;
}

$history = json_decode(file_get_contents($file), true) ?: [];
$record  = null;
foreach ($history as $r) {
    if ($r['id'] === $id) { $record = $r; break; }
}

if (!$record) {
    header('Location: index.php');
    exit;
}

$action = $record['type'] === 'payslip' ? 'generate-payslip.php' : 'generate-offer.php';
$skip   = ['id', 'type', 'generated_at'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Regenerating document&hellip;</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; align-items: center;
               justify-content: center; height: 100vh; margin: 0; background: #d6dce6; color: #555; }
    </style>
</head>
<body>
    <p>Opening document&hellip;</p>
    <form id="r" method="POST" action="<?= htmlspecialchars($action) ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
        <input type="hidden" name="is_regen" value="1">
        <?php foreach ($record as $key => $value): ?>
            <?php if (!in_array($key, $skip)): ?>
                <?php if (is_array($value)): ?>
                    <input type="hidden"
                           name="<?= htmlspecialchars($key) ?>"
                           value="<?= htmlspecialchars(implode(',', $value)) ?>">
                <?php else: ?>
                    <input type="hidden"
                           name="<?= htmlspecialchars($key) ?>"
                           value="<?= htmlspecialchars((string)$value) ?>">
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </form>
    <script>document.getElementById('r').submit();</script>
</body>
</html>
