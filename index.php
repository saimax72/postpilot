<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

// Signed-in visitors go straight to their calendar.
if (auth_user()) {
    redirect('/dashboard.php');
}

$installed = db_installed();
$platforms = platforms();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — Schedule social media posts across every platform from one calendar</title>
<meta name="description" content="Plan, schedule and auto-publish your content to Instagram, Facebook, TikTok, LinkedIn, X, Threads, Pinterest and YouTube from a single calendar.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
<link rel="icon" type="image/png" href="/assets/img/mark.png">
</head>
<body>

<header class="site-head">
  <div class="wrap">
    <a class="brand" href="/" style="padding:0">
      <?= brand_logo() ?>
    </a>
    <nav class="site-nav">
      <a href="#features">Features</a>
      <a href="#channels">Channels</a>
      <a href="#how">How it works</a>
    </nav>
    <div class="row">
      <a class="btn btn-ghost btn-sm" href="/login.php">Log in</a>
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-sm" href="/register.php">Start free</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if (!$installed): ?>
  <div class="wrap" style="padding-top:20px">
    <div class="alert alert-warn">
      <strong>Setup needed.</strong>&nbsp;The database is not connected yet — open
      <a href="/install.php">/install.php</a> to finish installation.
    </div>
  </div>
<?php endif; ?>

<section class="hero">
  <div class="wrap">
    <span class="eyebrow">
      <span class="dot" style="background:var(--green)"></span>
      <span class="dot" style="background:var(--yellow)"></span>
      One calendar. Every network.
    </span>

    <h1>Schedule social media posts across every platform from one calendar</h1>

    <p class="lede">
      Write once, pick your channels, drop it on the day. PostPilot queues the post and
      publishes it for you — so you spend less time posting and more time running the
      business you built.
    </p>

    <div class="hero-cta">
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-lg" href="/register.php">Create your free account</a>
      <?php endif; ?>
      <a class="btn btn-lg btn-ghost" href="/login.php">I already have one</a>
    </div>

    <div class="logo-strip">
      <?php foreach ($platforms as $key => $p): ?>
        <span class="pdot" style="background:<?= e($p['color']) ?>" title="<?= e($p['label']) ?>">
          <?= platform_icon($key, 20) ?>
        </span>
      <?php endforeach; ?>
    </div>

    <div class="hero-shot">
      <img src="<?= asset('/assets/img/hero-shot.jpg') ?>" width="1400" height="1302"
           alt="The PostPilot calendar on a tablet showing a month of scheduled posts across Instagram, Facebook, TikTok, YouTube, LinkedIn and X, with an engagement chart alongside.">
    </div>

  </div>
</section>

<section class="section section-alt" id="features">
  <div class="wrap">
    <h2>Everything you need to keep a content calendar full</h2>
    <p class="sub">Plan a month in an afternoon, then let the queue do the posting.</p>

    <div class="features">
      <?php
      $features = [
          ['calendar', 'var(--brand)',  'One shared calendar',
           'Month, week and list views of every scheduled post across every connected account. Drag a post to a new day to move it.'],
          ['zap',      'var(--yellow)', 'Auto-publishing',
           'A cron worker picks up posts the moment they are due and pushes them to each network. Failures retry and surface in your queue.'],
          ['grid',     'var(--pink)',   'Grid preview',
           'See how your upcoming Instagram posts will sit together in the feed before a single one goes live.'],
          ['globe',    'var(--green)',  'Eight networks',
           'Instagram, Facebook, TikTok, LinkedIn, X, Threads, Pinterest and YouTube — connected once, posted to together.'],
          ['image',    'var(--orange)', 'Media library',
           'Attach an image or video to a post, preview it exactly as each network will crop it, and reuse it later.'],
          ['shield',   '#7c3aed',       'Admin backend',
           'A full control panel: every user, every post, connected accounts, activity log, and suspend or promote in one click.'],
      ];
      foreach ($features as [$ic, $color, $title, $body]): ?>
        <article class="feature">
          <span class="f-icon" style="background:<?= $color ?>"><?= icon($ic, 21) ?></span>
          <h3><?= e($title) ?></h3>
          <p><?= e($body) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="channels">
  <div class="wrap">
    <h2>Post to the channels your customers actually use</h2>
    <p class="sub">Connect an account once. From then on it is a checkbox in the composer.</p>

    <div class="features">
      <?php foreach ($platforms as $key => $p): ?>
        <article class="feature" style="display:flex;gap:14px;align-items:flex-start">
          <span class="pdot" style="background:<?= e($p['color']) ?>;width:40px;height:40px;flex:none">
            <?= platform_icon($key, 19) ?>
          </span>
          <div>
            <h3 style="margin-bottom:2px"><?= e($p['label']) ?></h3>
            <p class="small"><?= number_format($p['limit']) ?> character limit<?= !empty($p['media_required']) ? ' · media required' : '' ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-alt" id="how">
  <div class="wrap">
    <h2>Three steps to a full month of content</h2>
    <p class="sub">No agency, no spreadsheet, no reminders on your phone.</p>

    <div class="steps">
      <div class="step">
        <h3>Connect your accounts</h3>
        <p class="muted">Add each profile or page you post from. They all live in one workspace.</p>
      </div>
      <div class="step">
        <h3>Write once, tick the channels</h3>
        <p class="muted">The composer shows a live preview and the character limit for every network you selected.</p>
      </div>
      <div class="step">
        <h3>Pick a slot and forget it</h3>
        <p class="muted">Your post sits on the calendar until its moment, then goes out on its own.</p>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="cta-band">
      <span class="blob b1"></span>
      <span class="blob b2"></span>
      <h2>Get your first post on the calendar today</h2>
      <p>Free to set up, runs on your own hosting, and your data never leaves your server.</p>
      <?php if (ALLOW_REGISTRATION): ?>
        <a class="btn btn-lg" href="/register.php">Start scheduling</a>
      <?php else: ?>
        <a class="btn btn-lg" href="/login.php">Log in</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<footer class="site-foot">
  <div class="wrap row-between">
    <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
    <span class="row" style="gap:20px">
      <a href="/login.php">Log in</a>
      <?php if (ALLOW_REGISTRATION): ?><a href="/register.php">Create account</a><?php endif; ?>
    </span>
  </div>
</footer>

</body>
</html>
