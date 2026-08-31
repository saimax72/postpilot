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

$posts = [];
if ($selected) {
    $posts = attach_targets(db_all(
        "SELECT p.* FROM posts p
         JOIN post_targets t ON t.post_id = p.id
         WHERE p.user_id = ? AND t.social_account_id = ? AND p.status <> 'failed'
         ORDER BY p.scheduled_at DESC
         LIMIT 24",
        [$uid, $selected]
    ));
}

layout_head('Grid preview', 'Grid preview',
    '<button class="btn" onclick="Composer.open()">' . icon('plus', 16) . ' New post</button>');
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
      <p class="small muted">Newest first — exactly how the feed will fill in.</p>
      <div class="feed-grid">
        <?php if (!$posts): ?>
          <?php for ($i = 0; $i < 9; $i++): ?>
            <div class="feed-cell"><span class="ph">empty</span></div>
          <?php endfor; ?>
        <?php else: ?>
          <?php foreach ($posts as $p): ?>
            <div class="feed-cell" title="<?= e(utc_to_local($p['scheduled_at'], $tz, 'j M H:i') . ' — ' . str_limit($p['content'], 60)) ?>">
              <?php if ($p['media_path'] && is_video($p['media_path'])): ?>
                <video src="<?= e(media_url($p['media_path'])) ?>" muted playsinline></video>
              <?php elseif ($p['media_path']): ?>
                <img src="<?= e(media_url($p['media_path'])) ?>" alt="">
              <?php else: ?>
                <span class="ph"><?= e(str_limit($p['content'], 46)) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php for ($i = count($posts); $i < 9; $i++): ?>
            <div class="feed-cell"><span class="ph">empty</span></div>
          <?php endfor; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card grow" style="min-width:280px">
      <div class="card-head"><h3>Coming up</h3></div>
      <div class="card-pad stack">
        <?php if (!$posts): ?>
          <p class="muted small">Nothing scheduled for this channel yet.</p>
        <?php else: ?>
          <?php foreach (array_slice($posts, 0, 8) as $p): ?>
            <button class="row" style="text-align:left;border:0;background:none;cursor:pointer;padding:0;font:inherit;color:inherit"
                    onclick="Composer.open(<?= (int)$p['id'] ?>)">
              <span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span>
              <span class="grow" style="min-width:0">
                <div class="small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(str_limit($p['content'] ?: 'Media post', 46)) ?></div>
                <div class="tiny muted"><?= e(utc_to_local($p['scheduled_at'], $tz, 'D j M · H:i')) ?></div>
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
