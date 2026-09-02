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

if ($selected) {
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

    if ($account && $account['platform'] === 'instagram') {
        $live = ig_recent_media($account, 12, !empty($_GET['refresh']));
    }
}

// Newest-first is how a feed fills: the next post to go out sits top left.
$upcoming = array_reverse($posts);

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
  </div>

  <div class="row" style="align-items:flex-start;gap:32px;flex-wrap:wrap">
    <div>
      <div class="row-between" style="margin-bottom:10px;max-width:420px">
        <span class="small muted">Upcoming posts stacked on your real feed.</span>
        <?php if ($live['ok']): ?>
          <a class="tiny" href="?account=<?= (int)$selected ?>&amp;refresh=1">Refresh</a>
        <?php endif; ?>
      </div>

      <?php if ($live['error']): ?>
        <div class="alert alert-warn small" style="max-width:420px"><?= e($live['error']) ?></div>
      <?php endif; ?>

      <div class="feed-grid">
        <?php foreach ($upcoming as $p): ?>
          <div class="feed-cell is-upcoming"
               title="<?= e(utc_to_local($p['scheduled_at'], $tz, 'j M H:i') . ' — ' . str_limit($p['content'], 60)) ?>"
               onclick="Composer.open(<?= (int)$p['id'] ?>)">
            <?php if ($p['media_path'] && is_video($p['media_path'])): ?>
              <video src="<?= e(media_url($p['media_path'])) ?>" muted playsinline></video>
            <?php elseif ($p['media_path']): ?>
              <img src="<?= e(media_url($p['media_path'])) ?>" alt="">
            <?php else: ?>
              <span class="ph"><?= e(str_limit($p['content'], 46)) ?></span>
            <?php endif; ?>
            <span class="feed-tag"><?= e(utc_to_local($p['scheduled_at'], $tz, 'j M')) ?></span>
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
