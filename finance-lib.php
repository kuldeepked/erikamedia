<?php
// Shared finance aggregation helpers used by dashboard-api.php and
// reports-screen-api.php so the two screens always agree on the math.
// Pure functions, no output — safe to require.

/** IDs of all active business books (Erika Media). */
function bizBookIds(PDO $pdo): array {
    return $pdo->query("SELECT id FROM books WHERE type = 'business' AND archived = 0")
               ->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/** Income / expense / net for one YYYY-MM month (non-void, excludes transfers). */
function monthTotals(PDO $pdo, array $books, string $month): array {
    if (!$books) return ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    $ph  = implode(',', array_fill(0, count($books), '?'));
    $sql = "SELECT type, SUM(amount) AS total
            FROM transactions
            WHERE void = 0 AND type IN ('income','expense')
              AND book_id IN ($ph)
              AND substr(date, 1, 7) = ?
            GROUP BY type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$books, $month]);
    $inc = 0.0; $exp = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['type'] === 'income')  $inc = (float) $r['total'];
        if ($r['type'] === 'expense') $exp = (float) $r['total'];
    }
    return ['income' => $inc, 'expense' => $exp, 'net' => $inc - $exp];
}

/**
 * Outstanding balance on a named pivot account (e.g. "Employee Advances",
 * "Loans Receivable"). given = transfer_in, recovered = transfer_out,
 * written_off = expense; outstanding = given - recovered - written_off.
 */
function accountOutstanding(PDO $pdo, string $accName): float {
    $stmt = $pdo->prepare(
        "SELECT t.type, SUM(t.amount) AS total
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE LOWER(a.name) = ? AND t.void = 0
         GROUP BY t.type"
    );
    $stmt->execute([strtolower($accName)]);
    $given = 0.0; $recovered = 0.0; $writtenOff = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['type'] === 'transfer_in')  $given      += (float) $r['total'];
        if ($r['type'] === 'transfer_out') $recovered  += (float) $r['total'];
        if ($r['type'] === 'expense')      $writtenOff += (float) $r['total'];
    }
    return round($given - $recovered - $writtenOff, 2);
}

/**
 * Outstanding "Employee Advances" balance per person (counterparty).
 * Returns [employeeName => outstanding]. Mirrors reports-api employee_advances:
 * given = transfer_in, recovered = transfer_out, written_off = expense.
 */
function advancesByEmployee(PDO $pdo): array {
    $stmt = $pdo->prepare(
        "SELECT t.counterparty, t.type, SUM(t.amount) AS total
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE LOWER(a.name) = 'employee advances' AND t.void = 0
         GROUP BY t.counterparty, t.type"
    );
    $stmt->execute();
    $by = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = trim((string) $r['counterparty']);
        if ($name === '') continue;
        if (!isset($by[$name])) $by[$name] = 0.0;
        if ($r['type'] === 'transfer_in')  $by[$name] += (float) $r['total'];
        if ($r['type'] === 'transfer_out') $by[$name] -= (float) $r['total'];
        if ($r['type'] === 'expense')      $by[$name] -= (float) $r['total'];
    }
    foreach ($by as $k => $v) $by[$k] = round($v, 2);
    return $by;
}
