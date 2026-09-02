<?php
/**
 * Finding the same image posted more than once.
 *
 * The question this answers is "did I put this picture out twice?", not "are
 * there spare files on disk". Those are different things: one photo cropped for
 * two ratios is two files and one post, which is fine; the same photo in two
 * published posts is one duplicate on the feed, which is not.
 *
 * So files are grouped by content and then collapsed to the posts that use
 * them. A group only counts when two or more posts share the image.
 *
 * Matching is on file content, so a copy saved under a different name is still
 * caught. It will not catch a resized or re-encoded version of the same photo -
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
 * Images that appear in more than one post, worst first.
 *
 * A post is counted once however many files of that image it references: a post
 * points at both the original and its crop, and those are the same picture used
 * once, not twice.
 */
function dup_repeated(int $userId, bool $refresh = false): array
{
    $files = dup_hashes($userId, $refresh);
    if (!$files) {
        return [];
    }

    $posts = attach_targets(db_all(
        'SELECT id, status, scheduled_at, published_at, content, media_path, media_original
           FROM posts WHERE user_id = ? ORDER BY COALESCE(published_at, scheduled_at) ASC',
        [$userId]
    ));

    $byHash = [];
    foreach ($posts as $post) {
        $hashes = [];
        foreach ([$post['media_original'], $post['media_path']] as $ref) {
            if ($ref && isset($files[$ref])) {
                $hashes[$files[$ref]['hash']] = $files[$ref];
            }
        }
        // Keyed by post id so the original and the crop cannot count twice.
        foreach ($hashes as $hash => $file) {
            $byHash[$hash]['file']         = $byHash[$hash]['file'] ?? $file;
            $byHash[$hash]['posts'][$post['id']] = $post;
        }
    }

    $groups = [];
    foreach ($byHash as $hash => $g) {
        $list = array_values($g['posts']);
        if (count($list) < 2) {
            continue;
        }

        $published = 0;
        foreach ($list as $p) {
            if ($p['status'] === 'published' && !post_was_demo($p)) {
                $published++;
            }
        }

        $groups[] = [
            'hash'      => $hash,
            'file'      => $g['file'],
            'posts'     => $list,
            'count'     => count($list),
            'published' => $published,
        ];
    }

    // The ones actually live on a feed twice matter most.
    usort($groups, fn($a, $b) => [$b['published'], $b['count']] <=> [$a['published'], $a['count']]);
    return $groups;
}

/** Headline numbers for the page. */
function dup_summary(array $groups): array
{
    $onFeed = 0; $extra = 0;
    foreach ($groups as $g) {
        $extra += $g['count'] - 1;
        if ($g['published'] > 1) {
            $onFeed++;
        }
    }
    return ['images' => count($groups), 'extra' => $extra, 'on_feed' => $onFeed];
}
