<?php
/**
 * Guest-facing Maya Ilai booking configurator (full pricing parity).
 *
 * TWO STEPS, in this order:
 *   1. How many of you, and for how long. Nothing else.
 *   2. The handful of real configurations that sleep that party, cheapest
 *      first, each with what it includes and its total — plus "Build it
 *      yourself" for anyone who wants to assemble something bespoke.
 *
 * The eight-rows-at-once picker that used to open the page is still here, whole,
 * behind that toggle: it is the only way to reach a bespoke mix, and the search
 * only ever shows a handful. What changed is the order — the guest is asked the
 * one question they can answer before being shown products that overlap ("Double
 * Room + living room" and "One-Bedroom Suite" are the same $750 stay).
 *
 * Both steps price server-side through api/maya-ilai-quote.php, which shares the
 * exact same calculation as the staff tool (one pricing path). Step 2's
 * suggestions are quoted error-free before they are ever returned, so nothing
 * the guest can tap is unbookable.
 *
 * A selection turns into a booking REQUEST (enquiry) — the property confirms it,
 * matching the existing 24h-hold flow. Set nothing before including.
 */
require_once __DIR__ . '/maya-ilai-pricing.php';
$mibCfg   = maya_ilai_pricing_get();
$mibRates = $mibCfg['rates'];
$mibRules = $mibCfg['rules'];
$mibMaxParty = maya_ilai_max_party($mibCfg);

// Unit types shown to guests, in order. inc = default guests when a unit is added,
// minper = the fewest guests one unit can hold (the tool rejects an empty room).
// `parts` is the primitive expansion EVERY row carries — the payload is assembled
// from it, so a combination and a hand-assembled equivalent post identically.
$mibUnits = [
    ['key'=>'Villa',  'parts'=>['villa'=>1],  'rate'=>$mibRates['villa'],  'inc'=>(int)$mibRules['villaIncluded'], 'max'=>(int)$mibRules['villaMax'], 'minper'=>1, 'note'=>'3-bedroom villa · sleeps up to '.$mibRules['villaMax']],
    ['key'=>'Studio', 'parts'=>['studio'=>1], 'rate'=>$mibRates['studio'], 'inc'=>2, 'max'=>2, 'minper'=>1, 'note'=>'Private studio · sleeps 2'],
    ['key'=>'Bunk Room','parts'=>['bunk'=>1], 'rate'=>$mibRates['bunk'],   'inc'=>(int)$mibRules['bunkIncluded'], 'max'=>(int)$mibRules['bunkMax'], 'minper'=>1, 'note'=>'Villa bunk room · up to '.$mibRules['bunkMax']],
    ['key'=>'Double Room','parts'=>['double'=>1],'rate'=>$mibRates['double'],'inc'=>2, 'max'=>2, 'minper'=>1, 'note'=>'Villa double room · sleeps 2'],
];

// The named combination products. Price, occupancy and note are ALL derived from
// the live config (never a hardcoded table), so a rate edited in admin moves the
// product with it — the displayed figure is summed from the same rates that price
// the expansion, so the two cannot drift apart. A combination that has stopped
// making sense at the live rates (costing at least a whole villa, which contains
// it) is dropped by maya_ilai_combo_offerable() rather than quoted.
$mibCombos = [];
foreach (maya_ilai_combos() as $c) {
    if (!maya_ilai_combo_offerable($mibCfg, $c['parts'])) continue;
    $occ    = maya_ilai_combo_occupancy($mibCfg, $c['parts']);
    $sleeps = $occ['included'] === $occ['max']
        ? 'sleeps '.$occ['included']
        : 'sleeps '.$occ['included'].', up to '.$occ['max'];
    $mibCombos[] = [
        'key'=>$c['key'], 'parts'=>$c['parts'], 'rate'=>maya_ilai_combo_rate($mibCfg, $c['parts']),
        'inc'=>$occ['included'], 'max'=>$occ['max'], 'minper'=>$occ['min'],
        'note'=>$c['desc'].' · '.$sleeps,
    ];
}
usort($mibCombos, fn($a, $b) => $a['rate'] <=> $b['rate']);   // price ascending

$mibGroups = [['label'=>'Rooms', 'rows'=>$mibUnits]];
if ($mibCombos) $mibGroups[] = ['label'=>'Combinations', 'rows'=>$mibCombos, 'class'=>'mib-group--combo',
                                'hint'=>'Whole bedroom sets, priced as one product.'];
?>
<style>
  .mib{--mib-line:rgba(184,150,90,.22);--mib-ink:#141412;--mib-mut:#6B6050;font-family:'Jost',sans-serif;color:var(--mib-ink)}
  .mib-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:2rem;align-items:start}
  .mib-rows{display:flex;flex-direction:column;gap:.9rem}
  .mib-group+.mib-group{margin-top:1.6rem}
  .mib-group__hd{display:flex;align-items:baseline;gap:.7rem;flex-wrap:wrap;margin-bottom:.7rem}
  .mib-group__lbl{font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;color:var(--mib-mut)}
  .mib-group__hint{font-size:.76rem;color:var(--mib-mut);opacity:.8}
  /* Combinations read as one picker with the rooms, but visibly their own shelf. */
  .mib-group--combo .mib-rows{border-left:2px solid var(--sand,#B8965A);padding-left:.9rem}
  .mib-group--combo .mib-group__lbl{color:var(--sand-dk,#8A6D33)}
  .mib-group--combo .mib-row{background:var(--sand-faint,#FAF6EE)}
  .mib-row{display:grid;grid-template-columns:1fr auto auto;gap:1rem;align-items:center;border:1px solid var(--mib-line);background:#fff;padding:.9rem 1.1rem}
  .mib-row__name{font-family:'Cormorant Garamond',serif;font-size:1.25rem;color:var(--mib-ink);line-height:1.1}
  .mib-row__note{font-size:.78rem;color:var(--mib-mut);margin-top:.15rem}
  .mib-row__rate{font-size:.8rem;color:var(--teal,#1E5C6B);margin-top:.2rem;font-weight:500}
  .mib-ctl{display:flex;flex-direction:column;align-items:center;gap:.3rem}
  .mib-ctl__lbl{font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mib-mut)}
  .mib-step{display:flex;align-items:center;gap:.5rem}
  .mib-step button{width:30px;height:30px;border:1px solid var(--mib-line);background:#fff;color:var(--teal,#1E5C6B);font-size:1.1rem;line-height:1;cursor:pointer;border-radius:4px}
  .mib-step button:hover{background:var(--sand-faint,#FAF6EE)}
  .mib-step button:disabled{opacity:.35;cursor:not-allowed}
  .mib-step__n{min-width:1.4rem;text-align:center;font-size:1rem;font-variant-numeric:tabular-nums}
  .mib-extra{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin-top:1.1rem;padding-top:1.1rem;border-top:1px solid var(--mib-line)}
  .mib-extra label{font-size:.85rem;color:var(--mib-mut);display:flex;align-items:center;gap:.45rem}
  .mib-extra input[type=number]{width:64px;padding:.45rem .5rem;border:1px solid var(--mib-line);font-family:inherit;font-size:.9rem}
  .mib-living{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
  .mib-living__txt{font-size:.85rem;color:var(--mib-mut)}
  .mib-living__hint{font-size:.72rem;color:var(--mib-mut);opacity:.75;flex-basis:100%}
  .mib-summary{position:sticky;top:90px;border:1px solid var(--mib-line);background:#fff;box-shadow:0 8px 32px rgba(0,0,0,.06)}
  .mib-sum-top{background:var(--teal-d,#102F3A);color:#fff;padding:1.3rem 1.4rem}
  .mib-sum-lbl{font-size:.6rem;letter-spacing:.24em;text-transform:uppercase;color:rgba(184,150,90,.7)}
  .mib-sum-total{font-family:'Cormorant Garamond',serif;font-size:2.1rem;line-height:1;margin:.3rem 0 .15rem}
  .mib-sum-per{font-size:.78rem;color:rgba(255,255,255,.55)}
  .mib-lines{padding:1rem 1.4rem}
  .mib-line{display:flex;justify-content:space-between;gap:1rem;font-size:.85rem;padding:.32rem 0;color:var(--mib-mut)}
  .mib-line strong{color:var(--mib-ink);font-weight:500}
  .mib-line--total{border-top:1px solid var(--mib-line);margin-top:.35rem;padding-top:.6rem;font-size:.95rem}
  .mib-line--total strong{font-weight:700}
  .mib-notice{margin:0 1.4rem 1rem;padding:.6rem .8rem;font-size:.78rem;background:var(--sand-faint,#FAF6EE);color:#7a5a1e;border:1px solid var(--mib-line)}
  .mib-notice.err{background:rgba(200,80,60,.06);color:#9B3B2A;border-color:rgba(200,80,60,.2)}
  .mib-cta{display:block;width:calc(100% - 2.8rem);margin:0 1.4rem 1.2rem;padding:.9rem;border:none;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);font-family:'Jost',sans-serif;font-weight:600;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;cursor:pointer}
  .mib-cta:hover{background:var(--sand-lt,#D4B07A)}
  .mib-cta:disabled{opacity:.4;cursor:not-allowed}
  .mib-fine{padding:0 1.4rem 1.3rem;font-size:.72rem;color:var(--mib-mut);line-height:1.6}

  /* ── Step 1 — how many of you ───────────────────────────────────────────── */
  .mib-party{border:1px solid var(--mib-line);background:#fff;padding:1.8rem 1.6rem;max-width:640px;margin:0 auto;text-align:center}
  .mib-party__q{font-family:'Cormorant Garamond',serif;font-size:1.9rem;line-height:1.15;font-weight:400;margin:0 0 .35rem}
  .mib-party__sub{font-size:.85rem;color:var(--mib-mut);margin:0 0 1.6rem}
  .mib-party__fields{display:flex;flex-wrap:wrap;gap:1.4rem;justify-content:center}
  .mib-field-big{flex:1 1 190px;min-width:0}
  .mib-field-big__lbl{font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--mib-mut);display:block;margin-bottom:.5rem}
  .mib-bigstep{display:flex;align-items:center;justify-content:center;gap:.9rem}
  .mib-bigstep button{width:46px;height:46px;flex:0 0 46px;border:1px solid var(--mib-line);background:#fff;color:var(--teal,#1E5C6B);font-size:1.5rem;line-height:1;cursor:pointer;border-radius:50%}
  .mib-bigstep button:hover{background:var(--sand-faint,#FAF6EE)}
  .mib-bigstep button:disabled{opacity:.3;cursor:not-allowed}
  .mib-bigstep__n{font-family:'Cormorant Garamond',serif;font-size:2.4rem;line-height:1;min-width:2.6rem;text-align:center;font-variant-numeric:tabular-nums}
  .mib-party__go{margin:1.7rem auto 0;width:100%;max-width:320px}
  .mib-party__fine{font-size:.72rem;color:var(--mib-mut);margin:.9rem 0 0;line-height:1.6}

  /* ── Step 2 — what fits ─────────────────────────────────────────────────── */
  .mib-recap{display:flex;align-items:baseline;gap:.8rem;flex-wrap:wrap;border-bottom:1px solid var(--mib-line);padding-bottom:.9rem;margin-bottom:1.4rem}
  .mib-recap__t{font-family:'Cormorant Garamond',serif;font-size:1.5rem;line-height:1.1}
  .mib-recap__back{margin-left:auto;background:none;border:none;padding:.2rem 0;font-family:inherit;font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:var(--teal,#1E5C6B);cursor:pointer;border-bottom:1px solid currentColor}
  .mib-offers{display:flex;flex-direction:column;gap:1rem}
  .mib-off{border:1px solid var(--mib-line);background:#fff;padding:1.2rem 1.3rem;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem 1.6rem;align-items:start}
  .mib-off--top{border-color:var(--sand,#B8965A);box-shadow:0 6px 26px rgba(184,150,90,.16)}
  .mib-off__tag{grid-column:1/-1;font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand-dk,#8A6D33)}
  .mib-off__name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;line-height:1.15;font-weight:400;margin:0}
  .mib-off__meta{font-size:.78rem;color:var(--mib-mut);margin:.2rem 0 0}
  .mib-off__money{text-align:right;white-space:nowrap}
  .mib-off__total{font-family:'Cormorant Garamond',serif;font-size:1.75rem;line-height:1}
  .mib-off__per{font-size:.74rem;color:var(--mib-mut);margin-top:.15rem}
  .mib-off__disc{display:inline-block;margin-top:.3rem;font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:#2D7A5F}
  .mib-off__inc{grid-column:1/-1;list-style:none;margin:.5rem 0 0;padding:.7rem 0 0;border-top:1px solid var(--mib-line);display:flex;flex-direction:column;gap:.3rem}
  .mib-off__inc li{display:flex;justify-content:space-between;gap:1rem;font-size:.82rem;color:var(--mib-mut)}
  .mib-off__u{color:var(--mib-ink)}
  .mib-off__ud{display:block;font-size:.72rem;opacity:.75}
  .mib-off__cta{grid-column:1/-1;width:100%;margin:.9rem 0 0}
  .mib-offers__msg{padding:1rem 1.1rem;border:1px solid var(--mib-line);background:var(--sand-faint,#FAF6EE);font-size:.85rem;color:var(--mib-mut)}
  .mib-bespoke{margin-top:1.8rem;border-top:1px solid var(--mib-line);padding-top:1.3rem}
  .mib-bespoke__btn{background:none;border:1px solid var(--mib-line);padding:.75rem 1.1rem;font-family:inherit;font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;color:var(--teal,#1E5C6B);cursor:pointer;width:100%}
  .mib-bespoke__btn:hover{background:var(--sand-faint,#FAF6EE)}
  .mib-bespoke__hint{font-size:.76rem;color:var(--mib-mut);margin:.7rem 0 0;line-height:1.6}
  .mib-bespoke__body{margin-top:1.5rem}

  /* Enquiry modal */
  .mib-modal{position:fixed;inset:0;background:rgba(16,47,58,.55);display:none;align-items:center;justify-content:center;z-index:600;padding:1rem}
  .mib-modal.open{display:flex}
  .mib-card{background:#fff;max-width:440px;width:100%;padding:1.8rem;max-height:90vh;overflow:auto}
  .mib-card h3{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;margin-bottom:.3rem}
  .mib-card p.sub{font-size:.85rem;color:var(--mib-mut);margin-bottom:1.1rem}
  .mib-field{margin-bottom:.8rem}
  .mib-field label{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--mib-mut);display:block;margin-bottom:.3rem}
  .mib-field input,.mib-field textarea{width:100%;padding:.65rem .8rem;border:1px solid var(--mib-line);font-family:inherit;font-size:.9rem}
  .mib-modal-actions{display:flex;gap:.6rem;margin-top:.5rem}
  .mib-msg{font-size:.85rem;margin-top:.7rem;display:none}
  .mib-msg.show{display:block}
  .mib-msg.ok{color:#2D7A5F}.mib-msg.bad{color:#9B3B2A}
  @media(max-width:820px){.mib-grid{grid-template-columns:1fr}.mib-summary{position:static}}
  /* Phone: the offer card stacks so the price sits under the name, never squeezed
     beside it, and the row steppers keep their own line rather than crushing the
     room name to one word per line. */
  @media(max-width:540px){
    .mib-party{padding:1.4rem 1.1rem}
    .mib-party__q{font-size:1.6rem}
    .mib-party__fields{gap:1.1rem}
    .mib-off{grid-template-columns:1fr;padding:1.1rem}
    .mib-off__money{text-align:left}
    .mib-off__total{font-size:1.9rem}
    .mib-off__inc li{flex-direction:column;gap:.1rem}
    .mib-off__ug{font-size:.74rem;opacity:.8}
    .mib-row{grid-template-columns:1fr 1fr;gap:.7rem 1rem}
    .mib-row>div:first-child{grid-column:1/-1}
    .mib-cta{width:calc(100% - 2rem);margin-left:1rem;margin-right:1rem}
    .mib-off__cta{width:100%;margin-left:0;margin-right:0}
    .mib-sum-top,.mib-lines{padding-left:1rem;padding-right:1rem}
    .mib-notice{margin-left:1rem;margin-right:1rem}
    .mib-fine{padding-left:1rem;padding-right:1rem}
  }
</style>

<div class="mib" id="mibRoot"
     data-endpoint="/api/maya-ilai-quote.php"
     data-contact="/api/submit-contact.php"
     data-maxguests="<?= (int)$mibMaxParty ?>"
     data-rates='<?= e(json_encode($mibRates)) ?>'
     data-rules='<?= e(json_encode(['bunkMax'=>(int)$mibRules['bunkMax'],'doublePerVilla'=>max(1,(int)$mibCfg['inventory']['doublePerVilla'])])) ?>'>

  <!-- ── Step 1 ─────────────────────────────────────────────────────────── -->
  <section class="mib-party" id="mibStep1">
    <h3 class="mib-party__q">How many of you are coming?</h3>
    <p class="mib-party__sub">Tell us the party and the length of stay — we'll show you what fits, with the price.</p>
    <div class="mib-party__fields">
      <div class="mib-field-big">
        <span class="mib-field-big__lbl" id="mibPGuestsLbl">Guests</span>
        <div class="mib-bigstep" id="mibPGuests" aria-labelledby="mibPGuestsLbl">
          <button type="button" data-dir="-1" aria-label="Fewer guests">−</button>
          <span class="mib-bigstep__n" id="mibPGuestsN" aria-live="polite">2</span>
          <button type="button" data-dir="1" aria-label="More guests">+</button>
        </div>
      </div>
      <div class="mib-field-big">
        <span class="mib-field-big__lbl" id="mibPNightsLbl">Nights</span>
        <div class="mib-bigstep" id="mibPNights" aria-labelledby="mibPNightsLbl">
          <button type="button" data-dir="-1" aria-label="Fewer nights">−</button>
          <span class="mib-bigstep__n" id="mibPNightsN" aria-live="polite">3</span>
          <button type="button" data-dir="1" aria-label="More nights">+</button>
        </div>
      </div>
    </div>
    <button type="button" class="mib-cta mib-party__go" id="mibPGo">Show what fits</button>
    <p class="mib-party__fine">Prices in USD, for the whole stay. The property confirms availability and holds your dates — you are not charged now. Group discounts apply automatically for larger parties (min <?= (int)$mibRules['minNights'] ?> nights).</p>
  </section>

  <!-- ── Step 2 ─────────────────────────────────────────────────────────── -->
  <section id="mibStep2" hidden>
    <div class="mib-recap">
      <span class="mib-recap__t" id="mibRecap">2 guests · 3 nights</span>
      <button type="button" class="mib-recap__back" id="mibBack">Change</button>
    </div>

    <div class="mib-offers" id="mibOffers"></div>

    <div class="mib-bespoke">
      <button type="button" class="mib-bespoke__btn" id="mibBespokeBtn" aria-expanded="false" aria-controls="mibBespoke">Build it yourself</button>
      <p class="mib-bespoke__hint">Want a particular mix of rooms, or a living room of your own? Open the full picker and assemble the stay room by room.</p>

      <div class="mib-bespoke__body" id="mibBespoke" hidden>
        <div class="mib-grid">
          <div>
            <?php foreach ($mibGroups as $grp): ?>
            <div class="mib-group <?= e($grp['class'] ?? '') ?>">
              <div class="mib-group__hd">
                <span class="mib-group__lbl"><?= e($grp['label']) ?></span>
                <?php if (!empty($grp['hint'])): ?><span class="mib-group__hint"><?= e($grp['hint']) ?></span><?php endif; ?>
              </div>
              <div class="mib-rows">
                <?php foreach ($grp['rows'] as $u): ?>
                <div class="mib-row" data-unit="<?= e($u['key']) ?>" data-parts='<?= e(json_encode($u['parts'])) ?>'
                     data-inc="<?= (int)$u['inc'] ?>" data-max="<?= (int)$u['max'] ?>" data-minper="<?= (int)$u['minper'] ?>">
                  <div>
                    <div class="mib-row__name"><?= e($u['key']) ?></div>
                    <div class="mib-row__note"><?= e($u['note']) ?></div>
                    <div class="mib-row__rate" data-rate="<?= (float)$u['rate'] ?>">from $<?= number_format((float)$u['rate']) ?> / night</div>
                  </div>
                  <div class="mib-ctl">
                    <span class="mib-ctl__lbl">Rooms</span>
                    <div class="mib-step" data-step="qty">
                      <button type="button" data-dir="-1" aria-label="Fewer">−</button>
                      <span class="mib-step__n mib-qty">0</span>
                      <button type="button" data-dir="1" aria-label="More">+</button>
                    </div>
                  </div>
                  <div class="mib-ctl">
                    <span class="mib-ctl__lbl">Guests</span>
                    <div class="mib-step" data-step="g">
                      <button type="button" data-dir="-1" aria-label="Fewer">−</button>
                      <span class="mib-step__n mib-g">0</span>
                      <button type="button" data-dir="1" aria-label="More">+</button>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <div class="mib-extra">
              <div class="mib-living">
                <span class="mib-living__txt">Living Room + Kitchen <span style="color:var(--teal,#1E5C6B)">($<?= number_format((float)$mibRates['living']) ?>/night)</span></span>
                <div class="mib-step" id="mibLivingStep">
                  <button type="button" data-dir="-1" aria-label="Fewer living rooms">−</button>
                  <span class="mib-step__n" id="mibLivingN">0</span>
                  <button type="button" data-dir="1" aria-label="More living rooms">+</button>
                </div>
                <span class="mib-living__hint" id="mibLivingHint"></span>
              </div>
              <label>Nights <input type="number" id="mibNights" min="1" value="3"></label>
            </div>
          </div>

          <aside class="mib-summary">
            <div class="mib-sum-top">
              <div class="mib-sum-lbl">Estimated total</div>
              <div class="mib-sum-total" id="mibTotal">$0</div>
              <div class="mib-sum-per" id="mibPer">Add rooms to price your stay</div>
            </div>
            <div class="mib-lines" id="mibLines"></div>
            <div class="mib-notice" id="mibNotice">Choose your rooms and guests.</div>
            <button class="mib-cta" id="mibRequest" disabled>Request to book</button>
            <div class="mib-fine">Prices in USD. The property confirms availability and holds your dates — you are not charged now. Group discounts apply automatically for larger parties (min <?= (int)$mibRules['minNights'] ?> nights).</div>
          </aside>
        </div>
      </div>
    </div>
  </section>

  <!-- Enquiry modal -->
  <div class="mib-modal" id="mibModal">
    <div class="mib-card">
      <h3>Request your Maya Ilai stay</h3>
      <p class="sub" id="mibSummaryText"></p>
      <form id="mibForm">
        <div class="mib-field"><label>Name</label><input name="name" required></div>
        <div class="mib-field"><label>Email</label><input name="email" type="email" required></div>
        <div class="mib-field"><label>Phone</label><input name="phone"></div>
        <div class="mib-field"><label>Dates / notes (optional)</label><textarea name="note" rows="2" placeholder="Preferred dates, questions…"></textarea></div>
        <input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="mib-modal-actions">
          <button type="submit" class="mib-cta" style="width:auto;margin:0;flex:1">Send request</button>
          <button type="button" class="mib-cta" id="mibCancel" style="width:auto;margin:0;background:#eee;color:#333">Cancel</button>
        </div>
        <div class="mib-msg" id="mibMsg"></div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('mibRoot');
  if (!root) return;
  var endpoint = root.dataset.endpoint, contact = root.dataset.contact;
  var rules = JSON.parse(root.dataset.rules);
  var maxGuests = parseInt(root.dataset.maxguests, 10) || 96;
  var usd = function (n) { return '$' + Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var rows = Array.prototype.slice.call(root.querySelectorAll('.mib-row'));
  var state = {}; // unit -> {qty, g, parts, inc, max, minper}
  rows.forEach(function (r) {
    state[r.dataset.unit] = {
      qty: 0, g: 0,
      parts:  JSON.parse(r.dataset.parts),
      inc:    parseInt(r.dataset.inc, 10) || 1,
      max:    parseInt(r.dataset.max, 10) || 1,
      minper: parseInt(r.dataset.minper, 10) || 1
    };
  });
  var livingQty = 0, nights = 3, lastQuote = null, timer = null;

  /**
   * Split a row's guests across the primitives it expands to, mirroring
   * maya_ilai_split_guests(): one guest per bedroom first (the tool rejects an
   * empty selected room), then doubles to 2, then the remainder into the bunk
   * rooms. Single-primitive rows just hand their guests to their own bucket, so
   * the four original rows post exactly what they always did.
   */
  function allocate(parts, guests) {
    var a = { double: 0, bunk: 0, studio: 0, villa: 0 };
    if (parts.studio) { a.studio = guests; return a; }
    if (parts.villa)  { a.villa  = guests; return a; }
    var d = parts.double || 0, b = parts.bunk || 0, left = Math.max(0, guests), take;
    a.double = Math.min(d, left); left -= a.double;               // one per double
    a.bunk   = Math.min(b, left); left -= a.bunk;                 // one per bunk room
    take = Math.min(d * 2 - a.double, left); a.double += take; left -= take;   // doubles to 2
    a.bunk += Math.min(b * rules.bunkMax - a.bunk, left);         // remainder into bunks
    return a;
  }

  /**
   * Every row's primitive totals — the one place a selection becomes primitives.
   *
   * Also reports the combination context (how many villas the combinations
   * occupy, and which bedrooms came from them), because the living-room
   * allowance cannot be derived from the totals alone: 2× One-Bedroom Suite and
   * 2 loose doubles are the same primitives but a different number of villas.
   */
  function expand() {
    var p = { qtyDouble: 0, qtyBunk: 0, qtyStudio: 0, qtyVilla: 0, qtyLiving: 0,
              guestDouble: 0, guestBunk: 0, guestStudio: 0, guestVilla: 0,
              comboUnits: 0, comboDouble: 0, comboBunk: 0 };
    rows.forEach(function (r) {
      var s = state[r.dataset.unit];
      if (!s.qty) return;
      var pooled = {}, k, n = 0;
      for (k in s.parts) { pooled[k] = s.parts[k] * s.qty; n++; }
      p.qtyDouble += pooled.double || 0; p.qtyBunk   += pooled.bunk   || 0;
      p.qtyStudio += pooled.studio || 0; p.qtyVilla  += pooled.villa  || 0;
      p.qtyLiving += pooled.living || 0;
      var a = allocate(pooled, s.g);
      p.guestDouble += a.double; p.guestBunk += a.bunk;
      p.guestStudio += a.studio; p.guestVilla += a.villa;
      if (n > 1) {   // a multi-part row is a combination; each unit is one villa
        p.comboUnits  += s.qty;
        p.comboDouble += pooled.double || 0;
        p.comboBunk   += pooled.bunk   || 0;
      }
    });
    return p;
  }

  /**
   * How many MORE standalone living rooms the selection may take. A villa has
   * one living room and you cannot rent one in a villa you have no bedroom in,
   * so the allowance comes from the bedrooms (whole villas excluded — they
   * already include theirs) minus the ones combinations already consume.
   *
   * Mirrors maya_ilai_quote()'s invariant exactly: each combination unit occupies
   * its own villa and so lends one living room, while LOOSE doubles and bunks pack
   * together and lend one between them per villa.
   *
   * The combination bedrooms are subtracted from the packing term because they are
   * already accounted for by their own unit — counting them twice would let a guest
   * build a selection the server then rejects. api/maya-ilai-quote.php forwards
   * comboUnits/comboDouble/comboBunk so both sides compute this identically; the
   * server remains authoritative, since the endpoint is public.
   */
  /**
   * How many living rooms an expanded selection is entitled to. ONE definition,
   * used by both the standalone stepper and the per-row caps — the strict and
   * relaxed forms were briefly duplicated here, and the copy that was missed
   * silently stopped a second One-Bedroom Suite from being sellable.
   */
  function livingAllowance(p) {
    var looseDouble = Math.max(0, p.qtyDouble - p.comboDouble);
    var looseBunk   = Math.max(0, p.qtyBunk   - p.comboBunk);
    return p.comboUnits
      + Math.max(Math.ceil(looseDouble / rules.doublePerVilla), looseBunk);
  }

  function livingCap() {
    var p = expand();
    return Math.max(0, livingAllowance(p) - p.qtyLiving);
  }

  /**
   * Cap the rows that BRING a living room with them, so the guest cannot build a
   * selection the server would reject. Mirrors maya_ilai_quote()'s invariant over
   * the combination-supplied living rooms; the standalone stepper is clamped
   * separately by syncLiving(), which is why a suite can absorb one you had
   * added by hand — it comes with its own.
   *
   * It asks "would one MORE of this row still be valid?" rather than "is there
   * spare allowance right now" — adding a suite brings its own villa as well as
   * its own living room, so the two cancel and a second suite must stay offerable.
   */
  function syncRowCaps() {
    rows.forEach(function (r) {
      var s = state[r.dataset.unit];
      if (!s.parts.living) return;
      var plus = r.querySelector('.mib-step[data-step="qty"] button[data-dir="1"]');
      var keep = s.qty;
      s.qty = keep + 1;
      var p = expand();
      s.qty = keep;
      plus.disabled = p.qtyLiving > livingAllowance(p);
    });
  }

  function syncLiving() {
    syncRowCaps();
    var p = expand();
    var free = livingCap();                 // allowance not already used by a combination
    if (livingQty > free) livingQty = free;
    var remaining = free - livingQty;

    document.getElementById('mibLivingN').textContent = livingQty;
    var btns = document.getElementById('mibLivingStep').querySelectorAll('button');
    btns[0].disabled = livingQty <= 0;
    btns[1].disabled = remaining <= 0;
    document.getElementById('mibLivingHint').textContent =
      (!p.qtyDouble && !p.qtyBunk) ? 'Add a villa bedroom first — a living room comes with one.'
      : remaining                  ? 'One per villa · ' + remaining + ' more available'
                                   : 'One per villa · all your villas have one';
  }

  function payload() {
    var p = expand();
    p.qtyLiving += livingQty;
    p.nights = nights;
    return p;
  }

  function render(row) {
    var u = row.dataset.unit, s = state[u];
    row.querySelector('.mib-qty').textContent = s.qty;
    row.querySelector('.mib-g').textContent = s.g;
  }

  function quote() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload()) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) { lastQuote = d.quote; paint(d.quote); } })
        .catch(function () {});
    }, 120);
  }

  function priceSpan(n) {
    if (typeof window.tsPriceSpan === 'function') return window.tsPriceSpan(n, 'USD');
    return usd(n);
  }

  function paint(q) {
    var totalEl = document.getElementById('mibTotal'), perEl = document.getElementById('mibPer');
    var linesEl = document.getElementById('mibLines'), noticeEl = document.getElementById('mibNotice'), cta = document.getElementById('mibRequest');
    var hasErr = q.errors && q.errors.length;
    totalEl.innerHTML = q.guests ? priceSpan(q.total) : '$0';
    perEl.textContent = q.guests && !hasErr ? (priceSpanText(q.total / q.guests / q.nights) + ' per guest / night') : 'Add rooms to price your stay';
    var disc = q.base * q.adjustment / 100;
    linesEl.innerHTML =
      row('Accommodation / night', priceSpan(q.base)) +
      (q.adjustment ? row(q.adjustmentLabel + ' (' + q.adjustment + '%)', priceSpan(disc)) : '') +
      (q.supplements ? row('Extra-guest charges / night', priceSpan(q.supplements)) : '') +
      row(q.nights + ' night' + (q.nights === 1 ? '' : 's'), priceSpan(q.nightly * q.nights)) +
      row('Eco-Resort Fee · ' + q.guests + ' guest' + (q.guests === 1 ? '' : 's'), priceSpan(q.eco)) +
      row('<strong>Estimated total</strong>', '<strong>' + priceSpan(q.total) + '</strong>', true);
    noticeEl.className = 'mib-notice' + (hasErr ? ' err' : '');
    noticeEl.innerHTML = hasErr ? q.errors.join('<br>') : ('Fits the compound · ' + q.guests + ' guest' + (q.guests === 1 ? '' : 's') + ', capacity ' + q.capacity + '.');
    cta.disabled = hasErr || !q.guests;
  }
  function priceSpanText(n) { return usd(n); }
  function row(a, b, tot) { return '<div class="mib-line' + (tot ? ' mib-line--total' : '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; }

  // Steppers
  rows.forEach(function (r) {
    var u = r.dataset.unit;
    r.querySelectorAll('.mib-step').forEach(function (step) {
      var kind = step.dataset.step;
      step.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
          var dir = parseInt(b.dataset.dir, 10), s = state[u];
          if (kind === 'qty') {
            s.qty = Math.max(0, s.qty + dir);
            // Default guests to the included capacity when rooms change.
            s.g = s.qty === 0 ? 0 : Math.max(s.qty * s.minper, Math.min(s.qty * s.max, s.inc * s.qty));
          } else {
            var lo = s.qty * s.minper, hi = s.qty * s.max;
            s.g = Math.min(hi, Math.max(lo, s.g + dir));
          }
          render(r); syncLiving(); quote();
        });
      });
    });
  });
  document.getElementById('mibLivingStep').querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () {
      livingQty = Math.max(0, Math.min(livingCap(), livingQty + parseInt(b.dataset.dir, 10)));
      syncLiving(); quote();
    });
  });
  document.getElementById('mibNights').addEventListener('input', function () { nights = Math.max(1, parseInt(this.value, 10) || 1); quote(); });

  /* ── Step 1 → Step 2: the configuration search ───────────────────────────
   *
   * The guest answers party + nights; the server returns the handful of
   * configurations that actually sleep them, already priced and already proved
   * bookable (every suggestion is quoted error-free before it is returned).
   */
  var step1 = document.getElementById('mibStep1'), step2 = document.getElementById('mibStep2');
  var offersEl = document.getElementById('mibOffers'), recapEl = document.getElementById('mibRecap');
  var party = { guests: 2, nights: 3 };
  var offers = [];            // the suggestions currently on screen
  var searchTimer = null;
  // Stale-response guard, the same shape as quote()'s debounce above but with a
  // sequence token as well: the debounce stops a burst of requests, the token
  // stops a slow EARLIER reply from painting over a newer one when the guest
  // steps back and changes the party size.
  var searchSeq = 0;

  function paintParty() {
    document.getElementById('mibPGuestsN').textContent = party.guests;
    document.getElementById('mibPNightsN').textContent = party.nights;
    var gb = document.getElementById('mibPGuests').querySelectorAll('button');
    gb[0].disabled = party.guests <= 1;
    gb[1].disabled = party.guests >= maxGuests;
    var nb = document.getElementById('mibPNights').querySelectorAll('button');
    nb[0].disabled = party.nights <= 1;
    nb[1].disabled = party.nights >= 30;
  }

  function bigStep(id, key, lo, hi) {
    document.getElementById(id).querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () {
        party[key] = Math.max(lo, Math.min(hi, party[key] + parseInt(b.dataset.dir, 10)));
        paintParty();
      });
    });
  }
  bigStep('mibPGuests', 'guests', 1, maxGuests);
  bigStep('mibPNights', 'nights', 1, 30);

  function offerCard(o, i) {
    var q = o.quote;
    var inc = o.units.map(function (u) {
      return '<li><span class="mib-off__u">' + (u.qty > 1 ? u.qty + ' × ' : '') + esc(u.key)
           + '<span class="mib-off__ud">' + esc(u.desc) + '</span></span>'
           + '<span class="mib-off__ug">' + u.guests + ' guest' + (u.guests === 1 ? '' : 's') + '</span></li>';
    }).join('');
    return '<article class="mib-off' + (i === 0 ? ' mib-off--top' : '') + '" data-offer="' + i + '">'
      + (i === 0 ? '<div class="mib-off__tag">Best price</div>' : '')
      + '<div><h4 class="mib-off__name">' + esc(o.label) + '</h4>'
      + '<p class="mib-off__meta">Sleeps ' + q.guests + ' · ' + q.nights + ' night' + (q.nights === 1 ? '' : 's')
      + (q.capacity > q.guests ? ' · room for ' + q.capacity : '') + '</p></div>'
      + '<div class="mib-off__money"><div class="mib-off__total">' + priceSpan(q.total) + '</div>'
      + '<div class="mib-off__per">' + priceSpan(q.nightly) + ' / night'
      + (q.eco ? ' + ' + priceSpan(q.eco) + ' eco fee' : '') + '</div>'
      + (q.adjustment < 0 ? '<div class="mib-off__disc">' + esc(q.adjustmentLabel) + ' ' + q.adjustment + '%</div>' : '')
      + '</div>'
      + '<ul class="mib-off__inc">' + inc + '</ul>'
      + '<button type="button" class="mib-cta mib-off__cta">Request this stay</button>'
      + '</article>';
  }

  function search() {
    clearTimeout(searchTimer);
    var seq = ++searchSeq;
    recapEl.textContent = party.guests + ' guest' + (party.guests === 1 ? '' : 's') + ' · '
                        + party.nights + ' night' + (party.nights === 1 ? '' : 's');
    offersEl.innerHTML = '<div class="mib-offers__msg">Finding what fits…</div>';
    searchTimer = setTimeout(function () {
      fetch(endpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode: 'suggest', guests: party.guests, nights: party.nights, limit: 5 })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (seq !== searchSeq) return;                      // a newer search has already run
          if (!d || !d.ok) {
            offersEl.innerHTML = '<div class="mib-offers__msg">' + esc((d && d.error) || 'Could not price that stay.') + '</div>';
            offers = []; return;
          }
          offers = d.suggestions || [];
          if (!offers.length) {
            offersEl.innerHTML = '<div class="mib-offers__msg">We can\'t sleep a party that size in one stay — '
              + 'open the full picker below, or send us a note and we\'ll work it out with you.</div>';
            return;
          }
          offersEl.innerHTML = offers.map(offerCard).join('');
        })
        .catch(function () {
          if (seq !== searchSeq) return;
          offersEl.innerHTML = '<div class="mib-offers__msg">Network error — please try again.</div>';
          offers = [];
        });
    }, 120);
  }

  document.getElementById('mibPGo').addEventListener('click', function () {
    step1.hidden = true; step2.hidden = false;
    nights = party.nights;
    document.getElementById('mibNights').value = party.nights;
    search();
    step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  document.getElementById('mibBack').addEventListener('click', function () {
    searchSeq++;                      // abandon whatever is in flight
    step2.hidden = true; step1.hidden = false;
    step1.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // Tapping an offer requests THAT stay — its own quote, not the picker's.
  offersEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.mib-off__cta');
    if (!btn) return;
    var card = btn.closest('.mib-off');
    var o = offers[parseInt(card.dataset.offer, 10)];
    if (!o) return;
    openModal(o.quote, o.label + ' · ' + o.units.map(function (u) {
      return (u.qty > 1 ? u.qty + '× ' : '') + u.key + ' (' + u.guests + ' guests)';
    }).join(', ') + ' · ' + o.quote.nights + ' nights · ' + usd(o.quote.total) + ' total');
  });

  // "Build it yourself" — the full picker, unchanged, for a bespoke mix.
  var bespoke = document.getElementById('mibBespoke'), bespokeBtn = document.getElementById('mibBespokeBtn');
  bespokeBtn.addEventListener('click', function () {
    var open = bespoke.hidden;
    bespoke.hidden = !open;
    bespokeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    bespokeBtn.textContent = open ? 'Hide the full picker' : 'Build it yourself';
    if (open) { syncLiving(); quote(); bespoke.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  });

  // Enquiry modal — shared by both steps. modalQuote is what the guest is
  // actually requesting; the picker's own live quote keeps updating in
  // lastQuote behind it and must not overwrite what the modal is showing.
  var modal = document.getElementById('mibModal');
  var modalQuote = null, modalSummary = '';
  function openModal(q, summary) {
    modalQuote = q; modalSummary = summary;
    document.getElementById('mibSummaryText').textContent = summary;
    document.getElementById('mibMsg').className = 'mib-msg';
    modal.classList.add('open');
  }
  document.getElementById('mibRequest').addEventListener('click', function () {
    if (!lastQuote || lastQuote.errors.length || !lastQuote.guests) return;
    openModal(lastQuote, summaryText(lastQuote));
  });
  document.getElementById('mibCancel').addEventListener('click', function () { modal.classList.remove('open'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });

  function summaryText(q) {
    var parts = [];
    rows.forEach(function (r) {
      var s = state[r.dataset.unit];
      if (s.qty) parts.push(s.qty + '× ' + r.dataset.unit + ' (' + s.g + ' guests)');
    });
    if (livingQty) parts.push(livingQty + '× Living Room + Kitchen');
    return parts.join(', ') + ' · ' + q.nights + ' nights · ' + usd(q.total) + ' total';
  }

  document.getElementById('mibForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, msg = document.getElementById('mibMsg');
    if (f.website.value) { modal.classList.remove('open'); return; } // honeypot
    var q = modalQuote;
    if (!q) return;
    var message = 'Maya Ilai booking request:\n' + modalSummary +
      '\nAccommodation/night: ' + usd(q.nightly) + ' · Eco fee: ' + usd(q.eco) + ' · Estimated total: ' + usd(q.total) +
      (f.note.value.trim() ? ('\n\nGuest note: ' + f.note.value.trim()) : '');
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
    fetch(contact, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: f.name.value, email: f.email.value, phone: f.phone.value,
        subject: 'Maya Ilai booking request', message: message,
        quoted_total: q.total, quoted_currency: 'USD',
        'cf-turnstile-response': ''
      })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j && res.j.ok) {
          msg.className = 'mib-msg ok show'; msg.textContent = 'Request sent — the property will confirm availability by email shortly.';
          setTimeout(function () { modal.classList.remove('open'); }, 2200);
        } else { msg.className = 'mib-msg bad show'; msg.textContent = (res.j && res.j.error) || 'Could not send. Please try again.'; }
      })
      .catch(function () { msg.className = 'mib-msg bad show'; msg.textContent = 'Network error. Please try again.'; })
      .then(function () { btn.disabled = false; });
  });

  paintParty();
  syncLiving();
})();
</script>
