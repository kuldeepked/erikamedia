<?php
// Recurring income and expense rules — rent, internet, subscriptions: the fixed
// monthly costs that otherwise get remembered late or not at all, which is
// exactly how a set of books drifts out of line with reality.
//
// A rule is a template, not a record. Nothing it describes exists in the ledger
// until the rule is run, at which point it writes one ordinary transaction per
// period it still owes, each stamped source='recurring' and source_ref=<rule id>.
//
// That stamp is what makes running a rule twice harmless, and it is deliberately
// the only thing consulted when deciding what is outstanding — see
// recurringMissedPeriods().
//
// Everything above the last section is a plain function taking an explicit
// "today", so the whole thing can be exercised without a request and without
// waiting for a calendar month to pass.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger-lib.php';

const RECURRING_SOURCE = 'recurring';
const RECURRING_TYPES  = ['income', 'expense'];

/**
 * Validation failures travel as exceptions rather than calling jsonError()
 * directly: jsonError() exits, which would make every function below callable
 * only from a live request. The dispatcher turns them back into JSON.
 */
class RecurringError extends RuntimeException {}

// ── Periods and dates ────────────────────────────────────────────────────────

function recurringAddMonths(string $period, int $n): string {
    return date('Y-m', strtotime($period . '-01 ' . ($n >= 0 ? '+' : '-') . abs($n) . ' month'));
}

/**
 * The day a rule's charge falls on inside $period, clamped to the length of
 * that month.
 *
 * A rule that says "the 31st" has to land on 28 February. Letting the date roll
 * forward into 3 March would file February's charge in March — February would
 * read as cheap, March as expensive, and the row would never line up with the
 * statement line it belongs to.
 */
function recurringDateFor(string $period, int $day): string {
    $len = (int) date('t', strtotime($period . '-01'));
    if ($day < 1) $day = 1;
    return sprintf('%s-%02d', $period, min($day, $len));
}

/**
 * Every period this rule has ever written into the ledger.
 *
 * Voided rows count. A run that resurrected a row somebody deliberately voided
 * would be a worse failure than one that leaves a gap — and it would do it
 * again on every subsequent run. Bringing a voided period back is a job for
 * unvoiding it, where the decision is visible.
 */
function recurringGeneratedPeriods(PDO $pdo, string $ruleId): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT substr(date, 1, 7) AS period
         FROM transactions WHERE source = ? AND source_ref = ? ORDER BY period'
    );
    $stmt->execute([RECURRING_SOURCE, $ruleId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Periods the rule still owes the ledger as at $today, oldest first.
 *
 * last_run_period is not consulted. It is a convenience field on a row anyone
 * can edit, and a rule restored from a backup or corrected by hand carries a
 * value that is either behind the ledger — and would duplicate a month — or
 * ahead of it, and would silently skip one. What was actually written is the
 * only thing that can answer this.
 *
 * A period is owed only once its due date has arrived. Generating this month's
 * rent on the 3rd when it is charged on the 25th would post a future-dated row,
 * which the integrity report flags as an error in its own right.
 */
function recurringMissedPeriods(array $rule, array $generated, string $today): array {
    $start = (string) $rule['start_period'];
    $end   = trim((string) ($rule['end_period'] ?? ''));
    $day   = (int) $rule['day_of_month'];

    $limit = substr($today, 0, 7);
    if ($end !== '' && $end < $limit) $limit = $end;
    if ($start > $limit) return [];

    $done = array_flip($generated);
    $out  = [];
    foreach (monthsInRange($start . '-01', $limit . '-01') as $period) {
        if (isset($done[$period])) continue;
        if (recurringDateFor($period, $day) > $today) continue;
        $out[] = $period;
    }
    return $out;
}

/**
 * The next period the rule will generate: the oldest one it owes if it is
 * behind, otherwise the first one still ahead of it. Null once end_period has
 * passed and there is nothing left to write.
 */
function recurringNextDue(array $rule, array $generated, array $missed, string $today): ?string {
    if ($missed) return $missed[0];

    $end  = trim((string) ($rule['end_period'] ?? ''));
    $done = array_flip($generated);
    $now  = substr($today, 0, 7);

    $period = (string) $rule['start_period'];
    if ($period < $now) $period = $now;

    // Walk past anything already written. Twenty-four steps is far more than a
    // sane rule needs and stops a corrupted row spinning here forever.
    for ($i = 0; $i < 24; $i++) {
        if ($end !== '' && $period > $end) return null;
        if (!isset($done[$period])) return $period;
        $period = recurringAddMonths($period, 1);
    }
    return null;
}

// ── Reading ──────────────────────────────────────────────────────────────────

function recurringLookup(PDO $pdo, string $table, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function recurringSelectSql(string $where = ''): string {
    // Left joins throughout: a rule whose account or category went missing must
    // still show up on the list so it can be fixed, not disappear silently.
    return 'SELECT r.*,
                   a.name     AS account_name,
                   a.currency AS account_currency,
                   a.archived AS account_archived,
                   c.name     AS category_name,
                   c.type     AS category_type,
                   b.name     AS book_name
            FROM recurring_rules r
            LEFT JOIN accounts   a ON a.id = r.account_id
            LEFT JOIN categories c ON c.id = r.category_id
            LEFT JOIN books      b ON b.id = r.book_id'
        . ($where !== '' ? ' WHERE ' . $where : '')
        . ' ORDER BY r.active DESC, r.name COLLATE NOCASE';
}

/** One rule with its joined names and due annotations. */
function recurringGet(PDO $pdo, string $id, string $today): array {
    $stmt = $pdo->prepare(recurringSelectSql('r.id = ?'));
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RecurringError('Recurring rule not found.', 404);
    return recurringAnnotate($pdo, $row, $today);
}

function recurringAnnotate(PDO $pdo, array $rule, string $today): array {
    $generated = recurringGeneratedPeriods($pdo, (string) $rule['id']);
    $missed    = recurringMissedPeriods($rule, $generated, $today);

    $rule['amount']       = round((float) $rule['amount'], 2);
    $rule['day_of_month'] = (int) $rule['day_of_month'];
    $rule['active']       = (int) $rule['active'];
    $rule['account_archived'] = (int) ($rule['account_archived'] ?? 0);

    $rule['generated_periods'] = $generated;
    $rule['missed_periods']    = $missed;
    $rule['missed_count']      = count($missed);
    $rule['missed_total']      = round($rule['amount'] * count($missed), 2);
    $rule['is_due']            = $rule['active'] === 1 && count($missed) > 0;
    $rule['next_due_period']   = recurringNextDue($rule, $generated, $missed, $today);
    $rule['next_due_date']     = $rule['next_due_period'] === null
        ? null
        : recurringDateFor($rule['next_due_period'], $rule['day_of_month']);

    return $rule;
}

function recurringList(PDO $pdo, string $today): array {
    $rules = [];
    $due   = 0;
    foreach ($pdo->query(recurringSelectSql())->fetchAll() as $row) {
        $rule = recurringAnnotate($pdo, $row, $today);
        if ($rule['is_due']) $due++;
        $rules[] = $rule;
    }
    return [
        'rules'          => $rules,
        'due_count'      => $due,
        'today'          => $today,
        'current_period' => substr($today, 0, 7),
    ];
}

// ── Validation ───────────────────────────────────────────────────────────────

/** Tolerant boolean: the UI sends JSON true/false, older callers send '0'/'1'. */
function recurringFlag($value, int $default): int {
    if ($value === null) return $default;
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_numeric($value)) return ((int) $value) === 0 ? 0 : 1;
    $s = strtolower(trim((string) $value));
    if ($s === '') return $default;
    return in_array($s, ['0', 'false', 'no', 'off'], true) ? 0 : 1;
}

/**
 * Clean, validated field set for create/update. $existing supplies the fallback
 * for anything the caller left out, so an update that only changes the amount
 * does not have to resend the whole rule.
 */
function recurringValidate(PDO $pdo, array $in, ?array $existing = null): array {
    $name = trim((string) ($in['name'] ?? ($existing['name'] ?? '')));
    if ($name === '') throw new RecurringError('Name is required.');

    $type = trim((string) ($in['type'] ?? ($existing['type'] ?? '')));
    if (!in_array($type, RECURRING_TYPES, true)) {
        throw new RecurringError('Type must be income or expense.');
    }

    $amount = (float) ($in['amount'] ?? ($existing['amount'] ?? 0));
    if ($amount <= 0) throw new RecurringError('Amount must be greater than zero.');

    $bookId = trim((string) ($in['book_id'] ?? ($existing['book_id'] ?? '')));
    if ($bookId === '') $bookId = (string) (primaryBusinessBookId($pdo) ?? '');
    if ($bookId === '') throw new RecurringError('book_id is required.');
    if (!recurringLookup($pdo, 'books', $bookId)) throw new RecurringError('Book not found.');

    $accountId = trim((string) ($in['account_id'] ?? ($existing['account_id'] ?? '')));
    if ($accountId === '') throw new RecurringError('account_id is required.');
    $account = recurringLookup($pdo, 'accounts', $accountId);
    if (!$account) throw new RecurringError('Account not found: ' . $accountId);
    if ((int) $account['archived'] === 1) {
        throw new RecurringError('That account is archived. Pick a live account to post to.');
    }

    $currency = strtoupper(trim((string) ($in['currency'] ?? '')));
    if ($currency === '') $currency = (string) $account['currency'];
    if ($currency !== $account['currency']) {
        throw new RecurringError('Currency mismatch with account (' . $account['currency'] . ').');
    }

    // Sent as '' or null, the category is being cleared; omitted altogether, it
    // is being left alone. An update that posts the whole rule back needs both
    // to be possible.
    $categoryRaw = array_key_exists('category_id', $in) ? $in['category_id'] : ($existing['category_id'] ?? '');
    $categoryId  = $categoryRaw === null ? '' : trim((string) $categoryRaw);
    if ($categoryId !== '') {
        $category = recurringLookup($pdo, 'categories', $categoryId);
        if (!$category)                  throw new RecurringError('Category not found.');
        if ($category['type'] !== $type) throw new RecurringError('Category type does not match the rule type.');
    } else {
        $categoryId = null;
    }

    $day = (int) ($in['day_of_month'] ?? ($existing['day_of_month'] ?? 1));
    if ($day < 1 || $day > 31) throw new RecurringError('Day of month must be between 1 and 31.');

    $start = trim((string) ($in['start_period'] ?? ($existing['start_period'] ?? '')));
    if (!isMonth($start)) throw new RecurringError('start_period must be a month in YYYY-MM format.');

    $endRaw = array_key_exists('end_period', $in) ? $in['end_period'] : ($existing['end_period'] ?? null);
    $end    = $endRaw === null ? '' : trim((string) $endRaw);
    if ($end === '') {
        $end = null;
    } elseif (!isMonth($end)) {
        throw new RecurringError('end_period must be a month in YYYY-MM format.');
    } elseif ($end < $start) {
        throw new RecurringError('end_period cannot be before start_period.');
    }

    return [
        'name'         => mb_substr($name, 0, 120),
        'book_id'      => $bookId,
        'type'         => $type,
        'amount'       => round($amount, 2),
        'currency'     => $currency,
        'account_id'   => $accountId,
        'category_id'  => $categoryId,
        'counterparty' => mb_substr(trim((string) ($in['counterparty'] ?? ($existing['counterparty'] ?? ''))), 0, 200),
        'description'  => mb_substr(trim((string) ($in['description'] ?? ($existing['description'] ?? ''))), 0, 500),
        'day_of_month' => $day,
        'start_period' => $start,
        'end_period'   => $end,
        'active'       => recurringFlag($in['active'] ?? null, (int) ($existing['active'] ?? 1)),
    ];
}

// ── Writing ──────────────────────────────────────────────────────────────────

function recurringCreate(PDO $pdo, array $in, string $today): array {
    $f  = recurringValidate($pdo, $in, null);
    $id = newId('rec');

    $pdo->prepare(
        'INSERT INTO recurring_rules
            (id, name, book_id, type, amount, currency, account_id, category_id,
             counterparty, description, day_of_month, start_period, end_period,
             last_run_period, active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
    )->execute([
        $id, $f['name'], $f['book_id'], $f['type'], $f['amount'], $f['currency'],
        $f['account_id'], $f['category_id'], $f['counterparty'], $f['description'],
        $f['day_of_month'], $f['start_period'], $f['end_period'], $f['active'], date('c'),
    ]);

    audit($pdo, 'created', 'recurring_rule', $id, null, $f);
    return ['success' => true, 'id' => $id, 'rule' => recurringGet($pdo, $id, $today)];
}

function recurringUpdate(PDO $pdo, array $in, string $today): array {
    $id = trim((string) ($in['id'] ?? ''));
    if ($id === '') throw new RecurringError('id is required.');
    $before = recurringLookup($pdo, 'recurring_rules', $id);
    if (!$before) throw new RecurringError('Recurring rule not found.', 404);

    $f = recurringValidate($pdo, $in, $before);

    // last_run_period is left alone. It is only ever a report of what happened,
    // and nothing here changes what happened.
    $pdo->prepare(
        'UPDATE recurring_rules
            SET name = ?, book_id = ?, type = ?, amount = ?, currency = ?, account_id = ?,
                category_id = ?, counterparty = ?, description = ?, day_of_month = ?,
                start_period = ?, end_period = ?, active = ?
          WHERE id = ?'
    )->execute([
        $f['name'], $f['book_id'], $f['type'], $f['amount'], $f['currency'], $f['account_id'],
        $f['category_id'], $f['counterparty'], $f['description'], $f['day_of_month'],
        $f['start_period'], $f['end_period'], $f['active'], $id,
    ]);

    audit($pdo, 'updated', 'recurring_rule', $id, $before, $f);
    return ['success' => true, 'id' => $id, 'rule' => recurringGet($pdo, $id, $today)];
}

/**
 * Hard delete. A rule is a template — the transactions it already generated are
 * ordinary rows and stay exactly where they are, keeping their source_ref as a
 * record of where they came from.
 */
function recurringDelete(PDO $pdo, array $in): array {
    $id = trim((string) ($in['id'] ?? ''));
    if ($id === '') throw new RecurringError('id is required.');
    $before = recurringLookup($pdo, 'recurring_rules', $id);
    if (!$before) throw new RecurringError('Recurring rule not found.', 404);

    $generated = recurringGeneratedPeriods($pdo, $id);
    $pdo->prepare('DELETE FROM recurring_rules WHERE id = ?')->execute([$id]);

    audit($pdo, 'deleted', 'recurring_rule', $id, $before, ['generated_periods' => $generated]);
    return ['success' => true, 'id' => $id, 'generated_periods_kept' => $generated];
}

function recurringToggle(PDO $pdo, array $in, string $today): array {
    $id = trim((string) ($in['id'] ?? ''));
    if ($id === '') throw new RecurringError('id is required.');
    $before = recurringLookup($pdo, 'recurring_rules', $id);
    if (!$before) throw new RecurringError('Recurring rule not found.', 404);

    // No `active` in the payload flips it, so the same endpoint serves a switch
    // and a plain toggle button.
    $active = recurringFlag($in['active'] ?? null, ((int) $before['active'] === 1) ? 0 : 1);
    $pdo->prepare('UPDATE recurring_rules SET active = ? WHERE id = ?')->execute([$active, $id]);

    audit($pdo, $active ? 'resumed' : 'paused', 'recurring_rule', $id,
          ['active' => (int) $before['active']], ['active' => $active]);
    return ['success' => true, 'id' => $id, 'active' => $active, 'rule' => recurringGet($pdo, $id, $today)];
}

// ── Running ──────────────────────────────────────────────────────────────────

function recurringInsertTx(PDO $pdo, array $row): void {
    $pdo->prepare(
        'INSERT INTO transactions
            (id, date, book_id, type, amount, currency, account_id, category_id,
             counterparty, description, linked_tx_id, split_group_id, void,
             source, source_ref, reconciled, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, 0, ?, ?)'
    )->execute([
        $row['id'], $row['date'], $row['book_id'], $row['type'], $row['amount'],
        $row['currency'], $row['account_id'], $row['category_id'],
        $row['counterparty'], $row['description'],
        $row['source'], $row['source_ref'], $row['created_at'], $row['updated_at'],
    ]);
}

/**
 * Everything $rule still owes the ledger, written if $commit is true.
 *
 * Preview and run are the same function so the list of transactions shown
 * before the button is pressed cannot drift from the list that gets written.
 *
 * The caller owns the database transaction — a run over several rules is
 * all-or-nothing.
 */
function recurringRunRule(PDO $pdo, array $rule, string $today, bool $commit): array {
    $id  = (string) $rule['id'];
    $out = [
        'rule_id'      => $id,
        'rule_name'    => (string) $rule['name'],
        'periods'      => [],
        'transactions' => [],
        'created'      => 0,
        'total'        => 0.0,
        'currency'     => (string) $rule['currency'],
        'skipped'      => false,
        'reason'       => '',
    ];

    $skip = function (string $reason) use (&$out) {
        $out['skipped'] = true;
        $out['reason']  = $reason;
        return $out;
    };

    if ((int) $rule['active'] !== 1) return $skip('Rule is paused.');

    $account = recurringLookup($pdo, 'accounts', (string) $rule['account_id']);
    if (!$account)                        return $skip('The account this rule posts to no longer exists.');
    if ((int) $account['archived'] === 1) return $skip('The account this rule posts to is archived.');

    // Re-checked at run time, not just at write time: an account's currency can
    // be changed after the rule was saved, and booking PKR into a USD account
    // would quietly corrupt every balance that account touches.
    if ((string) $account['currency'] !== (string) $rule['currency']) {
        return $skip('Account currency (' . $account['currency'] . ') no longer matches the rule ('
                   . $rule['currency'] . '). Edit the rule.');
    }

    $generated = recurringGeneratedPeriods($pdo, $id);
    $missed    = recurringMissedPeriods($rule, $generated, $today);
    if (!$missed) {
        $out['reason'] = 'Nothing due.';
        return $out;
    }

    $now         = date('c');
    $amount      = round((float) $rule['amount'], 2);
    $description = trim((string) $rule['description']) !== ''
        ? (string) $rule['description']
        : (string) $rule['name'];

    foreach ($missed as $period) {
        $row = [
            // A preview hands back an empty id rather than one it will never
            // use, so nothing downstream can try to match a previewed row
            // against the row that eventually gets written.
            'id'           => $commit ? newId('tx') : '',
            'date'         => recurringDateFor($period, (int) $rule['day_of_month']),
            'book_id'      => (string) $rule['book_id'],
            'type'         => (string) $rule['type'],
            'amount'       => $amount,
            'currency'     => (string) $rule['currency'],
            'account_id'   => (string) $rule['account_id'],
            'category_id'  => $rule['category_id'] !== null && $rule['category_id'] !== ''
                              ? (string) $rule['category_id'] : null,
            'counterparty' => (string) $rule['counterparty'],
            'description'  => $description,
            'source'       => RECURRING_SOURCE,
            'source_ref'   => $id,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        if ($commit) {
            recurringInsertTx($pdo, $row);
            audit($pdo, 'created', 'transaction', $row['id'], null, $row + ['period' => $period]);
        }

        $out['periods'][]      = $period;
        $out['transactions'][] = $row + [
            'period'        => $period,
            'account_name'  => (string) $account['name'],
            'category_name' => (string) ($rule['category_name'] ?? ''),
            'book_name'     => (string) ($rule['book_name'] ?? ''),
        ];
        $out['created']++;
        $out['total'] = round($out['total'] + $amount, 2);
    }

    if ($commit) {
        // last_run_period is rewritten from what the ledger now holds rather
        // than from what we just did, so a stale value on the row is repaired
        // by the next run instead of being carried forward.
        $all  = array_merge($generated, $out['periods']);
        $last = $all ? max($all) : null;
        $pdo->prepare('UPDATE recurring_rules SET last_run_period = ? WHERE id = ?')->execute([$last, $id]);

        audit($pdo, 'ran', 'recurring_rule', $id, ['last_run_period' => $rule['last_run_period']], [
            'periods'         => $out['periods'],
            'transaction_ids' => array_column($out['transactions'], 'id'),
            'total'           => $out['total'],
            'last_run_period' => $last,
        ]);
    }

    return $out;
}

/** The rules an action=run / action=preview payload is asking about. */
function recurringSelectRules(PDO $pdo, array $in, string $today): array {
    if (!empty($in['all'])) {
        $rules = [];
        foreach (recurringList($pdo, $today)['rules'] as $rule) {
            if ($rule['is_due']) $rules[] = $rule;
        }
        return $rules;
    }

    $ids = [];
    if (isset($in['ids']) && is_array($in['ids'])) {
        foreach ($in['ids'] as $id) {
            $id = trim((string) $id);
            if ($id !== '') $ids[] = $id;
        }
    }
    $single = trim((string) ($in['id'] ?? ''));
    if ($single !== '') $ids[] = $single;

    $ids = array_values(array_unique($ids));
    if (!$ids) throw new RecurringError('Provide id, ids or all.');

    $rules = [];
    foreach ($ids as $id) $rules[] = recurringGet($pdo, $id, $today);
    return $rules;
}

function recurringRunMany(PDO $pdo, array $rules, string $today, bool $commit): array {
    $results    = [];
    $created    = 0;
    $byCurrency = [];

    if ($commit) $pdo->beginTransaction();
    try {
        foreach ($rules as $rule) {
            $res = recurringRunRule($pdo, $rule, $today, $commit);
            $created += $res['created'];
            if ($res['created'] > 0) {
                $cur = $res['currency'];
                $byCurrency[$cur] = round(($byCurrency[$cur] ?? 0.0) + $res['total'], 2);
            }
            $results[] = $res;
        }
        if ($commit) $pdo->commit();
    } catch (Throwable $e) {
        if ($commit && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'success'           => true,
        'preview'           => !$commit,
        'today'             => $today,
        'created_count'     => $created,
        'total_by_currency' => $byCurrency,
        'results'           => $results,
    ];
}

// ── Request handling ─────────────────────────────────────────────────────────
//
// Only this section touches the request, the session or the output stream.
// Defining RECURRING_API_NO_DISPATCH before including the file gives you the
// functions above without any of that — which is how the tests use it.

function recurringApiDispatch(PDO $pdo): void {
    requireLogin();
    $today = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse(recurringList($pdo, $today));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    verifyCsrf();
    $input  = readJsonBody();
    $action = trim((string) ($input['action'] ?? ''));

    try {
        switch ($action) {
            case 'create':
                jsonResponse(recurringCreate($pdo, $input, $today));
                break;
            case 'update':
                jsonResponse(recurringUpdate($pdo, $input, $today));
                break;
            case 'delete':
                jsonResponse(recurringDelete($pdo, $input));
                break;
            case 'toggle':
                jsonResponse(recurringToggle($pdo, $input, $today));
                break;
            case 'run':
                jsonResponse(recurringRunMany($pdo, recurringSelectRules($pdo, $input, $today), $today, true));
                break;
            case 'preview':
                jsonResponse(recurringRunMany($pdo, recurringSelectRules($pdo, $input, $today), $today, false));
                break;
        }
    } catch (RecurringError $e) {
        $code = (int) $e->getCode();
        jsonError($e->getMessage(), ($code >= 400 && $code <= 599) ? $code : 400);
    }

    jsonError('Invalid action.');
}

if (!defined('RECURRING_API_NO_DISPATCH')) {
    require_once __DIR__ . '/auth.php';
    recurringApiDispatch(db());
}
