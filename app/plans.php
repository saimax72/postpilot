<?php
/**
 * Plans, trials and limits.
 *
 * Everything about what a tier costs and allows lives in plans() below — one
 * place to edit prices, limits and the copy shown on the pricing page. Nothing
 * else should hard-code a number; ask plan_limit() or plan_allows() instead.
 *
 * There is no payment processing yet. A user's plan is a column on their row,
 * set by an administrator. Adding a provider later means changing how that
 * column gets set, not how limits are enforced.
 */

define('TRIAL_DAYS', 7);

function plans(): array
{
    return [
        'trial' => [
            'label'  => 'Free trial',
            'price'  => '$0',
            'period' => 'for ' . TRIAL_DAYS . ' days',
            'blurb'  => 'The whole app, for a week. No card needed.',
            'limits' => [
                'posts_per_day' => 10,
                'channels'      => 0,
                'templates'     => 0,
                'hashtag_sets'  => 0,
                'bulk_upload'   => true,
            ],
            'includes' => [
                'Every feature, unlocked',
                '10 scheduled posts a day',
                'All 8 networks, unlimited channels',
                'Bulk upload, templates, hashtag sets',
            ],
            'excludes' => [
                'Ends after ' . TRIAL_DAYS . ' days',
            ],
        ],

        'pro' => [
            'label'  => 'Pro',
            'price'  => '$12',
            'period' => 'per month',
            'blurb'  => 'For anyone posting regularly.',
            'limits' => [
                'posts_per_day' => 0,          // 0 means no limit
                'channels'      => 0,
                'templates'     => 0,
                'hashtag_sets'  => 0,
                'bulk_upload'   => true,
            ],
            'includes' => [
                'Unlimited scheduled posts',
                'Unlimited connected channels',
                'Bulk upload with cadence scheduling',
                'Unlimited templates and hashtag sets',
                'Publish now and automatic retries',
                'Admin control centre',
            ],
            'excludes' => [],
        ],
    ];
}

function plan_key(?array $user = null): string
{
    $user = $user ?: auth_user();
    $key  = $user['plan'] ?? 'trial';
    return isset(plans()[$key]) ? $key : 'trial';
}

function plan(?array $user = null): array
{
    return plans()[plan_key($user)];
}

function is_admin_user(?array $user = null): bool
{
    $user = $user ?: auth_user();
    return ($user['role'] ?? '') === 'admin';
}

/* ---------------- Trial ---------------- */

/** When this user's trial ends, or null if they are not on one. */
function trial_ends(?array $user = null): ?string
{
    $user = $user ?: auth_user();
    if (!$user || plan_key($user) !== 'trial') {
        return null;
    }
    // Falls back to the signup date so an account created before trials
    // existed still gets a sensible window rather than none at all.
    return ($user['trial_ends_at'] ?? null)
        ?: gmdate('Y-m-d H:i:s', strtotime($user['created_at'] . ' UTC') + TRIAL_DAYS * 86400);
}

/** Whole days left, floored at zero. Null when not on a trial. */
function trial_days_left(?array $user = null): ?int
{
    $ends = trial_ends($user);
    if (!$ends) {
        return null;
    }
    return max(0, (int)ceil((strtotime($ends . ' UTC') - time()) / 86400));
}

function trial_expired(?array $user = null): bool
{
    $user = $user ?: auth_user();
    if (is_admin_user($user)) {
        return false;
    }
    $ends = trial_ends($user);
    return $ends !== null && strtotime($ends . ' UTC') < time();
}

/* ---------------- Limits ---------------- */

/** A numeric limit for a user. 0 means unlimited. Administrators are never limited. */
function plan_limit(string $key, ?array $user = null): int
{
    $user = $user ?: auth_user();
    if (is_admin_user($user)) {
        return 0;
    }
    return (int)(plan($user)['limits'][$key] ?? 0);
}

/** A boolean feature gate. Administrators always pass. */
function plan_allows(string $feature, ?array $user = null): bool
{
    $user = $user ?: auth_user();
    if (is_admin_user($user)) {
        return true;
    }
    return (bool)(plan($user)['limits'][$feature] ?? false);
}

/* ---------------- Usage ---------------- */

/**
 * Posts created today, in the user's own timezone.
 *
 * Their day is the one that matters: a limit that resets at midnight UTC would
 * roll over mid-evening for someone in Toronto, which is not what "10 a day"
 * means to them.
 */
function usage_posts_today(int $userId, ?array $user = null): int
{
    $user = $user ?: auth_user();
    $tz   = $user['timezone'] ?? APP_TIMEZONE;

    try {
        $start = (new DateTime('today', new DateTimeZone($tz)))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $start = gmdate('Y-m-d 00:00:00');
    }

    return (int)db_value(
        'SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at >= ?',
        [$userId, $start]
    );
}

function usage_channels(int $userId): int
{
    return (int)db_value('SELECT COUNT(*) FROM social_accounts WHERE user_id = ?', [$userId]);
}

/**
 * Why this user cannot create another post right now, or null if they can.
 * Returns a sentence meant to be shown as-is.
 */
function post_block_reason(int $userId, ?array $user = null): ?string
{
    $user = $user ?: auth_user();

    if (is_admin_user($user)) {
        return null;
    }

    if (trial_expired($user)) {
        return 'Your ' . TRIAL_DAYS . '-day free trial has ended. Upgrade to Pro to keep scheduling.';
    }

    $limit = plan_limit('posts_per_day', $user);
    if ($limit === 0) {
        return null;
    }

    $used = usage_posts_today($userId, $user);
    if ($used < $limit) {
        return null;
    }

    return sprintf(
        'The %s allows %d posts a day and you have created %d today. '
        . 'Upgrade to Pro for unlimited posts, or try again tomorrow.',
        plan($user)['label'], $limit, $used
    );
}

/** Usage lines for the settings page. */
function plan_usage_rows(int $userId, ?array $user = null): array
{
    $user  = $user ?: auth_user();
    $limit = plan_limit('posts_per_day', $user);
    $used  = usage_posts_today($userId, $user);

    return [[
        'label' => 'Posts today',
        'used'  => $used,
        'limit' => $limit,
        'text'  => $limit === 0 ? $used . ' — unlimited' : $used . ' of ' . $limit,
        'full'  => $limit > 0 && $used >= $limit,
    ], [
        'label' => 'Connected channels',
        'used'  => usage_channels($userId),
        'limit' => 0,
        'text'  => usage_channels($userId) . ' — unlimited',
        'full'  => false,
    ]];
}

/**
 * The strip shown across the top of the app while a trial is running.
 *
 * A limit the user cannot see is a limit they only discover by hitting it, so
 * this states both numbers — days left and posts used today — before either
 * runs out. Returns an empty string when there is nothing worth saying.
 */
function trial_banner(?array $user = null): string
{
    $user = $user ?: auth_user();
    if (!$user || is_admin_user($user) || plan_key($user) !== 'trial') {
        return '';
    }

    if (trial_expired($user)) {
        return '<div class="trial-bar is-over">'
             . '<span><strong>Your free trial has ended.</strong> '
             . 'Scheduled posts still publish, but you cannot create new ones.</span>'
             . '<a class="btn btn-sm" href="/pricing.php">Upgrade to Pro</a>'
             . '</div>';
    }

    $days  = trial_days_left($user);
    $limit = plan_limit('posts_per_day', $user);
    $used  = usage_posts_today((int)$user['id'], $user);

    $left  = $days === 1 ? '1 day left' : $days . ' days left';
    $posts = $limit > 0 ? $used . ' of ' . $limit . ' posts today' : '';

    // Only nag once the trial is nearly over or the day is nearly spent.
    $urgent = $days <= 2 || ($limit > 0 && $used >= $limit);

    return '<div class="trial-bar' . ($urgent ? ' is-warn' : '') . '">'
         . '<span><strong>' . e($left) . '</strong> in your free trial'
         . ($posts ? ' &middot; ' . e($posts) : '') . '</span>'
         . '<a class="btn btn-sm btn-ghost" href="/pricing.php">See plans</a>'
         . '</div>';
}

/**
 * Whether migration 005 has been applied.
 *
 * The code is deployed by pushing, but migrations are run by hand afterwards,
 * so there is a window where the column is not there yet. Signup writing to a
 * missing column would be a fatal error, and a paying-nothing user losing the
 * ability to register is a worse outcome than a trial date computed from
 * created_at, which is what trial_ends() falls back to anyway.
 */
function has_trial_column(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db_one("SHOW COLUMNS FROM users LIKE 'trial_ends_at'");
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Where upgrade enquiries go: the first administrator on the installation. */
function owner_email(): string
{
    static $email = null;
    if ($email === null) {
        $email = (string)db_value("SELECT email FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    }
    return $email ?: '';
}
