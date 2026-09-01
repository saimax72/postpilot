<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        $tz   = (string)($_POST['timezone'] ?? 'UTC');

        if (mb_strlen($name) < 2) {
            flash('error', 'Please enter your name.');
        } elseif (!in_array($tz, timezone_list(), true)) {
            flash('error', 'That time zone is not recognised.');
        } else {
            db_run('UPDATE users SET name = ?, timezone = ? WHERE id = ?', [$name, $tz, $uid]);
            log_activity($uid, 'profile_update', 'Profile updated');
            flash('success', 'Profile saved.');
        }
    }

    if ($do === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');

        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } else {
            db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_activity($uid, 'password_change', 'Password changed');
            flash('success', 'Password updated.');
        }
    }

    redirect('/settings.php');
}

$stats  = post_stats($uid);
$recent = db_all('SELECT * FROM activity_log WHERE user_id = ? ORDER BY id DESC LIMIT 12', [$uid]);

layout_head('Settings', 'Settings');
?>

<div class="page-narrow stack" style="gap:24px">

  <div class="card">
    <div class="card-head"><h3>Profile</h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="profile">

      <label class="field">
        <span>Name</span>
        <input type="text" name="name" value="<?= e($user['name']) ?>" required>
      </label>

      <label class="field">
        <span>Email</span>
        <input type="email" value="<?= e($user['email']) ?>" disabled>
        <span class="hint">Contact an administrator to change the email on the account.</span>
      </label>

      <label class="field">
        <span>Time zone</span>
        <select name="timezone">
          <?php foreach (timezone_list() as $zone): ?>
            <option value="<?= e($zone) ?>" <?= $zone === $user['timezone'] ? 'selected' : '' ?>>
              <?= e(str_replace('_', ' ', $zone)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Changing this re-labels every time on your calendar — already-scheduled posts still go out
          at the same real-world moment.
        </span>
      </label>

      <button class="btn" type="submit">Save profile</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h3>Password</h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">

      <label class="field">
        <span>Current password</span>
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label class="field">
        <span>New password</span>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        <span class="hint">At least 8 characters.</span>
      </label>

      <button class="btn" type="submit">Change password</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head">
      <h3>Your plan</h3>
      <a class="btn btn-ghost btn-sm" href="/pricing.php">Compare plans</a>
    </div>
    <div class="card-pad">
      <div class="row" style="justify-content:space-between;align-items:baseline;gap:14px">
        <div>
          <div style="font-weight:650;font-size:1.0625rem"><?= e(plan()['label']) ?></div>
          <div class="small muted"><?= e(plan()['blurb']) ?></div>
        </div>
        <div style="text-align:right">
          <div style="font-family:var(--sans);font-weight:800;font-size:1.5rem;letter-spacing:-.03em"><?= e(plan()['price']) ?></div>
          <div class="tiny muted"><?= e(plan()['period']) ?></div>
        </div>
      </div>

      <?php if (($daysLeft = trial_days_left()) !== null): ?>
        <p class="small muted" style="margin:14px 0 0">
          <?php if (trial_expired()): ?>
            Your trial ended. Posts already scheduled still publish; creating new ones needs Pro.
          <?php else: ?>
            <?= $daysLeft === 1 ? '1 day' : (int)$daysLeft . ' days' ?> left,
            ending <?= e(date('j F Y', strtotime(trial_ends() . ' UTC'))) ?>.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <div class="plan-usage">
        <?php foreach (plan_usage_rows((int)$user['id']) as $row): ?>
          <div class="plan-usage-row<?= $row['full'] ? ' is-full' : '' ?>">
            <span class="k"><?= e($row['label']) ?></span>
            <span class="v"><?= e($row['text']) ?></span>
            <?php if ($row['limit'] > 0): ?>
              <span class="plan-meter">
                <span style="width:<?= (int)min(100, round(100 * $row['used'] / $row['limit'])) ?>%"></span>
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (is_admin_user()): ?>
        <p class="tiny muted" style="margin:14px 0 0">
          Administrators are exempt from every plan limit.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Your workspace</h3></div>
    <div class="card-pad">
      <div class="stats" style="margin:0">
        <div class="stat">         <div class="k">Total posts</div><div class="v"><?= $stats['total'] ?></div></div>
        <div class="stat s-green"> <div class="k">Published</div>  <div class="v"><?= $stats['published'] ?></div></div>
        <div class="stat s-pink">  <div class="k">Channels</div>   <div class="v"><?= $stats['accounts'] ?></div></div>
      </div>
      <p class="small muted" style="margin:18px 0 0">
        Account created <?= e(date('j F Y', strtotime($user['created_at']))) ?>.
        <?php if ($user['role'] === 'admin'): ?>
          You have <span class="badge badge-admin">admin</span> access —
          <a href="/admin/index.php">open the admin backend</a>.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Recent activity</h3></div>
    <div class="table-wrap">
      <table class="data">
        <tbody>
        <?php if (!$recent): ?>
          <tr><td class="muted small">Nothing recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td style="width:150px"><span class="badge"><?= e(str_replace('_', ' ', $r['action'])) ?></span></td>
            <td class="small"><?= e($r['detail']) ?></td>
            <td class="tiny muted nowrap" style="text-align:right"><?= e(time_ago($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php layout_foot(); ?>
