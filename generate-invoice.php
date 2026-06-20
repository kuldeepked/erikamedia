<?php
require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verifyCsrf();

function h(string $v): string { return htmlspecialchars(trim($v), ENT_QUOTES); }

// ── Inputs ────────────────────────────────────────────────────────────────
$client_name    = h($_POST['client_name']    ?? '');
$client_email   = h($_POST['client_email']   ?? '');
$client_address = trim((string) ($_POST['client_address'] ?? ''));

$currency = trim((string) ($_POST['currency'] ?? 'Rs.'));
if ($currency === '') $currency = 'Rs.';
$decimals = preg_match('/rs|pkr/i', $currency) ? 0 : 2;

$invoice_no = trim((string) ($_POST['invoice_no'] ?? ''));
if ($invoice_no === '') $invoice_no = 'EM-' . date('Y') . '-001';

$issue_raw  = $_POST['issue_date'] ?? date('Y-m-d');
$issue_date = date('F j, Y', strtotime($issue_raw));
$due_date   = !empty($_POST['due_date']) ? date('F j, Y', strtotime($_POST['due_date'])) : '';

$tax_percent = max(0, (float) ($_POST['tax_percent'] ?? 0));
$notes       = trim((string) ($_POST['notes'] ?? ''));

// ── Line items (arrays from the form) ─────────────────────────────────────
$descs  = $_POST['item_desc']  ?? [];
$qtys   = $_POST['item_qty']   ?? [];
$prices = $_POST['item_price'] ?? [];

$items = [];
$subtotal = 0.0;
for ($i = 0; $i < count($descs); $i++) {
    $d = trim((string) $descs[$i]);
    if ($d === '') continue;
    $q   = (float) ($qtys[$i]   ?? 0);
    $p   = (float) ($prices[$i] ?? 0);
    $amt = $q * $p;
    $subtotal += $amt;
    $items[] = ['desc' => $d, 'qty' => $q, 'price' => $p, 'amount' => $amt];
}

if (!$items || $client_name === '') {
    header('Location: index.php');
    exit;
}

$tax   = $subtotal * $tax_percent / 100;
$total = $subtotal + $tax;

function money(float $v, string $cur, int $dec): string {
    return $cur . ' ' . number_format($v, $dec, '.', ',');
}
function qtyFmt(float $v): string {
    return rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');
}

// ── Save a record (numbering + history of billed invoices) ────────────────
$invFile = __DIR__ . '/invoices.json';
$inv = file_exists($invFile) ? (json_decode((string) file_get_contents($invFile), true) ?: []) : [];
array_unshift($inv, [
    'invoice_no'   => $invoice_no,
    'client_name'  => trim((string) ($_POST['client_name'] ?? '')),
    'issue_date'   => $issue_raw,
    'due_date'     => $_POST['due_date'] ?? '',
    'currency'     => $currency,
    'subtotal'     => round($subtotal, 2),
    'tax_percent'  => $tax_percent,
    'tax'          => round($tax, 2),
    'total'        => round($total, 2),
    'items'        => $items,
    'generated_at' => date('Y-m-d H:i:s'),
]);
if (count($inv) > 500) $inv = array_slice($inv, 0, 500);
file_put_contents($invFile, json_encode($inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= h($invoice_no) ?> &mdash; <?= $client_name ?></title>
    <style>
        body { margin: 0; padding: 30px 20px 60px; background: #d6dce6; font-family: Arial, Helvetica, sans-serif; }

        .action-bar { position: fixed; top: 0; left: 0; right: 0; background: #0d1b3e; padding: 10px 24px;
                      display: flex; align-items: center; justify-content: space-between; z-index: 999;
                      box-shadow: 0 2px 8px rgba(0,0,0,0.25); }
        .action-bar span { color: rgba(255,255,255,0.7); font-size: 13px; }
        .action-bar span strong { color: #fff; }
        .btn-print { padding: 9px 22px; background: #4a90d9; color: #fff; border: none; border-radius: 5px;
                     font-size: 13px; font-weight: 600; cursor: pointer; font-family: Arial, sans-serif; letter-spacing: 0.3px; }
        .btn-print:hover { background: #357abd; }

        .pages-wrapper { margin-top: 58px; }
        .page { width: 210mm; min-height: 297mm; background: #fff; margin: 0 auto; position: relative;
                overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.18); display: flex; flex-direction: column; }

        /* Decorative corners (match letterhead) */
        .deco-tr { position: absolute; top: 0; right: 0; width: 170px; height: 120px; pointer-events: none; overflow: hidden; }
        .deco-tr-gray { position: absolute; top: -18px; right: -18px; width: 155px; height: 95px; background: #8a9baa; transform: skewX(-22deg); transform-origin: top right; opacity: 0.75; }
        .deco-tr-teal { position: absolute; top: 12px; right: -8px; width: 120px; height: 78px; background: #1a8e82; transform: skewX(-22deg); transform-origin: top right; }
        .deco-bl { position: absolute; bottom: 0; left: 0; width: 110px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-bl-teal { position: absolute; bottom: -18px; left: -18px; width: 110px; height: 60px; background: #1a8e82; transform: skewX(-22deg); transform-origin: bottom left; }
        .deco-br { position: absolute; bottom: 0; right: 0; width: 130px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-br-gray { position: absolute; bottom: -18px; right: -18px; width: 130px; height: 60px; background: #8a9baa; transform: skewX(-22deg); transform-origin: bottom right; opacity: 0.65; }

        /* Header */
        .lh-header { padding: 28px 50px 20px; display: flex; align-items: flex-start; justify-content: space-between; position: relative; z-index: 5; }
        .logo-wrap { display: inline-block; background: #0d1b3e; padding: 7px 9px; border-radius: 4px; }
        .logo-wrap img { height: 56px; width: auto; display: block; }
        .company-info { text-align: right; }
        .company-info .co-name { font-size: 15px; font-weight: 700; color: #0d1b3e; letter-spacing: 0.3px; }
        .company-info .co-tagline { font-size: 10px; color: #1a8e82; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 3px; }
        .lh-divider { height: 2px; background: linear-gradient(to right, #0d1b3e 60%, #1a8e82 100%); margin: 0 50px; }

        /* Invoice body */
        .inv-body { flex: 1; padding: 26px 50px 0; color: #222; position: relative; z-index: 5; }
        .inv-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 30px; margin-bottom: 26px; }
        .inv-title { font-size: 30px; font-weight: 800; letter-spacing: 2px; color: #0d1b3e; margin-bottom: 18px; }
        .inv-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #1a8e82; font-weight: 700; margin-bottom: 5px; }
        .inv-client { font-size: 15px; font-weight: 700; color: #0d1b3e; }
        .inv-cmeta { font-size: 12px; color: #444; margin-top: 2px; line-height: 1.5; }
        .inv-meta { min-width: 230px; background: #f4f6f9; border: 1px solid #e0e5ec; border-radius: 6px; padding: 14px 16px; }
        .inv-meta-row { display: flex; justify-content: space-between; gap: 16px; font-size: 12.5px; padding: 3px 0; color: #555; }
        .inv-meta-row strong { color: #0d1b3e; }

        .inv-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 18px; }
        .inv-table thead th { background: #0d1b3e; color: #fff; padding: 9px 12px; text-align: left; font-weight: 600; }
        .inv-table thead th.r { text-align: right; }
        .inv-table tbody td { padding: 9px 12px; border-bottom: 1px solid #e3e7ee; color: #333; }
        .inv-table tbody td.r { text-align: right; white-space: nowrap; }
        .inv-table tbody tr:nth-child(even) td { background: #f8fafc; }

        .inv-totals { margin-left: auto; width: 300px; margin-bottom: 22px; }
        .inv-tot-row { display: flex; justify-content: space-between; padding: 7px 12px; font-size: 13px; color: #333; }
        .inv-tot-row.inv-grand { background: #0d1b3e; color: #fff; font-weight: 700; font-size: 15px; border-radius: 4px; margin-top: 4px; }

        .inv-notes { font-size: 12px; color: #444; line-height: 1.6; border-top: 1px solid #ddd; padding-top: 14px; max-width: 62%; }
        .inv-notes .inv-label { margin-bottom: 6px; }

        /* Footer (match letterhead) */
        .lh-footer { margin: 24px 50px 0; padding: 14px 0 28px; border-top: 1px solid #ccc; display: flex;
                     justify-content: space-between; align-items: flex-start; font-size: 11px; color: #444; position: relative; z-index: 5; }
        .footer-col { display: flex; align-items: flex-start; gap: 7px; }
        .footer-icon { font-size: 13px; margin-top: 1px; flex-shrink: 0; }
        .footer-label { font-weight: 700; display: block; margin-bottom: 1px; font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; color: #0d1b3e; }

        @media print {
            body { background: white; padding: 0; }
            .action-bar { display: none !important; }
            .pages-wrapper { margin-top: 0; }
            .page { width: 100%; margin: 0; box-shadow: none; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span>Invoice <strong><?= h($invoice_no) ?></strong> &mdash; <?= $client_name ?></span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="pages-wrapper">
<div class="page">
    <div class="deco-tr"><div class="deco-tr-gray"></div><div class="deco-tr-teal"></div></div>
    <div class="deco-bl"><div class="deco-bl-teal"></div></div>
    <div class="deco-br"><div class="deco-br-gray"></div></div>

    <div class="lh-header">
        <div class="logo-wrap"><img src="assets/logo.png" alt="Erika Media"></div>
        <div class="company-info">
            <div class="co-name">Erika Media</div>
            <div class="co-tagline">Where Technology Meets Creativity</div>
        </div>
    </div>
    <div class="lh-divider"></div>

    <div class="inv-body">
        <div class="inv-top">
            <div>
                <div class="inv-title">INVOICE</div>
                <div class="inv-label">Bill To</div>
                <div class="inv-client"><?= $client_name ?></div>
                <?php if ($client_email !== ''): ?><div class="inv-cmeta"><?= $client_email ?></div><?php endif; ?>
                <?php if ($client_address !== ''): ?><div class="inv-cmeta"><?= nl2br(h($client_address)) ?></div><?php endif; ?>
            </div>
            <div class="inv-meta">
                <div class="inv-meta-row"><span>Invoice #</span><strong><?= h($invoice_no) ?></strong></div>
                <div class="inv-meta-row"><span>Issue date</span><strong><?= h($issue_date) ?></strong></div>
                <?php if ($due_date !== ''): ?><div class="inv-meta-row"><span>Due date</span><strong><?= h($due_date) ?></strong></div><?php endif; ?>
            </div>
        </div>

        <table class="inv-table">
            <thead>
                <tr><th style="width:34px;">#</th><th>Description</th><th class="r" style="width:70px;">Qty</th>
                    <th class="r" style="width:120px;">Unit Price</th><th class="r" style="width:130px;">Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $it): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= h($it['desc']) ?></td>
                    <td class="r"><?= qtyFmt($it['qty']) ?></td>
                    <td class="r"><?= money($it['price'], $currency, $decimals) ?></td>
                    <td class="r"><?= money($it['amount'], $currency, $decimals) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="inv-totals">
            <div class="inv-tot-row"><span>Subtotal</span><span><?= money($subtotal, $currency, $decimals) ?></span></div>
            <?php if ($tax_percent > 0): ?>
            <div class="inv-tot-row"><span>Tax (<?= qtyFmt($tax_percent) ?>%)</span><span><?= money($tax, $currency, $decimals) ?></span></div>
            <?php endif; ?>
            <div class="inv-tot-row inv-grand"><span>Total</span><span><?= money($total, $currency, $decimals) ?></span></div>
        </div>

        <?php if ($notes !== ''): ?>
        <div class="inv-notes"><div class="inv-label">Notes</div><?= nl2br(h($notes)) ?></div>
        <?php endif; ?>
    </div>

    <div class="lh-footer">
        <div class="footer-col"><span class="footer-icon">&#128205;</span>
            <div><span class="footer-label">Address</span>Office No. 505, 5th Floor, Kashif Center,<br>Sharah-e-Faisal, Karachi</div></div>
        <div class="footer-col"><span class="footer-icon">&#128222;</span>
            <div><span class="footer-label">Contact</span>0334-2123573</div></div>
        <div class="footer-col"><span class="footer-icon">&#127760;</span>
            <div><span class="footer-label">Website</span>www.erikamedia.com</div></div>
    </div>
</div>
</div>

</body>
</html>
