/* ===== Gallery bulk selection & deletion — Tribal Sand admin =====
 * Progressive enhancement for image galleries in venue-edit.php + room-edit.php.
 * Works against any `.gallery-grid` paired with a `.gallery-bulkbar[data-gallery-bulk]`
 * sibling. No native chrome — the per-image checkbox is a styled `.gallery-select`.
 *
 * The bulk bar carries everything the JS needs via data-attributes, so the same
 * script drives both editors even though their backends name the id field
 * differently (venue: image_id, room: img_id). Delete is unified on both to
 * `gallery_action=delete_images` + `image_ids[]`.
 *
 * Bar contract:
 *   data-gallery-bulk                 marks the bar
 *   data-grid="<id>"                  id of the .gallery-grid it controls
 *   data-endpoint="/admin/…?id=N"     POST target
 *   data-idfield="image_id|img_id"    single-image id field (for "Set as main")
 *   data-csrf="<token>"               CSRF token
 * Item contract (inside each .gallery-item):
 *   <label class="gallery-select"><input type="checkbox" data-bulk-item value="<id>"><span class="ck"></span></label>
 * Buttons inside the bar: [data-bulk-all] (checkbox), [data-bulk-clear],
 *   [data-bulk-delete], [data-bulk-setmain], [data-bulk-count].
 */
(function () {
  'use strict';

  function confirmThen(message, onYes) {
    if (typeof window.tsConfirm === 'function') window.tsConfirm(message, onYes);
    else if (window.confirm(message)) onYes();   // graceful fallback
  }

  // Build a throwaway form and submit it (full reload → server re-renders the
  // grid + flash). Keeps us consistent with the page's other gallery actions.
  function postForm(endpoint, csrf, fields) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = endpoint;
    form.style.display = 'none';
    function add(name, value) {
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = name; inp.value = value;
      form.appendChild(inp);
    }
    add('csrf_token', csrf);
    Object.keys(fields).forEach(function (name) {
      var v = fields[name];
      if (Array.isArray(v)) v.forEach(function (item) { add(name, item); });
      else add(name, v);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function initBar(bar) {
    if (bar.dataset.bulkWired) return;
    bar.dataset.bulkWired = '1';

    var grid = document.getElementById(bar.dataset.grid);
    if (!grid) { bar.hidden = true; return; }

    var endpoint = bar.dataset.endpoint;
    var idField  = bar.dataset.idfield || 'image_id';
    var csrf     = bar.dataset.csrf || '';

    var allBox    = bar.querySelector('[data-bulk-all]');
    var clearBtn  = bar.querySelector('[data-bulk-clear]');
    var delBtn    = bar.querySelector('[data-bulk-delete]');
    var mainBtn   = bar.querySelector('[data-bulk-setmain]');
    var countEl   = bar.querySelector('[data-bulk-count]');

    function items() { return Array.prototype.slice.call(grid.querySelectorAll('[data-bulk-item]')); }
    function checked() { return items().filter(function (c) { return c.checked; }); }

    function refresh() {
      var sel = checked();
      var n = sel.length;
      var total = items().length;
      if (countEl) countEl.textContent = n + ' selected';
      if (delBtn) {
        delBtn.disabled = n === 0;
        delBtn.textContent = 'Delete selected (' + n + ')';
      }
      if (mainBtn) mainBtn.hidden = (n !== 1);
      if (allBox) {
        allBox.checked = total > 0 && n === total;
        allBox.indeterminate = n > 0 && n < total;
      }
      // Reflect selection on the tile itself.
      items().forEach(function (c) {
        var tile = c.closest('.gallery-item');
        if (tile) tile.classList.toggle('is-selected', c.checked);
      });
    }

    grid.addEventListener('change', function (e) {
      if (e.target && e.target.matches('[data-bulk-item]')) refresh();
    });

    if (allBox) allBox.addEventListener('change', function () {
      var on = allBox.checked;
      items().forEach(function (c) { c.checked = on; });
      refresh();
    });

    if (clearBtn) clearBtn.addEventListener('click', function () {
      items().forEach(function (c) { c.checked = false; });
      refresh();
    });

    if (delBtn) delBtn.addEventListener('click', function () {
      var ids = checked().map(function (c) { return c.value; });
      if (!ids.length) return;
      var msg = ids.length === 1
        ? 'Delete this photo? This cannot be undone.'
        : 'Delete ' + ids.length + ' photos? This cannot be undone.';
      confirmThen(msg, function () {
        postForm(endpoint, csrf, { gallery_action: 'delete_images', 'image_ids[]': ids });
      });
    });

    if (mainBtn) mainBtn.addEventListener('click', function () {
      var sel = checked();
      if (sel.length !== 1) return;
      var fields = { gallery_action: 'set_hero' };
      fields[idField] = sel[0].value;
      postForm(endpoint, csrf, fields);
    });

    refresh();
  }

  function init(root) {
    root = root || document;
    var bars = root.querySelectorAll ? root.querySelectorAll('.gallery-bulkbar[data-gallery-bulk]') : [];
    Array.prototype.forEach.call(bars, initBar);
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', function () { init(); });

  window.initGalleryBulk = init;   // re-run after AJAX swaps if ever needed
})();
