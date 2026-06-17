# Attendance module — Erika Media HR Dashboard

Lets the team clock in / out from a small Windows app (or any browser).
Records arrival/leave times, flags lateness against your office hours, and
feeds penalties straight into the existing Activity Log → payslips.

## Files added

| File | Purpose |
|------|---------|
| `attendance-config.php`  | Shared storage + helpers (settings, accounts, records, late calc, login tokens). Included by the others. |
| `attendance-api.php`     | Employee-facing endpoint the app calls (`login` / `clock_in` / `clock_out` / `status`). |
| `clock.php`              | The touch-friendly clock-in page the Windows app opens. Also usable in any browser. |
| `attendance-admin.php`   | Admin screen: today's board, monthly report, employee logins, office-hours settings, push penalties. |
| `index.php`              | One new sidebar link → **Attendance** (under *Operations*). |

Data is stored alongside the other dashboard data and **blocked from the web**
by your existing `.htaccess` rules:

- `attendance.json` — daily records
- `.attendance-accounts.json` — employee logins (bcrypt-hashed passwords)
- `.attendance-settings.json` — office hours, grace, timezone, **device key**
- `.attendance-throttle.json` — login brute-force counters

All four are in `.gitignore` (the device key + password hashes are secrets).

## Deploy (IONOS)

1. Upload these files to the dashboard folder (same place as `index.php`):
   `attendance-config.php`, `attendance-api.php`, `clock.php`,
   `attendance-admin.php`, and the updated `index.php`.
2. Make sure the folder is writable by PHP (it already is — `employees.json`
   etc. are written there).
3. Open the dashboard → **Attendance** in the sidebar. On first load it
   auto-creates the settings file and a **device key**.

No database changes — it uses the same JSON-file approach as the rest of HR.

## First-time setup (in the Attendance screen)

1. **Office hours & lateness rule** — set the start time (e.g. `09:00`), grace
   minutes (e.g. `15`), workday end, and timezone (`Asia/Karachi`). Save.
   Anyone clocking in later than *start + grace* is marked **late**.
2. **Employee logins** — for each team member click **Create login**, give them
   a username + password. They use these in the app. (You can disable/reset/remove
   logins any time.)
3. **Device key** — copy the key shown at the bottom; you'll paste it… actually
   you don't need to: the app reads it automatically from `clock.php`. The key is
   only shown so you can rotate it if ever needed.

## Daily use

- Team opens the **Erika Attendance** app (or `https://YOUR-DASHBOARD/clock.php`),
  signs in, taps **Clock In** on arrival and **Clock Out** when leaving.
- You watch the **Today** board and the **Monthly report** (late days + total
  minutes late per person).

## Penalties → payslips

Late rules are intentionally **manual** for now (you said you'd configure them
later). In the Monthly report, click **Penalty** on a row to log a deduction.
It writes a normal `penalty` entry into the **Activity Log** with the amount and
reason — exactly like a penalty you'd add by hand — so it flows into payslip
generation automatically. When you decide the rule (e.g. "Rs 200 per late day
after 3"), we can automate this step.

## Windows app

See `../attendance-installer/README.txt` for building and distributing the
installable Windows app. It's a thin wrapper that opens `clock.php` in its own
window — so any change you make to the page is instantly live in the app.
