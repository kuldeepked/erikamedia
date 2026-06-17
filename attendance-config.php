<?php
// ─────────────────────────────────────────────────────────────────
// Erika Media HR Dashboard — Attendance module: shared storage + helpers.
//
// This file is INCLUDED by attendance-api.php / clock.php / attendance-admin.php.
// It is never served as data. All persistent data lives in JSON files that
// .htaccess blocks from direct web access:
//   attendance.json            — daily clock-in/out records (one per emp/day)
//   .attendance-accounts.json  — employee login credentials (bcrypt hashes)
//   .attendance-settings.json  — office hours + grace + device key
//
// Times are computed in the office timezone (Asia/Karachi by default) so the
// "late" flag reflects local arrival time regardless of where PHP is hosted.
// ─────────────────────────────────────────────────────────────────

const ATT_RECORDS_FILE  = __DIR__ . '/attendance.json';
const ATT_ACCOUNTS_FILE = __DIR__ . '/.attendance-accounts.json';
const ATT_SETTINGS_FILE = __DIR__ . '/.attendance-settings.json';
const ATT_DEVICES_FILE  = __DIR__ . '/.attendance-devices.json';
const ATT_EMPLOYEES_FILE = __DIR__ . '/employees.json';

const ATT_DEFAULT_SETTINGS = [
    'timezone'      => 'Asia/Karachi',
    'start_time'    => '09:00',   // default shift start (for staff with no shift assigned)
    'grace_minutes' => 15,        // minutes after start before "late" applies
    'workday_end'   => '18:00',   // informational; used as a sane clock-out hint
    'device_key'    => '',        // shared secret for the installed app; auto-generated
    'require_device_approval' => true, // only approved computers may clock in
    'shifts'        => [          // named shifts; "end <= start" means it crosses midnight
        ['id' => 'morning', 'name' => 'Morning', 'start' => '09:00', 'end' => '18:00', 'grace' => 15],
        ['id' => 'evening', 'name' => 'Evening', 'start' => '18:00', 'end' => '03:00', 'grace' => 15],
    ],
];

// ── Settings ──────────────────────────────────────────────────────
function attSettings(): array {
    $data = is_file(ATT_SETTINGS_FILE)
        ? json_decode((string) file_get_contents(ATT_SETTINGS_FILE), true)
        : [];
    if (!is_array($data)) $data = [];
    $s = array_merge(ATT_DEFAULT_SETTINGS, $data);

    // Generate a device key on first run and persist it.
    if (empty($s['device_key'])) {
        $s['device_key'] = bin2hex(random_bytes(16));
        @file_put_contents(
            ATT_SETTINGS_FILE,
            json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // All subsequent date()/time-of-day logic runs in office-local time.
    @date_default_timezone_set(($s['timezone'] ?? '') ?: 'Asia/Karachi');
    return $s;
}

function attSaveSettings(array $s): void {
    // Merge over what's already saved so a partial save never wipes other
    // keys (e.g. saving office hours must not reset shifts or the device key).
    $existing = is_file(ATT_SETTINGS_FILE)
        ? json_decode((string) file_get_contents(ATT_SETTINGS_FILE), true)
        : [];
    if (!is_array($existing)) $existing = [];
    $merged = array_merge(ATT_DEFAULT_SETTINGS, $existing, $s);
    if (empty($merged['device_key'])) {
        $merged['device_key'] = bin2hex(random_bytes(16));
    }
    file_put_contents(
        ATT_SETTINGS_FILE,
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

// ── Employee login accounts ───────────────────────────────────────
function attAccounts(): array {
    if (!is_file(ATT_ACCOUNTS_FILE)) return [];
    $data = json_decode((string) file_get_contents(ATT_ACCOUNTS_FILE), true);
    return is_array($data) ? $data : [];
}

function attSaveAccounts(array $accounts): void {
    file_put_contents(
        ATT_ACCOUNTS_FILE,
        json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function attFindAccount(string $username): ?array {
    $username = trim($username);
    foreach (attAccounts() as $a) {
        if (strcasecmp($a['username'] ?? '', $username) === 0) return $a;
    }
    return null;
}

function attFindAccountByEmployee(string $employee): ?array {
    foreach (attAccounts() as $a) {
        if (strcasecmp($a['employee'] ?? '', $employee) === 0) return $a;
    }
    return null;
}

// ── Employees (read-only here; managed by employees-api.php) ──────
function attEmployees(): array {
    if (!is_file(ATT_EMPLOYEES_FILE)) return [];
    $data = json_decode((string) file_get_contents(ATT_EMPLOYEES_FILE), true);
    return is_array($data) ? $data : [];
}

// ── Registered computers (per-device approval) ────────────────────
function attDevices(): array {
    if (!is_file(ATT_DEVICES_FILE)) return [];
    $data = json_decode((string) file_get_contents(ATT_DEVICES_FILE), true);
    return is_array($data) ? $data : [];
}

function attSaveDevices(array $devices): void {
    file_put_contents(
        ATT_DEVICES_FILE,
        json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function attFindDevice(string $id): ?array {
    $id = trim($id);
    foreach (attDevices() as $d) {
        if (($d['id'] ?? '') === $id) return $d;
    }
    return null;
}

// Short human-readable code so the admin can match a physical PC to a record.
function attDeviceCode(string $id): string {
    return strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $id), 0, 6));
}

// Returns the device record; auto-registers an unknown device as "pending".
function attEnsureDevice(string $id): array {
    $id = trim($id);
    $devices = attDevices();
    foreach ($devices as $d) {
        if (($d['id'] ?? '') === $id) return $d;
    }
    $rec = [
        'id'            => $id,
        'code'          => attDeviceCode($id),
        'name'          => 'New computer',
        'status'        => 'pending',   // pending | approved | blocked
        'first_seen'    => date('c'),
        'last_seen'     => date('c'),
        'last_employee' => '',
    ];
    $devices[] = $rec;
    attSaveDevices($devices);
    return $rec;
}

function attTouchDevice(string $id, string $employee): void {
    $devices = attDevices();
    foreach ($devices as &$d) {
        if (($d['id'] ?? '') === $id) {
            $d['last_seen']     = date('c');
            $d['last_employee'] = $employee;
        }
    }
    unset($d);
    attSaveDevices($devices);
}

// ── Daily attendance records ──────────────────────────────────────
function attRecords(): array {
    if (!is_file(ATT_RECORDS_FILE)) return [];
    $data = json_decode((string) file_get_contents(ATT_RECORDS_FILE), true);
    return is_array($data) ? $data : [];
}

function attSaveRecords(array $records): void {
    // Newest day first, then employee name.
    usort($records, function ($a, $b) {
        $cmp = strcmp($b['date'] ?? '', $a['date'] ?? '');
        if ($cmp !== 0) return $cmp;
        return strcmp($a['employee'] ?? '', $b['employee'] ?? '');
    });
    file_put_contents(
        ATT_RECORDS_FILE,
        json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function attTodayRecord(string $employee, string $date): ?array {
    foreach (attRecords() as $r) {
        if (($r['date'] ?? '') === $date
            && strcasecmp($r['employee'] ?? '', $employee) === 0) {
            return $r;
        }
    }
    return null;
}

function attNewId(): string {
    return 'att_' . bin2hex(random_bytes(6));
}

// ── Late calculation ──────────────────────────────────────────────
// Compares time-of-day of arrival against office start + grace.
// Returns ['late' => bool, 'late_minutes' => int] where late_minutes is
// minutes past the official start time (0 if on/before start).
function attComputeLate(string $hhmm, array $settings): array {
    $start = (string) ($settings['start_time'] ?? '09:00');
    $grace = (int) ($settings['grace_minutes'] ?? 0);

    $startMin = attMinutesOfDay($start);
    $inMin    = attMinutesOfDay($hhmm);
    if ($startMin === null || $inMin === null) {
        return ['late' => false, 'late_minutes' => 0];
    }

    $diff = $inMin - $startMin;
    return [
        'late'         => $diff > $grace,
        'late_minutes' => max(0, $diff),
    ];
}

function attMinutesOfDay(string $t): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($t), $m)) return null;
    return ((int) $m[1]) * 60 + (int) $m[2];
}

// ── Shifts ────────────────────────────────────────────────────────
function attShifts(array $settings): array {
    $shifts = $settings['shifts'] ?? [];
    return is_array($shifts) ? $shifts : [];
}

function attShiftById(array $settings, string $id): ?array {
    foreach (attShifts($settings) as $sh) {
        if (($sh['id'] ?? '') === $id) return $sh;
    }
    return null;
}

// The fallback "shift" used for staff with no shift assigned — built from the
// global default office hours so existing single-shift setups keep working.
function attDefaultShift(array $settings): array {
    return [
        'id'    => '',
        'name'  => 'Default',
        'start' => (string) $settings['start_time'],
        'end'   => (string) $settings['workday_end'],
        'grace' => (int) $settings['grace_minutes'],
    ];
}

function attShiftForAccount(?array $acct, array $settings): array {
    $id = is_array($acct) ? (string) ($acct['shift'] ?? '') : '';
    if ($id !== '') {
        $sh = attShiftById($settings, $id);
        if ($sh) return $sh;
    }
    return attDefaultShift($settings);
}

function attShiftCrossesMidnight(array $shift): bool {
    $start = attMinutesOfDay((string) ($shift['start'] ?? ''));
    $end   = attMinutesOfDay((string) ($shift['end'] ?? ''));
    return $start !== null && $end !== null && $end <= $start;
}

// The "business date" a clock-in/out belongs to. For a shift that crosses
// midnight, the early-morning tail (before the shift's end time) is attributed
// to the day the shift STARTED — i.e. yesterday — so an evening clock-out at
// 02:00 still matches the 18:00 clock-in record.
function attShiftBusinessDate(array $shift): string {
    if (attShiftCrossesMidnight($shift)) {
        $end = attMinutesOfDay((string) $shift['end']);
        $now = ((int) date('G')) * 60 + (int) date('i');
        if ($end !== null && $now < $end) {
            return date('Y-m-d', strtotime('-1 day'));
        }
    }
    return date('Y-m-d');
}

// ── Session token (opaque to client, signed with device key) ──────
// Issued at login so clock_in/clock_out/status don't resend the password.
function attMakeToken(string $employee, array $settings): string {
    $payload = ['e' => $employee, 'x' => time() + 12 * 3600];
    $body = attB64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig  = substr(hash_hmac('sha256', $body, (string) $settings['device_key']), 0, 32);
    return $body . '.' . $sig;
}

function attVerifyToken(string $token, array $settings): ?string {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    [$body, $sig] = $parts;

    $expected = substr(hash_hmac('sha256', $body, (string) $settings['device_key']), 0, 32);
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode((string) attB64UrlDecode($body), true);
    if (!is_array($payload) || empty($payload['e']) || empty($payload['x'])) return null;
    if ((int) $payload['x'] < time()) return null;  // expired

    return (string) $payload['e'];
}

function attB64UrlEncode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function attB64UrlDecode(string $s): string {
    return (string) base64_decode(strtr($s, '-_', '+/'));
}

// ── JSON response helpers (no side effects — safe for the public API) ──
function attJson($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function attErr(string $msg, int $code = 400): void {
    attJson(['ok' => false, 'error' => $msg], $code);
}
