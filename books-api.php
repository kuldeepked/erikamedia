<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $books = $pdo->query(
        'SELECT * FROM books WHERE archived = 0 ORDER BY display_order, name'
    )->fetchAll();
    jsonResponse(['books' => $books]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'create') {
    $name = trim((string) ($input['name'] ?? ''));
    $type = trim((string) ($input['type'] ?? 'business'));
    if ($name === '')                              jsonError('Name is required.');
    if (!in_array($type, ['business','personal'])) jsonError('Type must be business or personal.');

    $exists = $pdo->prepare('SELECT 1 FROM books WHERE name = ?');
    $exists->execute([$name]);
    if ($exists->fetchColumn()) jsonError('A book with that name already exists.');

    $id = newId('book');
    $order = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM books')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO books (id, name, type, display_order, created_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$id, $name, $type, $order, date('c')]);

    audit($pdo, 'created', 'book', $id, null, ['name' => $name, 'type' => $type]);
    jsonResponse(['success' => true, 'id' => $id]);
}

if ($action === 'update') {
    $id   = trim((string) ($input['id'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $type = trim((string) ($input['type'] ?? ''));
    if ($id === '' || $name === '')                jsonError('id and name are required.');
    if (!in_array($type, ['business','personal'])) jsonError('Type must be business or personal.');

    $before = $pdo->prepare('SELECT * FROM books WHERE id = ?');
    $before->execute([$id]);
    $row = $before->fetch();
    if (!$row) jsonError('Book not found.', 404);

    $clash = $pdo->prepare('SELECT 1 FROM books WHERE name = ? AND id <> ?');
    $clash->execute([$name, $id]);
    if ($clash->fetchColumn()) jsonError('Another book already has that name.');

    $pdo->prepare('UPDATE books SET name = ?, type = ? WHERE id = ?')
        ->execute([$name, $type, $id]);

    audit($pdo, 'updated', 'book', $id, $row, ['name' => $name, 'type' => $type]);
    jsonResponse(['success' => true]);
}

if ($action === 'archive') {
    $id   = trim((string) ($input['id'] ?? ''));
    $flag = (int) (!empty($input['archived']));
    if ($id === '') jsonError('id is required.');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE book_id = ? AND void = 0');
    $stmt->execute([$id]);
    $count = (int) $stmt->fetchColumn();
    if ($flag === 1 && $count > 0) {
        jsonError('Cannot archive: this book has ' . $count . ' active transactions. Void or move them first.');
    }

    $pdo->prepare('UPDATE books SET archived = ? WHERE id = ?')->execute([$flag, $id]);
    audit($pdo, $flag ? 'archived' : 'unarchived', 'book', $id, null, null);
    jsonResponse(['success' => true]);
}

jsonError('Invalid action.');
