<?php
// Takes a point-in-time snapshot of everything that exists ONLY on this
// server: the SQLite ledger and the JSON data files. Nothing here is in git,
// so without this there is no way back from a corrupted or truncated file.
//
// Run it two ways:
//   1. Scheduled:  /dashboard/backup.php?token=SECRET     (same .cron-token as
//      cron-monthly-finances.php). Point a daily cron at it.
//   2. By hand:    open /dashboard/backup.php while logged in as admin —
//      worth doing before any risky change.
//
// Snapshots land in db/backups/YYYY-MM-DD/. That path is already denied by
// .htaccess (the db/ rule) and db/ is gitignored, so backups are neither
// downloadable nor committable. Re-running on the same day replaces that
// day's snapshot.
//
// IMPORTANT: the ledger is in WAL mode, so recent transactions live in
// erika.sqlite-wal until a checkpoint. Copying erika.sqlite by FTP gives you a
// database that opens fine but is MISSING the most recent data, with no error.
// VACUUM INTO is the only correct snapshot, and it must run on the server.

require_once __DIR__ . '/auth.php';

define('CRON_TOKEN_FILE', __DIR__ . '/.cron-token');
define('BACKUP_ROOT',     __DIR__ . '/db/backups');
define('KEEP_DAYS',       14);

header('Content-Type: text/plain; charset=UTF-8');

// Either a logged-in admin or a valid cron token.
if (!isLoggedIn()) {
    $expected = file_exists(CRON_TOKEN_FILE) ? trim((string) file_get_contents(CRON_TOKEN_FILE)) : '';
    $provided = (string) ($_GET['token'] ?? '');
    if ($expected === '' || strlen($expected) < 16 || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$started = microtime(true);
$stamp   = date('Y-m-d');
$dir     = BACKUP_ROOT . '/' . $stamp;
$log     = [];
$errors  = [];

echo "Erika Media backup — {$stamp}\n";
echo str_repeat('=', 52) . "\n\n";

// 0755, not 0700: the IONOS Webspace Explorer cannot list a directory with
// restrictive permissions, and backups you cannot see are backups you cannot
// download for off-site safety. This does NOT expose them to the web —
// .htaccess 404s the whole db/ path and denies .sqlite/.json by extension
// regardless of filesystem permissions.
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo "FATAL: cannot create {$dir}\n";
    echo "Check that db/ is writable by PHP.\n";
    exit;
}

// Repair permissions on directories created by an earlier run (mkdir above is
// skipped once they exist, so a previously restrictive mode would persist and
// keep the snapshots hidden from the file manager).
@chmod(BACKUP_ROOT, 0755);
@chmod($dir, 0755);

// ── 1. SQLite ledger ──────────────────────────────────────────────────────
$sqliteOut = $dir . '/erika.sqlite';
try {
    require_once __DIR__ . '/db.php';
    $pdo = db();

    // VACUUM INTO refuses to overwrite, so clear a same-day rerun first.
    if (file_exists($sqliteOut)) @unlink($sqliteOut);

    $pdo->exec("VACUUM INTO " . $pdo->quote($sqliteOut));

    // A snapshot nobody has opened is not a backup. Prove it is readable and
    // that the tables the app depends on actually survived.
    $check = new PDO('sqlite:' . $sqliteOut);
    $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $integrity = (string) $check->query('PRAGMA integrity_check')->fetchColumn();
    if (strtolower($integrity) !== 'ok') {
        throw new RuntimeException("integrity_check returned: {$integrity}");
    }

    // Verify the schema survived. Row counts are reported but NOT used as a
    // health test — a new or quiet install legitimately has zero rows, and
    // failing on that would cry wolf. A missing table is the real signal that
    // the snapshot is truncated or was written from a half-initialised file.
    $required = ['transactions', 'accounts', 'categories', 'books', 'meta'];
    $present  = $check->query(
        "SELECT name FROM sqlite_master WHERE type = 'table'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff($required, $present);
    if ($missing) {
        throw new RuntimeException('snapshot is missing table(s): ' . implode(', ', $missing));
    }

    $counts = [];
    foreach (['transactions', 'accounts', 'categories'] as $t) {
        $counts[$t] = (int) $check->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    $check = null;

    $log[] = sprintf(
        "  ledger   erika.sqlite            %8s  integrity OK  (%d tx, %d accounts, %d categories)",
        number_format(filesize($sqliteOut)), $counts['transactions'], $counts['accounts'], $counts['categories']
    );
} catch (Throwable $e) {
    $errors[] = 'SQLite snapshot FAILED: ' . $e->getMessage();
    @unlink($sqliteOut);
}

// ── 2. JSON data files and secrets ────────────────────────────────────────
// .admin.json matters most of all: lose it and you are locked out of the
// dashboard entirely, with no bootstrap path on shared hosting.
$files = [
    'employees.json', 'activity.json', 'history.json', 'invoices.json',
    'attendance.json', 'finances.json', 'petty-cash.json',
    '.admin.json', '.cron-token',
    '.attendance-accounts.json', '.attendance-settings.json',
    '.attendance-devices.json', '.attendance-throttle.json',
];

foreach ($files as $f) {
    $src = __DIR__ . '/' . $f;
    if (!file_exists($src)) { $log[] = sprintf("  skip     %-24s (not present)", $f); continue; }

    $raw = @file_get_contents($src);
    if ($raw === false) { $errors[] = "could not read {$f}"; continue; }

    // Refuse to snapshot a JSON file that is already corrupt — otherwise a
    // broken file silently overwrites the last good copy of itself.
    if (substr($f, -5) === '.json' && trim($raw) !== '' && json_decode($raw) === null) {
        $errors[] = "{$f} is NOT valid JSON — snapshot skipped, previous backup preserved. Investigate now.";
        continue;
    }

    if (@file_put_contents($dir . '/' . $f, $raw) === false) {
        $errors[] = "could not write backup of {$f}";
        continue;
    }
    $log[] = sprintf("  data     %-24s %8s bytes", $f, number_format(strlen($raw)));
}

// ── 3. Retention ──────────────────────────────────────────────────────────
$removed = [];
$dirs = array_filter((array) @scandir(BACKUP_ROOT), function ($d) {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d);
});
rsort($dirs);
foreach (array_slice($dirs, KEEP_DAYS) as $old) {
    $p = BACKUP_ROOT . '/' . $old;
    foreach ((array) @scandir($p) as $f) {
        if ($f !== '.' && $f !== '..') @unlink($p . '/' . $f);
    }
    if (@rmdir($p)) $removed[] = $old;
}

// ── 4. Report ─────────────────────────────────────────────────────────────
echo implode("\n", $log) . "\n\n";
echo sprintf("Snapshot: db/backups/%s\n", $stamp);
echo sprintf("Retained: %d day(s)%s\n", min(count($dirs), KEEP_DAYS),
             $removed ? '  (pruned ' . implode(', ', $removed) . ')' : '');
echo sprintf("Took:     %.2fs\n", microtime(true) - $started);

if ($errors) {
    http_response_code(500);
    echo "\nERRORS (" . count($errors) . "):\n";
    foreach ($errors as $e) echo "  ! {$e}\n";

    // A silent backup failure is the worst outcome: you find out at restore
    // time. Shout about it.
    $admin = loadAdmin();
    $to    = (string) ($admin['username'] ?? '');
    if ($to !== '') {
        require_once __DIR__ . '/mail_helper.php';
        @sendDashboardEmail(
            $to,
            'Erika Media — BACKUP FAILED ' . $stamp,
            "The nightly backup reported errors:\n\n  - " . implode("\n  - ", $errors)
            . "\n\nSnapshot directory: db/backups/{$stamp}\n"
        );
        echo "\nFailure notice emailed to {$to}\n";
    }
    exit;
}

echo "\nOK\n";

// NOTE: these snapshots sit on the same server as the originals, so they
// protect against corruption, a bad write, or a mistaken edit — but NOT
// against losing the hosting account. For true off-site safety, download
// db/backups/ periodically, or extend this to upload/email the snapshot.
