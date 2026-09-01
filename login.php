<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

if (auth_user()) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = $_POST['email'] ?? '';
    [$ok, $result] = attempt_login($email, $_POST['password'] ?? '');

    if ($ok) {
        $to = $_SESSION['intended'] ?? '/dashboard.php';
        unset($_SESSION['intended']);
        redirect($to);
    }
    $error = $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · <?= e(APP_NAME) ?></title>
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

      <h1 style="margin-top:18px">Welcome back</h1>
      <p class="muted">Sign in to see what is going out this week.</p>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>
      <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <label class="field">
          <span>Email address</span>
          <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email" placeholder="you@business.com">
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
        </label>
        <button class="btn btn-block btn-lg" type="submit">Log in</button>
      </form>

      <?php if (ALLOW_REGISTRATION): ?>
        <div class="divider">new here?</div>
        <a class="btn btn-ghost btn-block" href="/register.php">Create a free account</a>
      <?php endif; ?>
    </div>
  </div>

  <aside class="auth-aside">
    <span class="blob b1"></span>
    <span class="blob b2"></span>
    <div class="auth-visual">
      <img src="<?= asset('/assets/img/login-visual.jpg') ?>" width="1200" height="800"
           alt="A desk with the PostPilot calendar open on a monitor, an engagement chart beside it, and Instagram, Facebook, TikTok, YouTube, LinkedIn and X icons along the desktop.">
    </div>

    <ul>
      <li><span class="tick"><?= icon('check', 12) ?></span> Publishes to Instagram, Facebook, Threads, LinkedIn &amp; X</li>
      <li><span class="tick"><?= icon('check', 12) ?></span> Drag posts between days to reshuffle a week in seconds</li>
      <li><span class="tick"><?= icon('check', 12) ?></span> Runs on your own hosting — your data stays yours</li>
    </ul>
  </aside>

</div>
</body>
</html>
