<?php
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');
$file = __DIR__ . '/employees.json';

const NUMERIC_FIELDS = ['basic_salary', 'allowance', 'punctuality_bonus',
                        'provident_fund', 'eobi', 'professional_tax'];

function loadEmployees(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return [];
    // Ensure all employees have all expected fields (defaults 0)
    foreach ($data as &$e) {
        foreach (NUMERIC_FIELDS as $f) {
            if (!isset($e[$f])) $e[$f] = 0;
        }
    }
    return $data;
}

function saveEmployees(string $file, array $employees): void {
    usort($employees, fn($a, $b) => strcmp($a['name'], $b['name']));
    file_put_contents($file, json_encode($employees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function buildEmployee(array $input): array {
    $emp = [
        'name'        => trim($input['name']        ?? ''),
        'designation' => trim($input['designation'] ?? ''),
    ];
    foreach (NUMERIC_FIELDS as $f) {
        $emp[$f] = max(0, (int) ($input[$f] ?? 0));
    }
    return $emp;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(loadEmployees($file));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $action    = trim($input['action'] ?? '');
    $employees = loadEmployees($file);

    if ($action === 'add') {
        $new = buildEmployee($input);
        if (!$new['name'] || !$new['designation']) {
            http_response_code(400);
            echo json_encode(['error' => 'Both name and designation are required.']);
            exit;
        }
        foreach ($employees as $e) {
            if (strtolower($e['name']) === strtolower($new['name'])) {
                http_response_code(409);
                echo json_encode(['error' => "{$new['name']} is already in the list."]);
                exit;
            }
        }
        $employees[] = $new;
        saveEmployees($file, $employees);
        echo json_encode(['success' => true, 'employees' => loadEmployees($file)]);
        exit;
    }

    if ($action === 'edit') {
        $original = trim($input['original_name'] ?? '');
        $updated  = buildEmployee($input);
        if (!$original || !$updated['name'] || !$updated['designation']) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields.']);
            exit;
        }
        $found = false;
        foreach ($employees as &$e) {
            if ($e['name'] === $original) {
                // If renaming, check the new name doesn't collide with another existing employee
                if (strtolower($updated['name']) !== strtolower($original)) {
                    foreach ($employees as $other) {
                        if ($other['name'] !== $original
                            && strtolower($other['name']) === strtolower($updated['name'])) {
                            http_response_code(409);
                            echo json_encode(['error' => "{$updated['name']} is already in the list."]);
                            exit;
                        }
                    }
                }
                $e = $updated;
                $found = true;
                break;
            }
        }
        unset($e);
        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => "Employee '{$original}' not found."]);
            exit;
        }
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
