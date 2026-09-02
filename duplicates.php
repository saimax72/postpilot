<?php
/**
 * Duplicate check: images that went out in more than one post.
 *
 * Read-only on purpose. Nothing here deletes anything - the useful action is
 * on the post (or on the network), not on a file, and offering a delete button
 * next to a picture that is live on a feed invites the wrong one.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

$groups = dup_repeated($uid, isset($_GET['rescan']));
$sum    = dup_summary($groups);

layout_head('Duplicates', 'Duplicate posts',
    '<a class="btn btn-ghost" href="/duplicates.php?rescan=1">' . icon('back', 15) . ' Rescan</a>');
?>

<div class="page-mid stack" style="gap:22px">

  <?php if (!$groups): ?>

    <div class="card card-pad center" style="padding:56px 24px">
      <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
        <?= icon('check', 26) ?>
      </div>
      <h3>No image posted twice</h3>
      <p class="muted" style="max-width:52ch;margin-left:auto;margin-right:auto">
        Every picture in your calendar is used by a single post.
      </p>
    </div>

  <?php else: ?>

    <div class="stats" style="margin:0">
      <div class="stat s-red">   <div class="k">Live twice</div>    <div class="v"><?= (int)$sum['on_feed'] ?></div></div>
      <div class="stat s-yellow"><div class="k">Images reused</div> <div class="v"><?= (int)$sum['images'] ?></div></div>
      <div class="stat">         <div class="k">Extra posts</div>   <div class="v"><?= (int)$sum['extra'] ?></div></div>
    </div>

    <?php foreach ($groups as $g):
      $onFeed = $g['published'] > 1; ?>
      <div class="card">
        <div class="card-head">
          <h3>Used by <?= (int)$g['count'] ?> posts</h3>
          <?php if ($onFeed): ?>
            <span class="badge badge-failed" title="More than one of these actually published, so the picture is on the feed twice">on the feed <?= (int)$g['published'] ?>&times;</span>
          <?php else: ?>
            <span class="badge badge-scheduled">not published twice</span>
          <?php endif; ?>
        </div>

        <div class="card-pad">
          <div class="dup-set">
            <div class="dup-thumb">
              <img src="<?= e(rtrim(UPLOAD_URL, '/') . '/' . $g['file']['rel']) ?>" alt="" loading="lazy">
            </div>

            <div class="dup-posts">
              <?php foreach ($g['posts'] as $p):
                $when = $p['published_at'] ?: $p['scheduled_at'];
                $live = null;
                foreach ($p['targets'] as $t) {
                  if (!empty($t['remote_url'])) { $live = $t['remote_url']; break; }
                }
                ?>
                <div class="dup-post">
                  <span class="badge badge-<?= e($p['status']) ?>"><?= e($p['status']) ?></span>
                  <?php if ($p['status'] === 'published' && post_was_demo($p)): ?>
                    <span class="badge badge-draft" title="Recorded as published, but nothing was sent">demo only</span>
                  <?php endif; ?>

                  <span class="grow" style="min-width:0">
                    <span class="tiny muted"><?= $when ? e(date('j M Y, H:i', strtotime($when . ' UTC'))) : 'no date' ?></span>
                    <div class="tiny" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                      <?= e(str_limit($p['content'] ?: 'Media post', 70)) ?>
                    </div>
                  </span>

                  <?php if ($live): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($live) ?>" target="_blank" rel="noopener noreferrer">View live</a>
                  <?php endif; ?>
                  <a class="btn btn-ghost btn-sm" href="/queue.php#post-<?= (int)$p['id'] ?>">Open</a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <?php if ($onFeed): ?>
            <p class="small muted" style="margin:16px 0 0">
              This picture is live more than once. Deleting the extra from the network is done on
              the network &mdash; removing the post here does not take down something already
              published.
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>

  <p class="small muted">
    Matching is on image contents, so a copy saved under a different name is still found. A resized
    or re-exported version of the same photo is a different image and is not flagged. A post that
    uses one picture cropped two ways counts once, not twice.
  </p>

</div>

<?php layout_foot(); ?>
