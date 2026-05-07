<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

const TX_TYPES = ['income','expense','transfer_in','transfer_out'];

$pdo = db();

// ─────────────────────────────────────────────────────────────
// GET — list / export transactions, with rich filters.
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bookId      = trim((string) ($_GET['book_id'] ?? ''));
    $accountId   = trim((string) ($_GET['account_id'] ?? ''));
    $categoryId  = trim((string) ($_GET['category_id'] ?? ''));
    $month       = trim((string) ($_GET['month'] ?? ''));
    $type        = trim((string) ($_GET['type'] ?? ''));
    $includeVoid = !empty($_GET['include_void']);
    $splitGroup  = trim((string) ($_GET['split_group_id'] ?? ''));
    $linkedTx    = trim((string) ($_GET['linked_tx_id'] ?? ''));
    $limit       = (int) ($_GET['limit'] ?? 0);

    $sql  = 'SELECT t.*,
                    b.name  AS book_name,
                    a.name  AS account_name,
                    a.currency AS account_currency,
                    c.name  AS category_name,
                    c.type  AS category_type
             FROM transactions t
             JOIN books    b ON b.id = t.book_id
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN categories c ON c.id = t.category_id';
    $where = [];
    $args  = [];
    if (!$includeVoid)         $where[] = 't.void = 0';
    if ($bookId    !== '')   { $where[] = 't.book_id = ?';     $args[] = $bookId; }
    if ($accountId !== '')   { $where[] = 't.account_id = ?';  $args[] = $accountId; }
    if ($categoryId !== '')  { $where[] = 't.category_id = ?'; $args[] = $categoryId; }
    if ($splitGroup !== '')  { $where[] = 't.split_group_id = ?'; $args[] = $splitGroup; }
    if ($linkedTx   !== '')  { $where[] = 't.linked_tx_id = ?';   $args[] = $linkedTx; }
    if ($type !== '') {
        if (!in_array($type, TX_TYPES)) jsonError('Invalid type.');
        $where[] = 't.type = ?'; $args[] = $type;
    }
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where[] = "substr(t.date, 1, 7) = ?";
        $args[]  = $month;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY t.date DESC, t.created_at DESC';
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $entries = $stmt->fetchAll();

    // Totals per currency, broken down by type.
    $totals = [];
    foreach ($entries as $e) {
        $cur = $e['currency'];
        if (!isset($totals[$cur])) $totals[$cur] = ['income' => 0, 'expense' => 0, 'transfer_in' => 0, 'transfer_out' => 0];
        $totals[$cur][$e['type']] += (float) $e['amount'];
    }
    foreach ($totals as $cur => &$t) {
        $t['net'] = $t['income'] - $t['expense'];  // ignores transfers in P&L
    }
    unset($t);

    if (($_GET['export'] ?? '') === 'csv') {
        $tag = $month !== '' ? $month : 'all';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="erika-finances-' . $tag . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Date','Book','Type','Account','Currency','Category','Counterparty','Description','Amount']);
        foreach ($entries as $e) {
            fputcsv($fp, [
                $e['date'], $e['book_name'], $e['type'], $e['account_name'], $e['currency'],
                $e['category_name'] ?? '', $e['counterparty'], $e['description'], $e['amount'],
            ]);
        }
        fputcsv($fp, []);
        foreach ($totals as $cur => $t) {
            fputcsv($fp, ['', '', '', '', $cur, '', '', 'Income',  $t['income']]);
            fputcsv($fp, ['', '', '', '', $cur, '', '', 'Expense', $t['expense']]);
            fputcsv($fp, ['', '', '', '', $cur, '', '', 'Net',     $t['net']]);
        }
        fclose($fp);
        exit;
    }

    jsonResponse(['entries' => $entries, 'totals' => $totals]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function lookup(PDO $pdo, string $table, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function validateDate(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonError('Date must be in YYYY-MM-DD format.');
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) jsonError('Invalid calendar date.');
    return $date;
}

function insertTx(PDO $pdo, array $row): array {
    $row['id']         = $row['id']         ?? newId('tx');
    $row['created_at'] = $row['created_at'] ?? date('c');
    $row['updated_at'] = $row['updated_at'] ?? date('c');
    $row['void']       = (int) ($row['void'] ?? 0);

    $stmt = $pdo->prepare(
        'INSERT INTO transactions
            (id, date, book_id, type, amount, currency, account_id, category_id,
             counterparty, description, linked_tx_id, split_group_id, void, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $row['id'], $row['date'], $row['book_id'], $row['type'], $row['amount'],
        $row['currency'], $row['account_id'], $row['category_id'] ?? null,
        $row['counterparty'] ?? '', $row['description'] ?? '',
        $row['linked_tx_id'] ?? null, $row['split_group_id'] ?? null,
        $row['void'], $row['created_at'], $row['updated_at'],
    ]);
    return $row;
}

function fetchAccount(PDO $pdo, string $id): array {
    $a = lookup($pdo, 'accounts', $id);
    if (!$a) jsonError('Account not found: ' . $id);
    return $a;
}

// ─────────────────────────────────────────────────────────────
// add — single income or expense
// ─────────────────────────────────────────────────────────────
if ($action === 'add') {
    $date         = validateDate(trim((string) ($input['date'] ?? date('Y-m-d'))));
    $bookId       = trim((string) ($input['book_id'] ?? ''));
    $type         = trim((string) ($input['type'] ?? ''));
    $amount       = (float) ($input['amount'] ?? 0);
    $accountId    = trim((string) ($input['account_id'] ?? ''));
    $categoryId   = trim((string) ($input['category_id'] ?? ''));
    $counterparty = trim((string) ($input['counterparty'] ?? ''));
    $description  = trim((string) ($input['description'] ?? ''));
    $currency     = strtoupper(trim((string) ($input['currency'] ?? '')));

    if ($bookId === '')                jsonError('book_id is required.');
    if ($accountId === '')             jsonError('account_id is required.');
    if (!in_array($type, ['income','expense']))
                                       jsonError('type must be income or expense.');
    if ($amount <= 0)                  jsonError('Amount must be greater than zero.');
    if (!lookup($pdo, 'books', $bookId)) jsonError('Book not found.');

    $account = fetchAccount($pdo, $accountId);
    if ($currency === '') $currency = $account['currency'];
    if ($currency !== $account['currency']) {
        jsonError('Currency mismatch with account (' . $account['currency'] . ').');
    }
    if ($categoryId !== '') {
        $cat = lookup($pdo, 'categories', $categoryId);
        if (!$cat)                  jsonError('Category not found.');
        if ($cat['type'] !== $type) jsonError('Category type does not match transaction type.');
    } else {
        $categoryId = null;
    }

    $row = insertTx($pdo, [
        'date' => $date, 'book_id' => $bookId, 'type' => $type, 'amount' => round($amount, 2),
        'currency' => $currency, 'account_id' => $accountId, 'category_id' => $categoryId,
        'counterparty' => mb_substr($counterparty, 0, 200), 'description' => mb_substr($description, 0, 500),
    ]);

    audit($pdo, 'created', 'transaction', $row['id'], null, $row);
    jsonResponse(['success' => true, 'entry' => $row]);
}

// ─────────────────────────────────────────────────────────────
// transfer — within-book paired transfer (transfer_out + transfer_in)
// ─────────────────────────────────────────────────────────────
if ($action === 'transfer') {
    $date         = validateDate(trim((string) ($input['date'] ?? date('Y-m-d'))));
    $bookId       = trim((string) ($input['book_id'] ?? ''));
    $amount       = (float) ($input['amount'] ?? 0);
    $srcId        = trim((string) ($input['src_account_id'] ?? ''));
    $dstId        = trim((string) ($input['dst_account_id'] ?? ''));
    $counterparty = trim((string) ($input['counterparty'] ?? ''));
    $description  = trim((string) ($input['description'] ?? ''));

    if ($bookId === '')         jsonError('book_id is required.');
    if ($srcId === $dstId)      jsonError('Source and destination accounts must differ.');
    if ($amount <= 0)           jsonError('Amount must be greater than zero.');
    if (!lookup($pdo, 'books', $bookId)) jsonError('Book not found.');

    $src = fetchAccount($pdo, $srcId);
    $dst = fetchAccount($pdo, $dstId);
    if ($src['currency'] !== $dst['currency']) {
        jsonError('Cross-currency transfers are not supported. Use Add Income/Expense on each side instead.');
    }

    // If counterparty was given (e.g. "Ahmed" for a loan), it goes on
    // BOTH legs so per-person totals roll up correctly. Otherwise fall
    // back to the opposite account name on each leg.
    $cpOut = $counterparty !== '' ? $counterparty : $dst['name'];
    $cpIn  = $counterparty !== '' ? $counterparty : $src['name'];

    $linkId = newId('link');
    $pdo->beginTransaction();
    try {
        $out = insertTx($pdo, [
            'date' => $date, 'book_id' => $bookId, 'type' => 'transfer_out', 'amount' => round($amount, 2),
            'currency' => $src['currency'], 'account_id' => $srcId,
            'description' => mb_substr($description, 0, 500),
            'counterparty' => mb_substr($cpOut, 0, 200),
            'linked_tx_id' => $linkId,
        ]);
        $in = insertTx($pdo, [
            'date' => $date, 'book_id' => $bookId, 'type' => 'transfer_in', 'amount' => round($amount, 2),
            'currency' => $dst['currency'], 'account_id' => $dstId,
            'description' => mb_substr($description, 0, 500),
            'counterparty' => mb_substr($cpIn, 0, 200),
            'linked_tx_id' => $linkId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit($pdo, 'created', 'transfer', $linkId, null, ['out' => $out, 'in' => $in]);
    jsonResponse(['success' => true, 'out' => $out, 'in' => $in]);
}

// ─────────────────────────────────────────────────────────────
// split — N income (or expense) rows sharing a split_group_id.
//          Optional `match_opposite`: a single matching expense (or income)
//          on another book, sharing linked_tx_id with each split.
//          Used for "Receive Salary with auto-split" or "Pay Kuldeep".
// ─────────────────────────────────────────────────────────────
if ($action === 'split') {
    $date         = validateDate(trim((string) ($input['date'] ?? date('Y-m-d'))));
    $bookId       = trim((string) ($input['book_id'] ?? ''));
    $splitType    = trim((string) ($input['split_type'] ?? 'income'));
    $currency     = strtoupper(trim((string) ($input['currency'] ?? '')));
    $counterparty = trim((string) ($input['counterparty'] ?? ''));
    $description  = trim((string) ($input['description'] ?? ''));
    $splits       = $input['splits'] ?? [];
    $matchOpp     = $input['match_opposite'] ?? null;

    if ($bookId === '')                          jsonError('book_id is required.');
    if (!in_array($splitType, ['income','expense']))
                                                 jsonError('split_type must be income or expense.');
    if (!is_array($splits) || count($splits) < 2) jsonError('Provide at least 2 splits.');
    if (!lookup($pdo, 'books', $bookId))         jsonError('Book not found.');

    $totalAmt = 0.0;
    $cleanSplits = [];
    foreach ($splits as $i => $s) {
        $amt   = (float) ($s['amount'] ?? 0);
        $accId = trim((string) ($s['account_id'] ?? ''));
        $catId = trim((string) ($s['category_id'] ?? ''));
        if ($amt <= 0)         jsonError('Split #' . ($i + 1) . ': amount must be > 0.');
        if ($accId === '')     jsonError('Split #' . ($i + 1) . ': account_id is required.');
        $acc = fetchAccount($pdo, $accId);
        if ($currency === '') $currency = $acc['currency'];
        if ($currency !== $acc['currency']) {
            jsonError('Split #' . ($i + 1) . ': currency mismatch (' . $acc['currency'] . ' vs ' . $currency . ').');
        }
        if ($catId !== '') {
            $cat = lookup($pdo, 'categories', $catId);
            if (!$cat)                       jsonError('Split #' . ($i + 1) . ': category not found.');
            if ($cat['type'] !== $splitType) jsonError('Split #' . ($i + 1) . ': category type mismatch.');
        } else {
            $catId = null;
        }
        $totalAmt += $amt;
        $cleanSplits[] = [
            'amount' => round($amt, 2),
            'account_id' => $accId,
            'category_id' => $catId,
            'description' => trim((string) ($s['description'] ?? $description)),
        ];
    }
    $totalAmt = round($totalAmt, 2);

    // Validate match_opposite (the matching cross-book expense/income).
    $cleanMatch = null;
    if (is_array($matchOpp) && !empty($matchOpp)) {
        $oppBook   = trim((string) ($matchOpp['book_id'] ?? ''));
        $oppType   = $splitType === 'income' ? 'expense' : 'income';
        $oppAcc    = trim((string) ($matchOpp['account_id'] ?? ''));
        $oppCat    = trim((string) ($matchOpp['category_id'] ?? ''));
        $oppDesc   = trim((string) ($matchOpp['description'] ?? $description));
        if ($oppBook === '')                       jsonError('match_opposite.book_id is required.');
        if ($oppAcc === '')                        jsonError('match_opposite.account_id is required.');
        if (!lookup($pdo, 'books', $oppBook))      jsonError('match_opposite book not found.');
        $oppAccount = fetchAccount($pdo, $oppAcc);
        if ($oppAccount['currency'] !== $currency) jsonError('match_opposite account currency mismatch.');
        if ($oppCat !== '') {
            $cat = lookup($pdo, 'categories', $oppCat);
            if (!$cat)                       jsonError('match_opposite category not found.');
            if ($cat['type'] !== $oppType)   jsonError('match_opposite category type mismatch.');
        } else {
            $oppCat = null;
        }
        $cleanMatch = [
            'book_id' => $oppBook, 'type' => $oppType, 'account_id' => $oppAcc,
            'category_id' => $oppCat, 'description' => $oppDesc,
        ];
    }

    $linkId  = newId('link');
    $groupId = newId('split');

    $pdo->beginTransaction();
    try {
        $opposite = null;
        if ($cleanMatch !== null) {
            $opposite = insertTx($pdo, [
                'date' => $date, 'book_id' => $cleanMatch['book_id'], 'type' => $cleanMatch['type'],
                'amount' => $totalAmt, 'currency' => $currency, 'account_id' => $cleanMatch['account_id'],
                'category_id' => $cleanMatch['category_id'], 'counterparty' => $counterparty,
                'description' => mb_substr($cleanMatch['description'], 0, 500),
                'linked_tx_id' => $linkId,
            ]);
        }

        $created = [];
        foreach ($cleanSplits as $s) {
            $created[] = insertTx($pdo, [
                'date' => $date, 'book_id' => $bookId, 'type' => $splitType,
                'amount' => $s['amount'], 'currency' => $currency, 'account_id' => $s['account_id'],
                'category_id' => $s['category_id'], 'counterparty' => $counterparty,
                'description' => mb_substr($s['description'], 0, 500),
                'linked_tx_id' => $cleanMatch !== null ? $linkId : null,
                'split_group_id' => $groupId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit($pdo, 'created', 'split', $groupId, null, [
        'splits' => $created, 'opposite' => $opposite, 'total' => $totalAmt,
    ]);
    jsonResponse(['success' => true, 'splits' => $created, 'opposite' => $opposite, 'split_group_id' => $groupId]);
}

// ─────────────────────────────────────────────────────────────
// update — edit a single transaction (any field). For grouped/linked
// transactions, the user is responsible for keeping siblings consistent;
// the UI warns them.
// ─────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id = trim((string) ($input['id'] ?? ''));
    $row = lookup($pdo, 'transactions', $id);
    if (!$row) jsonError('Transaction not found.', 404);

    $date         = validateDate(trim((string) ($input['date'] ?? $row['date'])));
    $amount       = (float) ($input['amount'] ?? $row['amount']);
    $accountId    = trim((string) ($input['account_id'] ?? $row['account_id']));
    $categoryId   = trim((string) ($input['category_id'] ?? $row['category_id'] ?? ''));
    $counterparty = trim((string) ($input['counterparty'] ?? $row['counterparty']));
    $description  = trim((string) ($input['description'] ?? $row['description']));
    $type         = trim((string) ($input['type'] ?? $row['type']));

    if (!in_array($type, TX_TYPES)) jsonError('Invalid type.');
    if ($amount <= 0)               jsonError('Amount must be greater than zero.');

    $account = fetchAccount($pdo, $accountId);
    if ($categoryId !== '') {
        $cat = lookup($pdo, 'categories', $categoryId);
        if (!$cat) jsonError('Category not found.');
        // Categories only apply to income/expense, not transfers; allow null otherwise.
        if (in_array($type, ['income','expense']) && $cat['type'] !== $type) {
            jsonError('Category type does not match transaction type.');
        }
    } else {
        $categoryId = null;
    }

    $pdo->prepare(
        'UPDATE transactions SET date = ?, type = ?, amount = ?, currency = ?, account_id = ?,
                                 category_id = ?, counterparty = ?, description = ?, updated_at = ?
         WHERE id = ?'
    )->execute([
        $date, $type, round($amount, 2), $account['currency'], $accountId,
        $categoryId, mb_substr($counterparty, 0, 200), mb_substr($description, 0, 500),
        date('c'), $id,
    ]);
    audit($pdo, 'updated', 'transaction', $id, $row, [
        'date' => $date, 'type' => $type, 'amount' => $amount,
        'account_id' => $accountId, 'category_id' => $categoryId,
    ]);
    jsonResponse(['success' => true]);
}

// ─────────────────────────────────────────────────────────────
// void / unvoid — soft-delete with cascade across linked rows.
// ─────────────────────────────────────────────────────────────
if ($action === 'void' || $action === 'unvoid') {
    $id = trim((string) ($input['id'] ?? ''));
    $row = lookup($pdo, 'transactions', $id);
    if (!$row) jsonError('Transaction not found.', 404);

    $cascade = !empty($input['cascade']);
    $flag    = $action === 'void' ? 1 : 0;

    $ids = [$id];
    if ($cascade) {
        if (!empty($row['linked_tx_id'])) {
            $stmt = $pdo->prepare('SELECT id FROM transactions WHERE linked_tx_id = ?');
            $stmt->execute([$row['linked_tx_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tid) $ids[] = $tid;
        }
        if (!empty($row['split_group_id'])) {
            $stmt = $pdo->prepare('SELECT id FROM transactions WHERE split_group_id = ?');
            $stmt->execute([$row['split_group_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tid) $ids[] = $tid;
        }
        $ids = array_values(array_unique($ids));
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE transactions SET void = ?, updated_at = ? WHERE id = ?');
        $now  = date('c');
        foreach ($ids as $tid) $stmt->execute([$flag, $now, $tid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit($pdo, $action === 'void' ? 'voided' : 'unvoided', 'transaction', $id, $row, ['ids' => $ids]);
    jsonResponse(['success' => true, 'affected_ids' => $ids]);
}

jsonError('Invalid action.');
