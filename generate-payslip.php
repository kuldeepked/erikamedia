<?php
// Issue one payslip and print it.
//
// The page itself is unchanged — same A4 sheet, same numbers, same print
// behaviour. What happens behind it is not. This used to write a row to
// history.json, mark the activity entries paid, and hand-roll a single advance
// recovery transaction, leaving the salary itself to a "Post salaries to
// Finances" button somebody had to remember to press. Now one savePayslip()
// call records the slip and books all of it — net pay, advance recovered,
// statutory withheld — inside one transaction.
//
// history.json is still written so the Document History screen keeps working,
// but it is a display log now: the payslips table is what the books are built
// from, and it is the only one that never truncates.

require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verifyCsrf();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';     // payrollMarkActivityPaid()
require_once __DIR__ . '/payslip-render.php';  // renderPayslipHtml(), payslipStyles()

$pay_period_raw = trim((string) ($_POST['pay_period'] ?? ''));
if (!isMonth($pay_period_raw)) $pay_period_raw = date('Y-m');
$pay_period = periodLabel($pay_period_raw);

$slip = [
    'employee'         => trim((string) ($_POST['employee_name'] ?? '')),
    'designation'      => trim((string) ($_POST['designation']   ?? '')),
    'period'           => $pay_period_raw,
    'basic'            => (float) ($_POST['basic_salary']     ?? 0),
    'allowance'        => (float) ($_POST['allowance']        ?? 0),
    'commission'       => (float) ($_POST['commission']       ?? 0),
    'bonus'            => (float) ($_POST['performer_bonus']  ?? 0),
    'provident_fund'   => (float) ($_POST['provident_fund']   ?? 0),
    'eobi'             => (float) ($_POST['eobi']             ?? 0),
    'professional_tax' => (float) ($_POST['professional_tax'] ?? 0),
    'loan'             => (float) ($_POST['loan']             ?? 0),
    'absent_late'      => (float) ($_POST['absent_late']      ?? 0),
    'penalty'          => (float) ($_POST['penalty']          ?? 0),
];

// Activity IDs that contributed to commissions / penalties / bonuses on this
// slip. Comma-separated when submitted; stored on the slip AND marked paid_in
// YYYY-MM, but only on initial generation.
$paid_activity_ids = [];
if (!empty($_POST['paid_activity_ids'])) {
    $paid_activity_ids = array_values(array_filter(
        array_map('trim', explode(',', (string) $_POST['paid_activity_ids']))
    ));
}
$slip['activity_ids'] = $paid_activity_ids;

// Which account the net pay leaves. Falls back to the saved default, and
// postPayslip() falls back again to the first ordinary asset account, so a form
// that never learned about this field still works.
$account_id = trim((string) ($_POST['account_id'] ?? ''));

$save_error = null;

// ── Record and book, unless this is a reprint ────────────────────────────────
// is_regen means the user re-opened an existing document from Document History
// or clicked Re-gen on a row that already has a slip. That is a reprint of a
// document that already exists in the books: re-saving would void the live slip
// and write a fresh set of ledger rows for a payslip nobody re-issued.
if (empty($_POST['is_regen'])) {
    try {
        $pdo = db();
        $res = savePayslip($pdo, $slip, ['account_id' => $account_id]);

        // Print what was recorded, not what was asked for. savePayslip clamps
        // the advance recovery to what the employee actually still owes, so a
        // stale form offering to recover 25,000 against a 6,000 balance books
        // 6,000 — and the sheet the employee signs has to say the same.
        $stored = $res['payslip'];
        foreach (['basic','allowance','commission','bonus','provident_fund','eobi',
                  'professional_tax','loan','absent_late','penalty'] as $k) {
            $slip[$k] = $stored[$k];
        }

        // Display log for the Document History screen. Same shape as always so
        // regenerate.php can post it straight back here, and the same 200-record
        // cap — offer letters share this file and it is not a record of account.
        $hf   = __DIR__ . '/history.json';
        $hist = payrollReadJson($hf);
        array_unshift($hist, [
            'id'                => $stored['id'],   // the real payslip id, so History links to the record
            'type'              => 'payslip',
            'employee_name'     => $stored['employee'],
            'designation'       => $stored['designation'],
            'pay_period'        => $pay_period_raw,
            'basic_salary'      => $stored['basic'],
            'allowance'         => $stored['allowance'],
            'commission'        => $stored['commission'],
            'performer_bonus'   => $stored['bonus'],
            'provident_fund'    => $stored['provident_fund'],
            'eobi'              => $stored['eobi'],
            'loan'              => $stored['loan'],
            'professional_tax'  => $stored['professional_tax'],
            'absent_late'       => $stored['absent_late'],
            'penalty'           => $stored['penalty'],
            'paid_activity_ids' => $paid_activity_ids,
            'generated_at'      => date('Y-m-d H:i:s'),
        ]);
        if (count($hist) > 200) $hist = array_slice($hist, 0, 200);
        file_put_contents($hf, json_encode($hist, JSON_PRETTY_PRINT));

        payrollMarkActivityPaid($paid_activity_ids, $pay_period_raw);
    } catch (Throwable $e) {
        // The slip still prints, because the person waiting on it should not be
        // stranded on a stack trace — but this is not the old "advance recovery
        // failed, never mind" case. Nothing was recorded, so it says so, loudly
        // and on screen only. The activity entries stay unpaid and history stays
        // untouched, which means generating again is safe.
        $save_error = $e->getMessage();
        error_log('payslip save failed: ' . $save_error);
    }
}

$employee_name = payslipEsc($slip['employee']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip — <?= $employee_name ?> — <?= $pay_period ?></title>
    <style>
<?= payslipStyles() ?>

        .save-warning {
            max-width: 210mm;
            margin: 0 auto 14px;
            padding: 11px 16px;
            background: #fdecea;
            border: 1px solid #e6a29a;
            border-radius: 5px;
            color: #8c2f22;
            font-size: 13px;
            line-height: 1.5;
        }

        @media print {
            .save-warning { display: none !important; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span>Payslip &mdash; <strong><?= $employee_name ?></strong> &mdash; <?= $pay_period ?></span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="page-wrap">
<?php if ($save_error !== null): ?>
    <div class="save-warning">
        <strong>This payslip was not recorded.</strong>
        It printed, but nothing was saved and nothing reached the books:
        <?= payslipEsc($save_error) ?>.
        Fix that and generate it again — no activity has been marked paid, so re-running is safe.
    </div>
<?php endif; ?>
<?= renderPayslipHtml($slip) ?>
</div>

</body>
</html>
