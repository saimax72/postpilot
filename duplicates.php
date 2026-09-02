<?php
/**
 * Duplicates: posts that are on the feed more than once.
 *
 * Read from the network, not from our own records. A post that published and
 * then lost its confirmation to a dropped connection is filed here as a
 * failure, so anything built on what we think happened is blind to exactly the
 * case worth finding. The account itself is the only reliable answer.
 *
 * Deliberately shows nothing else. A page that lists near-misses beside the
 * real thing leaves the reader to do the sorting.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

$feeds = [];
foreach (accounts_for_user($uid) as $account) {
    if ($account['platform'] === 'instagram') {
        $feeds[] = [
            'account' => $account,
            'result'  => ig_feed_duplicates($account, 50, isset($_GET['rescan'])),
        ];
    }
}

$found  = 0;
$errors = [];
foreach ($feeds as $f) {
    if ($f['result']['ok']) {
        $found += count($f['result']['groups']);
    } else {
        $errors[] = $f['account']['display_name'] . ': ' . $f['result']['error'];
    }
}

layout_head('Duplicates', 'Duplicates',
    '<a class="btn btn-ghost" href="/duplicates.php?rescan=1">' . icon('back', 15) . ' Refresh</a>');
?>

<div class="page-mid stack" style="gap:20px">

  <?php foreach ($errors as $message): ?>
    <div class="alert alert-warn"><?= icon('alert', 16) ?><span><?= e($message) ?></span></div>
  <?php endforeach; ?>

  <?php if (!$feeds): ?>

    <div class="card card-pad center" style="padding:52px 24px">
      <h3>No Instagram account connected</h3>
      <p class="muted">Connect one and this checks its feed for posts that went out twice.</p>
      <a class="btn" href="/accounts.php"><?= icon('link', 16) ?> Connect an account</a>
    </div>

  <?php elseif (!$found && !$errors): ?>

    <div class="card card-pad center" style="padding:52px 24px">
      <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
        <?= icon('check', 26) ?>
      </div>
      <h3>No duplicates</h3>
      <p class="muted">Nothing on your feed has gone out twice.</p>
    </div>

  <?php else: ?>

    <?php foreach ($feeds as $f): ?>
      <?php foreach ($f['result']['groups'] ?? [] as $items): ?>

        <div class="card">
          <div class="card-head">
            <h3>Posted <?= count($items) ?> times</h3>
            <span class="badge badge-failed">on <?= e($f['account']['display_name']) ?></span>
          </div>

          <div class="card-pad">
            <div class="dup-row">
              <?php foreach ($items as $m): ?>
                <a class="dup-copy" href="<?= e($m['permalink']) ?>"
                   target="_blank" rel="noopener noreferrer">
                  <div class="dup-thumb">
                    <?php if ($m['image']): ?>
                      <img src="<?= e($m['image']) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                  </div>
                  <div class="tiny muted" style="margin-top:8px">
                    <?= $m['timestamp'] ? e(date('j M Y, H:i', strtotime($m['timestamp']))) : '' ?>
                  </div>
                  <p class="dup-caption"><?= e(str_limit($m['caption'], 160)) ?></p>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    <?php endforeach; ?>

  <?php endif; ?>

</div>

<?php layout_foot(); ?>
