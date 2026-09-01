<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

// Signed-in visitors go straight to their calendar.
if (auth_user()) {
    redirect('/dashboard.php');
}

$installed = db_installed();

/** One section image, in the boxed frame the design uses throughout. */
function shot(string $file, string $alt): string
{
    return '<div class="pp-shot"><img src="' . asset('/assets/img/home/' . $file)
         . '" alt="' . e($alt) . '" loading="lazy"></div>';
}

$networks = [
    'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok',
    'linkedin'  => 'LinkedIn',  'x' => 'X',               'threads' => 'Threads',
    'pinterest' => 'Pinterest', 'youtube' => 'YouTube',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — Plan once. Publish everywhere.</title>
<meta name="description" content="Schedule and publish content across 8 social networks from one simple calendar. Save time and stay consistent.">
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
      <a href="#features">Features</a>
      <a href="#platforms">Platforms</a>
      <a href="#how">How it works</a>
    </nav>
    <div class="row">
      <a class="btn btn-ghost btn-sm" href="/login.php">Log in</a>
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-sm" href="/register.php">Start free</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!$installed): ?>
    <div class="alert alert-warn">
      <strong>Setup needed.</strong>&nbsp;The database is not connected yet — open
      <a href="/install.php">/install.php</a> to finish installation.
    </div>
  <?php endif; ?>

  <!-- ============================= Hero ============================= -->
  <section class="pp-hero">
    <div class="pp-hero-copy">
      <h1>Plan once.<br>Publish <span class="accent">everywhere.</span></h1>
      <p class="pp-lede">
        Schedule and publish content across 8 social networks from one simple
        calendar. Save time and stay consistent.
      </p>

      <div class="row" style="gap:18px;flex-wrap:wrap">
        <?php if (ALLOW_REGISTRATION): ?>
          <a class="btn btn-lg" href="/register.php">Start scheduling free</a>
        <?php endif; ?>
        <a class="pp-textlink" href="#how">View how it works &rarr;</a>
      </div>

      <p class="pp-eyebrow">Publish everywhere your audience lives</p>
      <div class="pp-networks">
        <?php foreach ($networks as $key => $label): ?>
          <img src="<?= asset('/assets/img/home/icon-' . $key . '.png') ?>"
               alt="<?= e($label) ?>" width="36" height="36" loading="lazy">
        <?php endforeach; ?>
      </div>
    </div>

    <div class="pp-hero-shot">
      <img src="<?= asset('/assets/img/home/hero-calendar.jpg') ?>" width="1140" height="680"
           alt="The PostPilot calendar showing a month of scheduled posts with thumbnails and times.">
    </div>
  </section>

  <!-- =========================== Features =========================== -->
  <span id="features" class="pp-anchor"></span>

  <section class="pp-panel pp-split">
    <div class="pp-split-copy">
      <h2>Plan your entire<br>month visually</h2>
      <p>See your content at a glance with intuitive month, week, and list views.
         Drag and drop to reschedule in seconds.</p>
      <ul class="pp-checks">
        <li>Calendar, week &amp; list views</li>
        <li>Drag &amp; drop rescheduling</li>
        <li>Colour-coded by platform</li>
      </ul>
    </div>
    <?= shot('sec-calendar.jpg', 'Month view of the PostPilot calendar with scheduled posts across a month.') ?>
  </section>

  <section class="pp-panel pp-split pp-split-flip">
    <div class="pp-split-copy">
      <h2>Create once.<br>Adapt everywhere.</h2>
      <p>Write your post and see live previews for every network. Tailor captions,
         media, and settings to fit each platform perfectly.</p>
      <ul class="pp-checks">
        <li>Live previews for every network</li>
        <li>Network-specific character limits</li>
        <li>Custom media per platform</li>
      </ul>
    </div>
    <?= shot('sec-composer.jpg', 'The PostPilot composer with a caption and live Instagram, LinkedIn and TikTok previews.') ?>
  </section>

  <section class="pp-panel pp-split">
    <div class="pp-split-copy">
      <h2>Post automatically<br>while you focus on<br>the big picture.</h2>
      <p>Your posts go live on time, every time. PostPilot handles publishing,
         retries on failure, and keeps you informed.</p>
      <ul class="pp-checks">
        <li>Automatic publishing</li>
        <li>Smart retry on failure</li>
        <li>Real-time queue &amp; status</li>
      </ul>
    </div>
    <?= shot('sec-queue.jpg', 'The publishing queue listing posts with their platform, status and time.') ?>
  </section>

  <!-- ========================= Feature cards ========================= -->
  <span id="platforms" class="pp-anchor"></span>
  <h2 class="pp-h2-center">Everything you need, all in one place</h2>

  <div class="pp-cards">
    <?php
    $cards = [
        ['One shared calendar',     'Keep your team aligned with one central content calendar.',     'card-calendar.jpg'],
        ['Auto-publishing',         'Your content goes live on time, even while you sleep.',         'card-auto.jpg'],
        ['Instagram grid preview',  'Plan your feed and keep your aesthetic looking perfect.',       'card-grid.jpg'],
        ['Media library',           'Organise, search, and reuse your photos, videos and GIFs.',     'card-media.jpg'],
        ['8 social networks',       'Publish across all major platforms from one simple dashboard.', 'card-networks.jpg'],
        ['Admin control centre',    'Manage users, roles, and permissions with ease.',               'card-admin.jpg'],
    ];
    foreach ($cards as [$title, $body, $img]): ?>
      <article class="pp-card">
        <div class="pp-card-copy">
          <h3><?= e($title) ?></h3>
          <p><?= e($body) ?></p>
        </div>
        <img src="<?= asset('/assets/img/home/' . $img) ?>" alt="" loading="lazy">
      </article>
    <?php endforeach; ?>
  </div>

  <!-- ============================ Steps ============================= -->
  <span id="how" class="pp-anchor"></span>
  <h2 class="pp-h2-center">Your month in three simple steps</h2>

  <div class="pp-steps">
    <?php
    $steps = [
        ['01', 'Connect',  'Connect your social media accounts in just a few clicks.',       'step-connect.jpg'],
        ['02', 'Create',   'Write your content, customise for each platform, and preview.',  'step-create.jpg'],
        ['03', 'Schedule', 'Pick the perfect time and let PostPilot handle the rest.',       'step-schedule.jpg'],
    ];
    foreach ($steps as [$n, $title, $body, $img]): ?>
      <div class="pp-step">
        <img src="<?= asset('/assets/img/home/' . $img) ?>" alt="" width="60" height="62" loading="lazy">
        <div>
          <span class="pp-step-n"><?= $n ?></span>
          <h3><?= e($title) ?></h3>
          <p><?= e($body) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ============================= CTA ============================== -->
  <section class="pp-cta">
    <img class="pp-cta-art" src="<?= asset('/assets/img/home/cta-art.jpg') ?>" alt="" loading="lazy">
    <div class="pp-cta-copy">
      <h2>Your content calendar is waiting.</h2>
      <p>Connect your channels and schedule your first post in minutes.</p>
    </div>
    <div class="pp-cta-action">
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-lg" href="/register.php">Start scheduling free</a>
        <span class="tiny">No credit card required</span>
      <?php else: ?>
        <a class="btn btn-lg" href="/login.php">Log in</a>
      <?php endif; ?>
    </div>
  </section>

  <!-- ============================ Footer ============================ -->
  <footer class="pp-foot">
    <a class="brand" href="/" style="padding:0"><?= brand_logo() ?></a>
    <nav class="pp-nav">
      <a href="#features">Features</a>
      <a href="#platforms">Platforms</a>
      <a href="#how">How it works</a>
      <a href="/login.php">Log in</a>
    </nav>
    <span class="tiny muted">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
  </footer>

</div>

</body>
</html>
