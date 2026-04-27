<?php
// Triggered monthly by a scheduled remote agent. Generates last month's
// CSVs (main Finances + Petty Cash) and emails them to the admin as
// attachments.
//
// Auth: ?token=SECRET (token lives in /dashboard/.cron-token on the server,
//       generated once and never committed to git).
// Optional: ?month=YYYY-MM to email a specific month instead of last month.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail_helper.php';

define('CRON_TOKEN_FILE', __DIR__ . '/.cron-token');

header('Content-Type: text/plain; charset=UTF-8');

$expected = file_exists(CRON_TOKEN_FILE) ? trim(file_get_contents(CRON_TOKEN_FILE)) : '';
$provided = (string) ($_GET['token'] ?? '');

if ($expected === '' || strlen($expected) < 16 || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$month = (string) ($_GET['month'] ?? '');
if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m', strtotime('first day of last month'));
}
$monthName = date('F Y', strtotime($month . '-01'));

/**
 * Load entries from a ledger file, filtered to the target month.
 */
function loadMonthEntries(string $file, string $month): array {
    $raw = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    return array_values(array_filter($raw,
        fn($e) => strpos((string) ($e['date'] ?? ''), $month) === 0));
}

/**
 * Build one CSV string and return [csv, in, out, net].
 */
function buildLedgerCsv(array $entries): array {
    $in = 0.0; $out = 0.0;
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, ['Date', 'Type', 'Description', 'Amount (Rs)']);
    foreach ($entries as $e) {
        $type = ($e['type'] ?? '') === 'in' ? 'Money In' : 'Money Out';
        if (($e['type'] ?? '') === 'in')  $in  += (float) ($e['amount'] ?? 0);
        if (($e['type'] ?? '') === 'out') $out += (float) ($e['amount'] ?? 0);
        fputcsv($fp, [
            $e['date'] ?? '',
            $type,
            $e['description'] ?? '',
            $e['amount'] ?? 0,
        ]);
    }
    fputcsv($fp, []);
    fputcsv($fp, ['', '', 'Total Money In',  $in]);
    fputcsv($fp, ['', '', 'Total Money Out', $out]);
    fputcsv($fp, ['', '', 'Net Balance',     $in - $out]);
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);
    return [$csv, $in, $out, $in - $out];
}

$ledgers = [
    'finances'   => ['file' => __DIR__ . '/finances.json',   'label' => 'Main Finances', 'slug' => 'finances'],
    'petty-cash' => ['file' => __DIR__ . '/petty-cash.json', 'label' => 'Petty Cash',    'slug' => 'petty-cash'],
];

$attachments = [];
$summaryLines = [];
$totalEntries = 0;

foreach ($ledgers as $key => $cfg) {
    $entries = loadMonthEntries($cfg['file'], $month);
    [$csv, $in, $out, $net] = buildLedgerCsv($entries);
    $attachments[] = [
        'filename' => "erika-{$cfg['slug']}-{$month}.csv",
        'csv'      => $csv,
    ];
    $totalEntries += count($entries);
    $summaryLines[] = sprintf(
        "%s\n  Money In:    Rs. %s\n  Money Out:   Rs. %s\n  Net Balance: Rs. %s\n  Entries:     %d",
        $cfg['label'],
        number_format($in,  0),
        number_format($out, 0),
        number_format($net, 0),
        count($entries)
    );
}

$admin = loadAdmin();
$to    = $admin['username'] ?? '';
if (!$to) {
    http_response_code(500);
    echo "No admin email configured\n";
    exit;
}

$bodyText  = "Monthly summary — {$monthName}\n";
$bodyText .= str_repeat('─', 42) . "\n\n";
$bodyText .= implode("\n\n", $summaryLines) . "\n\n";
$bodyText .= "Both CSVs are attached. Forward to your accountant for the books.\n\n";
$bodyText .= "(Sent automatically by the Erika Media HR Dashboard.)\n";

$boundary = 'BOUND_' . bin2hex(random_bytes(12));

$headers  = "From: " . MAIL_FROM . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: " . MAIL_X_MAILER . "\r\n";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $bodyText . "\r\n";
foreach ($attachments as $att) {
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/csv; name=\"{$att['filename']}\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$att['filename']}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($att['csv']));
}
$body .= "--{$boundary}--";

$sent = @mail($to, "Erika Media — Finances + Petty Cash for {$monthName}", $body, $headers);

if (!$sent) {
    http_response_code(500);
    echo "Mail send failed\n";
    exit;
}

echo "OK month={$month} entries_total={$totalEntries}\n";
