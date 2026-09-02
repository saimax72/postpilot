<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

$me = require_admin();
$id = (int)($_GET['id'] ?? 0);

$u = db_one('SELECT * FROM users WHERE id = ?', [$id]);
if (!$u) {
    http_response_code(404);
    layout_head('User not found', 'User not found');
    echo '<div class="card card-pad">That user no longer exists. <a href="/admin/users.php">Back to all users</a>.</div>';
    layout_foot();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'comp') {
        billing_comp_pro($id, (int)$me['id'], trim((string)($_POST['note'] ?? '')));
        flash('success', $u['name'] . ' now has Pro access, with no payment and no daily limit.');
        redirect('/admin/user.php?id=' . $id);
    }
    if ($do === 'uncomp') {
        billing_uncomp($id, (int)$me['id']);
        flash('success', 'Complimentary Pro removed. Their posts and channels are untouched.');
        redirect('/admin/user.php?id=' . $id);
    }
}

$isSelf   = $id === (int)$me['id'];
$stats    = post_stats($id);
$accounts = accounts_for_user($id);

$posts = attach_targets(db_all(
    'SELECT * FROM posts WHERE user_id = ? ORDER BY scheduled_at DESC LIMIT 40', [$id]
));

$log = db_all('SELECT * FROM activity_log WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$id]);

layout_head($u['name'], $u['name'],
    '<a class="btn btn-ghost btn-sm" href="/admin/users.php">' . icon('back', 15) . ' All users</a>');
?>

<div class="card card-pad" style="margin-bottom:22px">
  <div class="row-between">
    <div class="row">
      <span class="avatar" style="background:<?= e($u['avatar_color']) ?>;width:52px;height:52px;font-size:1rem">
        <?= e(initials($u['name'])) ?>
      </span>
      <div>
        <h2 style="margin:0 0 3px"><?= e($u['name']) ?></h2>
        <div class="small muted"><?= e($u['email']) ?></div>
        <div class="row" style="margin-top:7px;gap:6px">
          <span class="badge badge-<?= e($u['status']) ?>"><?= e($u['status']) ?></span>
          <?php if ($u['role'] === 'admin'): ?><span class="badge badge-admin">admin</span><?php endif; ?>
          <span class="badge"><?= e(str_replace('_', ' ', $u['timezone'])) ?></span>
        </div>
      </div>
    </div>
    <div class="small muted" style="text-align:right">
      Joined <?= e(date('j M Y', strtotime($u['created_at']))) ?><br>
      Last seen <?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : 'never' ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:22px">
  <div class="card-head">
    <h3>Plan</h3>
    <?php if (billing_is_comped($u)): ?>
      <span class="badge badge-admin">complimentary Pro</span>
    <?php elseif (plan_key($u) === 'pro'): ?>
      <span class="badge badge-published">paying &middot; <?= e(ucfirst((string)$u['billing_provider'])) ?></span>
    <?php else: ?>
      <span class="badge badge-scheduled"><?= e(plan($u)['label']) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-pad">
    <?php
    $limit = plan_limit('posts_per_day', $u);
    $used  = usage_posts_today($id, $u);
    $left  = trial_days_left($u);
    ?>
    <p class="muted" style="margin-top:0">
      <?php if (plan_key($u) === 'pro'): ?>
        Unlimited posts, no daily cap.
        <?php if ($u['plan_since']): ?>
          On Pro since <?= e(date('j M Y', strtotime($u['plan_since'] . ' UTC'))) ?>.
        <?php endif; ?>
      <?php else: ?>
        <?= $limit ? $limit . ' posts a day' : 'Unlimited posts' ?>,
        <?= (int)$used ?> used today<?php if ($left !== null): ?>,
        <?= trial_expired($u) ? 'trial ended' : $left . ' day' . ($left === 1 ? '' : 's') . ' of trial left' ?><?php endif; ?>.
      <?php endif; ?>
    </p>

    <?php if (billing_is_comped($u)): ?>
      <form method="post" onsubmit="return confirm('Remove complimentary Pro from <?= e($u['name']) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="uncomp">
        <p class="small muted">
          Granted by hand, so nothing is billed and nothing needs cancelling. Removing it puts them
          back on the trial tier &mdash; their posts, channels and templates are untouched.
        </p>
        <button class="btn btn-ghost" type="submit">Remove complimentary Pro</button>
      </form>

    <?php elseif (plan_key($u) === 'pro'): ?>
      <div class="alert alert-info" style="margin-bottom:0;align-items:flex-start">
        <?= icon('zap', 16) ?>
        <span>This account is paying through <?= e(ucfirst((string)$u['billing_provider'])) ?>.
        Cancel the subscription there rather than changing the plan here, or they will keep
        being charged for a plan they no longer have.</span>
      </div>

    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="comp">
        <label class="field" style="max-width:420px">
          <span>Give Pro access, free</span>
          <input type="text" name="note" maxlength="120"
                 placeholder="Why — e.g. beta tester, friend of the business">
          <span class="hint">Recorded in the billing log so you can tell later why they have it.</span>
        </label>
        <button class="btn" type="submit"><?= icon('sparkle', 16) ?> Grant complimentary Pro</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat">         <div class="k">Scheduled</div><div class="v"><?= $stats['scheduled'] ?></div></div>
  <div class="stat s-green"> <div class="k">Published</div><div class="v"><?= $stats['published'] ?></div></div>
  <div class="stat s-yellow"><div class="k">Drafts</div>   <div class="v"><?= $stats['drafts'] ?></div></div>
  <div class="stat s-red">   <div class="k">Failed</div>   <div class="v"><?= $stats['failed'] ?></div></div>
  <div class="stat s-pink">  <div class="k">Channels</div> <div class="v"><?= $stats['accounts'] ?></div></div>
</div>

<div class="row" style="align-items:flex-start;gap:22px;flex-wrap:wrap">

  <div class="card grow" style="min-width:340px">
    <div class="card-head"><h3>Connected accounts</h3></div>
    <div class="table-wrap">
      <table class="data">
        <tbody>
        <?php if (!$accounts): ?>
          <tr><td class="muted small">No accounts connected.</td></tr>
        <?php endif; ?>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td>
              <span class="chip">
                <span class="pdot pdot-sm" style="background:<?= e(platform_color($a['platform'])) ?>"><?= platform_icon($a['platform'], 10) ?></span>
                <?= e(platform_label($a['platform'])) ?>
              </span>
            </td>
            <td><strong class="small"><?= e($a['display_name']) ?></strong>
              <?php if ($a['handle']): ?><div class="tiny muted"><?= e($a['handle']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $a['access_token'] ? 'published' : 'draft' ?>">
              <?= $a['access_token'] ? 'live' : 'demo' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="min-width:300px;flex:0 1 360px">
    <div class="card-head"><h3>Admin actions</h3></div>
    <div class="card-pad stack">
      <?php if ($isSelf): ?>
        <p class="small muted">This is your own account — change your own details in
          <a href="/settings.php">Settings</a>.</p>
      <?php else: ?>
        <form method="post" action="/admin/users.php" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="<?= $u['status'] === 'active' ? 'suspend' : 'activate' ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-ghost btn-block" type="submit">
            <?= $u['status'] === 'active' ? 'Suspend this account' : 'Re-activate this account' ?>
          </button>
        </form>

        <form method="post" action="/admin/users.php" class="row">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="role">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
          <button class="btn btn-ghost btn-block" type="submit">
            <?= $u['role'] === 'admin' ? 'Demote to regular user' : 'Promote to administrator' ?>
          </button>
        </form>
      <?php endif; ?>

      <form method="post" action="/admin/users.php">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="reset_password">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label class="field" style="margin-bottom:8px">
          <span>Set a new password</span>
          <input type="text" name="password" minlength="8" value="<?= e(bin2hex(random_bytes(5))) ?>">
        </label>
        <button class="btn btn-ghost btn-block" type="submit">Reset password</button>
      </form>

      <?php if (!$isSelf): ?>
        <form method="post" action="/admin/users.php"
              onsubmit="return confirm('Permanently delete <?= e($u['name']) ?> along with every post and connected account? This cannot be undone.')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-danger btn-block" type="submit"><?= icon('trash', 15) ?> Delete user</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="card" style="margin-top:22px">
  <div class="card-head"><h3>Posts</h3><span class="small muted">most recent 40</span></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th style="width:150px">When</th><th>Post</th><th style="width:130px">Channels</th><th style="width:110px">Status</th></tr></thead>
      <tbody>
      <?php if (!$posts): ?>
        <tr><td colspan="4" class="muted small">This user has not created any posts.</td></tr>
      <?php endif; ?>
      <?php foreach ($posts as $p): ?>
        <tr>
          <td class="nowrap small">
            <?= e(utc_to_local($p['scheduled_at'], $u['timezone'], 'j M Y')) ?>
            <div class="tiny muted"><?= e(utc_to_local($p['scheduled_at'], $u['timezone'], 'H:i')) ?></div>
          </td>
          <td class="small">
            <?= e(str_limit($p['content'] ?: 'Media post', 100)) ?>
            <?php if ($p['last_error']): ?>
              <div class="tiny" style="color:var(--red)"><?= e(str_limit($p['last_error'], 100)) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="row" style="gap:4px;flex-wrap:wrap">
              <?php foreach ($p['targets'] as $t): ?>
                <span class="pdot pdot-sm" style="background:<?= e(platform_color($t['platform'])) ?>" title="<?= e(platform_label($t['platform'])) ?>">
                  <?= platform_icon($t['platform'], 10) ?>
                </span>
              <?php endforeach; ?>
            </span>
          </td>
          <td><span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:22px">
  <div class="card-head"><h3>Activity</h3></div>
  <div class="table-wrap">
    <table class="data">
      <tbody>
      <?php if (!$log): ?>
        <tr><td class="muted small">No activity recorded.</td></tr>
      <?php endif; ?>
      <?php foreach ($log as $r): ?>
        <tr>
          <td style="width:150px"><span class="badge"><?= e(str_replace('_', ' ', $r['action'])) ?></span></td>
          <td class="small"><?= e($r['detail']) ?></td>
          <td class="tiny muted nowrap"><?= e($r['ip']) ?></td>
          <td class="tiny muted nowrap" style="text-align:right"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_foot(); ?>
