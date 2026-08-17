<?php
// Post issued payslips to Finances.
//
// This is now a catch-up button, not the main path: generating a payslip books
// it. What is left for this endpoint is slips that were saved without posting
// (autopost switched off, or a posting that failed on a bad account) and the
// historical slips the migration imported without matching ledger rows.
//
// It no longer computes anything. It finds the unposted live slips for the
// month or range and hands each to postPayslip(), which is idempotent through
// transactions.source_ref — so pressing this twice cannot double-book, and it
// no longer has to guess at duplicates by counterparty + category + month.
//
// `no_category` is gone: ensureSalaryCategory() creates the employee's salary
// category on demand rather than reporting a piece of setup nobody was told to
// do and silently skipping a real salary.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}
verifyCsrf();

$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action !== 'post') {
    jsonError('Invalid action.');
}

// One month or a span. `month` is what the existing payroll screen sends.
$month = trim((string) ($input['month'] ?? ''));
$from  = trim((string) ($input['from_period'] ?? ''));
$to    = trim((string) ($input['to_period']   ?? ''));

if (isMonth($month)) {
    $from = $to = $month;
} elseif (isMonth($from) || isMonth($to)) {
    [$from, $to] = payrollNormalisePeriods($from, $to);
    $month = null;
} else {
    jsonError('A valid month (YYYY-MM), or from_period and to_period, is required.');
}

$accountId = trim((string) ($input['account_id'] ?? ''));
$account   = null;
if ($accountId !== '') {
    $accStmt = $pdo->prepare('SELECT id, name, currency FROM accounts WHERE id = ? AND archived = 0');
    $accStmt->execute([$accountId]);
    $account = $accStmt->fetch();
    if (!$account) {
        jsonError('That source account was not found.');
    }
    // Remember it. Payroll is paid from the same account every month; being
    // asked to re-pick it on every run is how the wrong one gets chosen.
    settingSet($pdo, 'payroll_account_id', $accountId);
}

$slips = listPayslips($pdo, ['from_period' => $from, 'to_period' => $to, 'posted' => 0]);

$posted = []; $skipped = []; $failed = []; $warnings = [];
$totalAmt = 0.0;

foreach ($slips as $slip) {
    try {
        // Each slip books on its own. A bad one is reported and stepped over —
        // the old all-or-nothing transaction meant one broken row cost the
        // whole month its postings.
        $res = postPayslip($pdo, $slip['id'], $accountId);

        if (!empty($res['already'])) {
            $skipped[] = [
                'id' => $slip['id'], 'employee' => $slip['employee'],
                'period' => $slip['period'], 'reason' => 'already_posted',
            ];
            continue;
        }
        if (empty($res['posted'])) {
            // Nothing to book: net, advance and statutory all round to zero.
            $skipped[] = [
                'id' => $slip['id'], 'employee' => $slip['employee'],
                'period' => $slip['period'], 'reason' => 'nothing_to_post',
            ];
            $warnings = array_merge($warnings, $res['warnings'] ?? []);
            continue;
        }

        $amount = $res['booked']['total_expense'] ?? 0.0;
        $posted[] = [
            'id'           => $slip['id'],
            'employee'     => $slip['employee'],
            'period'       => $slip['period'],
            'label'        => periodLabel($slip['period']),
            'net'          => $slip['net'],
            'amount'       => $amount,
            'transactions' => $res['transactions'],
        ];
        $totalAmt += $amount;
        $warnings = array_merge($warnings, $res['warnings'] ?? []);
    } catch (Throwable $e) {
        $failed[] = [
            'id' => $slip['id'], 'employee' => $slip['employee'],
            'period' => $slip['period'], 'error' => $e->getMessage(),
        ];
    }
}

// Employees with no live payslip at all in the range. Reported because a name
// missing from a payroll run is exactly the thing worth noticing, and posting
// can say nothing about it otherwise.
$notGenerated = [];
foreach (payrollRowsForRange($pdo, $from, $to)['months'] as $m) {
    foreach ($m['rows'] as $r) {
        if (!$r['generated']) {
            $notGenerated[] = ['employee' => $r['employee'], 'period' => $m['period']];
        }
    }
}

jsonResponse([
    'success'       => true,
    'month'         => $month,          // null for a range request
    'from_period'   => $from,
    'to_period'     => $to,
    'account'       => $account['name'] ?? null,
    'account_id'    => $accountId,
    'posted'        => $posted,
    'skipped'       => $skipped,
    'failed'        => $failed,
    'not_generated' => $notGenerated,
    'warnings'      => array_values(array_unique($warnings)),
    'amount_total'  => round($totalAmt, 2),
]);
