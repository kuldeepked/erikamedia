<?php
// Shared payroll math. Used by payroll-api.php (preview) and
// payroll-post-api.php (post salaries to Finances) so the two can never drift.
// Mirrors generate-payslip.php exactly:
//   Earnings   = basic + allowance + commission + performer_bonus
//   Deductions = provident_fund + eobi + loan(advance) + professional_tax
//                + absent_late + penalty
//   Net        = earnings - deductions
// Commission/bonus/penalty come from UNPAID activity entries for the month
// (penalties include the ones the Attendance screen logs). Loan = outstanding
// salary advance. "generated" = a payslip already exists for that emp/month.

require_once __DIR__ . '/finance-lib.php';

function payrollReadJson(string $file): array {
    return file_exists($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

/**
 * Net pay each employee was ACTUALLY paid for $month, read back from the
 * payslip record written when the slip was generated.
 *
 * payrollRows() recomputes from live state. That is right for the preview but
 * wrong for posting to Finances: generating a payslip writes the advance
 * recovery, which zeroes the outstanding balance, so a later recompute returns
 * a net higher by exactly the advance. Posting must match the document the
 * employee was handed, so it reads the stored payslip instead of recomputing.
 *
 * History is newest-first, so the first record found for an employee is the
 * most recent payslip for that month — which is the one that counts if a slip
 * was regenerated.
 *
 * @return array<string, array{employee: string, net: int, generated_at: string}>
 *         keyed by lowercased employee name.
 */
function payrollGeneratedNet(string $month): array {
    $history = payrollReadJson(__DIR__ . '/history.json');
    $out = [];

    foreach ($history as $h) {
        if (($h['type'] ?? '') !== 'payslip') continue;
        if ((string) ($h['pay_period'] ?? '') !== $month) continue;

        $name = trim((string) ($h['employee_name'] ?? ''));
        if ($name === '') continue;

        $key = strtolower($name);
        if (isset($out[$key])) continue;   // keep the newest slip only

        $earnings = (int) ($h['basic_salary'] ?? 0) + (int) ($h['allowance'] ?? 0)
                  + (int) ($h['commission'] ?? 0)   + (int) ($h['performer_bonus'] ?? 0);

        $deductions = (int) ($h['provident_fund'] ?? 0) + (int) ($h['eobi'] ?? 0)
                    + (int) ($h['loan'] ?? 0)           + (int) ($h['professional_tax'] ?? 0)
                    + (int) ($h['absent_late'] ?? 0)    + (int) ($h['penalty'] ?? 0);

        $out[$key] = [
            'employee'     => $name,
            'net'          => $earnings - $deductions,
            'generated_at' => (string) ($h['generated_at'] ?? ''),
        ];
    }

    return $out;
}

function payrollRows(PDO $pdo, string $month): array {
    $employees = payrollReadJson(__DIR__ . '/employees.json');
    $activity  = payrollReadJson(__DIR__ . '/activity.json');
    $history   = payrollReadJson(__DIR__ . '/history.json');

    // Outstanding advances keyed case-insensitively.
    $advLc = [];
    foreach (advancesByEmployee($pdo) as $name => $amt) {
        $advLc[strtolower($name)] = $amt;
    }

    // Already-generated payslips this month.
    $generated = [];
    foreach ($history as $h) {
        if (($h['type'] ?? '') === 'payslip' && ($h['pay_period'] ?? '') === $month) {
            $generated[strtolower(trim((string) ($h['employee_name'] ?? '')))] = true;
        }
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
            'generated'        => isset($generated[$key]),
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
