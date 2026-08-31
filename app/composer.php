<?php
/**
 * The post composer modal. Included once per page that can create or edit a post.
 * Requires $accounts (array from accounts_for_user) to be in scope.
 */
?>
<div class="modal-backdrop hide" id="composer" role="dialog" aria-modal="true" aria-labelledby="composer-title">
  <div class="modal">
    <form id="composer-form" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="post_id"        id="c-post-id" value="">
      <input type="hidden" name="media_original" id="c-media-original" value="">
      <input type="hidden" name="media_ratio"    id="c-media-ratio" value="square">
      <input type="hidden" name="crop_fx" id="c-crop-fx" value="0">
      <input type="hidden" name="crop_fy" id="c-crop-fy" value="0">
      <input type="hidden" name="crop_fw" id="c-crop-fw" value="1">
      <input type="hidden" name="crop_fh" id="c-crop-fh" value="1">
      <input type="hidden" name="status"         id="c-status" value="scheduled">
      <input type="hidden" name="template_id"   id="c-template-id" value="">

      <div class="modal-head">
        <h2 id="composer-title">New post</h2>
        <button type="button" class="x-close" onclick="Composer.close()" aria-label="Close">&times;</button>
      </div>

      <div class="modal-body">
        <div id="c-error" class="alert alert-error hide"></div>
        <div id="c-note"  class="alert alert-success hide"></div>

        <!-- Shown instead of the editor once a post has gone out. -->
        <div id="c-sent" class="alert alert-info hide" style="display:block">
          <strong id="c-sent-head"></strong>
          <div id="c-sent-body" class="small" style="margin-top:8px"></div>
        </div>

        <?php $templates = templates_for_user((int)auth_id()); ?>
        <?php if ($templates): ?>
          <div class="tpl-bar">
            <span class="tiny muted nowrap">Start from</span>
            <?php foreach ($templates as $tpl): ?>
              <button type="button" class="tpl-chip" data-tpl="<?= (int)$tpl['id'] ?>">
                <?= e($tpl['name']) ?>
              </button>
            <?php endforeach; ?>
            <a class="tiny" href="/templates.php" target="_blank" rel="noopener">Manage</a>
          </div>
        <?php endif; ?>

        <div class="composer">
          <!-- ---------- Left: the post ---------- -->
          <div>
            <div class="field">
              <span class="label">Publish to</span>
              <?php if (!$accounts): ?>
                <div class="alert alert-info" style="margin:0">
                  No accounts connected yet. <a href="/accounts.php">Connect one</a> and it will appear here.
                </div>
              <?php else: ?>
                <div class="acct-picker" id="c-accounts">
                  <?php foreach ($accounts as $a): ?>
                    <label class="acct" data-platform="<?= e($a['platform']) ?>" data-limit="<?= (int)platform_limit($a['platform']) ?>">
                      <input type="checkbox" name="accounts[]" value="<?= (int)$a['id'] ?>">
                      <span class="pdot pdot-sm" style="background:<?= e(platform_color($a['platform'])) ?>">
                        <?= platform_icon($a['platform'], 10) ?>
                      </span>
                      <?= e($a['display_name']) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="field">
              <div class="row-between" style="margin-bottom:6px">
                <span class="label" style="margin:0">Caption</span>
                <span class="counter" id="c-counter">0 characters</span>
              </div>
              <textarea name="content" id="c-content" placeholder="What are you sharing?&#10;&#10;#hashtags go here" rows="6"></textarea>
              <div class="row-between" style="margin-top:6px">
                <span class="hint" id="c-limit-hint" style="margin:0"></span>
                <span class="hint" id="c-tag-count" style="margin:0"></span>
              </div>

              <?php $sets = sets_for_user((int)auth_id()); ?>
              <div class="set-bar" style="margin-top:10px">
                <span class="tiny muted nowrap">Hashtag sets:</span>
                <?php if ($sets): ?>
                  <?php foreach ($sets as $hs): ?>
                    <button type="button" class="set-chip"
                            data-set="<?= (int)$hs['id'] ?>" data-target="c-content"
                            title="<?= e(implode(' ', $hs['tag_list'])) ?>">
                      <?= e($hs['name']) ?> <span class="tiny muted"><?= (int)$hs['tag_count'] ?></span>
                    </button>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="tiny muted">none yet</span>
                <?php endif; ?>
                <a class="tiny" href="/hashtags.php" target="_blank" rel="noopener">Manage</a>
              </div>
            </div>

            <label class="field">
              <span>Link (optional)</span>
              <input type="url" name="link_url" id="c-link" placeholder="https://your-site.com/offer">
            </label>

            <details class="more-options">
              <summary>More options</summary>
              <div style="padding-top:16px">
                <label class="field">
                  <span>Alt text</span>
                  <input type="text" name="alt_text" id="c-alt" maxlength="400"
                         placeholder="Describe the image for people using a screen reader">
                  <span class="hint">Sent to Instagram with the image. Good for accessibility, and it is indexed.</span>
                </label>

                <label class="field" style="margin-bottom:0">
                  <span>First comment</span>
                  <textarea name="first_comment" id="c-first" rows="3" maxlength="600"
                            style="min-height:76px"
                            placeholder="#hashtags #you #would #rather #keep #out #of #the #caption"></textarea>
                  <span class="hint">
                    Posted as a comment straight after publishing — the usual place to park a wall of hashtags.
                    Instagram only; needs the <span class="mono">instagram_manage_comments</span> permission.
                  </span>
                  <?php if ($sets): ?>
                    <div class="set-bar" style="margin-top:8px">
                      <span class="tiny muted nowrap">Insert set:</span>
                      <?php foreach ($sets as $hs): ?>
                        <button type="button" class="set-chip"
                                data-set="<?= (int)$hs['id'] ?>" data-target="c-first"
                                title="<?= e(implode(' ', $hs['tag_list'])) ?>">
                          <?= e($hs['name']) ?> <span class="tiny muted"><?= (int)$hs['tag_count'] ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </label>
              </div>
            </details>

          </div>

          <!-- ---------- Middle: media + cropper ---------- -->
          <div class="composer-media">
            <!-- ---------- Media + cropper ---------- -->
            <div class="field">
              <span class="label">Media</span>

              <div class="dropzone" id="c-dropzone">
                <?= icon('image', 22) ?>
                <div style="margin-top:6px">Drop an image or video here, or <strong>browse</strong></div>
                <div class="tiny">JPG, PNG, GIF, WEBP, MP4 or MOV · up to <?= round(MAX_UPLOAD_BYTES / 1048576) ?> MB</div>
              </div>
              <input type="file" name="media" id="c-media" accept="image/*,video/mp4,video/quicktime" class="hide">

              <div class="cropper hide" id="c-cropper">
                <div class="ratio-picker" id="c-ratios">
                  <?php foreach (media_ratios() as $key => $r): ?>
                    <button type="button" class="ratio-btn<?= $key === 'square' ? ' on' : '' ?>"
                            data-ratio="<?= e($key) ?>" title="<?= e($r['note']) ?>">
                      <span class="ratio-box r-<?= e($key) ?>"></span>
                      <span><?= e($r['label']) ?></span>
                      <span class="tiny muted"><?= e($r['hint']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>

                <div class="crop-stage">
                  <div class="crop-frame" id="c-crop-frame">
                    <img id="c-crop-img" alt="" draggable="false">
                    <div class="crop-guides"></div>
                  </div>
                  <div class="crop-controls">
                    <span class="tiny muted nowrap">Zoom</span>
                    <input type="range" id="c-zoom" min="1" max="3" step="0.01" value="1">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="Cropper.reset()">Reset</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="Composer.clearMedia()">Remove</button>
                  </div>
                  <p class="tiny muted" style="margin:8px 0 0">
                    Drag the image to reposition it. What you see inside the frame is exactly what gets posted —
                    the crop is written into the file before it is sent.
                  </p>
                </div>

                <div class="video-note hide" id="c-video-note">
                  <div class="alert alert-warn" style="margin:0">
                    <?= icon('image', 16) ?>
                    <span>Video is uploaded as-is — cropping and repositioning apply to images only.
                    Shoot or trim to your target ratio before uploading.</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ---------- Right: when + preview ---------- -->
          <div>
            <div class="field">
              <span class="label">Date</span>
              <input type="date" name="date" id="c-date" required>
            </div>
            <div class="field">
              <span class="label">Time (<?= e(str_replace('_', ' ', user_tz())) ?>)</span>
              <input type="time" name="time" id="c-time" required>
            </div>

            <div class="field">
              <div class="row-between" style="margin-bottom:6px">
                <span class="label" style="margin:0">Preview</span>
                <span class="tiny muted" id="pv-platform-label"></span>
              </div>

              <div class="preview-phone">
                <div class="pv-head">
                  <span class="pdot" id="pv-dot" style="background:var(--brand)"></span>
                  <span class="grow" style="min-width:0">
                    <div class="pv-name" id="pv-name">Your account</div>
                    <div class="pv-handle" id="pv-handle">now</div>
                  </span>
                  <span class="tiny muted" style="letter-spacing:2px">···</span>
                </div>

                <div class="pv-media hide" id="pv-media">
                  <div class="pv-frame" id="pv-frame"><img id="pv-img" alt=""></div>
                </div>
                <video id="pv-video" class="hide" muted playsinline style="width:100%;border-radius:8px"></video>

                <div class="pv-actions" id="pv-actions">
                  <span>♥</span><span>💬</span><span>➤</span>
                </div>

                <div class="pv-body" id="pv-body" style="color:var(--muted)">Your caption will appear here.</div>
                <div class="pv-link tiny hide" id="pv-link"></div>
                <div class="pv-meta tiny muted" id="pv-meta"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost btn-sm hide" id="c-delete" onclick="Composer.remove()">
          <?= icon('trash', 15) ?> Delete
        </button>
        <div class="row" style="margin-left:auto;flex-wrap:wrap" id="c-actions">
          <button type="button" class="btn btn-ghost btn-sm" onclick="Composer.saveTemplate()">
            Save as template
          </button>
          <button type="button" class="btn btn-ghost" onclick="Composer.save('draft')">Draft</button>
          <button type="button" class="btn btn-soft" onclick="Composer.save('scheduled', true)"
                  title="Schedule this one and immediately start the next with the same caption">
            Schedule &amp; add another
          </button>
          <button type="submit" class="btn"><?= icon('send', 16) ?> Schedule post</button>
        </div>
        <button type="button" class="btn hide" id="c-close-btn" style="margin-left:auto"
                onclick="Composer.close()">Close</button>
      </div>
    </form>
  </div>
</div>
