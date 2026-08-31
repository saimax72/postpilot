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

  var CFG = window.BULK || { templates: [] };

  var Bulk = {

    items: [],       // { path, url, name, video, caption }
    busy: false,

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
                video: !!data.video, caption: ''
              });
            } else {
              failures.push(file.name + ' — ' + (data.error || 'rejected'));
            }
          })
          .catch(function () { failures.push(file.name + ' — network error'); })
          .then(function () {
            done++;
            var total = done + list.length;
            var pct = Math.round((done / total) * 100);
            $('#b-bar').style.width = pct + '%';
            $('#b-progress-text').textContent = 'Uploading ' + done + ' of ' + total + '…';
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

      var times = ($('#b-times').value || '09:00')
        .split(',')
        .map(function (t) { return t.trim(); })
        .filter(function (t) { return /^\d{1,2}:\d{2}$/.test(t); })
        .map(function (t) {
          var p = t.split(':');
          return String(p[0]).padStart(2, '0') + ':' + p[1];
        });

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
      var slots = this.slots();

      $('#b-count').textContent = this.items.length + (this.items.length === 1 ? ' image' : ' images');

      if (!this.items.length) {
        grid.innerHTML = '';
        $('#b-summary').textContent = 'Nothing queued yet.';
        return;
      }

      grid.innerHTML = this.items.map(function (it, i) {
        var s = slots[i] || { date: '—', time: '' };
        var media = it.video
          ? '<video src="' + it.url + '" muted></video>'
          : '<img src="' + it.url + '" alt="">';

        return '<div class="bulk-item">' +
                 '<div class="bulk-thumb">' + media +
                   '<button type="button" class="bulk-x" data-i="' + i + '">&times;</button>' +
                   '<span class="bulk-n">' + (i + 1) + '</span>' +
                 '</div>' +
                 '<div class="bulk-slot">' + s.date + (s.time ? ' · ' + s.time : '') + '</div>' +
                 '<textarea class="bulk-caption" data-i="' + i + '" rows="2" ' +
                   'placeholder="Override caption (optional)">' + (it.caption || '') + '</textarea>' +
               '</div>';
      }).join('');

      $$('.bulk-x', grid).forEach(function (b) {
        b.addEventListener('click', function () { Bulk.remove(Number(b.dataset.i)); });
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
      el.classList.remove('hide');
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

        if (data.failed && data.failed.length) {
          Bulk.fail(data.created + ' created, ' + data.failed.length + ' skipped: ' +
            data.failed.slice(0, 3).map(function (f) { return f.name + ' (' + f.error + ')'; }).join('; '));
          Bulk.items = [];
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
    ['b-start', 'b-times', 'b-interval', 'b-ratio'].forEach(function (id) {
      $('#' + id).addEventListener('input', function () { Bulk.render(); });
      $('#' + id).addEventListener('change', function () { Bulk.render(); });
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
        (field.value.match(/(?:^|[^\w&])#([\p{L}\p{N}_]+)/gu) || []).forEach(function (t) {
          have[t.trim().toLowerCase()] = true;
        });
        var fresh = tags.filter(function (t) { return !have[t.toLowerCase()]; });
        if (!fresh.length) return;
        field.value += (field.value.trim() === '' ? '' : '\n\n') + fresh.join(' ');
        Bulk.count();
      });
    });

    Bulk.count();
  });
})();
