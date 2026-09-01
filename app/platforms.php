<?php
/**
 * Platform registry. Everything the UI and the publisher need to know about
 * each network lives here — add a new network by adding one entry plus a
 * driver method in publisher.php.
 */
function platforms(): array
{
    return [
        'facebook' => [
            'live'       => true,
            'label'      => 'Facebook',
            'color'      => '#1877F2',
            'limit'      => 63206,
            'media'      => true,
            'docs'       => 'https://developers.facebook.com/docs/pages-api',
            'oauth_note' => 'Meta Graph API — needs a Facebook App with pages_manage_posts.',
        ],
        'instagram' => [
            'live'       => true,
            'label'      => 'Instagram',
            'color'      => '#E1306C',
            'limit'      => 2200,
            'media'      => true,
            'media_required' => true,
            'docs'       => 'https://developers.facebook.com/docs/instagram-api',
            'oauth_note' => 'Instagram Graph API — business account linked to a Facebook Page.',
        ],
        'x' => [
            'live'       => true,
            'label'      => 'X (Twitter)',
            'color'      => '#0F172A',
            'limit'      => 280,
            'media'      => true,
            'docs'       => 'https://developer.x.com/en/docs/x-api',
            'oauth_note' => 'X API v2 — OAuth 2.0 with tweet.write scope.',
        ],
        'linkedin' => [
            'live'       => true,
            'label'      => 'LinkedIn',
            'color'      => '#0A66C2',
            'limit'      => 3000,
            'media'      => true,
            'docs'       => 'https://learn.microsoft.com/linkedin/marketing/',
            'oauth_note' => 'LinkedIn Marketing API — w_member_social scope.',
        ],
        'threads' => [
            'live'       => true,
            'label'      => 'Threads',
            'color'      => '#000000',
            'limit'      => 500,
            'media'      => true,
            'docs'       => 'https://developers.facebook.com/docs/threads',
            'oauth_note' => 'Threads API — same Meta app as Instagram.',
        ],
        'tiktok' => [
            'live'       => false,
            'label'      => 'TikTok',
            'color'      => '#00F2EA',
            'limit'      => 2200,
            'media'      => true,
            'media_required' => true,
            'docs'       => 'https://developers.tiktok.com/doc/content-posting-api-get-started',
            'oauth_note' => 'TikTok Content Posting API — video.publish scope.',
        ],
        'youtube' => [
            'live'       => false,
            'label'      => 'YouTube',
            'color'      => '#FF0000',
            'limit'      => 5000,
            'media'      => true,
            'media_required' => true,
            'docs'       => 'https://developers.google.com/youtube/v3/docs/videos/insert',
            'oauth_note' => 'YouTube Data API v3 — youtube.upload scope.',
        ],
        'pinterest' => [
            'live'       => false,
            'label'      => 'Pinterest',
            'color'      => '#E60023',
            'limit'      => 500,
            'media'      => true,
            'media_required' => true,
            'docs'       => 'https://developers.pinterest.com/docs/api/v5/',
            'oauth_note' => 'Pinterest API v5 — pins:write scope.',
        ],
    ];
}

function platform(string $key): ?array
{
    return platforms()[$key] ?? null;
}

/**
 * Whether this network has a publishing driver in app/publisher.php.
 *
 * A network without one still connects and schedules, then fails at send time,
 * so the interface says so up front instead of letting someone plan a month
 * into a dead end. Add a driver, flip the flag.
 */
function platform_live(string $key): bool
{
    return (bool)(platforms()[$key]['live'] ?? false);
}

/** Networks that can actually publish. */
function platforms_live(): array
{
    return array_filter(platforms(), fn($p) => !empty($p['live']));
}

function platform_label(string $key): string
{
    return platforms()[$key]['label'] ?? ucfirst($key);
}

function platform_color(string $key): string
{
    return platforms()[$key]['color'] ?? '#64748b';
}

function platform_limit(string $key): int
{
    return platforms()[$key]['limit'] ?? 2000;
}

/** Inline SVG glyph for a platform, sized by the caller's CSS. */
function platform_icon(string $key, int $size = 16): string
{
    $paths = [
        'facebook'  => '<path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/>',
        'instagram' => '<path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.3-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2zm0 3.2A6.6 6.6 0 1 0 18.6 12 6.6 6.6 0 0 0 12 5.4zm0 10.9A4.3 4.3 0 1 1 16.3 12 4.3 4.3 0 0 1 12 16.3zm6.9-11.1a1.5 1.5 0 1 1-1.5-1.5 1.5 1.5 0 0 1 1.5 1.5z"/>',
        'x'         => '<path d="M17.5 3h3.2l-7 8 8.2 10h-6.4l-5-6.1-5.8 6.1H1.5l7.5-8.6L1.2 3h6.6l4.5 5.6L17.5 3zm-1.1 16.1h1.8L7.7 4.8H5.8l10.6 14.3z"/>',
        'linkedin'  => '<path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM10 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V21h-4v-5.4c0-1.3 0-3-1.83-3s-2.1 1.42-2.1 2.9V21H10z"/>',
        'threads'   => '<path d="M12 2C6.5 2 3 5.6 3 12s3.5 10 9 10c4.2 0 6.9-2.1 7.6-5.2.5-2.4-.6-4.5-2.9-5.5-.3-2.6-2-4.1-4.7-4.1-1.7 0-3.1.7-3.9 2l1.6 1.1c.5-.8 1.3-1.1 2.3-1.1 1.4 0 2.3.7 2.6 2-.7-.2-1.5-.2-2.3-.2-2.7.2-4.4 1.8-4.3 4 .1 2 1.8 3.4 4.1 3.3 2.3-.1 3.8-1.5 4.3-3.9 1 .6 1.4 1.6 1.1 2.8-.4 1.9-2.3 3.4-5.5 3.4-4.3 0-7-2.8-7-8s2.7-8 7-8c3.4 0 5.8 1.7 6.6 4.7l1.9-.5C19.6 4.2 16.5 2 12 2zm.4 14.1c-1.1.1-2-.4-2.1-1.3-.1-.8.6-1.5 2.2-1.6.6 0 1.2 0 1.8.1-.3 2-1.1 2.7-1.9 2.8z"/>',
        'tiktok'    => '<path d="M16.6 5.8a4.9 4.9 0 0 1-1.1-2.8h-3v12.3a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V9.7a5.7 5.7 0 1 0 4.87 5.6V9.05a8 8 0 0 0 4.63 1.48V7.5a4.85 4.85 0 0 1-3.58-1.7z"/>',
        'youtube'   => '<path d="M23 12s0-3.4-.4-5a2.5 2.5 0 0 0-1.8-1.8C19.2 4.7 12 4.7 12 4.7s-7.2 0-8.8.5A2.5 2.5 0 0 0 1.4 7C1 8.6 1 12 1 12s0 3.4.4 5a2.5 2.5 0 0 0 1.8 1.8c1.6.5 8.8.5 8.8.5s7.2 0 8.8-.5a2.5 2.5 0 0 0 1.8-1.8c.4-1.6.4-5 .4-5zM9.7 15.4V8.6l6 3.4z"/>',
        'pinterest' => '<path d="M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5.1s-.3-.6-.3-1.5c0-1.4.8-2.5 1.9-2.5.9 0 1.3.7 1.3 1.5 0 .9-.6 2.2-.9 3.5-.3 1 .5 1.9 1.6 1.9 1.9 0 3.2-2.4 3.2-5.3 0-2.2-1.5-3.8-4.2-3.8-3 0-4.9 2.3-4.9 4.8 0 .9.3 1.5.7 2 .2.2.2.3.1.6l-.2.9c-.1.3-.3.4-.5.3-1.4-.6-2.1-2.2-2.1-4 0-3 2.5-6.5 7.4-6.5 4 0 6.6 2.9 6.6 6 0 4-2.2 7-5.5 7-1.1 0-2.2-.6-2.5-1.3l-.7 2.7c-.3 1-.9 2-1.4 2.7A10 10 0 1 0 12 2z"/>',
    ];
    $path = $paths[$key] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
}
