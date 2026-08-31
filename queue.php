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

$posts = attach_targets(db_all(
    'SELECT * FROM posts WHERE ' . implode(' AND ', $where) . ' ORDER BY scheduled_at DESC LIMIT 200',
    $params
));

$accounts = accounts_for_user($uid);
$stats    = post_stats($uid);

$actions = '<a class="btn btn-ghost btn-sm" href="/dashboard.php">' . icon('calendar', 15) . ' Calendar</a>'
         . '<button class="btn" onclick="Composer.open()">' . icon('plus', 16) . ' New post</button>';

layout_head('Queue', 'Queue', $actions);
?>

<div class="cal-toolbar">
  <span class="seg">
    <?php foreach ([
        'all'       => 'All (' . $stats['total'] . ')',
        'scheduled' => 'Scheduled (' . $stats['scheduled'] . ')',
        'published' => 'Published (' . $stats['published'] . ')',
        'draft'     => 'Drafts (' . $stats['drafts'] . ')',
        'failed'    => 'Failed (' . $stats['failed'] . ')',
    ] as $key => $label): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>" href="?status=<?= $key ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </span>
  <?php if (!empty($_GET['date'])): ?>
    <a class="btn btn-ghost btn-sm" href="?status=<?= e($filter) ?>">Clear date filter</a>
  <?php endif; ?>
</div>

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
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th style="width:150px">When</th>
            <th>Post</th>
            <th style="width:150px">Channels</th>
            <th style="width:120px">Status</th>
            <th style="width:120px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
          <tr>
            <td class="nowrap">
              <strong><?= e(utc_to_local($p['scheduled_at'], $tz, 'j M Y')) ?></strong>
              <div class="tiny muted"><?= e(utc_to_local($p['scheduled_at'], $tz, 'H:i')) ?></div>
            </td>
            <td>
              <div class="row" style="align-items:flex-start">
                <?php if ($p['media_path']): ?>
                  <span style="width:40px;height:40px;border-radius:8px;overflow:hidden;flex:none;background:var(--line-soft)">
                    <?php if (is_video($p['media_path'])): ?>
                      <video src="<?= e(media_url($p['media_path'])) ?>" muted style="width:100%;height:100%;object-fit:cover"></video>
                    <?php else: ?>
                      <img src="<?= e(media_url($p['media_path'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
                <span style="min-width:0">
                  <?= e(str_limit($p['content'] ?: 'Media post', 110)) ?>
                  <?php if ($p['status'] === 'failed' && $p['last_error']): ?>
                    <div class="tiny" style="color:var(--red);margin-top:3px"><?= e(str_limit($p['last_error'], 120)) ?></div>
                  <?php endif; ?>
                </span>
              </div>
            </td>
            <td>
              <span class="row" style="gap:4px;flex-wrap:wrap">
                <?php foreach ($p['targets'] as $t): ?>
                  <span class="pdot pdot-sm" style="background:<?= e(platform_color($t['platform'])) ?>"
                        title="<?= e(platform_label($t['platform']) . ' — ' . ($t['display_name'] ?? '') . ' (' . $t['status'] . ')') ?>">
                    <?= platform_icon($t['platform'], 10) ?>
                  </span>
                <?php endforeach; ?>
              </span>
            </td>
            <td><span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
            <td class="nowrap">
              <?php if (in_array($p['status'], ['published', 'publishing'], true)): ?>
                <span class="tiny muted"><?= $p['published_at'] ? e(time_ago($p['published_at'])) : '—' ?></span>
              <?php else: ?>
                <button class="btn btn-ghost btn-sm" onclick="Composer.open(<?= (int)$p['id'] ?>)">Edit</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php
require __DIR__ . '/app/composer.php';

layout_foot(composer_payload($posts, $accounts, $tz));
