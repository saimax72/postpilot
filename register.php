<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

if (auth_user()) {
    redirect('/dashboard.php');
}
if (!ALLOW_REGISTRATION) {
    flash('info', 'New sign-ups are closed. Ask an administrator for an account.');
    redirect('/login.php');
}

$error = '';
$name  = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$tz    = $_POST['timezone'] ?? 'UTC';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // The very first account created becomes the administrator.
    $isFirst = (int)db_value('SELECT COUNT(*) FROM users') === 0;

    [$ok, $result] = register_user($name, $email, $_POST['password'] ?? '', $tz, $isFirst ? 'admin' : 'user');

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $result;
        flash('success', $isFirst
            ? 'Welcome. This first account is the administrator — connect an account to get started.'
            : 'Account created. Connect your first social account to start scheduling.');
        redirect('/accounts.php');
    }
    $error = $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body>
<div class="auth-wrap">

  <div class="auth-panel">
    <div class="auth-form">
      <a class="brand" href="/" style="padding:0">
        <?= brand_logo() ?>
      </a>

      <h1 style="margin-top:18px">Start scheduling</h1>
      <p class="muted">Free for <?= TRIAL_DAYS ?> days. No card needed.</p>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <label class="field">
          <span>Your name</span>
          <input type="text" name="name" value="<?= e($name) ?>" required autofocus autocomplete="name" placeholder="Alex Morgan">
        </label>
        <label class="field">
          <span>Email address</span>
          <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" placeholder="you@business.com">
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
          <span class="hint">Use 8 characters or more. Longer is better than complicated.</span>
        </label>
        <label class="field">
          <span>Time zone</span>
          <select name="timezone">
            <?php foreach (timezone_list() as $zone): ?>
              <option value="<?= e($zone) ?>" <?= $zone === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $zone)) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Every time you see on the calendar is shown in this zone.</span>
        </label>
        <button class="btn btn-block btn-lg" type="submit">Create account</button>
      </form>

      <div class="divider">already registered?</div>
      <a class="btn btn-ghost btn-block" href="/login.php">Log in instead</a>
    </div>
  </div>

  <aside class="auth-aside is-plans">
    <span class="blob b1"></span>
    <span class="blob b2"></span>
    <div class="aside-scroll">
    <h2>Free for <?= TRIAL_DAYS ?> days.<br>Then $12 a month.</h2>
    <p>No card needed, and nothing is charged when the trial ends.</p>

    <div class="trial-box">
      <div class="trial-head">
        <span class="trial-name">Your <?= TRIAL_DAYS ?>-day trial</span>
        <span class="trial-price">$0</span>
      </div>
      <ul>
        <li><span class="tick"><?= icon('check', 12) ?></span> Every feature unlocked</li>
        <li><span class="tick"><?= icon('check', 12) ?></span> <strong>10 scheduled posts a day</strong></li>
        <li><span class="tick"><?= icon('check', 12) ?></span> 5 networks publishing, unlimited channels</li>
        <li><span class="tick"><?= icon('check', 12) ?></span> Bulk upload, templates, hashtag sets</li>
      </ul>
    </div>

    <div class="trial-box is-paid">
      <div class="trial-head">
        <span class="trial-name">Pro, after the trial</span>
        <span class="trial-price">$12<span class="trial-per">/month</span></span>
      </div>
      <ul>
        <li><span class="tick"><?= icon('check', 12) ?></span> <strong>Unlimited posts</strong> — no daily cap</li>
        <li><span class="tick"><?= icon('check', 12) ?></span> Everything in the trial, permanently</li>
      </ul>
    </div>

    <p class="trial-note">
      When the trial ends your posts, images and templates stay put, and anything
      already scheduled still publishes. Only creating new posts stops until you upgrade.
      <a href="/pricing.php">Full details</a>
    </p>
    </div>
  </aside>

</div>
<script>
  // Pre-select the visitor's own time zone the first time they see this form.
  (function () {
    var sel = document.querySelector('select[name=timezone]');
    if (sel && sel.value === 'UTC') {
      try {
        var guess = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if ([...sel.options].some(function (o) { return o.value === guess; })) sel.value = guess;
      } catch (e) {}
    }
  })();
</script>
</body>
</html>
