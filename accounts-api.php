<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

const ACC_TYPES      = ['bank','cash','wallet','crypto'];
const ACC_CURRENCIES = ['PKR','USD','USDT','EUR','AED','GBP'];

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $includeArchived = !empty($_GET['include_archived']);
    $bookId          = trim((string) ($_GET['book_id'] ?? ''));

    $sql  = 'SELECT a.*,
                    a.opening_balance
                    + COALESCE((SELECT SUM(amount) FROM transactions
                                WHERE account_id = a.id AND void = 0
                                  AND type IN ("income","transfer_in")), 0)
                    - COALESCE((SELECT SUM(amount) FROM transactions
                                WHERE account_id = a.id AND void = 0
                                  AND type IN ("expense","transfer_out")), 0)
                    AS balance
             FROM accounts a';
    $where = [];
    $args  = [];
    if (!$includeArchived) $where[] = 'a.archived = 0';
    if ($bookId !== '') {
        $where[] = '(a.book_id = ? OR a.book_id IS NULL)';
        $args[]  = $bookId;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.display_order, a.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    jsonResponse(['accounts' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'create') {
    $name            = trim((string) ($input['name'] ?? ''));
    $type            = trim((string) ($input['type'] ?? ''));
    $currency        = strtoupper(trim((string) ($input['currency'] ?? 'PKR')));
    $bookId          = trim((string) ($input['book_id'] ?? ''));
    $openingBalance  = (float) ($input['opening_balance'] ?? 0);
    $notes           = trim((string) ($input['notes'] ?? ''));

    if ($name === '')                          jsonError('Name is required.');
    if (!in_array($type, ACC_TYPES))           jsonError('Type must be one of: ' . implode(', ', ACC_TYPES));
    if (!in_array($currency, ACC_CURRENCIES))  jsonError('Currency must be one of: ' . implode(', ', ACC_CURRENCIES));

    $bookIdParam = $bookId === '' ? null : $bookId;
    if ($bookIdParam !== null) {
        $check = $pdo->prepare('SELECT 1 FROM books WHERE id = ?');
        $check->execute([$bookIdParam]);
        if (!$check->fetchColumn()) jsonError('Book not found.');
    }

    $clash = $pdo->prepare('SELECT 1 FROM accounts WHERE name = ?');
    $clash->execute([$name]);
    if ($clash->fetchColumn()) jsonError('An account with that name already exists.');

    $id = newId('acc');
    $order = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM accounts')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO accounts (id, name, type, currency, book_id, opening_balance, notes, display_order, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $name, $type, $currency, $bookIdParam, $openingBalance, $notes, $order, date('c')]);

    audit($pdo, 'created', 'account', $id, null, [
        'name' => $name, 'type' => $type, 'currency' => $currency,
        'book_id' => $bookIdParam, 'opening_balance' => $openingBalance,
    ]);
    jsonResponse(['success' => true, 'id' => $id]);
}

if ($action === 'update') {
    $id              = trim((string) ($input['id'] ?? ''));
    $name            = trim((string) ($input['name'] ?? ''));
    $type            = trim((string) ($input['type'] ?? ''));
    $currency        = strtoupper(trim((string) ($input['currency'] ?? '')));
    $bookId          = trim((string) ($input['book_id'] ?? ''));
    $openingBalance  = (float) ($input['opening_balance'] ?? 0);
    $notes           = trim((string) ($input['notes'] ?? ''));

    if ($id === '' || $name === '')            jsonError('id and name are required.');
    if (!in_array($type, ACC_TYPES))           jsonError('Type must be one of: ' . implode(', ', ACC_TYPES));
    if (!in_array($currency, ACC_CURRENCIES))  jsonError('Currency must be one of: ' . implode(', ', ACC_CURRENCIES));

    $before = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $before->execute([$id]);
    $row = $before->fetch();
    if (!$row) jsonError('Account not found.', 404);

    $clash = $pdo->prepare('SELECT 1 FROM accounts WHERE name = ? AND id <> ?');
    $clash->execute([$name, $id]);
    if ($clash->fetchColumn()) jsonError('Another account already has that name.');

    $bookIdParam = $bookId === '' ? null : $bookId;
    if ($bookIdParam !== null) {
        $check = $pdo->prepare('SELECT 1 FROM books WHERE id = ?');
        $check->execute([$bookIdParam]);
        if (!$check->fetchColumn()) jsonError('Book not found.');
    }

    $pdo->prepare(
        'UPDATE accounts SET name = ?, type = ?, currency = ?, book_id = ?, opening_balance = ?, notes = ? WHERE id = ?'
    )->execute([$name, $type, $currency, $bookIdParam, $openingBalance, $notes, $id]);

    audit($pdo, 'updated', 'account', $id, $row, [
        'name' => $name, 'type' => $type, 'currency' => $currency,
        'book_id' => $bookIdParam, 'opening_balance' => $openingBalance,
    ]);
    jsonResponse(['success' => true]);
}

if ($action === 'archive') {
    $id   = trim((string) ($input['id'] ?? ''));
    $flag = (int) (!empty($input['archived']));
    if ($id === '') jsonError('id is required.');

    $stmt = $pdo->prepare('UPDATE accounts SET archived = ? WHERE id = ?');
    $stmt->execute([$flag, $id]);
    audit($pdo, $flag ? 'archived' : 'unarchived', 'account', $id, null, null);
    jsonResponse(['success' => true]);
}

if ($action === 'reorder') {
    $order = $input['order'] ?? [];
    if (!is_array($order)) jsonError('order must be an array of account ids.');

    $stmt = $pdo->prepare('UPDATE accounts SET display_order = ? WHERE id = ?');
    foreach ($order as $i => $accId) {
        if (is_string($accId) && $accId !== '') $stmt->execute([$i, $accId]);
    }
    jsonResponse(['success' => true]);
}

jsonError('Invalid action.');
