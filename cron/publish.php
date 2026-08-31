<?php
/**
 * The publishing worker. Run it every minute.
 *
 * Hostinger hPanel -> Advanced -> Cron Jobs -> "Every minute":
 *   /usr/bin/php /home/uXXXXXXX/domains/yourdomain.com/public_html/cron/publish.php
 *
 * Or, if you would rather trigger it over the web:
 *   curl -s "https://yourdomain.com/cron/publish.php?key=YOUR_CRON_SECRET"
 */

require_once __DIR__ . '/../app/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    // Over HTTP the secret is mandatory - otherwise anyone could trigger a run.
    if (!hash_equals(CRON_SECRET, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Overlapping runs would double-post, so take a lock first.
$lockFile = __DIR__ . '/../storage/publish.lock';
$lock     = fopen($lockFile, 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another publisher run is still going. Skipping.\n";
    exit;
}

$started = microtime(true);
$report  = publish_due_posts(50);

printf(
    "[%s UTC] processed=%d published=%d failed=%d in %.2fs\n",
    gmdate('Y-m-d H:i:s'),
    $report['processed'],
    $report['published'],
    $report['failed'],
    microtime(true) - $started
);

foreach ($report['details'] as $d) {
    printf("  post #%d: %s %s\n", $d['post'], $d['ok'] ? 'OK' : 'FAILED', $d['message'] ?? '');
}

// Keep a rolling log so you can see whether cron actually fired.
@file_put_contents(
    __DIR__ . '/../storage/publish.log',
    sprintf("[%s] processed=%d published=%d failed=%d\n",
        gmdate('c'), $report['processed'], $report['published'], $report['failed']),
    FILE_APPEND
);

flock($lock, LOCK_UN);
fclose($lock);
