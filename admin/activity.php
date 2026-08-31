<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

require_admin();

$action = trim((string)($_GET['action'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 60;
$offset = ($page - 1) * $per;

$where  = '1=1';
$params = [];
if ($action !== '') {
    $where    = 'a.action = ?';
    $params[] = $action;
}

$total = (int)db_value("SELECT COUNT(*) FROM activity_log a WHERE $where", $params);

$rows = db_all(
    "SELECT a.*, u.name, u.email, u.avatar_color
     FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
     WHERE $where
     ORDER BY a.id DESC
     LIMIT $per OFFSET $offset",
    $params
);

$kinds = db_all('SELECT action, COUNT(*) n FROM activity_log GROUP BY action ORDER BY n DESC');

layout_head('Activity log', 'Activity log',
    '<a class="btn btn-ghost btn-sm" href="/admin/index.php">' . icon('back', 15) . ' Overview</a>');
?>

<div class="cal-toolbar">
  <span class="seg" style="flex-wrap:wrap">
    <a class="<?= $action === '' ? 'on' : '' ?>" href="?">All (<?= number_format($total) ?>)</a>
    <?php foreach ($kinds as $k): ?>
      <a class="<?= $action === $k['action'] ? 'on' : '' ?>" href="?action=<?= urlencode($k['action']) ?>">
        <?= e(str_replace('_', ' ', $k['action'])) ?> (<?= (int)$k['n'] ?>)
      </a>
    <?php endforeach; ?>
  </span>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th style="width:170px">Action</th><th style="width:220px">User</th><th>Detail</th><th style="width:130px">IP</th><th style="width:150px">When</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted small">Nothing logged yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge"><?= e(str_replace('_', ' ', $r['action'])) ?></span></td>
          <td>
            <?php if ($r['user_id']): ?>
              <a class="row" href="/admin/user.php?id=<?= (int)$r['user_id'] ?>" style="color:inherit">
                <span class="avatar" style="background:<?= e($r['avatar_color'] ?? '#64748b') ?>;width:26px;height:26px;font-size:.625rem">
                  <?= e(initials($r['name'] ?? '?')) ?>
                </span>
                <span class="small"><?= e($r['name'] ?? 'deleted user') ?></span>
              </a>
            <?php else: ?>
              <span class="small muted">system</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($r['detail']) ?></td>
          <td class="tiny muted mono"><?= e($r['ip']) ?></td>
          <td class="tiny muted nowrap">
            <?= e(date('j M Y H:i', strtotime($r['created_at'] . ' UTC'))) ?> UTC
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
  <div class="row" style="margin-top:18px;justify-content:center;gap:8px">
    <?php if ($page > 1): ?>
      <a class="btn btn-ghost btn-sm" href="?<?= http_build_query(array_filter(['action' => $action, 'page' => $page - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
      <a class="btn btn-ghost btn-sm" href="?<?= http_build_query(array_filter(['action' => $action, 'page' => $page + 1])) ?>">Next</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php layout_foot(); ?>
