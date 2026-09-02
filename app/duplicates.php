<?php
/**
 * Marking up the grid where one picture sits on more than one post.
 *
 * This is a hint, not the authority. Whether something is really on the feed
 * twice is answered by ig_feed_duplicates(), which asks the network - our own
 * records cannot see a post that published and then lost its confirmation.
 *
 * Matching is on file content, so a copy saved under a different name is still
 * caught. It will not catch a resized or re-encoded version of the same photo:
 * that needs perceptual hashing, which is a different and much slower job.
 */

/** Where the hash cache for one user lives. */
function dup_cache_path(int $userId): string
{
    return rtrim(UPLOAD_DIR, '/\\') . '/' . $userId . '/.hashcache.json';
}

/**
 * Every media file belonging to this user, with a content hash.
 *
 * Hashes are cached against size and modification time, so a second visit costs
 * a stat() per file rather than reading every byte again. On shared hosting
 * that is the difference between instant and half a minute.
 */
function dup_hashes(int $userId, bool $refresh = false): array
{
    $dir = rtrim(UPLOAD_DIR, '/\\') . '/' . $userId;
    if (!is_dir($dir)) {
        return [];
    }

    $cache = [];
    if (!$refresh && is_file(dup_cache_path($userId))) {
        $raw   = json_decode((string)file_get_contents(dup_cache_path($userId)), true);
        $cache = is_array($raw) ? $raw : [];
    }

    $out     = [];
    $changed = false;

    foreach (scandir($dir) ?: [] as $name) {
        if ($name[0] === '.' || !is_file($dir . '/' . $name)) {
            continue;
        }
        $abs   = $dir . '/' . $name;
        $rel   = $userId . '/' . $name;
        $size  = (int)filesize($abs);
        $mtime = (int)filemtime($abs);

        $hit = $cache[$name] ?? null;
        if ($hit && (int)($hit['size'] ?? -1) === $size && (int)($hit['mtime'] ?? -1) === $mtime) {
            $hash = (string)$hit['hash'];
        } else {
            // sha1 rather than sha256: this is duplicate detection, not
            // security, and it halves the read cost on a folder of photos.
            $hash    = (string)sha1_file($abs);
            $changed = true;
        }

        $cache[$name] = ['size' => $size, 'mtime' => $mtime, 'hash' => $hash];
        $out[$rel] = ['name' => $name, 'rel' => $rel, 'size' => $size, 'hash' => $hash];
    }

    // Drop cache entries for files that are gone, so it cannot grow forever.
    foreach (array_keys($cache) as $name) {
        if (!isset($out[$userId . '/' . $name])) {
            unset($cache[$name]);
            $changed = true;
        }
    }

    if ($changed) {
        @file_put_contents(dup_cache_path($userId), json_encode($cache));
    }

    return $out;
}

/**
 * post id => the image it shares with other posts, for marking up a grid.
 *
 * $liveOnly is the important argument. Repeating a bulk run leaves the same
 * picture attached to two posts routinely - one failed, one succeeded, or two
 * drafts - and none of that is a duplicate anybody needs to act on. What
 * matters is a picture that reached the feed twice, so that is the default.
 *
 * "Reached the feed" means a target that published with a real id: a demo
 * publish was recorded but never transmitted, and counting it would report a
 * duplicate that does not exist on the network.
 */
function dup_post_map(int $userId, bool $refresh = false, bool $liveOnly = true): array
{
    $files = dup_hashes($userId, $refresh);
    if (!$files) {
        return [];
    }

    $posts = db_all(
        "SELECT p.id, p.media_path, p.media_original,
                (SELECT COUNT(*) FROM post_targets t
                  WHERE t.post_id = p.id
                    AND t.status = 'published'
                    AND t.remote_post_id IS NOT NULL
                    AND t.remote_post_id NOT LIKE 'demo-%') AS live_targets
           FROM posts p WHERE p.user_id = ?",
        [$userId]
    );

    $byHash = [];
    foreach ($posts as $post) {
        if ($liveOnly && (int)$post['live_targets'] === 0) {
            continue;
        }
        foreach ([$post['media_original'], $post['media_path']] as $ref) {
            if ($ref && isset($files[$ref])) {
                // Keyed by post id so one picture cropped two ways, on the same
                // post, cannot make that post look like its own duplicate.
                $byHash[$files[$ref]['hash']][(int)$post['id']] = true;
            }
        }
    }

    $map = [];
    foreach ($byHash as $hash => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        foreach (array_keys($ids) as $id) {
            $map[$id] = ['hash' => $hash, 'count' => count($ids)];
        }
    }
    return $map;
}
