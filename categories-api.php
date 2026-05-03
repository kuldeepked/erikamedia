<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

const CAT_TYPES = ['income','expense'];

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $includeArchived = !empty($_GET['include_archived']);
    $bookId          = trim((string) ($_GET['book_id'] ?? ''));
    $type            = trim((string) ($_GET['type'] ?? ''));

    // Pull each category with its all-time aggregate (count + sum) of non-void transactions.
    // Aggregation crosses currency, so we also expose currency breakdown.
    $sql = 'SELECT c.*,
                   COALESCE((SELECT COUNT(*) FROM transactions t
                             WHERE t.category_id = c.id AND t.void = 0), 0) AS tx_count,
                   COALESCE((SELECT SUM(amount) FROM transactions t
                             WHERE t.category_id = c.id AND t.void = 0), 0) AS tx_total
            FROM categories c';
    $where = [];
    $args  = [];
    if (!$includeArchived) $where[] = 'c.archived = 0';
    if ($bookId !== '') {
        $where[] = '(c.book_scope = ? OR c.book_scope IS NULL)';
        $args[]  = $bookId;
    }
    if ($type !== '') {
        if (!in_array($type, CAT_TYPES)) jsonError('Invalid type filter.');
        $where[] = 'c.type = ?';
        $args[]  = $type;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY c.type, c.display_order, c.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $cats = $stmt->fetchAll();

    // Per-currency breakdown (one extra query — light enough).
    $bd = $pdo->query(
        'SELECT category_id, currency, SUM(amount) AS total, COUNT(*) AS n
         FROM transactions
         WHERE void = 0 AND category_id IS NOT NULL
         GROUP BY category_id, currency'
    )->fetchAll();
    $byCat = [];
    foreach ($bd as $row) {
        $byCat[$row['category_id']][] = [
            'currency' => $row['currency'],
            'total'    => (float) $row['total'],
            'count'    => (int)   $row['n'],
        ];
    }
    foreach ($cats as &$c) {
        $c['by_currency'] = $byCat[$c['id']] ?? [];
    }
    unset($c);

    jsonResponse(['categories' => $cats]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'create') {
    $name           = trim((string) ($input['name'] ?? ''));
    $type           = trim((string) ($input['type'] ?? ''));
    $parentId       = trim((string) ($input['parent_id'] ?? ''));
    $bookScope      = trim((string) ($input['book_scope'] ?? ''));
    $linkedEmployee = trim((string) ($input['linked_employee'] ?? ''));

    if ($name === '')                jsonError('Name is required.');
    if (!in_array($type, CAT_TYPES)) jsonError('Type must be income or expense.');

    $parentIdParam = $parentId === '' ? null : $parentId;
    $bookScopeParam = $bookScope === '' ? null : $bookScope;

    if ($parentIdParam !== null) {
        $stmt = $pdo->prepare('SELECT type FROM categories WHERE id = ?');
        $stmt->execute([$parentIdParam]);
        $parentType = $stmt->fetchColumn();
        if (!$parentType)            jsonError('Parent category not found.');
        if ($parentType !== $type)   jsonError('Child must match parent type (' . $parentType . ').');
    }
    if ($bookScopeParam !== null) {
        $stmt = $pdo->prepare('SELECT 1 FROM books WHERE id = ?');
        $stmt->execute([$bookScopeParam]);
        if (!$stmt->fetchColumn()) jsonError('Book not found.');
    }

    $id = newId('cat');
    $order = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM categories')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO categories (id, name, type, parent_id, book_scope, linked_employee, display_order, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $name, $type, $parentIdParam, $bookScopeParam, $linkedEmployee, $order, date('c')]);

    audit($pdo, 'created', 'category', $id, null, ['name' => $name, 'type' => $type]);
    jsonResponse(['success' => true, 'id' => $id]);
}

if ($action === 'update') {
    $id             = trim((string) ($input['id'] ?? ''));
    $name           = trim((string) ($input['name'] ?? ''));
    $type           = trim((string) ($input['type'] ?? ''));
    $parentId       = trim((string) ($input['parent_id'] ?? ''));
    $bookScope      = trim((string) ($input['book_scope'] ?? ''));
    $linkedEmployee = trim((string) ($input['linked_employee'] ?? ''));

    if ($id === '' || $name === '')  jsonError('id and name are required.');
    if (!in_array($type, CAT_TYPES)) jsonError('Type must be income or expense.');

    $before = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $before->execute([$id]);
    $row = $before->fetch();
    if (!$row) jsonError('Category not found.', 404);

    $parentIdParam  = $parentId === '' ? null : $parentId;
    $bookScopeParam = $bookScope === '' ? null : $bookScope;

    if ($parentIdParam === $id) jsonError('Category cannot be its own parent.');

    $pdo->prepare(
        'UPDATE categories SET name = ?, type = ?, parent_id = ?, book_scope = ?, linked_employee = ? WHERE id = ?'
    )->execute([$name, $type, $parentIdParam, $bookScopeParam, $linkedEmployee, $id]);

    audit($pdo, 'updated', 'category', $id, $row, [
        'name' => $name, 'type' => $type, 'parent_id' => $parentIdParam,
        'book_scope' => $bookScopeParam, 'linked_employee' => $linkedEmployee,
    ]);
    jsonResponse(['success' => true]);
}

if ($action === 'archive') {
    $id   = trim((string) ($input['id'] ?? ''));
    $flag = (int) (!empty($input['archived']));
    if ($id === '') jsonError('id is required.');
    $pdo->prepare('UPDATE categories SET archived = ? WHERE id = ?')->execute([$flag, $id]);
    audit($pdo, $flag ? 'archived' : 'unarchived', 'category', $id, null, null);
    jsonResponse(['success' => true]);
}

// Auto-create salary sub-categories under a parent for every employee in employees.json.
// Idempotent: skips employees that already have a linked category.
if ($action === 'sync_employee_salary_categories') {
    $parentId = trim((string) ($input['parent_id'] ?? ''));
    if ($parentId === '') jsonError('parent_id is required.');

    $stmt = $pdo->prepare('SELECT type FROM categories WHERE id = ?');
    $stmt->execute([$parentId]);
    $parentType = $stmt->fetchColumn();
    if ($parentType !== 'expense') jsonError('Parent must be an expense category.');

    $employees = json_decode((string) @file_get_contents(__DIR__ . '/employees.json'), true);
    if (!is_array($employees)) jsonError('Could not read employees.json.', 500);

    $existing = $pdo->prepare('SELECT 1 FROM categories WHERE linked_employee = ? AND parent_id = ?');
    $insert = $pdo->prepare(
        'INSERT INTO categories (id, name, type, parent_id, linked_employee, display_order, created_at)
         VALUES (?, ?, "expense", ?, ?, ?, ?)'
    );

    $created = [];
    $now = date('c');
    foreach ($employees as $i => $emp) {
        $name = trim((string) ($emp['name'] ?? ''));
        if ($name === '') continue;
        $existing->execute([$name, $parentId]);
        if ($existing->fetchColumn()) continue;
        $id = newId('cat');
        $insert->execute([$id, $name, $parentId, $name, 100 + $i, $now]);
        $created[] = $name;
    }

    jsonResponse(['success' => true, 'created' => $created]);
}

jsonError('Invalid action.');
