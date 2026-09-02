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

/**
 * A crop box from the client, or null if it is not usable.
 * Anything malformed falls back to a centred crop rather than producing a
 * distorted image from junk numbers.
 */
function valid_crop($crop): ?array
{
    if (!is_array($crop)) {
        return null;
    }
    $box = [];
    foreach (['fx', 'fy', 'fw', 'fh'] as $k) {
        if (!isset($crop[$k]) || !is_numeric($crop[$k])) {
            return null;
        }
        $box[$k] = min(max((float)$crop[$k], 0), 1);
    }
    if ($box['fw'] <= 0.01 || $box['fh'] <= 0.01) {
        return null;
    }
    return $box;
}

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

    $created   = [];
    $failed    = [];
    $stoppedBy = null;
    $skipped   = 0;

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
            // A crop set by hand in the framing editor wins; otherwise centre it.
            $box  = valid_crop($item['crop'] ?? null)
                 ?: ($size ? cover_box($size['w'], $size['h'], $ratio)
                           : ['fx' => 0, 'fy' => 0, 'fw' => 1, 'fh' => 1]);

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
            continue;
        }

        // Once the plan limit is what is stopping us, every remaining file will
        // fail for the same reason. Stop and report it once rather than
        // producing one identical error per image.
        if ($blocked = post_block_code($uid, $user)) {
            $stoppedBy = $blocked;
            $skipped   = count($items) - count($created) - count($failed);
            break;
        }

        $failed[] = ['name' => $label, 'error' => $result];
    }

    if ($tplId && $created) {
        template_used($tplId, $uid);
    }

    log_activity($uid, 'bulk_create', count($created) . ' posts created, ' . count($failed) . ' failed');

    json_out([
        'ok'        => true,
        'created'   => count($created),
        'failed'    => $failed,
        'blocked'   => $stoppedBy,
        'skipped'   => $skipped,
        'remaining' => posts_remaining_today($uid, $user),
        'limit'     => plan_limit('posts_per_day', $user),
        'posts'     => $created,
    ]);
}

/* --------------------------------------------------------- publish_one ---- */

/*
 * Publish a single item from a batch, straight away.
 *
 * One request per item rather than one for the batch: publishing talks to the
 * network and can take tens of seconds each, so a batch of twenty in a single
 * request would exceed any sane execution limit and give no progress until it
 * either finished or died.
 */
if ($action === 'publish_one') {
    @set_time_limit(120);

    $body     = json_body();
    $accounts = array_map('intval', (array)($body['accounts'] ?? []));
    $ratio    = (string)($body['ratio'] ?? 'square');
    $path     = trim((string)($body['path'] ?? ''));
    $caption  = (string)($body['caption'] ?? '');
    $label    = (string)($body['name'] ?? 'image');

    if (!media_ratio($ratio)) {
        $ratio = 'square';
    }
    if (!$accounts) {
        json_fail('Choose at least one channel.');
    }
    if ($path === '' || strpos($path, $uid . '/') !== 0) {
        json_fail('That file does not belong to your account.');
    }

    $mediaPath = $path;
    $cropBox   = null;

    if (!is_video($path)) {
        $size = image_size($path);
        $box  = valid_crop($body['crop'] ?? null)
             ?: ($size ? cover_box($size['w'], $size['h'], $ratio)
                       : ['fx' => 0, 'fy' => 0, 'fw' => 1, 'fh' => 1]);

        [$cropOk, $cropRes] = crop_media($path, $uid, $ratio, $box);
        if (!$cropOk) {
            json_out(['ok' => false, 'name' => $label, 'error' => $cropRes]);
        }
        $mediaPath = $cropRes;
        $cropBox   = implode(',', array_map(fn($v) => round($v, 6), array_values($box)));
    }

    [$ok, $result] = post_save(
        $uid, null, $caption, $accounts, gmdate('Y-m-d H:i:s'), 'scheduled',
        $mediaPath, trim((string)($body['link'] ?? '')) ?: null,
        $path, $ratio, $cropBox,
        mb_substr((string)($body['alt'] ?? ''), 0, 400) ?: null,
        mb_substr((string)($body['first_comment'] ?? ''), 0, 600) ?: null
    );

    if (!$ok) {
        // Tell the client *why* in a form it can act on. Publishing a batch
        // runs one request per image, so without this the same sentence comes
        // back twenty times and gets concatenated into a wall of red text.
        json_out([
            'ok'        => false,
            'name'      => $label,
            'error'     => $result,
            'blocked'   => post_block_code($uid, $user),
            'remaining' => posts_remaining_today($uid, $user),
            'limit'     => plan_limit('posts_per_day', $user),
        ]);
    }

    // Claim it the way the worker does, so a cron run cannot double-post.
    $claimed = db_run(
        "UPDATE posts SET status = 'publishing', attempts = attempts + 1
         WHERE id = ? AND status = 'scheduled'",
        [$result]
    )->rowCount();

    if (!$claimed) {
        json_out(['ok' => true, 'id' => $result, 'published' => false, 'name' => $label,
                  'error' => 'The scheduler picked this one up first — check the queue.']);
    }

    $res = publish_post((int)$result);

    json_out([
        'ok'        => true,
        'id'        => $result,
        'name'      => $label,
        'published' => (bool)$res['ok'],
        'error'     => $res['ok'] ? null : $res['message'],
    ]);
}

json_fail('Unknown action.', 404);
