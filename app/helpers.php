<?php

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        http_response_code(419);
        exit('Session expired. Refresh the page and try again.');
    }
}

/* ---------------- Flash messages ---------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ---------------- JSON responses ---------------- */

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $message], $code);
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ---------------- Time ---------------- */

/** Convert a "Y-m-d H:i" string in the user's timezone into a UTC datetime string. */
function local_to_utc(string $localDatetime, string $tz): string
{
    $dt = new DateTime($localDatetime, new DateTimeZone($tz));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** Convert a stored UTC datetime into the user's timezone. */
function utc_to_local(string $utcDatetime, string $tz, string $format = 'Y-m-d H:i'): string
{
    $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($tz));
    return $dt->format($format);
}

function timezone_list(): array
{
    return DateTimeZone::listIdentifiers();
}

function time_ago(string $utcDatetime): string
{
    $diff = time() - strtotime($utcDatetime . ' UTC');
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($utcDatetime . ' UTC'));
}

/* ---------------- Token encryption ---------------- */

function encrypt_secret(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $key = hash('sha256', APP_KEY, true);
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}

function decrypt_secret(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 17) {
        return null;
    }
    $key = hash('sha256', APP_KEY, true);
    $iv  = substr($raw, 0, 16);
    $ct  = substr($raw, 16);
    $out = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $out === false ? null : $out;
}

/* ---------------- Misc ---------------- */

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function log_activity(?int $userId, string $action, string $detail = ''): void
{
    db_run(
        'INSERT INTO activity_log (user_id, action, detail, ip) VALUES (?,?,?,?)',
        [$userId, $action, mb_substr($detail, 0, 400), client_ip()]
    );
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function str_limit(string $text, int $len = 80): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}
