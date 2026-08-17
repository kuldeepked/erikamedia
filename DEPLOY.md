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

1. `auth.php`, `migrations.php`, `db.php`
2. `finance-lib.php`, `ledger-lib.php`, `payslip-lib.php`, `payslip-render.php`,
   `payroll-lib.php`, `attendance-config.php`
3. everything else

`db.php` requires `migrations.php`, and `ledger-lib.php` requires `finance-lib.php`, so those two
must already be up before `db.php` lands or every page 500s. Within step 2 the order is:
`finance-lib` → `ledger-lib` → `payslip-lib` → `payroll-lib`.

Files that must travel together:

| If you change | Also upload |
|---|---|
| `migrations.php` | `db.php` |
| `ledger-lib.php` | `accounting-api.php`, `dashboard-api.php`, `payslip-lib.php`, `recurring-api.php`, `settings-api.php` |
| `payslip-lib.php` | `payroll-lib.php`, `payslips-api.php`, `payslip-bulk.php`, `generate-payslip.php`, `payroll-post-api.php` |
| `payslip-render.php` | `generate-payslip.php`, `payslip-bulk.php` |
| `payroll-lib.php` | `payroll-api.php`, `payroll-post-api.php`, `payslips-api.php`, `payslip-bulk.php` |
| `attendance-config.php` | `attendance-api.php`, `attendance-admin.php`, `clock.php` |
| `finance-lib.php` | `dashboard-api.php`, `reports-screen-api.php`, `ledger-lib.php` |
| any `*-api.php` response shape | `index.php` (the whole frontend lives in it) |
| `assets/style.css` | `index.php` (they share class names) |
| `auth.php` (e.g. `INTERVIEW_RATE`) | `activity-api.php` |

---

## After every upload

1. Load `https://erikamedia.com/dashboard/login.php` — expect **200**.
2. Load `https://erikamedia.com/` — expect **200**.
3. Check file permissions on anything you uploaded: files should be **604**, folders **705**.
   Manual uploads have repeatedly landed as **666** (world-writable). Fix via the Explorer:
   `⋮` → Change Permissions → set the octal field to `604`.
4. Spot-check the tab you actually changed.
5. Open **Accounting → Health**. If the migration or the upload went wrong, a broken trial balance
   or a wave of new issues shows up here before anyone relies on the numbers.

## Changing the schema

`initSchema()` in `db.php` is `CREATE TABLE IF NOT EXISTS` only, so on a server where the database
already exists **it does nothing**. It describes the original schema and is deliberately frozen —
adding a column there and uploading it will not add the column to the live database, and every
insert referencing it then fails.

Schema changes go in **`migrations.php`** instead. It keeps a version number in `meta.schema_version`
and applies each numbered step in order, inside its own transaction, on the next page load. Every
step guards itself by inspecting the live schema first, so a re-run or a half-applied migration is
harmless.

To add one: append a numbered entry to the `$steps` map in `runSchemaMigrations()`, write the
function, and bump `SCHEMA_VERSION`. Never renumber or edit a step that has already shipped —
a server that has run it will skip the edit entirely.

**Take a snapshot before uploading any migration.** Migration 2 rebuilds the `accounts` table to
widen a CHECK constraint; it is transactional and verified by `PRAGMA foreign_key_check`, but a
schema rebuild is still the moment you most want a backup.

### What the first upload of the accounting release will do

On its first page load after upload the live database jumps from version 1 to 7. It will:

- rebuild `accounts` to allow `receivable`/`liability`/`equity` types and add a `kind` column,
  marking "Employee Advances" and "Loans Receivable" as receivables;
- add `code`/`tax_deductible` to `categories`, and `source`/`source_ref`/`reconciled`/`reconciled_at`
  to `transactions`;
- create `payslips`, `recurring_rules` and `settings`;
- copy every payslip out of `history.json` into the `payslips` table, marking as posted any that
  already have a matching salary expense in the ledger so nothing is double-booked.
  `history.json` itself is not modified.

None of this deletes anything. It is safe to run twice — the second run is a no-op.

Expect the Payroll Register's reconciliation to report a difference for months issued **before**
this release: the old flow booked net pay only and never recorded the provident fund, EOBI, tax or
advance recovery. That is the report doing its job. Re-generating those months from the Payroll
screen reverses the old entry and books the full cost.

## Taking a backup (do this before any risky change)

The database is in WAL mode, so recent transactions live in `erika.sqlite-wal` until a checkpoint.
**Downloading `erika.sqlite` on its own gives you a database missing the most recent data**, with no
error to warn you. The only safe snapshot is SQLite's `VACUUM INTO`, run on the server.
