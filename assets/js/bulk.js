/* ==========================================================================
   PostPilot — bulk upload

   Upload many images, choose a cadence, and lay them onto the calendar.
   Files go up one request at a time: PHP's max_file_uploads defaults to 20,
   so a single form post would quietly discard everything past the twentieth.
   ========================================================================== */

(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var CFG = window.BULK || { templates: [], ratios: {} };

  var RATIOS = CFG.ratios || { square: 1, portrait: 0.8, landscape: 1.91, story: 0.5625 };

  function ratioValue() { return RATIOS[($('#b-ratio') || {}).value] || 1; }

  /** The centred crop for an image at a ratio - mirrors cover_box() in PHP. */
  function coverBox(w, h, r) {
    var src = w / Math.max(1, h);
    if (src > r) {
      var fw = r / src;
      return { fx: (1 - fw) / 2, fy: 0, fw: fw, fh: 1 };
    }
    var fh = src / r;
    return { fx: 0, fy: (1 - fh) / 2, fw: 1, fh: fh };
  }

  /** The crop in force for an item: hand-set if there is one, else centred. */
  function boxFor(it) {
    if (it.crop) return it.crop;
    if (!it.w || !it.h) return { fx: 0, fy: 0, fw: 1, fh: 1 };
    return coverBox(it.w, it.h, ratioValue());
  }

  /**
   * Drive the progress bar. `done` counts finished items; the bar is nudged
   * half a step ahead while one is in flight, so a slow item still shows
   * forward movement rather than sitting on the previous number.
   */
  function progress(done, total, label, inFlight) {
    var frac = total ? (done + (inFlight ? 0.5 : 0)) / total : 0;
    var pct  = Math.min(100, Math.round(frac * 100));

    $('#b-bar').style.width = pct + '%';
    $('#b-progress-pct').textContent = pct + '%';
    $('#b-progress-text').textContent = label;
  }

  var Bulk = {

    items: [],       // { path, url, name, video, caption }
    busy: false,
    retired: {},     // grid index -> already published, tile hidden
    publishing: false,

    /* ------------------------------------------------------------ uploads */

    pick: function (files) {
      var list = Array.prototype.slice.call(files);
      if (!list.length) return;

      if (this.items.length + list.length > 200) {
        this.fail('That would be more than 200 in one batch. Trim the selection.');
        list = list.slice(0, 200 - this.items.length);
        if (!list.length) return;
      }

      this.busy = true;
      $('#b-progress').classList.remove('hide');
      progress(0, list.length, 'Preparing…', true);
      $('#b-go').disabled = true;

      var self = this, done = 0, failures = [];

      // Strictly sequential: gentler on shared hosting than 50 parallel POSTs.
      function next() {
        if (!list.length) {
          self.busy = false;
          $('#b-go').disabled = false;
          $('#b-progress').classList.add('hide');
          if (failures.length) {
            self.fail(failures.length + ' file(s) were rejected: ' + failures.slice(0, 3).join('; ')
              + (failures.length > 3 ? '…' : ''));
          }
          self.render();
          return;
        }

        var file = list.shift();
        var body = new FormData();
        body.append('_csrf', CFG.csrf);
        body.append('media', file);

        fetch('/api/bulk.php?action=upload', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              self.items.push({
                path: data.path, url: data.url, name: file.name,
                video: !!data.video, caption: '',
                w: data.w, h: data.h,
                crop: null            // set by the framing editor
              });
            } else {
              failures.push(file.name + ' — ' + (data.error || 'rejected'));
            }
          })
          .catch(function () { failures.push(file.name + ' — network error'); })
          .then(function () {
            done++;
            var total = done + list.length;
            progress(done, total, 'Uploading ' + done + ' of ' + total, list.length > 0);
            self.render();
            next();
          });
      }

      next();
    },

    remove: function (i) {
      this.items.splice(i, 1);
      this.render();
    },

    clear: function () {
      if (this.items.length && !confirm('Remove all ' + this.items.length + ' images from this batch?')) return;
      this.items = [];
      this.render();
    },

    /* ----------------------------------------------------------- cadence */

    /** Build the list of slots, in order, one per queued image. */
    slots: function () {
      var start = $('#b-start').value || new Date().toISOString().slice(0, 10);

      var times = $$('#b-times input[type=time]')
        .map(function (i) { return i.value; })
        .filter(function (t) { return /^\d{2}:\d{2}$/.test(t); });

      // De-duplicate, then order them so the day fills forwards.
      times = times.filter(function (t, i) { return times.indexOf(t) === i; });
      if (!times.length) times = ['09:00'];
      times.sort();

      var days = $$('#b-days input:checked').map(function (i) { return Number(i.value); });
      if (!days.length) days = [1, 2, 3, 4, 5, 6, 7];

      var interval = Number($('#b-interval').value) || 1;

      var out = [];
      var cursor = new Date(start + 'T00:00:00');
      var eligible = 0;
      var guard = 0;

      while (out.length < this.items.length && guard++ < 4000) {
        // JS Sunday is 0; our checkboxes use ISO 1..7 with Monday first.
        var iso = cursor.getDay() === 0 ? 7 : cursor.getDay();

        if (days.indexOf(iso) !== -1) {
          if (eligible % interval === 0) {
            for (var t = 0; t < times.length && out.length < this.items.length; t++) {
              out.push({
                date: cursor.getFullYear() + '-' +
                      String(cursor.getMonth() + 1).padStart(2, '0') + '-' +
                      String(cursor.getDate()).padStart(2, '0'),
                time: times[t]
              });
            }
          }
          eligible++;
        }
        cursor.setDate(cursor.getDate() + 1);
      }

      return out;
    },

    /* ------------------------------------------------------------ render */

    render: function () {
      var grid = $('#b-grid');
      // The grid is rebuilt from scratch here, so any hidden tiles are gone
      // with it - the bookkeeping has to match or the count drifts.
      this.retired = {};
      var slots = this.slots();

      $('#b-count').textContent = this.items.length + (this.items.length === 1 ? ' image' : ' images');

      if (!this.items.length) {
        grid.innerHTML = '';
        $('#b-summary').textContent = 'Nothing queued yet.';
        return;
      }

      var r = ratioValue();

      grid.innerHTML = this.items.map(function (it, i) {
        var s = slots[i] || { date: '—', time: '' };
        var media;

        if (it.video) {
          media = '<video src="' + it.url + '" muted></video>';
        } else {
          // Draw the thumbnail through the crop, so the grid shows the framing
          // that will actually post rather than a square of the original.
          var b = boxFor(it);
          media = '<img src="' + it.url + '" alt="" style="position:absolute;max-width:none;' +
                  'width:' + (100 / b.fw) + '%;height:' + (100 / b.fh) + '%;' +
                  'left:' + (-b.fx * 100 / b.fw) + '%;top:' + (-b.fy * 100 / b.fh) + '%">';
        }

        return '<div class="bulk-item">' +
                 '<div class="bulk-thumb" style="aspect-ratio:' + r + '" data-frame="' + i + '">' + media +
                   '<button type="button" class="bulk-x" data-i="' + i + '">&times;</button>' +
                   '<span class="bulk-n">' + (i + 1) + '</span>' +
                   (it.video ? '' : '<span class="bulk-edit">Frame</span>') +
                 '</div>' +
                 '<div class="bulk-slot">' + s.date + (s.time ? ' · ' + s.time : '') + '</div>' +
                 '<textarea class="bulk-caption" data-i="' + i + '" rows="2" ' +
                   'placeholder="Override caption (optional)">' + (it.caption || '') + '</textarea>' +
               '</div>';
      }).join('');

      $$('.bulk-x', grid).forEach(function (b) {
        b.addEventListener('click', function (ev) {
          ev.stopPropagation();          // do not open the framer on remove
          Bulk.remove(Number(b.dataset.i));
        });
      });

      $$('.bulk-thumb[data-frame]', grid).forEach(function (t) {
        var i = Number(t.dataset.frame);
        if (Bulk.items[i] && Bulk.items[i].video) return;
        t.addEventListener('click', function () { Framer.open(i); });
      });
      $$('.bulk-caption', grid).forEach(function (t) {
        t.addEventListener('input', function () { Bulk.items[Number(t.dataset.i)].caption = t.value; });
      });

      var first = slots[0], last = slots[slots.length - 1];
      $('#b-summary').textContent = this.items.length + ' posts, ' +
        (first ? first.date + ' ' + first.time : '?') + ' through ' +
        (last ? last.date + ' ' + last.time : '?') + '.';
    },

    fail: function (msg) {
      var el = $('#bulk-error');
      el.textContent = msg;
      el.className = 'alert alert-error';
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    /**
     * Hitting the plan limit is not an error, it is a sales moment.
     *
     * The generic failure path repeats one sentence per file, which is how a
     * limit of ten turned into four identical paragraphs of red text. This says
     * it once, leads with what did work, and offers the way out.
     */
    limitReached: function (info) {
      var el = $('#bulk-error');
      var done = info.done || 0, left = info.skipped || 0;

      var parts = [];
      parts.push('<div class="limit-note">');
      parts.push('<div class="limit-icon">✦</div>');
      parts.push('<div class="limit-body">');

      if (info.reason === 'trial_ended') {
        parts.push('<h4>Your free trial has ended</h4>');
        parts.push('<p>Everything you have made is still here, and posts already scheduled ' +
                   'keep publishing. Only creating new ones is paused.</p>');
      } else {
        parts.push('<h4>That is today&rsquo;s ' + (info.limit || 10) + ' posts</h4>');
        parts.push('<p>Your free trial includes ' + (info.limit || 10) +
                   ' posts a day. The limit resets at midnight in your timezone, ' +
                   'or Pro removes it entirely.</p>');
      }

      if (done || left) {
        parts.push('<p class="limit-tally">');
        if (done)  parts.push('<strong>' + done + '</strong> scheduled just now. ');
        if (left)  parts.push('<strong>' + left + '</strong> still waiting &mdash; ' +
                              'they are kept, so you can finish them any time.');
        parts.push('</p>');
      }

      parts.push('<a class="btn btn-sm" href="/pricing.php">Upgrade to Pro</a>');
      parts.push('</div></div>');

      el.innerHTML = parts.join('');
      el.className = 'limit-wrap';
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    /* ------------------------------------------------------------ create */

    create: function (status) {
      if (this.busy) { this.fail('Still uploading — give it a moment.'); return; }
      if (!this.items.length) { this.fail('Add some images first.'); return; }

      var accounts = $$('#b-accounts input:checked').map(function (i) { return Number(i.value); });
      if (!accounts.length) { this.fail('Choose at least one channel.'); return; }

      $('#bulk-error').classList.add('hide');

      var slots   = this.slots();
      var caption = $('#b-caption').value;

      // Catch a too-long caption here rather than letting all 200 fail server-side.
      var limit = null, tightest = null;
      (CFG.accounts || []).forEach(function (a) {
        if (accounts.indexOf(a.id) === -1) return;
        if (limit === null || a.limit < limit) { limit = a.limit; tightest = a; }
      });

      if (limit !== null) {
        var longest = caption, worst = null;
        this.items.forEach(function (it) {
          var c = it.caption && it.caption.trim() ? it.caption : caption;
          if (Array.from(c).length > Array.from(longest).length) { longest = c; worst = it.name; }
        });
        if (Array.from(longest).length > limit) {
          this.fail((worst ? 'The caption on ' + worst : 'Your caption') + ' is ' +
            Array.from(longest).length + ' characters — ' + tightest.name +
            ' allows ' + limit.toLocaleString() + '. Nothing was created.');
          return;
        }
      }

      var payload = {
        accounts: accounts,
        ratio:    $('#b-ratio').value,
        link:     $('#b-link').value,
        status:   status,
        template_id: this.templateId || 0,
        items: this.items.map(function (it, i) {
          return {
            path:    it.path,
            name:    it.name,
            caption: it.caption && it.caption.trim() ? it.caption : caption,
            crop:    it.crop || null,
            date:    slots[i] ? slots[i].date : '',
            time:    slots[i] ? slots[i].time : ''
          };
        })
      };

      var btn = $('#b-go');
      btn.disabled = true;
      btn.textContent = 'Creating…';

      fetch('/api/bulk.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Add to calendar';

        if (!data.ok) { Bulk.fail(data.error || 'Could not create the batch.'); return; }

        if (data.blocked) {
          Bulk.limitReached({
            reason:  data.blocked,
            limit:   data.limit,
            done:    data.created,
            skipped: data.skipped
          });
          return;
        }

        if (data.failed && data.failed.length) {
          Bulk.fail(data.created + ' created, ' + data.failed.length + ' skipped: ' +
            data.failed.slice(0, 3).map(function (f) { return f.name + ' (' + f.error + ')'; }).join('; ')
            + (data.created === 0 ? ' — your images are still queued, fix the cause and try again.' : ''));

          // Drop only what actually landed. Wiping the queue on a failure means
          // re-uploading everything to retry, which is exactly the wrong moment.
          if (data.created > 0) {
            var made = {};
            (data.failed || []).forEach(function (f) { made[f.name] = true; });
            Bulk.items = Bulk.items.filter(function (it) { return made[it.name]; });
          }
          Bulk.render();
          return;
        }

        window.location.href = '/dashboard.php';
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Add to calendar';
        Bulk.fail('Network error — nothing was created.');
      });
    },

    /**
     * Publish the whole batch immediately, one at a time.
     *
     * Sequential on purpose: each call talks to the network, and firing twenty
     * at once would trip rate limits and give no usable progress. A short gap
     * between posts keeps us well clear of Instagram's throttling.
     */
    publishAll: function () {
      if (this.busy) { this.fail('Still uploading — give it a moment.'); return; }
      if (!this.items.length) { this.fail('Add some images first.'); return; }

      var accounts = $$('#b-accounts input:checked').map(function (i) { return Number(i.value); });
      if (!accounts.length) { this.fail('Choose at least one channel.'); return; }

      if (!confirm('Publish all ' + this.items.length + ' posts right now, one after another?\n\n' +
                   'They go out immediately, not on the schedule above.')) return;

      $('#bulk-error').classList.add('hide');

      var self    = this;
      var caption = $('#b-caption').value;
      var queue   = this.items.slice();
      var total   = queue.length;
      var done    = 0, okCount = 0;
      var failures = [];
      this.blocked = null;
      this.publishing = true;
      this.resetTiles();

      var btn = $('#b-now');
      btn.disabled = true;
      $('#b-go').disabled = true;
      $('#b-progress').classList.remove('hide');
      progress(0, total, 'Starting…', true);

      function step() {
        if (!queue.length) {
          btn.disabled = false;
          $('#b-go').disabled = false;
          $('#b-progress').classList.add('hide');
          self.publishing = false;
          self.countRemaining();

          if (self.blocked) {
            self.limitReached({
              reason:  self.blocked.reason,
              limit:   self.blocked.limit,
              done:    okCount,
              skipped: queue.length + failures.length
            });
          } else if (failures.length) {
            self.fail(okCount + ' published, ' + failures.length + ' failed: ' +
              failures.slice(0, 3).map(function (f) { return f.name + ' (' + f.error + ')'; }).join('; ') +
              (failures.length > 3 ? '…' : ''));
          } else {
            window.location.href = '/queue.php?status=published';
          }
          return;
        }

        var it  = queue.shift();
        var idx = self.items.indexOf(it);

        self.mark(idx, 'working', 'posting…');
        progress(done, total, 'Publishing ' + (done + 1) + ' of ' + total, true);

        fetch('/api/bulk.php?action=publish_one', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
          credentials: 'same-origin',
          body: JSON.stringify({
            path:    it.path,
            name:    it.name,
            caption: it.caption && it.caption.trim() ? it.caption : caption,
            crop:    it.crop || null,
            accounts: accounts,
            ratio:   $('#b-ratio').value,
            link:    $('#b-link').value
          })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok && data.published) {
            okCount++;
            self.mark(idx, 'ok', 'published');
            // A beat so the badge is readable before the tile leaves.
            setTimeout(function () { self.retire(idx); }, 500);
          } else if (data.blocked) {
            // Every remaining post would fail identically, so stop here and
            // leave the rest of the queue intact for after an upgrade.
            self.blocked = { reason: data.blocked, limit: data.limit };
            self.mark(idx, 'bad', 'daily limit');
            queue.length = 0;
          } else {
            var why = data.error || 'refused';
            failures.push({ name: it.name, error: why });
            self.mark(idx, 'bad', why);
          }
        })
        .catch(function () {
          failures.push({ name: it.name, error: 'network error' });
          self.mark(idx, 'bad', 'network error');
        })
        .then(function () {
          done++;
          var left = total - done;
          progress(done, total, done === total
            ? 'Finished all ' + total
            : 'Publishing ' + (done + 1) + ' of ' + total
              + ' · ' + left + ' to go', done < total);
          // A breath between posts - the networks throttle bursts.
          setTimeout(step, 1500);
        });
      }

      step();
    },

    /** Stamp a result onto one thumbnail in the grid. */
    /**
     * Take a published tile out of the grid.
     *
     * The tile is hidden rather than removed: mark() and the click handlers
     * address items by their position in the grid, so deleting a node would
     * shift every index after it onto the wrong image.
     *
     * The count updates as they go, which is the point - watching the number
     * fall is how you know a long run is actually progressing.
     */
    retire: function (index) {
      var cell = document.querySelectorAll('#b-grid .bulk-item')[index];
      this.retired[index] = true;

      if (!cell) { this.countRemaining(); return; }

      var self = this;
      cell.classList.add('is-going');

      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      setTimeout(function () {
        cell.classList.add('is-done');
        self.countRemaining();
      }, reduce ? 0 : 420);
    },

    /** How many tiles are still waiting to go out. */
    countRemaining: function () {
      var left = 0;
      for (var i = 0; i < this.items.length; i++) {
        if (!this.retired[i]) left++;
      }
      var el = $('#b-count');
      if (el) {
        el.textContent = this.publishing
          ? left + (left === 1 ? ' image left' : ' images left')
          : this.items.length + (this.items.length === 1 ? ' image' : ' images');
      }
      return left;
    },

    /** Bring every tile back, for a fresh run. */
    resetTiles: function () {
      this.retired = {};
      $$('#b-grid .bulk-item').forEach(function (c) {
        c.classList.remove('is-going', 'is-done');
      });
      this.countRemaining();
    },

    mark: function (index, state, text) {
      var cell = document.querySelectorAll('#b-grid .bulk-item')[index];
      if (!cell) return;
      var thumb = cell.querySelector('.bulk-thumb');
      if (!thumb) return;

      var tag = thumb.querySelector('.bulk-result');
      if (!tag) {
        tag = document.createElement('span');
        tag.className = 'bulk-result';
        thumb.appendChild(tag);
      }
      tag.className = 'bulk-result is-' + state;
      tag.textContent = text;
    },

    applyTemplate: function (id) {
      var t = null;
      (CFG.templates || []).forEach(function (x) { if (x.id === Number(id)) t = x; });
      if (!t) return;

      $('#b-caption').value = t.content || '';
      $('#b-link').value    = t.link || '';
      $('#b-ratio').value   = t.ratio || 'square';
      this.templateId = t.id;

      $$('#b-accounts .acct').forEach(function (l) {
        var input = l.querySelector('input');
        var on = (t.accounts || []).indexOf(Number(input.value)) !== -1;
        input.checked = on;
        l.classList.toggle('on', on);
      });

      $$('.tpl-chip').forEach(function (c) { c.classList.toggle('on', Number(c.dataset.tpl) === t.id); });
      this.count();
      this.render();
    },

    count: function () {
      $('#b-counter').textContent = Array.from($('#b-caption').value).length + ' characters';
    }
  };

  window.Bulk = Bulk;

  /* ==========================================================================
     Framer - per-image framing.

     Same idea as the composer's cropper: it never touches pixels, it records
     which fraction of the source sits inside the frame. PHP does the real crop.
     ========================================================================== */

  var Framer = {

    index: -1,
    natW: 0, natH: 0,
    scale: 1, baseScale: 1,
    tx: 0, ty: 0,
    ready: false,

    frame: function () { return $('#f-frame'); },

    open: function (i) {
      var it = Bulk.items[i];
      if (!it || it.video) return;

      this.index = i;
      this.ready = false;

      $('#framer').classList.remove('hide');
      document.body.style.overflow = 'hidden';
      $('#f-count').textContent = 'Image ' + (i + 1) + ' of ' + Bulk.items.length;
      $('#f-prev').disabled = i === 0;
      $('#f-next').disabled = i === Bulk.items.length - 1;

      var self = this;
      var probe = new Image();
      probe.onload = function () {
        self.natW = it.w || probe.naturalWidth;
        self.natH = it.h || probe.naturalHeight;
        $('#f-img').src = it.url;
        self.shape();
        self.apply(boxFor(it));
        self.ready = true;
      };
      probe.src = it.url;
    },

    close: function () {
      $('#framer').classList.add('hide');
      document.body.style.overflow = '';
    },

    done: function () {
      this.commit();
      this.close();
      Bulk.render();
    },

    step: function (d) {
      this.commit();
      var next = this.index + d;
      if (next >= 0 && next < Bulk.items.length) {
        Bulk.render();
        this.open(next);
      }
    },

    /** Size the frame to the batch ratio, within a height budget. */
    shape: function () {
      var f = this.frame();
      var stage = f.parentElement;
      var w = (stage ? stage.clientWidth : 0) || 320;
      var h = w / ratioValue();
      if (h > 380) { h = 380; w = h * ratioValue(); }
      f.style.width  = Math.round(w) + 'px';
      f.style.height = Math.round(h) + 'px';
    },

    fit: function () {
      var f = this.frame();
      if (!this.natW || !this.natH) return 1;
      return Math.max(f.clientWidth / this.natW, f.clientHeight / this.natH);
    },

    apply: function (b) {
      this.shape();
      var f = this.frame();
      this.baseScale = this.fit();
      this.scale = (f.clientWidth / b.fw) / this.natW;
      this.tx = -b.fx * this.natW * this.scale;
      this.ty = -b.fy * this.natH * this.scale;
      $('#f-zoom').value = Math.min(3, Math.max(1, this.scale / this.baseScale)).toFixed(2);
      this.clamp();
      this.paint();
    },

    reset: function () {
      var it = Bulk.items[this.index];
      if (!it) return;
      it.crop = null;
      this.apply(coverBox(this.natW, this.natH, ratioValue()));
    },

    setZoom: function (z) {
      var f = this.frame();
      var cx = f.clientWidth / 2, cy = f.clientHeight / 2;
      var before = this.scale;
      this.baseScale = this.fit();
      this.scale = this.baseScale * z;
      var k = this.scale / before;
      this.tx = cx - (cx - this.tx) * k;
      this.ty = cy - (cy - this.ty) * k;
      this.clamp();
      this.paint();
    },

    clamp: function () {
      var f = this.frame();
      var dw = this.natW * this.scale, dh = this.natH * this.scale;
      this.tx = Math.min(0, Math.max(f.clientWidth  - dw, this.tx));
      this.ty = Math.min(0, Math.max(f.clientHeight - dh, this.ty));
      if (dw <= f.clientWidth)  this.tx = (f.clientWidth  - dw) / 2;
      if (dh <= f.clientHeight) this.ty = (f.clientHeight - dh) / 2;
    },

    paint: function () {
      var i = $('#f-img');
      i.style.width  = (this.natW * this.scale) + 'px';
      i.style.height = (this.natH * this.scale) + 'px';
      i.style.left   = this.tx + 'px';
      i.style.top    = this.ty + 'px';
    },

    /** Store the framed region on the item, as fractions of the source. */
    commit: function () {
      var it = Bulk.items[this.index];
      if (!it || !this.ready || !this.natW) return;

      var f = this.frame();
      var cl = function (v) { return Math.min(1, Math.max(0, v)) || 0; };

      it.crop = {
        fx: cl((-this.tx / this.scale) / this.natW),
        fy: cl((-this.ty / this.scale) / this.natH),
        fw: cl((f.clientWidth  / this.scale) / this.natW),
        fh: cl((f.clientHeight / this.scale) / this.natH)
      };
    },

    bind: function () {
      var self = this, dragging = false, lx = 0, ly = 0;
      var f = this.frame();
      if (!f) return;

      f.addEventListener('pointerdown', function (ev) {
        if (!self.ready) return;
        dragging = true; lx = ev.clientX; ly = ev.clientY;
        f.setPointerCapture(ev.pointerId);
        f.classList.add('grabbing');
      });
      f.addEventListener('pointermove', function (ev) {
        if (!dragging) return;
        self.tx += ev.clientX - lx; self.ty += ev.clientY - ly;
        lx = ev.clientX; ly = ev.clientY;
        self.clamp(); self.paint();
      });
      ['pointerup', 'pointercancel'].forEach(function (t) {
        f.addEventListener(t, function () { dragging = false; f.classList.remove('grabbing'); });
      });

      $('#f-zoom').addEventListener('input', function () { self.setZoom(parseFloat(this.value)); });

      $('#framer').addEventListener('mousedown', function (ev) {
        if (ev.target === this) self.done();
      });
      document.addEventListener('keydown', function (ev) {
        if ($('#framer').classList.contains('hide')) return;
        if (ev.key === 'Escape')     self.done();
        if (ev.key === 'ArrowRight') self.step(1);
        if (ev.key === 'ArrowLeft')  self.step(-1);
      });
    }
  };

  window.Framer = Framer;

  document.addEventListener('DOMContentLoaded', function () {
    if (!$('#b-dropzone')) return;

    // With a single channel there is nothing to choose, so tick it. Having to
    // select the only option is friction that only ever produces an error.
    var chans = $$('#b-accounts input');
    if (chans.length === 1) {
      chans[0].checked = true;
      chans[0].closest('.acct').classList.add('on');
    }

    // Default the start date to tomorrow.
    var d = new Date();
    d.setDate(d.getDate() + 1);
    $('#b-start').value = d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    var zone = $('#b-dropzone'), input = $('#b-files');

    zone.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () { Bulk.pick(input.files); input.value = ''; });

    ['dragenter', 'dragover'].forEach(function (t) {
      zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.remove('over'); });
    });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer.files) Bulk.pick(e.dataTransfer.files);
    });

    // Any cadence change re-slots the whole batch.
    ['b-start', 'b-interval', 'b-ratio'].forEach(function (id) {
      $('#' + id).addEventListener('input', function () { Bulk.render(); });
      $('#' + id).addEventListener('change', function () { Bulk.render(); });
    });

    // Times of day: a row of pickers rather than a comma-separated string.
    var timeList = $('#b-times');

    timeList.addEventListener('input',  function () { Bulk.render(); });
    timeList.addEventListener('change', function () { Bulk.render(); });

    timeList.addEventListener('click', function (ev) {
      var x = ev.target.closest('.time-x');
      if (!x) return;
      // Never leave zero times - the batch would have nowhere to go.
      if (timeList.querySelectorAll('.time-chip').length > 1) {
        x.closest('.time-chip').remove();
        Bulk.render();
      }
    });

    $('#b-add-time').addEventListener('click', function () {
      var chips = timeList.querySelectorAll('.time-chip');
      var last  = chips[chips.length - 1].querySelector('input').value || '09:00';

      // Offer a sensible next slot rather than another identical time.
      var parts = last.split(':');
      var next  = (Number(parts[0]) + 4) % 24;

      var chip = document.createElement('span');
      chip.className = 'time-chip';
      chip.innerHTML = '<input type="time" value="' + String(next).padStart(2, '0') + ':' + parts[1] + '">' +
                       '<button type="button" class="time-x" title="Remove">&times;</button>';
      timeList.appendChild(chip);
      Bulk.render();
    });
    $$('#b-days input').forEach(function (i) {
      i.addEventListener('change', function () { Bulk.render(); });
    });

    $('#b-caption').addEventListener('input', function () { Bulk.count(); });

    $$('.tpl-chip').forEach(function (c) {
      c.addEventListener('click', function () { Bulk.applyTemplate(c.dataset.tpl); });
    });

    $$('.set-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var field = $('#b-caption');
        var tags  = (chip.dataset.tags || '').split(/\s+/).filter(Boolean);
        var have  = {};
        (field.value.match(/(?:^|[^\w&])([#@][\p{L}\p{N}_.]+)/gu) || []).forEach(function (t) {
          have[t.trim().toLowerCase()] = true;
        });
        var fresh = tags.filter(function (t) { return !have[t.toLowerCase()]; });
        if (!fresh.length) return;
        field.value += (field.value.trim() === '' ? '' : '\n\n') + fresh.join(' ');
        Bulk.count();
      });
    });

    Bulk.count();
    Framer.bind();
  });
})();
