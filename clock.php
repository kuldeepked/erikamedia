<?php
// ─────────────────────────────────────────────────────────────────
// Erika Media — Attendance clock-in page (employee-facing, public).
//
// This is the page the installed Windows app opens in an app-mode window.
// Employees sign in with the username + password the admin gave them, then
// tap Clock In / Clock Out. It talks to attendance-api.php on the same origin.
//
// The device key is rendered into the page server-side so same-origin calls
// carry it; the real authentication is the employee's own password.
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/attendance-config.php';
$settings  = attSettings();
$deviceKey = $settings['device_key'];
$tz        = $settings['timezone'] ?: 'Asia/Karachi';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clock In — Erika Media</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .clock-shell {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 24px;
            background:
                radial-gradient(circle at 25% 25%, rgba(204,120,92,0.16), transparent 52%),
                radial-gradient(circle at 78% 75%, rgba(31,29,26,0.10), transparent 52%),
                var(--bg);
        }
        .clock-card {
            width: 100%; max-width: 440px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 34px 34px 30px;
            text-align: center;
        }
        .clock-logo { width: 60px; height: 60px; object-fit: contain; border-radius: 12px; }
        .clock-brand { font-size: 17px; font-weight: 600; margin-top: 10px; }
        .clock-sub { font-size: 11px; letter-spacing: 1.6px; text-transform: uppercase;
                     color: var(--accent); font-weight: 600; margin-top: 3px; }
        .clock-head { padding-bottom: 20px; margin-bottom: 22px; border-bottom: 1px solid var(--border); }

        .big-time { font-size: 46px; font-weight: 700; letter-spacing: -0.02em; line-height: 1; }
        .big-date { font-size: 13.5px; color: var(--text-muted); margin-top: 8px; }

        .who { margin: 18px 0 6px; }
        .who-name { font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
        .who-role { font-size: 12.5px; color: var(--text-faint); }

        .status-pill {
            display: inline-block; margin: 14px 0 4px; padding: 7px 16px;
            border-radius: 999px; font-size: 13px; font-weight: 600;
        }
        .status-none    { background: var(--surface-3);   color: var(--text-muted); }
        .status-in      { background: var(--success-soft); color: var(--success); }
        .status-late    { background: var(--danger-soft);  color: var(--danger); }
        .status-out     { background: var(--info-soft);    color: var(--info); }

        .status-detail { font-size: 13px; color: var(--text-muted); margin: 8px 0 4px; min-height: 18px; }

        .clock-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 22px; }
        .big-btn {
            padding: 20px 14px; border: none; border-radius: var(--radius-md);
            font-size: 16px; font-weight: 700; font-family: inherit; cursor: pointer;
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            transition: transform 0.05s, background 0.15s, opacity 0.15s;
        }
        .big-btn .bb-ic { font-size: 22px; line-height: 1; }
        .big-btn:active { transform: scale(0.97); }
        .btn-in  { background: var(--success); color: #fff; }
        .btn-in:hover  { background: #2f6238; }
        .btn-out { background: var(--text); color: #fff; }
        .btn-out:hover { background: var(--accent); }
        .big-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .big-btn:disabled:hover { background: var(--success); }
        .btn-out:disabled:hover { background: var(--text); }

        .signout { margin-top: 18px; }
        .clock-foot { font-size: 11px; color: var(--text-faint); margin-top: 20px; letter-spacing: 0.3px; }
        .form-group { text-align: left; }
        .form-group + .form-group { margin-top: 14px; }
        #login-error { display: none; }
        .auto-note { font-size: 11.5px; color: var(--text-faint); margin-top: 14px; min-height: 16px; }
        input[type="text"], input[type="password"] { font-size: 16px; }
    </style>
</head>
<body>
<div class="clock-shell">
    <div class="clock-card">

        <!-- ── Login screen ───────────────────────────── -->
        <div id="screen-login">
            <div class="clock-head">
                <img src="assets/logo.png" alt="Erika Media" class="clock-logo">
                <div class="clock-brand">Erika Media</div>
                <div class="clock-sub">Attendance</div>
            </div>

            <div id="login-error" class="login-error"></div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" id="f-username" autocomplete="username"
                       autocapitalize="none" spellcheck="false" autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" id="f-password" autocomplete="current-password">
            </div>
            <button id="btn-login" class="btn-generate" style="width:100%; justify-content:center; margin-top:22px;">
                Sign in
            </button>
            <div class="clock-foot">Sign in, then tap Clock In at the start of your shift.</div>
            <div class="clock-foot" id="dev-foot" style="margin-top:4px;"></div>
        </div>

        <!-- ── Clock screen ───────────────────────────── -->
        <div id="screen-clock" style="display:none;">
            <div class="clock-head">
                <div class="big-time" id="live-time">--:--:--</div>
                <div class="big-date" id="live-date"></div>
            </div>

            <div class="who">
                <div class="who-name" id="emp-name"></div>
                <div class="who-role" id="emp-role"></div>
            </div>

            <div id="status-pill" class="status-pill status-none">Not clocked in</div>
            <div class="status-detail" id="status-detail"></div>

            <div class="clock-actions">
                <button class="big-btn btn-in"  id="btn-in"><span class="bb-ic">→</span>Clock In</button>
                <button class="big-btn btn-out" id="btn-out"><span class="bb-ic">←</span>Clock Out</button>
            </div>

            <div class="auto-note" id="auto-note"></div>
            <button class="btn-cancel signout" id="btn-signout" style="margin-top:14px;">Sign out</button>
        </div>

    </div>
</div>

<script>
const DEVICE_KEY = <?= json_encode($deviceKey) ?>;
const TZ         = <?= json_encode($tz) ?>;

// Stable per-computer id (persists in this app's isolated profile).
let DEVICE_ID = localStorage.getItem('erika_device_id');
if (!DEVICE_ID) {
    DEVICE_ID = (window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : 'dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    localStorage.setItem('erika_device_id', DEVICE_ID);
}
const DEVICE_CODE = DEVICE_ID.replace(/[^a-z0-9]/ig, '').slice(0, 6).toUpperCase();

let state = { token: null, employee: '', designation: '', settings: null, today: null };
let autoTimer = null;

const $ = id => document.getElementById(id);

async function api(action, body = {}) {
    const res = await fetch('attendance-api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Device-Key': DEVICE_KEY },
        body: JSON.stringify({ action, device_id: DEVICE_ID, ...body })
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (!res.ok || data.ok === false) {
        throw new Error(data.error || ('Request failed (' + res.status + ').'));
    }
    return data;
}

// ── Live office-time clock ───────────────────────────
function tick() {
    const now = new Date();
    try {
        $('live-time').textContent = now.toLocaleTimeString('en-GB',
            { timeZone: TZ, hour12: false });
        $('live-date').textContent = now.toLocaleDateString('en-GB',
            { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    } catch (e) {
        $('live-time').textContent = now.toLocaleTimeString();
    }
}
setInterval(tick, 1000); tick();

// ── Login ────────────────────────────────────────────
function showError(msg) {
    const el = $('login-error');
    el.textContent = msg; el.style.display = 'block';
}
async function doLogin() {
    const username = $('f-username').value.trim();
    const password = $('f-password').value;
    if (!username || !password) { showError('Enter your username and password.'); return; }
    $('btn-login').disabled = true; $('btn-login').textContent = 'Signing in…';
    try {
        const d = await api('login', { username, password });
        state.token = d.token;
        state.employee = d.employee;
        state.designation = d.designation || '';
        state.settings = d.settings;
        state.today = d.today;
        state.shift = d.shift || null;
        $('login-error').style.display = 'none';
        $('f-username').value = ''; $('f-password').value = '';
        showClock();
    } catch (e) {
        showError(e.message);
    } finally {
        $('btn-login').disabled = false; $('btn-login').textContent = 'Sign in';
    }
}

// ── Clock screen ─────────────────────────────────────
function showLogin() {
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    state = { token: null, employee: '', designation: '', settings: null, today: null, shift: null };
    $('screen-clock').style.display = 'none';
    $('screen-login').style.display = 'block';
    $('auto-note').textContent = '';
    $('f-username').focus();
}

function showClock() {
    $('screen-login').style.display = 'none';
    $('screen-clock').style.display = 'block';
    $('emp-name').textContent = state.employee;
    $('emp-role').textContent = state.designation;
    renderStatus();
}

function renderStatus() {
    const pill = $('status-pill');
    const detail = $('status-detail');
    const t = state.today;
    const btnIn = $('btn-in'), btnOut = $('btn-out');

    btnIn.disabled = false; btnOut.disabled = false;

    if (!t || !t.clock_in) {
        pill.className = 'status-pill status-none';
        pill.textContent = 'Not clocked in yet';
        detail.textContent = (state.shift && state.shift.name)
            ? state.shift.name + ' shift (starts ' + state.shift.start + ') — tap Clock In when you arrive.'
            : 'Tap Clock In when you arrive.';
        btnOut.disabled = true;
    } else if (t.clock_in && !t.clock_out) {
        if (t.late) {
            pill.className = 'status-pill status-late';
            pill.textContent = 'Clocked in — LATE';
            detail.textContent = 'In at ' + t.clock_in + ' · ' + t.late_minutes + ' min late';
        } else {
            pill.className = 'status-pill status-in';
            pill.textContent = 'Clocked in — on time';
            detail.textContent = 'In at ' + t.clock_in;
        }
        btnIn.disabled = true;
    } else {
        pill.className = 'status-pill status-out';
        pill.textContent = 'Day complete';
        detail.textContent = 'In ' + t.clock_in + ' · Out ' + t.clock_out
            + (t.late ? ' · was ' + t.late_minutes + ' min late' : '');
        btnIn.disabled = true; btnOut.disabled = true;
    }
}

function startAutoSignout(seconds) {
    if (autoTimer) clearInterval(autoTimer);
    let left = seconds;
    $('auto-note').textContent = 'Signing out in ' + left + 's…';
    autoTimer = setInterval(() => {
        left--;
        if (left <= 0) { showLogin(); return; }
        $('auto-note').textContent = 'Signing out in ' + left + 's…';
    }, 1000);
}

async function doClock(action) {
    $('btn-in').disabled = true; $('btn-out').disabled = true;
    try {
        const d = await api(action, { token: state.token });
        state.today = d.today;
        renderStatus();
        $('auto-note').textContent = '';
        startAutoSignout(10);
    } catch (e) {
        $('status-detail').textContent = e.message;
        if (/expired|authoris/i.test(e.message)) { showLogin(); return; }
        renderStatus();
    }
}

// ── Wire up ──────────────────────────────────────────
$('btn-login').addEventListener('click', doLogin);
$('f-password').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
$('f-username').addEventListener('keydown', e => { if (e.key === 'Enter') $('f-password').focus(); });
$('btn-in').addEventListener('click', () => doClock('clock_in'));
$('btn-out').addEventListener('click', () => doClock('clock_out'));
$('btn-signout').addEventListener('click', showLogin);
$('dev-foot').textContent = 'Computer ID: ' + DEVICE_CODE;
</script>
</body>
</html>
