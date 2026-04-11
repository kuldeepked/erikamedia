<?php
header('Content-Type: application/json');
$file = __DIR__ . '/employees.json';

function loadEmployees(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveEmployees(string $file, array $employees): void {
    usort($employees, fn($a, $b) => strcmp($a['name'], $b['name']));
    file_put_contents($file, json_encode($employees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// GET — return all employees
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadEmployees($file));
    exit;
}

// POST — add or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action     = trim($input['action'] ?? '');
    $employees  = loadEmployees($file);

    if ($action === 'add') {
        $name        = trim($input['name']        ?? '');
        $designation = trim($input['designation'] ?? '');

        if (!$name || !$designation) {
            http_response_code(400);
            echo json_encode(['error' => 'Both name and designation are required.']);
            exit;
        }
        foreach ($employees as $e) {
            if (strtolower($e['name']) === strtolower($name)) {
                http_response_code(409);
                echo json_encode(['error' => "{$name} is already in the list."]);
                exit;
            }
        }
        $employees[] = ['name' => $name, 'designation' => $designation];
        saveEmployees($file, $employees);
        echo json_encode(['success' => true, 'employees' => loadEmployees($file)]);
        exit;
    }

    if ($action === 'delete') {
        $name      = trim($input['name'] ?? '');
        $employees = array_values(array_filter($employees, fn($e) => $e['name'] !== $name));
        saveEmployees($file, $employees);
        echo json_encode(['success' => true, 'employees' => $employees]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
