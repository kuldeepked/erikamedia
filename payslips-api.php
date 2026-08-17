<?php
// The payslips table, as an API.
//
// Everything here reads or writes real payslip records — the ones the payroll
// register and the P&L are built from. history.json is not consulted at any
// point; it is a display log for the Document History screen and truncates at
// 200 records, which is under two years of payroll.
//
//   GET  ?from_period&to_period[&employees&include_voided&posted]
//   GET  ...&format=csv          the payroll register a consultant reconciles
//   POST action=generate         issue + book slips across a month or a span
//   POST action=void             void a slip and reverse what it booked
//   POST action=post             book a slip that was saved unposted
//   POST action=post_range       book every unposted live slip in a span

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';
requireLogin();

$pdo = db();

// ── GET: list, register, reconciliation ──────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // A year to today unless asked otherwise. An unbounded default would drag
    // every slip ever issued through the register on a screen that mostly wants
    // "the recent past".
    $to   = trim((string) ($_GET['to_period']   ?? ''));
    $from = trim((string) ($_GET['from_period'] ?? ''));
    if (!isMonth($to))   $to   = date('Y-m');
    if (!isMonth($from)) $from = date('Y-m', strtotime($to . '-01 -11 months'));
    [$from, $to] = payrollNormalisePeriods($from, $to);

    $employees = [];
    foreach (explode(',', (string) ($_GET['employees'] ?? '')) as $name) {
        $name = trim($name);
        if ($name !== '') $employees[] = $name;
    }

    $filters = [
        'from_period'    => $from,
        'to_period'      => $to,
        'employees'      => $employees,
        'include_voided' => !empty($_GET['include_voided']),
    ];
    $postedFilter = (string) ($_GET['posted'] ?? '');
    if ($postedFilter === '0' || $postedFilter === '1') {
        $filters['posted'] = (int) $postedFilter;
    }

    $payslips = listPayslips($pdo, $filters);
    $register = payrollRegister($pdo, $from, $to, $employees);

    if (($_GET['format'] ?? '') === 'csv') {
        payslipsRegisterCsv($pdo, $register, $from, $to);
    }

    jsonResponse([
        'from_period'    => $from,
        'to_period'      => $to,
        'employees'      => $employees,
        'currency'       => baseCurrency($pdo),
        'count'          => count($payslips),
        'payslips'       => $payslips,
        'register'       => $register,
        'reconciliation' => payrollReconciliation($pdo, $from, $to),
    ]);
}

/**
 * The payroll register as a spreadsheet: one row per payslip with every
 * component, then a totals row. Header block names the company and the period
 * the way the other exports do, so a downloaded file still says what it is
 * three months later.
 */
function payslipsRegisterCsv(PDO $pdo, array $register, string $from, string $to): void {
    $label = $from === $to ? periodLabel($from) : periodLabel($from) . ' – ' . periodLabel($to);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payroll-register-' . $from . '_' . $to . '.csv"');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, [settingGet($pdo, 'company_name', 'Erika Media')]);
    fputcsv($fp, ['Payroll register']);
    fputcsv($fp, ['Period', $label]);
    fputcsv($fp, ['Currency', baseCurrency($pdo)]);
    fputcsv($fp, ['Generated', date('Y-m-d H:i')]);
    fputcsv($fp, []);

    $money = fn($v) => number_format((float) $v, 2, '.', '');

    fputcsv($fp, [
        'Payslip ID', 'Period', 'Employee', 'Designation',
        'Basic', 'Allowance', 'Commission', 'Bonus', 'Gross',
        'Provident Fund', 'EOBI', 'Professional Tax', 'Statutory',
        'Advance Recovered', 'Absent/Late', 'Penalty', 'Total Deductions', 'Net',
        'Posted', 'Voided', 'Generated At',
    ]);

    // Oldest first — a register is read the way a year is lived.
    $slips = $register['slips'];
    usort($slips, fn($a, $b) => [$a['period'], $a['employee']] <=> [$b['period'], $b['employee']]);

    foreach ($slips as $s) {
        fputcsv($fp, [
            $s['id'], $s['period'], $s['employee'], $s['designation'],
            $money($s['basic']), $money($s['allowance']), $money($s['commission']),
            $money($s['bonus']), $money($s['gross']),
            $money($s['provident_fund']), $money($s['eobi']), $money($s['professional_tax']),
            $money($s['statutory']),
            $money($s['loan']), $money($s['absent_late']), $money($s['penalty']),
            $money($s['deductions']), $money($s['net']),
            $s['posted'] ? 'yes' : 'no', $s['voided'] ? 'yes' : 'no', $s['generated_at'],
        ]);
    }

    $t = $register['totals'];
    fputcsv($fp, []);
    fputcsv($fp, [
        'TOTAL', '', $t['count'] . ' payslips', '',
        $money($t['basic']), $money($t['allowance']), $money($t['commission']),
        $money($t['bonus']), $money($t['gross']),
        $money($t['provident_fund']), $money($t['eobi']), $money($t['professional_tax']),
        $money($t['statutory']),
        $money($t['loan']), $money($t['absent_late']), $money($t['penalty']),
        $money($t['deductions']), $money($t['net']), '', '', '',
    ]);
    fputcsv($fp, []);
    fputcsv($fp, ['Salary cost expected in the ledger', $money($register['expected_salary_expense'])]);
    fclose($fp);
    exit;
}

// ── POST: generate, void, post ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}
verifyCsrf();

$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'generate') {
    // period is shorthand for a single month.
    $period = trim((string) ($input['period'] ?? ''));
    $from   = trim((string) ($input['from_period'] ?? ''));
    $to     = trim((string) ($input['to_period']   ?? ''));
    if (isMonth($period)) {
        $from = $to = $period;
    } elseif (!isMonth($from) && !isMonth($to)) {
        jsonError('A period (YYYY-MM), or from_period and to_period, is required.');
    }

    $accountId = trim((string) ($input['account_id'] ?? ''));
    if ($accountId !== '') {
        $check = $pdo->prepare('SELECT 1 FROM accounts WHERE id = ? AND archived = 0');
        $check->execute([$accountId]);
        if (!$check->fetchColumn()) jsonError('That source account was not found.');
    }

    $res = payrollGenerateRange($pdo, [
        'from_period'   => $from,
        'to_period'     => $to,
        'employees'     => (array) ($input['employees'] ?? []),
        'account_id'    => $accountId,
        // Default on: a bulk run over a span is normally a catch-up, and
        // replacing slips that already exist would void and re-book months of
        // payroll nobody asked to touch.
        'skip_existing' => !array_key_exists('skip_existing', $input) || (bool) $input['skip_existing'],
    ]);

    jsonResponse(array_merge(['success' => true], $res));
}

if ($action === 'void') {
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') jsonError('A payslip id is required.');

    try {
        $res = voidPayslip($pdo, $id);
    } catch (Throwable $e) {
        jsonError($e->getMessage());
    }
    jsonResponse(array_merge(['success' => true, 'id' => $id], $res));
}

if ($action === 'post') {
    $id        = trim((string) ($input['id'] ?? ''));
    $accountId = trim((string) ($input['account_id'] ?? ''));
    if ($id === '') jsonError('A payslip id is required.');

    try {
        $res = postPayslip($pdo, $id, $accountId);
    } catch (Throwable $e) {
        jsonError($e->getMessage());
    }
    jsonResponse(array_merge(['success' => true, 'id' => $id], $res));
}

if ($action === 'post_range') {
    $from = trim((string) ($input['from_period'] ?? ''));
    $to   = trim((string) ($input['to_period']   ?? ''));
    if (!isMonth($from) && !isMonth($to)) {
        jsonError('from_period and to_period (YYYY-MM) are required.');
    }
    [$from, $to] = payrollNormalisePeriods($from, $to);

    $accountId = trim((string) ($input['account_id'] ?? ''));
    if ($accountId !== '') {
        $check = $pdo->prepare('SELECT 1 FROM accounts WHERE id = ? AND archived = 0');
        $check->execute([$accountId]);
        if (!$check->fetchColumn()) jsonError('That source account was not found.');
        settingSet($pdo, 'payroll_account_id', $accountId);
    }

    $posted = []; $skipped = []; $failed = []; $warnings = [];
    $total  = 0.0;

    foreach (listPayslips($pdo, ['from_period' => $from, 'to_period' => $to, 'posted' => 0]) as $slip) {
        try {
            $res = postPayslip($pdo, $slip['id'], $accountId);
            $row = ['id' => $slip['id'], 'employee' => $slip['employee'], 'period' => $slip['period']];

            if (!empty($res['already'])) {
                $skipped[] = $row + ['reason' => 'already_posted'];
            } elseif (empty($res['posted'])) {
                $skipped[] = $row + ['reason' => 'nothing_to_post'];
            } else {
                $amount   = $res['booked']['total_expense'] ?? 0.0;
                $posted[] = $row + ['amount' => $amount, 'net' => $slip['net'],
                                    'transactions' => $res['transactions']];
                $total   += $amount;
            }
            $warnings = array_merge($warnings, $res['warnings'] ?? []);
        } catch (Throwable $e) {
            $failed[] = ['id' => $slip['id'], 'employee' => $slip['employee'],
                         'period' => $slip['period'], 'error' => $e->getMessage()];
        }
    }

    jsonResponse([
        'success'      => true,
        'from_period'  => $from,
        'to_period'    => $to,
        'account_id'   => $accountId,
        'posted'       => $posted,
        'skipped'      => $skipped,
        'failed'       => $failed,
        'warnings'     => array_values(array_unique($warnings)),
        'amount_total' => round($total, 2),
    ]);
}

jsonError('Invalid action.');
