<?php
// SQLite database layer. PDO + auto-init schema on first call.
//
// Stores: books (Erika / Kuldeep), accounts (bank/cash/wallet/crypto),
// categories (chart of accounts), transactions, audit_log, meta.
//
// Migration from finances.json / petty-cash.json runs once, gated by
// the meta key "migrated_v1" so re-deploys don't re-import.

const DB_DIR  = __DIR__ . '/db';
const DB_PATH = __DIR__ . '/db/erika.sqlite';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(DB_DIR)) {
        @mkdir(DB_DIR, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    initSchema($pdo);
    seedDefaults($pdo);
    runMigrations($pdo);

    return $pdo;
}

function initSchema(PDO $pdo): void {
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS books (
            id            TEXT PRIMARY KEY,
            name          TEXT NOT NULL UNIQUE,
            type          TEXT NOT NULL CHECK(type IN ('business','personal')),
            display_order INTEGER NOT NULL DEFAULT 0,
            archived      INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS accounts (
            id              TEXT PRIMARY KEY,
            name            TEXT NOT NULL UNIQUE,
            type            TEXT NOT NULL CHECK(type IN ('bank','cash','wallet','crypto')),
            currency        TEXT NOT NULL DEFAULT 'PKR',
            book_id         TEXT NULL REFERENCES books(id) ON DELETE SET NULL,
            opening_balance REAL NOT NULL DEFAULT 0,
            notes           TEXT NOT NULL DEFAULT '',
            archived        INTEGER NOT NULL DEFAULT 0,
            display_order   INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS categories (
            id              TEXT PRIMARY KEY,
            name            TEXT NOT NULL,
            type            TEXT NOT NULL CHECK(type IN ('income','expense')),
            parent_id       TEXT NULL REFERENCES categories(id) ON DELETE SET NULL,
            book_scope      TEXT NULL REFERENCES books(id) ON DELETE SET NULL,
            linked_employee TEXT NOT NULL DEFAULT '',
            archived        INTEGER NOT NULL DEFAULT 0,
            display_order   INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS transactions (
            id              TEXT PRIMARY KEY,
            date            TEXT NOT NULL,
            book_id         TEXT NOT NULL REFERENCES books(id),
            type            TEXT NOT NULL CHECK(type IN ('income','expense','transfer_in','transfer_out')),
            amount          REAL NOT NULL CHECK(amount > 0),
            currency        TEXT NOT NULL DEFAULT 'PKR',
            account_id      TEXT NOT NULL REFERENCES accounts(id),
            category_id     TEXT NULL REFERENCES categories(id) ON DELETE SET NULL,
            counterparty    TEXT NOT NULL DEFAULT '',
            description     TEXT NOT NULL DEFAULT '',
            linked_tx_id    TEXT NULL,
            split_group_id  TEXT NULL,
            void            INTEGER NOT NULL DEFAULT 0,
            created_at      TEXT NOT NULL,
            updated_at      TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_tx_date         ON transactions(date);
        CREATE INDEX IF NOT EXISTS idx_tx_book_date    ON transactions(book_id, date);
        CREATE INDEX IF NOT EXISTS idx_tx_account      ON transactions(account_id);
        CREATE INDEX IF NOT EXISTS idx_tx_category     ON transactions(category_id);
        CREATE INDEX IF NOT EXISTS idx_tx_linked       ON transactions(linked_tx_id);
        CREATE INDEX IF NOT EXISTS idx_tx_split        ON transactions(split_group_id);
        CREATE INDEX IF NOT EXISTS idx_tx_void         ON transactions(void);
        CREATE INDEX IF NOT EXISTS idx_cat_parent      ON categories(parent_id);
        CREATE INDEX IF NOT EXISTS idx_cat_employee    ON categories(linked_employee);

        CREATE TABLE IF NOT EXISTS audit_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ts          TEXT NOT NULL,
            user        TEXT NOT NULL,
            action      TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id   TEXT NOT NULL,
            before_json TEXT NULL,
            after_json  TEXT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
        CREATE INDEX IF NOT EXISTS idx_audit_ts     ON audit_log(ts);
    SQL);
}

function seedDefaults(PDO $pdo): void {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
    if ($count > 0) return;

    $now = date('c');
    $stmt = $pdo->prepare(
        'INSERT INTO books (id, name, type, display_order, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute(['book_erika',   'Erika Media',          'business', 0, $now]);
    $stmt->execute(['book_kuldeep', 'Kuldeep (Personal)',   'personal', 1, $now]);
}

function runMigrations(PDO $pdo): void {
    $migrated = $pdo->query("SELECT value FROM meta WHERE key='migrated_v1'")->fetchColumn();
    if ($migrated === 'done') return;

    $pdo->beginTransaction();
    try {
        migrateLegacyJson($pdo);
        $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')
            ->execute(['migrated_v1', 'done']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function migrateLegacyJson(PDO $pdo): void {
    $now = date('c');
    $erikaId = 'book_erika';

    $legacyMain  = readLegacyJson(__DIR__ . '/finances.json');
    $legacyPetty = readLegacyJson(__DIR__ . '/petty-cash.json');
    if (!$legacyMain && !$legacyPetty) return;

    // Create the placeholder accounts + categories the legacy entries land in.
    $pdo->prepare(
        'INSERT INTO accounts (id, name, type, currency, book_id, opening_balance, notes, display_order, created_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([
        'acc_legacy_main', 'Legacy (uncategorised)', 'bank', 'PKR', $erikaId,
        'Auto-created during migration. Re-categorise these entries to your real accounts.',
        0, $now,
    ]);

    if ($legacyPetty) {
        $pdo->prepare(
            'INSERT INTO accounts (id, name, type, currency, book_id, opening_balance, notes, display_order, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            'acc_petty_cash', 'Petty Cash', 'cash', 'PKR', $erikaId,
            'Migrated from petty-cash.json.', 1, $now,
        ]);
    }

    $catIn = 'cat_uncat_in';
    $catOut = 'cat_uncat_out';
    $catStmt = $pdo->prepare(
        'INSERT INTO categories (id, name, type, display_order, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $catStmt->execute([$catIn,  'Uncategorised — Income',  'income',  0, $now]);
    $catStmt->execute([$catOut, 'Uncategorised — Expense', 'expense', 1, $now]);

    $insert = $pdo->prepare(
        'INSERT INTO transactions
            (id, date, book_id, type, amount, currency, account_id, category_id,
             counterparty, description, void, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );

    $importEntry = function (array $entry, string $accountId) use ($insert, $erikaId, $catIn, $catOut, $now) {
        $type   = ($entry['type'] ?? '') === 'in' ? 'income' : 'expense';
        $catId  = $type === 'income' ? $catIn : $catOut;
        $insert->execute([
            $entry['id'] ?? ('tx_' . bin2hex(random_bytes(6))),
            $entry['date'] ?? date('Y-m-d'),
            $erikaId,
            $type,
            (float) ($entry['amount'] ?? 0),
            'PKR',
            $accountId,
            $catId,
            '',
            (string) ($entry['description'] ?? ''),
            $entry['created_at'] ?? $now,
            $now,
        ]);
    };

    foreach ($legacyMain  as $e) $importEntry($e, 'acc_legacy_main');
    foreach ($legacyPetty as $e) $importEntry($e, 'acc_petty_cash');
}

function readLegacyJson(string $path): array {
    if (!file_exists($path)) return [];
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function newId(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(6));
}

function audit(PDO $pdo, string $action, string $entityType, string $entityId, ?array $before, ?array $after): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (ts, user, action, entity_type, entity_id, before_json, after_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        date('c'),
        $_SESSION['admin_user'] ?? 'system',
        $action,
        $entityType,
        $entityId,
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
    ]);
}

function jsonResponse($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    jsonResponse(['error' => $msg], $code);
}

function readJsonBody(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [];
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}
