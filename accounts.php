<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['do'] ?? '') === 'connect') {
        $platform = (string)($_POST['platform'] ?? '');
        $name     = trim((string)($_POST['display_name'] ?? ''));
        $handle   = trim((string)($_POST['handle'] ?? '')) ?: null;
        $token    = trim((string)($_POST['access_token'] ?? '')) ?: null;
        $extId    = trim((string)($_POST['external_id'] ?? '')) ?: null;

        if (!platform($platform)) {
            flash('error', 'Pick a network first.');
        } elseif ($name === '') {
            flash('error', 'Give the account a name so you can tell it apart in the composer.');
        } else {
            $id = account_connect($uid, $platform, $name, $handle, $token);
            if ($extId) {
                db_run('UPDATE social_accounts SET external_id = ? WHERE id = ? AND user_id = ?', [$extId, $id, $uid]);
            }
            flash('success', platform_label($platform) . ' account "' . $name . '" connected.');
        }
    }

    if (($_POST['do'] ?? '') === 'credentials') {
        $ok = account_credentials(
            (int)($_POST['id'] ?? 0),
            $uid,
            trim((string)($_POST['access_token'] ?? '')) ?: null,
            trim((string)($_POST['external_id'] ?? '')) ?: null
        );
        flash($ok ? 'success' : 'error',
            $ok ? 'Credentials saved. This account will publish for real from now on.'
                : 'That account was not found.');
    }

    if (($_POST['do'] ?? '') === 'demote') {
        account_clear_credentials((int)($_POST['id'] ?? 0), $uid);
        flash('success', 'Credentials removed. This account is back in demo mode.');
    }

    if (($_POST['do'] ?? '') === 'disconnect') {
        account_disconnect((int)($_POST['id'] ?? 0), $uid);
        flash('success', 'Account disconnected. Unsent posts that had no other channel were removed with it.');
    }

    redirect('/accounts.php');
}

$accounts = accounts_for_user($uid);
$grouped  = [];
foreach ($accounts as $a) {
    $grouped[$a['platform']][] = $a;
}

layout_head('Accounts', 'Connected accounts',
    '<button class="btn" onclick="document.getElementById(\'connect\').classList.toggle(\'hide\')">'
    . icon('plus', 16) . ' Connect account</button>');
?>

<!-- ---------------- Connect form ---------------- -->
<div class="card hide" id="connect" style="margin-bottom:24px">
  <div class="card-head"><h3>Connect an account</h3></div>
  <form method="post" class="card-pad">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="connect">

    <div class="field">
      <span class="label">Network</span>
      <div class="acct-picker" id="platform-picker">
        <?php foreach (platforms() as $key => $p): ?>
          <label class="acct">
            <input type="radio" name="platform" value="<?= e($key) ?>" required>
            <span class="pdot pdot-sm" style="background:<?= e($p['color']) ?>"><?= platform_icon($key, 10) ?></span>
            <?= e($p['label']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
      <label class="field grow" style="min-width:220px">
        <span>Account name</span>
        <input type="text" name="display_name" required placeholder="Rice Lake Boat Rentals">
        <span class="hint">Shown on the calendar and in the composer.</span>
      </label>
      <label class="field grow" style="min-width:200px">
        <span>Handle (optional)</span>
        <input type="text" name="handle" placeholder="@ricelakeboats">
      </label>
    </div>

    <details style="margin-bottom:16px">
      <summary style="cursor:pointer;font-weight:600;font-size:.875rem;color:var(--brand)">
        Add API credentials now (optional)
      </summary>
      <div style="padding-top:14px">
        <div class="alert alert-info" style="font-size:.8125rem">
          <?= icon('zap', 16) ?>
          <span>Leave these blank to run in <strong>demo mode</strong> — posts still move through the whole
          schedule-and-publish pipeline, they just are not sent to the network. Paste a real access token
          once your developer app is approved and PostPilot will start publishing for real.</span>
        </div>
        <div class="row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
          <label class="field grow" style="min-width:240px">
            <span>Access token</span>
            <input type="text" name="access_token" placeholder="EAAG… / Bearer token" autocomplete="off">
            <span class="hint">Encrypted with your APP_KEY before it touches the database.</span>
          </label>
          <label class="field grow" style="min-width:200px">
            <span>Account / page ID</span>
            <input type="text" name="external_id" placeholder="e.g. 1784… or urn:li:person:xxx">
            <span class="hint">Page ID, IG user ID, or LinkedIn URN.</span>
          </label>
        </div>
      </div>
    </details>

    <div class="row">
      <button class="btn" type="submit"><?= icon('link', 16) ?> Connect</button>
      <button class="btn btn-ghost" type="button" onclick="document.getElementById('connect').classList.add('hide')">Cancel</button>
    </div>
  </form>
</div>

<!-- ---------------- Connected list ---------------- -->
<?php if (!$accounts): ?>

  <div class="card card-pad center" style="padding:60px 24px">
    <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
      <?= icon('link', 26) ?>
    </div>
    <h3>No channels yet</h3>
    <p class="muted">Connect the profiles and pages you post from. You can add as many as you like.</p>
    <button class="btn" onclick="document.getElementById('connect').classList.remove('hide')">
      <?= icon('plus', 16) ?> Connect your first account
    </button>
  </div>

<?php else: ?>

  <div class="stack">
    <?php foreach ($grouped as $platform => $list): $p = platform($platform); ?>
      <div class="card">
        <div class="card-head">
          <div class="row">
            <span class="pdot" style="background:<?= e($p['color']) ?>;width:30px;height:30px"><?= platform_icon($platform, 15) ?></span>
            <h3><?= e($p['label']) ?></h3>
            <span class="badge"><?= count($list) ?> account<?= count($list) === 1 ? '' : 's' ?></span>
          </div>
          <a class="small" href="<?= e($p['docs']) ?>" target="_blank" rel="noopener noreferrer">API docs ↗</a>
        </div>
        <div class="table-wrap">
          <table class="data">
            <tbody>
            <?php foreach ($list as $a): ?>
              <tr>
                <td style="width:50%">
                  <strong><?= e($a['display_name']) ?></strong>
                  <?php if ($a['handle']): ?><div class="tiny muted"><?= e($a['handle']) ?></div><?php endif; ?>
                </td>
                <td>
                  <?php if ($a['access_token']): ?>
                    <span class="badge badge-published">live credentials</span>
                  <?php else: ?>
                    <span class="badge badge-draft">demo mode</span>
                  <?php endif; ?>
                </td>
                <td class="tiny muted nowrap">added <?= e(time_ago($a['created_at'])) ?></td>
                <td class="nowrap" style="text-align:right">
                  <div class="row" style="justify-content:flex-end;gap:6px">
                    <button class="btn <?= $a['access_token'] ? 'btn-ghost' : 'btn-soft' ?> btn-sm" type="button"
                            onclick="document.getElementById('cred-<?= (int)$a['id'] ?>').classList.toggle('hide')">
                      <?= $a['access_token'] ? 'Credentials' : 'Go live' ?>
                    </button>
                    <form method="post" style="margin:0"
                          onsubmit="return confirm('Disconnect this account? Unsent posts left with no other channel will be deleted.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="disconnect">
                      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit">Disconnect</button>
                    </form>
                  </div>
                </td>
              </tr>
              <tr class="hide" id="cred-<?= (int)$a['id'] ?>">
                <td colspan="4" style="background:var(--line-soft)">
                  <form method="post" style="padding:6px 0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

                    <div class="row" style="gap:14px;align-items:flex-start;flex-wrap:wrap">
                      <label class="field grow" style="min-width:260px;margin:0">
                        <span>Access token</span>
                        <input type="text" name="access_token" autocomplete="off"
                               placeholder="<?= $a['access_token'] ? 'Stored — leave blank to keep it' : 'Paste the long-lived token' ?>">
                        <span class="hint">Encrypted with APP_KEY before it is stored.</span>
                      </label>
                      <label class="field grow" style="min-width:220px;margin:0">
                        <span><?= e(platform_label($a['platform'])) ?> account ID</span>
                        <input type="text" name="external_id" value="<?= e($a['external_id'] ?? '') ?>"
                               placeholder="<?= $a['platform'] === 'linkedin' ? 'urn:li:person:xxxx' : '17841400000000000' ?>">
                        <span class="hint"><?= e(platform($a['platform'])['oauth_note'] ?? '') ?></span>
                      </label>
                    </div>

                    <div class="row" style="margin-top:12px">
                      <button class="btn btn-sm" type="submit" name="do" value="credentials">Save credentials</button>
                      <?php if ($a['access_token']): ?>
                        <button class="btn btn-ghost btn-sm" type="submit" name="do" value="demote"
                                onclick="return confirm('Remove the stored token? This account goes back to demo mode and will stop publishing for real.')">
                          Back to demo mode
                        </button>
                      <?php endif; ?>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<div class="card card-pad" style="margin-top:24px">
  <h3><?= icon('shield', 17) ?> How publishing works</h3>
  <p class="muted small" style="max-width:70ch">
    Each network needs its own developer app before it will accept posts from third-party software.
    Create one on the network's developer portal, request the publishing permission listed below, then
    paste the resulting access token against the account here. Until you do, PostPilot runs that account
    in demo mode so you can build out your calendar in the meantime.
  </p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Network</th><th>What you need</th><th>Driver</th></tr></thead>
      <tbody>
      <?php foreach (platforms() as $key => $p): ?>
        <tr>
          <td class="nowrap">
            <span class="chip"><span class="pdot pdot-sm" style="background:<?= e($p['color']) ?>"><?= platform_icon($key, 10) ?></span><?= e($p['label']) ?></span>
          </td>
          <td class="small"><?= e($p['oauth_note']) ?></td>
          <td>
            <?php if (in_array($key, ['facebook', 'instagram', 'threads', 'linkedin', 'x'], true)): ?>
              <span class="badge badge-published">built in</span>
            <?php else: ?>
              <span class="badge badge-draft">add driver</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // Highlight the selected network in the connect form.
  document.querySelectorAll('#platform-picker .acct').forEach(function (label) {
    label.addEventListener('click', function () {
      document.querySelectorAll('#platform-picker .acct').forEach(function (l) { l.classList.remove('on'); });
      label.classList.add('on');
    });
  });
</script>

<?php layout_foot(); ?>
