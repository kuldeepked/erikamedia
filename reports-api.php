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
//
// Multi-book aware: accounts are physical containers, but the same
// bank can hold money belonging to multiple books simultaneously.
// We return both:
//   - balance        : physical balance (sum across all books).
//   - by_book[]      : per-book slice from transactions only.
//   - book_balance   : (only if ?book_id=X) the slice owned by that book.
//
// Note: opening_balance is account-wide. If the account has a primary
// book_id set, opening_balance is attributed to that book in by_book;
// otherwise it stays unattributed (visible in physical balance only).
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

    // Per-book per-account balance from transactions only.
    $bookSql = 'SELECT t.account_id, t.book_id, b.name AS book_name,
                       SUM(CASE WHEN t.type IN ("income","transfer_in")  THEN  t.amount
                                WHEN t.type IN ("expense","transfer_out") THEN -t.amount
                                ELSE 0 END) AS balance
                FROM transactions t
                JOIN books b ON b.id = t.book_id
                WHERE t.void = 0
                GROUP BY t.account_id, t.book_id, b.name';
    $bookRows = $pdo->query($bookSql)->fetchAll();

    $byAccount = [];
    foreach ($bookRows as $r) {
        $byAccount[$r['account_id']][] = [
            'book_id'   => $r['book_id'],
            'book_name' => $r['book_name'],
            'balance'   => (float) $r['balance'],
        ];
    }

    // Attribute opening_balance to the account's primary book (if any).
    $bookNameById = [];
    foreach ($pdo->query('SELECT id, name FROM books')->fetchAll() as $b) {
        $bookNameById[$b['id']] = $b['name'];
    }

    foreach ($rows as &$a) {
        $byBook = $byAccount[$a['id']] ?? [];
        $opening = (float) $a['opening_balance'];
        if ($opening !== 0.0 && $a['book_id'] !== null) {
            $found = false;
            foreach ($byBook as &$bb) {
                if ($bb['book_id'] === $a['book_id']) {
                    $bb['balance'] += $opening;
                    $found = true;
                    break;
                }
            }
            unset($bb);
            if (!$found && isset($bookNameById[$a['book_id']])) {
                $byBook[] = [
                    'book_id'   => $a['book_id'],
                    'book_name' => $bookNameById[$a['book_id']],
                    'balance'   => $opening,
                ];
            }
        }
        // Drop zero rows so the UI doesn't show empty slices.
        $byBook = array_values(array_filter($byBook, fn($b) => abs($b['balance']) > 0.005));
        // Sort by absolute balance, biggest first.
        usort($byBook, fn($x, $y) => abs($y['balance']) <=> abs($x['balance']));
        $a['by_book'] = $byBook;

        if ($bookId !== '') {
            $slice = 0.0;
            foreach ($byBook as $bb) {
                if ($bb['book_id'] === $bookId) { $slice = $bb['balance']; break; }
            }
            $a['book_balance'] = $slice;
        }
    }
    unset($a);

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

// ─────────────────────────────────────────────────────────────
// loans_summary — per-person breakdown of money lent out.
//
// "Loans Receivable" is identified by name (case-insensitive) — any
// account literally named that is treated as the loans book. Pivots
// transactions on that account by counterparty:
//   lent        = transfer_in onto Loans Receivable
//                 (money moving FROM your bank INTO the receivable)
//   repaid      = transfer_out from Loans Receivable
//                 (money flowing BACK into your bank)
//   written_off = expense from Loans Receivable
//                 (acknowledging the loss)
//   outstanding = lent - repaid - written_off
// ─────────────────────────────────────────────────────────────
if ($type === 'loans_summary') {
    $accStmt = $pdo->prepare(
        "SELECT id, name, currency FROM accounts
         WHERE LOWER(name) = 'loans receivable' AND archived = 0
         LIMIT 1"
    );
    $accStmt->execute();
    $account = $accStmt->fetch();
    if (!$account) {
        jsonResponse(['account' => null, 'people' => [], 'totals' => null]);
    }

    $sql = "SELECT counterparty, type, SUM(amount) AS total
            FROM transactions
            WHERE account_id = ? AND void = 0
            GROUP BY counterparty, type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$account['id']]);
    $rows = $stmt->fetchAll();

    $byPerson = [];
    foreach ($rows as $r) {
        $name = trim((string) $r['counterparty']);
        if (!isset($byPerson[$name])) {
            $byPerson[$name] = [
                'counterparty' => $name,
                'lent' => 0.0, 'repaid' => 0.0, 'written_off' => 0.0,
            ];
        }
        $amt = (float) $r['total'];
        // Money INTO Loans Receivable = lent (transfer_in arrives here from your bank).
        // Money OUT of Loans Receivable = repaid (back to your bank) or written_off (expense).
        if ($r['type'] === 'transfer_in')   $byPerson[$name]['lent']        += $amt;
        if ($r['type'] === 'transfer_out')  $byPerson[$name]['repaid']      += $amt;
        if ($r['type'] === 'expense')       $byPerson[$name]['written_off'] += $amt;
    }

    foreach ($byPerson as &$p) {
        $p['outstanding'] = round($p['lent'] - $p['repaid'] - $p['written_off'], 2);
        $p['lent']        = round($p['lent'], 2);
        $p['repaid']      = round($p['repaid'], 2);
        $p['written_off'] = round($p['written_off'], 2);
    }
    unset($p);

    // Sort: open loans first (by outstanding desc), then settled.
    usort($byPerson, function($a, $b) {
        if ($a['outstanding'] != $b['outstanding']) return $b['outstanding'] <=> $a['outstanding'];
        return strcasecmp($a['counterparty'], $b['counterparty']);
    });

    $totals = ['lent' => 0, 'repaid' => 0, 'written_off' => 0, 'outstanding' => 0];
    foreach ($byPerson as $p) {
        $totals['lent']        += $p['lent'];
        $totals['repaid']      += $p['repaid'];
        $totals['written_off'] += $p['written_off'];
        $totals['outstanding'] += $p['outstanding'];
    }
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

    jsonResponse([
        'account'  => ['id' => $account['id'], 'name' => $account['name']],
        'currency' => $account['currency'],
        'people'   => array_values($byPerson),
        'totals'   => $totals,
    ]);
}

jsonError('Unknown report type.');
