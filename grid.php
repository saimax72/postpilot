<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];
$tz   = user_tz();

$accounts = accounts_for_user($uid);

// The grid preview only makes sense for the visual networks.
$visual = array_values(array_filter($accounts, fn($a) => in_array($a['platform'], ['instagram', 'pinterest', 'tiktok'], true)));

$selected = (int)($_GET['account'] ?? 0);
if (!$selected && $visual) {
    $selected = (int)$visual[0]['id'];
}

$posts   = [];
$account = $selected ? account_find($selected, $uid) : null;
$live    = ['ok' => false, 'items' => [], 'cached' => false, 'error' => null];

$show = in_array($_GET['show'] ?? '', ['all', 'dupes'], true) ? $_GET['show'] : 'upcoming';

if ($selected) {
    if ($show === 'all' || $show === 'dupes') {
        // Everything this channel has ever had, newest first, which is the
        // order a feed reads in.
        $posts = attach_targets(db_all(
            "SELECT p.* FROM posts p
             JOIN post_targets t ON t.post_id = p.id
             WHERE p.user_id = ? AND t.social_account_id = ?
             ORDER BY COALESCE(p.published_at, p.scheduled_at) DESC
             LIMIT 300",
            [$uid, $selected]
        ));
    } else {
        // Only what has not gone out yet - anything published is already in the
        // real feed below, and showing it twice would misrepresent the grid.
        $posts = attach_targets(db_all(
            "SELECT p.* FROM posts p
             JOIN post_targets t ON t.post_id = p.id
             WHERE p.user_id = ? AND t.social_account_id = ?
               AND p.status IN ('scheduled','publishing','draft')
             ORDER BY p.scheduled_at ASC
             LIMIT 18",
            [$uid, $selected]
        ));

        // The live feed is only fetched alongside the upcoming view. In the
        // all-posts view the same pictures are already there from our own
        // records, and pulling them twice would double the grid.
        if ($account && $account['platform'] === 'instagram') {
            $live = ig_recent_media($account, 12, !empty($_GET['refresh']));
        }
    }
}

// Which posts share their picture with another post. Cheap - the hashes are
// cached - and it is what makes a duplicate findable in a wall of thumbnails.
$dupes = $selected ? dup_post_map($uid, !empty($_GET['rescan'])) : [];

if ($show === 'dupes') {
    // Only the twins, and sorted so copies of one picture sit next to each
    // other. Comparing two tiles side by side is the whole point; hunting for
    // the second one three rows down is not.
    $posts = array_values(array_filter($posts, fn($p) => isset($dupes[(int)$p['id']])));
    usort($posts, fn($a, $b) => [$dupes[(int)$a['id']]['hash'], $a['id']]
                           <=> [$dupes[(int)$b['id']]['hash'], $b['id']]);
}

// Upcoming stacks newest-first so the next post to go out sits top left; the
// other views are already in the order they should read.
$upcoming = $show === 'upcoming' ? array_reverse($posts) : $posts;

layout_head('Grid preview', 'Grid preview',
    '<button class="btn" onclick="Composer.open()">' . icon('plus', 16) . ' New post</button>');

echo page_banner('banner-02-calendar-mobile');
echo upgrade_nudge(false);
?>

<?php if (!$visual): ?>

  <div class="card card-pad center" style="padding:60px 24px">
    <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
      <?= icon('grid', 26) ?>
    </div>
    <h3>No visual channels connected</h3>
    <p class="muted">Connect an Instagram, Pinterest or TikTok account to preview how your upcoming posts will sit together.</p>
    <a class="btn" href="/accounts.php"><?= icon('link', 16) ?> Connect an account</a>
  </div>

<?php else: ?>

  <div class="cal-toolbar">
    <span class="seg">
      <?php foreach ($visual as $a): ?>
        <a class="<?= $selected === (int)$a['id'] ? 'on' : '' ?>" href="?account=<?= (int)$a['id'] ?>">
          <?= e($a['display_name']) ?>
        </a>
      <?php endforeach; ?>
    </span>

    <span class="seg">
      <a class="<?= $show === 'upcoming' ? 'on' : '' ?>"
         href="?account=<?= (int)$selected ?>">Upcoming</a>
      <a class="<?= $show === 'all' ? 'on' : '' ?>"
         href="?account=<?= (int)$selected ?>&amp;show=all">All posts</a>
      <a class="<?= $show === 'dupes' ? 'on' : '' ?>"
         href="?account=<?= (int)$selected ?>&amp;show=dupes"
         title="Pictures that reached the feed more than once">
        Duplicates<?= $dupes ? ' (' . count($dupes) . ')' : '' ?>
      </a>
    </span>
  </div>

  <div class="row" style="align-items:flex-start;gap:32px;flex-wrap:wrap">
    <div>
      <div class="row-between" style="margin-bottom:10px;max-width:420px">
        <span class="small muted">
          <?= $show === 'all'
                ? count($upcoming) . ' post' . (count($upcoming) === 1 ? '' : 's') . ', newest first'
                : 'Upcoming posts stacked on your real feed.' ?>
        </span>
        <?php if ($live['ok']): ?>
          <a class="tiny" href="?account=<?= (int)$selected ?>&amp;refresh=1">Refresh</a>
        <?php endif; ?>
      </div>

      <?php if ($live['error']): ?>
        <div class="alert alert-warn small" style="max-width:420px"><?= e($live['error']) ?></div>
      <?php endif; ?>

      <?php if ($show === 'dupes' && !$upcoming): ?>
        <div class="alert alert-info" style="max-width:420px">
          <?= icon('check', 16) ?>
          <span>No picture on this channel has published more than once.
          <a href="/duplicates.php?all=1">Check images reused across posts</a>.</span>
        </div>
      <?php endif; ?>

      <div class="feed-grid">
        <?php foreach ($upcoming as $p):
          $sent = $p['status'] === 'published';
          $when = $sent ? ($p['published_at'] ?: $p['scheduled_at']) : $p['scheduled_at'];
          $dup  = $dupes[(int)$p['id']] ?? null;
          ?>
          <div class="feed-cell <?= $sent ? 'is-sent' : 'is-upcoming' ?><?= $p['status'] === 'failed' ? ' is-bad' : '' ?><?= $dup ? ' is-dup' : '' ?>"
               title="<?= e(utc_to_local($when, $tz, 'j M H:i') . ' - ' . $p['status'] . ' - ' . str_limit($p['content'], 60)) ?>"
               onclick="Composer.open(<?= (int)$p['id'] ?>)">
            <?php if ($p['media_path'] && is_video($p['media_path'])): ?>
              <video src="<?= e(media_url($p['media_path'])) ?>" muted playsinline preload="none"></video>
            <?php elseif ($p['media_path']): ?>
              <?php /* The all-posts view can be hundreds of tiles, so they load
                       as they are scrolled to rather than all at once. */ ?>
              <img src="<?= e(media_url($p['media_path'])) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="ph"><?= e(str_limit($p['content'], 46)) ?></span>
            <?php endif; ?>
            <span class="feed-tag"><?= e(utc_to_local($when, $tz, 'j M')) ?></span>
            <?php if ($dup): ?>
              <span class="feed-dup" title="This picture is on <?= (int)$dup['count'] ?> posts">
                <?= (int)$dup['count'] ?>&times;
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php foreach ($live['items'] as $m): ?>
          <a class="feed-cell is-live" href="<?= e($m['permalink']) ?>" target="_blank" rel="noopener"
             title="<?= e(str_limit($m['caption'], 80)) ?>">
            <?php if ($m['image']): ?>
              <img src="<?= e($m['image']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="ph">on Instagram</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>

        <?php
        $shown = count($upcoming) + count($live['items']);
        for ($i = $shown; $i < 9; $i++): ?>
          <div class="feed-cell"><span class="ph">empty</span></div>
        <?php endfor; ?>
      </div>

      <p class="tiny muted" style="max-width:420px;margin-top:10px">
        Tinted cells are scheduled and not yet live. The rest are pulled from
        <?= $account ? e($account['display_name']) : 'your account' ?>
        <?= $live['cached'] ? ' (cached, refreshes every 15 minutes)' : '' ?>.
      </p>
    </div>

    <div class="card grow" style="min-width:280px">
      <div class="card-head"><h3>Coming up</h3></div>
      <div class="card-pad stack">
        <?php if (!$posts): ?>
          <p class="muted small">Nothing scheduled for this channel yet.</p>
        <?php else: ?>
          <?php foreach ($posts as $p): ?>
            <button class="row" style="text-align:left;border:0;background:none;cursor:pointer;padding:0;font:inherit;color:inherit"
                    onclick="Composer.open(<?= (int)$p['id'] ?>)">
              <span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span>
              <span class="grow" style="min-width:0">
                <div class="small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(str_limit($p['content'] ?: 'Media post', 46)) ?></div>
                <div class="tiny muted">
                  <?= e(utc_to_local($p['scheduled_at'], $tz, 'D j M · H:i')) ?>
                  <span class="lv-eta" data-at="<?= e(gmdate('c', strtotime($p['scheduled_at'] . ' UTC'))) ?>"
                        data-status="<?= e($p['status']) ?>"></span>
                </div>
              </span>
            </button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php
require __DIR__ . '/app/composer.php';

layout_foot(composer_payload($posts, $accounts, $tz));
