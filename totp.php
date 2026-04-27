<?php
// Pure-PHP TOTP (RFC 6238) — no external dependencies.
// Used by setup-2fa.php / verify-2fa.php.

function totpGenerateSecret(int $bytes = 20): string {
    return totpBase32Encode(random_bytes($bytes));
}

function totpBase32Encode(string $bytes): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits); $i < $n; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totpBase32Decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $idx = strpos($alphabet, $b32[$i]);
        if ($idx === false) continue;
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function totpCode(string $secret, ?int $time = null, int $digits = 6, int $period = 30): string {
    $time = $time ?? time();
    $counter = (int) floor($time / $period);
    $binCounter = pack('J', $counter); // 64-bit big-endian
    $key = totpBase32Decode($secret);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset])     & 0x7f) << 24)
           | ((ord($hash[$offset + 1]) & 0xff) << 16)
           | ((ord($hash[$offset + 2]) & 0xff) << 8)
           |  (ord($hash[$offset + 3]) & 0xff);
    $code = $value % (10 ** $digits);
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

function totpVerify(string $secret, string $userCode, int $window = 1): bool {
    $userCode = preg_replace('/\D/', '', (string) $userCode);
    if (strlen($userCode) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCode($secret, $now + $i * 30), $userCode)) {
            return true;
        }
    }
    return false;
}

function totpUri(string $secret, string $accountEmail, string $issuer = 'Erika Media HR'): string {
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountEmail);
    $params = http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}
