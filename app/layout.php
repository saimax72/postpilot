<?php
/**
 * Shared chrome for the signed-in app (sidebar + topbar).
 * Usage:  layout_head('Calendar'); ... layout_foot();
 */

function icon(string $name, int $size = 18): string
{
    $p = [
        'calendar'  => '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.5.55.88 1.06 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'chevron-l' => '<path d="M15 18l-6-6 6-6"/>',
        'chevron-r' => '<path d="M9 18l6-6-6-6"/>',
        'send'      => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'trash'     => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'zap'       => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
        'sparkle'   => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
        'back'      => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($p[$name] ?? '') . '</svg>';
}

/**
 * The brand logo, in both inks.
 *
 * The wordmark exists as dark-on-light and light-on-dark artwork; which one is
 * correct depends on the surface, not the page. Both are emitted and CSS picks,
 * so the sidebar (always dark) and the light pages can differ from each other
 * while still following the theme toggle.
 */
function brand_logo(): string
{
    return '<span class="brand-logo-set">'
         . '<img class="brand-logo on-light" src="' . asset('/assets/img/logo-on-light.png')
         . '" alt="' . e(APP_NAME) . '">'
         . '<img class="brand-logo on-dark" src="' . asset('/assets/img/logo-on-dark.png')
         . '" alt="" aria-hidden="true">'
         . '</span>';
}

function nav_link(string $href, string $iconName, string $label, string $current, ?int $count = null): string
{
    $active = basename(parse_url($href, PHP_URL_PATH)) === $current ? ' active' : '';
    $badge  = $count !== null ? '<span class="nav-count">' . (int)$count . '</span>' : '';
    return '<a class="nav-item' . $active . '" href="' . e($href) . '">' . icon($iconName) . '<span>' . e($label) . '</span>' . $badge . '</a>';
}

function layout_head(string $title, string $heading = '', string $actions = ''): void
{
    $user    = auth_user();
    $current = basename($_SERVER['PHP_SELF']);
    $stats   = $user ? post_stats((int)$user['id']) : [];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<link rel="icon" type="image/png" href="/assets/img/mark.png">
<script>
  (function () {
    var t = localStorage.getItem('pp-theme');
    if (t) document.documentElement.setAttribute('data-theme', t);
  })();
</script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/dashboard.php">
      <?= brand_logo() ?>
    </a>

    <?= nav_link('/dashboard.php', 'calendar', 'Calendar',  $current) ?>
    <?= nav_link('/queue.php',     'list',     'Queue',     $current, $stats['scheduled'] ?? null) ?>
    <?= nav_link('/grid.php',      'grid',     'Grid preview', $current) ?>
    <?= nav_link('/bulk.php',      'zap',      'Bulk upload', $current) ?>
    <?= nav_link('/accounts.php',  'link',     'Accounts',  $current, $stats['accounts'] ?? null) ?>

    <div class="nav-label">Workspace</div>
    <?= nav_link('/templates.php', 'grid',    'Templates',    $current) ?>
    <?= nav_link('/hashtags.php', 'list',     'Hashtag sets', $current) ?>
    <?= nav_link('/settings.php',  'settings', 'Settings',  $current) ?>

    <?php if (is_admin()): ?>
      <div class="nav-label">Administration</div>
      <?= nav_link('/admin/index.php', 'shield', 'Admin overview', $current) ?>
      <?= nav_link('/admin/users.php', 'users',  'All users',      $current) ?>
      <?= nav_link('/admin/posts.php', 'chart',  'All posts',      $current) ?>
      <?= nav_link('/admin/activity.php', 'clock', 'Activity log',  $current) ?>
    <?php endif; ?>

    <div class="sidebar-foot">
      <div class="row" style="padding:6px 8px 12px">
        <span class="avatar" style="background:<?= e($user['avatar_color'] ?? '#2563eb') ?>"><?= e(initials($user['name'] ?? '?')) ?></span>
        <span class="grow" style="min-width:0">
          <div style="font-weight:600;font-size:.8125rem;overflow:hidden;text-overflow:ellipsis"><?= e($user['name'] ?? '') ?></div>
          <div class="tiny muted" style="overflow:hidden;text-overflow:ellipsis"><?= e($user['email'] ?? '') ?></div>
        </span>
      </div>
      <button class="nav-item" style="width:100%;border:0;background:none;cursor:pointer;font:inherit" onclick="toggleTheme()">
        <?= icon('moon') ?><span>Theme</span>
      </button>
      <a class="nav-item" href="/logout.php"><?= icon('logout') ?><span>Sign out</span></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="row">
        <button class="btn btn-ghost btn-icon menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu"><?= icon('menu') ?></button>
        <h1><?= e($heading !== '' ? $heading : $title) ?></h1>
      </div>
      <div class="row">
        <?php if ($user): ?>
          <span class="clock" id="now-clock" data-tz="<?= e($user['timezone']) ?>"
                title="Current time in your workspace timezone">
            <span class="clock-time">--:--</span>
            <span class="clock-zone"><?= e(str_replace('_', ' ', $user['timezone'])) ?></span>
          </span>
        <?php endif; ?>
        <?= $actions ?>
      </div>
    </header>

    <main class="page">
      <?= trial_banner($user) ?>
      <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
    <?php
}

function layout_foot(string $extraJs = ''): void
{
    ?>
    </main>
  </div>
</div>
<script>
/**
 * Workspace clock. Formatted in the user's timezone rather than the browser's,
 * so the time here always matches the times on the calendar - which is the
 * whole point of showing it.
 */
(function () {
  var el = document.getElementById('now-clock');
  if (!el) return;

  var tz = el.dataset.tz || 'UTC';
  var fmt;
  try {
    fmt = new Intl.DateTimeFormat('en-GB', {
      timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    });
  } catch (e) {
    fmt = new Intl.DateTimeFormat('en-GB', {
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    });
  }

  var out = el.querySelector('.clock-time');
  function tick() { out.textContent = fmt.format(new Date()); }
  tick();
  setInterval(tick, 1000);
})();

function toggleTheme() {
  var el = document.documentElement;
  var next = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  el.setAttribute('data-theme', next);
  localStorage.setItem('pp-theme', next);
}
</script>
<?= $extraJs ?>
</body>
</html>
    <?php
}
