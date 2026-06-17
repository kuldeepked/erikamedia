<?php
// ─────────────────────────────────────────────────────────────────
// Erika Media — Attendance API (employee-facing).
//
// Called by the installed desktop app / clock.php. This is a SEPARATE auth
// path from the admin dashboard: it authenticates an EMPLOYEE by their own
// username + password (set by the admin) and is guarded by a shared device
// key so it can't be trivially probed from the open internet.
//
// All requests: POST, JSON body, header  X-Device-Key: <key>
//
// Actions (field "action"):
//   login     {username, password}  -> {token, employee, today, settings}
//   status    {token}               -> {today}
//   clock_in  {token}               -> {today}
//   clock_out {token}               -> {today}
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/attendance-config.php';

header('Content-Type: application/json');

$settings = attSettings();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    attErr('Method not allowed.', 405);
}

// ── Device-key gate ───────────────────────────────────────────────
$deviceKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
if (!is_string($deviceKey) || !hash_equals((string) $settings['device_key'], $deviceKey)) {
    attErr('This device is not authorised. Please reinstall the attendance app.', 401);
}

$input  = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$action = trim((string) ($input['action'] ?? ''));

// Per-device approval: a unique id stored on each installed PC.
$deviceId    = trim((string) ($input['device_id'] ?? ''));
$reqApproval = !empty($settings['require_device_approval']);

// ── Simple file-based brute-force throttle (per username + IP) ─────
const ATT_THROTTLE_FILE = __DIR__ . '/.attendance-throttle.json';
const ATT_LOCK_AFTER    = 5;
const ATT_LOCK_SECONDS  = 300;

function attThrottleKey(string $username): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return strtolower($username) . '|' . $ip;
}
function attThrottleLoad(): array {
    if (!is_file(ATT_THROTTLE_FILE)) return [];
    $d = json_decode((string) file_get_contents(ATT_THROTTLE_FILE), true);
    return is_array($d) ? $d : [];
}
function attThrottleSave(array $d): void {
    @file_put_contents(ATT_THROTTLE_FILE, json_encode($d, JSON_UNESCAPED_UNICODE));
}
function attLockRemaining(string $key): int {
    $d = attThrottleLoad();
    $until = (int) ($d[$key]['until'] ?? 0);
    return $until > time() ? $until - time() : 0;
}
function attRecordFail(string $key): void {
    $d = attThrottleLoad();
    $count = (int) ($d[$key]['count'] ?? 0) + 1;
    $d[$key] = ['count' => $count, 'until' => 0];
    if ($count >= ATT_LOCK_AFTER) {
        $d[$key] = ['count' => 0, 'until' => time() + ATT_LOCK_SECONDS];
    }
    attThrottleSave($d);
}
function attClearFail(string $key): void {
    $d = attThrottleLoad();
    unset($d[$key]);
    attThrottleSave($d);
}

// ── Build a client-facing view of a day record ────────────────────
function attRecordView(?array $r): ?array {
    if ($r === null) return null;
    return [
        'date'         => $r['date'] ?? '',
        'clock_in'     => $r['clock_in'] ?? null,
        'clock_out'    => $r['clock_out'] ?? null,
        'late'         => (bool) ($r['late'] ?? false),
        'late_minutes' => (int) ($r['late_minutes'] ?? 0),
        'shift_name'   => $r['shift_name'] ?? '',
    ];
}

function attShiftView(array $sh): array {
    return [
        'id'    => $sh['id'] ?? '',
        'name'  => $sh['name'] ?? '',
        'start' => $sh['start'] ?? '',
        'end'   => $sh['end'] ?? '',
        'grace' => (int) ($sh['grace'] ?? 0),
    ];
}

function attDesignation(string $employee): string {
    foreach (attEmployees() as $e) {
        if (strcasecmp($e['name'] ?? '', $employee) === 0) {
            return (string) ($e['designation'] ?? '');
        }
    }
    return '';
}

function attPublicSettings(array $s): array {
    return [
        'start_time'    => $s['start_time'],
        'grace_minutes' => (int) $s['grace_minutes'],
        'workday_end'   => $s['workday_end'],
        'timezone'      => $s['timezone'],
    ];
}

// ── LOGIN ─────────────────────────────────────────────────────────
if ($action === 'login') {
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($username === '' || $password === '') {
        attErr('Enter your username and password.');
    }

    // Gate unapproved computers before checking the password.
    if ($reqApproval) {
        if ($deviceId === '') {
            attErr('This computer is not registered with the attendance app. Please reinstall it.', 403);
        }
        $dev = attEnsureDevice($deviceId);
        if (($dev['status'] ?? '') !== 'approved') {
            attJson([
                'ok'             => false,
                'error'          => 'This computer (code ' . $dev['code'] . ') is not approved yet. '
                                  . 'Ask your admin to approve it in the dashboard, then try again.',
                'device_code'    => $dev['code'],
                'device_pending' => true,
            ], 403);
        }
    }

    $key = attThrottleKey($username);
    if (($wait = attLockRemaining($key)) > 0) {
        attErr('Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).', 429);
    }

    usleep(random_int(150000, 450000)); // jitter to slow brute force

    $acct = attFindAccount($username);
    $ok = $acct
        && ($acct['active'] ?? true)
        && password_verify($password, (string) ($acct['password_hash'] ?? ''));

    if (!$ok) {
        attRecordFail($key);
        attErr('Invalid username or password.', 401);
    }

    attClearFail($key);

    $employee = (string) $acct['employee'];
    if ($reqApproval && $deviceId !== '') attTouchDevice($deviceId, $employee);
    $shift = attShiftForAccount($acct, $settings);
    $today = attShiftBusinessDate($shift);

    attJson([
        'ok'          => true,
        'employee'    => $employee,
        'designation' => attDesignation($employee),
        'token'       => attMakeToken($employee, $settings),
        'today'       => attRecordView(attTodayRecord($employee, $today)),
        'shift'       => attShiftView($shift),
        'settings'    => attPublicSettings($settings),
        'server_time' => date('c'),
        'server_date' => $today,
    ]);
}

// ── Token-authenticated actions ───────────────────────────────────
$employee = attVerifyToken((string) ($input['token'] ?? ''), $settings);
if ($employee === null) {
    attErr('Session expired. Please sign in again.', 401);
}

// Re-check the device on every action (a token alone isn't enough).
if ($reqApproval) {
    $dev = $deviceId !== '' ? attFindDevice($deviceId) : null;
    if (!$dev || ($dev['status'] ?? '') !== 'approved') {
        attErr('This computer is not approved to clock in. Ask your admin to approve it.', 403);
    }
    attTouchDevice($deviceId, $employee);
}
$acct  = attFindAccountByEmployee($employee);
$shift = attShiftForAccount($acct, $settings);
$today = attShiftBusinessDate($shift);

if ($action === 'status') {
    attJson([
        'ok'          => true,
        'employee'    => $employee,
        'today'       => attRecordView(attTodayRecord($employee, $today)),
        'settings'    => attPublicSettings($settings),
        'server_time' => date('c'),
        'server_date' => $today,
    ]);
}

if ($action === 'clock_in') {
    $records = attRecords();
    foreach ($records as $r) {
        if (($r['date'] ?? '') === $today && strcasecmp($r['employee'] ?? '', $employee) === 0) {
            // Already clocked in today — return existing, don't overwrite arrival time.
            attJson([
                'ok'      => true,
                'already' => true,
                'message' => 'You already clocked in today at ' . ($r['clock_in'] ?? '?') . '.',
                'today'   => attRecordView($r),
                'server_time' => date('c'),
            ]);
        }
    }

    $now  = date('H:i:s');
    $late = attComputeLate(substr($now, 0, 5), [
        'start_time'    => $shift['start'],
        'grace_minutes' => (int) ($shift['grace'] ?? 0),
    ]);
    $rec  = [
        'id'           => attNewId(),
        'employee'     => $employee,
        'date'         => $today,
        'shift'        => $shift['id'] ?? '',
        'shift_name'   => $shift['name'] ?? '',
        'clock_in'     => $now,
        'clock_out'    => null,
        'late'         => $late['late'],
        'late_minutes' => $late['late_minutes'],
        'clock_in_at'  => date('c'),
        'clock_out_at' => null,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
        'created_at'   => date('c'),
    ];
    $records[] = $rec;
    attSaveRecords($records);

    attJson([
        'ok'      => true,
        'message' => $late['late']
            ? 'Clocked in at ' . $now . ' — marked LATE (' . $late['late_minutes'] . ' min).'
            : 'Clocked in at ' . $now . ' — on time. Have a great day!',
        'today'   => attRecordView($rec),
        'server_time' => date('c'),
    ]);
}

if ($action === 'clock_out') {
    $records = attRecords();
    $found   = null;
    foreach ($records as &$r) {
        if (($r['date'] ?? '') === $today && strcasecmp($r['employee'] ?? '', $employee) === 0) {
            $r['clock_out']    = date('H:i:s');
            $r['clock_out_at'] = date('c');
            $found = $r;
            break;
        }
    }
    unset($r);

    if ($found === null) {
        attErr('You have not clocked in today, so there is nothing to clock out.', 409);
    }

    attSaveRecords($records);
    attJson([
        'ok'      => true,
        'message' => 'Clocked out at ' . $found['clock_out'] . '. See you tomorrow!',
        'today'   => attRecordView($found),
        'server_time' => date('c'),
    ]);
}

attErr('Unknown action.');
