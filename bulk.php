<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';

$user      = require_login();
$uid       = (int)$user['id'];
$accounts  = accounts_for_user($uid);
$templates = templates_for_user($uid);
$sets      = sets_for_user($uid);

layout_head('Bulk upload', 'Bulk upload',
    '<a class="btn btn-ghost btn-sm" href="/dashboard.php">' . icon('calendar', 15) . ' Calendar</a>');
?>

<?php if (!$accounts): ?>
  <div class="alert alert-info">
    <?= icon('link', 18) ?>
    <span><strong>Connect a channel first.</strong> <a href="/accounts.php">Add one</a>, then come back.</span>
  </div>
<?php else: ?>

<p class="muted" style="max-width:74ch">
  Drop in a pile of images, say how often you want them going out, and PostPilot spreads them
  across the calendar. Each one is centre-cropped to your chosen ratio — open any post afterwards
  to re-frame it by hand.
</p>

<div id="bulk-error" class="alert alert-error hide"></div>

<div class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">

  <!-- ============ Left: settings ============ -->
  <div class="card" style="min-width:330px;flex:0 1 400px">
    <div class="card-head"><h3>1 · What to post</h3></div>
    <div class="card-pad">

      <?php if ($templates): ?>
        <div class="field">
          <span class="label">Start from a template</span>
          <div class="tpl-bar" style="margin:0">
            <?php foreach ($templates as $t): ?>
              <button type="button" class="tpl-chip" data-tpl="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="field">
        <span class="label">Channels</span>
        <div class="acct-picker" id="b-accounts">
          <?php foreach ($accounts as $a): ?>
            <label class="acct">
              <input type="checkbox" value="<?= (int)$a['id'] ?>"
                     onchange="this.closest('.acct').classList.toggle('on', this.checked)">
              <span class="pdot pdot-sm" style="background:<?= e(platform_color($a['platform'])) ?>">
                <?= platform_icon($a['platform'], 10) ?>
              </span><?= e($a['display_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <div class="row-between" style="margin-bottom:7px">
          <span class="label" style="margin:0">Caption applied to every post</span>
          <span class="counter" id="b-counter">0</span>
        </div>
        <textarea id="b-caption" rows="6"
                  placeholder="The caption these posts share. You can override individual ones below."></textarea>
      </div>

      <?php if ($sets): ?>
        <div class="set-bar" style="margin:-8px 0 16px">
          <span class="tiny muted nowrap">Add a set:</span>
          <?php foreach ($sets as $hs): ?>
            <button type="button" class="set-chip"
                    data-tags="<?= e(implode(' ', $hs['tag_list'])) ?>">
              <?= e($hs['name']) ?> <span class="tiny muted"><?= (int)$hs['tag_count'] ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="field">
        <span class="label">Image ratio</span>
        <select id="b-ratio">
          <?php foreach (media_ratios() as $key => $r): ?>
            <option value="<?= e($key) ?>"><?= e($r['label'] . ' — ' . $r['hint']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Every image is centre-cropped to this shape.</span>
      </div>

      <label class="field" style="margin-bottom:0">
        <span>Link (optional)</span>
        <input type="url" id="b-link" placeholder="https://your-site.com">
      </label>
    </div>

    <div class="card-head" style="border-top:1px solid var(--line)"><h3>2 · How often</h3></div>
    <div class="card-pad">
      <label class="field">
        <span>Start on</span>
        <input type="date" id="b-start">
      </label>

      <label class="field">
        <span>Times of day</span>
        <input type="text" id="b-times" value="09:00" placeholder="09:00, 17:00">
        <span class="hint">
          Comma separated. Two times means two posts a day, and so on.
          Shown in <?= e(str_replace('_', ' ', user_tz())) ?>.
        </span>
      </label>

      <div class="field">
        <span class="label">Days to post on</span>
        <div class="acct-picker" id="b-days">
          <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d): ?>
            <label class="acct on" style="padding:7px 12px">
              <input type="checkbox" value="<?= $i + 1 ?>" checked
                     onchange="this.closest('.acct').classList.toggle('on', this.checked)">
              <?= $d ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="field" style="margin-bottom:0">
        <span>Skip ahead</span>
        <select id="b-interval">
          <option value="1">Every eligible day</option>
          <option value="2">Every other day</option>
          <option value="3">Every third day</option>
          <option value="7">Once a week</option>
        </select>
      </label>
    </div>
  </div>

  <!-- ============ Right: images + preview ============ -->
  <div class="grow" style="min-width:340px">
    <div class="card">
      <div class="card-head">
        <h3>3 · Images</h3>
        <div class="row" style="gap:8px">
          <span class="badge" id="b-count">0 images</span>
          <button class="btn btn-ghost btn-sm" type="button" onclick="Bulk.clear()">Clear all</button>
        </div>
      </div>
      <div class="card-pad">
        <div class="dropzone" id="b-dropzone">
          <?= icon('image', 22) ?>
          <div style="margin-top:6px">Drop images here, or <strong>browse</strong></div>
          <div class="tiny">
            Up to 200 per batch · <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB each ·
            they upload one at a time so nothing is dropped
          </div>
        </div>
        <input type="file" id="b-files" accept="image/*,video/mp4,video/quicktime" multiple class="hide">

        <div class="bulk-progress hide" id="b-progress">
          <div class="bulk-bar"><span id="b-bar"></span></div>
          <div class="tiny muted" id="b-progress-text"></div>
        </div>

        <div class="bulk-grid" id="b-grid"></div>
      </div>
      <div class="card-head" style="border-top:1px solid var(--line);border-bottom:0">
        <span class="small muted" id="b-summary">Nothing queued yet.</span>
        <div class="row" style="gap:8px">
          <button class="btn btn-ghost" type="button" onclick="Bulk.create('draft')">Add as drafts</button>
          <button class="btn" type="button" id="b-go" onclick="Bulk.create('scheduled')">
            <?= icon('calendar', 16) ?> Add to calendar
          </button>
        </div>
      </div>
    </div>
  </div>

</div>

<?php endif; ?>

<?php
$payload = '<script>window.BULK = ' . json_encode([
    'csrf'      => csrf_token(),
    'tz'        => user_tz(),
    'accounts'  => array_map(fn($a) => [
        'id'    => (int)$a['id'],
        'name'  => $a['display_name'],
        'limit' => platform_limit($a['platform']),
    ], $accounts),
    'templates' => array_map(fn($t) => [
        'id'       => (int)$t['id'],
        'name'     => $t['name'],
        'content'  => (string)$t['content'],
        'link'     => (string)$t['link_url'],
        'ratio'    => $t['media_ratio'] ?: 'square',
        'accounts' => $t['account_list'],
    ], $templates),
], JSON_UNESCAPED_SLASHES) . ';</script>'
. '<script src="' . asset('/assets/js/bulk.js') . '"></script>';

layout_foot($payload);
