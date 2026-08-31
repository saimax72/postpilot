<?php
/**
 * JSON endpoints used by the calendar and composer.
 *   POST /api/posts.php?action=save    (multipart form)
 *   POST /api/posts.php?action=move    {id, date, time}
 *   POST /api/posts.php?action=delete  {id}
 *   GET  /api/posts.php?action=range&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$uid  = (int)$user['id'];
$tz   = user_tz();

$action = $_GET['action'] ?? '';

if ($action === 'range') {
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d', strtotime('+31 days'));

    try {
        $fromUtc = local_to_utc($from . ' 00:00', $tz);
        $toUtc   = local_to_utc($to . ' 23:59', $tz);
    } catch (Exception $e) {
        json_fail('Invalid date range.');
    }

    $out = [];
    foreach (posts_in_range($uid, $fromUtc, $toUtc) as $p) {
        $out[] = [
            'id'        => (int)$p['id'],
            'content'   => $p['content'],
            'status'    => $p['status'],
            'date'      => utc_to_local($p['scheduled_at'], $tz, 'Y-m-d'),
            'time'      => utc_to_local($p['scheduled_at'], $tz, 'H:i'),
            'media'     => media_url($p['media_path']),
            'platforms' => array_values(array_unique(array_column($p['targets'], 'platform'))),
        ];
    }
    json_out(['ok' => true, 'posts' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST required.', 405);
}
csrf_check();

/* ---------------------------------------------------------------- save ---- */

if ($action === 'save') {
    $postId  = (int)($_POST['post_id'] ?? 0) ?: null;
    $content = (string)($_POST['content'] ?? '');
    $link    = trim((string)($_POST['link_url'] ?? '')) ?: null;
    $status  = ($_POST['status'] ?? 'scheduled') === 'draft' ? 'draft' : 'scheduled';
    $accounts = array_map('intval', (array)($_POST['accounts'] ?? []));

    if ($link !== null && !filter_var($link, FILTER_VALIDATE_URL)) {
        json_fail('That link does not look like a valid URL.');
    }

    $date = trim((string)($_POST['date'] ?? ''));
    $time = trim((string)($_POST['time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        json_fail('Pick a date and a time for this post.');
    }

    $now = !empty($_POST['publish_now']);

    try {
        // "Post now" ignores whatever is in the date and time fields.
        $scheduledUtc = $now ? gmdate('Y-m-d H:i:s') : local_to_utc("$date $time", $tz);
    } catch (Exception $e) {
        json_fail('That date and time could not be read.');
    }

    // Scheduling into the past only makes sense for drafts.
    if (!$now && $status === 'scheduled' && strtotime($scheduledUtc . ' UTC') < time() - 60) {
        json_fail('That time has already passed. Pick a future slot, or save it as a draft.');
    }

    // Media. A fresh upload replaces the original; otherwise we keep the one the
    // post already had, so re-cropping never needs a re-upload.
    $original = trim((string)($_POST['media_original'] ?? '')) ?: null;
    if (!empty($_FILES['media']['name'])) {
        [$ok, $result] = handle_upload($_FILES['media'], $uid);
        if (!$ok) {
            json_fail($result);
        }
        if ($result) {
            $original = $result;
        }
    }
    // Guard against a caller passing someone else's file path.
    if ($original && strpos($original, $uid . '/') !== 0) {
        $original = null;
    }

    $ratioKey = (string)($_POST['media_ratio'] ?? 'square');
    if (!media_ratio($ratioKey)) {
        $ratioKey = 'square';
    }

    $box = [
        'fx' => (float)($_POST['crop_fx'] ?? 0),
        'fy' => (float)($_POST['crop_fy'] ?? 0),
        'fw' => (float)($_POST['crop_fw'] ?? 1),
        'fh' => (float)($_POST['crop_fh'] ?? 1),
    ];

    // Bake the framing into a derivative - the networks fetch a URL and crop nothing.
    $mediaPath = $original;
    $cropBox   = null;

    if ($original && !is_video($original)) {
        [$ok, $result] = crop_media($original, $uid, $ratioKey, $box);
        if (!$ok) {
            json_fail($result);
        }
        $mediaPath = $result;
        $cropBox   = implode(',', array_map(fn($v) => round($v, 6), array_values($box)));
    }

    $altText = mb_substr(trim((string)($_POST['alt_text'] ?? '')), 0, 400) ?: null;
    $firstComment = mb_substr(trim((string)($_POST['first_comment'] ?? '')), 0, 600) ?: null;

    [$ok, $result] = post_save(
        $uid, $postId, $content, $accounts, $scheduledUtc, $status,
        $mediaPath, $link, $original, $original ? $ratioKey : null, $cropBox,
        $altText, $firstComment
    );

    if (!$ok) {
        json_fail($result);
    }

    // Track which templates actually get used, so the list can be ordered by it later.
    if ($tplId = (int)($_POST['template_id'] ?? 0)) {
        template_used($tplId, $uid);
    }

    /*
     * Post now: publish inline instead of waiting for the worker. Claimed with
     * the same conditional update the worker uses, so an overlapping cron run
     * cannot send it twice.
     */
    if ($now) {
        @set_time_limit(120);   // container polling can take up to ~45s

        $claimed = db_run(
            "UPDATE posts SET status = 'publishing', attempts = attempts + 1
             WHERE id = ? AND status = 'scheduled'",
            [$result]
        )->rowCount();

        if (!$claimed) {
            json_out(['ok' => true, 'id' => $result, 'published' => false,
                      'message' => 'The worker picked this post up first — check the queue for the outcome.']);
        }

        $res = publish_post((int)$result);

        json_out([
            'ok'        => true,
            'id'        => $result,
            'published' => (bool)$res['ok'],
            'message'   => $res['ok'] ? 'Published.' : $res['message'],
        ]);
    }

    json_out(['ok' => true, 'id' => $result]);
}

/* ------------------------------------------------------ save_template ---- */

if ($action === 'save_template') {
    [$ok, $result] = template_save($uid, (int)($_POST['id'] ?? 0) ?: null, (string)($_POST['name'] ?? ''), [
        'content'       => $_POST['content'] ?? '',
        'link_url'      => $_POST['link_url'] ?? '',
        'media_ratio'   => $_POST['media_ratio'] ?? 'square',
        'alt_text'      => $_POST['alt_text'] ?? '',
        'first_comment' => $_POST['first_comment'] ?? '',
        'account_ids'   => (array)($_POST['accounts'] ?? []),
    ]);

    if (!$ok) {
        json_fail($result);
    }
    json_out(['ok' => true, 'id' => $result]);
}

/* ---------------------------------------------------------------- move ---- */

if ($action === 'move') {
    $body = json_body();
    $id   = (int)($body['id'] ?? 0);
    $date = (string)($body['date'] ?? '');
    $time = (string)($body['time'] ?? '09:00');

    if (!$id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        json_fail('Invalid move request.');
    }

    try {
        $utc = local_to_utc("$date $time", $tz);
    } catch (Exception $e) {
        json_fail('That date could not be read.');
    }

    if (strtotime($utc . ' UTC') < time() - 60) {
        json_fail('You cannot move a post into the past.');
    }

    json_out(post_reschedule($id, $uid, $utc)
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'That post can no longer be moved.']);
}

/* -------------------------------------------------------------- delete ---- */

if ($action === 'delete') {
    $id = (int)(json_body()['id'] ?? 0);
    if (!$id) {
        json_fail('Missing post id.');
    }
    json_out(['ok' => post_delete($id, $uid)]);
}

json_fail('Unknown action.', 404);
