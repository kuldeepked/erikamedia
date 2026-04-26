<?php
// Shared auth + CSRF helpers. require_once at the top of every protected page.

define('ADMIN_FILE',     __DIR__ . '/.admin.json');
define('INTERVIEW_RATE', 1300);
define('LOGIN_LOCK_AFTER',  5);
define('LOGIN_LOCK_MINUTES', 5);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('ERIKA_DASH');
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrfToken(): string {
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

function verifyCsrf(): void {
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token invalid. Please refresh the page.']);
        exit;
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_user']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '-api.php')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated.']);
            exit;
        }
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: login.php?next=' . $next);
        exit;
    }
}

function loadAdmin(): array {
    if (!file_exists(ADMIN_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(ADMIN_FILE), true);
    return is_array($data) ? $data : [];
}

function saveAdmin(array $admin): void {
    file_put_contents(ADMIN_FILE, json_encode($admin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
