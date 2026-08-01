# Deploying the Erika Media dashboard

Target: IONOS shared hosting. There is **no staging environment** — whatever you upload is live
immediately, for everyone.

- Marketing site → `/` (repo folder `website/`)
- Dashboard → `/dashboard` (repo root)
- Dashboard assets → `/dashboard/assets` (repo folder `assets/`)

Two ways in: the IONOS control panel's **Webspace Explorer** (Hosting → Use Webspace) which works
off your ordinary browser login, or SFTP.

---

## ⛔ NEVER upload these

They exist **only on the server** and hold live data. Uploading a copy from your working folder
overwrites production instantly, and **there is currently no backup of any of them.**

```
employees.json          the staff roster + salary configuration
activity.json           every unpaid interview / placement / bonus / penalty
history.json            every generated payslip and offer letter
invoices.json           every client invoice raised
attendance.json         all clock-in / clock-out history
.admin.json             your admin login (bcrypt) — losing this locks you out completely
.cron-token             shared secret for the monthly finances cron
.attendance-*.json      employee logins, device keys, office hours, throttle
db/                     the SQLite financial ledger (erika.sqlite + -wal + -shm)
```

Some of these are tracked in git for historical reasons, so they **will** appear in your working
folder. Their presence there does not mean they are safe to upload. They are not.

Safest habit: upload **only the specific files you changed**, never a whole folder.

---

## Upload shared libraries FIRST

Manual upload is not atomic. If a page loads while only half the files are up, `require_once` on a
missing file is a fatal error and the tab dies. Upload in this order so the in-between state is
"new library, old caller" (usually harmless) rather than "new caller, missing library" (fatal):

1. `auth.php`, `db.php`
2. `finance-lib.php`, `payroll-lib.php`, `attendance-config.php`
3. everything else

Files that must travel together:

| If you change | Also upload |
|---|---|
| `payroll-lib.php` | `payroll-api.php`, `payroll-post-api.php` |
| `attendance-config.php` | `attendance-api.php`, `attendance-admin.php`, `clock.php` |
| `finance-lib.php` | `dashboard-api.php`, `reports-screen-api.php` |
| any `*-api.php` response shape | `index.php` (the whole frontend lives in it) |
| `auth.php` (e.g. `INTERVIEW_RATE`) | `activity-api.php` |

---

## After every upload

1. Load `https://erikamedia.com/dashboard/login.php` — expect **200**.
2. Load `https://erikamedia.com/` — expect **200**.
3. Check file permissions on anything you uploaded: files should be **604**, folders **705**.
   Manual uploads have repeatedly landed as **666** (world-writable). Fix via the Explorer:
   `⋮` → Change Permissions → set the octal field to `604`.
4. Spot-check the tab you actually changed.

## Before changing anything in `db.php`'s schema

`initSchema()` is `CREATE TABLE IF NOT EXISTS` only, so on a server where the database already
exists **it does nothing**. Adding a column to that file and uploading it will not add the column
to the live database — every insert referencing it then fails. Schema changes need a real
migration step and a snapshot taken first.

## Taking a backup (do this before any risky change)

The database is in WAL mode, so recent transactions live in `erika.sqlite-wal` until a checkpoint.
**Downloading `erika.sqlite` on its own gives you a database missing the most recent data**, with no
error to warn you. The only safe snapshot is SQLite's `VACUUM INTO`, run on the server.
