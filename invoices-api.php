<?php
// Invoices raised, read back out of invoices.json.
//
// Every invoice has been recorded since the feature was built, but nothing
// ever showed them: Document History reads history.json, which holds payslips
// and offer letters only. So there was a complete billing record on the server
// that could not be looked at, reprinted or corrected from the dashboard.
//
// Records written before invoices carried an id are given one derived from the
// invoice number, the same way generate-invoice.php derives it, so older rows
// address identically to new ones without rewriting the file.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';   // jsonResponse / jsonError / readJsonBody
requireLogin();

define('INVOICE_FILE', __DIR__ . '/invoices.json');

function invoicesLoad(): array {
    if (!file_exists(INVOICE_FILE)) return [];
    $data = json_decode((string) file_get_contents(INVOICE_FILE), true);
    return is_array($data) ? $data : [];
}

function invoicesSave(array $rows): void {
    file_put_contents(INVOICE_FILE, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function invoiceId(array $row): string {
    $id = trim((string) ($row['id'] ?? ''));
    if ($id !== '') return $id;
    return 'inv_' . substr(sha1(trim((string) ($row['invoice_no'] ?? ''))), 0, 12);
}

/** Newest first, with the derived fields the list needs. */
function invoicesList(): array {
    $rows = invoicesLoad();
    $out  = [];
    foreach ($rows as $r) {
        $r['id']          = invoiceId($r);
        $r['items']       = is_array($r['items'] ?? null) ? $r['items'] : [];
        $r['item_count']  = count($r['items']);
        $r['total']       = round((float) ($r['total'] ?? 0), 2);
        $r['subtotal']    = round((float) ($r['subtotal'] ?? 0), 2);
        $r['tax']         = round((float) ($r['tax'] ?? 0), 2);
        $r['amended']     = ($r['first_issued_at'] ?? '') !== ''
                         && ($r['first_issued_at'] ?? '') !== ($r['generated_at'] ?? '');
        $out[] = $r;
    }
    usort($out, function ($a, $b) {
        return strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? ''));
    });
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = invoicesList();

    $totals = [];
    foreach ($rows as $r) {
        $cur = (string) ($r['currency'] ?? '');
        if (!isset($totals[$cur])) $totals[$cur] = ['count' => 0, 'total' => 0.0];
        $totals[$cur]['count']++;
        $totals[$cur]['total'] = round($totals[$cur]['total'] + $r['total'], 2);
    }

    // Two records sharing a number means the file predates replace-on-reissue.
    // Worth surfacing rather than leaving as a quiet contradiction in the
    // billing record.
    $seen = []; $duplicates = [];
    foreach ($rows as $r) {
        $no = trim((string) ($r['invoice_no'] ?? ''));
        if ($no === '') continue;
        if (isset($seen[$no])) $duplicates[$no] = true; else $seen[$no] = true;
    }

    jsonResponse([
        'invoices'   => $rows,
        'count'      => count($rows),
        'totals'     => $totals,
        'duplicates' => array_keys($duplicates),
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
        if ($removed === null && invoiceId($r) === $id) { $removed = $r; continue; }
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
