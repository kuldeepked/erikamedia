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
        $loan       = max(0, (int) round($advLc[$key] ?? 0));
        $absentLate = 0;

        $earnings   = $basic + $allowance + $commission + $bonus;
        $deductions = $pf + $eobi + $loan + $tax + $absentLate + $penalty;
        $net        = $earnings - $deductions;

        $rows[] = [
            'employee'         => $name,
            'designation'      => (string) ($emp['designation'] ?? ''),
            'basic'            => $basic,
            'allowance'        => $allowance,
            'commission'       => $commission,
            'bonus'            => $bonus,
            'loan'             => $loan,
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
