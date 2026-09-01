<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        [$ok, $result] = template_save($uid, (int)($_POST['id'] ?? 0) ?: null, (string)($_POST['name'] ?? ''), [
            'content'       => $_POST['content'] ?? '',
            'link_url'      => $_POST['link_url'] ?? '',
            'media_ratio'   => $_POST['media_ratio'] ?? 'square',
            'alt_text'      => $_POST['alt_text'] ?? '',
            'first_comment' => $_POST['first_comment'] ?? '',
            'account_ids'   => (array)($_POST['accounts'] ?? []),
        ]);
        flash($ok ? 'success' : 'error', $ok ? 'Template saved.' : $result);
        if ($ok) {
            redirect('/templates.php');
        }
    }

    if ($do === 'delete') {
        template_delete((int)($_POST['id'] ?? 0), $uid);
        flash('success', 'Template deleted.');
        redirect('/templates.php');
    }
}

$templates = templates_for_user($uid);
$accounts  = accounts_for_user($uid);
$sets      = sets_for_user($uid);

$editing = null;
if (!empty($_GET['edit'])) {
    $editing = template_find((int)$_GET['edit'], $uid);
    if ($editing) {
        $editing['account_list'] = $editing['account_ids']
            ? array_map('intval', explode(',', $editing['account_ids'])) : [];
    }
}

layout_head('Templates', 'Post templates');
?>

<p class="muted" style="max-width:72ch">
  A template holds everything about a post except the picture and the moment it goes out — caption,
  hashtags, link, channels, image ratio, alt text and first comment. Build one, then each new post is
  three actions: pick the template, drop in an image, choose a slot.
</p>

<div class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">

  <!-- ---------------- Saved templates ---------------- -->
  <div class="grow" style="min-width:340px">
    <?php if (!$templates): ?>
      <div class="card card-pad center" style="padding:52px 24px">
        <div class="empty-badge"><?= icon('grid', 26) ?></div>
        <h3>No templates yet</h3>
        <p class="muted">Create one on the right, or build a post in the composer and hit
          <strong>Save as template</strong>.</p>
      </div>
    <?php else: ?>
      <div class="stack">
        <?php foreach ($templates as $t): ?>
          <div class="card tpl-card">
            <div class="card-head">
              <div class="row">
                <h3><?= e($t['name']) ?></h3>
                <?php if ($t['use_count']): ?>
                  <span class="badge">used <?= (int)$t['use_count'] ?>&times;</span>
                <?php endif; ?>
              </div>
              <div class="row" style="gap:6px">
                <a class="btn btn-soft btn-sm" href="/dashboard.php?template=<?= (int)$t['id'] ?>">Use</a>
                <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$t['id'] ?>">Edit</a>
                <form method="post" style="margin:0"
                      onsubmit="return confirm('Delete the template &quot;<?= e($t['name']) ?>&quot;?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash', 14) ?></button>
                </form>
              </div>
            </div>
            <div class="card-pad">
              <?php if ($t['content']): ?>
                <p class="small" style="white-space:pre-wrap;margin:0 0 12px"><?= e(str_limit($t['content'], 220)) ?></p>
              <?php endif; ?>
              <div class="row" style="gap:6px;flex-wrap:wrap">
                <?php foreach ($t['account_list'] as $aid):
                    $a = account_find($aid, $uid); if (!$a) continue; ?>
                  <span class="chip">
                    <span class="pdot pdot-sm" style="background:<?= e(platform_color($a['platform'])) ?>">
                      <?= platform_icon($a['platform'], 10) ?>
                    </span><?= e($a['display_name']) ?>
                  </span>
                <?php endforeach; ?>
                <?php if ($t['media_ratio']): ?>
                  <span class="chip"><?= e(media_ratio($t['media_ratio'])['label'] ?? $t['media_ratio']) ?></span>
                <?php endif; ?>
                <?php if ($t['tag_count']): ?>
                  <span class="chip"><?= (int)$t['tag_count'] ?> hashtags</span>
                <?php endif; ?>
                <?php if ($t['link_url']): ?>
                  <span class="chip"><?= icon('link', 11) ?> link</span>
                <?php endif; ?>
                <?php if ($t['first_comment']): ?>
                  <span class="chip">first comment</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ---------------- Create / edit ---------------- -->
  <div class="card" style="min-width:340px;flex:0 1 440px">
    <div class="card-head"><h3><?= $editing ? 'Edit template' : 'New template' ?></h3></div>
    <form method="post" class="card-pad">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

      <label class="field">
        <span>Template name</span>
        <input type="text" name="name" maxlength="80" required
               value="<?= e($editing['name'] ?? '') ?>" placeholder="FanExpo">
      </label>

      <?php if ($accounts): ?>
        <div class="field">
          <span class="label">Channels</span>
          <div class="acct-picker">
            <?php foreach ($accounts as $a):
                $on = $editing && in_array((int)$a['id'], $editing['account_list'], true); ?>
              <label class="acct<?= $on ? ' on' : '' ?>">
                <input type="checkbox" name="accounts[]" value="<?= (int)$a['id'] ?>" <?= $on ? 'checked' : '' ?>
                       onchange="this.closest('.acct').classList.toggle('on', this.checked)">
                <span class="pdot pdot-sm" style="background:<?= e(platform_color($a['platform'])) ?>">
                  <?= platform_icon($a['platform'], 10) ?>
                </span><?= e($a['display_name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <label class="field">
        <span>Caption</span>
        <textarea name="content" id="tpl-content" rows="7"
                  placeholder="Your standard caption, with the hashtags you always use."><?= e($editing['content'] ?? '') ?></textarea>
      </label>

      <?php if ($sets): ?>
        <div class="set-bar" style="margin:-8px 0 16px">
          <span class="tiny muted nowrap">Add a set:</span>
          <?php foreach ($sets as $hs): ?>
            <button type="button" class="set-chip"
                    onclick="addTagsTo('tpl-content', <?= htmlspecialchars(json_encode($hs['tag_list']), ENT_QUOTES) ?>)">
              <?= e($hs['name']) ?> <span class="tiny muted"><?= (int)$hs['tag_count'] ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="field">
        <span class="label">Image ratio</span>
        <select name="media_ratio">
          <?php foreach (media_ratios() as $key => $r): ?>
            <option value="<?= e($key) ?>" <?= ($editing['media_ratio'] ?? 'square') === $key ? 'selected' : '' ?>>
              <?= e($r['label'] . ' — ' . $r['hint']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <label class="field">
        <span>Link (optional)</span>
        <input type="url" name="link_url" value="<?= e($editing['link_url'] ?? '') ?>"
               placeholder="https://your-site.com">
      </label>

      <details class="more-options" <?= ($editing['alt_text'] ?? $editing['first_comment'] ?? '') ? 'open' : '' ?>>
        <summary>More options</summary>
        <div style="padding-top:16px">
          <label class="field">
            <span>Alt text</span>
            <input type="text" name="alt_text" maxlength="400" value="<?= e($editing['alt_text'] ?? '') ?>"
                   placeholder="Describe the image for screen readers">
          </label>
          <label class="field" style="margin-bottom:0">
            <span>First comment</span>
            <textarea name="first_comment" id="tpl-first" rows="3" maxlength="600"
                      style="min-height:76px"><?= e($editing['first_comment'] ?? '') ?></textarea>
          </label>
        </div>
      </details>

      <div class="row" style="margin-top:16px">
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create template' ?></button>
        <?php if ($editing): ?>
          <a class="btn btn-ghost" href="/templates.php">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

</div>

<script>
function addTagsTo(fieldId, tags) {
  var f = document.getElementById(fieldId);
  var have = {};
  (f.value.match(/(?:^|[^\w&])([#@][\p{L}\p{N}_.]+)/gu) || []).forEach(function (t) {
    have[t.trim().toLowerCase()] = true;
  });
  var fresh = tags.filter(function (t) { return !have[t.toLowerCase()]; });
  if (!fresh.length) return;
  f.value += (f.value.trim() === '' ? '' : '\n\n') + fresh.join(' ');
  f.focus();
}
</script>

<?php layout_foot(); ?>
