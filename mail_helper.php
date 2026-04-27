<?php
// Thin wrapper around PHP mail() — used by forgot-password.
// Same From/headers pattern as website/contact.php (proven to deliver via IONOS).

define('MAIL_FROM',     'Erika Media HR <no-reply@erikamedia.com>');
define('MAIL_X_MAILER', 'Erika Media HR Dashboard');

function sendDashboardEmail(string $to, string $subject, string $bodyText): bool {
    $headers  = "From: " . MAIL_FROM . "\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: " . MAIL_X_MAILER . "\r\n";
    return @mail($to, $subject, $bodyText, $headers);
}
