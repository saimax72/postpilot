<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user  = auth_user();
$inApp = (bool)$user;

/** One plan card, used on both the public page and the signed-in view. */
function plan_card(string $key, array $p, bool $current): string
{
    $out  = '<article class="price-card' . ($key === 'pro' ? ' is-featured' : '') . '">';
    if ($key === 'pro') {
        $out .= '<span class="price-tag">Everything unlocked</span>';
    }
    $out .= '<h3>' . e($p['label']) . '</h3>';
    $out .= '<p class="price-amount">' . e($p['price'])
          . ' <span>' . e($p['period']) . '</span></p>';
    $out .= '<p class="price-blurb">' . e($p['blurb']) . '</p>';

    $out .= '<ul class="price-list">';
    foreach ($p['includes'] as $line) {
        $out .= '<li class="yes">' . e($line) . '</li>';
    }
    foreach ($p['excludes'] as $line) {
        $out .= '<li class="no">' . e($line) . '</li>';
    }
    $out .= '</ul>';

    if ($current) {
        $out .= '<span class="badge badge-scheduled">Your current plan</span>';
    } elseif ($key === 'pro') {
        $out .= '<a class="btn btn-block" href="mailto:' . e(owner_email()) . '?subject=' . rawurlencode('Upgrade to PostPilot Pro') . '">Upgrade to Pro</a>';
    } elseif (!auth_user()) {
        $out .= '<a class="btn btn-ghost btn-block" href="/register.php">Start free trial</a>';
    }

    return $out . '</article>';
}

$plans = plans();
$now   = $user ? plan_key($user) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pricing · <?= e(APP_NAME) ?></title>
<meta name="description" content="Start with a <?= TRIAL_DAYS ?>-day free trial, 10 posts a day. Upgrade to Pro for unlimited posting.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<link rel="icon" type="image/png" href="/assets/img/mark.png">
</head>
<body class="pp-home">

<div class="pp-page">

  <header class="pp-head">
    <a class="brand" href="/" style="padding:0"><?= brand_logo() ?></a>
    <nav class="pp-nav">
      <a href="/#features">Features</a>
      <a href="/#how">How it works</a>
      <a href="/pricing.php">Pricing</a>
    </nav>
    <div class="row">
      <?php if ($inApp): ?>
        <a class="btn btn-sm" href="/dashboard.php">Open PostPilot</a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="/login.php">Log in</a>
        <?php if (ALLOW_REGISTRATION): ?>
          <a class="btn btn-sm" href="/register.php">Start free trial</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </header>

  <div style="text-align:center;padding:26px 0 4px">
    <h1 style="font-family:var(--sans);font-weight:800;letter-spacing:-.03em;font-size:clamp(2rem,3.6vw,2.75rem);margin:0 0 12px">
      Start free for <?= TRIAL_DAYS ?> days
    </h1>
    <p class="muted" style="max-width:52ch;margin:0 auto">
      Every feature is unlocked during the trial, capped at 10 scheduled posts a day.
      No card, and nothing is charged automatically when it ends.
    </p>
  </div>

  <div class="price-grid">
    <?php foreach ($plans as $key => $p): ?>
      <?= plan_card($key, $p, $now === $key) ?>
    <?php endforeach; ?>
  </div>

  <section class="pp-panel">
    <h2 style="font-family:var(--sans);font-weight:750;font-size:1.25rem;letter-spacing:-.02em;margin:0 0 16px">
      What happens when the trial ends
    </h2>
    <ul class="pp-checks" style="gap:12px">
      <li>Nothing is charged. There is no card on file, so the trial simply stops.</li>
      <li>Your posts, images, templates and hashtag sets stay exactly where they are.</li>
      <li>Posts already scheduled keep publishing — the trial limits creating new ones, not sending what you have.</li>
      <li>Upgrading to Pro re-opens everything immediately.</li>
    </ul>
  </section>

  <footer class="pp-foot">
    <a class="brand" href="/" style="padding:0"><?= brand_logo() ?></a>
    <nav class="pp-nav">
      <a href="/#features">Features</a>
      <a href="/pricing.php">Pricing</a>
      <a href="/login.php">Log in</a>
    </nav>
    <span class="tiny muted">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
  </footer>

</div>

</body>
</html>
