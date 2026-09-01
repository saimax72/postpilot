<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

require_admin();

// Run the publisher on demand so you can test the pipeline without waiting for cron.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'run_publisher') {
    csrf_check();
    $r = publish_due_posts(50);
    flash('success', sprintf('Publisher run: %d processed, %d published, %d failed.',
        $r['processed'], $r['published'], $r['failed']));
    redirect('/admin/index.php');
}

$totals = db_one(
    "SELECT
        COUNT(*)                    AS users,
        SUM(status='active')        AS active,
        SUM(status='suspended')     AS suspended,
        SUM(role='admin')           AS admins,
        SUM(created_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY)) AS new_week
     FROM users"
) ?: [];

$postTotals = db_one(
    "SELECT
        COUNT(*)                 AS total,
        SUM(status='scheduled')  AS scheduled,
        SUM(status='published')  AS published,
        SUM(status='draft')      AS drafts,
        SUM(status='failed')     AS failed
     FROM posts"
) ?: [];

$accountsTotal = (int)db_value('SELECT COUNT(*) FROM social_accounts');
$dueNow        = (int)db_value("SELECT COUNT(*) FROM posts WHERE status='scheduled' AND scheduled_at <= UTC_TIMESTAMP()");

$byPlatform = db_all(
    'SELECT platform, COUNT(*) AS n FROM social_accounts GROUP BY platform ORDER BY n DESC'
);

$newest = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count
     FROM users u ORDER BY u.id DESC LIMIT 6'
);

$failing = attach_targets(db_all(
    "SELECT * FROM posts WHERE status='failed' ORDER BY updated_at DESC LIMIT 6"
));

$activity = db_all(
    'SELECT a.*, u.name, u.email FROM activity_log a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 10'
);

$actions = '<form method="post" style="margin:0">' . csrf_field()
         . '<input type="hidden" name="do" value="run_publisher">'
         . '<button class="btn btn-soft btn-sm" type="submit">' . icon('zap', 15) . ' Run publisher now</button></form>'
         . '<a class="btn btn-ghost btn-sm" href="/dashboard.php">' . icon('back', 15) . ' Back to app</a>';

layout_head('Admin', 'Admin overview', $actions);

echo page_banner('banner-04-global-analytics');
?>

<div class="stats">
  <div class="stat">         <div class="k">Users</div>            <div class="v"><?= (int)($totals['users'] ?? 0) ?></div></div>
  <div class="stat s-green"> <div class="k">Active</div>           <div class="v"><?= (int)($totals['active'] ?? 0) ?></div></div>
  <div class="stat s-yellow"><div class="k">New this week</div>    <div class="v"><?= (int)($totals['new_week'] ?? 0) ?></div></div>
  <div class="stat s-pink">  <div class="k">Connected accounts</div><div class="v"><?= $accountsTotal ?></div></div>
  <div class="stat">         <div class="k">Posts</div>            <div class="v"><?= (int)($postTotals['total'] ?? 0) ?></div></div>
  <div class="stat s-red">   <div class="k">Failed posts</div>     <div class="v"><?= (int)($postTotals['failed'] ?? 0) ?></div></div>
</div>

<?php if ($dueNow > 0): ?>
  <div class="alert alert-warn">
    <?= icon('clock', 18) ?>
    <span><strong><?= $dueNow ?> post<?= $dueNow === 1 ? ' is' : 's are' ?> due right now.</strong>
      If this number keeps climbing, the cron job is not running —
      check the schedule in <span class="mono">cron/publish.php</span>.</span>
  </div>
<?php endif; ?>

<div class="row" style="align-items:flex-start;gap:22px;flex-wrap:wrap">

  <div class="card grow" style="min-width:320px">
    <div class="card-head">
      <h3>Newest users</h3>
      <a class="small" href="/admin/users.php">View all →</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>User</th><th>Posts</th><th>Joined</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($newest as $u): ?>
          <tr>
            <td>
              <div class="row">
                <span class="avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e(initials($u['name'])) ?></span>
                <span style="min-width:0">
                  <div style="font-weight:600"><?= e($u['name']) ?>
                    <?php if ($u['role'] === 'admin'): ?><span class="badge badge-admin">admin</span><?php endif; ?>
                  </div>
                  <div class="tiny muted"><?= e($u['email']) ?></div>
                </span>
              </div>
            </td>
            <td><?= (int)$u['post_count'] ?></td>
            <td class="tiny muted nowrap"><?= e(time_ago($u['created_at'])) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="/admin/user.php?id=<?= (int)$u['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="min-width:280px;flex:0 1 340px">
    <div class="card-head"><h3>Accounts per network</h3></div>
    <div class="card-pad stack" style="gap:11px">
      <?php if (!$byPlatform): ?>
        <p class="muted small">No accounts connected across the platform yet.</p>
      <?php endif; ?>
      <?php
      $max = $byPlatform ? max(array_column($byPlatform, 'n')) : 1;
      foreach ($byPlatform as $row):
          $pct = round(($row['n'] / $max) * 100);
      ?>
        <div>
          <div class="row-between" style="margin-bottom:4px">
            <span class="row" style="gap:7px">
              <span class="pdot pdot-sm" style="background:<?= e(platform_color($row['platform'])) ?>"><?= platform_icon($row['platform'], 10) ?></span>
              <span class="small"><?= e(platform_label($row['platform'])) ?></span>
            </span>
            <strong class="small"><?= (int)$row['n'] ?></strong>
          </div>
          <div style="height:6px;border-radius:99px;background:var(--line-soft);overflow:hidden">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= e(platform_color($row['platform'])) ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<div class="row" style="align-items:flex-start;gap:22px;flex-wrap:wrap;margin-top:22px">

  <div class="card grow" style="min-width:320px">
    <div class="card-head">
      <h3>Posts that failed to publish</h3>
      <a class="small" href="/admin/posts.php?status=failed">View all →</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <tbody>
        <?php if (!$failing): ?>
          <tr><td class="muted small">Nothing has failed. Good sign.</td></tr>
        <?php endif; ?>
        <?php foreach ($failing as $p): ?>
          <tr>
            <td>
              <div class="small"><?= e(str_limit($p['content'] ?: 'Media post', 60)) ?></div>
              <div class="tiny" style="color:var(--red)"><?= e(str_limit($p['last_error'] ?? 'Unknown error', 90)) ?></div>
            </td>
            <td class="tiny muted nowrap" style="text-align:right"><?= e(time_ago($p['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card grow" style="min-width:320px">
    <div class="card-head">
      <h3>Latest activity</h3>
      <a class="small" href="/admin/activity.php">View all →</a>
    </div>
    <div class="table-wrap">
      <table class="data">
        <tbody>
        <?php foreach ($activity as $a): ?>
          <tr>
            <td style="width:130px"><span class="badge"><?= e(str_replace('_', ' ', $a['action'])) ?></span></td>
            <td class="small"><?= e($a['name'] ?? 'system') ?><div class="tiny muted"><?= e(str_limit($a['detail'] ?? '', 60)) ?></div></td>
            <td class="tiny muted nowrap" style="text-align:right"><?= e(time_ago($a['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php layout_foot(); ?>
