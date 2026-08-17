<?php
// Company details, fiscal year, the accounts payroll posts to — the handful of
// values every report header and posting path reads, which used to be either
// hard-coded or re-picked from a dropdown on every run.
//
// Settings are a key/value table, so the only thing standing between it and a
// bag of typos is the whitelist below: a key that is not in it is rejected
// rather than stored, because a misspelled key would sit in the table looking
// saved while the code carried on reading the correctly spelled one.
//
// Reconciliation lives here too. Ticking a bank statement off line by line is
// not a change to the books — it is a statement about them — so it belongs with
// the settings screen rather than with the transaction editor.
//
// Everything above the last section is a plain function; only the last section
// reads the request and writes a response.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger-lib.php';

const SETTINGS_CURRENCIES = ['PKR', 'USD', 'USDT', 'EUR', 'AED', 'GBP'];

/** The only keys this endpoint will write, with the value assumed when absent. */
const SETTINGS_WRITABLE = [
    'company_name'            => '',
    'company_address'         => '',
    'company_ntn'             => '',
    'base_currency'           => 'PKR',
    'fiscal_year_start_month' => '7',
    'payroll_account_id'      => '',
    'statutory_account_id'    => '',
    'payroll_autopost'        => '1',
];

/** Bulk reconciliation is one statement at a time; anything larger is a bug. */
const SETTINGS_RECONCILE_MAX = 1000;

/**
 * Validation failures travel as exceptions rather than calling jsonError()
 * directly: jsonError() exits, which would make every function below callable
 * only from a live request. The dispatcher turns them back into JSON.
 */
class SettingsError extends RuntimeException {}

// ── Reading ──────────────────────────────────────────────────────────────────

function settingsApiAccountName(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $stmt = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * What payroll would pay from if nobody ever picks an account — the same guess
 * postPayslip() makes, surfaced here so the settings screen can say what will
 * happen rather than leaving the field looking unconfigured.
 */
function settingsApiDefaultPayrollAccount(PDO $pdo): string {
    $row = $pdo->query("SELECT name FROM accounts
                        WHERE archived = 0 AND kind = 'asset' AND type IN ('bank','cash')
                        ORDER BY display_order LIMIT 1")->fetch();
    return (string) ($row['name'] ?? '');
}

function settingsApiRead(PDO $pdo, string $today): array {
    $settings = settingsAll($pdo);
    // A database restored from before the settings migration can be missing
    // keys the UI binds fields to. Fill the gaps rather than hand back a form
    // with holes in it.
    foreach (SETTINGS_WRITABLE as $key => $default) {
        if (!isset($settings[$key])) $settings[$key] = $default;
    }

    $payroll   = settingsApiAccountName($pdo, (string) $settings['payroll_account_id']);
    $payrollIsDefault = $payroll === '';
    if ($payrollIsDefault) $payroll = settingsApiDefaultPayrollAccount($pdo);

    // findAccountByName, not ensurePivotAccount: opening the settings screen
    // must not create an account as a side effect.
    $statutory = settingsApiAccountName($pdo, (string) $settings['statutory_account_id']);
    $statutoryIsDefault = $statutory === '';
    if ($statutoryIsDefault) {
        $account   = findAccountByName($pdo, 'Statutory Payables');
        $statutory = $account ? (string) $account['name'] : '';
    }

    $fy = fiscalYearFor($pdo, $today);
    $fy['start_month'] = (int) $settings['fiscal_year_start_month'];

    return [
        'settings'                   => $settings,
        'fiscal_year'                => $fy,
        'accounts'                   => settingsApiAccounts($pdo),
        'books'                      => settingsApiBooks($pdo),
        'payroll_account'            => $payroll,
        'payroll_account_is_default' => $payrollIsDefault,
        'statutory_account'          => $statutory,
        'statutory_account_is_default' => $statutoryIsDefault,
        'currencies'                 => SETTINGS_CURRENCIES,
        'base_currency'              => baseCurrency($pdo),
        'today'                      => $today,
    ];
}

function settingsApiAccounts(PDO $pdo): array {
    $rows = $pdo->query('SELECT id, name, type, kind, currency, book_id, archived
                         FROM accounts ORDER BY archived, kind, display_order, name')->fetchAll();
    foreach ($rows as &$row) $row['archived'] = (int) $row['archived'];
    unset($row);
    return $rows;
}

function settingsApiBooks(PDO $pdo): array {
    $rows = $pdo->query('SELECT id, name, type, archived FROM books
                         ORDER BY display_order, name')->fetchAll();
    foreach ($rows as &$row) $row['archived'] = (int) $row['archived'];
    unset($row);
    return $rows;
}

// ── Saving ───────────────────────────────────────────────────────────────────

/** The value to store for $key, or a SettingsError explaining why not. */
function settingsApiValidateOne(PDO $pdo, string $key, $value): string {
    if (is_bool($value))  $value = $value ? '1' : '0';
    if ($value === null)  $value = '';
    if (is_array($value)) throw new SettingsError('Setting ' . $key . ' must be a single value.');
    $raw = (string) $value;

    switch ($key) {
        case 'company_name':
            $name = trim($raw);
            // It heads every payslip, invoice and export, so an empty one is a
            // blank letterhead rather than a harmless omission.
            if ($name === '') throw new SettingsError('Company name cannot be empty.');
            return mb_substr($name, 0, 120);

        case 'company_address':
            return mb_substr(trim($raw), 0, 500);

        case 'company_ntn':
            // Deliberately permissive: NTN, STRN and free-text registration
            // numbers all end up in this field depending on who is asking.
            return mb_substr(trim($raw), 0, 40);

        case 'base_currency':
            $currency = strtoupper(trim($raw));
            if (!in_array($currency, SETTINGS_CURRENCIES, true)) {
                throw new SettingsError('Currency must be one of: ' . implode(', ', SETTINGS_CURRENCIES) . '.');
            }
            return $currency;

        case 'fiscal_year_start_month':
            if (!preg_match('/^\d{1,2}$/', trim($raw))) {
                throw new SettingsError('Fiscal year start month must be a number between 1 and 12.');
            }
            $month = (int) trim($raw);
            if ($month < 1 || $month > 12) {
                throw new SettingsError('Fiscal year start month must be between 1 and 12.');
            }
            return (string) $month;

        case 'payroll_account_id':
        case 'statutory_account_id':
            $id = trim($raw);
            if ($id === '') return '';   // clearing it falls back to the default
            $stmt = $pdo->prepare('SELECT archived FROM accounts WHERE id = ?');
            $stmt->execute([$id]);
            $account = $stmt->fetch();
            if (!$account) throw new SettingsError('Account not found: ' . $id);
            if ((int) $account['archived'] === 1) {
                throw new SettingsError('That account is archived. Pick a live account.');
            }
            return $id;

        case 'payroll_autopost':
            $flag = strtolower(trim($raw));
            if (!in_array($flag, ['0', '1', 'true', 'false'], true)) {
                throw new SettingsError('payroll_autopost must be 0 or 1.');
            }
            return in_array($flag, ['1', 'true'], true) ? '1' : '0';
    }

    throw new SettingsError('Unknown setting: ' . $key);
}

/**
 * The key/value pairs a save payload is offering.
 *
 * Accepts either {settings:{…}} or the keys flat on the body, because both
 * shapes turn up in hand-written fetch calls — but action and the CSRF token
 * are stripped rather than treated as settings, and nothing else is forgiven.
 */
function settingsApiPayload(array $in): array {
    if (isset($in['settings'])) {
        if (!is_array($in['settings'])) throw new SettingsError('settings must be an object of key/value pairs.');
        $pairs = $in['settings'];
    } else {
        $pairs = $in;
        unset($pairs['action'], $pairs['_csrf']);
    }
    if (!$pairs) throw new SettingsError('Nothing to save.');
    return $pairs;
}

function settingsApiSave(PDO $pdo, array $in, string $today): array {
    $pairs = settingsApiPayload($in);

    // Validate the whole payload before writing any of it — half-applied
    // settings are worse than rejected ones.
    $clean = [];
    foreach ($pairs as $key => $value) {
        $key = trim((string) $key);
        if (!array_key_exists($key, SETTINGS_WRITABLE)) {
            throw new SettingsError('Unknown setting: ' . $key . '. Allowed: '
                                  . implode(', ', array_keys(SETTINGS_WRITABLE)) . '.');
        }
        $clean[$key] = settingsApiValidateOne($pdo, $key, $value);
    }

    $existing = settingsAll($pdo);
    $before   = [];
    $after    = [];
    foreach ($clean as $key => $value) {
        $old = (string) ($existing[$key] ?? '');
        if ($old === $value) continue;
        $before[$key] = $old;
        $after[$key]  = $value;
    }

    $pdo->beginTransaction();
    try {
        foreach ($after as $key => $value) settingSet($pdo, $key, $value);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    settingsFlush();

    // Nothing changed means nothing to record; an audit trail of no-ops is an
    // audit trail nobody reads.
    if ($after) audit($pdo, 'updated', 'settings', 'settings', $before, $after);

    return ['success' => true, 'changed' => array_keys($after)] + settingsApiRead($pdo, $today);
}

// ── Reconciliation ───────────────────────────────────────────────────────────

/**
 * Tick transactions off against a bank statement.
 *
 * reconciled_at is cleared when un-ticking rather than left behind, so the
 * column always means "when this was agreed with the statement" and never
 * "when it was agreed the last time somebody changed their mind".
 */
function settingsApiReconcile(PDO $pdo, array $in): array {
    $raw = $in['transaction_ids'] ?? null;
    if (!is_array($raw) || !$raw) throw new SettingsError('transaction_ids must be a non-empty array.');
    if (count($raw) > SETTINGS_RECONCILE_MAX) {
        throw new SettingsError('Too many transactions in one go (limit ' . SETTINGS_RECONCILE_MAX . ').');
    }

    $ids = [];
    foreach ($raw as $id) {
        $id = trim((string) $id);
        if ($id !== '') $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) throw new SettingsError('transaction_ids must be a non-empty array.');

    $flag = 1;
    if (array_key_exists('reconciled', $in)) {
        $value = $in['reconciled'];
        if (is_bool($value)) {
            $flag = $value ? 1 : 0;
        } elseif (is_numeric($value)) {
            $flag = ((int) $value) === 0 ? 0 : 1;
        } else {
            $flag = in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true) ? 0 : 1;
        }
    }

    $find  = $pdo->prepare('SELECT id, reconciled, reconciled_at FROM transactions WHERE id = ?');
    $rows  = [];
    $missing = [];
    foreach ($ids as $id) {
        $find->execute([$id]);
        $row = $find->fetch();
        if (!$row) { $missing[] = $id; continue; }
        $rows[] = $row;
    }
    if ($missing) {
        $shown = array_slice($missing, 0, 5);
        throw new SettingsError(
            'Transaction not found: ' . implode(', ', $shown)
            . (count($missing) > 5 ? ' (+' . (count($missing) - 5) . ' more)' : ''),
            404
        );
    }

    $now     = date('c');
    $stamp   = $flag === 1 ? $now : null;
    $changed = [];

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE transactions SET reconciled = ?, reconciled_at = ?, updated_at = ? WHERE id = ?'
        );
        foreach ($rows as $row) {
            if ((int) $row['reconciled'] === $flag) continue;
            $update->execute([$flag, $stamp, $now, $row['id']]);
            audit($pdo, $flag === 1 ? 'reconciled' : 'unreconciled', 'transaction', (string) $row['id'],
                  ['reconciled' => (int) $row['reconciled'], 'reconciled_at' => $row['reconciled_at']],
                  ['reconciled' => $flag, 'reconciled_at' => $stamp]);
            $changed[] = (string) $row['id'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'success'        => true,
        'reconciled'     => $flag,
        'reconciled_at'  => $stamp,
        'updated_count'  => count($changed),
        'updated_ids'    => $changed,
        'unchanged_count'=> count($rows) - count($changed),
    ];
}

// ── Request handling ─────────────────────────────────────────────────────────
//
// Only this section touches the request, the session or the output stream.
// Defining SETTINGS_API_NO_DISPATCH before including the file gives you the
// functions above without any of that — which is how the tests use it.

function settingsApiDispatch(PDO $pdo): void {
    requireLogin();
    $today = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse(settingsApiRead($pdo, $today));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    verifyCsrf();
    $input  = readJsonBody();
    $action = trim((string) ($input['action'] ?? ''));

    try {
        switch ($action) {
            case 'save':
                jsonResponse(settingsApiSave($pdo, $input, $today));
                break;
            case 'reconcile':
                jsonResponse(settingsApiReconcile($pdo, $input));
                break;
        }
    } catch (SettingsError $e) {
        $code = (int) $e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 400);
    }

    jsonError('Invalid action.');
}

if (!defined('SETTINGS_API_NO_DISPATCH')) {
    require_once __DIR__ . '/auth.php';
    settingsApiDispatch(db());
}
