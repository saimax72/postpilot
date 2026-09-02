<?php
/**
 * Reading back from Instagram.
 *
 * The grid preview is only honest if it shows the real feed with upcoming
 * posts stacked on top of it. That needs a read call, which is separate from
 * everything in publisher.php - that file only ever sends.
 */

define('IG_CACHE_TTL', 900);   // 15 minutes

function ig_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Recent media for a connected account, newest first.
 *
 * Returns ['ok' => bool, 'items' => array, 'error' => ?string, 'cached' => bool].
 * Cached for 15 minutes: this runs on a page load, and Instagram's rate limits
 * are not generous enough to refetch on every refresh.
 */
function ig_recent_media(array $account, int $limit = 12, bool $force = false): array
{
    $token = decrypt_secret($account['access_token'] ?? null);
    $igId  = $account['external_id'] ?? null;

    if (!$token) {
        return ['ok' => false, 'items' => [], 'cached' => false,
                'error' => 'This account is in demo mode — add API credentials to see the live feed.'];
    }
    if (!$igId) {
        return ['ok' => false, 'items' => [], 'cached' => false,
                'error' => 'Instagram account ID is missing on this connection.'];
    }

    $file = ig_cache_dir() . '/ig_' . (int)$account['id'] . '.json';

    if (!$force && is_file($file) && (time() - filemtime($file)) < IG_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached)) {
            return ['ok' => true, 'items' => $cached, 'cached' => true, 'error' => null];
        }
    }

    $res = http_get(instagram_host($token) . '/' . $igId . '/media', [
        'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
        'limit'        => max(1, min($limit, 50)),
        'access_token' => $token,
    ]);

    if (!empty($res['error'])) {
        // Serve stale rather than nothing - an expired token should not blank
        // a page that was working a minute ago.
        if (is_file($file)) {
            $stale = json_decode((string)file_get_contents($file), true);
            if (is_array($stale)) {
                return ['ok' => true, 'items' => $stale, 'cached' => true,
                        'error' => 'Showing cached posts — ' . graph_error($res)];
            }
        }
        return ['ok' => false, 'items' => [], 'cached' => false, 'error' => graph_error($res)];
    }

    $items = [];
    foreach (($res['data'] ?? []) as $m) {
        $items[] = [
            'id'        => $m['id'] ?? '',
            'caption'   => $m['caption'] ?? '',
            'type'      => $m['media_type'] ?? 'IMAGE',
            // Videos give a poster in thumbnail_url; images only have media_url.
            'image'     => $m['thumbnail_url'] ?? ($m['media_url'] ?? ''),
            'permalink' => $m['permalink'] ?? '',
            'timestamp' => $m['timestamp'] ?? '',
        ];
    }

    @file_put_contents($file, json_encode($items));
    return ['ok' => true, 'items' => $items, 'cached' => false, 'error' => null];
}

/**
 * Posts that appear more than once on the account's own feed.
 *
 * This asks Instagram rather than our own records, which is the only way to
 * catch the case that matters: a post that published, lost its confirmation to
 * a dropped database connection, was recorded as failed and then republished.
 * Our records show one post; the feed shows two. Only the feed is right.
 *
 * Matching is on the caption. Instagram re-encodes every upload, so two copies
 * of one picture are not the same bytes and cannot be compared as images
 * without downloading and perceptually hashing both. An accidental republish
 * carries the identical caption, which is a sharp enough signal - and posts
 * with no caption are skipped rather than guessed at.
 */
function ig_feed_duplicates(array $account, int $limit = 50, bool $force = false): array
{
    $feed = ig_recent_media($account, $limit, $force);
    if (!$feed['ok']) {
        return ['ok' => false, 'groups' => [], 'checked' => 0,
                'skipped' => 0, 'error' => $feed['error']];
    }

    $byCaption = [];
    $skipped   = 0;

    foreach ($feed['items'] as $item) {
        // Collapse whitespace so a stray newline does not hide a match.
        $key = trim(preg_replace('/\s+/u', ' ', (string)$item['caption']));
        if ($key === '') {
            $skipped++;
            continue;
        }
        $byCaption[mb_strtolower($key)][] = $item;
    }

    $groups = [];
    foreach ($byCaption as $items) {
        if (count($items) > 1) {
            $groups[] = $items;
        }
    }

    return [
        'ok'      => true,
        'groups'  => $groups,
        'checked' => count($feed['items']),
        'skipped' => $skipped,
        'cached'  => $feed['cached'],
        'error'   => $feed['error'],
    ];
}

/** Drop the cached feed for an account, so the next load refetches. */
function ig_forget(int $accountId): void
{
    @unlink(ig_cache_dir() . '/ig_' . $accountId . '.json');
}
