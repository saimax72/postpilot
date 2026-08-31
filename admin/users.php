<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do     = $_POST['do'] ?? '';
    $target = (int)($_POST['id'] ?? 0);

    // Never let an admin lock themselves out of their own backend.
    $isSelf = $target === (int)$me['id'];

    if ($do === 'create') {
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        [$ok, $result] = register_user(
            (string)($_POST['name'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['timezone'] ?? 'UTC'),
            $role
        );
        flash($ok ? 'success' : 'error', $ok ? 'User created.' : $result);
        if ($ok) {
            log_activity((int)$me['id'], 'admin_create_user', 'Created user #' . $result);
        }
    }

    if ($do === 'suspend' && !$isSelf) {
        db_run("UPDATE users SET status='suspended' WHERE id = ?", [$target]);
        log_activity((int)$me['id'], 'admin_suspend', 'Suspended user #' . $target);
        flash('success', 'User suspended — they can no longer sign in.');
    }

    if ($do === 'activate') {
        db_run("UPDATE users SET status='active' WHERE id = ?", [$target]);
        log_activity((int)$me['id'], 'admin_activate', 'Re-activated user #' . $target);
        flash('success', 'User re-activated.');
    }

    if ($do === 'role' && !$isSelf) {
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        db_run('UPDATE users SET role = ? WHERE id = ?', [$role, $target]);
        log_activity((int)$me['id'], 'admin_role', 'User #' . $target . ' set to ' . $role);
        flash('success', 'Role updated to ' . $role . '.');
    }

    if ($do === 'reset_password' && $target) {
        $new = (string)($_POST['password'] ?? '');
        if (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } else {
            db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $target]);
            log_activity((int)$me['id'], 'admin_reset_password', 'Reset password for user #' . $target);
            flash('success', 'Password reset. Send it to the user over a channel you trust.');
        }
    }

    if ($do === 'delete' && !$isSelf) {
        db_run('DELETE FROM users WHERE id = ?', [$target]);
        log_activity((int)$me['id'], 'admin_delete_user', 'Deleted user #' . $target);
        flash('success', 'User and all of their posts were deleted.');
    }

    if ($isSelf && in_array($do, ['suspend', 'role', 'delete'], true)) {
        flash('error', 'You cannot change your own role or status here.');
    }

    redirect('/admin/users.php' . (!empty($_POST['q']) ? '?q=' . urlencode($_POST['q']) : ''));
}

$q      = trim((string)($_GET['q'] ?? ''));
$params = [];
$where  = '1=1';

if ($q !== '') {
    $where    = '(name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$users = db_all(
    "SELECT u.*,
        (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count,
        (SELECT COUNT(*) FROM social_accounts a WHERE a.user_id = u.id) AS account_count
     FROM users u WHERE $where ORDER BY u.id DESC LIMIT 300",
    $params
);

layout_head('All users', 'All users',
    '<button class="btn" onclick="document.getElementById(\'newuser\').classList.toggle(\'hide\')">'
    . icon('plus', 16) . ' Add user</button>');
?>

<div class="card hide" id="newuser" style="margin-bottom:22px">
  <div class="card-head"><h3>Create a user</h3></div>
  <form method="post" class="card-pad">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
      <label class="field grow" style="min-width:180px"><span>Name</span>
        <input type="text" name="name" required placeholder="Alex Morgan"></label>
      <label class="field grow" style="min-width:200px"><span>Email</span>
        <input type="email" name="email" required placeholder="alex@business.com"></label>
      <label class="field grow" style="min-width:160px"><span>Temporary password</span>
        <input type="text" name="password" required minlength="8" value="<?= e(bin2hex(random_bytes(5))) ?>"></label>
      <label class="field" style="min-width:130px"><span>Role</span>
        <select name="role"><option value="user">User</option><option value="admin">Admin</option></select></label>
    </div>
    <div class="row">
      <button class="btn" type="submit">Create user</button>
      <button class="btn btn-ghost" type="button" onclick="document.getElementById('newuser').classList.add('hide')">Cancel</button>
    </div>
  </form>
</div>

<form method="get" class="row" style="margin-bottom:18px;max-width:420px">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or email…">
  <button class="btn btn-ghost" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="/admin/users.php">Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>User</th><th>Role</th><th>Status</th>
          <th>Posts</th><th>Channels</th><th>Last seen</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7" class="muted small">No users matched that search.</td></tr>
      <?php endif; ?>
      <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$me['id']; ?>
        <tr>
          <td>
            <div class="row">
              <span class="avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e(initials($u['name'])) ?></span>
              <span style="min-width:0">
                <div style="font-weight:600"><?= e($u['name']) ?><?= $self ? ' <span class="tiny muted">(you)</span>' : '' ?></div>
                <div class="tiny muted"><?= e($u['email']) ?></div>
              </span>
            </div>
          </td>
          <td>
            <?php if ($self): ?>
              <span class="badge badge-admin">admin</span>
            <?php else: ?>
              <form method="post" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="role">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <select name="role" onchange="this.form.submit()" style="padding:5px 28px 5px 10px;font-size:.8125rem">
                  <option value="user"  <?= $u['role'] === 'user'  ? 'selected' : '' ?>>User</option>
                  <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
              </form>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
          <td><?= (int)$u['post_count'] ?></td>
          <td><?= (int)$u['account_count'] ?></td>
          <td class="tiny muted nowrap"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : 'never' ?></td>
          <td class="nowrap" style="text-align:right">
            <div class="row" style="justify-content:flex-end;gap:6px">
              <a class="btn btn-ghost btn-sm" href="/admin/user.php?id=<?= (int)$u['id'] ?>">Open</a>
              <?php if (!$self): ?>
                <form method="post" style="margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="<?= $u['status'] === 'active' ? 'suspend' : 'activate' ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="q" value="<?= e($q) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">
                    <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small muted" style="margin-top:14px">
  Showing up to 300 users. Suspending blocks sign-in immediately; deleting a user also deletes their
  posts, connected accounts and media records.
</p>

<?php layout_foot(); ?>
