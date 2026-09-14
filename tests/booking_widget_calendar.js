// Booking calendar — reachable-stay greying must narrow CHECK-OUTS only.
// Run: node tests/booking_widget_calendar.js
//
// No npm, no build system: this file ships its own pinhole DOM (enough of the
// API that js/booking-widget.js really uses) and runs the REAL widget source in
// a vm context. `fetch` is stubbed so the suite is hermetic — the live-endpoint
// evidence lives in the commit message, this is the regression guard.
//
// The bug it pins: availability is a property of a STAY, so "at most N nights
// from this check-in" is a statement about candidate CHECK-OUTS. Applying it to
// every cell made eighteen months of calendar inert as check-ins too, with no
// way back short of clicking a date on or before the check-in — which may not
// exist when the check-in is the earliest selectable date.

'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');

let failures = 0;
function check(label, cond) {
  if (cond) console.log('PASS  ' + label);
  else { console.log('FAIL  ' + label); failures++; }
}

// ── Pinhole DOM ─────────────────────────────────────────────────────────────
// Only what the widget touches. Selector support is deliberately narrow; an
// unsupported selector returns [] rather than silently matching the wrong set.
class ClassList {
  constructor(el) { this.el = el; }
  get _s() { return this.el._class ? this.el._class.split(/\s+/).filter(Boolean) : []; }
  set _s(v) { this.el._class = v.join(' '); }
  contains(c) { return this._s.includes(c); }
  add(c)      { if (!this.contains(c)) this._s = this._s.concat(c); }
  remove(c)   { this._s = this._s.filter(x => x !== c); }
  toggle(c, on) { const want = on === undefined ? !this.contains(c) : !!on; want ? this.add(c) : this.remove(c); }
}

class El {
  constructor(tag) {
    this.tagName = (tag || 'div').toUpperCase();
    this._class = '';
    this.children = [];
    this.attrs = {};
    this.dataset = {};
    this.textContent = '';
    this.hidden = false;
    this.value = '';
    this._listeners = {};
    this.classList = new ClassList(this);
  }
  get className() { return this._class; }
  set className(v) { this._class = v; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  appendChild(c) { this.children.push(c); return c; }
  contains(n) { return n === this || this.children.some(c => c.contains && c.contains(n)); }
  addEventListener(t, fn) { (this._listeners[t] ||= []).push(fn); }
  dispatch(t, ev) { (this._listeners[t] || []).forEach(fn => fn(ev || { stopPropagation() {} })); }
  scrollIntoView() {}
  getBoundingClientRect() { return { top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0 }; }

  // renderMonth() writes a flat list of `<div class="…" data-date="…">N</div>`
  // and then queries it back. Parse exactly that shape.
  get innerHTML() { return this._html || ''; }
  set innerHTML(html) {
    this._html = html;
    this.children = [];
    const re = /<div class="([^"]*)"(?:\s+data-date="([^"]*)")?\s*>([^<]*)<\/div>/g;
    let m;
    while ((m = re.exec(html))) {
      const el = new El('div');
      el.className = m[1];
      if (m[2] !== undefined) { el.dataset.date = m[2]; el.setAttribute('data-date', m[2]); }
      el.textContent = m[3];
      this.children.push(el);
    }
  }

  _all() { return this.children.reduce((a, c) => a.concat([c], c._all ? c._all() : []), []); }
  querySelectorAll(sel) { return this._all().filter(el => matches(el, sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

// Supports: `.cls`, `:not(.cls)`, `[attr]`, `[attr="v"]`, and a leading tag —
// combined on ONE compound selector. Anything else (descendant combinators,
// pseudo-classes) is refused loudly so a test can never pass on a typo.
function matches(el, sel) {
  sel = sel.trim();
  if (/\s|,|>/.test(sel)) throw new Error('pinhole DOM: unsupported selector ' + JSON.stringify(sel));
  const parts = sel.match(/:not\([^)]*\)|\[[^\]]*\]|\.[-\w]+|^[a-zA-Z]+/g) || [];
  if (!parts.length) throw new Error('pinhole DOM: unparsed selector ' + JSON.stringify(sel));
  return parts.every(p => {
    if (p.startsWith(':not(')) return !matches(el, p.slice(5, -1));
    if (p.startsWith('.'))     return el.classList.contains(p.slice(1));
    if (p.startsWith('[')) {
      const m = p.slice(1, -1).match(/^([-\w]+)(?:=["']?([^"']*)["']?)?$/);
      if (!m) throw new Error('pinhole DOM: unsupported attr selector ' + p);
      const has = el.getAttribute(m[1]) !== null;
      return m[2] === undefined ? has : el.getAttribute(m[1]) === m[2];
    }
    return el.tagName === p.toUpperCase();
  });
}

// ── Fixture: the real widget's required element set ─────────────────────────
const IDS = ['availCalendar','bkCiBtn','bkCoBtn','bkCiValue','bkCoValue','bkDatesPop',
  'bkDatesHint','bkDatesDone','bkGuestsBtn','bkGuestsPop','bkGuestsValue','bkGuestsDone',
  'bkCalGrid','bkCalGrid2','bkMonthLabel','bkMonthLabel2','bkPrevMonth','bkNextMonth',
  'availCheckin','availCheckout','availAdults','availChildren','bkTotal','bkTotalLabel',
  'bkTotalPrice','availFeedback','bkSteps'];

function buildFixture(slug) {
  const byId = {};
  IDS.forEach(id => { byId[id] = new El('div'); });
  const wrap = byId.availCalendar;
  wrap.dataset.slug = slug; wrap.dataset.price = '250'; wrap.dataset.currency = 'USD';

  const form = new El('form');
  const submit = new El('button'); submit.className = 'bk-submit';
  const lbl = new El('span'); lbl.className = 'bk-submit__label';
  submit.appendChild(lbl); form.appendChild(submit);
  ['name','email','phone'].forEach(n => {
    const i = new El('input'); i.setAttribute('name', n); form.appendChild(i);
  });
  byId.availAdults.value = '2'; byId.availChildren.value = '0';
  byId.bkGuestsPop.appendChild(Object.assign(new El('span'), { _class: '' , dataset: { bkCount: 'adult' } }));

  const doc = {
    getElementById: id => (id === 'availForm' ? form : (byId[id] || null)),
    addEventListener() {},
    querySelectorAll: () => [],
  };
  return { doc, form, byId, wrap };
}

// ── Harness: boot the real widget with a scripted endpoint ──────────────────
const SRC = fs.readFileSync(path.join(__dirname, '..', 'js', 'booking-widget.js'), 'utf8');

// `answer` maps a check_in ymd -> the JSON the single-date branch returns (or an
// Error, to exercise a rejected fetch); it may be a value, a function, or async.
// `base` (optional) sends every request to a real server at that origin instead
// — the same harness then drives the real api/check-availability.php.
function boot(slug, answer, base) {
  const fx = buildFixture(slug);
  const pending = [];
  // Every promise the widget can still be waiting on, including the SECOND hop
  // (`res.json()`), which settle() must also drain or a real HTTP run races.
  const track = p => { pending.push(Promise.resolve(p).catch(() => {})); return p; };
  function fakeFetch(url) {
    const p = track((async () => {
      if (base) { const r = await fetch(base + url); return { json: () => track(r.json()) }; }
      const ci = (url.match(/check_in=([\d-]+)/) || [])[1];
      const co = (url.match(/check_out=([\d-]+)/) || [])[1];
      if (ci && !co) {
        const a = await (typeof answer === 'function' ? answer(ci) : answer);
        if (a instanceof Error) throw a;
        return { json: async () => a };
      }
      if (ci && co) return { json: async () => ({ available: true, nights: 1, total: 250, currency: 'USD' }) };
      return { json: async () => ({ fully_blocked: [] }) };     // calendar load
    })());
    // A real thenable, as tsLoadRoom()'s .then().catch() expects.
    return p;
  }

  const sandbox = {
    document: fx.doc, window: {}, console,
    localStorage: { getItem: () => null, setItem() {} },
    sessionStorage: { getItem: () => null, setItem() {} },
    fetch: fakeFetch,
    setTimeout, clearTimeout, Date, JSON, Math, Number, Array, Object, String, parseInt, parseFloat,
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(SRC, sandbox, { filename: 'js/booking-widget.js' });
  sandbox.window.initBookingWidget();

  return {
    fx,
    async settle() {
      // Drain the fetch chain to a fixed point: settling one hop can enqueue the
      // next (fetch -> json -> render). A macrotask turn, not just a microtask
      // one, so real localhost I/O gets a chance to land under `base`.
      for (let i = 0; i < 50; i++) {
        const n = pending.length;
        await Promise.all(pending.slice());
        await new Promise(r => setTimeout(r, 0));
        if (pending.length === n) { await Promise.all(pending.slice()); if (pending.length === n) break; }
      }
    },
    cells() {
      return [...fx.byId.bkCalGrid.querySelectorAll('.bk-cell'),
              ...fx.byId.bkCalGrid2.querySelectorAll('.bk-cell')];
    },
    cell(ymd) { return this.cells().find(c => c.dataset.date === ymd) || null; },
    // A cell is LIVE when renderMonth bound a click to it — the exact selector
    // the widget uses, so the test can't drift from the implementation.
    live(ymd) {
      const sel = '.bk-cell:not(.bk-cell--blocked):not(.bk-cell--blank)';
      const g = [...fx.byId.bkCalGrid.querySelectorAll(sel), ...fx.byId.bkCalGrid2.querySelectorAll(sel)];
      const c = g.find(x => x.dataset.date === ymd);
      return !!(c && (c._listeners.click || []).length);
    },
    async click(ymd) {
      const c = this.cell(ymd);
      if (!c) throw new Error('no cell ' + ymd);
      const fns = c._listeners.click || [];
      if (!fns.length) return false;      // inert: nothing to fire
      fns.forEach(f => f());
      await this.settle();
      return true;
    },
    ci() { return fx.byId.availCheckin.value; },
    co() { return fx.byId.availCheckout.value; },
  };
}

// The widget always opens on the current month + the next one, so drive it with
// dates relative to today rather than a hard-coded year that will age out.
const T = new Date(); T.setHours(0, 0, 0, 0);
const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const plus = n => { const d = new Date(T); d.setDate(d.getDate() + n); return ymd(d); };

module.exports = { boot, check };
if (require.main !== module) return;

(async () => {

// ── 1. The reported regression ───────────────────────────────────────────────
// Check-in with max_nights = 2; a date 8 days out is unreachable as a check-out
// but is a perfectly good check-in.
{
  const CI = plus(3);
  const h = boot('zuri-jua', { max_nights: 2, cap: 30, check_in: CI });
  await h.settle();
  await h.click(CI);

  check('1: reachable check-out (+1 night) stays live',  h.live(plus(4)));
  check('1: furthest check-out (+2 nights) stays live',  h.live(plus(5)));
  check('1: a date beyond the reach is NOT --blocked',   !h.cell(plus(11)).classList.contains('bk-cell--blocked'));
  check('1: ...it is marked --beyond instead',            h.cell(plus(11)).classList.contains('bk-cell--beyond'));
  check('1: ...and is STILL CLICKABLE (the regression)',  h.live(plus(11)));

  const took = await h.click(plus(11));
  check('1: clicking it is accepted',                     took === true);
  check('1: ...and starts a FRESH selection there',       h.ci() === plus(11) && h.co() === '');
  check('1: ...which re-fetches its own reach',           h.cell(plus(19)).classList.contains('bk-cell--beyond'));
  check('1: ...leaving no stale greying before it',      !h.cell(plus(12)).classList.contains('bk-cell--beyond'));
}

// ── 2. Check-outs: inside reachable = selectable, beyond = not ───────────────
{
  const CI = plus(3);
  const h = boot('zuri-jua', { max_nights: 2, cap: 30, check_in: CI });
  await h.settle();
  await h.click(CI);
  await h.click(plus(5));                       // the furthest reachable check-out
  check('2: a check-out inside the reach completes the range',
    h.ci() === CI && h.co() === plus(5));
}
{
  const CI = plus(3);
  const h = boot('zuri-jua', { max_nights: 2, cap: 30, check_in: CI });
  await h.settle();
  await h.click(CI);
  await h.click(plus(9));                       // beyond it
  check('2: a date beyond the reach is never taken as a check-out',
    h.co() === '');
  check('2: ...it becomes the new check-in instead',
    h.ci() === plus(9));
}
{
  const CI = plus(3);
  const h = boot('zuri-jua', { max_nights: 2, cap: 30, check_in: CI });
  await h.settle();
  await h.click(CI);
  check('2: the check-in itself is never greyed', !h.cell(CI).classList.contains('bk-cell--beyond'));
  check('2: a date BEFORE the check-in is never greyed (it is a re-pick)',
    !h.cell(plus(1)).classList.contains('bk-cell--beyond') && h.live(plus(1)));
}

// ── 3. Every permissive failure path greys nothing ──────────────────────────
const permissive = [
  ['fetch rejects',            new Error('network down')],
  ['non-numeric max_nights',   { max_nights: 'lots', cap: 30 }],
  ['max_nights = 0',           { max_nights: 0, cap: 30 }],
  ['max_nights >= cap',        { max_nights: 30, cap: 30 }],
  ['cap missing',              { max_nights: 4 }],
];
for (const [label, answer] of permissive) {
  const CI = plus(3);
  const h = boot('zuri-jua', answer);
  await h.settle();
  await h.click(CI);
  const greyed = h.cells().filter(c => c.classList.contains('bk-cell--beyond'));
  const inert  = h.cells().filter(c => c.dataset.date > CI && !h.live(c.dataset.date));
  check('3: ' + label + ' → nothing greyed', greyed.length === 0);
  check('3: ' + label + ' → every later date still clickable', inert.length === 0);
}

// ── 4. A room with no constraint has an entirely normal calendar ────────────
{
  const CI = plus(3);
  const h = boot('maya-ilai-studio', { max_nights: 30, cap: 30, check_in: CI });
  await h.settle();
  const before = h.cells().filter(c => h.live(c.dataset.date)).length;
  await h.click(CI);
  const after  = h.cells().filter(c => h.live(c.dataset.date)).length;
  check('4: no constraint → not one cell greyed',
    h.cells().every(c => !c.classList.contains('bk-cell--beyond')));
  check('4: no constraint → the live-cell count is unchanged by picking a check-in',
    before === after && before > 0);
}

// ── 5. Nothing changed for a guest who never picks a check-in ───────────────
{
  const h = boot('zuri-jua', { max_nights: 1, cap: 30 });
  await h.settle();
  check('5: first render greys nothing',
    h.cells().every(c => !c.classList.contains('bk-cell--beyond')));
  check('5: first render leaves every future date clickable',
    h.cells().filter(c => c.dataset.date >= ymd(T)).every(c => h.live(c.dataset.date)));
  check('5: ...and no check-in/check-out is set',
    h.ci() === '' && h.co() === '');
}

console.log('\n' + (failures ? failures + ' FAILED' : 'All passed'));
process.exit(failures ? 1 : 0);

})().catch(e => { console.error(e); process.exit(1); });
