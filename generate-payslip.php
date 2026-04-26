<?php
require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verifyCsrf();

// ── Number-to-words (Pakistani / South Asian format) ─────────────────────────
function numToWords(int $n): string {
    if ($n === 0) return 'Zero';

    $ones = ['', 'One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    $words = '';

    if ($n >= 10000000) {
        $words .= numToWords((int)($n / 10000000)) . ' Crore ';
        $n %= 10000000;
    }
    if ($n >= 100000) {
        $words .= numToWords((int)($n / 100000)) . ' Lakh ';
        $n %= 100000;
    }
    if ($n >= 1000) {
        $words .= numToWords((int)($n / 1000)) . ' Thousand ';
        $n %= 1000;
    }
    if ($n >= 100) {
        $words .= $ones[(int)($n / 100)] . ' Hundred ';
        $n %= 100;
    }
    if ($n >= 20) {
        $words .= $tens[(int)($n / 10)] . ' ';
        $n %= 10;
    }
    if ($n > 0) {
        $words .= $ones[$n] . ' ';
    }

    return trim($words);
}

// ── Sanitise inputs ───────────────────────────────────────────────────────────
function h(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES);
}

function fmtAmt(float $v): string {
    return $v > 0 ? number_format($v, 0, '.', ',') : '';
}

$employee_name  = h($_POST['employee_name']  ?? '');
$designation    = h($_POST['designation']    ?? '');
$pay_period_raw = $_POST['pay_period'] ?? date('Y-m');
$pay_period     = date('M Y', strtotime($pay_period_raw . '-01'));

$basic_salary    = (float)($_POST['basic_salary']    ?? 0);
$allowance       = (float)($_POST['allowance']       ?? 0);
$commission      = (float)($_POST['commission']      ?? 0);
$performer_bonus = (float)($_POST['performer_bonus'] ?? 0);

$provident_fund  = (float)($_POST['provident_fund']  ?? 0);
$eobi            = (float)($_POST['eobi']            ?? 0);
$loan            = (float)($_POST['loan']            ?? 0);
$professional_tax= (float)($_POST['professional_tax']?? 0);
$absent_late     = (float)($_POST['absent_late']     ?? 0);
$penalty         = (float)($_POST['penalty']         ?? 0);

$total_earnings   = $basic_salary + $allowance + $commission + $performer_bonus;
$total_deductions = $provident_fund + $eobi + $loan + $professional_tax + $absent_late + $penalty;
$net_pay          = $total_earnings - $total_deductions;

$net_words = numToWords((int)round($net_pay)) . ' Rupees Only';

// Activity IDs that contributed to commissions / penalties / bonuses on this slip.
// Comma-separated when submitted; we save them on the history record AND mark them
// paid_in YYYY-MM (only on initial generation, not regeneration).
$paid_activity_ids = [];
if (!empty($_POST['paid_activity_ids'])) {
    $paid_activity_ids = array_values(array_filter(
        array_map('trim', explode(',', $_POST['paid_activity_ids']))
    ));
}

// ── Save to history (skip when regenerating from history) ────────────────────
if (empty($_POST['is_regen'])) :
$_hf   = __DIR__ . '/history.json';
$_hist = file_exists($_hf) ? (json_decode(file_get_contents($_hf), true) ?: []) : [];
array_unshift($_hist, [
    'id'              => uniqid('ps_', true),
    'type'            => 'payslip',
    'employee_name'   => trim($_POST['employee_name']    ?? ''),
    'designation'     => trim($_POST['designation']      ?? ''),
    'pay_period'      => $pay_period_raw,
    'basic_salary'    => $basic_salary,
    'allowance'       => $allowance,
    'commission'      => $commission,
    'performer_bonus' => $performer_bonus,
    'provident_fund'  => $provident_fund,
    'eobi'            => $eobi,
    'loan'            => $loan,
    'professional_tax'=> $professional_tax,
    'absent_late'     => $absent_late,
    'penalty'         => $penalty,
    'paid_activity_ids' => $paid_activity_ids,
    'generated_at'    => date('Y-m-d H:i:s'),
]);
if (count($_hist) > 200) $_hist = array_slice($_hist, 0, 200);
file_put_contents($_hf, json_encode($_hist, JSON_PRETTY_PRINT));
unset($_hf, $_hist);

// Mark contributing activity entries as paid in this pay period
if (!empty($paid_activity_ids)) {
    $_af = __DIR__ . '/activity.json';
    $_act = file_exists($_af) ? (json_decode(file_get_contents($_af), true) ?: []) : [];
    $_idSet = array_flip($paid_activity_ids);
    foreach ($_act as &$_e) {
        if (isset($_idSet[$_e['id'] ?? ''])) {
            $_e['paid_in'] = $pay_period_raw;
        }
    }
    unset($_e);
    file_put_contents($_af, json_encode($_act, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    unset($_af, $_act, $_idSet);
}
endif;

// Build earnings rows (always show salary & allowance; others only if > 0)
$earn_rows = [
    ['Salary',          $basic_salary],
    ['Allowance',       $allowance],
];
if ($commission      > 0) $earn_rows[] = ['Commission',      $commission];
if ($performer_bonus > 0) $earn_rows[] = ['Performer Bonus', $performer_bonus];

// Build deduction rows (only show if > 0)
$ded_rows = [];
if ($provident_fund  > 0) $ded_rows[] = ['Provident Fund',  $provident_fund];
if ($eobi            > 0) $ded_rows[] = ['EOBI',            $eobi];
if ($loan            > 0) $ded_rows[] = ['Loan',            $loan];
if ($professional_tax> 0) $ded_rows[] = ['Professional Tax',$professional_tax];
if ($absent_late     > 0) $ded_rows[] = ['Absent/Late',     $absent_late];
if ($penalty         > 0) $ded_rows[] = ['Penalty',         $penalty];

// Pad to same length so the table rows line up
$max_rows = max(count($earn_rows), count($ded_rows), 4); // minimum 4 body rows
while (count($earn_rows) < $max_rows) $earn_rows[] = ['', 0];
while (count($ded_rows)  < $max_rows) $ded_rows[]  = ['', 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip — <?= $employee_name ?> — <?= $pay_period ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        /* ── Screen wrapper ──────────────────── */
        body {
            margin: 0;
            padding: 30px 20px 60px;
            background: #d6dce6;
            font-family: Arial, Helvetica, sans-serif;
        }

        .action-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: #0d1b3e;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        .action-bar span {
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            font-family: Arial, sans-serif;
        }

        .action-bar span strong { color: #fff; }

        .btn-print {
            padding: 9px 22px;
            background: #4a90d9;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: Arial, sans-serif;
        }

        .btn-print:hover { background: #357abd; }

        .page-wrap { margin-top: 58px; }

        /* ── A4 Page ─────────────────────────── */
        .page {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            margin: 0 auto;
            padding: 36px 48px 48px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
            position: relative;
        }

        /* ── Logo + header ────────────────────── */
        .slip-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 0;
        }

        .logo-box {
            background: #0d1b3e;
            padding: 7px 9px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .logo-box img {
            height: 65px;
            width: auto;
            display: block;
        }

        .slip-title {
            flex: 1;
            text-align: center;
        }

        .slip-title h1 {
            font-size: 26px;
            font-weight: 400;
            color: #222;
            margin: 6px 0 4px;
            letter-spacing: 0.5px;
        }

        .slip-title .company-name {
            font-size: 13px;
            color: #333;
            font-weight: 400;
            margin-bottom: 2px;
        }

        .slip-title .company-addr {
            font-size: 12px;
            color: #555;
            line-height: 1.5;
        }

        /* ── Employee info row ────────────────── */
        .emp-info {
            display: flex;
            justify-content: space-between;
            margin: 26px 0 22px;
            font-size: 13px;
            color: #222;
        }

        .ei-col { line-height: 1.8; }

        .ei-row { display: flex; }

        .ei-label {
            width: 115px;
            color: #333;
        }

        /* ── Earnings / Deductions table ──────── */
        table.slip-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 0;
            table-layout: fixed;
        }

        .slip-table thead th {
            padding: 9px 12px;
            border: 1px solid #ccc;
            background: #f7f7f7;
            font-weight: 700;
            color: #222;
            overflow: hidden;
        }

        .slip-table thead th:nth-child(2),
        .slip-table thead th:nth-child(4) {
            text-align: right;
        }

        .slip-table tbody td {
            padding: 7px 12px;
            border: 1px solid #ccc;
            color: #333;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .slip-table tbody td:nth-child(2),
        .slip-table tbody td:nth-child(4) {
            text-align: right;
        }

        .slip-table tfoot td {
            padding: 9px 12px;
            border: 1px solid #ccc;
            font-weight: 700;
            background: #f7f7f7;
            color: #222;
        }

        .slip-table tfoot td:nth-child(2),
        .slip-table tfoot td:nth-child(4) {
            text-align: right;
        }

        /* ── Net Pay row ─────────────────────── */
        .net-pay-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            border: 1px solid #ccc;
            border-top: none;
            padding: 9px 12px;
            font-size: 13.5px;
            font-weight: 700;
            color: #111;
        }

        .net-pay-row .np-label { margin-right: auto; }

        .net-pay-row .np-amount {
            min-width: 90px;
            text-align: right;
        }

        /* ── Amount in words ─────────────────── */
        .amount-words {
            text-align: center;
            margin: 28px 0 38px;
        }

        .amount-words .aw-number {
            font-size: 17px;
            font-weight: 700;
            color: #111;
            display: block;
            margin-bottom: 5px;
        }

        .amount-words .aw-text {
            font-size: 13px;
            color: #333;
        }

        /* ── Signatures ──────────────────────── */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .sig-block {
            text-align: center;
            width: 38%;
        }

        .sig-label {
            font-size: 13px;
            color: #333;
            margin-bottom: 34px;
        }

        .sig-line-draw {
            border-top: 1.5px solid #1a2e5a;
        }

        /* ── Footer note ─────────────────────── */
        .slip-footer {
            text-align: center;
            font-size: 12px;
            color: #888;
            font-style: italic;
            margin-top: 18px;
        }

        /* ── Print ───────────────────────────── */
        @media print {
            body { background: #fff; padding: 0; }
            .action-bar { display: none !important; }
            .page-wrap { margin-top: 0; }
            .page {
                box-sizing: border-box;
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 14mm 16mm;
                box-shadow: none;
            }
            @page { size: A4 portrait; margin: 0; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span>Payslip &mdash; <strong><?= $employee_name ?></strong> &mdash; <?= $pay_period ?></span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="page-wrap">
<div class="page">

    <!-- Header: logo + title -->
    <div class="slip-header">
        <div class="logo-box">
            <img src="assets/logo.png" alt="Erika Media">
        </div>
        <div class="slip-title">
            <h1>Payslip</h1>
            <div class="company-name">Erika Media</div>
            <div class="company-addr">
                Office No. 505, 5th Floor<br>
                Kashif Center, Sharah-e-Faisal Karachi
            </div>
        </div>
    </div>

    <!-- Employee info -->
    <div class="emp-info">
        <div class="ei-col">
            <div class="ei-row">
                <span class="ei-label">Pay Period</span>
                <span>: <?= $pay_period ?></span>
            </div>
        </div>
        <div class="ei-col">
            <div class="ei-row">
                <span class="ei-label">Employee Name</span>
                <span>: <?= $employee_name ?></span>
            </div>
            <div class="ei-row">
                <span class="ei-label">Designation</span>
                <span>: <?= $designation ?></span>
            </div>
            <div class="ei-row">
                <span class="ei-label">Basic Salary</span>
                <span>: <?= number_format($basic_salary, 0, '.', ',') ?></span>
            </div>
            <div class="ei-row">
                <span class="ei-label">Allowance</span>
                <span>: <?= number_format($allowance, 0, '.', ',') ?></span>
            </div>
        </div>
    </div>

    <!-- Earnings / Deductions Table -->
    <table class="slip-table">
        <colgroup>
            <col style="width:35%">
            <col style="width:15%">
            <col style="width:35%">
            <col style="width:15%">
        </colgroup>
        <thead>
            <tr>
                <th>Earnings</th>
                <th>Amount</th>
                <th>Deductions</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < $max_rows; $i++): ?>
            <tr>
                <td><?= h($earn_rows[$i][0]) ?></td>
                <td><?= $earn_rows[$i][0] !== '' ? fmtAmt($earn_rows[$i][1]) : '' ?></td>
                <td><?= isset($ded_rows[$i]) ? h($ded_rows[$i][0]) : '' ?></td>
                <td><?= (isset($ded_rows[$i]) && $ded_rows[$i][0] !== '') ? fmtAmt($ded_rows[$i][1]) : '' ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total Earnings</td>
                <td><?= number_format($total_earnings, 0, '.', ',') ?></td>
                <td>Total Deduction</td>
                <td><?= $total_deductions > 0 ? number_format($total_deductions, 0, '.', ',') : '' ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Net Pay -->
    <div class="net-pay-row">
        <span class="np-label">Net Pay</span>
        <span class="np-amount"><?= number_format($net_pay, 0, '.', ',') ?></span>
    </div>

    <!-- Amount in words -->
    <div class="amount-words">
        <span class="aw-number"><?= number_format($net_pay, 0, '.', ',') ?></span>
        <span class="aw-text"><?= $net_words ?></span>
    </div>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-block">
            <div class="sig-label">Employer Signature</div>
            <div class="sig-line-draw"></div>
        </div>
        <div class="sig-block">
            <div class="sig-label">Employee Signature</div>
            <div class="sig-line-draw"></div>
        </div>
    </div>

    <div class="slip-footer">This is system generated payslip</div>

</div>
</div>

</body>
</html>
