<?php
// Shared invoice storage. Used by generate-invoice.php (writing) and
// invoices-api.php (reading), so the two agree on how a record is identified.
//
// Identity is the hard part here. An invoice number looks like it should be
// unique and is not: this file already holds eight numbers used more than
// once, including EM-2026-005 issued to two different clients. Some of those
// pairs are the same invoice printed twice; others are genuinely different
// invoices that were accidentally given a number already in use.
//
// So the number cannot identify a record. The id is derived from enough of the
// content to tell any two rows apart, and two rows identical in all of it are
// indistinguishable anyway — acting on either is the same act.

define('INVOICE_FILE', __DIR__ . '/invoices.json');

function invoicesLoad(): array {
    if (!file_exists(INVOICE_FILE)) return [];
    $data = json_decode((string) file_get_contents(INVOICE_FILE), true);
    return is_array($data) ? $data : [];
}

function invoicesSave(array $rows): void {
    file_put_contents(INVOICE_FILE, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Always derived, never read from the record.
 *
 * Earlier versions stored an id built from the invoice number alone, so every
 * duplicated number produced one shared id and editing any of them opened the
 * first. Deriving it here means those rows heal themselves on the next read
 * instead of needing the file rewritten.
 */
function invoiceRecordId(array $row): string {
    $seed = implode('|', [
        trim((string) ($row['invoice_no']   ?? '')),
        trim((string) ($row['generated_at'] ?? '')),
        trim((string) ($row['client_name']  ?? '')),
        (string) round((float) ($row['total'] ?? 0), 2),
        trim((string) ($row['issue_date']   ?? '')),
    ]);
    return 'inv_' . substr(sha1($seed), 0, 16);
}

/** Newest first, each row carrying a stable id and the fields the list needs. */
function invoicesList(): array {
    $out = [];
    foreach (invoicesLoad() as $r) {
        $r['id']         = invoiceRecordId($r);
        $r['items']      = is_array($r['items'] ?? null) ? $r['items'] : [];
        $r['item_count'] = count($r['items']);
        $r['total']      = round((float) ($r['total'] ?? 0), 2);
        $r['subtotal']   = round((float) ($r['subtotal'] ?? 0), 2);
        $r['tax']        = round((float) ($r['tax'] ?? 0), 2);
        $r['amended']    = ($r['first_issued_at'] ?? '') !== ''
                        && ($r['first_issued_at'] ?? '') !== ($r['generated_at'] ?? '');
        $out[] = $r;
    }
    usort($out, function ($a, $b) {
        return strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? ''));
    });
    return $out;
}

/** Invoice numbers used by more than one record, with how many rows each has. */
function invoiceDuplicateNumbers(array $rows): array {
    $counts = [];
    foreach ($rows as $r) {
        $no = trim((string) ($r['invoice_no'] ?? ''));
        if ($no === '') continue;
        $counts[$no] = ($counts[$no] ?? 0) + 1;
    }
    return array_filter($counts, fn($n) => $n > 1);
}
