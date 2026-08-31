<?php
/**
 * Bulk upload endpoints.
 *
 *   POST /api/bulk.php?action=upload   one file per request (multipart)
 *   POST /api/bulk.php?action=create   JSON: the whole batch, already slotted
 *
 * Files are uploaded one at a time rather than as one enormous form, because
 * PHP's max_file_uploads defaults to 20 and would silently drop the rest.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$uid  = (int)$user['id'];
$tz   = user_tz();

define('BULK_MAX_ITEMS', 200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required.', 405);
}
csrf_check();

$action = $_GET['action'] ?? '';

/* -------------------------------------------------------------- upload ---- */

if ($action === 'upload') {
    if (empty($_FILES['media']['name'])) {
        json_fail('No file received.');
    }

    [$ok, $result] = handle_upload($_FILES['media'], $uid);
    if (!$ok) {
        json_fail($result);
    }

    $size = is_video($result) ? null : image_size($result);

    json_out([
        'ok'    => true,
        'path'  => $result,
        'url'   => media_url($result),
        'video' => is_video($result),
        'w'     => $size['w'] ?? null,
        'h'     => $size['h'] ?? null,
    ]);
}

/* -------------------------------------------------------------- create ---- */

if ($action === 'create') {
    $body = json_body();

    $accounts = array_map('intval', (array)($body['accounts'] ?? []));
    $ratio    = (string)($body['ratio'] ?? 'square');
    $status   = ($body['status'] ?? 'scheduled') === 'draft' ? 'draft' : 'scheduled';
    $tplId    = (int)($body['template_id'] ?? 0);
    $items    = (array)($body['items'] ?? []);

    if (!media_ratio($ratio)) {
        $ratio = 'square';
    }
    if (!$accounts) {
        json_fail('Choose at least one channel.');
    }
    if (!$items) {
        json_fail('Add some images first.');
    }
    if (count($items) > BULK_MAX_ITEMS) {
        json_fail('That is more than ' . BULK_MAX_ITEMS . ' posts in one batch. Split it up.');
    }

    $created = [];
    $failed  = [];

    foreach ($items as $i => $item) {
        $path    = trim((string)($item['path'] ?? ''));
        $caption = (string)($item['caption'] ?? '');
        $date    = (string)($item['date'] ?? '');
        $time    = (string)($item['time'] ?? '');
        $label   = $item['name'] ?? ('item ' . ($i + 1));

        // Never let a caller reach outside their own upload folder.
        if ($path === '' || strpos($path, $uid . '/') !== 0) {
            $failed[] = ['name' => $label, 'error' => 'That file does not belong to your account.'];
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            $failed[] = ['name' => $label, 'error' => 'Invalid slot.'];
            continue;
        }

        try {
            $utc = local_to_utc("$date $time", $tz);
        } catch (Exception $e) {
            $failed[] = ['name' => $label, 'error' => 'That date could not be read.'];
            continue;
        }

        if ($status === 'scheduled' && strtotime($utc . ' UTC') < time() - 60) {
            $failed[] = ['name' => $label, 'error' => 'Slot is in the past.'];
            continue;
        }

        // Frame each image centred on the chosen ratio. Individual posts can be
        // re-cropped afterwards by opening them in the composer.
        $mediaPath = $path;
        $cropBox   = null;

        if (!is_video($path)) {
            $size = image_size($path);
            $box  = $size ? cover_box($size['w'], $size['h'], $ratio)
                          : ['fx' => 0, 'fy' => 0, 'fw' => 1, 'fh' => 1];

            [$cropOk, $cropRes] = crop_media($path, $uid, $ratio, $box);
            if (!$cropOk) {
                $failed[] = ['name' => $label, 'error' => $cropRes];
                continue;
            }
            $mediaPath = $cropRes;
            $cropBox   = implode(',', array_map(fn($v) => round($v, 6), array_values($box)));
        }

        [$ok, $result] = post_save(
            $uid, null, $caption, $accounts, $utc, $status,
            $mediaPath, (string)($body['link'] ?? '') ?: null,
            $path, $ratio, $cropBox,
            mb_substr((string)($body['alt'] ?? ''), 0, 400) ?: null,
            mb_substr((string)($body['first_comment'] ?? ''), 0, 600) ?: null
        );

        if ($ok) {
            $created[] = ['id' => $result, 'date' => $date, 'time' => $time];
        } else {
            $failed[] = ['name' => $label, 'error' => $result];
        }
    }

    if ($tplId && $created) {
        template_used($tplId, $uid);
    }

    log_activity($uid, 'bulk_create', count($created) . ' posts created, ' . count($failed) . ' failed');

    json_out([
        'ok'      => true,
        'created' => count($created),
        'failed'  => $failed,
        'posts'   => $created,
    ]);
}

json_fail('Unknown action.', 404);
