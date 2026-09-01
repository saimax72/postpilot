<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];
$tz   = new DateTimeZone(user_tz());

$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['month', 'week', 'list'], true)) {
    $view = 'month';
}
$today = new DateTime('now', $tz);

// ---- Work out the visible window in the user's own time zone ----
if ($view === 'week') {
    $anchor = DateTime::createFromFormat('Y-m-d', $_GET['d'] ?? '', $tz) ?: clone $today;
    $anchor->setTime(0, 0);
    $gridStart = (clone $anchor)->modify('monday this week');
    $days      = 7;
    $title     = $gridStart->format('j M') . ' – ' . (clone $gridStart)->modify('+6 days')->format('j M Y');
    $prev      = (clone $gridStart)->modify('-7 days')->format('Y-m-d');
    $next      = (clone $gridStart)->modify('+7 days')->format('Y-m-d');
} else {
    // Month and list share a window so the prev/next controls behave the same.
    $anchor = DateTime::createFromFormat('Y-m-d', ($_GET['m'] ?? $today->format('Y-m')) . '-01', $tz) ?: clone $today;
    $anchor->setTime(0, 0);
    $monthStart = (clone $anchor)->modify('first day of this month');
    $gridStart  = (clone $monthStart)->modify('monday this week');
    // Cover the whole month: 5 or 6 rows depending on where it starts.
    $monthEnd   = (clone $monthStart)->modify('last day of this month');
    $days       = (int)ceil(((int)$gridStart->diff((clone $monthEnd)->modify('+1 day'))->days) / 7) * 7;
    $title      = $monthStart->format('F Y');
    $prev       = (clone $monthStart)->modify('-1 month')->format('Y-m');
    $next       = (clone $monthStart)->modify('+1 month')->format('Y-m');
}

$gridEnd = (clone $gridStart)->modify('+' . $days . ' days');

$fromUtc = (clone $gridStart)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtc   = (clone $gridEnd)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$posts    = posts_in_range($uid, $fromUtc, $toUtc);
$accounts = accounts_for_user($uid);
$stats    = post_stats($uid);

// ---- Bucket posts by their local date ----
$byDay = [];
foreach ($posts as $p) {
    $local = new DateTime($p['scheduled_at'], new DateTimeZone('UTC'));
    $local->setTimezone($tz);
    $byDay[$local->format('Y-m-d')][] = $p + ['local' => $local];
}

$actions = '<a class="btn btn-ghost btn-sm" href="/bulk.php">' . icon('zap', 15) . ' Bulk</a>'
         . '<a class="btn btn-ghost btn-sm" href="/queue.php">' . icon('list', 15) . ' Queue</a>'
         . '<button class="btn" onclick="Composer.open()">' . icon('plus', 16) . ' New post</button>';

layout_head('Calendar', 'Calendar', $actions);

echo page_banner('banner-01-calendar-platforms');
?>

<?php if (!$accounts): ?>
  <div class="alert alert-info">
    <?= icon('link', 18) ?>
    <span><strong>Connect your first account.</strong> Posts need somewhere to go —
      <a href="/accounts.php">add a channel</a> and it becomes a checkbox in the composer.</span>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">          <div class="k">Scheduled</div><div class="v"><?= $stats['scheduled'] ?></div></div>
  <div class="stat s-green">
    <div class="k">Published</div>
    <div class="v"><?= $stats['published'] ?></div>
    <?php if (!empty($stats['demo'])): ?>
      <div class="stat-note" title="Marked published before the account had credentials, so nothing was sent.">
        + <?= (int)$stats['demo'] ?> demo only
      </div>
    <?php endif; ?>
  </div>
  <div class="stat s-yellow"> <div class="k">Drafts</div>   <div class="v"><?= $stats['drafts'] ?></div></div>
  <div class="stat s-red">    <div class="k">Failed</div>   <div class="v"><?= $stats['failed'] ?></div></div>
  <div class="stat s-pink">   <div class="k">Channels</div> <div class="v"><?= $stats['accounts'] ?></div></div>
</div>

<div class="cal-toolbar">
  <div class="row">
    <a class="btn btn-ghost btn-icon" href="?view=<?= $view ?>&amp;<?= $view === 'week' ? 'd' : 'm' ?>=<?= e($prev) ?>" aria-label="Previous"><?= icon('chevron-l', 16) ?></a>
    <a class="btn btn-ghost btn-icon" href="?view=<?= $view ?>&amp;<?= $view === 'week' ? 'd' : 'm' ?>=<?= e($next) ?>" aria-label="Next"><?= icon('chevron-r', 16) ?></a>
    <a class="btn btn-ghost btn-sm" href="?view=<?= $view ?>">Today</a>
    <h2 class="cal-title"><?= e($title) ?></h2>
  </div>
  <?php if ($view !== 'list'): ?>
    <span class="seg" id="cal-size">
      <button type="button" data-size="sm">Compact</button>
      <button type="button" data-size="lg">Large</button>
    </span>
  <?php endif; ?>

  <span class="seg">
    <a class="<?= $view === 'month' ? 'on' : '' ?>" href="?view=month&amp;m=<?= e($anchor->format('Y-m')) ?>">Month</a>
    <a class="<?= $view === 'week'  ? 'on' : '' ?>" href="?view=week">Week</a>
    <a class="<?= $view === 'list'  ? 'on' : '' ?>" href="?view=list&amp;m=<?= e($anchor->format('Y-m')) ?>">List</a>
  </span>
</div>

<?php
/** Render one post chip. */
function ev_chip(array $p): string
{
    $out = '<button type="button" class="ev st-' . e($p['status']) . '" draggable="true"'
         . ' data-post="' . (int)$p['id'] . '" onclick="Composer.open(' . (int)$p['id'] . ')"'
         . ' title="' . e($p['local']->format('H:i') . ' — ' . str_limit($p['content'] ?: 'Media post', 90)) . '">';

    // The thumbnail is the cropped derivative, so the calendar shows exactly
    // what will be posted rather than the original upload.
    if ($p['media_path']) {
        $src = e(media_url($p['media_path']));
        $out .= is_video($p['media_path'])
            ? '<video class="ev-thumb" src="' . $src . '" muted playsinline preload="metadata"></video>'
            : '<img class="ev-thumb" src="' . $src . '" alt="" loading="lazy">';
    } else {
        $out .= '<span class="ev-thumb ev-thumb-empty">' . icon('image', 13) . '</span>';
    }

    $out .= '<span class="ev-main">';
    $out .= '<span class="ev-time">' . $p['local']->format('H:i')
          . ' <span class="ev-eta" data-at="' . e(gmdate('c', strtotime($p['scheduled_at'] . ' UTC')))
          . '" data-status="' . e($p['status']) . '"></span></span>';
    $out .= '<span class="ev-text">' . e(str_limit($p['content'] ?: 'Media post', 40)) . '</span>';
    $out .= '<span class="ev-icons">';
    foreach (array_unique(array_column($p['targets'], 'platform')) as $plat) {
        $out .= '<span class="pdot pdot-sm" style="background:' . e(platform_color($plat)) . '" title="' . e(platform_label($plat)) . '">'
              . platform_icon($plat, 10) . '</span>';
    }
    $out .= '</span></span></button>';
    return $out;
}

$todayKey = $today->format('Y-m-d');
?>

<?php if ($view === 'month'): ?>

  <div class="cal">
    <div class="cal-dow">
      <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?><div><?= $d ?></div><?php endforeach; ?>
    </div>
    <div class="cal-grid" id="cal-grid">
      <?php
      $cursor = clone $gridStart;
      for ($i = 0; $i < $days; $i++):
          $key   = $cursor->format('Y-m-d');
          $out   = $cursor->format('n') !== $anchor->format('n');
          $items = $byDay[$key] ?? [];
      ?>
        <div class="cal-cell<?= $out ? ' out' : '' ?><?= $key === $todayKey ? ' today' : '' ?>" data-date="<?= $key ?>">
          <span class="daynum"><?= $cursor->format('j') ?></span>
          <button class="add-slot" onclick="Composer.open(null, '<?= $key ?>')" aria-label="Add post on <?= $key ?>">+</button>
          <?php foreach (array_slice($items, 0, 3) as $p) echo ev_chip($p); ?>
          <?php if (count($items) > 3): ?>
            <a class="ev-more" href="/queue.php?date=<?= $key ?>">+<?= count($items) - 3 ?> more</a>
          <?php endif; ?>
        </div>
      <?php
          $cursor->modify('+1 day');
      endfor;
      ?>
    </div>
  </div>

<?php elseif ($view === 'list'): ?>

  <?php if (!$byDay): ?>
    <div class="card card-pad center" style="padding:56px 24px">
      <div class="empty-badge"><?= icon('list', 26) ?></div>
      <h3>Nothing scheduled in <?= e($anchor->format('F Y')) ?></h3>
      <p class="muted">Use the arrows above to look at another month, or write a post.</p>
      <button class="btn" onclick="Composer.open()"><?= icon('plus', 16) ?> New post</button>
    </div>
  <?php else: ?>
    <div class="stack" style="gap:20px">
      <?php
      ksort($byDay);
      foreach ($byDay as $key => $items):
          $d = new DateTime($key, $tz);
          $isToday = $key === $today->format('Y-m-d');
      ?>
        <div class="card">
          <div class="card-head">
            <div class="row">
              <h3 style="margin:0"><?= e($d->format('l j F')) ?></h3>
              <?php if ($isToday): ?><span class="badge badge-scheduled">today</span><?php endif; ?>
              <span class="badge"><?= count($items) ?> post<?= count($items) === 1 ? '' : 's' ?></span>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="Composer.open(null, '<?= $key ?>')">
              <?= icon('plus', 14) ?> Add to this day
            </button>
          </div>

          <?php foreach ($items as $p):
              $tags = extract_hashtags((string)$p['content']);
              $ratio = $p['media_ratio'] ? (media_ratio($p['media_ratio'])['label'] ?? null) : null;
          ?>
            <div class="lv-row" onclick="Composer.open(<?= (int)$p['id'] ?>)">
              <?php if ($p['media_path']): ?>
                <?php if (is_video($p['media_path'])): ?>
                  <video class="lv-thumb" style="<?= e(thumb_style($p['media_ratio'] ?? null)) ?>" src="<?= e(media_url($p['media_path'])) ?>" muted playsinline preload="metadata"></video>
                <?php else: ?>
                  <img class="lv-thumb" style="<?= e(thumb_style($p['media_ratio'] ?? null)) ?>" src="<?= e(media_url($p['media_path'])) ?>" alt="" loading="lazy">
                <?php endif; ?>
              <?php else: ?>
                <span class="lv-thumb lv-thumb-empty"><?= icon('image', 20) ?></span>
              <?php endif; ?>

              <div class="lv-when">
                <div class="lv-time"><?= e($p['local']->format('H:i')) ?></div>
                <div class="lv-eta" data-at="<?= e(gmdate('c', strtotime($p['scheduled_at'] . ' UTC'))) ?>"
                     data-status="<?= e($p['status']) ?>"></div>
              </div>

              <div class="lv-body">
                <p class="lv-caption"><?= e(str_limit($p['content'] ?: 'Media post', 180)) ?></p>

                <div class="lv-meta">
                  <?php foreach ($p['targets'] as $t): ?>
                    <span class="chip">
                      <span class="pdot pdot-sm" style="background:<?= e(platform_color($t['platform'])) ?>">
                        <?= platform_icon($t['platform'], 10) ?>
                      </span><?= e($t['display_name'] ?: platform_label($t['platform'])) ?>
                    </span>
                  <?php endforeach; ?>

                  <?php if ($ratio): ?><span class="chip"><?= e($ratio) ?></span><?php endif; ?>
                  <?php if ($tags): ?><span class="chip"><?= count($tags) ?> hashtags</span><?php endif; ?>
                  <?php if ($p['link_url']): ?><span class="chip"><?= icon('link', 11) ?> link</span><?php endif; ?>
                  <?php if (!empty($p['first_comment'])): ?><span class="chip">first comment</span><?php endif; ?>
                  <?php if (!empty($p['alt_text'])): ?><span class="chip">alt text</span><?php endif; ?>
                </div>

                <?php if ($p['last_error']): ?>
                  <div class="tiny" style="color:var(--red);margin-top:6px">
                    <?= $p['status'] === 'failed' ? 'Failed' : 'Retrying' ?>
                    (attempt <?= (int)$p['attempts'] ?>): <?= e(str_limit($p['last_error'], 180)) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="lv-side">
                <span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span>
          <?php if ($p['status'] === 'published' && post_was_demo($p)): ?>
            <span class="badge badge-draft" title="Recorded as published, but the account had no credentials at the time so nothing was sent.">demo only</span>
          <?php endif; ?>
                <?php if ($p['status'] === 'published' && $p['published_at']): ?>
                  <span class="tiny muted"><?= e(time_ago($p['published_at'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <div class="week-grid" id="cal-grid">
    <?php
    $cursor = clone $gridStart;
    for ($i = 0; $i < 7; $i++):
        $key   = $cursor->format('Y-m-d');
        $items = $byDay[$key] ?? [];
    ?>
      <div class="week-col cal-cell<?= $key === $todayKey ? ' today' : '' ?>" data-date="<?= $key ?>" style="min-height:260px">
        <h4><?= $cursor->format('D j M') ?></h4>
        <?php foreach ($items as $p) echo ev_chip($p); ?>
        <button class="btn btn-ghost btn-sm" style="margin-top:auto" onclick="Composer.open(null, '<?= $key ?>')">+ Add</button>
      </div>
    <?php
        $cursor->modify('+1 day');
    endfor;
    ?>
  </div>

<?php endif; ?>

<p class="small muted" style="margin-top:16px">
  Times shown in <strong><?= e(str_replace('_', ' ', user_tz())) ?></strong>.
  <?= $view === 'list' ? 'Click a post to edit it.' : 'Drag a post onto another day to move it.' ?>
</p>

<?php
require __DIR__ . '/app/composer.php';

layout_foot(composer_payload($posts, $accounts, user_tz()));
