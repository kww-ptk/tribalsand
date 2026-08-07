/* ===== Reusable drag-to-reorder for data-table bodies — Tribal Sand admin =====
 * A variant of the data-table toolkit (Tours & Rooms). Rows in a
 * <tbody data-dt-drag data-reorder-url="…" data-reorder-locked="0|1"> can be
 * dragged to reorder; on drop we POST the full id order to reorder-url.
 *
 * Because the reorder endpoint renumbers the posted ids 1..N, reordering is only
 * correct over the WHOLE unfiltered list — so the page shows every row on one
 * page and LOCKS drag (data-reorder-locked="1") whenever a search/filter is
 * active. Re-bound after every AJAX body swap via window.tsDtDrag (called from
 * admin-table.js reenhance()). Each tbody binds once (dataset.dragBound).
 */
(function () {
  'use strict';

  function bind(root) {
    root = root || document;
    root.querySelectorAll('tbody[data-dt-drag]').forEach(function (tbody) {
      if (tbody.dataset.dragBound) return;
      tbody.dataset.dragBound = '1';

      // Reorder disabled while the list is filtered/searched.
      if (tbody.getAttribute('data-reorder-locked') === '1') return;

      var url = tbody.getAttribute('data-reorder-url');
      if (!url) return;

      var dragged = null;

      tbody.querySelectorAll('.draggable-row').forEach(function (row) {
        row.draggable = true;
        row.addEventListener('dragstart', function () { dragged = row; row.style.opacity = '.4'; });
        row.addEventListener('dragend',   function () { dragged = null; row.style.opacity = ''; saveOrder(); });
        row.addEventListener('dragover',  function (e) { e.preventDefault(); });
        row.addEventListener('dragenter', function (e) {
          e.preventDefault();
          if (dragged && dragged !== row) {
            var rows = [].slice.call(tbody.querySelectorAll('.draggable-row'));
            var di = rows.indexOf(dragged), ri = rows.indexOf(row);
            tbody.insertBefore(dragged, di < ri ? row.nextSibling : row);
          }
        });
      });

      function saveOrder() {
        var ids = [].slice.call(tbody.querySelectorAll('.draggable-row')).map(function (r) { return r.dataset.id; });
        var fd = new FormData();
        fd.append('reorder', '1');
        fd.append('ids', JSON.stringify(ids));
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      }
    });
  }

  window.tsDtDrag = bind;
  if (document.readyState !== 'loading') bind();
  else document.addEventListener('DOMContentLoaded', function () { bind(); });
})();
