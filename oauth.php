<?php
/**
 * The "Connect with Facebook" endpoint.
 *
 * Three jobs, one file, because they are three steps of one conversation:
 *
 *   ?go=meta        send the browser to Facebook
 *   ?code=…&state=… Facebook sends it back here; we swap the code for a token
 *                   and show what was found
 *   POST            the user picks which of those to connect
 *
 * The middle step deliberately stops to ask rather than connecting everything
 * it finds. Someone who administers eleven Pages does not want eleven channels.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if (!oauth_meta_ready()) {
    flash('error', 'This installation has no Meta app configured, so one-click connecting is off. '
                 . 'Connect manually instead.');
    redirect('/connect.php');
}

/* ---------------- Step 3: save the chosen accounts ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $found  = $_SESSION['oauth_found'] ?? [];
    $picked = (array)($_POST['pick'] ?? []);
    unset($_SESSION['oauth_found']);

    if (!$found) {
        flash('error', 'That connection attempt expired. Please start again.');
        redirect('/accounts.php');
    }

    $new = 0; $again = 0;
    foreach ($picked as $i) {
        if (!isset($found[(int)$i])) {
            continue;
        }
        $c = $found[(int)$i];
        $c['token'] = decrypt_secret($c['token']);
        if (!$c['token']) {
            continue;                      // session outlived the APP_KEY somehow
        }
        $result = oauth_save_account($uid, $c);
        $result === 'connected' ? $new++ : $again++;
    }

    if (!$new && !$again) {
        flash('info', 'Nothing selected, so nothing was connected.');
    } else {
        $parts = [];
        if ($new)   { $parts[] = $new . ' account' . ($new === 1 ? '' : 's') . ' connected'; }
        if ($again) { $parts[] = $again . ' reconnected'; }
        flash('success', ucfirst(implode(' and ', $parts)) . '. These publish for real — no token needed.');
    }
    redirect('/accounts.php');
}

/* ---------------- Step 1: off to Facebook ---------------- */

if (($_GET['go'] ?? '') === 'meta') {
    redirect(oauth_meta_start_url(oauth_new_state()));
}

/* ---------------- Step 2: back from Facebook ---------------- */

// The user pressed Cancel on Facebook's screen. Not an error worth alarming
// anyone about.
if (isset($_GET['error'])) {
    flash('info', 'Facebook connection cancelled. Nothing was changed.');
    redirect('/accounts.php');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    redirect('/accounts.php');
}

if (!oauth_check_state($_GET['state'] ?? null)) {
    flash('error', 'That connection link did not match your session, so it was ignored. '
                 . 'Please start again from the Accounts page.');
    redirect('/accounts.php');
}

[$userToken, $error] = oauth_meta_token($code);
if (!$userToken) {
    flash('error', 'Facebook did not return a token: ' . $error);
    redirect('/accounts.php');
}

[$found, $error] = oauth_meta_discover($userToken);
if (!$found) {
    flash('error', $error);
    redirect('/accounts.php');
}

// Held in the session, not in the form, so a token never travels through the
// browser as a hidden field. Encrypted even there: session files sit on disk on
// shared hosting, and a Page token does not expire, so it is worth protecting
// for the minute it waits here.
$_SESSION['oauth_found'] = array_map(function ($c) {
    $c['token'] = encrypt_secret($c['token']);
    return $c;
}, $found);

$already = [];
foreach (db_all('SELECT platform, external_id FROM social_accounts WHERE user_id = ?', [$uid]) as $r) {
    $already[$r['platform'] . ':' . $r['external_id']] = true;
}

layout_head('Connect accounts', 'Choose what to connect');
?>

<div class="page-narrow stack" style="gap:20px">

  <div class="alert alert-success">
    <?= icon('check', 16) ?>
    <span>Facebook approved the connection. Pick the accounts you want to schedule to —
    you can always add the rest later.</span>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <div class="card">
      <div class="card-head">
        <h3><?= count($found) ?> account<?= count($found) === 1 ? '' : 's' ?> found</h3>
      </div>
      <div class="card-pad">
        <div class="stack" style="gap:10px">
          <?php foreach ($found as $i => $c):
              $p    = platform($c['platform']);
              $seen = isset($already[$c['platform'] . ':' . $c['external_id']]); ?>
            <label class="pick-row">
              <input type="checkbox" name="pick[]" value="<?= (int)$i ?>" <?= $seen ? '' : 'checked' ?>>
              <span class="pdot" style="background:<?= e($p['color']) ?>;width:30px;height:30px">
                <?= platform_icon($c['platform'], 15) ?>
              </span>
              <span class="grow" style="min-width:0">
                <strong><?= e($c['name']) ?></strong>
                <?php if ($c['handle']): ?>
                  <span class="muted small"><?= e($c['handle']) ?></span>
                <?php endif; ?>
                <div class="tiny muted"><?= e($c['note']) ?></div>
              </span>
              <?php if ($seen): ?>
                <span class="badge badge-scheduled">already connected</span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>

        <p class="small muted" style="margin:18px 0 0">
          Accounts you already have are unticked by default. Ticking one replaces its stored
          token and keeps every scheduled post exactly where it is — that is how you fix an
          expired connection.
        </p>
      </div>
    </div>

    <div class="row" style="margin-top:18px">
      <button class="btn btn-lg" type="submit"><?= icon('link', 16) ?> Connect selected</button>
      <a class="btn btn-ghost" href="/accounts.php">Cancel</a>
    </div>
  </form>

</div>

<?php layout_foot(); ?>
