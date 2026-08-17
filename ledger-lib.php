<?php
// The accounting core. Every report, export and posting path goes through here
// so the dashboard, the exports and the tax pack can never disagree about a
// number.
//
// The ledger is a cash book: one row moves one account and hits one P&L
// category, and that single row carries both legs. income/transfer_in raise an
// account, expense/transfer_out lower it. Transfers are written as a linked
// pair so they cancel out and never reach the P&L.
//
// That gives a balancing identity worth stating, because the trial balance
// leans on it: since every income and expense row moves exactly one account,
// and transfer pairs sum to zero,
//
//     sum of all account movements over a period  ==  income - expense
//
// If those two disagree, something is wrong with the data — usually a transfer
// missing its other leg — and trialBalance() reports it rather than papering
// over it.
//
// Liabilities and receivables are ordinary accounts carrying a kind. A
// receivable holds a positive balance for money owed TO the business. A
// liability runs negative, and the amount owed is the negation of its balance.
//
// Pure functions, no output. Safe to require anywhere.

require_once __DIR__ . '/finance-lib.php';

// ── Settings ─────────────────────────────────────────────────────────────────

function settingGet(PDO $pdo, string $key, string $default = ''): string {
    if (!isset($GLOBALS['__settings_cache'])) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT key, value FROM settings')->fetchAll() as $r) {
                $cache[$r['key']] = (string) $r['value'];
            }
        } catch (Throwable $e) {
            $cache = [];   // settings table not migrated yet
        }
        $GLOBALS['__settings_cache'] = $cache;
    }
    return $GLOBALS['__settings_cache'][$key] ?? $default;
}

function settingSet(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
        ->execute([$key, $value, date('c')]);
    settingsFlush();
}

/** settingGet caches for the request; call after any write. */
function settingsFlush(): void {
    unset($GLOBALS['__settings_cache']);
}

function settingsAll(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT key, value FROM settings ORDER BY key')->fetchAll() as $r) {
        $out[$r['key']] = (string) $r['value'];
    }
    return $out;
}

function baseCurrency(PDO $pdo): string {
    return settingGet($pdo, 'base_currency', 'PKR');
}

// ── Fiscal year ──────────────────────────────────────────────────────────────

function fiscalYearStartMonth(PDO $pdo): int {
    $m = (int) settingGet($pdo, 'fiscal_year_start_month', '7');
    return ($m >= 1 && $m <= 12) ? $m : 7;
}

/**
 * The fiscal year containing $date.
 *
 * With a July start (Pakistan), 2025-08-14 sits in the year running
 * 2025-07-01 to 2026-06-30. Pakistan names a tax year after the calendar year
 * it ends in, so that is tax year 2026.
 */
function fiscalYearFor(PDO $pdo, string $date): array {
    $startMonth = fiscalYearStartMonth($pdo);
    $y = (int) substr($date, 0, 4);
    $m = (int) substr($date, 5, 2);

    $startYear = ($m >= $startMonth) ? $y : $y - 1;
    $start = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $end   = date('Y-m-t', strtotime($start . ' +11 months'));

    if ($startMonth === 1) {
        $label = 'FY ' . $startYear;
    } else {
        $label = 'FY ' . $startYear . '-' . substr((string) ($startYear + 1), 2);
    }

    return [
        'start'    => $start,
        'end'      => $end,
        'label'    => $label,
        'tax_year' => $startMonth === 1 ? $startYear : $startYear + 1,
    ];
}

/** Every fiscal year that has activity, newest first. */
function fiscalYearsAvailable(PDO $pdo): array {
    $row = $pdo->query('SELECT MIN(date) AS lo, MAX(date) AS hi FROM transactions WHERE void = 0')->fetch();
    $lo = $row['lo'] ?? date('Y-m-d');
    $hi = $row['hi'] ?? date('Y-m-d');
    if (!$lo) $lo = date('Y-m-d');
    if (!$hi) $hi = date('Y-m-d');

    // Always include the year we are standing in, even with no activity yet.
    $today = date('Y-m-d');
    if ($hi < $today) $hi = $today;

    $years  = [];
    $cursor = fiscalYearFor($pdo, $lo);
    while ($cursor['start'] <= $hi) {
        $years[] = $cursor;
        $next = date('Y-m-d', strtotime($cursor['end'] . ' +1 day'));
        $cursor = fiscalYearFor($pdo, $next);
        if (count($years) > 60) break;   // guard against a nonsense date in the data
    }
    return array_reverse($years);
}

// ── Date ranges ──────────────────────────────────────────────────────────────

function isDate(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}

function isMonth(string $m): bool {
    return (bool) preg_match('/^\d{4}-\d{2}$/', $m) && (int) substr($m, 5, 2) >= 1 && (int) substr($m, 5, 2) <= 12;
}

function monthStart(string $month): string { return $month . '-01'; }
function monthEnd(string $month): string   { return date('Y-m-t', strtotime($month . '-01')); }

/**
 * Turn a preset (or an explicit from/to) into an inclusive date range.
 * Unknown presets fall back to the current fiscal year rather than erroring —
 * a report that quietly shows the wrong period would be worse than one that
 * shows a sensible default and says which period it used.
 */
function resolveRange(PDO $pdo, string $preset, string $from = '', string $to = ''): array {
    $today = date('Y-m-d');

    $mk = function (string $f, string $t, string $label) {
        return ['from' => $f, 'to' => $t, 'label' => $label];
    };

    switch ($preset) {
        case 'custom':
            if (isDate($from) && isDate($to)) {
                if ($from > $to) [$from, $to] = [$to, $from];
                return $mk($from, $to, fmtRangeLabel($from, $to));
            }
            break;

        case 'this_month':
            return $mk(date('Y-m-01'), date('Y-m-t'), date('F Y'));

        case 'last_month':
            $m = date('Y-m', strtotime('first day of last month'));
            return $mk(monthStart($m), monthEnd($m), date('F Y', strtotime($m . '-01')));

        case 'last_3_months':
            $start = date('Y-m-01', strtotime('first day of -2 months'));
            return $mk($start, date('Y-m-t'), 'Last 3 months');

        case 'last_6_months':
            $start = date('Y-m-01', strtotime('first day of -5 months'));
            return $mk($start, date('Y-m-t'), 'Last 6 months');

        case 'last_12_months':
            $start = date('Y-m-01', strtotime('first day of -11 months'));
            return $mk($start, date('Y-m-t'), 'Last 12 months');

        case 'this_quarter':
            $q     = (int) floor(((int) date('n') - 1) / 3);
            $start = date('Y-m-01', mktime(0, 0, 0, $q * 3 + 1, 1, (int) date('Y')));
            $end   = date('Y-m-t', mktime(0, 0, 0, $q * 3 + 3, 1, (int) date('Y')));
            return $mk($start, $end, 'Q' . ($q + 1) . ' ' . date('Y'));

        case 'this_fy':
            $fy = fiscalYearFor($pdo, $today);
            return $mk($fy['start'], $fy['end'], $fy['label']);

        case 'last_fy':
            $fy   = fiscalYearFor($pdo, $today);
            $prev = fiscalYearFor($pdo, date('Y-m-d', strtotime($fy['start'] . ' -1 day')));
            return $mk($prev['start'], $prev['end'], $prev['label']);

        case 'this_calendar_year':
            return $mk(date('Y-01-01'), date('Y-12-31'), date('Y'));

        case 'all_time':
            $row = $pdo->query('SELECT MIN(date) AS lo, MAX(date) AS hi FROM transactions WHERE void = 0')->fetch();
            $lo  = $row['lo'] ?: '2000-01-01';
            $hi  = $row['hi'] ?: $today;
            if ($hi < $today) $hi = $today;
            return $mk($lo, $hi, 'All time');
    }

    // Explicit dates without a preset.
    if (isDate($from) && isDate($to)) {
        if ($from > $to) [$from, $to] = [$to, $from];
        return $mk($from, $to, fmtRangeLabel($from, $to));
    }

    $fy = fiscalYearFor($pdo, $today);
    return $mk($fy['start'], $fy['end'], $fy['label']);
}

function fmtRangeLabel(string $from, string $to): string {
    $f = strtotime($from); $t = strtotime($to);
    if (date('Y-m-d', $f) === date('Y-m-01', $f) && date('Y-m-d', $t) === date('Y-m-t', $t)) {
        if (date('Y-m', $f) === date('Y-m', $t)) return date('F Y', $f);
        return date('M Y', $f) . ' – ' . date('M Y', $t);
    }
    return date('j M Y', $f) . ' – ' . date('j M Y', $t);
}

/** ['2026-01', '2026-02', ...] covering every month the range touches. */
function monthsInRange(string $from, string $to): array {
    $out = [];
    $cur = date('Y-m-01', strtotime($from));
    $end = date('Y-m-01', strtotime($to));
    while ($cur <= $end) {
        $out[] = substr($cur, 0, 7);
        $cur = date('Y-m-01', strtotime($cur . ' +1 month'));
        if (count($out) > 600) break;
    }
    return $out;
}

// ── Book scoping ─────────────────────────────────────────────────────────────

/** Business books only — what the tax consultant ever sees. */
function businessBookIds(PDO $pdo): array {
    return bizBookIds($pdo);
}

function allBookIds(PDO $pdo): array {
    return $pdo->query('SELECT id FROM books WHERE archived = 0')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Turn a book filter into SQL. An empty list means "no restriction" rather
 * than "match nothing" — callers that mean the latter should not call at all.
 */
function bookClause(array $bookIds, string $alias = 't'): array {
    if (!$bookIds) return ['', []];
    $ph = implode(',', array_fill(0, count($bookIds), '?'));
    return [" AND $alias.book_id IN ($ph)", array_values($bookIds)];
}

// ── Profit & loss ────────────────────────────────────────────────────────────

/**
 * Income and expense by category for a period, per currency.
 * Transfers are excluded — moving your own money is not a result.
 */
function plStatement(PDO $pdo, array $bookIds, string $from, string $to): array {
    [$bc, $bargs] = bookClause($bookIds);

    $sql = "SELECT t.type, t.currency,
                   COALESCE(c.id, '')            AS category_id,
                   COALESCE(c.name, '(uncategorised)') AS category,
                   COALESCE(c.code, '')          AS code,
                   COALESCE(c.tax_deductible, 1) AS tax_deductible,
                   COALESCE(c.linked_employee, '') AS linked_employee,
                   SUM(t.amount) AS total,
                   COUNT(*)      AS count
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.void = 0 AND t.type IN ('income','expense')
              AND t.date >= ? AND t.date <= ?$bc
            GROUP BY t.type, t.currency, c.id
            ORDER BY t.type, t.currency, total DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $bargs));
    $rows = $stmt->fetchAll();

    $income = []; $expense = []; $totals = [];
    foreach ($rows as $r) {
        $cur = (string) $r['currency'];
        if (!isset($totals[$cur])) $totals[$cur] = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
        $line = [
            'category_id'     => (string) $r['category_id'],
            'category'        => (string) $r['category'],
            'code'            => (string) $r['code'],
            'currency'        => $cur,
            'total'           => round((float) $r['total'], 2),
            'count'           => (int) $r['count'],
            'tax_deductible'  => (int) $r['tax_deductible'] === 1,
            'linked_employee' => (string) $r['linked_employee'],
        ];
        if ($r['type'] === 'income') {
            $income[] = $line;
            $totals[$cur]['income'] += $line['total'];
        } else {
            $expense[] = $line;
            $totals[$cur]['expense'] += $line['total'];
        }
    }
    foreach ($totals as $cur => $t) {
        $totals[$cur]['income']  = round($t['income'], 2);
        $totals[$cur]['expense'] = round($t['expense'], 2);
        $totals[$cur]['net']     = round($t['income'] - $t['expense'], 2);
    }

    return ['income' => $income, 'expense' => $expense, 'totals' => $totals,
            'from' => $from, 'to' => $to];
}

// ── Account balances and movement ────────────────────────────────────────────

/**
 * Every account's balance as at the close of $asOf, including opening balance.
 *
 * Movement is always filtered to the requested books. The account LIST and the
 * opening balance are filtered too, which is less obvious but matters: an
 * account is a physical container, yet its opening balance belongs to whichever
 * book owns it. Reporting all accounts unfiltered meant a business-only tax
 * pack counted the personal current account's opening balance as company cash.
 *
 * An account earns its place in a book-scoped report by being owned by one of
 * those books, or by having moved money for one of them. The second half of
 * that test is what keeps the trial balance honest — dropping an account that
 * has movement would break the identity the whole report rests on.
 */
function accountBalancesAsOf(PDO $pdo, string $asOf, array $bookIds = []): array {
    [$bc, $bargs] = bookClause($bookIds, 'tx');
    [$bc2, $bargs2] = bookClause($bookIds, 'tc');

    $sql = "SELECT a.id, a.name, a.type, a.kind, a.currency, a.book_id, a.archived,
                   a.opening_balance,
                   COALESCE((SELECT SUM(CASE WHEN tx.type IN ('income','transfer_in')  THEN  tx.amount
                                             WHEN tx.type IN ('expense','transfer_out') THEN -tx.amount
                                             ELSE 0 END)
                             FROM transactions tx
                             WHERE tx.account_id = a.id AND tx.void = 0
                               AND tx.date <= ?$bc), 0) AS movement,
                   (SELECT COUNT(*) FROM transactions tc
                    WHERE tc.account_id = a.id AND tc.void = 0
                      AND tc.date <= ?$bc2) AS tx_count
            FROM accounts a
            ORDER BY a.kind, a.display_order, a.name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$asOf], $bargs, [$asOf], $bargs2));
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $ownedHere = !$bookIds || in_array($r['book_id'], $bookIds, true);
        if ($bookIds && !$ownedHere && (int) $r['tx_count'] === 0) continue;

        // An opening balance sits with the book that owns the account; an
        // account merely passing money through for this book contributes none.
        $r['opening_balance'] = $ownedHere ? round((float) $r['opening_balance'], 2) : 0.0;
        $r['movement']        = round((float) $r['movement'], 2);
        $r['balance']         = round($r['opening_balance'] + $r['movement'], 2);
        $r['archived']        = (int) $r['archived'];
        $r['tx_count']        = (int) $r['tx_count'];
        $out[] = $r;
    }
    return $out;
}

/** Movement per account strictly inside the period (no opening balance). */
function accountMovement(PDO $pdo, string $from, string $to, array $bookIds = []): array {
    [$bc, $bargs] = bookClause($bookIds);

    $sql = "SELECT t.account_id, t.currency,
                   SUM(CASE WHEN t.type IN ('income','transfer_in')   THEN t.amount ELSE 0 END) AS debit,
                   SUM(CASE WHEN t.type IN ('expense','transfer_out') THEN t.amount ELSE 0 END) AS credit
            FROM transactions t
            WHERE t.void = 0 AND t.date >= ? AND t.date <= ?$bc
            GROUP BY t.account_id, t.currency";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $bargs));

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['account_id']] = [
            'debit'  => round((float) $r['debit'], 2),
            'credit' => round((float) $r['credit'], 2),
            'net'    => round((float) $r['debit'] - (float) $r['credit'], 2),
        ];
    }
    return $out;
}

/**
 * Trial balance for a period.
 *
 * The balance-sheet side lists each account's opening balance, movement and
 * closing balance. The P&L side lists income and expense totals. The check
 * that matters — and the reason this report is worth having — is that the
 * total movement across every account must equal the period's net profit.
 * When it does not, a transfer is missing its other leg or a row points at a
 * book outside the filter, and `warnings` says so instead of the report
 * silently not adding up.
 */
function trialBalance(PDO $pdo, array $bookIds, string $from, string $to): array {
    $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));

    $opening = [];
    foreach (accountBalancesAsOf($pdo, $dayBefore, $bookIds) as $a) {
        $opening[$a['id']] = $a;
    }
    $closingRows = accountBalancesAsOf($pdo, $to, $bookIds);
    $movement    = accountMovement($pdo, $from, $to, $bookIds);

    $accounts = [];
    $movementByCurrency = [];
    foreach ($closingRows as $a) {
        $mv   = $movement[$a['id']] ?? ['debit' => 0.0, 'credit' => 0.0, 'net' => 0.0];
        $open = $opening[$a['id']]['balance'] ?? 0.0;

        // Skip accounts that are dormant and empty — noise on the report.
        if (abs($open) < 0.005 && abs($mv['net']) < 0.005 && abs($a['balance']) < 0.005) continue;

        $accounts[] = [
            'id'       => $a['id'],
            'name'     => $a['name'],
            'type'     => $a['type'],
            'kind'     => $a['kind'],
            'currency' => $a['currency'],
            'opening'  => round((float) $open, 2),
            'debit'    => $mv['debit'],
            'credit'   => $mv['credit'],
            'movement' => $mv['net'],
            'closing'  => $a['balance'],
        ];

        $cur = $a['currency'];
        if (!isset($movementByCurrency[$cur])) $movementByCurrency[$cur] = 0.0;
        $movementByCurrency[$cur] += $mv['net'];
    }

    $pl = plStatement($pdo, $bookIds, $from, $to);

    $checks = [];
    $warnings = [];
    $currencies = array_unique(array_merge(array_keys($movementByCurrency), array_keys($pl['totals'])));
    foreach ($currencies as $cur) {
        $accMove = round($movementByCurrency[$cur] ?? 0.0, 2);
        $netPl   = round($pl['totals'][$cur]['net'] ?? 0.0, 2);
        $diff    = round($accMove - $netPl, 2);
        $checks[$cur] = [
            'account_movement' => $accMove,
            'net_profit'       => $netPl,
            'difference'       => $diff,
            'balanced'         => abs($diff) < 0.01,
        ];
        if (abs($diff) >= 0.01) {
            $warnings[] = "$cur: account movement ($accMove) does not match net profit ($netPl); "
                        . 'difference ' . $diff . '. Usually a transfer missing its matching leg.';
        }
    }

    foreach (unpairedTransfers($pdo, $from, $to, $bookIds) as $u) {
        $warnings[] = 'Unpaired transfer on ' . $u['date'] . ' (' . $u['account_name'] . ', '
                    . $u['amount'] . ') — the matching leg is missing or voided.';
    }

    return [
        'from' => $from, 'to' => $to,
        'accounts' => $accounts,
        'pl'       => $pl,
        'checks'   => $checks,
        'warnings' => $warnings,
        'balanced' => count($warnings) === 0,
    ];
}

// ── General ledger ───────────────────────────────────────────────────────────

/**
 * Every transaction in a period with its full context, newest last so a
 * running balance reads top to bottom the way a statement does.
 *
 * $filters: from, to, book_ids[], account_id, category_id, type, counterparty,
 *           search, include_void, source, reconciled ('','0','1'), limit
 */
function generalLedger(PDO $pdo, array $filters): array {
    $from = (string) ($filters['from'] ?? '1900-01-01');
    $to   = (string) ($filters['to']   ?? '2999-12-31');

    $where = ['t.date >= ?', 't.date <= ?'];
    $args  = [$from, $to];

    if (empty($filters['include_void'])) $where[] = 't.void = 0';

    $bookIds = $filters['book_ids'] ?? [];
    if ($bookIds) {
        $where[] = 't.book_id IN (' . implode(',', array_fill(0, count($bookIds), '?')) . ')';
        $args = array_merge($args, array_values($bookIds));
    }
    foreach ([
        'account_id'  => 't.account_id = ?',
        'category_id' => 't.category_id = ?',
        'type'        => 't.type = ?',
        'source'      => 't.source = ?',
    ] as $key => $clause) {
        $v = trim((string) ($filters[$key] ?? ''));
        if ($v !== '') { $where[] = $clause; $args[] = $v; }
    }
    $cp = trim((string) ($filters['counterparty'] ?? ''));
    if ($cp !== '') { $where[] = 'LOWER(t.counterparty) = ?'; $args[] = strtolower($cp); }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(t.description LIKE ? OR t.counterparty LIKE ?)';
        $args[]  = '%' . $search . '%';
        $args[]  = '%' . $search . '%';
    }
    $rec = (string) ($filters['reconciled'] ?? '');
    if ($rec === '0' || $rec === '1') { $where[] = 't.reconciled = ?'; $args[] = (int) $rec; }

    $sql = 'SELECT t.*, b.name AS book_name, a.name AS account_name, a.kind AS account_kind,
                   c.name AS category_name, c.type AS category_type, c.code AS category_code
            FROM transactions t
            JOIN books b    ON b.id = t.book_id
            JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY t.date ASC, t.created_at ASC, t.id ASC';

    $limit = (int) ($filters['limit'] ?? 0);
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $totals = ['income' => 0.0, 'expense' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0];
    foreach ($rows as &$r) {
        $r['amount']  = round((float) $r['amount'], 2);
        $r['signed']  = in_array($r['type'], ['income', 'transfer_in'], true) ? $r['amount'] : -$r['amount'];
        $r['void']    = (int) $r['void'];
        if (!$r['void']) $totals[$r['type']] += $r['amount'];
    }
    unset($r);
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);
    $totals['net'] = round($totals['income'] - $totals['expense'], 2);

    return ['entries' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to];
}

/**
 * One account's statement: opening balance, every movement with a running
 * balance, and the closing balance. This is the report you hand over next to a
 * bank statement to tie the two together.
 */
function accountStatement(PDO $pdo, string $accountId, string $from, string $to, array $bookIds = []): array {
    $acc = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $acc->execute([$accountId]);
    $account = $acc->fetch();
    if (!$account) return ['account' => null, 'entries' => [], 'opening' => 0.0, 'closing' => 0.0];

    $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
    [$bc, $bargs] = bookClause($bookIds, 'tx');

    $openStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN tx.type IN ('income','transfer_in')   THEN  tx.amount
                                  WHEN tx.type IN ('expense','transfer_out') THEN -tx.amount
                                  ELSE 0 END), 0)
         FROM transactions tx
         WHERE tx.account_id = ? AND tx.void = 0 AND tx.date <= ?$bc"
    );
    $openStmt->execute(array_merge([$accountId, $dayBefore], $bargs));
    // Same rule as accountBalancesAsOf: the opening balance belongs to the book
    // that owns the account, so a book-scoped statement of somebody else's
    // account starts from what this book put through it, not from their float.
    $ownedHere = !$bookIds || in_array($account['book_id'], $bookIds, true);
    $opening = round(($ownedHere ? (float) $account['opening_balance'] : 0.0)
                     + (float) $openStmt->fetchColumn(), 2);

    $ledger = generalLedger($pdo, [
        'from' => $from, 'to' => $to, 'account_id' => $accountId, 'book_ids' => $bookIds,
    ]);

    $running = $opening;
    foreach ($ledger['entries'] as &$e) {
        $running = round($running + $e['signed'], 2);
        $e['running_balance'] = $running;
    }
    unset($e);

    return [
        'account'  => $account,
        'opening'  => $opening,
        'closing'  => $running,
        'entries'  => $ledger['entries'],
        'totals'   => $ledger['totals'],
        'from'     => $from, 'to' => $to,
    ];
}

// ── Cash flow ────────────────────────────────────────────────────────────────

/** Income, expense and net for every month the range touches. */
function cashFlowByMonth(PDO $pdo, array $bookIds, string $from, string $to, string $currency = ''): array {
    [$bc, $bargs] = bookClause($bookIds);
    $curClause = $currency !== '' ? ' AND t.currency = ?' : '';
    $curArgs   = $currency !== '' ? [$currency] : [];

    $sql = "SELECT substr(t.date, 1, 7) AS month, t.type, SUM(t.amount) AS total
            FROM transactions t
            WHERE t.void = 0 AND t.type IN ('income','expense')
              AND t.date >= ? AND t.date <= ?$bc$curClause
            GROUP BY month, t.type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $bargs, $curArgs));

    $by = [];
    foreach ($stmt->fetchAll() as $r) {
        $m = (string) $r['month'];
        if (!isset($by[$m])) $by[$m] = ['income' => 0.0, 'expense' => 0.0];
        $by[$m][$r['type']] = round((float) $r['total'], 2);
    }

    $out = [];
    foreach (monthsInRange($from, $to) as $m) {
        $inc = $by[$m]['income']  ?? 0.0;
        $exp = $by[$m]['expense'] ?? 0.0;
        $out[] = [
            'month'   => $m,
            'label'   => date('M Y', strtotime($m . '-01')),
            'income'  => round($inc, 2),
            'expense' => round($exp, 2),
            'net'     => round($inc - $exp, 2),
        ];
    }
    return $out;
}

/**
 * Expense (or income) by category across months — the grid an accountant scans
 * to spot the month a cost jumped.
 */
function categoryMatrix(PDO $pdo, array $bookIds, string $from, string $to, string $type = 'expense', string $currency = ''): array {
    [$bc, $bargs] = bookClause($bookIds);
    $curClause = $currency !== '' ? ' AND t.currency = ?' : '';
    $curArgs   = $currency !== '' ? [$currency] : [];

    $sql = "SELECT COALESCE(c.name, '(uncategorised)') AS category,
                   COALESCE(c.id, '') AS category_id,
                   substr(t.date, 1, 7) AS month,
                   SUM(t.amount) AS total
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.void = 0 AND t.type = ?
              AND t.date >= ? AND t.date <= ?$bc$curClause
            GROUP BY c.id, month";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$type, $from, $to], $bargs, $curArgs));

    $months = monthsInRange($from, $to);
    $rows   = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = (string) $r['category_id'] . '|' . $r['category'];
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'category_id' => (string) $r['category_id'],
                'category'    => (string) $r['category'],
                'by_month'    => array_fill_keys($months, 0.0),
                'total'       => 0.0,
            ];
        }
        $amt = round((float) $r['total'], 2);
        $rows[$key]['by_month'][(string) $r['month']] = $amt;
        $rows[$key]['total'] += $amt;
    }

    $rows = array_values($rows);
    usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
    foreach ($rows as &$r) $r['total'] = round($r['total'], 2);
    unset($r);

    $monthTotals = array_fill_keys($months, 0.0);
    foreach ($rows as $r) {
        foreach ($months as $m) $monthTotals[$m] = round($monthTotals[$m] + $r['by_month'][$m], 2);
    }

    return ['months' => $months, 'rows' => $rows, 'month_totals' => $monthTotals,
            'grand_total' => round(array_sum($monthTotals), 2)];
}

/** Who you were paid by / paid to, biggest first. */
function counterpartySummary(PDO $pdo, array $bookIds, string $from, string $to, string $type): array {
    [$bc, $bargs] = bookClause($bookIds);
    $sql = "SELECT t.counterparty, t.currency, SUM(t.amount) AS total, COUNT(*) AS count,
                   MAX(t.date) AS last_date
            FROM transactions t
            WHERE t.void = 0 AND t.type = ? AND TRIM(t.counterparty) <> ''
              AND t.date >= ? AND t.date <= ?$bc
            GROUP BY LOWER(TRIM(t.counterparty)), t.currency
            ORDER BY total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$type, $from, $to], $bargs));
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['total'] = round((float) $r['total'], 2);
        $r['count'] = (int) $r['count'];
    }
    unset($r);
    return $rows;
}

// ── Data integrity ───────────────────────────────────────────────────────────

/**
 * Transfers whose other leg is missing. A transfer is written as a linked pair;
 * a lone leg means money appeared or vanished, and it will throw out both the
 * trial balance and any account it touches.
 */
function unpairedTransfers(PDO $pdo, string $from, string $to, array $bookIds = []): array {
    [$bc, $bargs] = bookClause($bookIds);
    $sql = "SELECT t.id, t.date, t.type, t.amount, t.linked_tx_id, a.name AS account_name
            FROM transactions t
            JOIN accounts a ON a.id = t.account_id
            WHERE t.void = 0 AND t.type IN ('transfer_in','transfer_out')
              AND t.date >= ? AND t.date <= ?$bc
              AND (t.linked_tx_id IS NULL OR t.linked_tx_id = ''
                   OR (SELECT COUNT(*) FROM transactions o
                       WHERE o.linked_tx_id = t.linked_tx_id AND o.void = 0) < 2)
            ORDER BY t.date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$from, $to], $bargs));
    return $stmt->fetchAll();
}

/**
 * Everything a consultant would query before signing off. Each check returns a
 * count and the offending rows so they can be fixed rather than just counted.
 */
function dataIntegrityChecks(PDO $pdo, array $bookIds, string $from, string $to): array {
    [$bc, $bargs] = bookClause($bookIds);
    $issues = [];

    $unpaired = unpairedTransfers($pdo, $from, $to, $bookIds);
    if ($unpaired) {
        $issues[] = [
            'key'      => 'unpaired_transfers',
            'severity' => 'error',
            'title'    => 'Transfers missing their matching leg',
            'detail'   => 'Money moved into or out of an account with nothing on the other side. '
                        . 'These break the trial balance.',
            'count'    => count($unpaired),
            'rows'     => array_slice($unpaired, 0, 50),
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.date, t.type, t.amount, t.counterparty, t.description, a.name AS account_name
         FROM transactions t JOIN accounts a ON a.id = t.account_id
         WHERE t.void = 0 AND t.type IN ('income','expense') AND t.category_id IS NULL
           AND t.date >= ? AND t.date <= ?$bc
         ORDER BY t.date DESC LIMIT 200"
    );
    $stmt->execute(array_merge([$from, $to], $bargs));
    $uncat = $stmt->fetchAll();
    if ($uncat) {
        $issues[] = [
            'key'      => 'uncategorised',
            'severity' => 'warning',
            'title'    => 'Income or expenses with no category',
            'detail'   => 'These land in "(uncategorised)" on the P&L. Your consultant cannot tell '
                        . 'what they were for, and an undocumented expense is an unclaimable one.',
            'count'    => count($uncat),
            'rows'     => array_slice($uncat, 0, 50),
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.date, t.type, t.amount, t.counterparty, a.name AS account_name
         FROM transactions t JOIN accounts a ON a.id = t.account_id
         WHERE t.void = 0 AND t.date > ?$bc ORDER BY t.date LIMIT 100"
    );
    $stmt->execute(array_merge([date('Y-m-d')], $bargs));
    $future = $stmt->fetchAll();
    if ($future) {
        $issues[] = [
            'key'      => 'future_dated',
            'severity' => 'warning',
            'title'    => 'Transactions dated in the future',
            'detail'   => 'Usually a typo in the year. They inflate the current period and will not '
                        . 'reconcile against a bank statement.',
            'count'    => count($future),
            'rows'     => $future,
        ];
    }

    // Cash and bank accounts cannot actually go negative.
    $negatives = [];
    foreach (accountBalancesAsOf($pdo, $to, $bookIds) as $a) {
        if ($a['kind'] === 'asset' && $a['balance'] < -0.005 && !$a['archived']) {
            $negatives[] = $a;
        }
    }
    if ($negatives) {
        $issues[] = [
            'key'      => 'negative_cash',
            'severity' => 'error',
            'title'    => 'Bank or cash account showing a negative balance',
            'detail'   => 'An account cannot hold less than nothing. Something is missing — usually '
                        . 'an unrecorded deposit or an opening balance that was never set.',
            'count'    => count($negatives),
            'rows'     => $negatives,
        ];
    }

    // Same amount, same counterparty, same day, booked twice.
    $stmt = $pdo->prepare(
        "SELECT t.date, t.amount, t.counterparty, t.type, COUNT(*) AS n, GROUP_CONCAT(t.id) AS ids
         FROM transactions t
         WHERE t.void = 0 AND t.date >= ? AND t.date <= ?$bc AND TRIM(t.counterparty) <> ''
         GROUP BY t.date, t.amount, LOWER(TRIM(t.counterparty)), t.type
         HAVING COUNT(*) > 1
         ORDER BY t.date DESC LIMIT 100"
    );
    $stmt->execute(array_merge([$from, $to], $bargs));
    $dupes = $stmt->fetchAll();
    if ($dupes) {
        $issues[] = [
            'key'      => 'possible_duplicates',
            'severity' => 'info',
            'title'    => 'Possible duplicate entries',
            'detail'   => 'Same date, amount and counterparty booked more than once. Sometimes real '
                        . '(two invoices settled together), sometimes a double entry.',
            'count'    => count($dupes),
            'rows'     => $dupes,
        ];
    }

    return $issues;
}

// ── Pivot accounts ───────────────────────────────────────────────────────────

function findAccountByName(PDO $pdo, string $name): ?array {
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE LOWER(name) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($name))]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Find (or create) one of the special-purpose accounts the payroll flow needs.
 * Creating on demand keeps a first payroll run from failing on a piece of setup
 * nobody was told to do.
 */
function ensurePivotAccount(PDO $pdo, string $name, string $type, string $kind, ?string $bookId, string $notes = ''): array {
    $existing = findAccountByName($pdo, $name);
    if ($existing) return $existing;

    $id    = newId('acc');
    $order = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM accounts')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO accounts (id, name, type, kind, currency, book_id, opening_balance, notes, display_order, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([$id, $name, $type, $kind, baseCurrency($pdo), $bookId, $notes, $order, date('c')]);

    audit($pdo, 'created', 'account', $id, null, ['name' => $name, 'kind' => $kind, 'auto' => true]);
    return findAccountByName($pdo, $name);
}

/** Where PF / EOBI / professional tax withheld from payslips accumulates. */
function statutoryAccount(PDO $pdo, ?string $bookId = null): array {
    if ($bookId === null) {
        $biz = businessBookIds($pdo);
        $bookId = $biz[0] ?? null;
    }
    return ensurePivotAccount(
        $pdo, 'Statutory Payables', 'liability', 'liability', $bookId,
        'Provident fund, EOBI and professional tax withheld from payslips but not yet remitted. '
        . 'Clear it with a transfer from your bank when you actually pay the authority.'
    );
}

function advancesAccount(PDO $pdo, ?string $bookId = null): array {
    if ($bookId === null) {
        $biz = businessBookIds($pdo);
        $bookId = $biz[0] ?? null;
    }
    return ensurePivotAccount(
        $pdo, 'Employee Advances', 'receivable', 'receivable', $bookId,
        'Salary advances handed out and not yet recovered.'
    );
}

/** The business book payroll and reports default to. */
function primaryBusinessBookId(PDO $pdo): ?string {
    $row = $pdo->query("SELECT id FROM books WHERE type = 'business' AND archived = 0
                        ORDER BY display_order LIMIT 1")->fetch();
    return $row['id'] ?? null;
}

// ── Formatting ───────────────────────────────────────────────────────────────

function fmtMoneyPlain(float $amount, string $currency = 'PKR'): string {
    $sym = $currency === 'PKR' ? 'Rs. ' : $currency . ' ';
    return $sym . number_format($amount, 2, '.', ',');
}

function fmtMoneyShort(float $amount, string $currency = 'PKR'): string {
    $sym = $currency === 'PKR' ? 'Rs. ' : $currency . ' ';
    return $sym . number_format($amount, 0, '.', ',');
}
