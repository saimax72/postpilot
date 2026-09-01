<?php
/**
 * PostPilot - bootstrap. Every entry point includes this file first.
 */

require_once __DIR__ . '/config.php';

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set(APP_TIMEZONE);

/**
 * A missing or misconfigured database is by far the most likely first-run
 * failure, so turn it into a page that says what to do instead of a stack trace.
 */
set_exception_handler(function (Throwable $e) {
    error_log('[' . APP_NAME . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Anything that is not a connection problem gets the plain 500 treatment.
    if (!($e instanceof RuntimeException)) {
        http_response_code(500);
        echo APP_DEBUG
            ? '<pre>' . htmlspecialchars((string)$e, ENT_QUOTES) . '</pre>'
            : 'Something went wrong. Please try again.';
        exit;
    }

    http_response_code(503);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup needed</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><div class="wrap" style="max-width:620px;padding-top:80px">
<h1>Setup needed</h1>
<div class="alert alert-error">$msg</div>
<p class="muted">Open <a href="/install.php">/install.php</a> to check your configuration, or edit
<span class="mono">app/config.php</span> with your Hostinger database details.</p>
</div></body></html>
HTML;
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/platforms.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/hashtags.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/publisher.php';
require_once __DIR__ . '/instagram.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('postpilot');
    session_start();
}
