<?php
// Every payslip in a date range, as one print job.
//
// Printing a quarter of payroll used to mean opening one tab per employee per
// month and saving twelve PDFs by hand. This puts the same slips — rendered by
// the same function, so they are identical to the ones printed individually —
// on consecutive A4 sheets in a single document. "Print / Save as PDF" then
// produces one file containing all of them.
//
// Existing slips are what gets printed. Pass generate_missing to issue (and
// book) the ones that are absent first; that never touches a slip that already
// exists, because a print button must not be able to void a month of payroll.

require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verifyCsrf();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';
require_once __DIR__ . '/payslip-render.php';

$pdo = db();

[$from, $to] = payrollNormalisePeriods(
    trim((string) ($_POST['from_period'] ?? '')),
    trim((string) ($_POST['to_period']   ?? ''))
);

$employees = [];
foreach ((array) ($_POST['employees'] ?? []) as $name) {
    $name = trim((string) $name);
    if ($name !== '') $employees[] = $name;
}

// only_generated is the default and the safe reading of this screen: print what
// was issued. It is kept as an explicit flag so a caller can send it alongside
// generate_missing and have the more conservative one win.
$onlyGenerated  = !empty($_POST['only_generated']);
$generateFirst  = !empty($_POST['generate_missing']) && !$onlyGenerated;
$accountId      = trim((string) ($_POST['account_id'] ?? ''));

$run = null;
if ($generateFirst) {
    $run = payrollGenerateRange($pdo, [
        'from_period'   => $from,
        'to_period'     => $to,
        'employees'     => $employees,
        'account_id'    => $accountId,
        'skip_existing' => true,
    ]);
}

$slips = listPayslips($pdo, [
    'from_period' => $from,
    'to_period'   => $to,
    'employees'   => $employees,
]);

// Chronological: a bulk print is filed in the order the months happened, not
// newest first the way a screen lists them.
usort($slips, fn($a, $b) => [$a['period'], $a['employee']] <=> [$b['period'], $b['employee']]);

$netTotal = 0.0;
foreach ($slips as $s) $netTotal += $s['net'];

$rangeLabel = $from === $to
    ? periodLabel($from)
    : periodLabel($from) . ' to ' . periodLabel($to);

$summary = count($slips) === 1
    ? '1 payslip'
    : count($slips) . ' payslips';
$summary .= ' — ' . $rangeLabel . ' — total net ' . fmtMoneyShort($netTotal, baseCurrency($pdo));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslips — <?= payslipEsc($rangeLabel) ?></title>
    <style>
<?= payslipStyles() ?>

        /* ── Bulk-only: many sheets in one document ──────────────── */
        .action-bar .bulk-note {
            display: block;
            margin-top: 3px;
            font-size: 12px;
            color: rgba(255,255,255,0.55);
        }

        .slip-sheet + .slip-sheet { margin-top: 26px; }

        .bulk-empty {
            width: 210mm;
            max-width: 100%;
            background: #fff;
            margin: 0 auto;
            padding: 48px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
            text-align: center;
            color: #444;
            font-size: 14px;
            line-height: 1.7;
        }

        .bulk-empty h2 {
            font-size: 19px;
            font-weight: 400;
            color: #222;
            margin: 0 0 10px;
        }

        .bulk-empty .hint { color: #777; font-size: 13px; }

        @media print {
            /* The screen gap between sheets would push each slip down its page. */
            .slip-sheet + .slip-sheet { margin-top: 0; }
            .bulk-empty { display: none !important; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span>
        <strong><?= payslipEsc($summary) ?></strong>
        <?php if ($run !== null): ?>
        <span class="bulk-note">
            Generated <?= count($run['generated']) ?>,
            skipped <?= count($run['skipped']) ?><?php if ($run['failed']): ?>,
            <?= count($run['failed']) ?> failed:
            <?php
            $msgs = [];
            foreach ($run['failed'] as $f) {
                $msgs[] = $f['employee'] . ' (' . $f['period'] . ') — ' . $f['error'];
            }
            echo payslipEsc(implode('; ', $msgs));
            ?>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="page-wrap">
<?php if (!$slips): ?>
    <div class="bulk-empty">
        <h2>No payslips for <?= payslipEsc($rangeLabel) ?></h2>
        <p>
            <?php if ($employees): ?>
                Nothing has been issued to <?= payslipEsc(implode(', ', $employees)) ?> in this period.
            <?php else: ?>
                No payslips have been issued in this period yet.
            <?php endif; ?>
        </p>
        <p class="hint">
            Generate them from the Payroll Run screen, or send this page again with
            <em>generate missing</em> ticked to issue and book them now.
        </p>
    </div>
<?php else: ?>
    <?php foreach ($slips as $i => $slip): ?>
    <?php // Break after every sheet but the last, so the PDF has no trailing blank page. ?>
    <div class="slip-sheet"<?= $i < count($slips) - 1 ? ' style="page-break-after: always;"' : '' ?>>
<?= renderPayslipHtml($slip) ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

</body>
</html>
