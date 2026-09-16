/* ===== Admin attendance — daily editor enhancements =====
 * Live Total/OT, quick-fill shifts, bulk actions, and status↔times interlock.
 * The page saves via a normal form POST (works with JS off); this only makes
 * entry faster and shows totals as you type.
 */
(function () {
  'use strict';

  // Built-in shifts as HH:MM (mirrors attendance_shifts() on the server).
  var SHIFTS = {
    std:      { in1: '08:00', out1: '13:00', in2: '14:00', out2: '17:00' },
    secday:   { in1: '07:00', out1: '19:00', in2: '', out2: '' },
    secnight: { in1: '19:00', out1: '07:00+1', in2: '', out2: '' }
  };
  var DAYTOK = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

  function toMin(v) {
    v = (v || '').trim();
    if (!v) return null;
    var plus = 0;
    if (v.slice(-2) === '+1') { plus = 1440; v = v.slice(0, -2).trim(); }
    var m = /^(\d{1,2}):(\d{2})$/.exec(v);
    if (!m) return null;
    return (+m[1]) * 60 + (+m[2]) + plus;
  }

  function rowInputs(row) {
    return {
      in1: row.querySelector('[name^="in1"]'), out1: row.querySelector('[name^="out1"]'),
      in2: row.querySelector('[name^="in2"]'), out2: row.querySelector('[name^="out2"]'),
      status: row.querySelector('.att-status'), total: row.querySelector('.att-total')
    };
  }

  function hoursOf(f) {
    var h = 0, a = toMin(f.in1.value), b = toMin(f.out1.value), c = toMin(f.in2.value), d = toMin(f.out2.value);
    if (a != null && b != null) h += b - a;
    if (c != null && d != null) h += d - c;
    return Math.max(0, h) / 60;
  }

  function recompute(row) {
    var f = rowInputs(row);
    var std = parseInt(row.dataset.std, 10) || 8;
    var worked = f.status.value === '';
    var h = worked ? hoursOf(f) : 0;
    if (worked && h > 0) {
      var ot = h - std;
      f.total.textContent = h.toFixed(1) + 'h' + (Math.abs(ot) > 0.01 ? ' (' + (ot > 0 ? '+' : '') + ot.toFixed(1) + ')' : '');
    } else {
      f.total.textContent = worked ? '—' : f.status.value;
    }
  }

  function fillShift(row, key) {
    var f = rowInputs(row), s = SHIFTS[key];
    if (!s) return;
    f.status.value = '';
    f.in1.value = s.in1; f.out1.value = s.out1; f.in2.value = s.in2; f.out2.value = s.out2;
    setTimesDisabled(row, false);
    recompute(row);
  }

  function isBlank(row) {
    var f = rowInputs(row);
    return f.status.value === '' && !f.in1.value && !f.out1.value && !f.in2.value && !f.out2.value;
  }

  function setTimesDisabled(row, off) {
    var f = rowInputs(row);
    [f.in1, f.out1, f.in2, f.out2].forEach(function (i) {
      i.disabled = off; if (off) i.value = '';
      // When timepicker.js has enhanced this input, relabel/disable its button
      // (the styled picker) to match the value/disabled state we just set.
      if (i._tpSync) i._tpSync();
    });
  }

  function onStatusChange(row) {
    var f = rowInputs(row);
    // A non-worked status clears + disables the time cells; "Worked" re-enables.
    setTimesDisabled(row, f.status.value !== '');
    recompute(row);
  }

  function currentDayTok() {
    var el = document.getElementById('attDateInput');
    if (!el || !el.value) return null;
    var p = el.value.split('-');
    var dt = new Date(+p[0], +p[1] - 1, +p[2]);
    return DAYTOK[dt.getDay()];
  }

  function init() {
    var form = document.getElementById('attForm');
    if (!form) return;

    form.querySelectorAll('.att-row').forEach(function (row) {
      recompute(row);
      rowInputs(row).status.addEventListener('change', function () { onStatusChange(row); });
      row.querySelectorAll('.att-t').forEach(function (i) { i.addEventListener('input', function () { recompute(row); }); });
      row.querySelectorAll('.att-q').forEach(function (b) {
        b.addEventListener('click', function () { fillShift(row, b.dataset.shift); });
      });
      // Sync interlock on load (a stored status day keeps its times disabled).
      if (rowInputs(row).status.value !== '') setTimesDisabled(row, true);
    });

    form.querySelectorAll('[data-bulk]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var kind = btn.dataset.bulk;
        var tok = currentDayTok();
        form.querySelectorAll('.att-row').forEach(function (row) {
          if (!isBlank(row)) return; // only fill blanks
          if (kind === 'std' || kind === 'secday') { fillShift(row, kind); }
          else if (kind === 'OFF') { rowInputs(row).status.value = 'OFF'; onStatusChange(row); }
          else if (kind === 'auto') {
            var off = (row.dataset.off || '').toUpperCase();
            var isOff = off ? (off === tok) : (tok === 'SUN');
            if (isOff) { rowInputs(row).status.value = 'OFF'; onStatusChange(row); }
          }
        });
      });
    });

    // Date jump: the styled datepicker sets #attDateInput and fires change.
    var dateInput = document.getElementById('attDateInput');
    if (dateInput) dateInput.addEventListener('change', function () {
      var gf = dateInput.closest('form'); if (gf) gf.submit();
    });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
