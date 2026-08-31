<?php
/**
 * Publishing layer.
 *
 * publish_due_posts() is what cron/publish.php calls. For each due post it
 * walks the post's targets and hands each one to the driver for that network.
 *
 * A driver returns: ['ok' => bool, 'id' => ?string, 'url' => ?string, 'error' => ?string]
 *
 * Drivers only make real API calls when the connected account has a stored
 * access token. Without one the post is marked published in "demo" mode so you
 * can exercise the whole scheduling pipeline before your developer apps are
 * approved by each network.
 */

define('PUBLISH_MAX_ATTEMPTS', 3);

function publish_due_posts(int $limit = 25): array
{
    $due = db_all(
        "SELECT * FROM posts
         WHERE status = 'scheduled'
           AND scheduled_at <= UTC_TIMESTAMP()
           AND attempts < " . PUBLISH_MAX_ATTEMPTS . "
         ORDER BY scheduled_at ASC
         LIMIT " . (int)$limit
    );

    $report = ['processed' => 0, 'published' => 0, 'failed' => 0, 'details' => []];

    foreach ($due as $post) {
        // Claim the row so two overlapping cron runs cannot double-post.
        $claimed = db_run(
            "UPDATE posts SET status = 'publishing', attempts = attempts + 1
             WHERE id = ? AND status = 'scheduled'",
            [$post['id']]
        )->rowCount();

        if (!$claimed) {
            continue;
        }

        $report['processed']++;
        $result = publish_post((int)$post['id']);

        if ($result['ok']) {
            $report['published']++;
        } else {
            $report['failed']++;
        }
        $report['details'][] = ['post' => (int)$post['id']] + $result;
    }

    return $report;
}

/**
 * Push one post to every target attached to it.
 */
function publish_post(int $postId): array
{
    $post = db_one('SELECT * FROM posts WHERE id = ?', [$postId]);
    if (!$post) {
        return ['ok' => false, 'message' => 'Post not found.'];
    }

    $targets = db_all(
        'SELECT t.*, a.access_token, a.display_name, a.external_id, a.status AS account_status
         FROM post_targets t
         JOIN social_accounts a ON a.id = t.social_account_id
         WHERE t.post_id = ?',
        [$postId]
    );

    if (!$targets) {
        db_run("UPDATE posts SET status='failed', last_error=? WHERE id=?",
            ['No connected accounts remain on this post.', $postId]);
        return ['ok' => false, 'message' => 'No targets.'];
    }

    $anySuccess = false;
    $errors     = [];

    foreach ($targets as $t) {
        if ($t['status'] === 'published') {
            $anySuccess = true;
            continue;
        }

        try {
            $res = publish_to_platform($t['platform'], $post, $t);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!empty($res['ok'])) {
            $anySuccess = true;
            db_run(
                "UPDATE post_targets
                 SET status='published', remote_post_id=?, remote_url=?, error=NULL, published_at=UTC_TIMESTAMP()
                 WHERE id=?",
                [$res['id'] ?? null, $res['url'] ?? null, $t['id']]
            );
        } else {
            $msg = mb_substr($res['error'] ?? 'Unknown error', 0, 500);
            $errors[] = platform_label($t['platform']) . ': ' . $msg;
            db_run("UPDATE post_targets SET status='failed', error=? WHERE id=?", [$msg, $t['id']]);
        }
    }

    $pending = (int)db_value(
        "SELECT COUNT(*) FROM post_targets WHERE post_id = ? AND status <> 'published'",
        [$postId]
    );

    if ($pending === 0) {
        db_run("UPDATE posts SET status='published', published_at=UTC_TIMESTAMP(), last_error=NULL WHERE id=?", [$postId]);
    } elseif ((int)$post['attempts'] + 1 >= PUBLISH_MAX_ATTEMPTS) {
        // Out of retries - park it as failed so it shows up in the UI.
        db_run("UPDATE posts SET status='failed', last_error=? WHERE id=?",
            [mb_substr(implode(' | ', $errors), 0, 500), $postId]);
    } else {
        // Put it back in the queue for the next cron run.
        db_run("UPDATE posts SET status='scheduled', last_error=? WHERE id=?",
            [mb_substr(implode(' | ', $errors), 0, 500), $postId]);
    }

    log_activity((int)$post['user_id'], $anySuccess ? 'post_published' : 'post_failed',
        'Post #' . $postId . ($errors ? ' - ' . implode(' | ', $errors) : ''));

    return [
        'ok'      => $pending === 0,
        'message' => $pending === 0 ? 'Published.' : implode(' | ', $errors),
    ];
}

/**
 * Router: hand a target to the right network driver.
 */
function publish_to_platform(string $platform, array $post, array $target): array
{
    $token = decrypt_secret($target['access_token'] ?? null);

    // No live credentials yet -> demo publish so the pipeline stays testable.
    if (!$token) {
        return [
            'ok'  => true,
            'id'  => 'demo-' . $platform . '-' . $post['id'],
            'url' => null,
        ];
    }

    switch ($platform) {
        case 'facebook':  return drive_facebook($post, $target, $token);
        case 'instagram': return drive_instagram($post, $target, $token);
        case 'threads':   return drive_threads($post, $target, $token);
        case 'linkedin':  return drive_linkedin($post, $target, $token);
        case 'x':         return drive_x($post, $target, $token);
        default:
            return [
                'ok'    => false,
                'error' => platform_label($platform) . ' publishing is not wired up yet. '
                         . 'Add a driver in app/publisher.php - see ' . (platform($platform)['docs'] ?? ''),
            ];
    }
}

/* ---------------- Network drivers ---------------- */

/** Facebook Page feed post (Graph API). external_id = Page ID. */
function drive_facebook(array $post, array $target, string $token): array
{
    $pageId = $target['external_id'] ?: 'me';
    $media  = absolute_media_url($post['media_path']);

    if ($media && !is_video($post['media_path'])) {
        $res = http_post("https://graph.facebook.com/v21.0/{$pageId}/photos", [
            'url'          => $media,
            'caption'      => $post['content'],
            'access_token' => $token,
        ]);
    } else {
        $res = http_post("https://graph.facebook.com/v21.0/{$pageId}/feed", array_filter([
            'message'      => $post['content'],
            'link'         => $post['link_url'],
            'access_token' => $token,
        ]));
    }

    if (!empty($res['id'])) {
        return ['ok' => true, 'id' => $res['id'], 'url' => 'https://facebook.com/' . $res['id']];
    }
    return ['ok' => false, 'error' => graph_error($res)];
}

/**
 * Meta ships two Instagram publishing APIs and they are not interchangeable:
 *
 *  - Instagram API with Instagram Login  -> graph.instagram.com, tokens start "IG"
 *  - Instagram API with Facebook Login   -> graph.facebook.com,  tokens start "EAA"
 *
 * Both expose the same container-then-publish endpoints, so the only thing
 * that differs is the host. The token prefix tells us which one we hold,
 * which beats asking the user to classify their own credentials.
 */
function instagram_host(string $token): string
{
    return str_starts_with($token, 'IG')
        ? 'https://graph.instagram.com/v21.0'
        : 'https://graph.facebook.com/v21.0';
}

/** Instagram: create a media container, then publish it. external_id = IG user ID. */
function drive_instagram(array $post, array $target, string $token): array
{
    $igId  = $target['external_id'];
    $media = absolute_media_url($post['media_path']);
    $host  = instagram_host($token);

    if (!$igId)  return ['ok' => false, 'error' => 'Instagram account ID missing on the connected account.'];
    if (!$media) return ['ok' => false, 'error' => 'Instagram requires an image or video.'];

    $create = http_post("{$host}/{$igId}/media", array_filter([
        'image_url'    => is_video($post['media_path']) ? null : $media,
        'video_url'    => is_video($post['media_path']) ? $media : null,
        'media_type'   => is_video($post['media_path']) ? 'REELS' : null,
        'caption'      => $post['content'],
        'alt_text'     => $post['alt_text'] ?? null,
        'access_token' => $token,
    ]));

    if (empty($create['id'])) {
        return ['ok' => false, 'error' => graph_error($create)];
    }

    $publish = http_post("{$host}/{$igId}/media_publish", [
        'creation_id'  => $create['id'],
        'access_token' => $token,
    ]);

    if (!empty($publish['id'])) {
        // First comment is best-effort: the post is already live, so a failure
        // here must not mark the whole thing failed.
        if (!empty($post['first_comment'])) {
            http_post("{$host}/{$publish['id']}/comments", [
                'message'      => $post['first_comment'],
                'access_token' => $token,
            ]);
        }
        return ['ok' => true, 'id' => $publish['id'], 'url' => null];
    }
    return ['ok' => false, 'error' => graph_error($publish)];
}

/** Threads: same two-step container flow as Instagram. */
function drive_threads(array $post, array $target, string $token): array
{
    $userId = $target['external_id'];
    if (!$userId) {
        return ['ok' => false, 'error' => 'Threads user ID missing on the connected account.'];
    }
    $media = absolute_media_url($post['media_path']);

    $create = http_post("https://graph.threads.net/v1.0/{$userId}/threads", array_filter([
        'media_type'   => $media ? (is_video($post['media_path']) ? 'VIDEO' : 'IMAGE') : 'TEXT',
        'image_url'    => $media && !is_video($post['media_path']) ? $media : null,
        'video_url'    => $media && is_video($post['media_path']) ? $media : null,
        'text'         => $post['content'],
        'access_token' => $token,
    ]));

    if (empty($create['id'])) {
        return ['ok' => false, 'error' => graph_error($create)];
    }

    $publish = http_post("https://graph.threads.net/v1.0/{$userId}/threads_publish", [
        'creation_id'  => $create['id'],
        'access_token' => $token,
    ]);

    return !empty($publish['id'])
        ? ['ok' => true, 'id' => $publish['id'], 'url' => null]
        : ['ok' => false, 'error' => graph_error($publish)];
}

/** LinkedIn UGC post. external_id = "urn:li:person:XXXX" or "urn:li:organization:XXXX". */
function drive_linkedin(array $post, array $target, string $token): array
{
    $author = $target['external_id'];
    if (!$author) {
        return ['ok' => false, 'error' => 'LinkedIn author URN missing on the connected account.'];
    }

    $body = [
        'author'          => $author,
        'lifecycleState'  => 'PUBLISHED',
        'specificContent' => [
            'com.linkedin.ugc.ShareContent' => [
                'shareCommentary'    => ['text' => $post['content']],
                'shareMediaCategory' => $post['link_url'] ? 'ARTICLE' : 'NONE',
            ],
        ],
        'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
    ];

    if ($post['link_url']) {
        $body['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [
            ['status' => 'READY', 'originalUrl' => $post['link_url']],
        ];
    }

    $res = http_json('https://api.linkedin.com/v2/ugcPosts', $body, [
        'Authorization: Bearer ' . $token,
        'X-Restli-Protocol-Version: 2.0.0',
    ]);

    return !empty($res['id'])
        ? ['ok' => true, 'id' => $res['id'], 'url' => null]
        : ['ok' => false, 'error' => $res['message'] ?? 'LinkedIn rejected the post.'];
}

/** X (Twitter) API v2 - text only; media needs the separate upload endpoint. */
function drive_x(array $post, array $target, string $token): array
{
    $res = http_json('https://api.x.com/2/tweets', ['text' => $post['content']], [
        'Authorization: Bearer ' . $token,
    ]);

    if (!empty($res['data']['id'])) {
        return [
            'ok'  => true,
            'id'  => $res['data']['id'],
            'url' => 'https://x.com/i/status/' . $res['data']['id'],
        ];
    }
    return ['ok' => false, 'error' => $res['detail'] ?? ($res['title'] ?? 'X rejected the post.')];
}

/* ---------------- HTTP helpers ---------------- */

function absolute_media_url(?string $path): ?string
{
    return $path ? rtrim(APP_URL, '/') . rtrim(UPLOAD_URL, '/') . '/' . ltrim($path, '/') : null;
}

function http_post(string $url, array $fields): array
{
    return http_request($url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
}

function http_json(string $url, array $body, array $headers = []): array
{
    $headers[] = 'Content-Type: application/json';
    return http_request($url, json_encode($body), $headers);
}

function http_request(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['error' => ['message' => 'Network error: ' . $err]];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['error' => ['message' => 'Unexpected response: ' . mb_substr($raw, 0, 200)]];
}

function graph_error(array $res): string
{
    return $res['error']['message'] ?? 'The network rejected the post.';
}
