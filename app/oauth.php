<?php
/**
 * Meta OAuth — connecting Facebook Pages and Instagram accounts by clicking a
 * button instead of pasting an access token.
 *
 * The important difference from the manual flow: the developer app belongs to
 * whoever runs this installation, not to each user. A user approves it on
 * Facebook's own screen and never sees a token at all.
 *
 * Facebook and Instagram genuinely share one app and one authorisation, so both
 * are discovered in a single pass. Threads does not — it authorises against
 * threads.net with its own credentials — so it is a separate provider and stays
 * on the manual flow until that is built.
 *
 * Nothing here is reachable unless META_APP_ID and META_APP_SECRET are set in
 * app/config.php, so an installation that has not configured an app simply does
 * not show the button.
 */

const GRAPH_VERSION = 'v21.0';
const GRAPH_HOST    = 'https://graph.facebook.com/' . GRAPH_VERSION;

/** True when this installation has a Meta app configured. */
function oauth_meta_ready(): bool
{
    return defined('META_APP_ID') && defined('META_APP_SECRET')
        && META_APP_ID !== '' && META_APP_SECRET !== ''
        && !str_starts_with((string)META_APP_ID, 'YOUR_');
}

/** The redirect URI. Must match the one registered on the Meta app exactly. */
function oauth_redirect_uri(): string
{
    return rtrim(APP_URL, '/') . '/oauth.php';
}

/**
 * Permissions we ask for.
 *
 * Kept to the minimum that lets the drivers in app/publisher.php work, because
 * every extra permission is another thing Meta's App Review will ask about.
 */
function oauth_meta_scopes(): array
{
    return [
        'pages_show_list',            // list the Pages you administer
        'pages_manage_posts',         // publish to them
        'pages_read_engagement',      // required alongside manage_posts
        'instagram_basic',            // see the linked Instagram account
        'instagram_content_publish',  // publish to it
    ];
}

/** Where to send the browser to start authorisation. */
function oauth_meta_start_url(string $state): string
{
    return 'https://www.facebook.com/' . GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
        'client_id'     => META_APP_ID,
        'redirect_uri'  => oauth_redirect_uri(),
        'state'         => $state,
        'response_type' => 'code',
        'scope'         => implode(',', oauth_meta_scopes()),
    ]);
}

/**
 * A one-time value tying the callback to this browser session.
 *
 * Without it, someone could hand the user a crafted callback URL and attach
 * their own Facebook account to the user's PostPilot login.
 */
function oauth_new_state(): string
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_state_at'] = time();
    return $state;
}

function oauth_check_state(?string $given): bool
{
    $known = $_SESSION['oauth_state'] ?? null;
    $when  = (int)($_SESSION['oauth_state_at'] ?? 0);
    unset($_SESSION['oauth_state'], $_SESSION['oauth_state_at']);

    return $known !== null && $given !== null
        && hash_equals($known, $given)
        && (time() - $when) < 900;          // 15 minutes is plenty
}

/* ---------------- Token exchange ---------------- */

/**
 * Swap the callback code for a long-lived user token.
 *
 * Returns [token, error]. The short-lived token lasts about an hour, which is
 * useless for scheduling, so the second call is not optional.
 */
function oauth_meta_token(string $code): array
{
    $short = http_get(GRAPH_HOST . '/oauth/access_token', [
        'client_id'     => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri'  => oauth_redirect_uri(),
        'code'          => $code,
    ]);

    if (empty($short['access_token'])) {
        return [null, graph_error($short)];
    }

    $long = http_get(GRAPH_HOST . '/oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => META_APP_ID,
        'client_secret'     => META_APP_SECRET,
        'fb_exchange_token' => $short['access_token'],
    ]);

    if (empty($long['access_token'])) {
        return [null, graph_error($long)];
    }

    return [$long['access_token'], null];
}

/**
 * Everything this user can post to, given a long-lived user token.
 *
 * Each Page carries its own access token. A Page token derived from a
 * long-lived user token does not expire, which is exactly what scheduling
 * needs — so it is the Page token we store, never the user token.
 *
 * Returns [candidates, error].
 */
function oauth_meta_discover(string $userToken): array
{
    $res = http_get(GRAPH_HOST . '/me/accounts', [
        'access_token' => $userToken,
        'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
        'limit'        => 100,
    ]);

    if (isset($res['error'])) {
        return [[], graph_error($res)];
    }

    $found = [];
    foreach ($res['data'] ?? [] as $page) {
        if (empty($page['access_token'])) {
            continue;                       // not an admin of this one
        }

        $found[] = [
            'platform'    => 'facebook',
            'external_id' => (string)$page['id'],
            'name'        => (string)($page['name'] ?? 'Facebook Page'),
            'handle'      => null,
            'token'       => (string)$page['access_token'],
            'note'        => 'Facebook Page',
        ];

        // The Instagram account, if one is linked, publishes with the same
        // Page token — that link is the whole reason Instagram needs a Page.
        if (!empty($page['instagram_business_account']['id'])) {
            $ig = $page['instagram_business_account'];
            $found[] = [
                'platform'    => 'instagram',
                'external_id' => (string)$ig['id'],
                'name'        => (string)($ig['username'] ?? $page['name'] ?? 'Instagram'),
                'handle'      => !empty($ig['username']) ? '@' . $ig['username'] : null,
                'token'       => (string)$page['access_token'],
                'note'        => 'Instagram, via ' . ($page['name'] ?? 'your Page'),
            ];
        }
    }

    if (!$found) {
        return [[], 'No Facebook Pages came back. PostPilot can only post to a Page you '
                  . 'administer — a personal profile will not work.'];
    }

    return [$found, null];
}

/* ---------------- Saving ---------------- */

/**
 * Connect one discovered account, or update it if it is already here.
 *
 * Reconnecting is the normal way to replace an expired token, so matching on
 * (user, platform, external_id) and updating in place keeps the account's
 * scheduled posts attached to it rather than orphaning them behind a duplicate.
 */
function oauth_save_account(int $userId, array $c): string
{
    $existing = db_one(
        'SELECT id FROM social_accounts WHERE user_id = ? AND platform = ? AND external_id = ?',
        [$userId, $c['platform'], $c['external_id']]
    );

    if ($existing) {
        db_run(
            'UPDATE social_accounts
                SET access_token = ?, display_name = ?, handle = ?, status = ?
              WHERE id = ? AND user_id = ?',
            [encrypt_secret($c['token']), $c['name'], $c['handle'], 'connected',
             $existing['id'], $userId]
        );
        log_activity($userId, 'account_reconnect',
            platform_label($c['platform']) . ' - ' . $c['name']);
        return 'reconnected';
    }

    $id = account_connect($userId, $c['platform'], $c['name'], $c['handle'], $c['token']);
    db_run('UPDATE social_accounts SET external_id = ? WHERE id = ? AND user_id = ?',
        [$c['external_id'], $id, $userId]);

    return 'connected';
}
