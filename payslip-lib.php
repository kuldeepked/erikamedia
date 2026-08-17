<?php
// Payslips and the ledger rows they produce.
//
// Issuing a payslip is an accounting event, not just a printed page, so saving
// one and booking it happen together inside a single transaction. The old flow
// wrote the slip to a JSON file, recorded the advance recovery, and left the
// salary itself to a separate "Post salaries to Finances" button that had to be
// remembered. If that button was never pressed the books were short by an
// entire month of payroll; if the page was reloaded it could be pressed twice.
//
// What each slip books:
//
//   net pay            expense on the paying bank account   cash goes out
//   advance recovered  expense on Employee Advances         receivable settles
//   PF + EOBI + tax    expense on Statutory Payables        liability builds up
//
// All three carry the employee's salary category, so the P&L shows the full
// cost of employing that person — which is gross earnings less any penalty
// withheld, since a penalty reduces what the business actually bore. Only the
// net row moves real money.
//
// Nothing is ever deleted. Regenerating a slip voids the previous one and
// reverses its postings, so the trail shows what was issued, what replaced it,
// and when.

require_once __DIR__ . '/ledger-lib.php';

// ── Arithmetic ───────────────────────────────────────────────────────────────

/**
 * Gross, deductions and net from a slip's components. One implementation so
 * the preview, the printed slip, the register and the ledger cannot drift.
 */
function payslipTotals(array $s): array {
    $gross = (float) ($s['basic'] ?? 0) + (float) ($s['allowance'] ?? 0)
           + (float) ($s['commission'] ?? 0) + (float) ($s['bonus'] ?? 0);

    $statutory = (float) ($s['provident_fund'] ?? 0) + (float) ($s['eobi'] ?? 0)
               + (float) ($s['professional_tax'] ?? 0);

    $deductions = $statutory + (float) ($s['loan'] ?? 0)
                + (float) ($s['absent_late'] ?? 0) + (float) ($s['penalty'] ?? 0);

    return [
        'gross'      => round($gross, 2),
        'statutory'  => round($statutory, 2),
        'deductions' => round($deductions, 2),
        'net'        => round($gross - $deductions, 2),
    ];
}

function payslipEmployeeKey(string $name): string {
    return strtolower(trim($name));
}

function periodEndDate(string $period): string {
    return date('Y-m-t', strtotime($period . '-01'));
}

function periodLabel(string $period): string {
    return date('M Y', strtotime($period . '-01'));
}

// ── Categories ───────────────────────────────────────────────────────────────

/**
 * The employee's salary expense category, created on demand.
 *
 * Posting used to skip anyone without one and report them in a `no_category`
 * list that was easy to miss, which meant a real salary silently never reached
 * the books. There is no reason for a person on the payroll not to have a line
 * in the chart of accounts, so it is made rather than complained about.
 */
function ensureSalaryCategory(PDO $pdo, string $employee, ?string $bookId = null): string {
    $key  = payslipEmployeeKey($employee);
    $stmt = $pdo->prepare(
        "SELECT id FROM categories
         WHERE type = 'expense' AND LOWER(TRIM(linked_employee)) = ? AND archived = 0
         ORDER BY display_order LIMIT 1"
    );
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    if ($id) return (string) $id;

    $id    = newId('cat');
    $order = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM categories')->fetchColumn();
    $pdo->prepare(
        "INSERT INTO categories (id, name, type, parent_id, book_scope, linked_employee, code, tax_deductible, display_order, created_at)
         VALUES (?, ?, 'expense', NULL, ?, ?, '', 1, ?, ?)"
    )->execute([$id, 'Salary — ' . trim($employee), $bookId, trim($employee), $order, date('c')]);

    audit($pdo, 'created', 'category', $id, null, ['name' => 'Salary — ' . trim($employee), 'auto' => true]);
    return $id;
}

// ── Reading ──────────────────────────────────────────────────────────────────

function loadPayslip(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM payslips WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ? hydratePayslip($r) : null;
}

/** The live (non-voided) slip for an employee and month, if there is one. */
function livePayslip(PDO $pdo, string $employee, string $period): ?array {
    $stmt = $pdo->prepare('SELECT * FROM payslips WHERE employee_key = ? AND period = ? AND voided = 0 LIMIT 1');
    $stmt->execute([payslipEmployeeKey($employee), $period]);
    $r = $stmt->fetch();
    return $r ? hydratePayslip($r) : null;
}

function hydratePayslip(array $r): array {
    foreach (['basic','allowance','commission','bonus','provident_fund','eobi',
              'professional_tax','loan','absent_late','penalty','gross','deductions','net'] as $k) {
        $r[$k] = round((float) $r[$k], 2);
    }
    $r['posted']    = (int) $r['posted'];
    $r['voided']    = (int) $r['voided'];
    $r['statutory'] = round($r['provident_fund'] + $r['eobi'] + $r['professional_tax'], 2);
    $ids = json_decode((string) $r['activity_ids'], true);
    $r['activity_ids'] = is_array($ids) ? $ids : [];
    $r['period_label'] = periodLabel((string) $r['period']);
    return $r;
}

/**
 * Payslips matching a filter, newest period first.
 * $filters: from_period, to_period, employees[], include_voided, posted
 */
function listPayslips(PDO $pdo, array $filters = []): array {
    $where = [];
    $args  = [];

    if (empty($filters['include_voided'])) $where[] = 'voided = 0';

    $fromP = trim((string) ($filters['from_period'] ?? ''));
    $toP   = trim((string) ($filters['to_period']   ?? ''));
    if (isMonth($fromP)) { $where[] = 'period >= ?'; $args[] = $fromP; }
    if (isMonth($toP))   { $where[] = 'period <= ?'; $args[] = $toP; }

    $emps = $filters['employees'] ?? [];
    if (is_array($emps) && $emps) {
        $keys = array_values(array_unique(array_map('payslipEmployeeKey', $emps)));
        $where[] = 'employee_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        $args = array_merge($args, $keys);
    }

    if (isset($filters['posted']) && $filters['posted'] !== '') {
        $where[] = 'posted = ?';
        $args[]  = (int) $filters['posted'];
    }

    $sql = 'SELECT * FROM payslips';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY period DESC, employee ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map('hydratePayslip', $stmt->fetchAll());
}

// ── Writing ──────────────────────────────────────────────────────────────────

/**
 * Void every ledger row a payslip produced.
 *
 * Voided rather than deleted: the rows stay visible with include_void so it is
 * always possible to see what was booked and later reversed. Voiding the
 * advance-recovery row also restores the outstanding balance, which is what
 * makes regenerating a slip safe.
 */
function reversePayslipPostings(PDO $pdo, string $payslipId): int {
    $stmt = $pdo->prepare(
        "SELECT id FROM transactions WHERE source = 'payroll' AND source_ref = ? AND void = 0"
    );
    $stmt->execute([$payslipId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$ids) return 0;

    $upd = $pdo->prepare('UPDATE transactions SET void = 1, updated_at = ? WHERE id = ?');
    $now = date('c');
    foreach ($ids as $id) $upd->execute([$now, $id]);

    audit($pdo, 'voided', 'payslip_postings', $payslipId, null, ['transaction_ids' => $ids]);
    return count($ids);
}

/** Outstanding salary advance for one person, as the ledger currently stands. */
function outstandingAdvanceFor(PDO $pdo, string $employee): float {
    $acc = findAccountByName($pdo, 'Employee Advances');
    if (!$acc) return 0.0;

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'transfer_in' THEN amount ELSE -amount END), 0)
         FROM transactions
         WHERE account_id = ? AND void = 0 AND LOWER(TRIM(counterparty)) = ?"
    );
    $stmt->execute([$acc['id'], payslipEmployeeKey($employee)]);
    return round(max(0.0, (float) $stmt->fetchColumn()), 2);
}

/**
 * Save a payslip and book it, atomically.
 *
 * $slip needs: employee, period, and any of the component amounts. Everything
 * else is derived. If a live slip already exists for that employee and month it
 * is voided and its postings reversed first, so a regeneration replaces rather
 * than duplicates.
 *
 * $opts:
 *   account_id  bank/cash account the net pay leaves from. Falls back to the
 *               saved payroll account setting.
 *   post        set false to record the slip without booking it.
 *   cap_loan    clamp advance recovery to what is actually outstanding
 *               (default true — stops a hand-typed figure double-recovering).
 *
 * Returns the saved slip plus what was booked.
 */
function savePayslip(PDO $pdo, array $slip, array $opts = []): array {
    $employee = trim((string) ($slip['employee'] ?? ''));
    $period   = trim((string) ($slip['period'] ?? ''));
    if ($employee === '') throw new InvalidArgumentException('Employee name is required.');
    if (!isMonth($period)) throw new InvalidArgumentException('Pay period must be YYYY-MM.');

    $shouldPost = array_key_exists('post', $opts) ? (bool) $opts['post']
                                                  : settingGet($pdo, 'payroll_autopost', '1') === '1';
    $capLoan    = !array_key_exists('cap_loan', $opts) || (bool) $opts['cap_loan'];

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();

    try {
        // Replace any live slip for this employee and month first — reversing
        // its advance recovery before the new figure is capped, so the cap is
        // measured against a balance that no longer counts the old slip.
        $previous = livePayslip($pdo, $employee, $period);
        $reversed = 0;
        if ($previous) {
            $reversed = reversePayslipPostings($pdo, $previous['id']);
            $pdo->prepare('UPDATE payslips SET voided = 1, voided_at = ?, posted = 0 WHERE id = ?')
                ->execute([date('c'), $previous['id']]);
        }

        $loan = max(0.0, (float) ($slip['loan'] ?? 0));
        if ($capLoan) {
            $loan = min($loan, outstandingAdvanceFor($pdo, $employee));
        }

        $components = [
            'basic'            => max(0.0, (float) ($slip['basic'] ?? 0)),
            'allowance'        => max(0.0, (float) ($slip['allowance'] ?? 0)),
            'commission'       => max(0.0, (float) ($slip['commission'] ?? 0)),
            'bonus'            => max(0.0, (float) ($slip['bonus'] ?? 0)),
            'provident_fund'   => max(0.0, (float) ($slip['provident_fund'] ?? 0)),
            'eobi'             => max(0.0, (float) ($slip['eobi'] ?? 0)),
            'professional_tax' => max(0.0, (float) ($slip['professional_tax'] ?? 0)),
            'loan'             => round($loan, 2),
            'absent_late'      => max(0.0, (float) ($slip['absent_late'] ?? 0)),
            'penalty'          => max(0.0, (float) ($slip['penalty'] ?? 0)),
        ];
        $totals = payslipTotals($components);

        $activityIds = $slip['activity_ids'] ?? [];
        if (!is_array($activityIds)) $activityIds = [];

        $id = newId('ps');
        $pdo->prepare(
            'INSERT INTO payslips
                (id, employee, employee_key, designation, period,
                 basic, allowance, commission, bonus,
                 provident_fund, eobi, professional_tax, loan, absent_late, penalty,
                 gross, deductions, net, activity_ids,
                 posted, voided, supersedes, generated_at, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)'
        )->execute([
            $id, $employee, payslipEmployeeKey($employee),
            trim((string) ($slip['designation'] ?? '')), $period,
            $components['basic'], $components['allowance'], $components['commission'], $components['bonus'],
            $components['provident_fund'], $components['eobi'], $components['professional_tax'],
            $components['loan'], $components['absent_late'], $components['penalty'],
            $totals['gross'], $totals['deductions'], $totals['net'],
            json_encode(array_values($activityIds)),
            $previous['id'] ?? null, date('c'), $_SESSION['admin_user'] ?? 'system',
        ]);

        audit($pdo, 'created', 'payslip', $id, $previous, [
            'employee' => $employee, 'period' => $period, 'net' => $totals['net'],
            'replaced' => $previous['id'] ?? null, 'reversed_rows' => $reversed,
        ]);

        $posting = ['posted' => false, 'transactions' => [], 'warnings' => []];
        if ($shouldPost) {
            $posting = postPayslip($pdo, $id, (string) ($opts['account_id'] ?? ''));
        }

        if ($ownTransaction) $pdo->commit();

        return [
            'payslip'  => loadPayslip($pdo, $id),
            'replaced' => $previous['id'] ?? null,
            'reversed_transactions' => $reversed,
            'posting'  => $posting,
        ];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Write the ledger rows for a saved payslip.
 *
 * Idempotent through transactions.source_ref: a slip that has already been
 * booked returns what it booked instead of booking it again. That replaces the
 * old "same counterparty, same category, same month" guess, which treated a
 * legitimate second payment in one month as a duplicate and broke outright if
 * an employee was renamed.
 */
function postPayslip(PDO $pdo, string $payslipId, string $accountId = ''): array {
    $slip = loadPayslip($pdo, $payslipId);
    if (!$slip)          throw new RuntimeException('Payslip not found: ' . $payslipId);
    if ($slip['voided']) throw new RuntimeException('Cannot post a voided payslip.');

    $existing = $pdo->prepare("SELECT id FROM transactions WHERE source = 'payroll' AND source_ref = ? AND void = 0");
    $existing->execute([$payslipId]);
    $already = $existing->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($already) {
        return ['posted' => true, 'already' => true, 'transactions' => $already, 'warnings' => []];
    }

    $bookId = primaryBusinessBookId($pdo);
    if (!$bookId) throw new RuntimeException('No business book to post salaries into.');

    if ($accountId === '') $accountId = settingGet($pdo, 'payroll_account_id', '');
    if ($accountId === '') {
        // The most-used ordinary asset account is a better guess than failing.
        $guess = $pdo->query("SELECT id FROM accounts
                              WHERE archived = 0 AND kind = 'asset' AND type IN ('bank','cash')
                              ORDER BY display_order LIMIT 1")->fetch();
        $accountId = $guess['id'] ?? '';
    }
    if ($accountId === '') throw new RuntimeException('No account available to pay salaries from.');

    $accStmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ? AND archived = 0');
    $accStmt->execute([$accountId]);
    $account = $accStmt->fetch();
    if (!$account) throw new RuntimeException('The account salaries are paid from was not found.');

    $categoryId = ensureSalaryCategory($pdo, $slip['employee'], $bookId);
    $date       = periodEndDate($slip['period']);
    $label      = periodLabel($slip['period']);
    $now        = date('c');
    $currency   = $account['currency'];

    $ins = $pdo->prepare(
        "INSERT INTO transactions
            (id, date, book_id, type, amount, currency, account_id, category_id,
             counterparty, description, linked_tx_id, split_group_id, void,
             source, source_ref, reconciled, created_at, updated_at)
         VALUES (?, ?, ?, 'expense', ?, ?, ?, ?, ?, ?, NULL, NULL, 0, 'payroll', ?, 0, ?, ?)"
    );

    $written  = [];
    $warnings = [];

    $book = function (float $amount, string $accId, string $description) use ($ins, $pdo, $bookId, $currency, $categoryId, $slip, $date, $now, $payslipId, &$written) {
        if ($amount <= 0.005) return;
        $txId = newId('tx');
        $ins->execute([
            $txId, $date, $bookId, round($amount, 2), $currency, $accId, $categoryId,
            $slip['employee'], $description, $payslipId, $now, $now,
        ]);
        $written[] = $txId;
    };

    // Cash actually leaving the business.
    if ($slip['net'] > 0.005) {
        $book($slip['net'], $account['id'], 'Net salary ' . $label);
    } elseif ($slip['net'] < -0.005) {
        $warnings[] = sprintf(
            '%s has a negative net for %s (%s). Deductions exceed earnings, so no payment was booked — '
            . 'the shortfall stays owed and should be carried to next month.',
            $slip['employee'], $label, fmtMoneyPlain($slip['net'], $currency)
        );
    }

    // Advance recovered: no cash moves, the receivable settles and becomes cost.
    if ($slip['loan'] > 0.005) {
        $adv = advancesAccount($pdo, $bookId);
        $book($slip['loan'], $adv['id'], 'Advance recovered from salary ' . $label);
    }

    // Withheld from the employee, owed onward to EOBI / the tax authority.
    if ($slip['statutory'] > 0.005) {
        $stat = statutoryAccount($pdo, $bookId);
        $parts = [];
        if ($slip['provident_fund']   > 0) $parts[] = 'PF';
        if ($slip['eobi']             > 0) $parts[] = 'EOBI';
        if ($slip['professional_tax'] > 0) $parts[] = 'professional tax';
        $book($slip['statutory'], $stat['id'], implode(' + ', $parts) . ' withheld ' . $label);
    }

    $pdo->prepare('UPDATE payslips SET posted = ?, posted_at = ? WHERE id = ?')
        ->execute([count($written) > 0 ? 1 : 0, $now, $payslipId]);

    if ($written) {
        audit($pdo, 'posted', 'payslip', $payslipId, null, [
            'employee' => $slip['employee'], 'period' => $slip['period'],
            'account'  => $account['name'], 'transactions' => $written,
            'net' => $slip['net'], 'advance' => $slip['loan'], 'statutory' => $slip['statutory'],
        ]);
    }

    return [
        'posted'       => count($written) > 0,
        'already'      => false,
        'transactions' => $written,
        'account'      => $account['name'],
        'warnings'     => $warnings,
        'booked'       => [
            'net'       => $slip['net'] > 0 ? $slip['net'] : 0.0,
            'advance'   => $slip['loan'],
            'statutory' => $slip['statutory'],
            'total_expense' => round(max(0.0, $slip['net']) + $slip['loan'] + $slip['statutory'], 2),
        ],
    ];
}

/** Void a payslip and reverse everything it booked. */
function voidPayslip(PDO $pdo, string $payslipId): array {
    $slip = loadPayslip($pdo, $payslipId);
    if (!$slip) throw new RuntimeException('Payslip not found.');
    if ($slip['voided']) return ['voided' => true, 'reversed' => 0, 'already' => true];

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $reversed = reversePayslipPostings($pdo, $payslipId);
        $pdo->prepare('UPDATE payslips SET voided = 1, voided_at = ?, posted = 0 WHERE id = ?')
            ->execute([date('c'), $payslipId]);
        audit($pdo, 'voided', 'payslip', $payslipId, $slip, ['reversed_transactions' => $reversed]);
        if ($own) $pdo->commit();
        return ['voided' => true, 'reversed' => $reversed, 'already' => false];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ── Payroll register ─────────────────────────────────────────────────────────

/**
 * Every slip issued over a span of months, with per-month and grand totals.
 * This is the sheet a consultant reconciles salary expense against.
 */
function payrollRegister(PDO $pdo, string $fromPeriod, string $toPeriod, array $employees = []): array {
    $slips = listPayslips($pdo, [
        'from_period' => $fromPeriod,
        'to_period'   => $toPeriod,
        'employees'   => $employees,
    ]);

    $blank = [
        'basic' => 0.0, 'allowance' => 0.0, 'commission' => 0.0, 'bonus' => 0.0,
        'gross' => 0.0, 'provident_fund' => 0.0, 'eobi' => 0.0, 'professional_tax' => 0.0,
        'statutory' => 0.0, 'loan' => 0.0, 'absent_late' => 0.0, 'penalty' => 0.0,
        'deductions' => 0.0, 'net' => 0.0, 'count' => 0,
    ];
    $add = function (array $acc, array $s): array {
        foreach ($acc as $k => $v) {
            if ($k === 'count') { $acc[$k] = $v + 1; continue; }
            $acc[$k] = round($v + (float) ($s[$k] ?? 0), 2);
        }
        return $acc;
    };

    $byMonth = []; $byEmployee = []; $grand = $blank;
    foreach ($slips as $s) {
        $m = $s['period'];
        $e = $s['employee'];
        if (!isset($byMonth[$m]))    $byMonth[$m]    = $blank;
        if (!isset($byEmployee[$e])) $byEmployee[$e] = $blank;
        $byMonth[$m]    = $add($byMonth[$m], $s);
        $byEmployee[$e] = $add($byEmployee[$e], $s);
        $grand          = $add($grand, $s);
    }

    krsort($byMonth);
    ksort($byEmployee);

    $months = [];
    foreach ($byMonth as $m => $t) {
        $months[] = array_merge(['period' => $m, 'label' => periodLabel($m)], $t);
    }
    $people = [];
    foreach ($byEmployee as $e => $t) {
        $people[] = array_merge(['employee' => $e], $t);
    }

    return [
        'from_period' => $fromPeriod,
        'to_period'   => $toPeriod,
        'slips'       => $slips,
        'by_month'    => $months,
        'by_employee' => $people,
        'totals'      => $grand,
        // What the ledger should show as salary cost for this span: gross less
        // anything withheld as a penalty, since that never left the business.
        'expected_salary_expense' => round(
            $grand['gross'] - $grand['penalty'] - $grand['absent_late'], 2
        ),
    ];
}

/**
 * Compare the register against what is actually in the ledger.
 * A mismatch means a slip was issued but never booked, or a salary was entered
 * by hand as well as posted — both worth knowing before handing the books over.
 */
function payrollReconciliation(PDO $pdo, string $fromPeriod, string $toPeriod): array {
    $register = payrollRegister($pdo, $fromPeriod, $toPeriod);
    $from = monthStart($fromPeriod);
    $to   = periodEndDate($toPeriod);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(t.amount), 0)
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.void = 0 AND t.type = 'expense' AND c.linked_employee <> ''
           AND t.date >= ? AND t.date <= ?"
    );
    $stmt->execute([$from, $to]);
    $ledgerSalary = round((float) $stmt->fetchColumn(), 2);

    $unposted = array_values(array_filter($register['slips'], fn($s) => !$s['posted']));
    $expected = $register['expected_salary_expense'];

    return [
        'expected_from_payslips' => $expected,
        'in_ledger'              => $ledgerSalary,
        'difference'             => round($ledgerSalary - $expected, 2),
        'matches'                => abs($ledgerSalary - $expected) < 0.01,
        'unposted_payslips'      => $unposted,
        'unposted_count'         => count($unposted),
    ];
}
