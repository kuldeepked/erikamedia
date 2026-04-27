<?php
// Triggered monthly by a scheduled remote agent. Generates last month's
// finances CSV and emails it to the admin as an attachment.
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

$file    = __DIR__ . '/finances.json';
$entries = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
$entries = array_values(array_filter($entries,
    fn($e) => strpos((string) ($e['date'] ?? ''), $month) === 0));

$inTotal = 0.0; $outTotal = 0.0;
foreach ($entries as $e) {
    if (($e['type'] ?? '') === 'in')  $inTotal  += (float) ($e['amount'] ?? 0);
    if (($e['type'] ?? '') === 'out') $outTotal += (float) ($e['amount'] ?? 0);
}
$net = $inTotal - $outTotal;

// Build CSV in memory
$fp = fopen('php://temp', 'r+');
fputcsv($fp, ['Date', 'Type', 'Description', 'Amount (Rs)']);
foreach ($entries as $e) {
    fputcsv($fp, [
        $e['date'] ?? '',
        ($e['type'] ?? '') === 'in' ? 'Money In' : 'Money Out',
        $e['description'] ?? '',
        $e['amount'] ?? 0,
    ]);
}
fputcsv($fp, []);
fputcsv($fp, ['', '', 'Total Money In',  $inTotal]);
fputcsv($fp, ['', '', 'Total Money Out', $outTotal]);
fputcsv($fp, ['', '', 'Net Balance',     $net]);
rewind($fp);
$csv = stream_get_contents($fp);
fclose($fp);

$admin = loadAdmin();
$to    = $admin['username'] ?? '';
if (!$to) {
    http_response_code(500);
    echo "No admin email configured\n";
    exit;
}

$fmt = fn($n) => 'Rs. ' . number_format((float) $n, 0);
$bodyText  = "Monthly finances summary — {$monthName}\n";
$bodyText .= str_repeat('─', 42) . "\n\n";
$bodyText .= "  Money In:     " . $fmt($inTotal)  . "\n";
$bodyText .= "  Money Out:    " . $fmt($outTotal) . "\n";
$bodyText .= "  Net Balance:  " . $fmt($net)      . "\n";
$bodyText .= "  Entries:      " . count($entries) . "\n\n";
$bodyText .= "Full CSV attached. Forward it to your accountant for the books.\n\n";
$bodyText .= "(Sent automatically by the Erika Media HR Dashboard.)\n";

$boundary = 'BOUND_' . bin2hex(random_bytes(12));
$filename = "erika-finances-{$month}.csv";

$headers  = "From: " . MAIL_FROM . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: " . MAIL_X_MAILER . "\r\n";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $bodyText . "\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/csv; name=\"{$filename}\"\r\n";
$body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= chunk_split(base64_encode($csv));
$body .= "--{$boundary}--";

$sent = @mail($to, "Erika Media — Finances for {$monthName}", $body, $headers);

if (!$sent) {
    http_response_code(500);
    echo "Mail send failed\n";
    exit;
}

echo "OK month={$month} entries=" . count($entries)
   . " in={$inTotal} out={$outTotal} net={$net}\n";
