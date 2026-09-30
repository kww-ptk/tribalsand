<?php
/**
 * Global rate editor UI — the "Set rates" modal, the change log card and the
 * Timeline selection bar, for admin/rates.php. OWNER ONLY: the caller includes
 * this only when is_owner() (every endpoint re-checks; see api/rate-editor.php).
 * Spec: docs/superpowers/specs/2026-09-30-global-rate-editor-design.md
 *
 * Needs includes/rate-editor.php loaded. One editor, three entry points — the
 * toolbar button ([data-re-open]), a Rate-card price cell ([data-re-cell]) and a
 * Timeline selection ([data-re-tl]) — all handled by admin/assets/admin-rate-editor.js,
 * emitted INLINE once per page (admin shell navigation re-runs inline scripts
 * only, never a <script src> inside content).
 *
 * Every figure the preview shows is the server's (action "preview"); "apply"
 * recomputes on the server. Nothing here computes a price.
 */
$__reOpt = rate_editor_options();
$__reLog = $__reOpt['log_supported'] ? rate_editor_log(10) : ['supported' => false, 'changes' => []];
?>
<div data-re data-endpoint="/api/rate-editor.php" data-csrf="<?= e(csrf_token()) ?>"
     data-max-ranges="<?= (int)$__reOpt['limits']['max_ranges'] ?>">

  <?php /* ── Change log ─────────────────────────────────────────────── */ ?>
  <?php if (!$__reLog['supported']): ?>
  <p class="re-lognote">Run <code>add_rate_change_log.sql</code> to enable the change log and undo.</p>
  <?php else: ?>
  <details class="card re-log" data-re-log>
    <summary class="card__head">
      <span class="card__title">Rate changes</span>
      <span class="re-log__meta"><?= $__reLog['changes'] ? 'Latest ' . count($__reLog['changes']) : 'None yet' ?> <?= admin_icon('chevron-down', 15) ?></span>
    </summary>
    <?php if (!$__reLog['changes']): ?>
    <p class="re-log__empty">Changes made with <strong>Set rates</strong> are listed here, with undo.</p>
    <?php else: ?>
    <ul class="re-log__list">
      <?php foreach ($__reLog['changes'] as $c): ?>
      <li class="re-log__item<?= $c['undone'] ? ' is-undone' : '' ?>">
        <div class="re-log__main">
          <div class="re-log__sum"><?= e($c['summary']) ?></div>
          <div class="re-log__by">
            <?= e($c['by']) ?> · <?= e($c['created_text']) ?>
            <?php if ($c['room_names']): ?> · <span title="<?= e(implode(', ', $c['room_names'])) ?>"><?= count($c['room_names']) === 1 ? e($c['room_names'][0]) : count($c['room_names']) . ' rooms' ?></span><?php endif; ?>
          </div>
        </div>
        <div class="re-log__act">
          <?php if ($c['undone']): ?>
          <span class="re-pill re-pill--muted" title="<?= e('Undone by ' . ($c['undone_by'] ?? 'Unknown') . ($c['undone_at'] ? ' · ' . date('j M Y, H:i', strtotime($c['undone_at'])) : '')) ?>">Undone</span>
          <?php elseif ($c['can_undo']): ?>
          <button type="button" class="btn-outline btn-sm" data-re-undo="<?= (int)$c['id'] ?>" data-summary="<?= e($c['summary']) ?>"><?= admin_icon('rotate', 14) ?> Undo</button>
          <?php else: ?>
          <span class="re-log__blocked"><?= e($c['undo_blocked']['message'] ?? 'Cannot be undone.') ?></span>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php /* ── Timeline selection bar ─────────────────────────────────── */ ?>
  <div class="re-selbar" data-re-selbar hidden>
    <button type="button" class="re-selbar__go" data-re-selgo>Set rates</button>
    <button type="button" class="re-selbar__x" data-re-selclear aria-label="Clear selection">×</button>
  </div>

  <?php /* ── The Set rates modal ────────────────────────────────────── */ ?>
  <div class="re-modal" data-re-modal hidden>
    <div class="re-modal__back" data-re-close></div>
    <div class="re-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reTitle">
      <div class="re-modal__head">
        <h2 id="reTitle">Set rates</h2>
        <button type="button" class="btn-icon" data-re-close aria-label="Close">×</button>
      </div>
      <p class="re-context" data-re-context hidden></p>

      <div data-re-step="form">
        <section class="re-sec">
          <div class="re-sec__head">
            <h3>Rooms</h3>
            <span class="re-sec__meta"><span data-re-count>No rooms selected</span>
              <button type="button" class="re-link" data-re-clear-rooms>Clear</button></span>
          </div>
          <div class="re-rooms">
            <?php foreach ($__reOpt['venues'] as $v):
                  if (!$v['rooms']) continue;
                  $vk = $v['id'] === null ? 'none' : (string)$v['id']; ?>
            <div class="re-venue" data-re-vgroup="<?= e($vk) ?>" data-buyout="<?= (int)($v['buyout_room_id'] ?? 0) ?>">
              <label class="optchip re-vchip"><input type="checkbox" data-re-venue="<?= e($vk) ?>"> <?= e($v['name']) ?><?php if ($v['id'] !== null && !$v['is_published']): ?> <span class="re-muted">(hidden)</span><?php endif; ?></label>
              <div class="re-vrooms">
                <?php foreach ($v['rooms'] as $r): ?>
                <label class="ckwrap re-room">
                  <input type="checkbox" data-re-room value="<?= (int)$r['id'] ?>" data-venue="<?= e($vk) ?>"
                         data-cur="<?= e($r['currency'] ?: 'USD') ?>" data-name="<?= e($r['name']) ?>"
                         data-venue-name="<?= e($v['name']) ?>">
                  <span class="ck"></span>
                  <span class="re-room__txt"><?= e($r['name']) ?>
                    <span class="re-room__meta"><?= e($r['currency'] ?: 'USD') ?><?= $r['is_entire_place'] ? ' · whole property' : '' ?><?= $r['is_published'] ? '' : ' · hidden' ?></span></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="re-sec">
          <div class="re-sec__head">
            <h3>Nights</h3>
            <span class="re-sec__meta" data-re-nights></span>
          </div>
          <div class="re-ranges" data-re-ranges></div>
          <button type="button" class="btn-outline btn-sm" data-re-add-range><?= admin_icon('plus', 14) ?> Add dates</button>
          <template data-re-range-tpl>
            <div class="re-range" data-re-range>
              <div class="re-field"><span>First night</span>
                <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="__PAIR__" data-dp-target="__ID___f" data-dp-placeholder="Pick a date">Pick a date</button>
                <input type="hidden" id="__ID___f" data-re-first></div>
              <div class="re-field"><span>Last night</span>
                <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="__PAIR__" data-dp-target="__ID___l" data-dp-placeholder="Same night">Same night</button>
                <input type="hidden" id="__ID___l" data-re-last></div>
              <button type="button" class="btn-icon re-range__rm" data-re-rm-range title="Remove these dates" aria-label="Remove these dates"><?= admin_icon('trash', 15) ?></button>
            </div>
          </template>
        </section>

        <section class="re-sec">
          <h3>Price</h3>
          <div class="optset re-modes" role="radiogroup" aria-label="How to set the price">
            <label class="optchip"><input type="radio" name="re_mode" value="fixed" data-re-mode checked> Fixed price</label>
            <label class="optchip"><input type="radio" name="re_mode" value="percent" data-re-mode> Change by %</label>
            <label class="optchip"><input type="radio" name="re_mode" value="match" data-re-mode> Same as season</label>
            <label class="optchip"><input type="radio" name="re_mode" value="base" data-re-mode> Back to base price</label>
          </div>

          <div class="re-modefields">
            <div class="re-field" data-re-for="fixed"><span>Price per night</span>
              <div class="re-money"><span class="re-money__cur" data-re-cur>—</span>
                <input class="inp inp--num no-spin" type="number" min="0" step="any" inputmode="decimal" data-re-amount placeholder="51000"></div>
            </div>
            <div class="re-field" data-re-for="percent" hidden><span>Change by</span>
              <div class="re-money"><input class="inp inp--num no-spin" type="number" step="any" inputmode="decimal" data-re-pct placeholder="5 or -10"><span class="re-money__cur">%</span></div>
              <small>Each night's current price, rounded to the nearest KES 10 / $1.</small>
            </div>
            <div class="re-field re-field--wide" data-re-for="match" hidden><span>Each room's own price for</span>
              <?php if ($__reOpt['labels']): ?>
              <select class="eselect" data-re-match aria-label="Season to match">
                <?php foreach ($__reOpt['labels'] as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select>
              <small>The room's most common price for that season in the same year as each night. Rooms without it are skipped.</small>
              <?php else: ?>
              <small>No season labels are in use yet.</small>
              <?php endif; ?>
            </div>
            <p class="re-hint" data-re-for="base" hidden>Removes the seasonal rates on these nights — each room's own base price applies.</p>
          </div>

          <div class="re-labelrow" data-re-labelrow>
            <div class="re-field re-field--wide"><span>Season label</span>
              <input class="inp" type="text" maxlength="100" list="reLabelList" data-re-label placeholder="Mid season" autocomplete="off">
              <datalist id="reLabelList"><?php foreach ($__reOpt['labels'] as $l): ?><option value="<?= e($l) ?>"></option><?php endforeach; ?></datalist>
            </div>
            <label class="ckwrap re-keep" data-re-keepwrap><input type="checkbox" data-re-keep checked><span class="ck"></span> Keep each night's label</label>
          </div>

          <label class="ckwrap re-buyout" data-re-buyoutwrap hidden><input type="checkbox" data-re-buyouts checked><span class="ck"></span>
            Also update buyouts <span class="re-muted">— the whole-property price becomes the sum of its rooms</span></label>
          <p class="re-warn" data-re-curwarn hidden></p>
        </section>
      </div>

      <div data-re-step="preview" hidden>
        <div class="re-pv" data-re-preview></div>
      </div>

      <div class="re-error" data-re-error role="alert" hidden></div>

      <div class="re-foot">
        <button type="button" class="btn-outline btn-sm" data-re-close data-re-for-step="form">Cancel</button>
        <button type="button" class="btn-primary btn-sm" data-re-preview-btn data-re-for-step="form">Preview</button>
        <button type="button" class="btn-outline btn-sm" data-re-back data-re-for-step="preview" hidden><?= admin_icon('chevron-left', 14) ?> Back</button>
        <button type="button" class="btn-primary btn-sm" data-re-apply data-re-for-step="preview" hidden>Confirm &amp; save</button>
      </div>
    </div>
  </div>
</div>

<?php if (empty($GLOBALS['__re_assets_done'])): $GLOBALS['__re_assets_done'] = true; ?>
<style>
/* Entry points */
.re-card td[data-re-cell]{cursor:pointer}
.re-card td[data-re-cell]:hover{background:rgba(30,92,107,.07)}
.re-card td[data-re-cell]:focus-visible{outline:2px solid var(--brand);outline-offset:-2px}
.re-tl{user-select:none;-webkit-user-select:none}
.re-tl td[data-d]{cursor:cell}
.rc-tl.re-tl td.is-sel{box-shadow:inset 0 0 0 2px var(--brand),inset 0 0 0 999px rgba(30,92,107,.16)}
.re-headbtns{display:flex;gap:8px;flex-wrap:wrap}
.re-selbar{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:950;display:flex;align-items:center;gap:2px;
  background:var(--brand);color:#fff;border-radius:999px;box-shadow:0 10px 30px rgba(16,47,58,.3);max-width:calc(100vw - 32px)}
.re-selbar[hidden]{display:none}
.re-selbar button{border:0;background:transparent;color:#fff;font:inherit;cursor:pointer}
.re-selbar__go{flex:1 1 auto;padding:11px 8px 11px 18px;font-size:13.5px;font-weight:600;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.re-selbar__x{padding:8px 14px;font-size:18px;line-height:1;opacity:.8}
.re-selbar__x:hover,.re-selbar__go:hover{opacity:1;text-decoration:underline}
/* Change log */
.re-lognote{font-size:12.5px;color:var(--muted);margin:18px 2px 0}
.re-log{margin:22px 0 0}
.re-log>summary{cursor:pointer;list-style:none}
.re-log>summary::-webkit-details-marker{display:none}
.re-log:not([open])>summary{border-bottom:0}
.re-log__meta{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted)}
.re-log__meta svg{transition:transform .18s}
.re-log[open] .re-log__meta svg{transform:rotate(180deg)}
.re-log__empty{margin:0;padding:14px 20px;font-size:13px;color:var(--muted)}
.re-log__list{list-style:none;margin:0;padding:0}
.re-log__item{display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border)}
.re-log__item:last-child{border-bottom:0}
.re-log__item.is-undone .re-log__sum{color:var(--muted);text-decoration:line-through}
.re-log__main{flex:1;min-width:0}
.re-log__sum{font-size:13.5px;font-weight:600;overflow-wrap:anywhere}
.re-log__by{font-size:12px;color:var(--muted);margin-top:2px}
.re-log__act{flex:0 0 auto;text-align:right;max-width:45%}
.re-log__blocked{font-size:12px;color:var(--muted)}
.re-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#e6eef5;color:#1f4460}
.re-pill--muted{background:#eeeae4;color:var(--muted)}
.re-pill--buyout{background:#182247;color:#fff}
/* Modal */
.re-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto;overscroll-behavior:contain}
.re-modal[hidden]{display:none}
.re-modal__back{position:fixed;inset:0;background:rgba(16,47,58,.45)}
.re-modal__dialog{position:relative;background:#faf8f5;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);width:100%;max-width:800px;padding:18px 20px 0;box-sizing:border-box}
.re-modal__head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.re-modal__head h2{margin:0;font-size:18px}
.re-context{margin:6px 0 0;font-size:12.5px;color:var(--muted)}
.re-sec{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-top:12px;min-width:0}
.re-sec h3{margin:0 0 10px;font-size:14px}
.re-sec__head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
.re-sec__meta{font-size:12px;color:var(--muted);display:inline-flex;gap:10px;align-items:baseline}
.re-link{border:0;background:none;padding:0;font:inherit;font-size:12px;color:var(--brand);cursor:pointer;text-decoration:underline}
.re-muted{color:var(--muted);font-weight:400}
.re-rooms{display:grid;gap:12px;max-height:300px;overflow-y:auto;padding-right:4px}
.re-venue{display:grid;gap:8px}
.re-vchip{justify-self:start;padding:5px 12px;font-size:12.5px;font-weight:600}
.re-vchip.is-part{border-color:var(--brand);box-shadow:inset 0 0 0 1px var(--brand)}
.re-vrooms{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px 14px;padding-left:4px}
.re-room{align-items:flex-start;min-width:0}
.re-room__txt{min-width:0;overflow-wrap:anywhere;line-height:1.3}
.re-room__meta{display:block;font-size:11px;color:var(--muted)}
.re-ranges{display:grid;gap:10px;margin-bottom:10px}
.re-range{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.re-range .re-field{flex:1 1 150px;min-width:0}
.re-field{display:grid;gap:5px;font-size:12px;color:var(--muted);font-weight:600;min-width:0}
.re-field small{font-weight:400;font-size:11.5px}
.re-field .dp-btn,.re-field .inp{width:100%;box-sizing:border-box}
.re-modes{margin-bottom:12px}
.re-modes .optchip{padding:7px 13px;font-size:12.5px}
.re-modefields{display:grid;gap:10px;max-width:420px}
.re-modefields [hidden]{display:none}
.re-field--wide .eselect{width:100%}
.re-money{display:flex;align-items:center;gap:8px}
.re-money .inp{flex:1;min-width:0}
.re-money__cur{font-size:13px;font-weight:700;color:var(--text);white-space:nowrap}
.re-hint{margin:0;font-size:13px;color:var(--muted)}
.re-labelrow{display:flex;gap:10px 16px;align-items:flex-end;flex-wrap:wrap;margin-top:12px}
.re-labelrow[hidden]{display:none}
.re-labelrow .re-field{flex:1 1 220px;max-width:320px}
.re-keep{padding-bottom:8px}
.re-keep[hidden]{display:none}
.re-buyout{margin-top:14px;align-items:flex-start}
.re-buyout[hidden]{display:none}
.re-warn{margin:12px 0 0;padding:9px 12px;border-radius:9px;background:#fff0ed;color:#a3362a;font-size:12.5px}
.re-warn[hidden]{display:none}
.re-error{margin:12px 0 0;padding:10px 12px;border-radius:9px;background:#fff0ed;color:#a3362a;font-size:13px}
.re-error[hidden]{display:none}
.re-foot{position:sticky;bottom:0;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;padding:14px 0 18px;background:#faf8f5;margin-top:4px;z-index:2}
.re-foot [hidden]{display:none}
/* Preview */
.re-pv{margin-top:12px}
.re-pv__sum{margin:0;font-size:15px;font-weight:700;overflow-wrap:anywhere}
.re-pv__tot{margin:4px 0 0;font-size:12.5px;color:var(--muted)}
.re-pv__note{margin:10px 0 0;padding:9px 12px;border-radius:9px;background:#e6f4f2;color:#096c66;font-size:12.5px}
.re-pv__rows{margin-top:12px;background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.re-pv__row{display:grid;grid-template-columns:minmax(0,1.3fr) auto minmax(0,1.6fr);gap:4px 14px;padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px;align-items:baseline}
.re-pv__row:last-child{border-bottom:0}
.re-pv__row.is-skipped,.re-pv__row.is-unchanged{background:#faf8f5;color:var(--muted)}
.re-pv__room strong{font-weight:600;color:var(--text);overflow-wrap:anywhere}
.re-pv__room small{display:block;font-size:11.5px;color:var(--muted)}
.re-pv__n{font-size:12px;color:var(--muted);white-space:nowrap}
.re-pv__money{text-align:right;overflow-wrap:anywhere}
.re-pv__arrow{color:var(--muted);margin:0 4px}
.re-pv__after{font-weight:700;color:var(--text)}
.re-pv__extra{grid-column:1/-1;font-size:12px;color:var(--muted);display:grid;gap:3px}
.re-pv__labels .rc-pill{margin-right:3px}
.re-pv__runs{margin:0;padding:0;list-style:none}
.re-pv__buyouts{margin:10px 0 0;padding:0;list-style:none;font-size:12.5px;color:var(--muted);display:grid;gap:4px}
@media (max-width:640px){
  .re-modal{padding:0}
  .re-modal__dialog{border-radius:0;max-width:none;min-height:100%;padding:14px 16px 0}
  .re-rooms{max-height:none;overflow:visible}
  .re-vrooms{grid-template-columns:minmax(0,1fr)}
  .re-modefields{max-width:none}
  .re-labelrow .re-field{max-width:none}
  .re-pv__row{grid-template-columns:minmax(0,1fr) auto}
  .re-pv__money{grid-column:1/-1;text-align:left}
  .re-log__item{flex-wrap:wrap}
  .re-log__act{max-width:none;text-align:left}
  .re-selbar{bottom:14px}
}
</style>
<script><?php readfile(__DIR__ . '/../admin/assets/admin-rate-editor.js'); ?></script>
<?php endif; ?>
