<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];
$tz   = user_tz();

$filter = $_GET['status'] ?? 'all';
$valid  = ['all', 'scheduled', 'published', 'draft', 'failed'];
if (!in_array($filter, $valid, true)) {
    $filter = 'all';
}

$where  = ['user_id = ?'];
$params = [$uid];

if ($filter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $filter;
}

// Deep link from "+n more" on the calendar.
if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $where[]  = 'scheduled_at >= ? AND scheduled_at < ?';
    $params[] = local_to_utc($_GET['date'] . ' 00:00', $tz);
    $params[] = local_to_utc($_GET['date'] . ' 23:59', $tz);
}

// Order by what happens next: upcoming posts soonest-first, then past posts
// most-recent-first. Plain DESC buried the next post below months of history.
$posts = attach_targets(db_all(
    'SELECT * FROM posts WHERE ' . implode(' AND ', $where) . '
     ORDER BY (scheduled_at >= UTC_TIMESTAMP()) DESC,
              CASE WHEN scheduled_at >= UTC_TIMESTAMP() THEN scheduled_at END ASC,
              scheduled_at DESC
     LIMIT 200',
    $params
));

$accounts = accounts_for_user($uid);
$stats    = post_stats($uid);

// The next post that will actually go out, regardless of the current filter -
// the queue's most useful single fact.
$next = db_one(
    "SELECT * FROM posts
     WHERE user_id = ? AND status = 'scheduled' AND scheduled_at >= UTC_TIMESTAMP()
     ORDER BY scheduled_at ASC LIMIT 1",
    [$uid]
);
if ($next) {
    $next = attach_targets([$next])[0];
}

$actions = '<a class="btn btn-ghost btn-sm" href="/dashboard.php">' . icon('calendar', 15) . ' Calendar</a>'
         . '<button class="btn" onclick="Composer.open()">' . icon('plus', 16) . ' New post</button>';

layout_head('Queue', 'Queue', $actions);
?>

<?php if ($next): ?>
  <div class="next-up" onclick="Composer.open(<?= (int)$next['id'] ?>)">
    <?php if ($next['media_path']): ?>
      <img class="next-thumb" src="<?= e(media_url($next['media_path'])) ?>" alt="">
    <?php else: ?>
      <span class="next-thumb next-thumb-empty"><?= icon('image', 18) ?></span>
    <?php endif; ?>

    <div class="grow" style="min-width:0">
      <div class="tiny" style="text-transform:uppercase;letter-spacing:.1em;opacity:.75">Next to publish</div>
      <div class="next-caption"><?= e(str_limit($next['content'] ?: 'Media post', 90)) ?></div>
      <div class="tiny" style="opacity:.8;margin-top:3px">
        <?= e(utc_to_local($next['scheduled_at'], $tz, 'D j M')) ?> at <?= e(utc_to_local($next['scheduled_at'], $tz, 'H:i')) ?>
        <?php foreach (array_unique(array_column($next['targets'], 'platform')) as $plat): ?>
          · <?= e(platform_label($plat)) ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="next-eta" data-at="<?= e(gmdate('c', strtotime($next['scheduled_at'] . ' UTC'))) ?>"
         data-status="scheduled"></div>
  </div>
<?php endif; ?>

<div class="cal-toolbar">
  <span class="seg">
    <?php foreach ([
        'all'       => 'All (' . $stats['total'] . ')',
        'scheduled' => 'Scheduled (' . $stats['scheduled'] . ')',
        // This tab lists demo publishes too, so its count has to include them
        // or the number would disagree with the rows underneath it.
        'published' => 'Published (' . ($stats['published'] + $stats['demo']) . ')',
        'draft'     => 'Drafts (' . $stats['drafts'] . ')',
        'failed'    => 'Failed (' . $stats['failed'] . ')',
    ] as $key => $label): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>" href="?status=<?= $key ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </span>
  <div class="row" style="gap:8px">
    <?php if (!empty($_GET['date'])): ?>
      <a class="btn btn-ghost btn-sm" href="?status=<?= e($filter) ?>">Clear date filter</a>
    <?php endif; ?>
    <?php if ($stats['failed']): ?>
      <button class="btn btn-soft btn-sm" type="button" onclick="Retry.all(<?= (int)$stats['failed'] ?>)">
        Retry all <?= (int)$stats['failed'] ?> failed
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($filter === 'failed' && $posts): ?>
  <div class="alert alert-warn" style="margin-bottom:20px">
    <?= icon('clock', 18) ?>
    <span>
      <strong>Most bulk failures are rate limits, not rejected posts.</strong>
      Instagram accepts <strong>25 posts per rolling 24 hours</strong>; past that it returns
      <span class="mono">Application request limit reached</span> or
      <span class="mono">User is performing too many actions</span>. Retrying straight away hits the
      same wall — <strong>Retry all</strong> spaces them an hour apart so they drain gradually.
    </span>
  </div>
<?php endif; ?>

<?php if (!$posts): ?>

  <div class="card card-pad center" style="padding:60px 24px">
    <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
      <?= icon('calendar', 26) ?>
    </div>
    <h3>Nothing here yet</h3>
    <p class="muted">
      <?= $filter === 'all' ? 'Your queue is empty. Write your first post and pick a slot.' : 'No posts with that status.' ?>
    </p>
    <button class="btn" onclick="Composer.open()"><?= icon('plus', 16) ?> New post</button>
  </div>

<?php else: ?>

  <div class="card">
    <?php foreach ($posts as $p):
        $tags  = extract_hashtags((string)$p['content']);
        $ratio = $p['media_ratio'] ? (media_ratio($p['media_ratio'])['label'] ?? null) : null;
        $sent  = in_array($p['status'], ['published', 'publishing'], true);
    ?>
      <div class="lv-row" onclick="Composer.open(<?= (int)$p['id'] ?>)">
        <?php if ($p['media_path']): ?>
          <?php if (is_video($p['media_path'])): ?>
            <video class="lv-thumb" style="<?= e(thumb_style($p['media_ratio'] ?? null)) ?>" src="<?= e(media_url($p['media_path'])) ?>" muted playsinline preload="metadata"></video>
          <?php else: ?>
            <img class="lv-thumb" style="<?= e(thumb_style($p['media_ratio'] ?? null)) ?>" src="<?= e(media_url($p['media_path'])) ?>" alt="" loading="lazy">
          <?php endif; ?>
        <?php else: ?>
          <span class="lv-thumb lv-thumb-empty"><?= icon('image', 22) ?></span>
        <?php endif; ?>

        <div class="lv-when">
          <div class="tiny muted"><?= e(utc_to_local($p['scheduled_at'], $tz, 'j M Y')) ?></div>
          <div class="lv-time"><?= e(utc_to_local($p['scheduled_at'], $tz, 'H:i')) ?></div>
          <div class="lv-eta" data-at="<?= e(gmdate('c', strtotime($p['scheduled_at'] . ' UTC'))) ?>"
               data-status="<?= e($p['status']) ?>"></div>
        </div>

        <div class="lv-body">
          <p class="lv-caption"><?= e(str_limit($p['content'] ?: 'Media post', 220)) ?></p>

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
            <div class="tiny" style="color:var(--red);margin-top:7px">
              <?= $p['status'] === 'failed' ? 'Failed' : 'Retrying' ?>
              (attempt <?= (int)$p['attempts'] ?>): <?= e(str_limit($p['last_error'], 200)) ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="lv-side">
          <span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span>
          <?php if ($p['status'] === 'failed'): ?>
            <button class="btn btn-soft btn-sm" type="button"
                    onclick="event.stopPropagation(); Retry.one(<?= (int)$p['id'] ?>, this)">Retry</button>
          <?php endif; ?>
          <?php if ($p['status'] === 'published' && post_was_demo($p)): ?>
            <span class="badge badge-draft" title="Recorded as published, but the account had no credentials at the time so nothing was sent.">demo only</span>
          <?php endif; ?>
          <?php if ($sent && $p['published_at']): ?>
            <span class="tiny muted"><?= e(time_ago($p['published_at'])) ?></span>
          <?php elseif (!$sent): ?>
            <span class="tiny muted">click to edit</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<script>
window.Retry = {
  send: function (body, done) {
    fetch('/api/posts.php?action=retry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json',
                 'X-CSRF-Token': document.querySelector('input[name="_csrf"]').value },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    })
    .then(function (r) { return r.json(); })
    .then(done)
    .catch(function () { alert('Network error — nothing was requeued.'); });
  },

  one: function (id, btn) {
    btn.disabled = true;
    btn.textContent = 'Queueing…';
    this.send({ id: id }, function (data) {
      if (data.ok) { window.location.reload(); }
      else { btn.disabled = false; btn.textContent = 'Retry'; alert(data.error || 'Could not requeue.'); }
    });
  },

  all: function (n) {
    if (!confirm('Requeue all ' + n + ' failed posts?

' +
                 'They are spaced an hour apart so they do not hit the same rate limit again.')) return;
    this.send({ all: true, spacing: 60 }, function (data) {
      if (data.ok) { window.location.reload(); }
      else { alert(data.error || 'Could not requeue.'); }
    });
  }
};
</script>

<?php
require __DIR__ . '/app/composer.php';

layout_foot(composer_payload($posts, $accounts, $tz));
