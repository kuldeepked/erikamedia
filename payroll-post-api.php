<?php
// Post salaries to Finances. For the chosen month + source account, writes one
// expense transaction per employee (their net pay) into that employee's linked
// salary category, in the Erika (business) book.
//
// Idempotent: skips any employee who already has a salary expense in their
// salary category for that month. Employees with no linked salary category are
// reported back (create them in Finance Setup → "sync salary categories").

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

$month = trim((string) ($input['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    jsonError('A valid month (YYYY-MM) is required.');
}
$accountId = trim((string) ($input['account_id'] ?? ''));
if ($accountId === '') {
    jsonError('Pick a source account to pay salaries from.');
}

// Validate the source account.
$accStmt = $pdo->prepare("SELECT id, currency, name FROM accounts WHERE id = ? AND archived = 0");
$accStmt->execute([$accountId]);
$account = $accStmt->fetch();
if (!$account) {
    jsonError('That source account was not found.');
}

// Resolve the Erika business book.
$bookRow = $pdo->query("SELECT id FROM books WHERE LOWER(name) LIKE 'erika%' ORDER BY display_order LIMIT 1")->fetch();
$bookId  = $bookRow['id'] ?? null;
if (!$bookId) {
    $biz = bizBookIds($pdo);
    $bookId = $biz[0] ?? null;
}
if (!$bookId) {
    jsonError('No business book found to post salaries into.');
}

// Map each employee (lowercased) → their linked salary expense category.
$catByEmp = [];
foreach ($pdo->query("SELECT id, linked_employee FROM categories
                      WHERE type = 'expense' AND linked_employee <> '' AND archived = 0")->fetchAll() as $c) {
    $catByEmp[strtolower(trim((string) $c['linked_employee']))] = $c['id'];
}

$res  = payrollRows($pdo, $month);
$date = date('Y-m-t', strtotime($month . '-01'));   // last day of the month
$now  = date('c');

$dupStmt = $pdo->prepare(
    "SELECT 1 FROM transactions
     WHERE void = 0 AND type = 'expense' AND counterparty = ? AND category_id = ?
       AND substr(date, 1, 7) = ? LIMIT 1"
);
$ins = $pdo->prepare(
    'INSERT INTO transactions
        (id, date, book_id, type, amount, currency, account_id, category_id,
         counterparty, description, void, created_at, updated_at)
     VALUES (?, ?, ?, "expense", ?, ?, ?, ?, ?, ?, 0, ?, ?)'
);

$posted = []; $skipped = []; $noCategory = []; $totalAmt = 0;

$pdo->beginTransaction();
try {
    foreach ($res['rows'] as $r) {
        if ((int) $r['net'] <= 0) continue;
        $key = strtolower($r['employee']);

        $catId = $catByEmp[$key] ?? null;
        if (!$catId) { $noCategory[] = $r['employee']; continue; }

        $dupStmt->execute([$r['employee'], $catId, $month]);
        if ($dupStmt->fetchColumn()) { $skipped[] = $r['employee']; continue; }

        $txId = newId('tx');
        $ins->execute([
            $txId, $date, $bookId, (int) $r['net'], $account['currency'],
            $accountId, $catId, $r['employee'], 'Net salary ' . $month, $now, $now,
        ]);
        audit($pdo, 'created', 'transaction', $txId, null, [
            'source' => 'payroll', 'employee' => $r['employee'], 'month' => $month, 'amount' => (int) $r['net'],
        ]);
        $posted[]  = $r['employee'];
        $totalAmt += (int) $r['net'];
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonError('Posting failed: ' . $e->getMessage(), 500);
}

jsonResponse([
    'success'      => true,
    'month'        => $month,
    'account'      => $account['name'],
    'posted'       => $posted,
    'skipped'      => $skipped,
    'no_category'  => $noCategory,
    'amount_total' => $totalAmt,
]);
