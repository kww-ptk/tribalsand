<?php
declare(strict_types=1);
/**
 * Reusable media picker for admin screens.
 *
 * Renders ONE modal per page (the markup is emitted once, however many fields
 * use it) plus a small field control per image slot. Clicking "Choose image"
 * opens the modal showing every image already on the site — see
 * media_library_items() — with an "Upload new" tile at the front.
 *
 * Usage:
 *     media_picker_field('hero_image', $currentKey, 'First hero slide');
 *     ... once, near the end of the page:
 *     media_picker_modal();
 *
 * The field writes the chosen storage key into a hidden <input name="…">, so a
 * host form submits it like any other field. Uploads POST to admin/media.php,
 * which returns JSON — the modal never navigates away from the editor.
 */
require_once __DIR__ . '/media.php';

/** One image slot: thumbnail, filename, Choose / Clear. */
function media_picker_field(string $name, string $value, string $label = '', string $hint = '', string $default = ''): void {
    // With no override we still preview the image the page ships with, so the
    // field shows what is actually on the site rather than an empty box.
    $url = media_url($value !== '' ? $value : $default);
    $id  = 'mp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    ?>
    <div class="mp-field" data-mp-field data-mp-default="<?= e($default !== '' ? media_url($default) : '') ?>">
      <?php if ($label !== ''): ?><label class="detail-item__label"><?= e($label) ?></label><?php endif; ?>
      <div class="mp-row">
        <div class="mp-thumb" data-mp-thumb>
          <?php if ($url !== ''): ?><img src="<?= e($url) ?>" alt=""><?php else: ?><span>No image</span><?php endif; ?>
        </div>
        <div class="mp-meta">
          <div class="mp-key" data-mp-key><?= $value !== '' ? e($value) : '<span class="text-muted">Using the built-in default</span>' ?></div>
          <div class="mp-actions">
            <button type="button" class="btn-outline btn-sm" data-mp-open="<?= e($id) ?>">Choose image</button>
            <button type="button" class="btn-outline btn-sm" data-mp-clear<?= $value === '' ? ' hidden' : '' ?>>Reset to default</button>
          </div>
          <?php if ($hint !== ''): ?><div class="field-hint"><?= e($hint) ?></div><?php endif; ?>
        </div>
      </div>
      <input type="hidden" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" data-mp-input>
    </div>
    <?php
}

/** The shared modal + styles + behaviour. Call once per page. */
function media_picker_modal(): void {
    static $done = false;
    if ($done) return;           // one modal per page, however many fields
    $done = true;
    $items = media_library_items();
    ?>
    <style>
    .mp-field [hidden]{display:none!important}
    .mp-row{display:flex;gap:14px;align-items:flex-start}
    .mp-thumb{width:104px;height:78px;flex:0 0 auto;border:1px solid var(--border,#e5e7eb);border-radius:6px;overflow:hidden;background:var(--bg,#f9fafb);display:flex;align-items:center;justify-content:center}
    .mp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .mp-thumb span{font-size:11px;color:var(--muted,#6b7280)}
    .mp-meta{flex:1 1 auto;min-width:0}
    .mp-key{font:11.5px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted,#6b7280);word-break:break-all;margin-bottom:8px}
    .mp-actions{display:flex;gap:8px;flex-wrap:wrap}
    .mp-back{position:fixed;inset:0;background:rgba(16,20,26,.55);z-index:9998;display:none}
    .mp-back.open{display:block}
    .mp-modal{position:fixed;inset:5vh 50% auto auto;transform:translateX(50%);width:min(920px,92vw);max-height:90vh;background:#fff;border-radius:10px;z-index:9999;display:none;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.3)}
    .mp-modal.open{display:flex}
    .mp-head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border,#e5e7eb)}
    .mp-head h3{margin:0;font-size:15px;flex:1 1 auto}
    .mp-search{flex:0 0 220px;padding:7px 10px;border:1px solid var(--border,#d1d5db);border-radius:6px;font-size:13px}
    .mp-body{padding:16px 18px;overflow-y:auto}
    .mp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:12px}
    .mp-tile{border:2px solid transparent;border-radius:7px;overflow:hidden;cursor:pointer;background:var(--bg,#f9fafb);padding:0;text-align:left}
    .mp-tile:hover{border-color:var(--sand,#B8965A)}
    .mp-tile img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
    .mp-tile figcaption{font:10.5px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted,#6b7280);padding:5px 6px;word-break:break-all}
    .mp-src{font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--sand,#B8965A)}
    .mp-upload{border:2px dashed var(--border,#d1d5db);border-radius:7px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;aspect-ratio:4/3;cursor:pointer;color:var(--muted,#6b7280);font-size:12px}
    .mp-upload:hover{border-color:var(--sand,#B8965A);color:var(--sand,#B8965A)}
    .mp-empty{color:var(--muted,#6b7280);font-size:13px;padding:20px 0}
    </style>

    <div class="mp-back" data-mp-back></div>
    <div class="mp-modal" data-mp-modal role="dialog" aria-modal="true" aria-label="Choose an image">
      <div class="mp-head">
        <h3>Choose an image</h3>
        <input type="search" class="mp-search" placeholder="Search filename…" data-mp-search>
        <button type="button" class="btn-outline btn-sm" data-mp-close>Close</button>
      </div>
      <div class="mp-body">
        <div class="mp-grid" data-mp-grid>
          <label class="mp-upload">
            <span style="font-size:20px;line-height:1">+</span>
            <span>Upload new</span>
            <input type="file" accept="image/jpeg,image/png,image/webp" hidden data-mp-upload>
          </label>
          <?php foreach ($items as $it): ?>
          <button type="button" class="mp-tile" data-mp-pick="<?= e($it['key']) ?>" data-mp-name="<?= e(strtolower($it['key'])) ?>">
            <img src="<?= e($it['url']) ?>" alt="" loading="lazy" decoding="async">
            <figcaption><span class="mp-src"><?= e($it['source']) ?></span><br><?= e($it['key']) ?></figcaption>
          </button>
          <?php endforeach; ?>
        </div>
        <?php if (!$items): ?>
        <p class="mp-empty">No images yet. Use “Upload new” to add the first one — anything you upload through a venue or room gallery will appear here too.</p>
        <?php endif; ?>
      </div>
    </div>

    <script>
    (function(){
      var back  = document.querySelector('[data-mp-back]');
      var modal = document.querySelector('[data-mp-modal]');
      var grid  = modal.querySelector('[data-mp-grid]');
      var target = null;                       // hidden input currently being edited

      function open(id){ target = document.getElementById(id); back.classList.add('open'); modal.classList.add('open'); }
      function close(){ back.classList.remove('open'); modal.classList.remove('open'); target = null; }

      function apply(key, url){
        if (!target) return;
        var field = target.closest('[data-mp-field]');
        target.value = key;
        var thumb = field.querySelector('[data-mp-thumb]');
        thumb.innerHTML = '<img src="' + url + '" alt="">';
        field.querySelector('[data-mp-key]').textContent = key;
        var clear = field.querySelector('[data-mp-clear]'); if (clear) clear.hidden = false;
        close();
      }

      document.addEventListener('click', function(e){
        var o = e.target.closest('[data-mp-open]');   if (o) { open(o.getAttribute('data-mp-open')); return; }
        if (e.target.closest('[data-mp-close]') || e.target.closest('[data-mp-back]')) { close(); return; }
        var p = e.target.closest('[data-mp-pick]');
        if (p) { apply(p.getAttribute('data-mp-pick'), p.querySelector('img').getAttribute('src')); return; }
        var c = e.target.closest('[data-mp-clear]');
        if (c) {
          var field = c.closest('[data-mp-field]');
          field.querySelector('[data-mp-input]').value = '';
          var dflt = field.getAttribute('data-mp-default') || '';
          field.querySelector('[data-mp-thumb]').innerHTML = dflt
            ? '<img src="' + dflt + '" alt="">' : '<span>No image</span>';
          field.querySelector('[data-mp-key]').innerHTML = '<span class="text-muted">Using the built-in default</span>';
          c.hidden = true;
        }
      });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });

      // Filter tiles by filename
      var search = modal.querySelector('[data-mp-search]');
      search.addEventListener('input', function(){
        var q = search.value.trim().toLowerCase();
        grid.querySelectorAll('[data-mp-pick]').forEach(function(t){
          t.style.display = !q || t.getAttribute('data-mp-name').indexOf(q) !== -1 ? '' : 'none';
        });
      });

      // Upload without leaving the editor
      var up = modal.querySelector('[data-mp-upload]');
      up.addEventListener('change', function(){
        if (!up.files || !up.files[0]) return;
        var label = up.closest('.mp-upload');
        var prev = label.innerHTML;
        label.innerHTML = '<span>Uploading…</span>';
        var fd = new FormData();
        fd.append('image', up.files[0]);
        fd.append('csrf_token', (document.querySelector('input[name=csrf_token]')||{}).value || '');
        fetch('/admin/media.php?ajax=1', { method:'POST', body: fd, credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(d){
            label.innerHTML = prev;
            up.value = '';
            if (!d || !d.ok) { alert((d && d.error) || 'Upload failed.'); return; }
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'mp-tile';
            b.setAttribute('data-mp-pick', d.key);
            b.setAttribute('data-mp-name', String(d.key).toLowerCase());
            b.innerHTML = '<img src="' + d.url + '" alt=""><figcaption><span class="mp-src">Library</span><br>' + d.key + '</figcaption>';
            grid.insertBefore(b, label.nextSibling);
            apply(d.key, d.url);
          })
          .catch(function(){ label.innerHTML = prev; up.value = ''; alert('Upload failed.'); });
      });
    })();
    </script>
    <?php
}
