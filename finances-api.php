<?php
require_once __DIR__ . '/auth.php';
requireLogin();

const FIN_VALID_TYPES = ['in', 'out'];
const FIN_TYPE_LABELS = ['in' => 'Money In', 'out' => 'Money Out'];
const FIN_LEDGERS     = ['finances' => 'finances.json', 'petty' => 'petty-cash.json'];

function ledgerFile(string $ledger): string {
    if (!isset(FIN_LEDGERS[$ledger])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid ledger.']);
        exit;
    }
    return __DIR__ . '/' . FIN_LEDGERS[$ledger];
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
       ? (json_decode(file_get_contents('php://input'), true) ?? [])
       : [];

$ledger = trim((string) ($_GET['ledger'] ?? $input['ledger'] ?? 'finances'));
$file   = ledgerFile($ledger);

function loadFinances(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveFinances(string $file, array $entries): void {
    usort($entries, function ($a, $b) {
        $cmp = strcmp($b['date'] ?? '', $a['date'] ?? '');
        if ($cmp !== 0) return $cmp;
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
    file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function summarizeFinances(array $entries): array {
    $in = 0.0; $out = 0.0;
    foreach ($entries as $e) {
        if (($e['type'] ?? '') === 'in')  $in  += (float) ($e['amount'] ?? 0);
        if (($e['type'] ?? '') === 'out') $out += (float) ($e['amount'] ?? 0);
    }
    return ['in' => $in, 'out' => $out, 'net' => $in - $out, 'count' => count($entries)];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $entries = loadFinances($file);
    $month   = trim($_GET['month'] ?? '');

    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $entries = array_values(array_filter($entries,
            fn($e) => strpos($e['date'] ?? '', $month) === 0));
    }

    if (($_GET['export'] ?? '') === 'csv') {
        $tag        = $month !== '' ? $month : 'all';
        $ledgerSlug = $ledger === 'petty' ? 'petty-cash' : 'finances';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="erika-' . $ledgerSlug . '-' . $tag . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Date', 'Type', 'Description', 'Amount (Rs)']);
        foreach ($entries as $e) {
            fputcsv($fp, [
                $e['date'] ?? '',
                FIN_TYPE_LABELS[$e['type'] ?? ''] ?? '',
                $e['description'] ?? '',
                $e['amount'] ?? 0,
            ]);
        }
        $sum = summarizeFinances($entries);
        fputcsv($fp, []);
        fputcsv($fp, ['', '', 'Total Money In',  $sum['in']]);
        fputcsv($fp, ['', '', 'Total Money Out', $sum['out']]);
        fputcsv($fp, ['', '', 'Net Balance',     $sum['net']]);
        fclose($fp);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['entries' => $entries, 'totals' => summarizeFinances($entries)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    header('Content-Type: application/json');

    $action  = trim($input['action'] ?? '');
    $entries = loadFinances($file);

    if ($action === 'add') {
        $type        = trim($input['type'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        $amount      = (float) ($input['amount'] ?? 0);

        if (!in_array($type, FIN_VALID_TYPES, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Type must be "in" or "out".']);
            exit;
        }
        if ($description === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Description is required.']);
            exit;
        }
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Amount must be greater than zero.']);
            exit;
        }
        if (mb_strlen($description) > 200) {
            $description = mb_substr($description, 0, 200);
        }

        $entry = [
            'id'          => 'fin_' . bin2hex(random_bytes(6)),
            'type'        => $type,
            'amount'      => round($amount, 2),
            'description' => $description,
            'date'        => date('Y-m-d'),
            'created_at'  => date('c'),
        ];
        $entries[] = $entry;
        saveFinances($file, $entries);
        echo json_encode(['success' => true, 'entry' => $entry, 'totals' => summarizeFinances($entries)]);
        exit;
    }

    if ($action === 'delete') {
        $id     = trim($input['id'] ?? '');
        $before = count($entries);
        $entries = array_values(array_filter($entries, fn($e) => ($e['id'] ?? '') !== $id));
        if (count($entries) === $before) {
            http_response_code(404);
            echo json_encode(['error' => 'Entry not found.']);
            exit;
        }
        saveFinances($file, $entries);
        echo json_encode(['success' => true, 'totals' => summarizeFinances($entries)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Method not allowed.']);
