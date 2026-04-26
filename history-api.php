<?php
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');
$file = __DIR__ . '/history.json';

function loadHistory(string $file): array {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadHistory($file));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'delete') {
        $id      = $input['id'] ?? '';
        $history = array_values(array_filter(loadHistory($file), fn($r) => $r['id'] !== $id));
        file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
