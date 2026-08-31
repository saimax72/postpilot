<?php
/**
 * Post templates.
 *
 * A template is everything about a post except the image and the moment it
 * goes out: caption, hashtags, link, target accounts, image ratio, alt text
 * and first comment. Apply one, drop in a photo, pick a slot, schedule.
 */

function templates_for_user(int $userId): array
{
    try {
        $rows = db_all('SELECT * FROM post_templates WHERE user_id = ? ORDER BY name', [$userId]);
    } catch (PDOException $e) {
        // Table not migrated yet. Templates are optional, so degrade to none
        // rather than taking down every page that embeds the composer.
        error_log('PostPilot: post_templates unavailable - ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$r) {
        $r['account_list'] = $r['account_ids']
            ? array_map('intval', array_filter(explode(',', $r['account_ids'])))
            : [];
        $r['tag_count'] = count(extract_hashtags((string)$r['content']))
                        + count(extract_hashtags((string)$r['first_comment']));
    }
    unset($r);
    return $rows;
}

function template_find(int $id, int $userId): ?array
{
    return db_one('SELECT * FROM post_templates WHERE id = ? AND user_id = ?', [$id, $userId]);
}

/**
 * Create or update a template. Returns [ok, id|errorMessage].
 * $accountIds is filtered down to accounts this user actually owns.
 */
function template_save(int $userId, ?int $id, string $name, array $fields): array
{
    $name = trim($name);
    if ($name === '') {
        return [false, 'Give the template a name — something like "FanExpo" works.'];
    }
    if (mb_strlen($name) > 80) {
        return [false, 'That name is too long — 80 characters at most.'];
    }

    $content = trim((string)($fields['content'] ?? ''));
    $link    = trim((string)($fields['link_url'] ?? '')) ?: null;

    if ($content === '' && !$link) {
        return [false, 'A template needs at least a caption or a link.'];
    }
    if ($link && !filter_var($link, FILTER_VALIDATE_URL)) {
        return [false, 'That link does not look like a valid URL.'];
    }

    $ratio = (string)($fields['media_ratio'] ?? 'square');
    if (!media_ratio($ratio)) {
        $ratio = 'square';
    }

    $owned = [];
    foreach ((array)($fields['account_ids'] ?? []) as $aid) {
        if (account_find((int)$aid, $userId)) {
            $owned[] = (int)$aid;
        }
    }

    $clash = db_value(
        'SELECT id FROM post_templates WHERE user_id = ? AND name = ? AND id <> ?',
        [$userId, $name, $id ?: 0]
    );
    if ($clash) {
        return [false, 'You already have a template called "' . $name . '".'];
    }

    $params = [
        $name,
        $content ?: null,
        $link,
        $ratio,
        mb_substr(trim((string)($fields['alt_text'] ?? '')), 0, 400) ?: null,
        mb_substr(trim((string)($fields['first_comment'] ?? '')), 0, 600) ?: null,
        $owned ? implode(',', $owned) : null,
    ];

    if ($id) {
        db_run(
            'UPDATE post_templates
                SET name=?, content=?, link_url=?, media_ratio=?, alt_text=?, first_comment=?, account_ids=?
              WHERE id=? AND user_id=?',
            array_merge($params, [$id, $userId])
        );
        log_activity($userId, 'template_update', $name);
        return [true, $id];
    }

    db_run(
        'INSERT INTO post_templates (name, content, link_url, media_ratio, alt_text, first_comment, account_ids, user_id)
         VALUES (?,?,?,?,?,?,?,?)',
        array_merge($params, [$userId])
    );
    $newId = (int)db()->lastInsertId();
    log_activity($userId, 'template_create', $name);
    return [true, $newId];
}

function template_delete(int $id, int $userId): bool
{
    $tpl = template_find($id, $userId);
    if (!$tpl) {
        return false;
    }
    db_run('DELETE FROM post_templates WHERE id = ? AND user_id = ?', [$id, $userId]);
    log_activity($userId, 'template_delete', $tpl['name']);
    return true;
}

/** Bumped every time a template is used to build a post. */
function template_used(int $id, int $userId): void
{
    db_run('UPDATE post_templates SET use_count = use_count + 1 WHERE id = ? AND user_id = ?', [$id, $userId]);
}
