<?php
// Dashboard overview — one consolidated KPI payload for the Home screen.
// Read-only. Pulls business-book finances (SQLite), this-month activity
// (activity.json), headcount/payroll base (employees.json), and outstanding
// advances/loans (SQLite, same pivot the reports endpoints use).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/finance-lib.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));

// Business books only (Erika Media). Future-proof if more are added.
$bizBooks = bizBookIds($pdo);

$cur  = monthTotals($pdo, $bizBooks, $month);
$prev = monthTotals($pdo, $bizBooks, $prevMonth);

// 6-month cash-flow series (oldest → newest).
$cashflow = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($month . '-01 -' . $i . ' month'));
    $t = monthTotals($pdo, $bizBooks, $m);
    $cashflow[] = [
        'month'   => $m,
        'label'   => date('M', strtotime($m . '-01')),
        'income'  => $t['income'],
        'expense' => $t['expense'],
    ];
}

$advances = accountOutstanding($pdo, 'employee advances');
$loans    = accountOutstanding($pdo, 'loans receivable');

// Activity (this month): interview / placement counts + top performer.
$activityFile = __DIR__ . '/activity.json';
$activity = file_exists($activityFile) ? (json_decode(file_get_contents($activityFile), true) ?: []) : [];

$interviews = 0; $placements = 0; $perf = [];
foreach ($activity as $a) {
    if (strpos((string) ($a['date'] ?? ''), $month) !== 0) continue;
    $emp  = (string) ($a['employee'] ?? '');
    $type = (string) ($a['type'] ?? '');
    if ($emp === '') continue;
    if (!isset($perf[$emp])) $perf[$emp] = ['interviews' => 0, 'placements' => 0];
    if ($type === 'interview') { $interviews++; $perf[$emp]['interviews']++; }
    if ($type === 'placement') { $placements++; $perf[$emp]['placements']++; }
}

$top = null;
foreach ($perf as $name => $p) {
    if ($top === null
        || $p['interviews'] > $top['interviews']
        || ($p['interviews'] === $top['interviews'] && $p['placements'] > $top['placements'])) {
        $top = ['name' => $name, 'interviews' => $p['interviews'], 'placements' => $p['placements']];
    }
}
if ($top && $top['interviews'] === 0 && $top['placements'] === 0) {
    $top = null; // nobody actually did anything this month
}

// activity.json is stored newest-first; take the latest few.
$recent = array_slice($activity, 0, 6);
$recent = array_map(function ($a) {
    return [
        'type'     => (string) ($a['type'] ?? ''),
        'employee' => (string) ($a['employee'] ?? ''),
        'amount'   => (int)    ($a['amount'] ?? 0),
        'date'     => (string) ($a['date'] ?? ''),
    ];
}, $recent);

// Headcount + base payroll + designation lookup.
$empFile   = __DIR__ . '/employees.json';
$employees = file_exists($empFile) ? (json_decode(file_get_contents($empFile), true) ?: []) : [];
$headcount = count($employees);

$payrollBase = 0;
$desigByName = [];
foreach ($employees as $e) {
    $desigByName[(string) ($e['name'] ?? '')] = (string) ($e['designation'] ?? '');
    $payrollBase += max(0, (int) ($e['basic_salary']      ?? 0))
                  + max(0, (int) ($e['allowance']         ?? 0))
                  + max(0, (int) ($e['punctuality_bonus'] ?? 0))
                  - max(0, (int) ($e['provident_fund']    ?? 0))
                  - max(0, (int) ($e['eobi']              ?? 0))
                  - max(0, (int) ($e['professional_tax']  ?? 0));
}
if ($top) $top['designation'] = $desigByName[$top['name']] ?? '';

jsonResponse([
    'month'    => $month,
    'currency' => 'PKR',
    'kpis' => [
        'income'               => $cur['income'],
        'expense'              => $cur['expense'],
        'net'                  => $cur['net'],
        'income_prev'          => $prev['income'],
        'expense_prev'         => $prev['expense'],
        'net_prev'             => $prev['net'],
        'interviews'           => $interviews,
        'placements'           => $placements,
        'headcount'            => $headcount,
        'advances_outstanding' => $advances,
        'loans_outstanding'    => $loans,
        'payroll_base'         => max(0, $payrollBase),
    ],
    'cashflow'        => $cashflow,
    'top_performer'   => $top,
    'recent_activity' => $recent,
]);
