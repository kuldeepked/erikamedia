<?php
// Payroll run — per-employee payslip preview. The math lives in payroll-lib.php
// (shared with the generators and payroll-post-api.php so they can never
// drift). Read-only; nothing here writes.
//
//   ?month=YYYY-MM              one month, the shape the payroll screen has
//                               always consumed
//   ?from=YYYY-MM&to=YYYY-MM    every month in the span, oldest first, for the
//                               bulk generate / bulk print screens
//
// Both shapes also carry the accounts payroll can be paid from and the saved
// default, so the caller does not need a second round trip to accounts-api.php
// just to draw a dropdown.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

/**
 * Accounts salaries can actually be paid out of: real money, still in use.
 * Employee Advances and Statutory Payables are receivable/liability pivots and
 * are excluded by kind, not by name.
 */
function payrollPayingAccounts(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, name, currency, type FROM accounts
         WHERE archived = 0 AND kind = 'asset'
         ORDER BY display_order, name"
    )->fetchAll();
}

$common = [
    'currency'           => 'PKR',
    'accounts'           => payrollPayingAccounts($pdo),
    'default_account_id' => settingGet($pdo, 'payroll_account_id', ''),
    // Oldest month with a payslip in it, so an "everything" range has a floor
    // instead of walking back to the beginning of time. Empty until the first
    // slip is issued.
    'earliest_period'    => (string) ($pdo->query(
        'SELECT MIN(period) FROM payslips WHERE voided = 0')->fetchColumn() ?: ''),
];

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to']   ?? ''));

if (isMonth($from) || isMonth($to)) {
    $range = payrollRowsForRange($pdo, $from, $to);
    jsonResponse(array_merge(['mode' => 'range'], $common, $range));
}

$month = trim((string) ($_GET['month'] ?? ''));
if (!isMonth($month)) {
    $month = date('Y-m');
}

jsonResponse(array_merge(
    ['mode' => 'month', 'month' => $month],
    $common,
    payrollRows($pdo, $month)
));
