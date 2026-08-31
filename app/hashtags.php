<?php
/**
 * Saved hashtag sets.
 *
 * A set is a named bundle of hashtags you drop into a caption or first comment
 * with one click, instead of retyping the same forty tags every week.
 */

define('MAX_TAGS_PER_SET', 60);

/**
 * Turn whatever the user typed into a clean, de-duplicated tag list.
 * Accepts them separated by spaces, commas or newlines, with or without the #.
 */
function normalise_tags(string $raw): array
{
    $parts = preg_split('/[\s,]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out   = [];

    foreach ($parts as $part) {
        // Strip every leading # (people paste "##tag"), then keep only tag-safe characters.
        $tag = preg_replace('/^#+/u', '', $part);
        $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag);

        if ($tag === '' || mb_strlen($tag) > 100) {
            continue;
        }
        // Instagram treats tags case-insensitively, so dedupe that way too.
        $key = mb_strtolower($tag);
        if (!isset($out[$key])) {
            $out[$key] = '#' . $tag;
        }
        if (count($out) >= MAX_TAGS_PER_SET) {
            break;
        }
    }

    return array_values($out);
}

function sets_for_user(int $userId): array
{
    try {
        $rows = db_all('SELECT * FROM hashtag_sets WHERE user_id = ? ORDER BY name', [$userId]);
    } catch (PDOException $e) {
        // Table not migrated yet. Hashtag sets are optional, so degrade to none
        // rather than taking down every page that embeds the composer.
        error_log('PostPilot: hashtag_sets unavailable - ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$r) {
        $r['tag_list']  = preg_split('/\s+/', trim($r['tags']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $r['tag_count'] = count($r['tag_list']);
    }
    unset($r);
    return $rows;
}

function set_find(int $id, int $userId): ?array
{
    return db_one('SELECT * FROM hashtag_sets WHERE id = ? AND user_id = ?', [$id, $userId]);
}

/** Returns [ok, id|errorMessage]. */
function set_save(int $userId, ?int $id, string $name, string $rawTags): array
{
    $name = trim($name);
    if ($name === '') {
        return [false, 'Give the set a name so you can find it in the composer.'];
    }
    if (mb_strlen($name) > 80) {
        return [false, 'That name is too long — 80 characters at most.'];
    }

    $tags = normalise_tags($rawTags);
    if (!$tags) {
        return [false, 'Add at least one hashtag.'];
    }

    $clash = db_value(
        'SELECT id FROM hashtag_sets WHERE user_id = ? AND name = ? AND id <> ?',
        [$userId, $name, $id ?: 0]
    );
    if ($clash) {
        return [false, 'You already have a set called "' . $name . '".'];
    }

    $joined = implode(' ', $tags);

    if ($id) {
        $st = db_run(
            'UPDATE hashtag_sets SET name = ?, tags = ? WHERE id = ? AND user_id = ?',
            [$name, $joined, $id, $userId]
        );
        if (!$st->rowCount() && !set_find($id, $userId)) {
            return [false, 'That set no longer exists.'];
        }
        log_activity($userId, 'hashtag_set_update', $name . ' (' . count($tags) . ' tags)');
        return [true, $id];
    }

    db_run('INSERT INTO hashtag_sets (user_id, name, tags) VALUES (?,?,?)', [$userId, $name, $joined]);
    $newId = (int)db()->lastInsertId();
    log_activity($userId, 'hashtag_set_create', $name . ' (' . count($tags) . ' tags)');
    return [true, $newId];
}

function set_delete(int $id, int $userId): bool
{
    $set = set_find($id, $userId);
    if (!$set) {
        return false;
    }
    db_run('DELETE FROM hashtag_sets WHERE id = ? AND user_id = ?', [$id, $userId]);
    log_activity($userId, 'hashtag_set_delete', $set['name']);
    return true;
}
