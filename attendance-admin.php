<?php
// ─────────────────────────────────────────────────────────────────
// Erika Media HR Dashboard — Attendance admin (admin-only).
//
// Manage employee logins, office hours, view today's board + monthly
// late report, and push penalties into the existing activity log.
//
// Mutations are POSTed back here as JSON (CSRF + session protected).
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/auth.php';
requireLogin();
require_once __DIR__ . '/attendance-config.php';

$settings = attSettings();

// ── POST: admin mutations (JSON) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    header('Content-Type: application/json');

    $in     = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $action = trim((string) ($in['action'] ?? ''));

    $send = function ($p, $code = 200) { http_response_code($code); echo json_encode($p); exit; };
    $fail = function ($m, $code = 400) use ($send) { $send(['ok' => false, 'error' => $m], $code); };

    if ($action === 'set_login') {
        $employee = trim((string) ($in['employee'] ?? ''));
        $username = trim((string) ($in['username'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $shift    = trim((string) ($in['shift'] ?? ''));
        if ($shift !== '' && !attShiftById($settings, $shift)) $shift = '';

        if ($employee === '' || $username === '') $fail('Employee and username are required.');
        if (!preg_match('/^[A-Za-z0-9._%+@-]{3,80}$/', $username)) {
            $fail('Username must be 3-80 characters. Letters, numbers and . _ % + - @ are allowed (so emails work).');
        }
        // Username must be unique across other employees.
        foreach (attAccounts() as $a) {
            if (strcasecmp($a['username'] ?? '', $username) === 0
                && strcasecmp($a['employee'] ?? '', $employee) !== 0) {
                $fail("Username '{$username}' is already taken.", 409);
            }
        }

        $accounts = attAccounts();
        $existing = null; $idx = null;
        foreach ($accounts as $i => $a) {
            if (strcasecmp($a['employee'] ?? '', $employee) === 0) { $existing = $a; $idx = $i; break; }
        }

        if ($existing === null && $password === '') $fail('Set a password for the new login.');
        if ($password !== '' && strlen($password) < 4) $fail('Password must be at least 4 characters.');

        $now = date('c');
        if ($existing === null) {
            $accounts[] = [
                'employee'      => $employee,
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'shift'         => $shift,
                'active'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        } else {
            $accounts[$idx]['username']   = $username;
            $accounts[$idx]['shift']      = $shift;
            $accounts[$idx]['updated_at'] = $now;
            if ($password !== '') {
                $accounts[$idx]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }
        attSaveAccounts($accounts);
        $send(['ok' => true]);
    }

    if ($action === 'toggle_active') {
        $employee = trim((string) ($in['employee'] ?? ''));
        $accounts = attAccounts();
        foreach ($accounts as &$a) {
            if (strcasecmp($a['employee'] ?? '', $employee) === 0) {
                $a['active'] = !($a['active'] ?? true);
                $a['updated_at'] = date('c');
            }
        }
        unset($a);
        attSaveAccounts($accounts);
        $send(['ok' => true]);
    }

    if ($action === 'delete_login') {
        $employee = trim((string) ($in['employee'] ?? ''));
        $accounts = array_values(array_filter(attAccounts(),
            fn($a) => strcasecmp($a['employee'] ?? '', $employee) !== 0));
        attSaveAccounts($accounts);
        $send(['ok' => true]);
    }

    if ($action === 'save_settings') {
        $start = trim((string) ($in['start_time'] ?? ''));
        $end   = trim((string) ($in['workday_end'] ?? ''));
        $grace = (int) ($in['grace_minutes'] ?? 0);
        $tz    = trim((string) ($in['timezone'] ?? 'Asia/Karachi'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $start)) $fail('Start time must be HH:MM.');
        if ($end !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $end)) $fail('Workday end must be HH:MM.');
        if (!in_array($tz, timezone_identifiers_list(), true)) $fail('Unknown timezone.');
        attSaveSettings([
            'start_time'    => $start,
            'workday_end'   => $end ?: '18:00',
            'grace_minutes' => max(0, min(240, $grace)),
            'timezone'      => $tz,
            'require_device_approval' => !empty($in['require_device_approval']),
        ]);
        $send(['ok' => true]);
    }

    if ($action === 'save_shifts') {
        $raw = $in['shifts'] ?? [];
        if (!is_array($raw)) $fail('Invalid shifts.');
        $clean = []; $seen = [];
        foreach ($raw as $r) {
            $name  = trim((string) ($r['name'] ?? ''));
            if ($name === '') continue;
            $start = trim((string) ($r['start'] ?? ''));
            $end   = trim((string) ($r['end'] ?? ''));
            if (!preg_match('/^\d{1,2}:\d{2}$/', $start) || !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
                $fail("Shift '{$name}' needs a valid start and end time (HH:MM).");
            }
            $id = trim((string) ($r['id'] ?? ''));
            if ($id === '') $id = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
            if ($id === '') $id = 'shift';
            $base = $id; $n = 2;
            while (isset($seen[$id])) { $id = $base . '_' . $n; $n++; }
            $seen[$id] = true;
            $clean[] = [
                'id'    => $id,
                'name'  => $name,
                'start' => $start,
                'end'   => $end,
                'grace' => max(0, min(240, (int) ($r['grace'] ?? 0))),
            ];
        }
        if (!$clean) $fail('Add at least one shift.');
        attSaveSettings(['shifts' => $clean]);
        $send(['ok' => true]);
    }

    if (in_array($action, ['approve_device', 'block_device', 'rename_device', 'delete_device'], true)) {
        $id      = trim((string) ($in['id'] ?? ''));
        $devices = attDevices();

        if ($action === 'delete_device') {
            $devices = array_values(array_filter($devices, fn($d) => ($d['id'] ?? '') !== $id));
            attSaveDevices($devices);
            $send(['ok' => true]);
        }

        $found = false;
        foreach ($devices as &$d) {
            if (($d['id'] ?? '') === $id) {
                if ($action === 'approve_device')      $d['status'] = 'approved';
                elseif ($action === 'block_device')    $d['status'] = 'blocked';
                elseif ($action === 'rename_device')   $d['name']   = (trim((string) ($in['name'] ?? '')) ?: ($d['name'] ?? 'Computer'));
                $found = true;
                break;
            }
        }
        unset($d);
        if (!$found) $fail('Device not found.', 404);
        attSaveDevices($devices);
        $send(['ok' => true]);
    }

    if ($action === 'create_penalty') {
        $employee = trim((string) ($in['employee'] ?? ''));
        $amount   = (int) ($in['amount'] ?? 0);
        $reason   = trim((string) ($in['reason'] ?? ''));
        $date     = trim((string) ($in['date'] ?? date('Y-m-d')));
        if ($employee === '') $fail('Employee required.');
        if ($amount <= 0) $fail('Penalty amount must be greater than zero.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $file = __DIR__ . '/activity.json';
        $activity = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $activity[] = [
            'id'         => 'act_' . bin2hex(random_bytes(6)),
            'type'       => 'penalty',
            'date'       => $date,
            'employee'   => $employee,
            'candidate'  => '',
            'client'     => '',
            'reason'     => $reason !== '' ? $reason : 'Late attendance',
            'amount'     => $amount,
            'paid_in'    => null,
            'created_at' => date('c'),
        ];
        usort($activity, function ($a, $b) {
            $cmp = strcmp($b['date'] ?? '', $a['date'] ?? '');
            return $cmp !== 0 ? $cmp : strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });
        file_put_contents($file, json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $send(['ok' => true]);
    }

    $fail('Unknown action.');
}

// ── GET: render ───────────────────────────────────────────────────
$month = (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']))
    ? $_GET['month'] : date('Y-m');

$records   = attRecords();
$employees = attEmployees();
$accounts  = attAccounts();
$devices   = attDevices();
$todayStr  = date('Y-m-d');

// Sort devices: pending first (need attention), then approved, then blocked.
usort($devices, function ($a, $b) {
    $rank = ['pending' => 0, 'approved' => 1, 'blocked' => 2];
    $ra = $rank[$a['status'] ?? ''] ?? 3;
    $rb = $rank[$b['status'] ?? ''] ?? 3;
    if ($ra !== $rb) return $ra - $rb;
    return strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? '');
});
$pendingDevices = count(array_filter($devices, fn($d) => ($d['status'] ?? '') === 'pending'));
$approvalOn     = !empty($settings['require_device_approval']);

$shifts = attShifts($settings);
$shiftNames = [];
foreach ($shifts as $sh) $shiftNames[$sh['id']] = $sh['name'];

// Index accounts by employee (lowercased).
$accByEmp = [];
foreach ($accounts as $a) $accByEmp[strtolower($a['employee'] ?? '')] = $a;

// Today board + monthly stats.
$todayByEmp = [];
$stats = [];
foreach ($records as $r) {
    $emp = $r['employee'] ?? '';
    $k = strtolower($emp);
    if (($r['date'] ?? '') === $todayStr) $todayByEmp[$k] = $r;
    if (strpos($r['date'] ?? '', $month) === 0) {
        if (!isset($stats[$k])) $stats[$k] = ['employee' => $emp, 'present' => 0, 'late' => 0, 'late_min' => 0];
        $stats[$k]['present']++;
        if (!empty($r['late'])) { $stats[$k]['late']++; $stats[$k]['late_min'] += (int) ($r['late_minutes'] ?? 0); }
    }
}
ksort($stats);

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance — Erika Media HR</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body { display: block; }
        .att-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 28px 64px; }
        .att-top { display: flex; align-items: center; justify-content: space-between;
                   gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
        .att-top h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
        .att-back { font-size: 13px; color: var(--accent-hover); text-decoration: none; font-weight: 600; }
        .att-back:hover { text-decoration: underline; }
        .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11.5px; font-weight:600; }
        .pill-in   { background: var(--success-soft); color: var(--success); }
        .pill-late { background: var(--danger-soft);  color: var(--danger); }
        .pill-out  { background: var(--info-soft);    color: var(--info); }
        .pill-none { background: var(--surface-3);    color: var(--text-muted); }
        .pill-yes  { background: var(--success-soft); color: var(--success); }
        .pill-no   { background: var(--surface-3);    color: var(--text-muted); }
        .pill-off  { background: var(--warning-soft); color: var(--warning); }
        .num { text-align: right; white-space: nowrap; }
        .att-toast { position: fixed; top: 18px; right: 18px; z-index: 200; padding: 12px 18px;
                     border-radius: var(--radius-sm); font-size: 13px; font-weight: 600;
                     box-shadow: var(--shadow-lg); display: none; }
        .att-toast.ok  { background: var(--success); color: #fff; }
        .att-toast.err { background: var(--danger);  color: #fff; }
        .keybox { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .keybox code { background: var(--surface-3); padding: 6px 10px; border-radius: var(--radius-sm);
                       font-size: 12.5px; word-break: break-all; }
        .row-actions { white-space: nowrap; text-align: right; }
        .row-actions button { margin-left: 4px; }
        .settings-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap:14px; }
        #shifts-body input { padding:7px 10px; border:1px solid var(--border); border-radius:var(--radius-sm);
                             font-family:inherit; font-size:13px; color:var(--text); background:var(--surface-2); }
        #shifts-body input:focus { outline:none; border-color:var(--accent); background:var(--surface);
                             box-shadow:0 0 0 3px var(--accent-faint); }
        /* modal */
        .modal-bg { display:none; position:fixed; inset:0; background:rgba(20,17,12,0.45); z-index:150;
                    align-items:center; justify-content:center; padding:20px; }
        .modal-bg.open { display:flex; }
        .modal { background:var(--surface); border-radius:var(--radius-lg); box-shadow:var(--shadow-lg);
                 width:100%; max-width:420px; padding:26px 26px 22px; }
        .modal h3 { font-size:16px; font-weight:700; margin-bottom:4px; }
        .modal p.sub { font-size:12.5px; color:var(--text-muted); margin-bottom:18px; }
    </style>
</head>
<body>
<div class="att-toast" id="toast"></div>

<div class="att-wrap">
    <div class="att-top">
        <div>
            <h1>Attendance</h1>
            <div class="card-subtitle" style="border:0; padding:0; margin:4px 0 0;">
                Signed in as <strong><?= h($_SESSION['admin_user'] ?? '') ?></strong>
                · Office starts <strong><?= h($settings['start_time']) ?></strong>
                (+<?= (int) $settings['grace_minutes'] ?> min grace)
            </div>
        </div>
        <a class="att-back" href="index.php">← Back to dashboard</a>
    </div>

    <!-- ── Today's board ──────────────────────────────── -->
    <div class="card">
        <div class="card-title">Today — <?= h(date('l, j F Y')) ?></div>
        <div class="card-subtitle">Who has clocked in so far today.</div>
        <table class="emp-table">
            <thead><tr><th>Employee</th><th>Status</th><th>In</th><th>Out</th><th class="num">Late by</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $e):
                $k = strtolower($e['name']);
                $r = $todayByEmp[$k] ?? null;
            ?>
                <tr>
                    <td><strong><?= h($e['name']) ?></strong><br>
                        <span class="muted" style="font-size:11.5px;"><?= h($e['designation']) ?></span></td>
                    <td>
                        <?php if (!$r): ?>
                            <span class="pill pill-none">Not in</span>
                        <?php elseif ($r['clock_out']): ?>
                            <span class="pill pill-out">Done</span>
                        <?php elseif (!empty($r['late'])): ?>
                            <span class="pill pill-late">Late</span>
                        <?php else: ?>
                            <span class="pill pill-in">On time</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $r ? h($r['clock_in']) : '—' ?></td>
                    <td><?= ($r && $r['clock_out']) ? h($r['clock_out']) : '—' ?></td>
                    <td class="num"><?= ($r && !empty($r['late'])) ? ((int) $r['late_minutes'] . ' min') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?>
                <tr><td colspan="5" class="emp-empty">No employees yet — add them under “Manage Team”.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Monthly report ─────────────────────────────── -->
    <div class="card">
        <div class="section-head">
            <div class="card-title" style="margin:0;">Monthly report</div>
            <form method="get" style="margin:0;">
                <input type="month" name="month" value="<?= h($month) ?>"
                       onchange="this.form.submit()"
                       style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;">
            </form>
        </div>
        <div class="card-subtitle">Present days, lateness and total minutes late for <?= h(date('F Y', strtotime($month . '-01'))) ?>.
            Use “Penalty” to log a deduction into the Activity Log (it flows into payslips).</div>
        <table class="emp-table">
            <thead><tr><th>Employee</th><th class="num">Present</th><th class="num">On time</th>
                <th class="num">Late</th><th class="num">Total late</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($employees as $e):
                $k = strtolower($e['name']);
                $s = $stats[$k] ?? ['present' => 0, 'late' => 0, 'late_min' => 0];
                $onTime = $s['present'] - $s['late'];
            ?>
                <tr>
                    <td><strong><?= h($e['name']) ?></strong></td>
                    <td class="num"><?= (int) $s['present'] ?></td>
                    <td class="num"><?= (int) $onTime ?></td>
                    <td class="num"><?= $s['late'] > 0
                        ? '<span class="pill pill-late">' . (int) $s['late'] . '</span>'
                        : '<span class="muted">0</span>' ?></td>
                    <td class="num"><?= (int) $s['late_min'] ?> min</td>
                    <td class="row-actions">
                        <button class="btn-edit"
                            onclick="openPenalty('<?= h(addslashes($e['name'])) ?>', <?= (int) $s['late'] ?>, <?= (int) $s['late_min'] ?>)">
                            Penalty</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Shifts ──────────────────────────────────────── -->
    <div class="card">
        <div class="card-title">Shifts</div>
        <div class="card-subtitle">Define each team's hours, then assign people to a shift under Employee logins.
            If a shift's end time is earlier than its start (e.g. 18:00 → 03:00) it's treated as crossing
            midnight — a clock-out after midnight still counts for that shift's day.</div>
        <table class="emp-table">
            <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Grace (min)</th><th class="row-actions"></th></tr></thead>
            <tbody id="shifts-body"></tbody>
        </table>
        <div style="margin-top:14px; display:flex; gap:10px; align-items:center;">
            <button class="btn-tool" onclick="addShiftRow()">+ Add shift</button>
            <button class="btn-generate" style="margin-top:0;" onclick="saveShifts()">Save shifts</button>
        </div>
    </div>

    <!-- ── Employee logins ────────────────────────────── -->
    <div class="card">
        <div class="card-title">Employee logins</div>
        <div class="card-subtitle">Give each team member a username + password so they can clock in from the app.</div>
        <table class="emp-table">
            <thead><tr><th>Employee</th><th>Username</th><th>Shift</th><th>Login</th><th class="row-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $e):
                $k = strtolower($e['name']);
                $a = $accByEmp[$k] ?? null;
            ?>
                <tr>
                    <td><strong><?= h($e['name']) ?></strong></td>
                    <td><?= $a ? '<code>' . h($a['username']) . '</code>' : '<span class="muted">—</span>' ?></td>
                    <td><?php $sid = $a['shift'] ?? ''; echo ($sid !== '' && isset($shiftNames[$sid]))
                        ? h($shiftNames[$sid]) : '<span class="muted">Default</span>'; ?></td>
                    <td>
                        <?php if (!$a): ?><span class="pill pill-no">No login</span>
                        <?php elseif (!($a['active'] ?? true)): ?><span class="pill pill-off">Disabled</span>
                        <?php else: ?><span class="pill pill-yes">Active</span><?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <button class="btn-edit" onclick="openLogin('<?= h(addslashes($e['name'])) ?>', '<?= $a ? h(addslashes($a['username'])) : '' ?>', '<?= $a ? h(addslashes($a['shift'] ?? '')) : '' ?>')">
                            <?= $a ? 'Edit / reset' : 'Create login' ?></button>
                        <?php if ($a): ?>
                            <button class="btn-edit" onclick="post('toggle_active', {employee: '<?= h(addslashes($e['name'])) ?>'})">
                                <?= ($a['active'] ?? true) ? 'Disable' : 'Enable' ?></button>
                            <button class="btn-delete" onclick="if(confirm('Remove login for <?= h(addslashes($e['name'])) ?>?')) post('delete_login', {employee: '<?= h(addslashes($e['name'])) ?>'})">Remove</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Office hours / settings ─────────────────────── -->
    <div class="card">
        <div class="card-title">Default hours &amp; lateness rule</div>
        <div class="card-subtitle">Used for anyone <strong>not assigned to a shift</strong>. Staff with a shift use
            their shift's start + grace instead. Anyone clocking in later than <em>start + grace</em> is marked late.</div>
        <div class="settings-grid">
            <div class="form-group">
                <label>Office start (HH:MM)</label>
                <input type="time" id="set-start" value="<?= h($settings['start_time']) ?>">
            </div>
            <div class="form-group">
                <label>Grace period (minutes)</label>
                <input type="number" id="set-grace" min="0" max="240" value="<?= (int) $settings['grace_minutes'] ?>">
            </div>
            <div class="form-group">
                <label>Workday end (HH:MM)</label>
                <input type="time" id="set-end" value="<?= h($settings['workday_end']) ?>">
            </div>
            <div class="form-group">
                <label>Timezone</label>
                <input type="text" id="set-tz" value="<?= h($settings['timezone']) ?>" spellcheck="false">
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;margin-top:18px;font-size:13.5px;cursor:pointer;">
            <input type="checkbox" id="set-approval" <?= $approvalOn ? 'checked' : '' ?> style="width:16px;height:16px;">
            Only allow clock-in from <strong>&nbsp;approved computers&nbsp;</strong> (recommended)
        </label>
        <button class="btn-generate" onclick="saveSettings()">Save settings</button>
    </div>

    <!-- ── Computers (per-device approval) ─────────────── -->
    <div class="card">
        <div class="card-title">Computers
            <?php if ($pendingDevices): ?><span class="pill pill-late" style="margin-left:8px;"><?= (int) $pendingDevices ?> awaiting approval</span><?php endif; ?>
        </div>
        <div class="card-subtitle">Each PC running the app appears here the first time someone signs in on it.
            <?= $approvalOn
                ? 'Approval is <strong>ON</strong> — only approved computers can clock in.'
                : 'Approval is <strong>OFF</strong> — any computer can clock in right now.' ?>
        </div>
        <table class="emp-table">
            <thead><tr><th>Computer</th><th>Code</th><th>Status</th><th>Last used</th><th class="row-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($devices as $d): $st = $d['status'] ?? ''; ?>
                <tr>
                    <td><strong><?= h($d['name'] ?? 'Computer') ?></strong>
                        <?php if (!empty($d['last_employee'])): ?><br><span class="muted" style="font-size:11.5px;">last: <?= h($d['last_employee']) ?></span><?php endif; ?>
                    </td>
                    <td><code><?= h($d['code'] ?? '') ?></code></td>
                    <td>
                        <?php if ($st === 'approved'): ?><span class="pill pill-yes">Approved</span>
                        <?php elseif ($st === 'blocked'): ?><span class="pill pill-late">Blocked</span>
                        <?php else: ?><span class="pill pill-off">Pending</span><?php endif; ?>
                    </td>
                    <td class="muted" style="font-size:12px;"><?= !empty($d['last_seen']) ? h(date('j M, H:i', strtotime($d['last_seen']))) : '-' ?></td>
                    <td class="row-actions">
                        <?php if ($st !== 'approved'): ?><button class="btn-edit" onclick="post('approve_device', {id:'<?= h($d['id']) ?>'})">Approve</button><?php endif; ?>
                        <?php if ($st !== 'blocked'): ?><button class="btn-edit" onclick="post('block_device', {id:'<?= h($d['id']) ?>'})">Block</button><?php endif; ?>
                        <button class="btn-edit" onclick="renameDevice('<?= h($d['id']) ?>', '<?= h(addslashes($d['name'] ?? '')) ?>')">Rename</button>
                        <button class="btn-delete" onclick="if(confirm('Remove this computer? It will need re-approval if used again.')) post('delete_device', {id:'<?= h($d['id']) ?>'})">Remove</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$devices): ?>
                <tr><td colspan="5" class="emp-empty">No computers yet. Install the app on a PC and sign in once — it will show up here for you to approve.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Device key (for the installer) ─────────────── -->
    <div class="card">
        <div class="card-title">Attendance app device key</div>
        <div class="card-subtitle">The Windows installer asks for this. It pairs the app to your dashboard — keep it private.</div>
        <div class="keybox">
            <code id="devkey"><?= h($settings['device_key']) ?></code>
            <button class="btn-tool" onclick="copyKey()">Copy</button>
        </div>
    </div>
</div>

<!-- ── Login modal ─────────────────────────────────── -->
<div class="modal-bg" id="modal-login">
    <div class="modal">
        <h3 id="ml-title">Set login</h3>
        <p class="sub" id="ml-sub"></p>
        <input type="hidden" id="ml-employee">
        <div class="form-group">
            <label>Username</label>
            <input type="text" id="ml-username" autocapitalize="none" spellcheck="false">
        </div>
        <div class="form-group" style="margin-top:14px;">
            <label id="ml-pwlabel">Password</label>
            <input type="text" id="ml-password" placeholder="Set a password">
        </div>
        <div class="form-group" style="margin-top:14px;">
            <label>Shift</label>
            <select id="ml-shift">
                <option value="">Default (<?= h($settings['start_time']) ?>)</option>
                <?php foreach ($shifts as $sh): ?>
                    <option value="<?= h($sh['id']) ?>"><?= h($sh['name']) ?> (<?= h($sh['start']) ?> - <?= h($sh['end']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="margin-top:20px;">
            <button class="btn-generate" style="margin-top:0;" onclick="saveLogin()">Save</button>
            <button class="btn-cancel" style="margin-top:0;" onclick="closeModal('modal-login')">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Penalty modal ───────────────────────────────── -->
<div class="modal-bg" id="modal-penalty">
    <div class="modal">
        <h3>Log penalty</h3>
        <p class="sub" id="mp-sub"></p>
        <input type="hidden" id="mp-employee">
        <div class="form-group">
            <label>Penalty amount (Rs.)</label>
            <input type="number" id="mp-amount" min="1" placeholder="e.g. 500">
        </div>
        <div class="form-group" style="margin-top:14px;">
            <label>Reason</label>
            <input type="text" id="mp-reason" value="Late attendance">
        </div>
        <div class="form-group" style="margin-top:14px;">
            <label>Date</label>
            <input type="date" id="mp-date" value="<?= h($todayStr) ?>">
        </div>
        <div style="margin-top:20px;">
            <button class="btn-generate" style="margin-top:0;" onclick="savePenalty()">Create penalty</button>
            <button class="btn-cancel" style="margin-top:0;" onclick="closeModal('modal-penalty')">Cancel</button>
        </div>
    </div>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const $ = id => document.getElementById(id);

function toast(msg, ok = true) {
    const t = $('toast');
    t.textContent = msg; t.className = 'att-toast ' + (ok ? 'ok' : 'err'); t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 2600);
}

async function post(action, body = {}, reload = true) {
    try {
        const res = await fetch('attendance-admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ action, ...body })
        });
        const data = await res.json();
        if (!res.ok || data.ok === false) throw new Error(data.error || 'Request failed.');
        toast('Saved.');
        if (reload) setTimeout(() => location.reload(), 500);
        return data;
    } catch (e) { toast(e.message, false); throw e; }
}

function openModal(id) { $(id).classList.add('open'); }
function closeModal(id) { $(id).classList.remove('open'); }

// Login modal
function openLogin(employee, username, shift) {
    $('ml-employee').value = employee;
    $('ml-username').value = username || '';
    $('ml-password').value = '';
    $('ml-shift').value = shift || '';
    $('ml-title').textContent = (username ? 'Edit login' : 'Create login') + ' — ' + employee;
    $('ml-sub').textContent = username
        ? 'Change the username, or set a new password (leave blank to keep the current one).'
        : 'Choose a username and password for this team member.';
    $('ml-pwlabel').textContent = username ? 'New password (optional)' : 'Password';
    $('ml-password').placeholder = username ? 'Leave blank to keep current' : 'Set a password';
    openModal('modal-login');
    $('ml-username').focus();
}
function saveLogin() {
    post('set_login', {
        employee: $('ml-employee').value,
        username: $('ml-username').value.trim(),
        password: $('ml-password').value,
        shift:    $('ml-shift').value
    });
}

// Penalty modal
function openPenalty(employee, lateDays, lateMin) {
    $('mp-employee').value = employee;
    $('mp-amount').value = '';
    $('mp-reason').value = 'Late attendance';
    $('mp-sub').textContent = employee + ' — ' + lateDays + ' late day(s), ' + lateMin + ' min total this month.';
    openModal('modal-penalty');
    $('mp-amount').focus();
}
function savePenalty() {
    const amount = parseInt($('mp-amount').value || '0', 10);
    if (!amount || amount <= 0) { toast('Enter a penalty amount.', false); return; }
    post('create_penalty', {
        employee: $('mp-employee').value,
        amount,
        reason: $('mp-reason').value.trim(),
        date: $('mp-date').value
    });
}

function saveSettings() {
    post('save_settings', {
        start_time:    $('set-start').value,
        grace_minutes: parseInt($('set-grace').value || '0', 10),
        workday_end:   $('set-end').value,
        timezone:      $('set-tz').value.trim(),
        require_device_approval: $('set-approval').checked
    });
}

function renameDevice(id, current) {
    const name = prompt('Name this computer (e.g. "Reception PC"):', current || '');
    if (name === null) return;
    post('rename_device', { id, name: name.trim() });
}

// ── Shifts editor ─────────────────────────────────────
let SHIFTS = <?= json_encode(array_values($shifts), JSON_UNESCAPED_UNICODE) ?>;
function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }
function renderShiftRows() {
    const b = $('shifts-body'); b.innerHTML = '';
    SHIFTS.forEach((s, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" data-i="' + i + '" data-k="name" value="' + escAttr(s.name) + '" placeholder="Shift name" style="width:150px"></td>' +
            '<td><input type="time" data-i="' + i + '" data-k="start" value="' + escAttr(s.start) + '"></td>' +
            '<td><input type="time" data-i="' + i + '" data-k="end" value="' + escAttr(s.end) + '"></td>' +
            '<td><input type="number" min="0" max="240" data-i="' + i + '" data-k="grace" value="' + escAttr(s.grace) + '" style="width:90px"></td>' +
            '<td class="row-actions"><button class="btn-delete" onclick="removeShift(' + i + ')">Remove</button></td>';
        b.appendChild(tr);
    });
}
function syncShiftInputs() {
    $('shifts-body').querySelectorAll('input').forEach(inp => {
        const i = +inp.dataset.i, k = inp.dataset.k;
        if (!SHIFTS[i]) return;
        SHIFTS[i][k] = (k === 'grace') ? parseInt(inp.value || '0', 10) : inp.value;
    });
}
function addShiftRow() { syncShiftInputs(); SHIFTS.push({ id: '', name: '', start: '09:00', end: '18:00', grace: 15 }); renderShiftRows(); }
function removeShift(i) { syncShiftInputs(); SHIFTS.splice(i, 1); renderShiftRows(); }
function saveShifts() { syncShiftInputs(); post('save_shifts', { shifts: SHIFTS }); }
renderShiftRows();

function copyKey() {
    navigator.clipboard.writeText($('devkey').textContent.trim())
        .then(() => toast('Device key copied.'))
        .catch(() => toast('Copy failed — select it manually.', false));
}
</script>
</body>
</html>
