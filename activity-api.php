<?php
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');
$file = __DIR__ . '/activity.json';

const VALID_TYPES = ['interview', 'placement', 'penalty', 'bonus'];

function loadActivity(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveActivity(string $file, array $activity): void {
    // Sort newest first by date, then by created_at
    usort($activity, function ($a, $b) {
        $cmp = strcmp($b['date'] ?? '', $a['date'] ?? '');
        if ($cmp !== 0) return $cmp;
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
    file_put_contents($file, json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function newId(): string {
    return 'act_' . bin2hex(random_bytes(6));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $activity = loadActivity($file);

    $employee    = trim($_GET['employee']    ?? '');
    $month       = trim($_GET['month']       ?? '');  // YYYY-MM
    $unpaidOnly  = ($_GET['unpaid_only']     ?? '') === '1';

    if ($employee !== '') {
        $activity = array_values(array_filter($activity,
            fn($e) => strcasecmp($e['employee'] ?? '', $employee) === 0));
    }
    if ($month !== '') {
        $activity = array_values(array_filter($activity,
            fn($e) => strpos($e['date'] ?? '', $month) === 0));
    }
    if ($unpaidOnly) {
        $activity = array_values(array_filter($activity,
            fn($e) => empty($e['paid_in'])));
    }

    echo json_encode($activity);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $action   = trim($input['action'] ?? '');
    $activity = loadActivity($file);

    if ($action === 'add') {
        $type      = trim($input['type'] ?? '');
        $date      = trim($input['date'] ?? '');
        $employee  = trim($input['employee'] ?? '');
        $candidate = trim($input['candidate'] ?? '');
        $client    = trim($input['client']    ?? '');
        $reason    = trim($input['reason']    ?? '');
        $amount    = (int) ($input['amount'] ?? 0);

        if (!in_array($type, VALID_TYPES, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid event type.']);
            exit;
        }
        if (!$employee || !$date) {
            http_response_code(400);
            echo json_encode(['error' => 'Employee and date are required.']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Date must be in YYYY-MM-DD format.']);
            exit;
        }

        // Interviews are always Rs. 1300
        if ($type === 'interview') {
            $amount = INTERVIEW_RATE;
        }
        if (in_array($type, ['placement', 'penalty', 'bonus'], true) && $amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Amount must be greater than zero.']);
            exit;
        }

        $entry = [
            'id'         => newId(),
            'type'       => $type,
            'date'       => $date,
            'employee'   => $employee,
            'candidate'  => $candidate,
            'client'     => $client,
            'reason'     => $reason,
            'amount'     => $amount,
            'paid_in'    => null,
            'created_at' => date('c'),
        ];
        $activity[] = $entry;
        saveActivity($file, $activity);
        echo json_encode(['success' => true, 'entry' => $entry]);
        exit;
    }

    if ($action === 'delete') {
        $id = trim($input['id'] ?? '');
        $before = count($activity);
        $activity = array_values(array_filter($activity, fn($e) => ($e['id'] ?? '') !== $id));
        if (count($activity) === $before) {
            http_response_code(404);
            echo json_encode(['error' => 'Entry not found.']);
            exit;
        }
        saveActivity($file, $activity);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_paid') {
        $ids   = $input['ids']   ?? [];
        $month = trim($input['month'] ?? '');  // YYYY-MM
        if (!is_array($ids) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            http_response_code(400);
            echo json_encode(['error' => 'ids array and month (YYYY-MM) required.']);
            exit;
        }
        $idSet = array_flip($ids);
        $count = 0;
        foreach ($activity as &$e) {
            if (isset($idSet[$e['id'] ?? ''])) {
                $e['paid_in'] = $month;
                $count++;
            }
        }
        unset($e);
        saveActivity($file, $activity);
        echo json_encode(['success' => true, 'updated' => $count]);
        exit;
    }

    if ($action === 'unmark_paid') {
        // Used when editing/regenerating a payslip — release entries so they can be recounted
        $ids = $input['ids'] ?? [];
        if (!is_array($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'ids array required.']);
            exit;
        }
        $idSet = array_flip($ids);
        foreach ($activity as &$e) {
            if (isset($idSet[$e['id'] ?? ''])) {
                $e['paid_in'] = null;
            }
        }
        unset($e);
        saveActivity($file, $activity);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
