/* ==========================================================================
   PostPilot — composer, cropper, live preview, calendar drag & drop
   Expects window.PP = { posts, accounts, tz, ratios }
   ========================================================================== */

(function () {
  'use strict';

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  var PP = window.PP || { posts: {}, accounts: [], ratios: {} };

  // Width / height for each named ratio. Mirrors media_ratios() in PHP.
  var RATIOS = PP.ratios || {
    square:    1 / 1,
    portrait:  4 / 5,
    landscape: 1.91 / 1,
    story:     9 / 16
  };

  /* ---------------------------------------------------------------- helpers */

  function csrf() {
    var el = $('input[name="_csrf"]');
    return el ? el.value : '';
  }

  function accountById(id) {
    for (var i = 0; i < PP.accounts.length; i++) {
      if (PP.accounts[i].id === Number(id)) return PP.accounts[i];
    }
    return null;
  }

  function todayLocal() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function nextRoundHour() {
    var d = new Date(Date.now() + 60 * 60 * 1000);
    return String(d.getHours()).padStart(2, '0') + ':00';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ==========================================================================
     Cropper — a viewfinder over the original image.

     It never modifies pixels. It records which fraction of the source sits
     inside the frame; PHP does the real crop with GD at save time.
     ========================================================================== */

  var Cropper = {

    ratio: 'square',
    natW: 0, natH: 0,   // natural size of the source image
    scale: 1,           // px on screen per source px
    baseScale: 1,       // the scale at which the image exactly covers the frame
    tx: 0, ty: 0,       // image offset inside the frame, both <= 0
    ready: false,

    frame: function () { return $('#c-crop-frame'); },
    img:   function () { return $('#c-crop-img'); },

    /** Point the cropper at a URL. Called on upload and when editing a post. */
    load: function (url, ratio, box) {
      var self = this;
      this.ready = false;
      this.ratio = ratio || 'square';
      this.setRatioButtons();

      var probe = new Image();
      probe.onload = function () {
        self.natW = probe.naturalWidth;
        self.natH = probe.naturalHeight;
        self.img().src = url;
        self.shapeFrame();

        if (box && box.fw > 0 && box.fw <= 1) {
          self.applyBox(box);
        } else {
          self.reset();
        }
        self.ready = true;
        self.commit();
      };
      probe.onerror = function () {
        // Not a readable image (or a video) — leave the cropper hidden.
        self.ready = false;
      };
      probe.src = url;
    },

    /**
     * Size the frame to the current ratio, within a height budget.
     * A 9:16 frame at full column width is ~600px tall, which pushes the rest
     * of the composer off screen - so tall ratios lose width instead.
     */
    maxHeight: 400,

    shapeFrame: function () {
      var f = this.frame();
      if (!f) return;

      var stage = f.parentElement;
      var avail = (stage ? stage.clientWidth : 0) || 340;
      var w = avail;
      var h = w / RATIOS[this.ratio];

      if (h > this.maxHeight) {
        h = this.maxHeight;
        w = h * RATIOS[this.ratio];
      }

      f.style.width  = Math.round(w) + 'px';
      f.style.height = Math.round(h) + 'px';
    },

    /** Smallest scale at which the image still covers the frame. */
    fit: function () {
      var f = this.frame();
      var fw = f.clientWidth, fh = f.clientHeight;
      if (!this.natW || !this.natH) return 1;
      return Math.max(fw / this.natW, fh / this.natH);
    },

    reset: function () {
      this.shapeFrame();
      this.baseScale = this.fit();
      this.scale = this.baseScale;

      var f = this.frame();
      // Centre the image in the frame.
      this.tx = (f.clientWidth  - this.natW * this.scale) / 2;
      this.ty = (f.clientHeight - this.natH * this.scale) / 2;

      var z = $('#c-zoom');
      if (z) z.value = 1;

      this.clamp();
      this.paint();
      this.commit();
    },

    setZoom: function (z) {
      var f = this.frame();
      var cx = f.clientWidth / 2, cy = f.clientHeight / 2;

      // Keep whatever is under the centre of the frame fixed while zooming.
      var before = this.scale;
      this.baseScale = this.fit();
      this.scale = this.baseScale * z;

      var k = this.scale / before;
      this.tx = cx - (cx - this.tx) * k;
      this.ty = cy - (cy - this.ty) * k;

      this.clamp();
      this.paint();
      this.commit();
    },

    setRatio: function (key) {
      if (!RATIOS[key]) return;
      this.ratio = key;
      $('#c-media-ratio').value = key;
      this.setRatioButtons();
      this.reset();
      Composer.refresh();
    },

    setRatioButtons: function () {
      var self = this;
      $$('#c-ratios .ratio-btn').forEach(function (b) {
        b.classList.toggle('on', b.dataset.ratio === self.ratio);
      });
      var r = $('#c-media-ratio');
      if (r) r.value = this.ratio;
    },

    /** Never let the frame show empty space. */
    clamp: function () {
      var f = this.frame();
      var dw = this.natW * this.scale, dh = this.natH * this.scale;
      this.tx = Math.min(0, Math.max(f.clientWidth  - dw, this.tx));
      this.ty = Math.min(0, Math.max(f.clientHeight - dh, this.ty));
      if (dw <= f.clientWidth)  this.tx = (f.clientWidth  - dw) / 2;
      if (dh <= f.clientHeight) this.ty = (f.clientHeight - dh) / 2;
    },

    paint: function () {
      var i = this.img();
      if (!i) return;
      i.style.width  = (this.natW * this.scale) + 'px';
      i.style.height = (this.natH * this.scale) + 'px';
      i.style.left   = this.tx + 'px';
      i.style.top    = this.ty + 'px';
    },

    /** Write the framed region into the hidden inputs, as fractions 0..1. */
    box: function () {
      var f = this.frame();
      var fw = f.clientWidth, fh = f.clientHeight;
      var fx = (-this.tx / this.scale) / this.natW;
      var fy = (-this.ty / this.scale) / this.natH;
      var bw = (fw / this.scale) / this.natW;
      var bh = (fh / this.scale) / this.natH;

      var cl = function (v) { return Math.min(1, Math.max(0, v)) || 0; };
      return { fx: cl(fx), fy: cl(fy), fw: cl(bw), fh: cl(bh) };
    },

    commit: function () {
      if (!this.natW) return;
      var b = this.box();
      $('#c-crop-fx').value = b.fx.toFixed(6);
      $('#c-crop-fy').value = b.fy.toFixed(6);
      $('#c-crop-fw').value = b.fw.toFixed(6);
      $('#c-crop-fh').value = b.fh.toFixed(6);
      Composer.paintPreview();
    },

    /** Restore a saved crop when reopening a post. */
    applyBox: function (b) {
      this.shapeFrame();
      var f = this.frame();
      this.scale = (f.clientWidth / b.fw) / this.natW;
      this.baseScale = this.fit();
      this.tx = -b.fx * this.natW * this.scale;
      this.ty = -b.fy * this.natH * this.scale;

      var z = $('#c-zoom');
      if (z) z.value = Math.min(3, Math.max(1, this.scale / this.baseScale)).toFixed(2);

      this.clamp();
      this.paint();
    },

    bindDrag: function () {
      var self = this, dragging = false, lastX = 0, lastY = 0;
      var f = this.frame();
      if (!f) return;

      f.addEventListener('pointerdown', function (ev) {
        if (!self.ready) return;
        dragging = true;
        lastX = ev.clientX; lastY = ev.clientY;
        f.setPointerCapture(ev.pointerId);
        f.classList.add('grabbing');
      });

      f.addEventListener('pointermove', function (ev) {
        if (!dragging) return;
        self.tx += ev.clientX - lastX;
        self.ty += ev.clientY - lastY;
        lastX = ev.clientX; lastY = ev.clientY;
        self.clamp();
        self.paint();
      });

      ['pointerup', 'pointercancel'].forEach(function (t) {
        f.addEventListener(t, function () {
          if (!dragging) return;
          dragging = false;
          f.classList.remove('grabbing');
          self.commit();
        });
      });
    }
  };

  window.Cropper = Cropper;

  /* ==========================================================================
     Composer
     ========================================================================== */

  var Composer = {

    el: null,
    mediaUrl: null,
    isVideo: false,

    open: function (postId, date, time) {
      this.el = $('#composer');
      if (!this.el) return;

      var form = $('#composer-form');
      form.reset();

      $('#c-post-id').value        = '';
      $('#c-media-original').value = '';
      $('#c-media-ratio').value    = 'square';
      $('#c-status').value         = 'scheduled';
      $('#c-error').classList.add('hide');
      $('#c-note').classList.add('hide');
      $('#c-delete').classList.add('hide');
      $('#c-template-id').value = '';
      this.activeTemplate = null;
      $$('#composer .tpl-chip').forEach(function (c) { c.classList.remove('on'); });
      $('#composer-title').textContent = 'New post';
      this.clearMedia();

      $$('#c-accounts .acct').forEach(function (l) {
        l.classList.remove('on');
        l.querySelector('input').checked = false;
      });

      var post = postId ? PP.posts[postId] : null;

      if (post) {
        $('#composer-title').textContent = 'Edit post';
        $('#c-post-id').value = post.id;
        $('#c-content').value = post.content || '';
        $('#c-link').value    = post.link || '';
        $('#c-date').value    = post.date;
        $('#c-time').value    = post.time;
        $('#c-status').value  = post.status === 'draft' ? 'draft' : 'scheduled';
        $('#c-alt').value     = post.alt || '';
        $('#c-first').value   = post.first || '';
        $('#c-delete').classList.remove('hide');
        if (post.alt || post.first) {
            var more = document.querySelector('.more-options');
            if (more) more.open = true;
        }

        if (post.original) {
          $('#c-media-original').value = post.media_original || '';
          this.showMedia(post.original, post.is_video, post.ratio || 'square', post.crop || null);
        }

        (post.accounts || []).forEach(function (aid) {
          var input = $('#c-accounts input[value="' + aid + '"]');
          if (input) { input.checked = true; input.closest('.acct').classList.add('on'); }
        });

        if (post.error) {
          $('#c-error').textContent = post.error;
          $('#c-error').classList.remove('hide');
        }
      } else {
        $('#c-date').value = date || todayLocal();
        $('#c-time').value = time || nextRoundHour();

        if (PP.accounts.length === 1) {
          var only = $('#c-accounts input');
          if (only) { only.checked = true; only.closest('.acct').classList.add('on'); }
        }
      }

      this.el.classList.remove('hide');
      document.body.style.overflow = 'hidden';
      this.refresh();
      setTimeout(function () { $('#c-content').focus(); }, 40);
    },

    close: function () {
      if (this.pendingReload) {
        window.location.reload();
        return;
      }
      if (this.el) this.el.classList.add('hide');
      document.body.style.overflow = '';
    },

    activeLimit: function () {
      var limits = $$('#c-accounts input:checked').map(function (i) {
        var a = accountById(i.value);
        return a ? a.limit : 99999;
      });
      return limits.length ? Math.min.apply(null, limits) : null;
    },

    refresh: function () {
      var text  = $('#c-content').value;
      var len   = Array.from(text).length;
      var limit = this.activeLimit();
      var el    = $('#c-counter');

      el.classList.remove('warn', 'over');
      if (limit === null) {
        el.textContent = len + ' characters';
        $('#c-limit-hint').textContent = 'Pick an account to see its character limit.';
      } else {
        el.textContent = len.toLocaleString() + ' / ' + limit.toLocaleString();
        if (len > limit) el.classList.add('over');
        else if (len > limit * 0.9) el.classList.add('warn');

        var tightest = null;
        $$('#c-accounts input:checked').forEach(function (i) {
          var a = accountById(i.value);
          if (a && a.limit === limit) tightest = a;
        });
        $('#c-limit-hint').textContent = tightest
          ? tightest.name + ' has the tightest limit here.'
          : '';
      }

      // Hashtag / mention counts
      var tags = text.match(/(?:^|[^\w&])#([\p{L}\p{N}_]+)/gu) || [];
      var tagEl = $('#c-tag-count');
      tagEl.textContent = tags.length
        ? tags.length + (tags.length === 1 ? ' hashtag' : ' hashtags') + (tags.length > 30 ? ' — Instagram caps at 30' : '')
        : '';
      tagEl.style.color = tags.length > 30 ? 'var(--red)' : '';

      this.paintPreview();
    },

    /** Render the preview panel: account, framed image, caption, hashtags. */
    paintPreview: function () {
      var text  = $('#c-content').value;
      var first = $('#c-accounts input:checked');
      var acct  = first ? accountById(first.value) : null;

      $('#pv-dot').style.background = acct ? acct.color : 'var(--brand)';
      $('#pv-name').textContent     = acct ? acct.name : 'Your account';
      $('#pv-handle').textContent   = acct && acct.handle ? acct.handle : ($('#c-time').value || 'now');
      $('#pv-platform-label').textContent = acct ? 'as it will look on ' + acct.platformLabel : '';

      // Caption with hashtags and mentions highlighted, like the real apps.
      var body = $('#pv-body');
      if (text) {
        body.innerHTML = escapeHtml(text)
          .replace(/(^|[^\w&])(#[\p{L}\p{N}_]+)/gu, '$1<span class="tag">$2</span>')
          .replace(/(^|[^\w&])(@[\p{L}\p{N}_.]+)/gu, '$1<span class="tag">$2</span>')
          .replace(/\n/g, '<br>');
        body.style.color = '';
      } else {
        body.textContent = 'Your caption will appear here.';
        body.style.color = 'var(--muted)';
      }

      // Link row
      var link = $('#c-link').value.trim();
      var linkEl = $('#pv-link');
      if (link) {
        linkEl.textContent = link.replace(/^https?:\/\//, '').slice(0, 60);
        linkEl.classList.remove('hide');
      } else {
        linkEl.classList.add('hide');
      }

      // Framed image, mirroring the cropper exactly
      var media = $('#pv-media'), vid = $('#pv-video');
      if (this.mediaUrl && this.isVideo) {
        media.classList.add('hide');
        vid.src = this.mediaUrl;
        vid.classList.remove('hide');
      } else if (this.mediaUrl && Cropper.ready) {
        vid.classList.add('hide');
        media.classList.remove('hide');

        var frame = $('#pv-frame'), img = $('#pv-img');
        var b  = Cropper.box();

        // Same height budget as the cropper, so a tall preview cannot run off
        // the bottom of the panel.
        var pw = (frame.parentElement ? frame.parentElement.clientWidth : 0) || 240;
        var ph = pw / RATIOS[Cropper.ratio];
        var cap = 320;
        if (ph > cap) { ph = cap; pw = ph * RATIOS[Cropper.ratio]; }

        frame.style.width  = Math.round(pw) + 'px';
        frame.style.height = Math.round(ph) + 'px';
        img.src = this.mediaUrl;
        img.style.width  = (pw / b.fw) + 'px';
        img.style.height = (ph / b.fh) + 'px';
        img.style.left   = (-b.fx * (pw / b.fw)) + 'px';
        img.style.top    = (-b.fy * (ph / b.fh)) + 'px';
      } else {
        media.classList.add('hide');
        vid.classList.add('hide');
      }

      $('#pv-actions').classList.toggle('hide', !this.mediaUrl);

      // Footer meta: character and hashtag counts, and the ratio in use
      var tags = (text.match(/(?:^|[^\w&])#([\p{L}\p{N}_]+)/gu) || []).length;
      var bits = [Array.from(text).length + ' characters'];
      if (tags) bits.push(tags + ' hashtag' + (tags === 1 ? '' : 's'));
      if (this.mediaUrl && !this.isVideo) {
        bits.push((PP.ratioLabels && PP.ratioLabels[Cropper.ratio]) || Cropper.ratio);
      }
      $('#pv-meta').textContent = bits.join(' · ');
    },

    showMedia: function (url, isVideo, ratio, box) {
      this.mediaUrl = url;
      this.isVideo  = !!isVideo;

      $('#c-dropzone').classList.add('hide');
      $('#c-cropper').classList.remove('hide');
      $('#c-video-note').classList.toggle('hide', !isVideo);
      $('.crop-stage').classList.toggle('hide', !!isVideo);
      $('#c-ratios').classList.toggle('hide', !!isVideo);

      if (isVideo) {
        Cropper.ready = false;
        this.paintPreview();
      } else {
        Cropper.load(url, ratio || 'square', box);
      }
    },

    clearMedia: function () {
      this.mediaUrl = null;
      this.isVideo  = false;
      Cropper.ready = false;

      $('#c-media').value = '';
      $('#c-media-original').value = '';
      $('#c-dropzone').classList.remove('hide');
      $('#c-cropper').classList.add('hide');
      $('#c-crop-fx').value = 0;
      $('#c-crop-fy').value = 0;
      $('#c-crop-fw').value = 1;
      $('#c-crop-fh').value = 1;
      var img = $('#c-crop-img');
      if (img) img.removeAttribute('src');
      this.paintPreview();
    },

    /**
     * Drop a saved hashtag set into a field, skipping any tag already present.
     * Comparison is case-insensitive, the way the networks treat tags.
     */
    insertSet: function (setId, targetId) {
      var set = null;
      (PP.sets || []).forEach(function (s) { if (s.id === Number(setId)) set = s; });
      if (!set) return;

      var field   = $('#' + targetId);
      var current = field.value;
      var have    = {};

      (current.match(/(?:^|[^\w&])#([\p{L}\p{N}_]+)/gu) || []).forEach(function (t) {
        have[t.trim().toLowerCase()] = true;
      });

      var fresh = set.tags.filter(function (t) { return !have[t.toLowerCase()]; });

      if (!fresh.length) {
        this.toast(field, 'Every tag in "' + set.name + '" is already there');
        return;
      }

      // Two blank lines before a hashtag block is the usual convention.
      var sep = current.trim() === '' ? '' : (/\n$/.test(current) ? '' : '\n\n');
      field.value = current + sep + fresh.join(' ');

      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.focus();
      field.setSelectionRange(field.value.length, field.value.length);

      this.toast(field, 'Added ' + fresh.length + ' tag' + (fresh.length === 1 ? '' : 's')
        + (fresh.length < set.tags.length ? ' (' + (set.tags.length - fresh.length) + ' already present)' : ''));
    },

    /** Small transient note under a field — cheaper than a full toast system. */
    toast: function (field, message) {
      var bar = field.closest('.field').querySelector('.set-bar');
      if (!bar) return;
      var note = bar.querySelector('.set-note');
      if (!note) {
        note = document.createElement('span');
        note.className = 'set-note tiny';
        bar.appendChild(note);
      }
      note.textContent = message;
      clearTimeout(note._t);
      note._t = setTimeout(function () { note.textContent = ''; }, 3500);
    },

    /* ---------------- Templates ---------------- */

    templateById: function (id) {
      var found = null;
      (PP.templates || []).forEach(function (t) { if (t.id === Number(id)) found = t; });
      return found;
    },

    /** Fill the composer from a saved template, leaving image and time alone. */
    applyTemplate: function (id) {
      var t = this.templateById(id);
      if (!t) return;

      $('#c-content').value = t.content || '';
      $('#c-link').value    = t.link || '';
      $('#c-alt').value     = t.alt || '';
      $('#c-first').value   = t.first || '';
      $('#c-template-id').value = t.id;

      $$('#c-accounts .acct').forEach(function (l) {
        var input = l.querySelector('input');
        var on = (t.accounts || []).indexOf(Number(input.value)) !== -1;
        input.checked = on;
        l.classList.toggle('on', on);
      });

      if (t.alt || t.first) {
        var more = document.querySelector('#composer .more-options');
        if (more) more.open = true;
      }

      // The ratio only bites once an image is loaded.
      if (Cropper.ready) Cropper.setRatio(t.ratio || 'square');
      else { Cropper.ratio = t.ratio || 'square'; Cropper.setRatioButtons(); }

      $$('#composer .tpl-chip').forEach(function (c) {
        c.classList.toggle('on', Number(c.dataset.tpl) === t.id);
      });

      this.activeTemplate = t;
      this.refresh();
      this.note('Applied "' + t.name + '" — add an image and pick a time.');
    },

    /** Save whatever is in the composer right now as a reusable template. */
    saveTemplate: function () {
      var name = prompt('Name this template:', this.activeTemplate ? this.activeTemplate.name : '');
      if (name === null || name.trim() === '') return;

      var body = new FormData();
      body.append('_csrf', csrf());
      body.append('name', name.trim());
      body.append('content', $('#c-content').value);
      body.append('link_url', $('#c-link').value);
      body.append('media_ratio', $('#c-media-ratio').value);
      body.append('alt_text', $('#c-alt').value);
      body.append('first_comment', $('#c-first').value);
      $$('#c-accounts input:checked').forEach(function (i) { body.append('accounts[]', i.value); });

      fetch('/api/posts.php?action=save_template', {
        method: 'POST', body: body, credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          Composer.note('Template "' + name.trim() + '" saved. It will appear next time you open the composer.');
        } else {
          Composer.fail(data.error || 'Could not save the template.');
        }
      })
      .catch(function () { Composer.fail('Network error — the template was not saved.'); });
    },

    note: function (msg) {
      var el = $('#c-note');
      el.textContent = msg;
      el.classList.remove('hide');
      clearTimeout(el._t);
      el._t = setTimeout(function () { el.classList.add('hide'); }, 5000);
    },

    fail: function (msg) {
      var el = $('#c-error');
      el.textContent = msg;
      el.classList.remove('hide');
    },

    save: function (status, again) {
      $('#c-status').value = status || 'scheduled';
      this.andAnother = !!again;
      var form = $('#composer-form');
      form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
    },

    submit: function (ev) {
      ev.preventDefault();

      var form = $('#composer-form');
      var btns = $$('button', form);
      btns.forEach(function (b) { b.disabled = true; });

      fetch('/api/posts.php?action=save', {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btns.forEach(function (b) { b.disabled = false; });

        if (!data.ok) {
          var box = $('#c-error');
          box.textContent = data.error || 'Could not save the post.';
          box.classList.remove('hide');
          box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          return;
        }

        if (!Composer.andAnother) {
          window.location.reload();
          return;
        }

        // Volume mode: keep caption, hashtags, channels and template. Clear the
        // image and step the date on a day, so the next one is image + save.
        Composer.pendingReload = true;
        Composer.andAnother = false;
        $('#c-post-id').value = '';
        $('#c-delete').classList.add('hide');
        $('#composer-title').textContent = 'New post';
        Composer.clearMedia();

        var d = new Date($('#c-date').value + 'T00:00:00');
        d.setDate(d.getDate() + 1);
        $('#c-date').value = d.getFullYear() + '-' +
          String(d.getMonth() + 1).padStart(2, '0') + '-' +
          String(d.getDate()).padStart(2, '0');

        Composer.note('Scheduled. Next one is queued for ' + $('#c-date').value +
                      ' — drop in an image and save again.');
        Composer.refresh();
      })
      .catch(function () {
        $('#c-error').textContent = 'Network error — the post was not saved.';
        $('#c-error').classList.remove('hide');
        btns.forEach(function (b) { b.disabled = false; });
      });
    },

    remove: function () {
      var id = $('#c-post-id').value;
      if (!id || !confirm('Delete this post? This cannot be undone.')) return;

      fetch('/api/posts.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ id: Number(id) }),
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function () { window.location.reload(); });
    }
  };

  window.Composer = Composer;

  /* ------------------------------------------------------------- wiring up  */

  /* ==========================================================================
     Countdowns

     Every element carrying data-at (a UTC timestamp) gets a live "in 3d 4h".
     Rendered client-side because it has to keep moving, and because the
     viewer's clock is the one that matters.
     ========================================================================== */

  function humanGap(ms) {
    var s = Math.round(ms / 1000);
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);

    if (d > 0) return d + 'd' + (h ? ' ' + h + 'h' : '');
    if (h > 0) return h + 'h' + (m ? ' ' + m + 'm' : '');
    if (m > 0) return m + 'm';
    return 'under a minute';
  }

  function tickCountdowns() {
    var now = Date.now();

    $$('[data-at]').forEach(function (el) {
      var at = Date.parse(el.dataset.at);
      if (isNaN(at)) return;

      var status = el.dataset.status || 'scheduled';
      var gap = at - now;

      el.classList.remove('eta-soon', 'eta-late', 'eta-done');

      if (status === 'published') {
        el.textContent = 'published';
        el.classList.add('eta-done');
      } else if (status === 'draft') {
        el.textContent = 'draft';
      } else if (status === 'failed') {
        el.textContent = 'failed';
        el.classList.add('eta-late');
      } else if (gap <= 0) {
        // Past its slot but not published: the worker has not picked it up yet.
        el.textContent = 'due ' + humanGap(-gap) + ' ago';
        el.classList.add('eta-late');
      } else {
        el.textContent = 'in ' + humanGap(gap);
        if (gap < 3600 * 1000) el.classList.add('eta-soon');
      }
    });
  }

  window.tickCountdowns = tickCountdowns;

  document.addEventListener('DOMContentLoaded', function () {

    tickCountdowns();
    setInterval(tickCountdowns, 30000);

    var form = $('#composer-form');
    if (!form) return;

    form.addEventListener('submit', Composer.submit.bind(Composer));

    $('#c-content').addEventListener('input', function () { Composer.refresh(); });
    $('#c-time').addEventListener('input',    function () { Composer.paintPreview(); });
    $('#c-link').addEventListener('input',    function () { Composer.paintPreview(); });

    $$('#c-accounts .acct').forEach(function (label) {
      label.addEventListener('click', function () {
        setTimeout(function () {
          label.classList.toggle('on', label.querySelector('input').checked);
          Composer.refresh();
        }, 0);
      });
    });

    // Template chips
    $$('#composer .tpl-chip').forEach(function (chip) {
      chip.addEventListener('click', function () { Composer.applyTemplate(chip.dataset.tpl); });
    });

    // /dashboard.php?template=12 opens the composer with that template applied
    var qs = new URLSearchParams(window.location.search);
    if (qs.get('template')) {
      Composer.open();
      Composer.applyTemplate(qs.get('template'));
    }

    // Hashtag set chips
    $$('.set-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        Composer.insertSet(chip.dataset.set, chip.dataset.target);
      });
    });

    // Cropper controls
    $$('#c-ratios .ratio-btn').forEach(function (b) {
      b.addEventListener('click', function () { Cropper.setRatio(b.dataset.ratio); });
    });
    var zoom = $('#c-zoom');
    if (zoom) zoom.addEventListener('input', function () { Cropper.setZoom(parseFloat(zoom.value)); });
    Cropper.bindDrag();

    window.addEventListener('resize', function () {
      if (Cropper.ready) { Cropper.reset(); }
    });

    // Close on backdrop click or Escape
    $('#composer').addEventListener('mousedown', function (ev) {
      if (ev.target === this) Composer.close();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') Composer.close();
      if (ev.key === 'n' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
        ev.preventDefault();
        Composer.open();
      }
    });

    /* ---- media picker + drag/drop ---- */

    var zone  = $('#c-dropzone');
    var input = $('#c-media');

    zone.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      if (input.files && input.files[0]) previewLocalFile(input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (t) {
      zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.remove('over'); });
    });
    zone.addEventListener('drop', function (ev) {
      var file = ev.dataTransfer.files && ev.dataTransfer.files[0];
      if (!file) return;
      var dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      previewLocalFile(file);
    });

    function previewLocalFile(file) {
      // A new upload always replaces whatever the post was carrying.
      $('#c-media-original').value = '';
      Composer.showMedia(URL.createObjectURL(file), /^video\//.test(file.type), 'square', null);
    }

    /* ---- calendar drag & drop ---- */

    var grid = $('#cal-grid');
    if (!grid) return;

    var dragging = null;

    grid.addEventListener('dragstart', function (ev) {
      var chip = ev.target.closest('.ev');
      if (!chip) return;
      dragging = chip;
      chip.classList.add('dragging');
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', chip.dataset.post);
    });

    grid.addEventListener('dragend', function () {
      if (dragging) dragging.classList.remove('dragging');
      dragging = null;
      $$('.cal-cell.drop', grid).forEach(function (c) { c.classList.remove('drop'); });
    });

    grid.addEventListener('dragover', function (ev) {
      var cell = ev.target.closest('.cal-cell');
      if (!cell || !dragging) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      $$('.cal-cell.drop', grid).forEach(function (c) { c.classList.remove('drop'); });
      cell.classList.add('drop');
    });

    grid.addEventListener('drop', function (ev) {
      var cell = ev.target.closest('.cal-cell');
      if (!cell || !dragging) return;
      ev.preventDefault();

      var postId = Number(ev.dataTransfer.getData('text/plain'));
      var post   = PP.posts[postId];
      var date   = cell.dataset.date;
      if (!post || post.date === date) return;

      cell.classList.remove('drop');

      fetch('/api/posts.php?action=move', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ id: postId, date: date, time: post.time }),
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) window.location.reload();
        else alert(data.error || 'Could not move that post.');
      });
    });
  });
})();
