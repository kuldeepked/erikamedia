<?php
// The accounting reports, read-only. One report per request, and every response
// says which period and which books it is describing.
//
// Nothing here computes a figure. Every number comes out of ledger-lib.php or
// payslip-lib.php, because the screen, the CSV an accountant opens in Excel and
// the tax pack that goes to the consultant all have to agree — the moment a
// report does its own arithmetic, one of the three starts quietly lying.
//
// Two structural decisions worth stating up front:
//
//   Report builders are plain functions taking (PDO, $q, $ctx) and returning an
//   array. Only the dispatch at the very bottom touches $_GET, headers or exit.
//   That is what makes a report's numbers checkable without pretending to be a
//   browser, and it is why the CSV writers consume the same payload the JSON
//   response does: an export cannot disagree with the screen it came from.
//
//   Every payload carries the resolved range, the book ids and a readable book
//   label. These files leave the app — they land in an accountant's inbox as
//   erika-pl-2025-07-01-to-2026-06-30.csv with nothing else around them. A
//   period-scoped number with no period printed beside it is the easiest way
//   there is to hand someone the wrong figure.
//
// GET only. Nothing in this file writes.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger-lib.php';
require_once __DIR__ . '/payslip-lib.php';

requireLogin();

$pdo = db();

// ── Request shape ────────────────────────────────────────────────────────────

/**
 * Normalise a query string into a plain array.
 *
 * Kept separate from the builders so the tax pack — which runs five reports to
 * answer one request — and the tests can call them with a hand-built $q and
 * never go near $_GET.
 */
function acctQuery(array $get): array {
    return [
        'report'   => strtolower(trim((string) ($get['report']   ?? 'overview'))),
        'preset'   => strtolower(trim((string) ($get['preset']   ?? ''))),
        'from'     => trim((string) ($get['from']     ?? '')),
        'to'       => trim((string) ($get['to']       ?? '')),
        'book'     => trim((string) ($get['book']     ?? 'business')),
        'currency' => strtoupper(trim((string) ($get['currency'] ?? ''))),
        'format'   => strtolower(trim((string) ($get['format']   ?? ''))),

        // Report-specific, all optional.
        'account_id'   => trim((string) ($get['account_id']   ?? '')),
        'category_id'  => trim((string) ($get['category_id']  ?? '')),
        'type'         => trim((string) ($get['type']         ?? '')),
        'counterparty' => trim((string) ($get['counterparty'] ?? '')),
        'search'       => trim((string) ($get['search']       ?? '')),
        'source'       => trim((string) ($get['source']       ?? '')),
        'reconciled'   => trim((string) ($get['reconciled']   ?? '')),
        'include_void' => !empty($get['include_void']),
        'limit'        => (int) ($get['limit'] ?? 0),
    ];
}

/**
 * Turn the book filter into ids the ledger functions understand.
 *
 * The empty-list guard is the important part. bookClause() reads an empty list
 * as "no restriction", so a business-scoped request made when no business book
 * exists would fall through and quietly report the personal book as company
 * income. Refusing outright is the only safe answer.
 */
function acctBookScope(PDO $pdo, string $book): array {
    if ($book === '') $book = 'business';

    if ($book === 'all') {
        return ['key' => 'all', 'ids' => [], 'label' => 'All books'];
    }

    if ($book === 'business') {
        $ids = businessBookIds($pdo);
        if (!$ids) {
            throw new InvalidArgumentException(
                'There is no active business book to report on. Create one, or ask for book=all.'
            );
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT name FROM books WHERE id IN ($ph) ORDER BY display_order, name");
        $stmt->execute(array_values($ids));
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return [
            'key'   => 'business',
            'ids'   => array_values($ids),
            'label' => $names ? implode(' + ', $names) : 'Business books',
        ];
    }

    $stmt = $pdo->prepare('SELECT id, name FROM books WHERE id = ?');
    $stmt->execute([$book]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Unknown book: ' . $book);

    return ['key' => (string) $row['id'], 'ids' => [(string) $row['id']], 'label' => (string) $row['name']];
}

/**
 * Resolve the parameters every report shares: period, books, reporting currency.
 *
 * The currency matters more than it looks. Any report that reduces to a single
 * number has to pick one, because adding PKR to USD produces a figure that is
 * wrong in both. Everything below totals in this currency alone and lists the
 * others beside it rather than folding them in.
 */
function acctContext(PDO $pdo, array $q): array {
    return [
        'range'    => resolveRange($pdo, $q['preset'], $q['from'], $q['to']),
        'scope'    => acctBookScope($pdo, $q['book']),
        'currency' => $q['currency'] !== '' ? $q['currency'] : baseCurrency($pdo),
    ];
}

/** The block every JSON response opens with, so a payload is self-describing. */
function acctEnvelope(PDO $pdo, string $report, array $ctx): array {
    return [
        'report'        => $report,
        'range'         => $ctx['range'],
        'book'          => $ctx['scope']['key'],
        'book_ids'      => $ctx['scope']['ids'],
        'book_label'    => $ctx['scope']['label'],
        'currency'      => $ctx['currency'],
        'base_currency' => baseCurrency($pdo),
        'company'       => settingGet($pdo, 'company_name', 'Erika Media'),
        'generated_at'  => date('c'),
    ];
}

/**
 * The period of the same length immediately before this one.
 *
 * Same length in days rather than "the previous calendar month": comparing a
 * 31-day January against a 28-day February would show costs falling 10% for no
 * reason other than the calendar, and someone would act on it.
 */
function acctPreviousRange(array $range): array {
    $days = (int) round((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
    if ($days < 1) $days = 1;

    $to   = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
    $from = date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));

    return ['from' => $from, 'to' => $to, 'label' => fmtRangeLabel($from, $to)];
}

/**
 * Movement between two figures, absolute and percent.
 *
 * A percent change measured from zero is undefined, not infinite: the UI gets
 * null and can say "new" instead of printing a number nobody can defend. The
 * denominator is the absolute previous value so a net swinging from -100 to +50
 * reads as an improvement rather than a 150% fall.
 */
function acctDelta(float $current, float $previous): array {
    $amount = round($current - $previous, 2);
    return [
        'current'   => round($current, 2),
        'previous'  => round($previous, 2),
        'amount'    => $amount,
        'percent'   => abs($previous) < 0.005 ? null : round($amount / abs($previous) * 100, 1),
        'direction' => abs($amount) < 0.005 ? 'flat' : ($amount > 0 ? 'up' : 'down'),
    ];
}

/**
 * Balance-sheet snapshot at $asOf, grouped the way a balance sheet reads.
 *
 * Every account is listed whatever its currency — dropping one would hide real
 * money — but only the reporting currency is added into `total`, with the rest
 * sitting in `by_currency` for anyone who needs them. A liability carries a
 * negative balance, so `owed` is its negation: that is the number a person
 * expects to see next to the word "owed".
 */
function acctBalanceBlock(PDO $pdo, string $asOf, array $bookIds, string $currency): array {
    $kinds = ['asset' => [], 'receivable' => [], 'liability' => [], 'equity' => []];

    foreach (accountBalancesAsOf($pdo, $asOf, $bookIds) as $a) {
        // An archived account with nothing left in it is noise. One that still
        // holds a balance has to be shown whatever its status — the money is
        // real even if nobody uses the account any more.
        if ($a['archived'] && abs((float) $a['balance']) < 0.005) continue;
        $kind = isset($kinds[$a['kind']]) ? (string) $a['kind'] : 'asset';
        $kinds[$kind][] = $a;
    }

    $group = function (array $rows) use ($currency) {
        $byCurrency = [];
        foreach ($rows as $r) {
            $c = (string) $r['currency'];
            if (!isset($byCurrency[$c])) $byCurrency[$c] = 0.0;
            $byCurrency[$c] = round($byCurrency[$c] + (float) $r['balance'], 2);
        }
        return [
            'accounts'    => $rows,
            'total'       => round($byCurrency[$currency] ?? 0.0, 2),
            'by_currency' => $byCurrency,
        ];
    };

    $block = [
        'as_of'       => $asOf,
        'currency'    => $currency,
        'assets'      => $group($kinds['asset']),
        'receivables' => $group($kinds['receivable']),
        'liabilities' => $group($kinds['liability']),
        'equity'      => $group($kinds['equity']),
    ];
    $block['liabilities']['owed'] = round(-$block['liabilities']['total'], 2);
    $block['net_position'] = round(
        $block['assets']['total'] + $block['receivables']['total'] + $block['liabilities']['total'], 2
    );

    return $block;
}

/** Every asset account's balance at the close of $asOf, reporting currency only. */
function acctAssetTotalAsOf(PDO $pdo, string $asOf, array $bookIds, string $currency): float {
    $total = 0.0;
    foreach (accountBalancesAsOf($pdo, $asOf, $bookIds) as $a) {
        if ($a['kind'] === 'asset' && (string) $a['currency'] === $currency) {
            $total += (float) $a['balance'];
        }
    }
    return round($total, 2);
}

/**
 * Per-person position on a pivot account, using reports-api.php's definitions
 * so the two screens can never disagree: money moving INTO the account is what
 * was handed out, money moving OUT is what came back, and an expense booked
 * against it is the part that was written off.
 *
 * Capped at $asOf rather than run over all time, because an outstanding balance
 * only means anything as at a date — a tax pack for last year has to show what
 * was owed at that year end, not what happens to be owed today. For any range
 * ending on or after today the figure is identical to the Team screen's.
 *
 * $fields renames the two transfer legs per account ('given'/'recovered' for
 * advances, 'lent'/'repaid' for loans) so the output reads the way the people
 * using it talk about it.
 */
function acctPivotOutstanding(PDO $pdo, string $accountName, string $asOf, array $bookIds,
                              array $fields, bool $skipUnnamed): array {
    $in  = $fields['in'];
    $out = $fields['out'];

    $accStmt = $pdo->prepare(
        'SELECT id, name, currency FROM accounts WHERE LOWER(name) = ? AND archived = 0 LIMIT 1'
    );
    $accStmt->execute([strtolower($accountName)]);
    $account = $accStmt->fetch();

    $empty = [
        'account' => null, 'currency' => '', 'as_of' => $asOf,
        'people'  => [], 'totals' => null,
    ];
    if (!$account) return $empty;

    [$bc, $bargs] = bookClause($bookIds, 't');
    $stmt = $pdo->prepare(
        "SELECT t.counterparty, t.type, SUM(t.amount) AS total
         FROM transactions t
         WHERE t.account_id = ? AND t.void = 0 AND t.date <= ?$bc
         GROUP BY t.counterparty, t.type"
    );
    $stmt->execute(array_merge([$account['id'], $asOf], $bargs));

    $byPerson = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = trim((string) $r['counterparty']);
        if ($skipUnnamed && $name === '') continue;
        if (!isset($byPerson[$name])) {
            $byPerson[$name] = ['counterparty' => $name, $in => 0.0, $out => 0.0, 'written_off' => 0.0];
        }
        $amt = (float) $r['total'];
        if ($r['type'] === 'transfer_in')  $byPerson[$name][$in]            += $amt;
        if ($r['type'] === 'transfer_out') $byPerson[$name][$out]           += $amt;
        if ($r['type'] === 'expense')      $byPerson[$name]['written_off']  += $amt;
    }

    foreach ($byPerson as &$p) {
        $p['outstanding'] = round($p[$in] - $p[$out] - $p['written_off'], 2);
        foreach ([$in, $out, 'written_off'] as $k) $p[$k] = round($p[$k], 2);
    }
    unset($p);

    // Open positions first, then settled ones alphabetically — the list is read
    // top-down to answer "who still owes us something".
    $people = array_values($byPerson);
    usort($people, function ($a, $b) {
        if ($a['outstanding'] != $b['outstanding']) return $b['outstanding'] <=> $a['outstanding'];
        return strcasecmp($a['counterparty'], $b['counterparty']);
    });

    $totals = [$in => 0.0, $out => 0.0, 'written_off' => 0.0, 'outstanding' => 0.0];
    foreach ($people as $p) {
        foreach (array_keys($totals) as $k) $totals[$k] = round($totals[$k] + (float) $p[$k], 2);
    }

    return [
        'account'  => ['id' => (string) $account['id'], 'name' => (string) $account['name']],
        'currency' => (string) $account['currency'],
        'as_of'    => $asOf,
        'people'   => $people,
        'totals'   => $totals,
    ];
}

// ── Reports ──────────────────────────────────────────────────────────────────

/**
 * The one screen someone opens to answer "how are we doing" — headline result,
 * what is in the accounts, where the money went and what is wrong with the data.
 *
 * The heavy arrays are deliberately left out: the payroll register drops its
 * per-slip list and the integrity checks drop their offending rows. Both are a
 * click away on their own reports, and an overview that takes a second to load
 * stops being an overview.
 */
function acctReportOverview(PDO $pdo, array $q, array $ctx): array {
    $ids  = $ctx['scope']['ids'];
    $from = $ctx['range']['from'];
    $to   = $ctx['range']['to'];
    $cur  = $ctx['currency'];

    $pl   = plStatement($pdo, $ids, $from, $to);
    $head = $pl['totals'][$cur] ?? ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];

    $topExpense = [];
    foreach ($pl['expense'] as $line) {
        if ($line['currency'] === $cur) $topExpense[] = $line;
    }
    usort($topExpense, fn($a, $b) => $b['total'] <=> $a['total']);
    $topExpense = array_slice($topExpense, 0, 5);
    foreach ($topExpense as &$l) {
        $l['share'] = $head['expense'] > 0.005 ? round($l['total'] / $head['expense'] * 100, 1) : null;
    }
    unset($l);

    // counterpartySummary already orders by total descending.
    $topIncome = [];
    foreach (counterpartySummary($pdo, $ids, $from, $to, 'income') as $r) {
        if ((string) $r['currency'] !== $cur) continue;
        $r['share'] = $head['income'] > 0.005 ? round($r['total'] / $head['income'] * 100, 1) : null;
        $topIncome[] = $r;
        if (count($topIncome) === 5) break;
    }

    // Payslips are not book-scoped: payroll only ever belongs to the business,
    // so the register covers the months the range touches regardless of filter.
    $register = payrollRegister($pdo, substr($from, 0, 7), substr($to, 0, 7));

    $issues = [];
    $counts = ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'rows' => 0];
    foreach (dataIntegrityChecks($pdo, $ids, $from, $to) as $i) {
        $issues[] = [
            'key'      => $i['key'],
            'severity' => $i['severity'],
            'title'    => $i['title'],
            'detail'   => $i['detail'],
            'count'    => (int) $i['count'],
        ];
        if (isset($counts[$i['severity']])) $counts[$i['severity']]++;
        $counts['total']++;                    // checks that fired
        $counts['rows'] += (int) $i['count'];  // transactions they name
    }

    return array_merge(acctEnvelope($pdo, 'overview', $ctx), [
        'totals' => [
            'income'           => round((float) $head['income'], 2),
            'expense'          => round((float) $head['expense'], 2),
            'net'              => round((float) $head['net'], 2),
            'currency'         => $cur,
            'by_currency'      => $pl['totals'],
            'other_currencies' => array_values(array_diff(array_keys($pl['totals']), [$cur])),
        ],
        'balances'  => acctBalanceBlock($pdo, $to, $ids, $cur),
        'cash_flow' => cashFlowByMonth($pdo, $ids, $from, $to, $cur),
        'top_expense_categories'    => $topExpense,
        'top_income_counterparties' => $topIncome,
        'payroll' => [
            'from_period'             => $register['from_period'],
            'to_period'               => $register['to_period'],
            'totals'                  => $register['totals'],
            'by_month'                => $register['by_month'],
            'by_employee'             => $register['by_employee'],
            'slip_count'              => count($register['slips']),
            'employee_count'          => count($register['by_employee']),
            'expected_salary_expense' => $register['expected_salary_expense'],
        ],
        'issues'       => $issues,
        'issue_counts' => $counts,
    ]);
}

/**
 * P&L by category, against the period of the same length before it.
 *
 * The comparison is the whole point of the report — a cost line means nothing
 * until you know what it was last time — so `change` is computed per currency
 * and never across them.
 */
function acctReportPl(PDO $pdo, array $q, array $ctx): array {
    $ids  = $ctx['scope']['ids'];
    $cur  = $ctx['currency'];

    $pl        = plStatement($pdo, $ids, $ctx['range']['from'], $ctx['range']['to']);
    $prevRange = acctPreviousRange($ctx['range']);
    $prev      = plStatement($pdo, $ids, $prevRange['from'], $prevRange['to']);

    $blank  = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    $change = [];
    // The reporting currency is seeded first so change[currency] always exists,
    // even for a period with no activity at all on either side. A UI that has
    // to check for the key before reading it will eventually forget to.
    $currencies = array_unique(array_merge(
        [$cur], array_keys($pl['totals']), array_keys($prev['totals'])
    ));
    foreach ($currencies as $c) {
        $now = $pl['totals'][$c]   ?? $blank;
        $was = $prev['totals'][$c] ?? $blank;
        $change[$c] = [
            'income'  => acctDelta((float) $now['income'],  (float) $was['income']),
            'expense' => acctDelta((float) $now['expense'], (float) $was['expense']),
            'net'     => acctDelta((float) $now['net'],     (float) $was['net']),
        ];
    }

    $head     = $pl['totals'][$cur]   ?? $blank;
    $headPrev = $prev['totals'][$cur] ?? $blank;

    return array_merge(acctEnvelope($pdo, 'pl', $ctx), [
        'income'  => $pl['income'],
        'expense' => $pl['expense'],
        'totals'  => $pl['totals'],
        // The same three numbers in the reporting currency, so the UI does not
        // have to reach into totals[currency] and cope with it being absent.
        'headline' => [
            'income'  => round((float) $head['income'], 2),
            'expense' => round((float) $head['expense'], 2),
            'net'     => round((float) $head['net'], 2),
        ],
        'previous' => [
            'range'    => $prevRange,
            'income'   => $prev['income'],
            'expense'  => $prev['expense'],
            'totals'   => $prev['totals'],
            'headline' => [
                'income'  => round((float) $headPrev['income'], 2),
                'expense' => round((float) $headPrev['expense'], 2),
                'net'     => round((float) $headPrev['net'], 2),
            ],
        ],
        'change' => $change,
    ]);
}

function acctReportTrialBalance(PDO $pdo, array $q, array $ctx): array {
    return array_merge(
        acctEnvelope($pdo, 'trial_balance', $ctx),
        trialBalance($pdo, $ctx['scope']['ids'], $ctx['range']['from'], $ctx['range']['to'])
    );
}

function acctReportLedger(PDO $pdo, array $q, array $ctx): array {
    $filters = [
        'from'         => $ctx['range']['from'],
        'to'           => $ctx['range']['to'],
        'book_ids'     => $ctx['scope']['ids'],
        'account_id'   => $q['account_id'],
        'category_id'  => $q['category_id'],
        'type'         => $q['type'],
        'counterparty' => $q['counterparty'],
        'search'       => $q['search'],
        'include_void' => $q['include_void'],
        'source'       => $q['source'],
        'reconciled'   => $q['reconciled'],
        'limit'        => $q['limit'],
    ];
    $ledger = generalLedger($pdo, $filters);

    return array_merge(acctEnvelope($pdo, 'ledger', $ctx), [
        'entries' => $ledger['entries'],
        'totals'  => $ledger['totals'],
        'count'   => count($ledger['entries']),
        // Echoed back so a UI showing 40 of 4000 rows knows it asked for 40.
        'filters' => $filters,
    ]);
}

function acctReportAccountStatement(PDO $pdo, array $q, array $ctx): array {
    if ($q['account_id'] === '') {
        throw new InvalidArgumentException('account_id is required for an account statement.');
    }

    $st = accountStatement(
        $pdo, $q['account_id'], $ctx['range']['from'], $ctx['range']['to'], $ctx['scope']['ids']
    );
    if ($st['account'] === null) {
        throw new RuntimeException('Account not found: ' . $q['account_id']);
    }

    return array_merge(acctEnvelope($pdo, 'account_statement', $ctx), [
        'account' => $st['account'],
        'opening' => $st['opening'],
        'closing' => $st['closing'],
        'entries' => $st['entries'],
        'totals'  => $st['totals'],
        'count'   => count($st['entries']),
    ]);
}

/**
 * Income and expense per month with the closing cash position beside them.
 *
 * The closing balance for the last month is capped at the end of the range, not
 * the end of the calendar month: a range ending mid-month would otherwise show
 * a balance that includes transactions dated after the period it claims to
 * cover. Earlier months are not capped at the range start on purpose — a
 * closing balance is cumulative from the beginning of the books by definition.
 */
function acctReportCashFlow(PDO $pdo, array $q, array $ctx): array {
    $ids  = $ctx['scope']['ids'];
    $from = $ctx['range']['from'];
    $to   = $ctx['range']['to'];
    $cur  = $ctx['currency'];

    $months = cashFlowByMonth($pdo, $ids, $from, $to, $cur);
    $totals = ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];

    foreach ($months as &$m) {
        $asOf = monthEnd($m['month']);
        if ($asOf > $to) $asOf = $to;
        $m['as_of']           = $asOf;
        $m['closing_balance'] = acctAssetTotalAsOf($pdo, $asOf, $ids, $cur);

        $totals['income']  = round($totals['income']  + $m['income'], 2);
        $totals['expense'] = round($totals['expense'] + $m['expense'], 2);
        $totals['net']     = round($totals['net']     + $m['net'], 2);
    }
    unset($m);

    return array_merge(acctEnvelope($pdo, 'cash_flow', $ctx), [
        'months'          => $months,
        'totals'          => $totals,
        'opening_balance' => acctAssetTotalAsOf($pdo, date('Y-m-d', strtotime($from . ' -1 day')), $ids, $cur),
        'closing_balance' => acctAssetTotalAsOf($pdo, $to, $ids, $cur),
    ]);
}

function acctReportCategoryMatrix(PDO $pdo, array $q, array $ctx): array {
    $type   = $q['type'] === 'income' ? 'income' : 'expense';
    $matrix = categoryMatrix(
        $pdo, $ctx['scope']['ids'], $ctx['range']['from'], $ctx['range']['to'], $type, $ctx['currency']
    );

    return array_merge(acctEnvelope($pdo, 'expense_matrix', $ctx), [
        'type'         => $type,
        'months'       => $matrix['months'],
        'rows'         => $matrix['rows'],
        'month_totals' => $matrix['month_totals'],
        'grand_total'  => $matrix['grand_total'],
    ]);
}

function acctReportCounterparties(PDO $pdo, array $q, array $ctx): array {
    $ids  = $ctx['scope']['ids'];
    $from = $ctx['range']['from'];
    $to   = $ctx['range']['to'];
    $cur  = $ctx['currency'];

    $income  = counterpartySummary($pdo, $ids, $from, $to, 'income');
    $expense = counterpartySummary($pdo, $ids, $from, $to, 'expense');

    $sum = function (array $rows) use ($cur) {
        $t = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['currency'] === $cur) $t += (float) $r['total'];
        }
        return round($t, 2);
    };

    return array_merge(acctEnvelope($pdo, 'counterparties', $ctx), [
        'income'  => $income,
        'expense' => $expense,
        // Reporting currency only; rows in other currencies stay in the lists.
        'totals'  => ['income' => $sum($income), 'expense' => $sum($expense)],
    ]);
}

function acctReportPayrollRegister(PDO $pdo, array $q, array $ctx): array {
    $fromPeriod = substr($ctx['range']['from'], 0, 7);
    $toPeriod   = substr($ctx['range']['to'], 0, 7);

    $register = payrollRegister($pdo, $fromPeriod, $toPeriod);

    return array_merge(acctEnvelope($pdo, 'payroll_register', $ctx), [
        'from_period'             => $fromPeriod,
        'to_period'               => $toPeriod,
        'by_month'                => $register['by_month'],
        'by_employee'             => $register['by_employee'],
        'slips'                   => $register['slips'],
        'totals'                  => $register['totals'],
        'expected_salary_expense' => $register['expected_salary_expense'],
        'reconciliation'          => payrollReconciliation($pdo, $fromPeriod, $toPeriod),
    ]);
}

/**
 * Who still owes the business money, and how much.
 *
 * Book-scoped, unlike the Team screen's version: a personal loan to a friend
 * has no business being in the pack that goes to the company's tax consultant,
 * and the default scope is the business books.
 */
function acctReportAdvances(PDO $pdo, array $q, array $ctx): array {
    $asOf = $ctx['range']['to'];
    $ids  = $ctx['scope']['ids'];

    return array_merge(acctEnvelope($pdo, 'advances', $ctx), [
        'employee_advances' => acctPivotOutstanding(
            $pdo, 'Employee Advances', $asOf, $ids, ['in' => 'given', 'out' => 'recovered'], true
        ),
        // Loans keep unnamed rows: a loan with no counterparty recorded is
        // exactly the kind of thing this report exists to surface.
        'loans_receivable'  => acctPivotOutstanding(
            $pdo, 'Loans Receivable', $asOf, $ids, ['in' => 'lent', 'out' => 'repaid'], false
        ),
    ]);
}

function acctReportIssues(PDO $pdo, array $q, array $ctx): array {
    $issues = dataIntegrityChecks($pdo, $ctx['scope']['ids'], $ctx['range']['from'], $ctx['range']['to']);

    $counts = ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'rows' => 0];
    foreach ($issues as $i) {
        if (isset($counts[$i['severity']])) $counts[$i['severity']]++;
        $counts['total']++;
        $counts['rows'] += (int) $i['count'];
    }

    return array_merge(acctEnvelope($pdo, 'issues', $ctx), [
        'issues' => $issues,
        'counts' => $counts,
    ]);
}

function acctReportFiscalYears(PDO $pdo, array $q, array $ctx): array {
    return array_merge(acctEnvelope($pdo, 'fiscal_years', $ctx), [
        'years'       => fiscalYearsAvailable($pdo),
        'current'     => fiscalYearFor($pdo, date('Y-m-d')),
        'start_month' => fiscalYearStartMonth($pdo),
    ]);
}

/**
 * Everything the tax consultant is sent, in one file.
 *
 * Built by running the real reports rather than re-querying, so the pack cannot
 * say something different from the screen it was exported from. The integrity
 * issues go in last and on purpose: the consultant should see what is known to
 * be wrong with the data before signing anything.
 */
function acctReportTaxPack(PDO $pdo, array $q, array $ctx): array {
    return array_merge(acctEnvelope($pdo, 'tax_pack', $ctx), [
        'sections' => [
            'pl'               => acctReportPl($pdo, $q, $ctx),
            'trial_balance'    => acctReportTrialBalance($pdo, $q, $ctx),
            'payroll_register' => acctReportPayrollRegister($pdo, $q, $ctx),
            'balances'         => acctBalanceBlock($pdo, $ctx['range']['to'], $ctx['scope']['ids'], $ctx['currency']),
            'issues'           => acctReportIssues($pdo, $q, $ctx),
        ],
    ]);
}

// ── CSV ──────────────────────────────────────────────────────────────────────

/** Reports that can be downloaded as a file. */
function acctCsvReports(): array {
    return ['pl', 'trial_balance', 'ledger', 'account_statement', 'cash_flow',
            'expense_matrix', 'payroll_register', 'counterparties', 'advances',
            'issues', 'tax_pack'];
}

function acctReportTitle(string $report, array $payload): string {
    switch ($report) {
        case 'overview':          return 'Overview';
        case 'pl':                return 'Profit & Loss by category';
        case 'trial_balance':     return 'Trial balance';
        case 'ledger':            return 'General ledger';
        case 'account_statement': return 'Account statement — ' . (string) ($payload['account']['name'] ?? '');
        case 'cash_flow':         return 'Cash flow by month';
        case 'expense_matrix':    return (($payload['type'] ?? 'expense') === 'income' ? 'Income' : 'Expense')
                                       . ' by category and month';
        case 'counterparties':    return 'Counterparties';
        case 'payroll_register':  return 'Payroll register';
        case 'advances':          return 'Advances and loans outstanding';
        case 'issues':            return 'Data integrity issues';
        case 'fiscal_years':      return 'Fiscal years';
        case 'tax_pack':          return 'Tax pack';
    }
    return $report;
}

function acctCsvFilename(string $report, array $range): string {
    return 'erika-' . $report . '-' . $range['from'] . '-to-' . $range['to'] . '.csv';
}

/** Two decimals, no thousands separator — a grouped number is text to Excel. */
function acctCsvMoney($value): string {
    return number_format((float) $value, 2, '.', '');
}

function acctCsvSection($fp, string $title): void {
    fputcsv($fp, [$title]);
}

/**
 * The block every export opens with.
 *
 * An accountant receives these as loose attachments months after they were
 * made. Without the company, the report name, the period and the book at the
 * top, a file called "erika-pl-…" is a column of numbers that could belong to
 * anything.
 */
function acctCsvHeaderBlock($fp, PDO $pdo, string $title, array $payload): void {
    fputcsv($fp, [settingGet($pdo, 'company_name', 'Erika Media')]);

    $ntn = settingGet($pdo, 'company_ntn', '');
    if ($ntn !== '') fputcsv($fp, ['NTN', $ntn]);

    fputcsv($fp, [$title]);
    fputcsv($fp, ['Period', (string) $payload['range']['label'],
                  $payload['range']['from'] . ' to ' . $payload['range']['to']]);
    fputcsv($fp, ['Book', (string) $payload['book_label']]);
    fputcsv($fp, ['Currency', (string) $payload['currency']]);
    fputcsv($fp, ['Generated', date('Y-m-d H:i')]);
    fputcsv($fp, []);
}

function acctCsvBodyPl($fp, array $p): void {
    foreach (['income' => 'INCOME', 'expense' => 'EXPENSE'] as $side => $heading) {
        acctCsvSection($fp, $heading);
        fputcsv($fp, ['Code', 'Category', 'Currency', 'Amount', 'Entries', 'Tax deductible']);
        foreach ($p[$side] as $l) {
            fputcsv($fp, [
                $l['code'], $l['category'], $l['currency'],
                acctCsvMoney($l['total']), $l['count'], $l['tax_deductible'] ? 'yes' : 'no',
            ]);
        }
        fputcsv($fp, ['', 'Total ' . $side, (string) $p['currency'], acctCsvMoney($p['headline'][$side])]);
        fputcsv($fp, []);
    }

    fputcsv($fp, ['', 'NET', (string) $p['currency'], acctCsvMoney($p['headline']['net'])]);
    fputcsv($fp, []);

    acctCsvSection($fp, 'MOVEMENT vs ' . (string) $p['previous']['range']['label']);
    fputcsv($fp, ['Currency', 'Measure', 'This period', 'Previous', 'Change', 'Change %']);
    foreach ($p['change'] as $currency => $measures) {
        foreach (['income', 'expense', 'net'] as $k) {
            $d = $measures[$k];
            fputcsv($fp, [
                $currency, ucfirst($k),
                acctCsvMoney($d['current']), acctCsvMoney($d['previous']), acctCsvMoney($d['amount']),
                $d['percent'] === null ? 'n/a' : $d['percent'],
            ]);
        }
    }
}

function acctCsvBodyTrialBalance($fp, array $p): void {
    fputcsv($fp, ['Account', 'Type', 'Kind', 'Currency', 'Opening', 'Debit', 'Credit', 'Movement', 'Closing']);
    foreach ($p['accounts'] as $a) {
        fputcsv($fp, [
            $a['name'], $a['type'], $a['kind'], $a['currency'],
            acctCsvMoney($a['opening']), acctCsvMoney($a['debit']), acctCsvMoney($a['credit']),
            acctCsvMoney($a['movement']), acctCsvMoney($a['closing']),
        ]);
    }

    fputcsv($fp, []);
    acctCsvSection($fp, 'BALANCE CHECK — total account movement must equal net profit');
    fputcsv($fp, ['Currency', 'Account movement', 'Net profit', 'Difference', 'Balanced']);
    foreach ($p['checks'] as $currency => $c) {
        fputcsv($fp, [
            $currency, acctCsvMoney($c['account_movement']), acctCsvMoney($c['net_profit']),
            acctCsvMoney($c['difference']), $c['balanced'] ? 'yes' : 'no',
        ]);
    }

    if (!empty($p['warnings'])) {
        fputcsv($fp, []);
        acctCsvSection($fp, 'WARNINGS');
        foreach ($p['warnings'] as $w) fputcsv($fp, [$w]);
    }
}

function acctCsvBodyLedger($fp, array $p): void {
    fputcsv($fp, ['Date', 'Book', 'Type', 'Account', 'Currency', 'Code', 'Category',
                  'Counterparty', 'Description', 'Amount', 'Signed', 'Source', 'Reconciled', 'Void']);
    foreach ($p['entries'] as $e) {
        fputcsv($fp, [
            $e['date'], $e['book_name'], $e['type'], $e['account_name'], $e['currency'],
            (string) ($e['category_code'] ?? ''), (string) ($e['category_name'] ?? ''),
            $e['counterparty'], $e['description'],
            acctCsvMoney($e['amount']), acctCsvMoney($e['signed']),
            (string) ($e['source'] ?? ''),
            empty($e['reconciled']) ? 'no' : 'yes',
            empty($e['void']) ? '' : 'VOID',
        ]);
    }

    fputcsv($fp, []);
    acctCsvSection($fp, 'TOTALS');
    fputcsv($fp, ['Income',        acctCsvMoney($p['totals']['income'])]);
    fputcsv($fp, ['Expense',       acctCsvMoney($p['totals']['expense'])]);
    fputcsv($fp, ['Transfers in',  acctCsvMoney($p['totals']['transfer_in'])]);
    fputcsv($fp, ['Transfers out', acctCsvMoney($p['totals']['transfer_out'])]);
    fputcsv($fp, ['Net',           acctCsvMoney($p['totals']['net'])]);
}

function acctCsvBodyAccountStatement($fp, array $p): void {
    $a = $p['account'];
    fputcsv($fp, ['Account', (string) $a['name'], (string) $a['type'], (string) $a['currency']]);
    fputcsv($fp, ['Opening balance', acctCsvMoney($p['opening'])]);
    fputcsv($fp, []);

    fputcsv($fp, ['Date', 'Type', 'Category', 'Counterparty', 'Description', 'In', 'Out', 'Balance', 'Reconciled']);
    foreach ($p['entries'] as $e) {
        $in  = $e['signed'] > 0 ? $e['amount'] : 0.0;
        $out = $e['signed'] < 0 ? $e['amount'] : 0.0;
        fputcsv($fp, [
            $e['date'], $e['type'], (string) ($e['category_name'] ?? ''),
            $e['counterparty'], $e['description'],
            acctCsvMoney($in), acctCsvMoney($out), acctCsvMoney($e['running_balance']),
            empty($e['reconciled']) ? 'no' : 'yes',
        ]);
    }

    fputcsv($fp, []);
    fputcsv($fp, ['Closing balance', acctCsvMoney($p['closing'])]);
}

function acctCsvBodyCashFlow($fp, array $p): void {
    fputcsv($fp, ['Month', 'Income', 'Expense', 'Net', 'Closing cash and bank']);
    foreach ($p['months'] as $m) {
        fputcsv($fp, [
            $m['label'], acctCsvMoney($m['income']), acctCsvMoney($m['expense']),
            acctCsvMoney($m['net']), acctCsvMoney($m['closing_balance']),
        ]);
    }
    fputcsv($fp, [
        'Total', acctCsvMoney($p['totals']['income']), acctCsvMoney($p['totals']['expense']),
        acctCsvMoney($p['totals']['net']), acctCsvMoney($p['closing_balance']),
    ]);
    fputcsv($fp, []);
    fputcsv($fp, ['Opening cash and bank', acctCsvMoney($p['opening_balance'])]);
}

function acctCsvBodyMatrix($fp, array $p): void {
    fputcsv($fp, array_merge(['Category'], $p['months'], ['Total']));
    foreach ($p['rows'] as $r) {
        $line = [$r['category']];
        foreach ($p['months'] as $m) $line[] = acctCsvMoney($r['by_month'][$m] ?? 0);
        $line[] = acctCsvMoney($r['total']);
        fputcsv($fp, $line);
    }
    $line = ['Total'];
    foreach ($p['months'] as $m) $line[] = acctCsvMoney($p['month_totals'][$m] ?? 0);
    $line[] = acctCsvMoney($p['grand_total']);
    fputcsv($fp, $line);
}

function acctCsvBodyCounterparties($fp, array $p): void {
    foreach (['income' => 'PAID BY', 'expense' => 'PAID TO'] as $side => $heading) {
        acctCsvSection($fp, $heading);
        fputcsv($fp, ['Counterparty', 'Currency', 'Total', 'Entries', 'Last entry']);
        foreach ($p[$side] as $r) {
            fputcsv($fp, [
                $r['counterparty'], $r['currency'], acctCsvMoney($r['total']),
                $r['count'], (string) ($r['last_date'] ?? ''),
            ]);
        }
        fputcsv($fp, ['Total', (string) $p['currency'], acctCsvMoney($p['totals'][$side])]);
        fputcsv($fp, []);
    }
}

/** The payroll columns, in the order a payslip reads them. */
function acctPayrollColumns(): array {
    return ['basic' => 'Basic', 'allowance' => 'Allowance', 'commission' => 'Commission',
            'bonus' => 'Bonus', 'gross' => 'Gross', 'provident_fund' => 'Provident fund',
            'eobi' => 'EOBI', 'professional_tax' => 'Professional tax',
            'loan' => 'Advance recovered', 'absent_late' => 'Absent/late', 'penalty' => 'Penalty',
            'deductions' => 'Deductions', 'net' => 'Net'];
}

function acctCsvBodyPayrollMonths($fp, array $p): void {
    $cols = acctPayrollColumns();
    fputcsv($fp, array_merge(['Month', 'Slips'], array_values($cols)));
    foreach ($p['by_month'] as $m) {
        $line = [$m['label'], $m['count']];
        foreach (array_keys($cols) as $k) $line[] = acctCsvMoney($m[$k]);
        fputcsv($fp, $line);
    }
    $line = ['Total', $p['totals']['count']];
    foreach (array_keys($cols) as $k) $line[] = acctCsvMoney($p['totals'][$k]);
    fputcsv($fp, $line);
}

function acctCsvBodyPayrollRegister($fp, array $p): void {
    $cols = acctPayrollColumns();

    acctCsvSection($fp, 'BY MONTH');
    acctCsvBodyPayrollMonths($fp, $p);
    fputcsv($fp, []);

    acctCsvSection($fp, 'BY EMPLOYEE');
    fputcsv($fp, array_merge(['Employee', 'Slips'], array_values($cols)));
    foreach ($p['by_employee'] as $e) {
        $line = [$e['employee'], $e['count']];
        foreach (array_keys($cols) as $k) $line[] = acctCsvMoney($e[$k]);
        fputcsv($fp, $line);
    }
    fputcsv($fp, []);

    acctCsvSection($fp, 'RECONCILIATION AGAINST THE LEDGER');
    $r = $p['reconciliation'];
    fputcsv($fp, ['Expected from payslips', acctCsvMoney($r['expected_from_payslips'])]);
    fputcsv($fp, ['In the ledger',          acctCsvMoney($r['in_ledger'])]);
    fputcsv($fp, ['Difference',             acctCsvMoney($r['difference'])]);
    fputcsv($fp, ['Matches',                $r['matches'] ? 'yes' : 'no']);
    fputcsv($fp, ['Payslips never posted',  $r['unposted_count']]);
}

function acctCsvBodyPivot($fp, array $block, string $inLabel, string $outLabel): void {
    if ($block['account'] === null) {
        fputcsv($fp, ['No such account in these books.']);
        return;
    }
    $in  = strtolower($inLabel);
    $out = strtolower($outLabel);

    fputcsv($fp, ['Counterparty', $inLabel, $outLabel, 'Written off', 'Outstanding', 'Currency']);
    foreach ($block['people'] as $p) {
        fputcsv($fp, [
            $p['counterparty'], acctCsvMoney($p[$in]), acctCsvMoney($p[$out]),
            acctCsvMoney($p['written_off']), acctCsvMoney($p['outstanding']), $block['currency'],
        ]);
    }
    fputcsv($fp, [
        'Total', acctCsvMoney($block['totals'][$in]), acctCsvMoney($block['totals'][$out]),
        acctCsvMoney($block['totals']['written_off']), acctCsvMoney($block['totals']['outstanding']),
        $block['currency'],
    ]);
}

function acctCsvBodyAdvances($fp, array $p): void {
    acctCsvSection($fp, 'EMPLOYEE ADVANCES — outstanding at ' . $p['range']['to']);
    acctCsvBodyPivot($fp, $p['employee_advances'], 'Given', 'Recovered');
    fputcsv($fp, []);

    acctCsvSection($fp, 'LOANS RECEIVABLE — outstanding at ' . $p['range']['to']);
    acctCsvBodyPivot($fp, $p['loans_receivable'], 'Lent', 'Repaid');
}

function acctCsvBodyBalances($fp, array $block): void {
    fputcsv($fp, ['Account', 'Kind', 'Type', 'Currency', 'Opening', 'Movement', 'Closing']);
    foreach (['assets' => 'Assets', 'receivables' => 'Receivables',
              'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label) {
        foreach ($block[$key]['accounts'] as $a) {
            fputcsv($fp, [
                $a['name'], $a['kind'], $a['type'], $a['currency'],
                acctCsvMoney($a['opening_balance']), acctCsvMoney($a['movement']), acctCsvMoney($a['balance']),
            ]);
        }
        fputcsv($fp, ['Total ' . $label, '', '', $block['currency'], '', '', acctCsvMoney($block[$key]['total'])]);
    }
    fputcsv($fp, []);
    fputcsv($fp, ['Owed on liabilities', '', '', $block['currency'], '', '', acctCsvMoney($block['liabilities']['owed'])]);
    fputcsv($fp, ['Net position',        '', '', $block['currency'], '', '', acctCsvMoney($block['net_position'])]);
}

/**
 * The integrity checks and the rows behind them.
 *
 * Each check returns a different row shape — an unpaired transfer and a
 * negative cash balance have almost nothing in common — so the columns are
 * taken from the rows themselves rather than forced into one layout that would
 * be mostly empty for every check.
 */
function acctCsvBodyIssues($fp, array $issues): void {
    if (!$issues) {
        fputcsv($fp, ['No issues found.']);
        return;
    }

    fputcsv($fp, ['Severity', 'Check', 'Count', 'Detail']);
    foreach ($issues as $i) {
        fputcsv($fp, [$i['severity'], $i['title'], $i['count'], $i['detail']]);
    }

    foreach ($issues as $i) {
        $rows = $i['rows'] ?? [];
        if (!$rows) continue;
        fputcsv($fp, []);
        acctCsvSection($fp, strtoupper((string) $i['severity']) . ': ' . $i['title']);

        $first = reset($rows);
        $cols  = array_keys($first);
        fputcsv($fp, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = $r[$c] ?? '';
                $line[] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            fputcsv($fp, $line);
        }
    }
}

/** The single file that goes to the tax consultant. */
function acctCsvTaxPack($fp, PDO $pdo, array $p): void {
    acctCsvHeaderBlock($fp, $pdo, 'Tax pack', $p);

    acctCsvSection($fp, '1. PROFIT & LOSS BY CATEGORY');
    acctCsvBodyPl($fp, $p['sections']['pl']);
    fputcsv($fp, []);

    acctCsvSection($fp, '2. TRIAL BALANCE');
    acctCsvBodyTrialBalance($fp, $p['sections']['trial_balance']);
    fputcsv($fp, []);

    acctCsvSection($fp, '3. PAYROLL REGISTER BY MONTH');
    acctCsvBodyPayrollMonths($fp, $p['sections']['payroll_register']);
    fputcsv($fp, []);

    acctCsvSection($fp, '4. ACCOUNT CLOSING BALANCES AT ' . $p['range']['to']);
    acctCsvBodyBalances($fp, $p['sections']['balances']);
    fputcsv($fp, []);

    acctCsvSection($fp, '5. DATA INTEGRITY ISSUES');
    acctCsvBodyIssues($fp, $p['sections']['issues']['issues']);
}

/** Write $payload to $fp as CSV. Takes a stream so a test can render to memory. */
function acctCsvWrite($fp, PDO $pdo, string $report, array $payload): void {
    if ($report === 'tax_pack') {
        acctCsvTaxPack($fp, $pdo, $payload);
        return;
    }

    acctCsvHeaderBlock($fp, $pdo, acctReportTitle($report, $payload), $payload);

    switch ($report) {
        case 'pl':                acctCsvBodyPl($fp, $payload); return;
        case 'trial_balance':     acctCsvBodyTrialBalance($fp, $payload); return;
        case 'ledger':            acctCsvBodyLedger($fp, $payload); return;
        case 'account_statement': acctCsvBodyAccountStatement($fp, $payload); return;
        case 'cash_flow':         acctCsvBodyCashFlow($fp, $payload); return;
        case 'expense_matrix':    acctCsvBodyMatrix($fp, $payload); return;
        case 'counterparties':    acctCsvBodyCounterparties($fp, $payload); return;
        case 'payroll_register':  acctCsvBodyPayrollRegister($fp, $payload); return;
        case 'advances':          acctCsvBodyAdvances($fp, $payload); return;
        case 'issues':            acctCsvBodyIssues($fp, $payload['issues']); return;
    }

    throw new InvalidArgumentException('CSV export is not available for ' . $report . '.');
}

// ── Dispatch ─────────────────────────────────────────────────────────────────

/** Every report this endpoint answers to. */
function accountingReports(): array {
    return ['overview', 'pl', 'trial_balance', 'ledger', 'account_statement', 'cash_flow',
            'expense_matrix', 'counterparties', 'payroll_register', 'advances', 'issues',
            'fiscal_years', 'tax_pack'];
}

/**
 * Build one report. Bad input throws InvalidArgumentException, a missing record
 * throws RuntimeException; the dispatcher turns those into 400 and 404. Nothing
 * in here writes, echoes or exits, which is what makes it testable.
 */
function accountingReport(PDO $pdo, array $q): array {
    $ctx = acctContext($pdo, $q);

    switch ($q['report']) {
        case '':
        case 'overview':          return acctReportOverview($pdo, $q, $ctx);
        case 'pl':                return acctReportPl($pdo, $q, $ctx);
        case 'trial_balance':     return acctReportTrialBalance($pdo, $q, $ctx);
        case 'ledger':            return acctReportLedger($pdo, $q, $ctx);
        case 'account_statement': return acctReportAccountStatement($pdo, $q, $ctx);
        case 'cash_flow':         return acctReportCashFlow($pdo, $q, $ctx);
        case 'expense_matrix':    return acctReportCategoryMatrix($pdo, $q, $ctx);
        case 'counterparties':    return acctReportCounterparties($pdo, $q, $ctx);
        case 'payroll_register':  return acctReportPayrollRegister($pdo, $q, $ctx);
        case 'advances':          return acctReportAdvances($pdo, $q, $ctx);
        case 'issues':            return acctReportIssues($pdo, $q, $ctx);
        case 'fiscal_years':      return acctReportFiscalYears($pdo, $q, $ctx);
        case 'tax_pack':          return acctReportTaxPack($pdo, $q, $ctx);
    }

    throw new InvalidArgumentException(
        'Unknown report: ' . $q['report'] . '. Available: ' . implode(', ', accountingReports()) . '.'
    );
}

function accountingApiDispatch(PDO $pdo): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        jsonError('Method not allowed.', 405);
    }

    $q = acctQuery($_GET);

    try {
        $payload = accountingReport($pdo, $q);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
        return;
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), 404);
        return;
    }

    if ($q['format'] !== 'csv') {
        jsonResponse($payload);
    }

    if (!in_array($q['report'], acctCsvReports(), true)) {
        jsonError('CSV export is not available for ' . $q['report'] . '.', 400);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . acctCsvFilename($q['report'], $payload['range']) . '"');
    $fp = fopen('php://output', 'w');
    acctCsvWrite($fp, $pdo, $q['report'], $payload);
    fclose($fp);
    exit;
}

// Served by a web server this file is an endpoint; run from the command line it
// is a library. A test has to be able to require it and call the builders to
// check the numbers, which is impossible if requiring it immediately answers a
// request and exits. Every real request arrives through a SAPI other than
// "cli" — including the built-in server — so nothing about the endpoint changes.
if (PHP_SAPI !== 'cli') {
    accountingApiDispatch($pdo);
}
