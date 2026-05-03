<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$type   = trim((string) ($_GET['type'] ?? ''));
$bookId = trim((string) ($_GET['book_id'] ?? ''));
$month  = trim((string) ($_GET['month'] ?? ''));
$monthOk = preg_match('/^\d{4}-\d{2}$/', $month);

// ─────────────────────────────────────────────────────────────
// category_totals — running totals per category, broken down by currency.
// Optional filters: book_id, month (YYYY-MM).
// ─────────────────────────────────────────────────────────────
if ($type === 'category_totals') {
    $sql = 'SELECT c.id, c.name, c.type, c.parent_id, c.book_scope, c.linked_employee,
                   t.currency,
                   SUM(t.amount) AS total,
                   COUNT(*)      AS count
            FROM categories c
            LEFT JOIN transactions t
                 ON t.category_id = c.id AND t.void = 0';
    $where = ['c.archived = 0'];
    $args  = [];
    if ($bookId !== '')  { $where[] = '(t.book_id = ? OR t.id IS NULL)'; $args[] = $bookId; }
    if ($monthOk)        { $where[] = "(substr(t.date, 1, 7) = ? OR t.id IS NULL)"; $args[] = $month; }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY c.id, t.currency
              ORDER BY c.type, c.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    // Roll up to one record per category with by_currency array.
    $cats = [];
    foreach ($rows as $r) {
        $id = $r['id'];
        if (!isset($cats[$id])) {
            $cats[$id] = [
                'id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'],
                'parent_id' => $r['parent_id'], 'book_scope' => $r['book_scope'],
                'linked_employee' => $r['linked_employee'],
                'by_currency' => [],
            ];
        }
        if ($r['currency'] !== null) {
            $cats[$id]['by_currency'][] = [
                'currency' => $r['currency'],
                'total'    => (float) $r['total'],
                'count'    => (int)   $r['count'],
            ];
        }
    }
    jsonResponse(['categories' => array_values($cats)]);
}

// ─────────────────────────────────────────────────────────────
// account_balances — opening + signed sum of non-void tx per account.
// ─────────────────────────────────────────────────────────────
if ($type === 'account_balances') {
    $sql = 'SELECT a.id, a.name, a.type, a.currency, a.book_id, a.opening_balance,
                   a.opening_balance
                   + COALESCE((SELECT SUM(amount) FROM transactions
                               WHERE account_id = a.id AND void = 0
                                 AND type IN ("income","transfer_in")), 0)
                   - COALESCE((SELECT SUM(amount) FROM transactions
                               WHERE account_id = a.id AND void = 0
                                 AND type IN ("expense","transfer_out")), 0)
                   AS balance,
                   (SELECT COUNT(*) FROM transactions
                    WHERE account_id = a.id AND void = 0) AS tx_count
            FROM accounts a
            WHERE a.archived = 0
            ORDER BY a.display_order, a.name';
    $rows = $pdo->query($sql)->fetchAll();

    // Per-currency net (across the whole list, helpful for dashboard at-a-glance).
    $byCurrency = [];
    foreach ($rows as $r) {
        $cur = $r['currency'];
        if (!isset($byCurrency[$cur])) $byCurrency[$cur] = 0;
        $byCurrency[$cur] += (float) $r['balance'];
    }
    jsonResponse(['accounts' => $rows, 'by_currency' => $byCurrency]);
}

// ─────────────────────────────────────────────────────────────
// employee_salaries — per-employee salary history.
//   ?employee=Name → one employee's transactions + total
//   no employee   → roll-up: each employee with total paid
// ─────────────────────────────────────────────────────────────
if ($type === 'employee_salaries') {
    $employee = trim((string) ($_GET['employee'] ?? ''));

    if ($employee !== '') {
        $sql = 'SELECT t.*, a.name AS account_name, c.name AS category_name
                FROM transactions t
                JOIN accounts a ON a.id = t.account_id
                JOIN categories c ON c.id = t.category_id
                WHERE c.linked_employee = ? AND t.void = 0
                ORDER BY t.date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$employee]);
        $entries = $stmt->fetchAll();
        $total = 0;
        $byCur = [];
        foreach ($entries as $e) {
            $cur = $e['currency'];
            if (!isset($byCur[$cur])) $byCur[$cur] = 0;
            $byCur[$cur] += (float) $e['amount'];
            $total += (float) $e['amount'];
        }
        jsonResponse(['employee' => $employee, 'entries' => $entries, 'by_currency' => $byCur]);
    }

    $sql = 'SELECT c.linked_employee AS employee,
                   t.currency,
                   SUM(t.amount) AS total,
                   COUNT(*)      AS count,
                   MAX(t.date)   AS last_paid
            FROM categories c
            JOIN transactions t ON t.category_id = c.id
            WHERE c.linked_employee <> "" AND t.void = 0
            GROUP BY c.linked_employee, t.currency
            ORDER BY c.linked_employee';
    $rows = $pdo->query($sql)->fetchAll();
    $emp = [];
    foreach ($rows as $r) {
        $name = $r['employee'];
        if (!isset($emp[$name])) {
            $emp[$name] = ['employee' => $name, 'last_paid' => $r['last_paid'], 'by_currency' => []];
        } elseif ($r['last_paid'] > $emp[$name]['last_paid']) {
            $emp[$name]['last_paid'] = $r['last_paid'];
        }
        $emp[$name]['by_currency'][] = [
            'currency' => $r['currency'],
            'total'    => (float) $r['total'],
            'count'    => (int)   $r['count'],
        ];
    }
    jsonResponse(['employees' => array_values($emp)]);
}

// ─────────────────────────────────────────────────────────────
// pl — Profit & Loss for a book/period. Excludes transfers.
// ─────────────────────────────────────────────────────────────
if ($type === 'pl') {
    if ($bookId === '') jsonError('book_id is required for P&L.');
    $args = [$bookId];
    $where = 'WHERE t.book_id = ? AND t.void = 0 AND t.type IN ("income","expense")';
    if ($monthOk) {
        $where .= " AND substr(t.date, 1, 7) = ?";
        $args[] = $month;
    }

    $sql = "SELECT t.type, t.currency, COALESCE(c.name, '(uncategorised)') AS category,
                   SUM(t.amount) AS total, COUNT(*) AS count
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            $where
            GROUP BY t.type, t.currency, c.name
            ORDER BY t.type, t.currency, total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $totalsByCurrency = [];
    foreach ($rows as $r) {
        $cur = $r['currency'];
        if (!isset($totalsByCurrency[$cur])) {
            $totalsByCurrency[$cur] = ['income' => 0, 'expense' => 0, 'net' => 0];
        }
        $totalsByCurrency[$cur][$r['type']] += (float) $r['total'];
    }
    foreach ($totalsByCurrency as &$t) {
        $t['net'] = $t['income'] - $t['expense'];
    }
    unset($t);

    jsonResponse(['rows' => $rows, 'totals' => $totalsByCurrency]);
}

// ─────────────────────────────────────────────────────────────
// split_groups — recent split events for a book. Useful to list
// "your salary splits" with a drill-down.
// ─────────────────────────────────────────────────────────────
if ($type === 'split_groups') {
    if ($bookId === '') jsonError('book_id is required.');
    $sql = 'SELECT split_group_id, MIN(date) AS date, currency,
                   SUM(amount) AS total, COUNT(*) AS legs,
                   MIN(counterparty) AS counterparty, MIN(description) AS description
            FROM transactions
            WHERE split_group_id IS NOT NULL AND book_id = ? AND void = 0
            GROUP BY split_group_id
            ORDER BY date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookId]);
    jsonResponse(['groups' => $stmt->fetchAll()]);
}

jsonError('Unknown report type.');
