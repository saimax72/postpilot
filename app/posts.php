<?php
/**
 * Post + connected-account data layer.
 */

function accounts_for_user(int $userId): array
{
    return db_all(
        'SELECT * FROM social_accounts WHERE user_id = ? ORDER BY platform, display_name',
        [$userId]
    );
}

function account_find(int $id, int $userId): ?array
{
    return db_one('SELECT * FROM social_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
}

function account_connect(int $userId, string $platform, string $displayName, ?string $handle, ?string $token = null): int
{
    if (!platform($platform)) {
        throw new InvalidArgumentException('Unknown platform.');
    }
    db_run(
        'INSERT INTO social_accounts (user_id, platform, display_name, handle, access_token)
         VALUES (?,?,?,?,?)',
        [$userId, $platform, $displayName, $handle, encrypt_secret($token)]
    );
    $id = (int)db()->lastInsertId();
    log_activity($userId, 'account_connect', platform_label($platform) . ' - ' . $displayName);
    return $id;
}

/**
 * Set or replace the stored API credentials for a connected account, without
 * disconnecting it. Network tokens expire - Instagram's every 60 days - so
 * this is a routine chore, not a one-off.
 */
function account_credentials(int $id, int $userId, ?string $token, ?string $externalId): bool
{
    $acct = account_find($id, $userId);
    if (!$acct) {
        return false;
    }

    // An empty token field means "leave it alone", not "clear it" - otherwise
    // editing the account ID would silently drop the account into demo mode.
    if ($token !== null && $token !== '') {
        db_run('UPDATE social_accounts SET access_token = ? WHERE id = ? AND user_id = ?',
            [encrypt_secret($token), $id, $userId]);
    }

    db_run('UPDATE social_accounts SET external_id = ?, status = ? WHERE id = ? AND user_id = ?',
        [$externalId ?: null, 'connected', $id, $userId]);

    log_activity($userId, 'account_credentials',
        platform_label($acct['platform']) . ' - ' . $acct['display_name']
        . ($token ? ' (token updated)' : ' (id updated)'));

    return true;
}

/** Remove stored credentials, dropping the account back to demo mode. */
function account_clear_credentials(int $id, int $userId): void
{
    $acct = account_find($id, $userId);
    if (!$acct) {
        return;
    }
    db_run('UPDATE social_accounts SET access_token = NULL WHERE id = ? AND user_id = ?', [$id, $userId]);
    log_activity($userId, 'account_credentials_cleared',
        platform_label($acct['platform']) . ' - ' . $acct['display_name']);
}

function account_disconnect(int $id, int $userId): void
{
    $acct = account_find($id, $userId);
    if (!$acct) {
        return;
    }
    db_run('DELETE FROM social_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);

    // Targets cascade away with the account. Any unsent post left with nowhere
    // to go would sit in the queue forever, so clear those out too.
    db_run(
        "DELETE FROM posts
         WHERE user_id = ?
           AND status IN ('draft','scheduled','failed')
           AND NOT EXISTS (SELECT 1 FROM post_targets t WHERE t.post_id = posts.id)",
        [$userId]
    );

    log_activity($userId, 'account_disconnect', platform_label($acct['platform']) . ' - ' . $acct['display_name']);
}

/* ---------------- Posts ---------------- */

/**
 * Posts in a UTC window, with their targets attached.
 */
function posts_in_range(int $userId, string $fromUtc, string $toUtc): array
{
    $posts = db_all(
        'SELECT * FROM posts
         WHERE user_id = ? AND scheduled_at >= ? AND scheduled_at < ?
         ORDER BY scheduled_at ASC',
        [$userId, $fromUtc, $toUtc]
    );
    return attach_targets($posts);
}

function posts_upcoming(int $userId, int $limit = 8): array
{
    $posts = db_all(
        "SELECT * FROM posts
         WHERE user_id = ? AND status IN ('scheduled','publishing','draft') AND scheduled_at >= UTC_TIMESTAMP()
         ORDER BY scheduled_at ASC LIMIT " . (int)$limit,
        [$userId]
    );
    return attach_targets($posts);
}

function post_find(int $id, int $userId): ?array
{
    $post = db_one('SELECT * FROM posts WHERE id = ? AND user_id = ?', [$id, $userId]);
    if (!$post) {
        return null;
    }
    $rows = attach_targets([$post]);
    return $rows[0];
}

function attach_targets(array $posts): array
{
    if (!$posts) {
        return [];
    }
    $ids = array_column($posts, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $targets = db_all(
        "SELECT t.*, a.display_name, a.handle
         FROM post_targets t
         LEFT JOIN social_accounts a ON a.id = t.social_account_id
         WHERE t.post_id IN ($in)",
        $ids
    );
    $grouped = [];
    foreach ($targets as $t) {
        $grouped[$t['post_id']][] = $t;
    }
    foreach ($posts as &$p) {
        $p['targets'] = $grouped[$p['id']] ?? [];
    }
    unset($p);
    return $posts;
}

/**
 * Create or update a post plus its targets.
 * $accountIds must belong to $userId. $scheduledUtc is 'Y-m-d H:i:s' in UTC.
 * Returns [ok, postId|errorMessage].
 */
function post_save(int $userId, ?int $postId, string $content, array $accountIds, string $scheduledUtc, string $status, ?string $mediaPath, ?string $linkUrl, ?string $mediaOriginal = null, ?string $mediaRatio = null, ?string $cropBox = null, ?string $altText = null, ?string $firstComment = null): array
{
    $content = trim($content);
    if ($content === '' && !$mediaPath) {
        return [false, 'Write something or attach an image before scheduling.'];
    }

    // Plan limits are checked here because every route that creates a post -
    // the composer, bulk upload, Post now - comes through this function.
    // Editing an existing post is never blocked: the post already counted.
    if (!$postId) {
        $owner = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($owner && ($why = post_block_reason($userId, $owner))) {
            return [false, $why];
        }
    }
    if (!$accountIds) {
        return [false, 'Choose at least one account to publish to.'];
    }
    if (!in_array($status, ['draft', 'scheduled'], true)) {
        $status = 'scheduled';
    }

    // Keep only accounts this user actually owns.
    $owned = [];
    foreach ($accountIds as $aid) {
        if ($acct = account_find((int)$aid, $userId)) {
            $owned[(int)$aid] = $acct['platform'];
        }
    }
    if (!$owned) {
        return [false, 'Those accounts are not connected to your workspace.'];
    }

    // Per-network character limits and media rules.
    foreach ($owned as $platform) {
        $limit = platform_limit($platform);
        if (mb_strlen($content) > $limit) {
            return [false, platform_label($platform) . ' allows at most ' . number_format($limit) . ' characters.'];
        }
        if (!empty(platform($platform)['media_required']) && !$mediaPath && $status === 'scheduled') {
            return [false, platform_label($platform) . ' requires an image or video.'];
        }

        // Instagram feed images must sit between 4:5 and 1.91:1. A 9:16 still is
        // a Story or a Reel cover, and both use endpoints this app does not
        // implement - so the API would reject it at publish time. Say so now,
        // while the post can still be fixed.
        if ($platform === 'instagram' && $mediaRatio === 'story' && $mediaPath && !is_video($mediaPath)) {
            return [false, 'Instagram feed posts do not accept 9:16 images — that shape is for Stories and Reels. '
                         . 'Choose Square, Portrait or Landscape for this post.'];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($postId) {
            $existing = db_one('SELECT * FROM posts WHERE id = ? AND user_id = ?', [$postId, $userId]);
            if (!$existing) {
                $pdo->rollBack();
                return [false, 'Post not found.'];
            }
            if (in_array($existing['status'], ['published', 'publishing'], true)) {
                $pdo->rollBack();
                return [false, 'A post that has already gone out cannot be edited.'];
            }
            // A re-crop leaves the previous derivative orphaned.
            if ($existing['media_path'] && $existing['media_path'] !== $mediaPath) {
                discard_crop($existing['media_path'], $userId);
            }
            db_run(
                'UPDATE posts SET content=?, media_path=?, media_original=?, media_ratio=?, crop_box=?,
                        alt_text=?, first_comment=?, link_url=?, scheduled_at=?, status=?, last_error=NULL
                 WHERE id=? AND user_id=?',
                [$content, $mediaPath, $mediaOriginal, $mediaRatio, $cropBox,
                 $altText, $firstComment, $linkUrl, $scheduledUtc, $status, $postId, $userId]
            );
            db_run('DELETE FROM post_targets WHERE post_id = ?', [$postId]);
        } else {
            db_run(
                'INSERT INTO posts (user_id, content, media_path, media_original, media_ratio, crop_box,
                                    alt_text, first_comment, link_url, scheduled_at, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$userId, $content, $mediaPath, $mediaOriginal, $mediaRatio, $cropBox,
                 $altText, $firstComment, $linkUrl, $scheduledUtc, $status]
            );
            $postId = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare('INSERT INTO post_targets (post_id, social_account_id, platform) VALUES (?,?,?)');
        foreach ($owned as $aid => $platform) {
            $st->execute([$postId, $aid, $platform]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, APP_DEBUG ? $e->getMessage() : 'Could not save the post.'];
    }

    log_activity($userId, 'post_save', 'Post #' . $postId . ' - ' . str_limit($content, 60));
    return [true, (int)$postId];
}

function post_delete(int $id, int $userId): bool
{
    $st = db_run('DELETE FROM posts WHERE id = ? AND user_id = ?', [$id, $userId]);
    if ($st->rowCount()) {
        log_activity($userId, 'post_delete', 'Post #' . $id);
        return true;
    }
    return false;
}

/** Move a post to a new time - used by calendar drag and drop. */
function post_reschedule(int $id, int $userId, string $scheduledUtc): bool
{
    $st = db_run(
        "UPDATE posts SET scheduled_at = ?, status = IF(status='failed','scheduled',status)
         WHERE id = ? AND user_id = ? AND status IN ('draft','scheduled','failed')",
        [$scheduledUtc, $id, $userId]
    );
    return $st->rowCount() > 0;
}

/**
 * Put a failed post back in the queue.
 *
 * Clears the error and the attempt count, and resets any target that failed so
 * it is tried again - a target already published is left alone, so a post that
 * half-succeeded does not double-post to the network that worked.
 */
function post_retry(int $id, int $userId, ?string $whenUtc = null): bool
{
    $post = db_one("SELECT * FROM posts WHERE id = ? AND user_id = ? AND status = 'failed'", [$id, $userId]);
    if (!$post) {
        return false;
    }

    db_run(
        "UPDATE posts
            SET status = 'scheduled', attempts = 0, last_error = NULL,
                scheduled_at = ?
          WHERE id = ? AND user_id = ?",
        [$whenUtc ?: gmdate('Y-m-d H:i:s'), $id, $userId]
    );

    db_run("UPDATE post_targets SET status = 'pending', error = NULL
             WHERE post_id = ? AND status = 'failed'", [$id]);

    return true;
}

/**
 * Requeue every failed post, spaced out.
 *
 * Spacing is the point: these fail in bulk because a network throttled a burst,
 * and firing them all again at once reproduces exactly that. Returns how many
 * were requeued and when the last one is due.
 */
/**
 * Requeue every failed post, except the ones that may already be live.
 *
 * A bulk action is precisely where nobody inspects the individual rows, so a
 * post that reached the network before its failure is skipped rather than
 * silently published a second time. They are reported back so the caller can
 * say what was left behind, and each can still be requeued by hand after
 * checking the account.
 */
function post_retry_all(int $userId, int $spacingMinutes = 60): array
{
    $rows = db_all(
        "SELECT id, last_error FROM posts WHERE user_id = ? AND status = 'failed'
         ORDER BY scheduled_at ASC",
        [$userId]
    );

    $when    = time() + 120;        // a couple of minutes to breathe
    $count   = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        if (post_may_be_live($row)) {
            $skipped++;
            continue;
        }
        if (post_retry((int)$row['id'], $userId, gmdate('Y-m-d H:i:s', $when))) {
            $count++;
            $when += $spacingMinutes * 60;
        }
    }

    return [
        'count'   => $count,
        'skipped' => $skipped,
        'last'    => $count ? gmdate('Y-m-d H:i:s', $when - $spacingMinutes * 60) : null,
    ];
}

function post_stats(int $userId): array
{
    $row = db_one(
        "SELECT
            SUM(status='scheduled') AS scheduled,
            SUM(status='published') AS published,
            SUM(status='draft')     AS drafts,
            SUM(status='failed')    AS failed,
            COUNT(*)                AS total
         FROM posts WHERE user_id = ?",
        [$userId]
    ) ?: [];

    // Posts marked published where every target only ever recorded a demo id.
    // Counting these alongside real ones makes the published figure disagree
    // with the network, which is the one number a user will check against.
    $demo = (int)db_value(
        "SELECT COUNT(*) FROM posts p
         WHERE p.user_id = ? AND p.status = 'published'
           AND EXISTS (SELECT 1 FROM post_targets t WHERE t.post_id = p.id)
           AND NOT EXISTS (
                 SELECT 1 FROM post_targets t
                 WHERE t.post_id = p.id AND t.status = 'published'
                   AND (t.remote_post_id IS NULL OR t.remote_post_id NOT LIKE 'demo-%')
               )",
        [$userId]
    );

    return [
        'scheduled' => (int)($row['scheduled'] ?? 0),
        'published' => (int)($row['published'] ?? 0) - $demo,
        'demo'      => $demo,
        'drafts'    => (int)($row['drafts'] ?? 0),
        'failed'    => (int)($row['failed'] ?? 0),
        'total'     => (int)($row['total'] ?? 0),
        'accounts'  => (int)db_value('SELECT COUNT(*) FROM social_accounts WHERE user_id = ?', [$userId]),
    ];
}

/**
 * True when a post was "published" without anything being sent - every target
 * recorded a demo id because the account had no credentials at the time.
 *
 * Worth surfacing: such a post sits in the published count looking identical to
 * a real one, and the only way to notice is that the network disagrees.
 */
/**
 * Whether this failure might have reached the network anyway.
 *
 * A post can go out and still be recorded as failed: the network accepts it,
 * then the database write that records that fact fails. The tell is the error
 * text - a lost connection, or the worker recovering a run that was cut off
 * mid-publish. Both mean the post may already be live.
 *
 * Ordinary failures (a rejected image, a bad token, a rate limit) never reached
 * the network, so they are safe to retry and are not flagged.
 */
function post_may_be_live(array $post): bool
{
    $err = (string)($post['last_error'] ?? '');
    if ($err === '') {
        return false;
    }
    foreach (['Interrupted while publishing', 'Lost connection', 'server has gone away',
              'Error while sending', '2013', '2006'] as $needle) {
        if (stripos($err, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function post_was_demo(array $post): bool
{
    $targets = $post['targets'] ?? [];
    if (!$targets) {
        return false;
    }
    foreach ($targets as $t) {
        if (($t['status'] ?? '') !== 'published') {
            continue;
        }
        if (!str_starts_with((string)($t['remote_post_id'] ?? ''), 'demo-')) {
            return false;
        }
    }
    return true;
}

/* ---------------- Uploads ---------------- */

function handle_upload(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload failed (code ' . $file['error'] . ').'];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return [false, 'File is larger than ' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return [false, 'Only JPG, PNG, GIF, WEBP, MP4 or MOV files are allowed.'];
    }

    $dir = rtrim(UPLOAD_DIR, '/\\') . '/' . $userId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, 'Upload directory is not writable.'];
    }

    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [false, 'Could not store the uploaded file.'];
    }

    return [true, $userId . '/' . $name];
}

function media_url(?string $path): ?string
{
    return $path ? rtrim(UPLOAD_URL, '/') . '/' . ltrim($path, '/') : null;
}

function is_video(?string $path): bool
{
    return $path && preg_match('/\.(mp4|mov)$/i', $path) === 1;
}

/* ---------------- Composer payload ---------------- */

/**
 * Everything the composer's JavaScript needs, for any page that embeds it.
 * Keeps dashboard, queue and grid from drifting apart.
 */
function composer_payload(array $posts, array $accounts, string $tz): string
{
    $jsPosts = [];
    foreach ($posts as $p) {
        $crop = null;
        if (!empty($p['crop_box'])) {
            $parts = array_map('floatval', explode(',', $p['crop_box']));
            if (count($parts) === 4) {
                $crop = ['fx' => $parts[0], 'fy' => $parts[1], 'fw' => $parts[2], 'fh' => $parts[3]];
            }
        }

        // The cropper always works from the untouched original when there is one.
        $source = $p['media_original'] ?? null ?: ($p['media_path'] ?? null);

        $jsPosts[$p['id']] = [
            'id'             => (int)$p['id'],
            'content'        => $p['content'],
            'date'           => utc_to_local($p['scheduled_at'], $tz, 'Y-m-d'),
            'time'           => utc_to_local($p['scheduled_at'], $tz, 'H:i'),
            'status'         => $p['status'],
            'original'       => media_url($source),
            'media_original' => $source,
            'is_video'       => is_video($source),
            'ratio'          => $p['media_ratio'] ?? 'square',
            'crop'           => $crop,
            'alt'            => $p['alt_text'] ?? '',
            'first'          => $p['first_comment'] ?? '',
            'link'           => $p['link_url'],
            'accounts'       => array_map('intval', array_column($p['targets'] ?? [], 'social_account_id')),
            'error'          => $p['last_error'],
            // Per-network outcome, so a published post can explain itself -
            // in particular whether it actually went out or was a demo run.
            'results'        => array_map(fn($t) => [
                'platform' => $t['platform'],
                'label'    => platform_label($t['platform']),
                'account'  => $t['display_name'] ?? '',
                'colour'   => platform_color($t['platform']),
                'status'   => $t['status'],
                'url'      => $t['remote_url'],
                'demo'     => str_starts_with((string)$t['remote_post_id'], 'demo-'),
                'error'    => $t['error'],
            ], $p['targets'] ?? []),
        ];
    }

    $ratios = $labels = [];
    foreach (media_ratios() as $key => $r) {
        $ratios[$key] = $r['w'] / $r['h'];
        $labels[$key] = $r['label'] . ' ' . $r['hint'];
    }

    $data = [
        'posts'    => (object)$jsPosts,
        'accounts' => array_map(fn($a) => [
            'id'            => (int)$a['id'],
            'name'          => $a['display_name'],
            'handle'        => $a['handle'],
            'platform'      => $a['platform'],
            'platformLabel' => platform_label($a['platform']),
            'color'         => platform_color($a['platform']),
            'limit'         => platform_limit($a['platform']),
        ], $accounts),
        'tz'          => $tz,
        'templates'   => array_map(fn($t) => [
            'id'       => (int)$t['id'],
            'name'     => $t['name'],
            'content'  => (string)$t['content'],
            'link'     => (string)$t['link_url'],
            'ratio'    => $t['media_ratio'] ?: 'square',
            'alt'      => (string)$t['alt_text'],
            'first'    => (string)$t['first_comment'],
            'accounts' => $t['account_list'],
        ], templates_for_user((int)auth_id())),
        'sets'        => array_map(fn($s) => [
            'id'   => (int)$s['id'],
            'name' => $s['name'],
            'tags' => $s['tag_list'],
        ], sets_for_user((int)auth_id())),
        'ratios'      => (object)$ratios,
        'ratioLabels' => (object)$labels,
    ];

    return '<script>window.PP = ' . json_encode($data, JSON_UNESCAPED_SLASHES) . ';</script>'
         . '<script src="' . asset('/assets/js/app.js') . '"></script>';
}
