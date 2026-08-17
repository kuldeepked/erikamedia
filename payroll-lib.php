<?php
// Shared payroll math. Used by payroll-api.php (preview), payslips-api.php and
// payslip-bulk.php (generation) and payroll-post-api.php (posting) so they can
// never drift.
//   Earnings   = basic + allowance + commission + performer_bonus
//   Deductions = provident_fund + eobi + loan(advance) + professional_tax
//                + absent_late + penalty
//   Net        = earnings - deductions
// Commission/bonus/penalty come from UNPAID activity entries for the month
// (penalties include the ones the Attendance screen logs). Loan = outstanding
// salary advance.
//
// "generated" now means a live row in the payslips TABLE, not a line in
// history.json. history.json is truncated to 200 records and shared with offer
// letters, so it was never a safe answer to "was this person paid?".

require_once __DIR__ . '/finance-lib.php';
require_once __DIR__ . '/payslip-lib.php';

function payrollReadJson(string $file): array {
    return file_exists($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

/**
 * Mark activity entries as paid in a pay period.
 *
 * Commissions, bonuses and penalties are only counted while unpaid, so this is
 * what stops a commission being paid twice. Batched by period and written once,
 * because activity.json is a whole-file read-modify-write and doing it per
 * employee in a bulk run is a lot of chances to lose the file.
 *
 * @return int number of entries marked
 */
function payrollMarkActivityPaid(array $ids, string $period): int {
    $ids = array_values(array_filter(array_map('strval', $ids), fn($v) => $v !== ''));
    if (!$ids) return 0;

    $file = __DIR__ . '/activity.json';
    $act  = payrollReadJson($file);
    if (!$act) return 0;

    $wanted = array_flip($ids);
    $n = 0;
    foreach ($act as &$e) {
        if (isset($wanted[(string) ($e['id'] ?? '')])) {
            $e['paid_in'] = $period;
            $n++;
        }
    }
    unset($e);

    if ($n > 0) {
        file_put_contents($file, json_encode($act, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return $n;
}

function payrollRows(PDO $pdo, string $month): array {
    $employees = payrollReadJson(__DIR__ . '/employees.json');
    $activity  = payrollReadJson(__DIR__ . '/activity.json');

    // Outstanding advances keyed case-insensitively.
    $advLc = [];
    foreach (advancesByEmployee($pdo) as $name => $amt) {
        $advLc[strtolower($name)] = $amt;
    }

    // Unpaid activity for the month, grouped per employee.
    $actByEmp = [];
    foreach ($activity as $e) {
        if (strpos((string) ($e['date'] ?? ''), $month) !== 0) continue;
        if (!empty($e['paid_in'])) continue;
        $key = strtolower(trim((string) ($e['employee'] ?? '')));
        if ($key === '') continue;
        if (!isset($actByEmp[$key])) {
            $actByEmp[$key] = ['commission' => 0, 'bonus' => 0, 'penalty' => 0, 'ids' => []];
        }
        $type = (string) ($e['type'] ?? '');
        $amt  = (int) ($e['amount'] ?? 0);
        if ($type === 'interview' || $type === 'placement') {
            $actByEmp[$key]['commission'] += $amt; $actByEmp[$key]['ids'][] = (string) ($e['id'] ?? '');
        } elseif ($type === 'bonus') {
            $actByEmp[$key]['bonus'] += $amt;       $actByEmp[$key]['ids'][] = (string) ($e['id'] ?? '');
        } elseif ($type === 'penalty') {
            $actByEmp[$key]['penalty'] += $amt;     $actByEmp[$key]['ids'][] = (string) ($e['id'] ?? '');
        }
    }

    $rows = [];
    $tot  = ['basic' => 0, 'allowance' => 0, 'commission' => 0, 'bonus' => 0, 'loan' => 0,
             'provident_fund' => 0, 'eobi' => 0, 'professional_tax' => 0, 'penalty' => 0, 'net' => 0];

    foreach ($employees as $emp) {
        $name = trim((string) ($emp['name'] ?? ''));
        if ($name === '') continue;
        $key = strtolower($name);
        $act = $actByEmp[$key] ?? ['commission' => 0, 'bonus' => 0, 'penalty' => 0, 'ids' => []];

        $basic      = max(0, (int) ($emp['basic_salary'] ?? 0));
        $allowance  = max(0, (int) ($emp['allowance'] ?? 0));
        $commission = (int) $act['commission'];
        $bonus      = max(0, (int) ($emp['punctuality_bonus'] ?? 0)) + (int) $act['bonus'];
        $pf         = max(0, (int) ($emp['provident_fund'] ?? 0));
        $eobi       = max(0, (int) ($emp['eobi'] ?? 0));
        $tax        = max(0, (int) ($emp['professional_tax'] ?? 0));
        $penalty    = (int) $act['penalty'];
        $outstanding = max(0, (int) round($advLc[$key] ?? 0));
        $absentLate = 0;

        $earnings = $basic + $allowance + $commission + $bonus;

        // Statutory and disciplinary deductions come out first — these are not
        // negotiable and are allowed to exceed earnings (that is a real
        // situation the payroll runner needs to see, not hide).
        $fixedDeductions = $pf + $eobi + $tax + $absentLate + $penalty;

        // Salary-advance recovery is capped so it can never push net pay below
        // zero. Recover at most whatever is left after the fixed deductions,
        // and never more than the employee actually owes. Anything not
        // recovered this month stays outstanding and is picked up next month,
        // because the advance balance is derived from the ledger rather than
        // stored per payslip.
        $loan = max(0, min($outstanding, $earnings - $fixedDeductions));

        $deductions = $fixedDeductions + $loan;
        $net        = $earnings - $deductions;

        // What was actually issued for this employee/month, if anything. The
        // preview above recomputes from live state, which is right for a month
        // nobody has been paid for yet — but once a slip exists the advance it
        // recovered is gone from the ledger, so a recompute reads back a higher
        // net than the document the employee was handed. Both figures are
        // returned rather than one being chosen here: the UI shows the preview
        // while pending and the issued figure once generated.
        $issued = livePayslip($pdo, $name, $month);

        $rows[] = [
            'employee'         => $name,
            'designation'      => (string) ($emp['designation'] ?? ''),
            'basic'            => $basic,
            'allowance'        => $allowance,
            'commission'       => $commission,
            'bonus'            => $bonus,
            'loan'             => $loan,
            // What the employee owed before this run, and what is left after
            // the capped recovery above. carried_forward > 0 means the advance
            // was only partly recovered this month.
            'advance_outstanding'    => $outstanding,
            'advance_carried_forward' => max(0, $outstanding - $loan),
            'provident_fund'   => $pf,
            'eobi'             => $eobi,
            'professional_tax' => $tax,
            'penalty'          => $penalty,
            'absent_late'      => $absentLate,
            'net'              => $net,
            'activity_ids'     => array_values(array_filter($act['ids'])),
            'generated'        => $issued !== null,
            'posted'           => $issued !== null && (int) $issued['posted'] === 1,
            'payslip_id'       => $issued['id'] ?? null,
            'payslip_net'      => $issued === null ? null : $issued['net'],
        ];

        $tot['basic']            += $basic;
        $tot['allowance']        += $allowance;
        $tot['commission']       += $commission;
        $tot['bonus']            += $bonus;
        $tot['loan']             += $loan;
        $tot['provident_fund']   += $pf;
        $tot['eobi']             += $eobi;
        $tot['professional_tax'] += $tax;
        $tot['penalty']          += $penalty;
        $tot['net']              += $net;
    }

    return [
        'rows'            => $rows,
        'totals'          => $tot,
        'generated_count' => count(array_filter($rows, fn($r) => $r['generated'])),
        'pending_count'   => count(array_filter($rows, fn($r) => !$r['generated'])),
    ];
}

/**
 * payrollRows() for every month in a span, ascending, plus the totals across
 * the whole span and a per-employee roll-up.
 *
 * Months run oldest-first deliberately: this is what feeds a bulk generate, and
 * an advance recovered in January has to be gone before February is computed.
 */
function payrollRowsForRange(PDO $pdo, string $fromPeriod, string $toPeriod): array {
    [$fromPeriod, $toPeriod] = payrollNormalisePeriods($fromPeriod, $toPeriod);

    $totals = ['basic' => 0, 'allowance' => 0, 'commission' => 0, 'bonus' => 0, 'loan' => 0,
               'provident_fund' => 0, 'eobi' => 0, 'professional_tax' => 0, 'penalty' => 0, 'net' => 0];

    $months     = [];
    $byEmployee = [];
    $generated  = 0;
    $pending    = 0;

    foreach (monthsInRange(monthStart($fromPeriod), monthStart($toPeriod)) as $period) {
        $m = payrollRows($pdo, $period);

        $months[] = [
            'period'          => $period,
            'label'           => periodLabel($period),
            'rows'            => $m['rows'],
            'totals'          => $m['totals'],
            'generated_count' => $m['generated_count'],
            'pending_count'   => $m['pending_count'],
        ];

        foreach ($totals as $k => $v) $totals[$k] = $v + ($m['totals'][$k] ?? 0);
        $generated += $m['generated_count'];
        $pending   += $m['pending_count'];

        foreach ($m['rows'] as $r) {
            $key = strtolower($r['employee']);
            if (!isset($byEmployee[$key])) {
                $byEmployee[$key] = [
                    'employee'    => $r['employee'],
                    'designation' => $r['designation'],
                    'months'      => 0,
                    'generated'   => 0,
                    'pending'     => 0,
                    'net'         => 0,
                ];
            }
            $byEmployee[$key]['months']++;
            $byEmployee[$key][$r['generated'] ? 'generated' : 'pending']++;
            $byEmployee[$key]['net'] += $r['net'];
        }
    }

    return [
        'from_period'     => $fromPeriod,
        'to_period'       => $toPeriod,
        'months'          => $months,
        'totals'          => $totals,
        // Names, in employees.json order — this is what an employee filter is
        // built from. The roll-up sits beside it rather than replacing it.
        'employees'       => array_column(array_values($byEmployee), 'employee'),
        'by_employee'     => array_values($byEmployee),
        'generated_count' => $generated,
        'pending_count'   => $pending,
    ];
}

/** Two YYYY-MM strings, valid and in order. Anything unusable becomes today. */
function payrollNormalisePeriods(string $from, string $to): array {
    if (!isMonth($from)) $from = isMonth($to) ? $to : date('Y-m');
    if (!isMonth($to))   $to   = $from;
    return $from <= $to ? [$from, $to] : [$to, $from];
}

/**
 * Generate (and by default post) payslips across a span of months.
 *
 * Shared by payslips-api.php and payslip-bulk.php so "generate the missing
 * slips" means exactly one thing wherever it is triggered from.
 *
 * $opts: from_period, to_period, employees[] (names, empty = everyone),
 *        account_id, skip_existing (default true), post (default true),
 *        mark_activity (default true).
 *
 * Every slip is its own savePayslip() transaction, and a failure is recorded
 * and stepped over rather than thrown: one employee with bad data must not cost
 * the other eleven their payslips halfway through a year-end run.
 */
function payrollGenerateRange(PDO $pdo, array $opts = []): array {
    [$from, $to] = payrollNormalisePeriods(
        (string) ($opts['from_period'] ?? ''), (string) ($opts['to_period'] ?? '')
    );

    $only = [];
    foreach ((array) ($opts['employees'] ?? []) as $name) {
        $k = strtolower(trim((string) $name));
        if ($k !== '') $only[$k] = true;
    }

    $accountId    = (string) ($opts['account_id'] ?? '');
    $skipExisting = !array_key_exists('skip_existing', $opts) || (bool) $opts['skip_existing'];
    $post         = !array_key_exists('post',          $opts) || (bool) $opts['post'];
    $markActivity = !array_key_exists('mark_activity', $opts) || (bool) $opts['mark_activity'];

    $generated = []; $skipped = []; $failed = [];
    $totals = ['count' => 0, 'gross' => 0.0, 'statutory' => 0.0, 'deductions' => 0.0,
               'net' => 0.0, 'posted' => 0];

    foreach (monthsInRange(monthStart($from), monthStart($to)) as $period) {
        // Recomputed inside the loop, not up front: generating January's slips
        // recovers January's advances, and February's rows must be measured
        // against the balance that leaves behind.
        $month   = payrollRows($pdo, $period);
        $paidIds = [];

        foreach ($month['rows'] as $r) {
            $key = strtolower($r['employee']);
            if ($only && !isset($only[$key])) continue;

            if ($skipExisting && $r['generated']) {
                $skipped[] = ['employee' => $r['employee'], 'period' => $period,
                              'reason' => 'already_generated', 'payslip_id' => $r['payslip_id']];
                continue;
            }

            // Nobody is handed a payslip for nothing. Most of these are staff
            // whose salary has not been filled in yet, and a pile of Rs. 0
            // slips would have to be voided one by one to fix it.
            if ($r['basic'] + $r['allowance'] + $r['commission'] + $r['bonus'] <= 0) {
                $skipped[] = ['employee' => $r['employee'], 'period' => $period,
                              'reason' => 'no_earnings', 'payslip_id' => null];
                continue;
            }

            try {
                $saved = savePayslip($pdo, [
                    'employee'         => $r['employee'],
                    'designation'      => $r['designation'],
                    'period'           => $period,
                    'basic'            => $r['basic'],
                    'allowance'        => $r['allowance'],
                    'commission'       => $r['commission'],
                    'bonus'            => $r['bonus'],
                    'provident_fund'   => $r['provident_fund'],
                    'eobi'             => $r['eobi'],
                    'professional_tax' => $r['professional_tax'],
                    'loan'             => $r['loan'],
                    'absent_late'      => $r['absent_late'],
                    'penalty'          => $r['penalty'],
                    'activity_ids'     => $r['activity_ids'],
                ], ['account_id' => $accountId, 'post' => $post]);

                $slip = $saved['payslip'];
                $generated[] = [
                    'id'           => $slip['id'],
                    'employee'     => $slip['employee'],
                    'period'       => $period,
                    'label'        => periodLabel($period),
                    'gross'        => $slip['gross'],
                    'deductions'   => $slip['deductions'],
                    'net'          => $slip['net'],
                    'posted'       => (int) $slip['posted'] === 1,
                    'replaced'     => $saved['replaced'],
                    'transactions' => $saved['posting']['transactions'] ?? [],
                    'warnings'     => $saved['posting']['warnings'] ?? [],
                ];
                $paidIds = array_merge($paidIds, $r['activity_ids']);

                $totals['count']++;
                $totals['gross']      = round($totals['gross'] + $slip['gross'], 2);
                $totals['statutory']  = round($totals['statutory'] + $slip['statutory'], 2);
                $totals['deductions'] = round($totals['deductions'] + $slip['deductions'], 2);
                $totals['net']        = round($totals['net'] + $slip['net'], 2);
                if ((int) $slip['posted'] === 1) $totals['posted']++;
            } catch (Throwable $e) {
                $failed[] = ['employee' => $r['employee'], 'period' => $period,
                             'error' => $e->getMessage()];
            }
        }

        if ($markActivity && $paidIds) {
            payrollMarkActivityPaid($paidIds, $period);
        }
    }

    return [
        'from_period' => $from,
        'to_period'   => $to,
        'generated'   => $generated,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'totals'      => $totals,
    ];
}
