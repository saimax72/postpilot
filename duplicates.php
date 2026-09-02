<?php
/**
 * Duplicate image check.
 *
 * Flags the same photo appearing more than once in a user's media, and lets
 * them delete the copies nothing is using. Copies a post depends on are shown
 * but never removable from here - see dup_delete().
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'delete') {
        [$ok, $msg] = dup_delete($uid, (string)($_POST['rel'] ?? ''));
        flash($ok ? 'success' : 'error', $msg);
    }

    if ($do === 'delete_unused') {
        $gone = 0; $kept = 0;
        foreach (dup_groups($uid) as $g) {
            // Always leave the first copy, whether or not it is referenced -
            // "delete every unused duplicate" must never empty a whole group.
            foreach (array_slice($g['copies'], 1) as $c) {
                [$ok] = dup_delete($uid, $c['rel']);
                $ok ? $gone++ : $kept++;
            }
        }
        flash($gone ? 'success' : 'info',
            $gone === 0
                ? 'Nothing was removed — every duplicate is still used by a post.'
                : $gone . ' unused ' . ($gone === 1 ? 'copy' : 'copies') . ' deleted'
                  . ($kept ? ', ' . $kept . ' kept because posts still use them' : '') . '.');
    }

    redirect('/duplicates.php');
}

$refresh = isset($_GET['rescan']);
$groups  = dup_groups($uid, $refresh);
$sum     = dup_summary($groups);

layout_head('Duplicates', 'Duplicate images',
    '<a class="btn btn-ghost" href="/duplicates.php?rescan=1">' . icon('back', 15) . ' Rescan</a>');
?>

<div class="page-mid stack" style="gap:22px">

  <?php if (!$groups): ?>

    <div class="card card-pad center" style="padding:56px 24px">
      <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
        <?= icon('check', 26) ?>
      </div>
      <h3>No duplicates found</h3>
      <p class="muted" style="max-width:52ch;margin-left:auto;margin-right:auto">
        Every image in your library is unique. This compares file contents, so a copy saved under a
        different name is still caught &mdash; but a re-exported or resized version of the same photo
        counts as a different image.
      </p>
    </div>

  <?php else: ?>

    <div class="stats" style="margin:0">
      <div class="stat s-yellow"><div class="k">Duplicate sets</div><div class="v"><?= (int)$sum['groups'] ?></div></div>
      <div class="stat">        <div class="k">Extra copies</div> <div class="v"><?= (int)$sum['extra'] ?></div></div>
      <div class="stat s-pink">  <div class="k">Space used</div>   <div class="v"><?= e(human_size($sum['wasted'])) ?></div></div>
    </div>

    <div class="alert alert-info" style="align-items:flex-start">
      <?= icon('zap', 16) ?>
      <span>Copies that a post already uses are kept, even here &mdash; deleting one would leave a
      scheduled post with a missing image, and it would fail at publish time rather than now.
      Detach the post from the image first if you really want that copy gone.</span>
    </div>

    <?php if ($sum['extra'] > 1): ?>
      <form method="post" onsubmit="return confirm('Delete every duplicate copy that no post is using?')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete_unused">
        <button class="btn" type="submit"><?= icon('trash', 16) ?> Delete all unused copies</button>
      </form>
    <?php endif; ?>

    <?php foreach ($groups as $n => $g): ?>
      <div class="card">
        <div class="card-head">
          <h3>Set <?= $n + 1 ?> &middot; <?= (int)$g['count'] ?> identical files</h3>
          <div class="row" style="gap:6px">
            <?php if ($g['in_use'] >= $g['count']): ?>
              <span class="badge" title="Every copy is used by a post, so none can be removed here">all in use</span>
            <?php endif; ?>
            <span class="badge badge-publishing"><?= e(human_size($g['wasted'])) ?> extra</span>
          </div>
        </div>
        <div class="card-pad">
          <div class="dup-row">
            <?php foreach ($g['copies'] as $i => $c): $inUse = count($c['posts']); ?>
              <div class="dup-copy<?= $i === 0 ? ' is-keep' : '' ?>">
                <div class="dup-thumb">
                  <img src="<?= e(rtrim(UPLOAD_URL, '/') . '/' . $c['rel']) ?>" alt="" loading="lazy">
                  <?php if ($i === 0): ?><span class="dup-tag">keep</span><?php endif; ?>
                </div>

                <div class="tiny" style="word-break:break-all;margin-top:8px"><?= e($c['name']) ?></div>
                <div class="tiny muted"><?= e(human_size($c['size'])) ?></div>

                <?php if ($inUse): ?>
                  <div class="tiny" style="margin-top:6px">
                    <?php foreach ($c['posts'] as $post): ?>
                      <a href="/queue.php#post-<?= (int)$post['id'] ?>">Post #<?= (int)$post['id'] ?></a>
                      <span class="muted">(<?= e($post['status']) ?>)</span><br>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($i > 0): ?>
                  <form method="post" style="margin-top:8px"
                        onsubmit="return confirm('Delete this copy? No post is using it.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="rel" value="<?= e($c['rel']) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Delete copy</button>
                  </form>
                <?php else: ?>
                  <div class="tiny muted" style="margin-top:6px">Not used yet</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>

  <p class="small muted">
    Matching is on file contents, so renamed copies are found. A resized or re-exported version of
    the same photo is a different file and is not flagged.
  </p>

</div>

<?php layout_foot(); ?>
