<?php
/* ══════════════════════════════════════════
   ERIKA MEDIA — Contact Form Handler
   ══════════════════════════════════════════
   Receives AJAX POST from the website contact
   form and sends an email to the inbox below.
   Returns JSON: {"ok": true} or {"ok": false}
   ══════════════════════════════════════════ */

header('Content-Type: application/json');

// ── Only accept POST ──────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Destination inbox ────────────────────
// Change this to the email address you want to receive enquiries.
define('TO_EMAIL', 'info@erikamedia.com');
define('TO_NAME',  'Erika Media');

// ── Read & sanitise inputs ───────────────
function clean(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

$name    = clean($_POST['name']    ?? '');
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$phone   = clean($_POST['phone']   ?? '');
$service = clean($_POST['service'] ?? '');
$message = clean($_POST['message'] ?? '');

// ── Basic validation ─────────────────────
if ($name === '' || $email === '' || $message === '' || $service === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Required fields missing']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid email address']);
    exit;
}

// ── Compose email ────────────────────────
$subject = 'New Enquiry from ' . $name . ' — Erika Media Website';

$body  = "You have received a new enquiry via the Erika Media website.\n";
$body .= str_repeat('─', 50) . "\n\n";
$body .= "Name      : {$name}\n";
$body .= "Email     : {$email}\n";
$body .= "Phone     : " . ($phone !== '' ? $phone : 'Not provided') . "\n";
$body .= "Service   : {$service}\n\n";
$body .= "Message:\n{$message}\n\n";
$body .= str_repeat('─', 50) . "\n";
$body .= "Sent from erikamedia.com contact form\n";

// Headers — reply-to is set to the enquirer so you can reply directly
$headers  = "From: Erika Media Website <no-reply@erikamedia.com>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

// ── Send ─────────────────────────────────
$sent = mail(TO_EMAIL, $subject, $body, $headers);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail delivery failed']);
}
