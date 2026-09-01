<?php
/**
 * Plans and pricing.
 *
 * Two chromes, one set of content. A signed-in user gets the app layout with
 * the sidebar, because they arrived from "See plans" and are still inside the
 * product; a visitor gets the marketing header and footer, because they are
 * still deciding whether to be a user at all.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user  = auth_user();
$inApp = (bool)$user;
$plans = plans();
$now   = $user ? plan_key($user) : null;

/** The cards and the explanation. Identical either side of the login. */
function pricing_content(array $plans, ?string $now, bool $inApp): void
{
    $ended = $inApp && trial_days_left() !== null && trial_expired();
    ?>
    <div class="price-grid">
      <?php foreach ($plans as $key => $p): ?>
        <?= plan_card($key, $p, $now === $key) ?>
      <?php endforeach; ?>
    </div>

    <section class="pp-panel" style="margin-top:26px">
      <h2 style="font-family:var(--sans);font-weight:750;font-size:1.25rem;letter-spacing:-.02em;margin:0 0 16px">
        <?= $ended ? 'What happens now your trial has ended' : 'What happens when the trial ends' ?>
      </h2>
      <ul class="pp-checks" style="gap:12px">
        <li>Nothing is charged. There is no card on file, so the trial simply stops.</li>
        <li>Your posts, images, templates and hashtag sets stay exactly where they are.</li>
        <li>Posts already scheduled keep publishing &mdash; the trial limits creating new ones,
            not sending what you have.</li>
        <li>Upgrading to Pro re-opens everything immediately.</li>
      </ul>
    </section>
    <?php
}

/* ---------------- Signed in: the app layout ---------------- */

if ($inApp) {
    layout_head('Pricing', 'Plans and pricing');
    $days = trial_days_left();
    ?>
    <div class="page-mid">
    <?php if ($days !== null): ?>
      <p class="muted" style="margin:0 0 22px;max-width:62ch">
        <?php if (trial_expired()): ?>
          Your free trial has ended. Everything you have made is still here and scheduled posts keep
          publishing &mdash; only creating new ones is paused.
        <?php else: ?>
          You have <strong><?= $days === 1 ? '1 day' : (int)$days . ' days' ?></strong> left of your
          free trial, capped at <?= plan_limit('posts_per_day') ?> posts a day. Pro removes the cap.
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php pricing_content($plans, $now, true); ?>
    </div>
    <?php
    layout_foot();
    return;
}

/* ---------------- Signed out: the marketing page ---------------- */
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
      <a class="btn btn-ghost btn-sm" href="/login.php">Log in</a>
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-sm" href="/register.php">Start free trial</a>
      <?php endif; ?>
    </div>
  </header>

  <div style="padding:26px 0 4px">
    <h2 class="pp-h2-center" style="font-size:clamp(2rem,3.6vw,2.75rem);font-weight:800;margin:0 0 12px">
      Start free for <?= TRIAL_DAYS ?> days
    </h2>
    <p class="pp-h2-sub">
      Every feature is unlocked during the trial, capped at 10 scheduled posts a day.
      No card, and nothing is charged automatically when it ends.
    </p>
  </div>

  <?php pricing_content($plans, $now, false); ?>

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
