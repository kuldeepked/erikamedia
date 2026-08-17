<?php
// Versioned schema migrations.
//
// db.php's initSchema() is CREATE TABLE IF NOT EXISTS only, so on a server
// where the database already exists it does nothing — adding a column there
// and uploading it leaves the live database untouched while every insert
// referencing the new column fails. Everything past the original schema
// therefore goes through here instead.
//
// Rules for anything added below:
//   - Numbered, applied in order, each inside its own transaction.
//   - Idempotent: guard every step by inspecting the live schema first, so a
//     half-applied migration (or a re-run) is harmless.
//   - Never destructive. Columns get added, rows get backfilled; nothing is
//     dropped that holds data.
//
// The version lives in meta.schema_version. A database that predates this file
// is version 1 — the original schema from initSchema().

const SCHEMA_VERSION = 7;

function runSchemaMigrations(PDO $pdo): void {
    $current = (int) ($pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn() ?: 1);
    if ($current >= SCHEMA_VERSION) return;

    $steps = [
        2 => 'migrateAccountKinds',
        3 => 'migrateCategoryCodes',
        4 => 'migrateTransactionProvenance',
        5 => 'migratePayslipsTable',
        6 => 'migrateRecurringRules',
        7 => 'migrateSettingsTable',
    ];

    foreach ($steps as $version => $fn) {
        if ($version <= $current) continue;
        $fn($pdo);
        $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')
            ->execute(['schema_version', (string) $version]);
    }
}

// ── Schema inspection helpers ────────────────────────────────────────────────

function columnExists(PDO $pdo, string $table, string $column): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) {
        if (strcasecmp((string) $c['name'], $column) === 0) return true;
    }
    return false;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function tableSql(PDO $pdo, string $table): string {
    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    if (columnExists($pdo, $table, $column)) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

// ── 2. Account kinds ─────────────────────────────────────────────────────────
//
// The original accounts.type CHECK only allowed bank/cash/wallet/crypto, which
// describes the physical form of an account and says nothing about which side
// of the balance sheet it sits on. "Employee Advances" and "Loans Receivable"
// were already being used as receivable pivots despite being typed as banks,
// and there was nowhere at all to park a liability such as EOBI withheld from
// payslips but not yet remitted.
//
// Widening a CHECK constraint needs a table rebuild — SQLite's ALTER TABLE
// cannot modify one in place. This follows SQLite's documented 12-step
// procedure. Row ids are preserved, so transactions.account_id keeps pointing
// at the same accounts it did before.

function migrateAccountKinds(PDO $pdo): void {
    if (columnExists($pdo, 'accounts', 'kind')) return;

    // Must be toggled outside a transaction.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('PRAGMA legacy_alter_table = ON');

    $pdo->beginTransaction();
    try {
        $pdo->exec(<<<SQL
            CREATE TABLE accounts_migrating (
                id              TEXT PRIMARY KEY,
                name            TEXT NOT NULL UNIQUE,
                type            TEXT NOT NULL CHECK(type IN ('bank','cash','wallet','crypto','receivable','liability','equity')),
                kind            TEXT NOT NULL DEFAULT 'asset' CHECK(kind IN ('asset','receivable','liability','equity')),
                currency        TEXT NOT NULL DEFAULT 'PKR',
                book_id         TEXT NULL REFERENCES books(id) ON DELETE SET NULL,
                opening_balance REAL NOT NULL DEFAULT 0,
                notes           TEXT NOT NULL DEFAULT '',
                archived        INTEGER NOT NULL DEFAULT 0,
                display_order   INTEGER NOT NULL DEFAULT 0,
                created_at      TEXT NOT NULL
            );
        SQL);

        $pdo->exec(
            "INSERT INTO accounts_migrating
                (id, name, type, kind, currency, book_id, opening_balance, notes, archived, display_order, created_at)
             SELECT id, name, type, 'asset', currency, book_id, opening_balance, notes, archived, display_order, created_at
             FROM accounts"
        );

        $pdo->exec('DROP TABLE accounts');
        $pdo->exec('ALTER TABLE accounts_migrating RENAME TO accounts');

        // The two pivots the reports already treat as receivables.
        $pdo->exec(
            "UPDATE accounts SET kind = 'receivable', type = 'receivable'
             WHERE LOWER(name) IN ('employee advances', 'loans receivable')"
        );

        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        if ($violations) {
            throw new RuntimeException('Foreign key check failed after rebuilding accounts.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }

    $pdo->exec('PRAGMA legacy_alter_table = OFF');
    $pdo->exec('PRAGMA foreign_keys = ON');
}

// ── 3. Category codes ────────────────────────────────────────────────────────
//
// An accountant reading an export wants a chart-of-accounts code next to each
// line, and needs to know which expenses are claimable. Both are optional and
// default to "no code, deductible" so nothing changes until they are filled in.

function migrateCategoryCodes(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        addColumnIfMissing($pdo, 'categories', 'code',           "TEXT NOT NULL DEFAULT ''");
        addColumnIfMissing($pdo, 'categories', 'tax_deductible', 'INTEGER NOT NULL DEFAULT 1');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 4. Transaction provenance + reconciliation ───────────────────────────────
//
// source/source_ref record what created a row. Payroll postings were previously
// deduplicated by matching counterparty + category + month, which silently
// treats a second legitimate payment in the same month as a duplicate and
// cannot survive an employee being renamed. A stable reference to the payslip
// that produced the row replaces that guesswork.
//
// reconciled lets the bank statement be ticked off line by line, which is the
// first thing a consultant asks for.

function migrateTransactionProvenance(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        addColumnIfMissing($pdo, 'transactions', 'source',        "TEXT NOT NULL DEFAULT ''");
        addColumnIfMissing($pdo, 'transactions', 'source_ref',    "TEXT NOT NULL DEFAULT ''");
        addColumnIfMissing($pdo, 'transactions', 'reconciled',    'INTEGER NOT NULL DEFAULT 0');
        addColumnIfMissing($pdo, 'transactions', 'reconciled_at', 'TEXT NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_source     ON transactions(source, source_ref)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_reconciled ON transactions(reconciled)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 5. Payslips move into the database ───────────────────────────────────────
//
// Payslips lived in history.json, which is truncated to the newest 200 records
// every time one is written. Offer letters share that file and count towards
// the same 200. At roughly a dozen staff that is under a year and a half of
// payroll before the oldest slips start disappearing with no warning — and
// "download every payslip we ever issued" has to be able to reach them.
//
// history.json stays exactly as it is: the Document History screen still reads
// it, and nothing is deleted from it here. This copies the payslips out into a
// table that never truncates.

function migratePayslipsTable(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS payslips (
                id               TEXT PRIMARY KEY,
                employee         TEXT NOT NULL,
                employee_key     TEXT NOT NULL,
                designation      TEXT NOT NULL DEFAULT '',
                period           TEXT NOT NULL,
                basic            REAL NOT NULL DEFAULT 0,
                allowance        REAL NOT NULL DEFAULT 0,
                commission       REAL NOT NULL DEFAULT 0,
                bonus            REAL NOT NULL DEFAULT 0,
                provident_fund   REAL NOT NULL DEFAULT 0,
                eobi             REAL NOT NULL DEFAULT 0,
                professional_tax REAL NOT NULL DEFAULT 0,
                loan             REAL NOT NULL DEFAULT 0,
                absent_late      REAL NOT NULL DEFAULT 0,
                penalty          REAL NOT NULL DEFAULT 0,
                gross            REAL NOT NULL DEFAULT 0,
                deductions       REAL NOT NULL DEFAULT 0,
                net              REAL NOT NULL DEFAULT 0,
                activity_ids     TEXT NOT NULL DEFAULT '[]',
                posted           INTEGER NOT NULL DEFAULT 0,
                posted_at        TEXT NULL,
                voided           INTEGER NOT NULL DEFAULT 0,
                voided_at        TEXT NULL,
                supersedes       TEXT NULL,
                generated_at     TEXT NOT NULL,
                generated_by     TEXT NOT NULL DEFAULT ''
            );

            CREATE INDEX IF NOT EXISTS idx_payslip_period ON payslips(period);
            CREATE INDEX IF NOT EXISTS idx_payslip_emp    ON payslips(employee_key, period);
            CREATE INDEX IF NOT EXISTS idx_payslip_posted ON payslips(posted);
        SQL);

        // One live payslip per employee per month. Superseded slips are voided
        // rather than deleted, so the partial index leaves them alone.
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_payslip_unique
             ON payslips(employee_key, period) WHERE voided = 0'
        );

        importPayslipsFromHistory($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Copy payslip records out of history.json into the payslips table.
 *
 * history.json is newest-first, so the first record seen for an employee/month
 * is the one that counts; later ones are earlier regenerations and are stored
 * voided so the register shows what was actually issued without losing the
 * trail.
 *
 * Each imported slip is matched against the ledger — if a salary expense for
 * that employee and month is already there, the slip is marked posted and the
 * transaction is stamped with its id. Without that, the first run of the new
 * posting code would book every historical salary a second time.
 */
function importPayslipsFromHistory(PDO $pdo): void {
    $path = __DIR__ . '/history.json';
    if (!file_exists($path)) return;

    $history = json_decode((string) file_get_contents($path), true);
    if (!is_array($history)) return;

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO payslips
            (id, employee, employee_key, designation, period,
             basic, allowance, commission, bonus,
             provident_fund, eobi, professional_tax, loan, absent_late, penalty,
             gross, deductions, net, activity_ids,
             posted, posted_at, voided, generated_at, generated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    // An employee's salary expense category, used to spot an existing posting.
    $catByEmp = [];
    foreach ($pdo->query("SELECT id, linked_employee FROM categories
                          WHERE type = 'expense' AND linked_employee <> ''")->fetchAll() as $c) {
        $catByEmp[strtolower(trim((string) $c['linked_employee']))] = $c['id'];
    }

    $findPosting = $pdo->prepare(
        "SELECT id FROM transactions
         WHERE void = 0 AND type = 'expense' AND category_id = ?
           AND LOWER(TRIM(counterparty)) = ? AND substr(date, 1, 7) = ?
         ORDER BY created_at LIMIT 1"
    );
    $stampPosting = $pdo->prepare(
        "UPDATE transactions SET source = 'payroll', source_ref = ? WHERE id = ?"
    );

    $seen = [];
    foreach ($history as $h) {
        if (($h['type'] ?? '') !== 'payslip') continue;

        $name   = trim((string) ($h['employee_name'] ?? ''));
        $period = trim((string) ($h['pay_period'] ?? ''));
        if ($name === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) continue;

        $key      = strtolower($name);
        $slot     = $key . '|' . $period;
        $isLive   = !isset($seen[$slot]);
        $seen[$slot] = true;

        $basic      = (float) ($h['basic_salary']     ?? 0);
        $allowance  = (float) ($h['allowance']        ?? 0);
        $commission = (float) ($h['commission']       ?? 0);
        $bonus      = (float) ($h['performer_bonus']  ?? 0);
        $pf         = (float) ($h['provident_fund']   ?? 0);
        $eobi       = (float) ($h['eobi']             ?? 0);
        $tax        = (float) ($h['professional_tax'] ?? 0);
        $loan       = (float) ($h['loan']             ?? 0);
        $absent     = (float) ($h['absent_late']      ?? 0);
        $penalty    = (float) ($h['penalty']          ?? 0);

        $gross      = $basic + $allowance + $commission + $bonus;
        $deductions = $pf + $eobi + $tax + $loan + $absent + $penalty;

        $activityIds = $h['paid_activity_ids'] ?? [];
        if (!is_array($activityIds)) $activityIds = [];

        // Reuse history's own id so a re-run of this import cannot duplicate.
        $id = (string) ($h['id'] ?? '');
        if ($id === '') $id = 'ps_' . md5($slot . '|' . (string) ($h['generated_at'] ?? ''));

        $posted   = 0;
        $postedAt = null;
        if ($isLive) {
            $catId = $catByEmp[$key] ?? null;
            if ($catId !== null) {
                $findPosting->execute([$catId, $key, $period]);
                $txId = $findPosting->fetchColumn();
                if ($txId) {
                    $posted   = 1;
                    $postedAt = (string) ($h['generated_at'] ?? date('c'));
                    $stampPosting->execute([$id, $txId]);
                }
            }
        }

        $insert->execute([
            $id, $name, $key, trim((string) ($h['designation'] ?? '')), $period,
            $basic, $allowance, $commission, $bonus,
            $pf, $eobi, $tax, $loan, $absent, $penalty,
            $gross, $deductions, $gross - $deductions,
            json_encode(array_values($activityIds)),
            $posted, $postedAt, $isLive ? 0 : 1,
            (string) ($h['generated_at'] ?? date('c')), 'import',
        ]);
    }
}

// ── 6. Recurring rules ───────────────────────────────────────────────────────
//
// Rent, internet, subscriptions — the fixed monthly costs that otherwise get
// remembered late or not at all, which is exactly how a set of books drifts out
// of line with reality. A rule generates its transaction once per period and
// records which period it last generated, so running it twice is harmless.

function migrateRecurringRules(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS recurring_rules (
                id              TEXT PRIMARY KEY,
                name            TEXT NOT NULL,
                book_id         TEXT NOT NULL REFERENCES books(id),
                type            TEXT NOT NULL CHECK(type IN ('income','expense')),
                amount          REAL NOT NULL CHECK(amount > 0),
                currency        TEXT NOT NULL DEFAULT 'PKR',
                account_id      TEXT NOT NULL REFERENCES accounts(id),
                category_id     TEXT NULL REFERENCES categories(id) ON DELETE SET NULL,
                counterparty    TEXT NOT NULL DEFAULT '',
                description     TEXT NOT NULL DEFAULT '',
                day_of_month    INTEGER NOT NULL DEFAULT 1,
                start_period    TEXT NOT NULL,
                end_period      TEXT NULL,
                last_run_period TEXT NULL,
                active          INTEGER NOT NULL DEFAULT 1,
                created_at      TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_recurring_active ON recurring_rules(active);
        SQL);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 7. Settings ──────────────────────────────────────────────────────────────
//
// Fiscal year start, company details for report headers, and the accounts
// payroll posts to. Previously these were either hard-coded or re-picked from a
// dropdown on every run.

function migrateSettingsTable(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        SQL);

        $now  = date('c');
        $seed = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, ?)');
        // Pakistan's tax year runs 1 July – 30 June.
        $seed->execute(['fiscal_year_start_month', '7', $now]);
        $seed->execute(['company_name',    'Erika Media', $now]);
        $seed->execute(['company_address', "Office No. 505, 5th Floor\nKashif Center, Sharah-e-Faisal Karachi", $now]);
        $seed->execute(['company_ntn',     '', $now]);
        $seed->execute(['base_currency',   'PKR', $now]);
        $seed->execute(['payroll_account_id',   '', $now]);
        $seed->execute(['statutory_account_id', '', $now]);
        $seed->execute(['payroll_autopost',     '1', $now]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
