<?php
/**
 * Finding the same image more than once.
 *
 * Bulk uploads make this easy to do by accident: the same photo picked twice
 * from a folder, or re-exported under a new name, becomes two posts nobody
 * meant to schedule.
 *
 * Matching is on file content, not on filename or size, so a copy renamed
 * "IMG_2201 (1).jpg" is still caught. It will not catch a re-encoded or
 * resized version of the same photo - that needs perceptual hashing, which is
 * a different and much slower job. Exact copies are the common case and this
 * finds them reliably rather than approximately.
 *
 * Originals are compared rather than the cropped files: the same photo cropped
 * square and again as 4:5 produces two different files, and they are not
 * duplicates - they are one photo, used twice on purpose.
 */

/** Where the hash cache for one user lives. */
function dup_cache_path(int $userId): string
{
    return rtrim(UPLOAD_DIR, '/\\') . '/' . $userId . '/.hashcache.json';
}

/**
 * Every media file belonging to this user, with a content hash.
 *
 * Hashes are cached against size and modification time, so a second visit
 * costs a stat() per file rather than reading every byte again. On shared
 * hosting that is the difference between instant and half a minute.
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
        $abs = $dir . '/' . $name;
        $rel = $userId . '/' . $name;

        $size = (int)filesize($abs);
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
 * Groups of identical files, largest waste first.
 *
 * Each group carries the posts that use each copy, because that is what decides
 * whether a copy can safely go: a file no post references is just clutter, one
 * that is scheduled is not.
 */
function dup_groups(int $userId, bool $refresh = false): array
{
    $files = dup_hashes($userId, $refresh);
    if (!$files) {
        return [];
    }

    // Which posts point at each file, as an original or as a crop source.
    $usage = [];
    foreach (db_all(
        'SELECT id, status, scheduled_at, media_path, media_original
           FROM posts WHERE user_id = ?', [$userId]
    ) as $post) {
        foreach ([$post['media_original'], $post['media_path']] as $ref) {
            if ($ref) {
                $usage[$ref][$post['id']] = $post;
            }
        }
    }

    $byHash = [];
    foreach ($files as $rel => $f) {
        $f['posts'] = array_values($usage[$rel] ?? []);
        $byHash[$f['hash']][] = $f;
    }

    $groups = [];
    foreach ($byHash as $hash => $copies) {
        if (count($copies) < 2) {
            continue;
        }
        // Oldest first: the first copy is the one worth keeping.
        usort($copies, fn($a, $b) => strcmp($a['name'], $b['name']));

        $used = 0;
        foreach ($copies as $c) {
            $used += count($c['posts']) > 0 ? 1 : 0;
        }

        $groups[] = [
            'hash'    => $hash,
            'copies'  => $copies,
            'count'   => count($copies),
            'wasted'  => $copies[0]['size'] * (count($copies) - 1),
            'in_use'  => $used,
        ];
    }

    usort($groups, fn($a, $b) => $b['wasted'] <=> $a['wasted']);
    return $groups;
}

/** Headline numbers for the page. */
function dup_summary(array $groups): array
{
    $extra = 0; $wasted = 0;
    foreach ($groups as $g) {
        $extra  += $g['count'] - 1;
        $wasted += $g['wasted'];
    }
    return ['groups' => count($groups), 'extra' => $extra, 'wasted' => $wasted];
}

/**
 * Delete one copy, but only when nothing points at it.
 *
 * Refusing to remove a file a post uses is the whole safety property here:
 * these are scheduled posts, and a missing image fails at publish time, hours
 * later, when nobody is watching.
 */
function dup_delete(int $userId, string $rel): array
{
    if (strpos($rel, $userId . '/') !== 0 || strpos($rel, '..') !== false) {
        return [false, 'That file does not belong to your account.'];
    }

    $used = (int)db_value(
        'SELECT COUNT(*) FROM posts WHERE user_id = ? AND (media_path = ? OR media_original = ?)',
        [$userId, $rel, $rel]
    );
    if ($used > 0) {
        return [false, 'That copy is used by ' . $used . ' post' . ($used === 1 ? '' : 's') . ', so it was kept.'];
    }

    $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . $rel;
    if (!is_file($abs)) {
        return [false, 'That file is already gone.'];
    }

    @unlink($abs);
    log_activity($userId, 'media_dedupe', 'Removed duplicate ' . basename($rel));
    return [true, 'Copy deleted.'];
}
