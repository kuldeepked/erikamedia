<?php
// The printed payslip: markup, styles and the amount-in-words line.
//
// Lifted out of generate-payslip.php unchanged so payslip-bulk.php can put a
// hundred slips in one print job without the two ever drifting. A slip printed
// on its own and the same slip inside a bulk run come out of this function
// character for character — which matters, because the employee keeps one copy
// and the file keeps the other.
//
// Pure functions, no output, no side effects. The caller supplies the <html>
// chrome and decides what to do with the string.

require_once __DIR__ . '/payslip-lib.php';

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

// Prefixed rather than the old bare h() / fmtAmt(), because half the standalone
// generators in this app define an h() of their own and this file is now
// required from more than one of them.
function payslipEsc(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES);
}

function payslipAmt(float $v): string {
    return $v > 0 ? number_format($v, 0, '.', ',') : '';
}

/**
 * The stylesheet for a payslip page. Identical to what generate-payslip.php has
 * always emitted — including the action-bar rules, since the bulk page carries
 * the same bar and both hide it when printing.
 */
function payslipStyles(): string {
    return <<<'CSS'
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
CSS;
}

/**
 * One A4 payslip, as the <div class="page"> the stylesheet above expects.
 *
 * $slip takes the payslips-table column names, so a row straight out of
 * loadPayslip() can be handed over untouched: employee, designation, period,
 * basic, allowance, commission, bonus, provident_fund, eobi, professional_tax,
 * loan, absent_late, penalty. Totals are never passed in — they come from
 * payslipTotals() so the paper and the ledger cannot disagree.
 */
function renderPayslipHtml(array $slip): string {
    $employee_name = payslipEsc((string) ($slip['employee'] ?? ''));
    $designation   = payslipEsc((string) ($slip['designation'] ?? ''));

    $period     = trim((string) ($slip['period'] ?? ''));
    $pay_period = isMonth($period) ? periodLabel($period) : payslipEsc($period);

    $basic_salary    = (float) ($slip['basic']            ?? 0);
    $allowance       = (float) ($slip['allowance']        ?? 0);
    $commission      = (float) ($slip['commission']       ?? 0);
    $performer_bonus = (float) ($slip['bonus']            ?? 0);
    $provident_fund  = (float) ($slip['provident_fund']   ?? 0);
    $eobi            = (float) ($slip['eobi']             ?? 0);
    $loan            = (float) ($slip['loan']             ?? 0);
    $professional_tax= (float) ($slip['professional_tax'] ?? 0);
    $absent_late     = (float) ($slip['absent_late']      ?? 0);
    $penalty         = (float) ($slip['penalty']          ?? 0);

    $totals           = payslipTotals($slip);
    $total_earnings   = $totals['gross'];
    $total_deductions = $totals['deductions'];
    $net_pay          = $totals['net'];

    $net_words = numToWords((int) round($net_pay)) . ' Rupees Only';

    // Build earnings rows (always show salary & allowance; others only if > 0)
    $earn_rows = [
        ['Salary',          $basic_salary],
        ['Allowance',       $allowance],
    ];
    if ($commission      > 0) $earn_rows[] = ['Commission',      $commission];
    if ($performer_bonus > 0) $earn_rows[] = ['Punctuality Bonus', $performer_bonus];

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

    ob_start();
?>
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
                <td><?= payslipEsc($earn_rows[$i][0]) ?></td>
                <td><?= $earn_rows[$i][0] !== '' ? payslipAmt($earn_rows[$i][1]) : '' ?></td>
                <td><?= isset($ded_rows[$i]) ? payslipEsc($ded_rows[$i][0]) : '' ?></td>
                <td><?= (isset($ded_rows[$i]) && $ded_rows[$i][0] !== '') ? payslipAmt($ded_rows[$i][1]) : '' ?></td>
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
<?php
    return (string) ob_get_clean();
}
