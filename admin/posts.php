<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int)($_POST['id'] ?? 0);

    if (($_POST['do'] ?? '') === 'retry' && $pid) {
        db_run("UPDATE posts SET status='scheduled', attempts=0, last_error=NULL WHERE id=?", [$pid]);
        db_run("UPDATE post_targets SET status='pending', error=NULL WHERE post_id=? AND status='failed'", [$pid]);
        log_activity((int)$me['id'], 'admin_retry_post', 'Requeued post #' . $pid);
        flash('success', 'Post #' . $pid . ' put back in the queue.');
    }

    if (($_POST['do'] ?? '') === 'delete' && $pid) {
        db_run('DELETE FROM posts WHERE id = ?', [$pid]);
        log_activity((int)$me['id'], 'admin_delete_post', 'Deleted post #' . $pid);
        flash('success', 'Post deleted.');
    }

    redirect('/admin/posts.php?status=' . urlencode($_POST['status'] ?? 'all'));
}

$status = $_GET['status'] ?? 'all';
$valid  = ['all', 'scheduled', 'published', 'draft', 'failed', 'publishing'];
if (!in_array($status, $valid, true)) {
    $status = 'all';
}

$q      = trim((string)($_GET['q'] ?? ''));
$where  = ['1=1'];
$params = [];

if ($status !== 'all') {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = '(p.content LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$rows = db_all(
    'SELECT p.*, u.name AS user_name, u.email AS user_email, u.timezone AS user_tz, u.avatar_color
     FROM posts p JOIN users u ON u.id = p.user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.scheduled_at DESC LIMIT 250',
    $params
);
$rows = attach_targets($rows);

$counts = db_one(
    "SELECT COUNT(*) total,
        SUM(status='scheduled') scheduled, SUM(status='published') published,
        SUM(status='draft') drafts, SUM(status='failed') failed
     FROM posts"
) ?: [];

layout_head('All posts', 'All posts',
    '<a class="btn btn-ghost btn-sm" href="/admin/index.php">' . icon('back', 15) . ' Overview</a>');
?>

<div class="cal-toolbar">
  <span class="seg">
    <?php foreach ([
        'all'       => 'All (' . (int)($counts['total'] ?? 0) . ')',
        'scheduled' => 'Scheduled (' . (int)($counts['scheduled'] ?? 0) . ')',
        'published' => 'Published (' . (int)($counts['published'] ?? 0) . ')',
        'draft'     => 'Drafts (' . (int)($counts['drafts'] ?? 0) . ')',
        'failed'    => 'Failed (' . (int)($counts['failed'] ?? 0) . ')',
    ] as $key => $label): ?>
      <a class="<?= $status === $key ? 'on' : '' ?>" href="?status=<?= $key ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </span>

  <form method="get" class="row" style="max-width:360px">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search caption or user…">
    <button class="btn btn-ghost btn-sm" type="submit">Search</button>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th style="width:150px">When</th><th style="width:180px">User</th><th>Post</th>
        <th style="width:120px">Channels</th><th style="width:110px">Status</th><th style="width:150px"></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted small">No posts matched.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td class="nowrap small">
            <?= e(utc_to_local($p['scheduled_at'], $p['user_tz'], 'j M Y')) ?>
            <div class="tiny muted"><?= e(utc_to_local($p['scheduled_at'], $p['user_tz'], 'H:i')) ?> <?= e(str_replace('_', ' ', $p['user_tz'])) ?></div>
          </td>
          <td>
            <a class="row" href="/admin/user.php?id=<?= (int)$p['user_id'] ?>" style="color:inherit">
              <span class="avatar" style="background:<?= e($p['avatar_color']) ?>;width:28px;height:28px;font-size:.625rem">
                <?= e(initials($p['user_name'])) ?>
              </span>
              <span style="min-width:0">
                <div class="small" style="font-weight:600;overflow:hidden;text-overflow:ellipsis"><?= e($p['user_name']) ?></div>
              </span>
            </a>
          </td>
          <td class="small">
            <?= e(str_limit($p['content'] ?: 'Media post', 90)) ?>
            <?php if ($p['last_error']): ?>
              <div class="tiny" style="color:var(--red)"><?= e(str_limit($p['last_error'], 110)) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="row" style="gap:4px;flex-wrap:wrap">
              <?php foreach ($p['targets'] as $t): ?>
                <span class="pdot pdot-sm" style="background:<?= e(platform_color($t['platform'])) ?>"
                      title="<?= e(platform_label($t['platform']) . ' — ' . $t['status']) ?>">
                  <?= platform_icon($t['platform'], 10) ?>
                </span>
              <?php endforeach; ?>
            </span>
          </td>
          <td><span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
          <td class="nowrap" style="text-align:right">
            <div class="row" style="justify-content:flex-end;gap:6px">
              <?php if (in_array($p['status'], ['failed', 'publishing'], true)): ?>
                <form method="post" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="retry">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="status" value="<?= e($status) ?>">
                  <button class="btn btn-soft btn-sm" type="submit">Requeue</button>
                </form>
              <?php endif; ?>
              <form method="post" style="margin:0" onsubmit="return confirm('Delete this post?')">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="status" value="<?= e($status) ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash', 14) ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small muted" style="margin-top:14px">Showing up to 250 posts, newest scheduled time first.</p>

<?php layout_foot(); ?>
