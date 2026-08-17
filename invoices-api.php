<?php
// Invoices raised, read back out of invoices.json.
//
// Every invoice has been recorded since the feature was built, but nothing
// ever showed them: Document History reads history.json, which holds payslips
// and offer letters only. So there was a complete billing record on the server
// that could not be looked at, reprinted or corrected from the dashboard.
//
// Row identity is derived in invoice-lib.php, not taken from the record — see
// the note there about why an invoice number cannot be trusted to be unique.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';   // jsonResponse / jsonError / readJsonBody
require_once __DIR__ . '/invoice-lib.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = invoicesList();

    $totals = [];
    foreach ($rows as $r) {
        $cur = (string) ($r['currency'] ?? '');
        if (!isset($totals[$cur])) $totals[$cur] = ['count' => 0, 'total' => 0.0];
        $totals[$cur]['count']++;
        $totals[$cur]['total'] = round($totals[$cur]['total'] + $r['total'], 2);
    }

    // A number used more than once may be the same invoice printed twice or two
    // different invoices that collided — only the person who raised them knows
    // which. Reported rather than resolved.
    $duplicates = invoiceDuplicateNumbers($rows);

    jsonResponse([
        'invoices'   => $rows,
        'count'      => count($rows),
        'totals'     => $totals,
        'duplicates' => array_keys($duplicates),
        'duplicate_counts' => $duplicates,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$input  = readJsonBody();
$action = trim((string) ($input['action'] ?? ''));

if ($action === 'delete') {
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') jsonError('id is required.');

    $rows = invoicesLoad();
    $kept = [];
    $removed = null;
    foreach ($rows as $r) {
        if ($removed === null && invoiceRecordId($r) === $id) { $removed = $r; continue; }
        $kept[] = $r;
    }
    if ($removed === null) jsonError('Invoice not found.', 404);

    invoicesSave($kept);
    jsonResponse([
        'success'    => true,
        'invoice_no' => (string) ($removed['invoice_no'] ?? ''),
        // Deleting the record does not unbill the client, and any payment
        // already banked stays in the ledger where it belongs.
        'note'       => 'Removed from the invoice list. Any payment recorded against it in Finances is untouched.',
    ]);
}

jsonError('Invalid action.');
