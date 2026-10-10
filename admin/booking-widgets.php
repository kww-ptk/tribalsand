<?php
declare(strict_types=1);
/**
 * Admin: booking widget embed codes (owner-only).
 *
 * One code per published property for its own website. The code loads the SAME
 * widget the property page on tribalsand.com uses (booking-embed.php), so the
 * property sites book into the one calendar — see includes/booking-embed.php.
 * Read-only: nothing on this page writes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking-embed.php';
require_login();
require_owner();

$pageTitle  = 'Booking widgets';
$activeMenu = 'venues';

$base = site_url();
try { $venues = booking_embed_venues(); $loadError = false; }
catch (Throwable $e) { $venues = []; $loadError = true; }

$kindLabel = [
    'property'  => 'Choose dates and guests, then pick a room or the whole property',
    'room'      => 'Book the whole property',
    'maya_ilai' => 'Build-your-stay configurator',
];

include __DIR__ . '/_layout.php';
?>
<style>
.bw-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,460px);gap:16px;align-items:start}
@media (max-width:1100px){.bw-grid{grid-template-columns:minmax(0,1fr)}}
.bw-item{padding:16px 18px;border-bottom:1px solid var(--border,#eee)}
.bw-item:last-child{border-bottom:0}
.bw-item__top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.bw-item__name{font-weight:600;font-size:15px}
.bw-item__kind{font-size:12px;color:var(--muted,#888)}
.bw-code{width:100%;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;padding:10px 12px;border:1px solid var(--border,#e5e5e5);border-radius:8px;background:#fafaf7;resize:none;white-space:pre;overflow-x:auto;box-sizing:border-box}
.bw-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.bw-more{margin-top:8px;font-size:12px}
.bw-more summary{cursor:pointer;color:var(--muted,#777)}
.bw-preview{position:sticky;top:16px}
.bw-preview__frame{display:block;width:100%;height:720px;border:0;background:#f6f3ec;border-radius:0 0 10px 10px}
.bw-preview__empty{padding:40px 20px;text-align:center;color:var(--muted,#888);font-size:13px}
.bw-steps{margin:0;padding-left:18px;font-size:13px;line-height:1.7}
.bw-theme{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.bw-field{display:flex;flex-direction:column;gap:7px;min-width:0;font-size:12px;font-weight:600;color:var(--text)}
.bw-field input:not([type=color]),.bw-field select{width:100%;min-height:42px;border:1px solid var(--border);border-radius:var(--radius,8px);padding:10px 12px;background:var(--white,#fff);color:var(--text);font:inherit;font-weight:400;box-sizing:border-box}
.bw-field input:focus-visible,.bw-field select:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
.bw-field small{font-size:11px;font-weight:400;color:var(--muted);line-height:1.5}
.bw-color{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius,8px);background:var(--white,#fff);min-height:42px;box-sizing:border-box}
.bw-color input{width:28px;height:28px;padding:0;border:0;background:transparent;cursor:pointer;flex-shrink:0}
.bw-color input::-webkit-color-swatch-wrapper{padding:0}
.bw-color input::-webkit-color-swatch{border:1px solid var(--border);border-radius:6px}
.bw-color input::-moz-color-swatch{border:1px solid var(--border);border-radius:6px}
.bw-color output{font:12px ui-monospace,Consolas,monospace;color:var(--muted)}
.bw-description{font-size:13px;line-height:1.6;color:var(--muted);margin:0 0 18px}
.bw-preview .card__head{gap:12px;flex-wrap:wrap}
.bw-preview__stage{padding:16px;background:var(--bg,#f6f3ec);border-radius:0 0 var(--radius,8px) var(--radius,8px)}
.bw-preview__frame{margin:0 auto;background:transparent;border-radius:var(--radius,8px)}
.bw-preview__frame[hidden],#bwPreviewOpen[hidden]{display:none}
@media(max-width:640px){.bw-theme{grid-template-columns:repeat(2,minmax(0,1fr))}.bw-preview{position:static}}
@media(max-width:380px){.bw-theme{grid-template-columns:minmax(0,1fr)}}
</style>

<div class="page-header">
  <div>
    <h1>Booking widgets</h1>
    <p class="text-muted" style="margin:4px 0 0;font-size:13px">Put a property’s booking form on its own website. The form is the same one as on tribalsand.com and books into the same calendar, so the sites can never double-book each other.</p>
  </div>
</div>

<?php if ($loadError): ?>
<div class="alert alert--error">The properties could not be loaded. Try again in a moment.</div>
<?php endif; ?>

<div class="bw-grid">
  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card__head"><span class="card__title">How to add it to a property website</span></div>
      <div class="card__body card__body--pad">
        <ol class="bw-steps">
          <li>Copy the property’s code below.</li>
          <li>On the property website, paste it where the booking form should appear (in WordPress: an <strong>HTML</strong> / <strong>Custom HTML</strong> block).</li>
          <li>Publish, then open the page and check the form shows. Requests and holds land in Bookings here, with the website’s address as the lead source.</li>
        </ol>
      </div>
    </div>
    <div class="card">
      <div class="card__head"><span class="card__title">Widget appearance</span></div>
      <div class="card__body card__body--pad">
        <p class="bw-description">Match the booking form to your website. Changes update the preview and embed codes below.</p>
        <div class="bw-theme" id="bwTheme">
          <label class="bw-field"><span>Maximum width</span><input data-bw-option="max-width" value="440px" placeholder="440px or 100%"><small>Fits the available space. Use 100% for full width.</small></label>
          <label class="bw-field"><span>Corner radius (px)</span><input type="number" data-bw-option="radius" value="14" min="0" max="32"><small>0 for square corners, up to 32 for rounded.</small></label>
          <label class="bw-field"><span>Heading style</span><select data-bw-option="font"><option value="serif">Classic serif</option><option value="sans">Simple sans serif</option></select></label>
          <?php foreach (['primary' => ['Buttons', '#1e5c6b'], 'header' => ['Header', '#102f3a'], 'accent' => ['Accent', '#b8965a'], 'background' => ['Background', '#ffffff']] as $key => [$label, $color]): ?>
          <label class="bw-field"><span><?= e($label) ?></span><span class="bw-color"><input type="color" data-bw-option="<?= e($key) ?>" value="<?= e($color) ?>"><output><?= e(strtoupper($color)) ?></output></span></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><span class="card__title">Codes per property</span></div>
      <div class="card__body" style="padding:0">
        <?php if (!$venues): ?>
          <div class="bw-preview__empty">No published properties.</div>
        <?php endif; ?>
        <?php foreach ($venues as $v): $code = booking_embed_code($v['slug'], $base); $iframe = booking_embed_iframe_code($v['slug'], $base, 'Book ' . $v['name']); ?>
        <div class="bw-item">
          <div class="bw-item__top">
            <div>
              <div class="bw-item__name"><?= e($v['name']) ?></div>
              <div class="bw-item__kind"><?= $v['plan'] ? e($kindLabel[$v['plan']['kind']] ?? '') : 'No published room to book yet — the widget shows a link to tribalsand.com' ?></div>
            </div>
            <button type="button" class="btn-outline btn-sm" data-bw-preview="<?= e($v['slug']) ?>" data-bw-name="<?= e($v['name']) ?>">Preview</button>
          </div>
          <textarea class="bw-code" rows="2" readonly aria-label="Embed code for <?= e($v['name']) ?>"><?= e($code) ?></textarea>
          <div class="bw-actions">
            <button type="button" class="btn-primary btn-sm" data-bw-copy>Copy code</button>
            <a class="btn-outline btn-sm" data-bw-open="<?= e($v['slug']) ?>" href="/booking-embed?venue=<?= e(rawurlencode($v['slug'])) ?>" target="_blank" rel="noopener">Open in new tab ↗</a>
          </div>
          <details class="bw-more">
            <summary>Website doesn’t allow scripts? Use this instead</summary>
            <p class="text-muted" style="margin:6px 0">A plain frame of a fixed height. It works everywhere, but doesn’t resize itself and its booking pop-up stays inside the frame.</p>
            <textarea class="bw-code" rows="2" readonly aria-label="Frame code for <?= e($v['name']) ?>"><?= e($iframe) ?></textarea>
            <div class="bw-actions"><button type="button" class="btn-outline btn-sm" data-bw-copy>Copy frame code</button></div>
          </details>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card bw-preview">
    <div class="card__head"><span class="card__title" id="bwPreviewTitle">Preview</span><a class="btn-outline btn-sm" id="bwPreviewOpen" target="_blank" rel="noopener" hidden>Open in new tab ↗</a></div>
    <div class="bw-preview__empty" id="bwPreviewEmpty">Press <strong>Preview</strong> on a property to see its widget as guests will.</div>
    <div class="bw-preview__stage"><iframe class="bw-preview__frame" id="bwPreviewFrame" title="Booking widget preview" hidden></iframe></div>
  </div>
</div>

<script>
(function () {
  if (window.__bwBound) return;   // the admin shell re-runs inline scripts on every swap
  window.__bwBound = true;
  function theme() {
    var options = {};
    document.querySelectorAll('[data-bw-option]').forEach(function (input) { options[input.getAttribute('data-bw-option')] = input.value; });
    return options;
  }
  function previewUrl(slug) {
    var params = new URLSearchParams(theme());
    params.set('venue', slug);
    return '/booking-embed?' + params.toString();
  }
  document.addEventListener('input', function (event) {
    if (!event.target.matches('[data-bw-option]')) return;
    var options = theme();
    var swatch = event.target.closest('.bw-color');
    if (swatch) swatch.querySelector('output').textContent = event.target.value.toUpperCase();
    document.querySelectorAll('[data-bw-open]').forEach(function (link) { link.href = previewUrl(link.getAttribute('data-bw-open')); });
    document.querySelectorAll('.bw-item').forEach(function (item) {
      var box = item.querySelector('.bw-code');
      var template = document.createElement('template');
      template.innerHTML = box.value;
      var div = template.content.querySelector('[data-tribalsand-booking]');
      if (!div) return;
      Object.keys(options).forEach(function (key) { div.setAttribute('data-' + key, options[key]); });
      box.value = div.outerHTML + '\n' + template.content.querySelector('script').outerHTML;
    });
    var frame = document.getElementById('bwPreviewFrame');
    if (frame && frame.dataset.venue) {
      frame.style.maxWidth = options['max-width'];
      frame.src = previewUrl(frame.dataset.venue);
      document.getElementById('bwPreviewOpen').href = frame.src;
    }
  });
  document.addEventListener('click', function (e) {
    var copy = e.target.closest('[data-bw-copy]');
    if (copy) {
      var box = copy.closest('.bw-item, .bw-more').querySelector('.bw-code');
      var done = function () { if (window.tsToast) window.tsToast('Code copied', 'success'); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
      else { box.select(); document.execCommand('copy'); done(); }
      return;
    }
    var prev = e.target.closest('[data-bw-preview]');
    if (prev) {
      var f = document.getElementById('bwPreviewFrame');
      if (!f) return;
      f.dataset.venue = prev.getAttribute('data-bw-preview');
      f.style.maxWidth = theme()['max-width'];
      f.src = previewUrl(f.dataset.venue);
      f.hidden = false;
      var open = document.getElementById('bwPreviewOpen');
      open.href = f.src;
      open.hidden = false;
      document.getElementById('bwPreviewEmpty').hidden = true;
      document.getElementById('bwPreviewTitle').textContent = 'Preview — ' + prev.getAttribute('data-bw-name');
    }
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
