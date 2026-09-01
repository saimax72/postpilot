<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        [$ok, $result] = set_save(
            $uid,
            (int)($_POST['id'] ?? 0) ?: null,
            (string)($_POST['name'] ?? ''),
            (string)($_POST['tags'] ?? '')
        );
        flash($ok ? 'success' : 'error', $ok ? 'Hashtag set saved.' : $result);
    }

    if ($do === 'delete') {
        set_delete((int)($_POST['id'] ?? 0), $uid);
        flash('success', 'Set deleted.');
    }

    redirect('/hashtags.php');
}

$sets    = sets_for_user($uid);
$editing = null;
if (!empty($_GET['edit'])) {
    $editing = set_find((int)$_GET['edit'], $uid);
}

layout_head('Hashtag sets', 'Hashtag sets',
    '<button class="btn" onclick="document.getElementById(\'set-form\').scrollIntoView({behavior:\'smooth\'});document.getElementById(\'set-name\').focus()">'
    . icon('plus', 16) . ' New set</button>');
?>

<p class="muted" style="max-width:72ch">
  Bundle the hashtags and @mentions you reuse into a named set, then drop the whole lot into a
  caption or a first comment with one click. Everything is cleaned up as you save — a bare word
  becomes a hashtag, anything starting with <span class="mono">@</span> stays a mention,
  duplicates are removed, and case is ignored when comparing.
</p>

<div class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">

  <!-- ---------------- Existing sets ---------------- -->
  <div class="grow" style="min-width:320px">
    <?php if (!$sets): ?>
      <div class="card card-pad center" style="padding:50px 24px">
        <div style="width:56px;height:56px;border-radius:16px;background:var(--brand-50);color:var(--brand);display:grid;place-items:center;margin:0 auto 16px">
          <?= icon('list', 26) ?>
        </div>
        <h3>No sets yet</h3>
        <p class="muted">Create one on the right — a name, then the hashtags and @mentions you want in it.</p>
      </div>
    <?php else: ?>
      <div class="stack">
        <?php foreach ($sets as $s): ?>
          <div class="card">
            <div class="card-head">
              <div class="row">
                <h3><?= e($s['name']) ?></h3>
                <?php
                 $mentions = count(array_filter($s['tag_list'], fn($t) => str_starts_with($t, '@')));
                 $tags     = $s['tag_count'] - $mentions;
                ?>
                <?php if ($tags): ?>
                  <span class="badge"><?= $tags ?> hashtag<?= $tags === 1 ? '' : 's' ?></span>
                <?php endif; ?>
                <?php if ($mentions): ?>
                  <span class="badge badge-scheduled"><?= $mentions ?> mention<?= $mentions === 1 ? '' : 's' ?></span>
                <?php endif; ?>
                <?php if ($tags > 30): ?>
                  <span class="badge badge-failed">over Instagram's 30</span>
                <?php endif; ?>
              </div>
              <div class="row" style="gap:6px">
                <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$s['id'] ?>">Edit</a>
                <button class="btn btn-ghost btn-sm" type="button"
                        onclick="copySet(<?= (int)$s['id'] ?>)">Copy</button>
                <form method="post" style="margin:0"
                      onsubmit="return confirm('Delete the set &quot;<?= e($s['name']) ?>&quot;?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash', 14) ?></button>
                </form>
              </div>
            </div>
            <div class="card-pad">
              <div class="tag-cloud" id="set-<?= (int)$s['id'] ?>">
                <?php foreach ($s['tag_list'] as $t): ?>
                  <span class="tag-pill<?= str_starts_with($t, '@') ? ' is-mention' : '' ?>"><?= e($t) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ---------------- Create / edit ---------------- -->
  <div class="card" style="min-width:320px;flex:0 1 400px" id="set-form">
    <div class="card-head"><h3><?= $editing ? 'Edit set' : 'New set' ?></h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

      <label class="field">
        <span>Set name</span>
        <input type="text" name="name" id="set-name" maxlength="80" required
               value="<?= e($editing['name'] ?? '') ?>"
               placeholder="Cosplay photography">
      </label>

      <label class="field">
        <span>Hashtags and mentions</span>
        <textarea name="tags" rows="8" required
                  placeholder="#cosplay #portrait @fanexpocanada&#10;&#10;or just: cosplay, portrait, @thecosplayer"><?= e($editing['tags'] ?? '') ?></textarea>
        <span class="hint">
          Separate with spaces, commas or new lines. A bare word becomes a hashtag; start it with
          <span class="mono">@</span> to keep it as a mention. Up to <?= MAX_TAGS_PER_SET ?> per set.
        </span>
      </label>

      <div class="row">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create set' ?></button>
        <?php if ($editing): ?>
          <a class="btn btn-ghost" href="/hashtags.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

</div>

<script>
function copySet(id) {
  var tags = Array.prototype.map.call(
    document.querySelectorAll('#set-' + id + ' .tag-pill'),
    function (el) { return el.textContent.trim(); }
  ).join(' ');

  navigator.clipboard.writeText(tags).then(function () {
    alert('Copied ' + tags.split(' ').length + ' items to your clipboard.');
  });
}
</script>

<?php layout_foot(); ?>
