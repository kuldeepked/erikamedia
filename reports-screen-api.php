<?php
// Reports & Analytics screen — one consolidated payload:
//   - 12-month income/expense/net series (+ summary totals)
//   - expenses by category for the selected month
//   - per-employee performance (interviews/placements/earnings) for the month
// Read-only. Finances come from SQLite (business books); performance from
// activity.json.

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

$books = bizBookIds($pdo);

// ── 12-month series ───────────────────────────────────────────────────────
$series = [];
$sumIncome = 0.0; $sumExpense = 0.0;
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($month . '-01 -' . $i . ' month'));
    $t = monthTotals($pdo, $books, $m);
    $series[] = [
        'month'   => $m,
        'label'   => date('M', strtotime($m . '-01')),
        'income'  => $t['income'],
        'expense' => $t['expense'],
        'net'     => $t['net'],
    ];
    $sumIncome  += $t['income'];
    $sumExpense += $t['expense'];
}

// ── Expenses by category for the selected month ───────────────────────────
$catSql = "SELECT COALESCE(c.name, '(uncategorised)') AS name, SUM(t.amount) AS total
           FROM transactions t
           LEFT JOIN categories c ON c.id = t.category_id
           WHERE t.void = 0 AND t.type = 'expense'
             AND substr(t.date, 1, 7) = ?";
$args = [$month];
if ($books) {
    $ph = implode(',', array_fill(0, count($books), '?'));
    $catSql .= " AND t.book_id IN ($ph)";
    $args = array_merge($args, $books);
}
$catSql .= " GROUP BY c.name ORDER BY total DESC";
$stmt = $pdo->prepare($catSql);
$stmt->execute($args);
$categories = array_map(
    fn($r) => ['name' => $r['name'], 'total' => (float) $r['total']],
    $stmt->fetchAll()
);

// ── Per-employee performance for the month (activity.json) ────────────────
$activityFile = __DIR__ . '/activity.json';
$activity = file_exists($activityFile) ? (json_decode(file_get_contents($activityFile), true) ?: []) : [];

$byEmp = [];
foreach ($activity as $a) {
    if (strpos((string) ($a['date'] ?? ''), $month) !== 0) continue;
    $emp = (string) ($a['employee'] ?? '');
    if ($emp === '') continue;
    if (!isset($byEmp[$emp])) {
        $byEmp[$emp] = ['employee' => $emp, 'interviews' => 0, 'placements' => 0, 'commission' => 0];
    }
    $type = (string) ($a['type'] ?? '');
    $amt  = (int) ($a['amount'] ?? 0);
    if ($type === 'interview')      { $byEmp[$emp]['interviews']++;  $byEmp[$emp]['commission'] += $amt; }
    elseif ($type === 'placement')  { $byEmp[$emp]['placements']++;  $byEmp[$emp]['commission'] += $amt; }
    elseif ($type === 'bonus')      { $byEmp[$emp]['commission'] += $amt; }
    // penalties are payslip deductions, not performance earnings — excluded here
}
$people = array_values($byEmp);
usort($people, fn($a, $b) => $b['commission'] <=> $a['commission']);

jsonResponse([
    'month'    => $month,
    'currency' => 'PKR',
    'summary'  => [
        'income'  => $sumIncome,
        'expense' => $sumExpense,
        'net'     => $sumIncome - $sumExpense,
        'months'  => 12,
    ],
    'series'     => $series,
    'categories' => $categories,
    'people'     => $people,
]);
