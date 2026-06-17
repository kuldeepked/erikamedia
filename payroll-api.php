<?php
// Payroll run — per-employee payslip preview for a month. The math lives in
// payroll-lib.php (shared with payroll-post-api.php so they can never drift).
// Read-only. Actual generation still goes through generate-payslip.php (which
// writes history, marks activity paid, and records advance recovery).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll-lib.php';
requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

jsonResponse(array_merge(['month' => $month, 'currency' => 'PKR'], payrollRows($pdo, $month)));
