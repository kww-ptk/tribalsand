<?php
/**
 * Guest-facing Maya Ilai booking configurator (full pricing parity).
 *
 * A POPUP, not a page section. Inline it dominated the property page; the page
 * now carries a short invitation and anything marked [data-mib-open] opens the
 * flow over it. This partial renders ONLY the popup (plus its styles and its
 * controller) — the trigger copy lives on the page.
 *
 * THREE STEPS, in one popup, in this order:
 *   1. How many of you, and when. Party size and a real check-in/check-out
 *      range — nights are DERIVED from the dates, never typed.
 *   2. The handful of real configurations that sleep that party, cheapest
 *      first, each with what it includes and its total — plus "Build it
 *      yourself" for anyone who wants to assemble something bespoke.
 *   3. The request form.
 *
 * Step 3 is a STEP, not a second modal. A dialog inside a dialog means two
 * scroll locks, two focus traps and two Escape handlers racing each other; and
 * the form is a continuation of the same flow, so it wants a Back to step 2
 * rather than a separate lifecycle. One popup owns the lock, the trap and the
 * Escape key; the only other layer on screen is the shared datepicker's pop,
 * which lives on <body> at z-index 9999 and is deferred to explicitly (Escape
 * and a backdrop click close the calendar first, the popup second).
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
 * THE DATES ARE NOT CHECKED AGAINST AVAILABILITY. There is no inventory engine
 * behind this flow — a guest can pick a week the compound is full and still be
 * priced. That is why every surface says the property confirms availability, and
 * why the result is a REQUEST (enquiry), not a booking. Do not let the copy
 * imply otherwise until something actually checks.
 *
 * Dates use the shared styled picker (js/datepicker.js + the .dp-btn/.dp-pop
 * rules in css/booking.css) — never a native date input. Set nothing before
 * including.
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
    // NO bare Bunk Room row, on either surface. A guest can never select one on
    // its own — it comes inside the Two-Bedroom Family Room, the Two-Bedroom
    // Family Suite and the Three-Bedroom Villa, which are still here and still
    // priced through the same primitives. The server stays permissive (a posted
    // qtyBunk still prices correctly); this is a merchandising rule, not a
    // validation one.
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

// The date range needs the shared picker. A page that already declares
// $page_booking / $page_rooms_rates has it from includes/head.php together with
// css/booking.css, which carries the public .dp-btn / .dp-pop styling — emitting
// it again would double-load the script and, if datepicker.css came with it,
// fight booking.css over the trigger's look. maya_ilai.php is such a page.
// Anywhere else, the partial loads the script itself so it stays self-contained.
$mibNeedsDp = empty($GLOBALS['page_booking']) && empty($GLOBALS['page_rooms_rates']);
// The longest stay api/maya-ilai-quote.php will price. The UI must not let a
// guest build a range the endpoint then rejects.
$mibMaxNights = 30;
?>
<?php if ($mibNeedsDp): ?>
<link rel="stylesheet" href="/css/datepicker.css?v=<?= @filemtime(__DIR__ . '/../css/datepicker.css') ?: '1' ?>">
<script src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>" defer></script>
<?php endif; ?>
<style>
  /* Both selectors carry the tokens: the popup is moved to <body> on init, so
     it stops inheriting anything from .mib and must stand on its own. */
  .mib,.mib-pop{--mib-line:rgba(184,150,90,.22);--mib-ink:#141412;--mib-mut:#6B6050;font-family:'Jost',sans-serif;color:var(--mib-ink)}

  /* ── The popup shell ─────────────────────────────────────────────────────
     One dialog for all three steps. The bar is fixed and the BODY scrolls, so
     a tall step scrolls inside the popup instead of growing it past the
     viewport. .mib-locked is put on <html> as well as <body>: this page sets
     html{overflow-x:hidden}, which stops body's overflow propagating to the
     viewport, so locking body alone would leave the page behind scrolling. */
  /* Above the fixed site nav (9000) and the cookie banner (9500) — the same
     shelf the photo lightbox uses — but BELOW the datepicker's pop (9999),
     which has to open over this. */
  .mib-pop{position:fixed;inset:0;z-index:9600;display:none;font-family:'Jost',sans-serif}
  .mib-pop.open{display:block}
  .mib-pop__scrim{position:absolute;inset:0;background:rgba(16,47,58,.62)}
  .mib-pop__dialog{position:relative;width:min(980px,calc(100% - 2rem));max-height:92vh;margin:4vh auto;
    display:flex;flex-direction:column;background:var(--off,#FAF8F4);
    box-shadow:0 30px 80px rgba(0,0,0,.4);outline:none}
  .mib-pop__bar{flex:0 0 auto;display:flex;align-items:flex-start;gap:1rem;padding:1.05rem 1.4rem;
    background:var(--teal-d,#102F3A);color:#fff}
  .mib-pop__eyebrow{font-size:.58rem;letter-spacing:.24em;text-transform:uppercase;color:rgba(184,150,90,.85)}
  .mib-pop__title{font-family:'Cormorant Garamond',serif;font-size:1.45rem;font-weight:400;line-height:1.15;margin:.15rem 0 0;color:#fff}
  .mib-pop__x{margin-left:auto;flex:0 0 auto;width:38px;height:38px;border:1px solid rgba(255,255,255,.28);
    background:none;color:#fff;font-size:1.5rem;line-height:1;cursor:pointer;border-radius:50%;font-family:inherit}
  .mib-pop__x:hover{background:rgba(255,255,255,.14)}
  .mib-pop__body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:1.6rem 1.4rem 2rem}
  html.mib-locked,body.mib-locked{overflow:hidden}
  /* ── The third-party chat bubble stands down while this popup is open ─────
     The LeadConnector widget (includes/footer.php) is fixed bottom-right and
     owns that corner BY CONVENTION — which is why no WhatsApp float was ever
     added beside it. That convention is about the PAGE. At 375×812 the widget's
     greeting card covers a 296×136 slab of the bottom of the viewport, and
     inside this dialog that slab lands on the datepicker's Done button and its
     last row of dates, on an offer card's "Request this stay", and on the top
     of the picker. A guest who cannot confirm a date is not making a trade-off
     with a support channel, they are stuck.

     So: hidden for the life of the popup, and only the popup. Three properties
     make this safe to leave alone —
       - It hangs off .mib-locked, the class openPop()/closePop() already put on
         <html>, so EVERY close path restores it — the ×, Escape, the backdrop
         and a sent request all run closePop() and nothing else has to remember.
       - It is CSS, matching a custom element by name. The widget's own scripts
         and DOM are untouched; the node is never removed, so there is nothing
         to re-add and nothing to race. `display:none` on the host takes its
         shadow tree out of hit-testing with it, and the widget re-hydrates
         intact when the rule stops matching.
       - A deploy where the widget never loads (it is third-party, and the
         footer can suppress it outright) simply matches no element. */
  html.mib-locked chat-widget{display:none!important}
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

  /* Dates. The styled .dp-btn comes from css/booking.css — never a native
     date input (house rule), and never a second nights control: the nights
     readout below is computed from the range. */
  .mib-dates{display:flex;flex-wrap:wrap;gap:1rem;margin:1.5rem auto 0;max-width:430px;text-align:left}
  .mib-date{flex:1 1 180px;min-width:0}
  .mib-date .mib-field-big__lbl{margin-bottom:.35rem}
  .mib-date .dp-btn{width:100%;font-family:inherit}
  .mib-derived{margin:1.1rem 0 0;font-size:.86rem;color:var(--mib-mut)}
  .mib-derived strong{color:var(--mib-ink);font-weight:500}
  .mib-alert{margin:.9rem auto 0;max-width:430px;padding:.55rem .75rem;font-size:.78rem;line-height:1.5;
    text-align:left;background:rgba(200,80,60,.06);color:#9B3B2A;border:1px solid rgba(200,80,60,.2)}
  .mib-alert[hidden]{display:none}
  /* The min-nights note. Not an error — the stay prices fine, it just does not
     earn the group discount, which the fine print promises "automatically". */
  .mib-minnote{margin:.9rem auto 0;max-width:430px;padding:.55rem .75rem;font-size:.78rem;line-height:1.5;
    text-align:left;background:var(--sand-faint,#FAF6EE);color:#7a5a1e;border:1px solid var(--mib-line)}
  .mib-minnote[hidden]{display:none}
  .mib-stay{font-size:.85rem;color:var(--mib-mut)}
  .mib-stay strong{color:var(--mib-ink);font-weight:500}
  .mib-stay__d{display:block;font-size:.72rem;opacity:.8}

  /* ── Step 2 — what fits ─────────────────────────────────────────────────── */
  .mib-recap{display:flex;align-items:baseline;gap:.8rem;flex-wrap:wrap;border-bottom:1px solid var(--mib-line);padding-bottom:.9rem;margin-bottom:1.4rem}
  .mib-recap__t{font-family:'Cormorant Garamond',serif;font-size:1.5rem;line-height:1.1}
  .mib-recap__back{margin-left:auto;background:none;border:none;padding:.2rem 0;font-family:inherit;font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:var(--teal,#1E5C6B);cursor:pointer;border-bottom:1px solid currentColor}
  .mib-offers__lede{font-size:.84rem;color:var(--mib-mut);margin:0 0 .9rem;max-width:46ch}
  .mib-offers{display:flex;flex-direction:column;gap:1rem}
  /* The card is the stay's details, and — when there is one — a photograph of
     that configuration beside them. The DETAILS grid (name beside price,
     everything else spanning) moved down one level into .mib-off__body, so
     every 1/-1 span below still means "the full width of the details" and
     nothing about those rows changed. */
  .mib-off{border:1px solid var(--mib-line);background:#fff;padding:1.2rem 1.3rem;display:grid;grid-template-columns:minmax(0,1fr);gap:1.2rem;align-items:start}
  .mib-off__body{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem 1.6rem;align-items:start}
  /* ONLY a card that actually has a photo becomes two columns. Without one the
     card is the single column it has always been — no reserved gutter, no grey
     placeholder standing in for a picture nobody has uploaded yet.
     Equal halves: the photograph is half the argument for a stay, and a 15rem
     cap left it a thumbnail beside a column of text three times its width. */
  .mib-off--photo{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  .mib-off__fig{margin:0;min-width:0}
  /* A fixed ratio on the FRAME, reserved before any file arrives, so the list
     does not jump as the photographs load in — and does not jump again as the
     guest slides between them. The slides stack inside it absolutely, so one
     photograph and six occupy exactly the same box. */
  .mib-slides{position:relative;aspect-ratio:4/3;overflow:hidden;background:var(--sand-faint,#FAF6EE)}
  .mib-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .28s ease}
  .mib-slide.on{opacity:1;visibility:visible}
  .mib-slide img{display:block;width:100%;height:100%;object-fit:cover}
  @media(prefers-reduced-motion:reduce){.mib-slide{transition:none}}
  /* The chrome. It exists ONLY on a figure with more than one photograph —
     `.mib-slides--one` is how a single image keeps rendering exactly as it did,
     with nothing laid over it. */
  .mib-slides--one .mib-slide__nav,.mib-slides--one .mib-slide__count{display:none}
  .mib-slide__nav{position:absolute;top:50%;transform:translateY(-50%);width:34px;height:34px;
    display:flex;align-items:center;justify-content:center;padding:0;border:none;border-radius:50%;
    background:rgba(16,47,58,.55);color:#fff;font:400 1.3rem/1 'Jost',sans-serif;cursor:pointer;
    -webkit-tap-highlight-color:transparent}
  .mib-slide__nav:hover{background:rgba(16,47,58,.82)}
  .mib-slide__nav:focus-visible{outline:2px solid var(--sand-lt,#D4B07A);outline-offset:2px}
  .mib-slide__nav--prev{left:.5rem}
  .mib-slide__nav--next{right:.5rem}
  .mib-slide__count{position:absolute;right:.5rem;bottom:.5rem;margin:0;padding:.12rem .45rem;
    background:rgba(16,47,58,.55);color:#fff;font-size:.66rem;letter-spacing:.08em;
    font-variant-numeric:tabular-nums;pointer-events:none}
  /* Narrow: the photo goes ABOVE the details, full width and wider-cropped. A
     thumbnail squeezed beside a price is worse than no photograph at all. */
  @media(max-width:700px){
    .mib-off--photo{grid-template-columns:minmax(0,1fr)}
    .mib-off__fig{order:-1}
    .mib-slides{aspect-ratio:16/9}
  }
  .mib-off--top{border-color:var(--sand,#B8965A);box-shadow:0 6px 26px rgba(184,150,90,.16)}
  /* The cheapest stay is never the lead, so it gets its own quieter accent —
     visible at a skim, not competing with the property's pick. */
  .mib-off--cheap{border-left:3px solid var(--teal,#1E5C6B)}
  .mib-off__tag{grid-column:1/-1;font-size:.58rem;letter-spacing:.22em;text-transform:uppercase;color:var(--sand-dk,#8A6D33)}
  .mib-off--cheap .mib-off__tag{color:var(--teal,#1E5C6B)}
  .mib-off__why{grid-column:1/-1;margin:-.25rem 0 0;font-size:.8rem;line-height:1.5;color:var(--mib-mut)}
  .mib-off__name{font-family:'Cormorant Garamond',serif;font-size:1.5rem;line-height:1.15;font-weight:400;margin:0}
  .mib-off__meta{font-size:.78rem;color:var(--mib-mut);margin:.2rem 0 0}
  .mib-off__money{text-align:right;white-space:nowrap}
  .mib-off__total{font-family:'Cormorant Garamond',serif;font-size:1.75rem;line-height:1}
  .mib-off__per{font-size:.74rem;color:var(--mib-mut);margin-top:.15rem}
  /* Fine print, deliberately quieter than the rate above it: the fee is part of
     the total but not part of the nightly rate, and the type should say so. */
  .mib-off__disc{display:inline-block;margin-top:.3rem;font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:#2D7A5F}
  .mib-off__inc{grid-column:1/-1;list-style:none;margin:.5rem 0 0;padding:.7rem 0 0;border-top:1px solid var(--mib-line);display:flex;flex-direction:column;gap:.3rem}
  .mib-off__inc li{display:flex;justify-content:space-between;gap:1rem;font-size:.82rem;color:var(--mib-mut)}
  .mib-off__u{color:var(--mib-ink)}
  .mib-off__ud{display:block;font-size:.72rem;opacity:.75}
  .mib-off__cta{grid-column:1/-1;width:100%;margin:.9rem 0 0}
  .mib-offers__msg{padding:1rem 1.1rem;border:1px solid var(--mib-line);background:var(--sand-faint,#FAF6EE);font-size:.85rem;color:var(--mib-mut)}
  .mib-avail{padding:.6rem .85rem;margin-bottom:.7rem;border-radius:8px;font-size:.82rem;line-height:1.45;border:1px solid var(--mib-line)}
  .mib-avail--deal{background:#EEF7EE;border-color:#CFE8CF;color:#2F6B36}
  .mib-avail--tight{background:#FBF1E7;border-color:#F0D9BE;color:#8A5A22}
  .mib-avail b{font-weight:600}
  .mib-bespoke{margin-top:1.8rem;border-top:1px solid var(--mib-line);padding-top:1.3rem}
  .mib-bespoke__btn{background:none;border:1px solid var(--mib-line);padding:.75rem 1.1rem;font-family:inherit;font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;color:var(--teal,#1E5C6B);cursor:pointer;width:100%}
  .mib-bespoke__btn:hover{background:var(--sand-faint,#FAF6EE)}
  .mib-bespoke__hint{font-size:.76rem;color:var(--mib-mut);margin:.7rem 0 0;line-height:1.6}
  .mib-bespoke__body{margin-top:1.5rem}

  /* ── Step 3 — the request form ──────────────────────────────────────────
     A step inside the one popup, not a second modal: no nested backdrop, no
     second scroll lock, no second focus trap. */
  .mib-card{background:#fff;max-width:480px;width:100%;margin:0 auto;padding:1.6rem;border:1px solid var(--mib-line)}
  .mib-card h3{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;margin-bottom:.3rem}
  .mib-card p.sub{font-size:.85rem;color:var(--mib-mut);margin-bottom:1.1rem;line-height:1.6}
  .mib-field{margin-bottom:.8rem}
  .mib-req{color:#9B3B2A}
  .mib-req-note{font-size:.7rem;color:var(--mib-mut);margin:-.3rem 0 .8rem}
  .mib-field label{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--mib-mut);display:block;margin-bottom:.3rem}
  .mib-field input,.mib-field textarea{width:100%;padding:.65rem .8rem;border:1px solid var(--mib-line);font-family:inherit;font-size:.9rem}
  .mib-modal-actions{display:flex;gap:.6rem;margin-top:.5rem;flex-wrap:wrap}
  .mib-msg{font-size:.85rem;margin-top:.7rem;display:none}
  .mib-msg.show{display:block}
  .mib-msg.ok{color:#2D7A5F}.mib-msg.bad{color:#9B3B2A}

  /* Inside the popup the grid has the DIALOG's width, not the viewport's, so
     it stacks until the viewport is wide enough for the dialog to hold two
     readable columns. The summary sticks to the popup's own scroll box. */
  .mib-pop .mib-grid{grid-template-columns:1fr}
  .mib-pop .mib-summary{position:static}
  @media(min-width:1040px){
    .mib-pop .mib-grid{grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr)}
    .mib-pop .mib-summary{position:sticky;top:0}
  }
  @media(max-width:820px){.mib-grid{grid-template-columns:1fr}.mib-summary{position:static}}
  /* Phone: full-screen. A centred card with margins wastes the only screen
     space the picker and the offer list have. */
  @media(max-width:640px){
    .mib-pop__dialog{width:100%;max-width:100%;max-height:100%;height:100%;margin:0}
    .mib-pop__body{padding:1.2rem 1rem 2.2rem}
    .mib-pop__bar{padding:.9rem 1rem}
    .mib-pop__title{font-size:1.25rem}
    .mib-card{padding:1.2rem}
  }
  /* Phone: the offer card stacks so the price sits under the name, never squeezed
     beside it, and the row steppers keep their own line rather than crushing the
     room name to one word per line. */
  @media(max-width:540px){
    .mib-party{padding:1.4rem 1.1rem}
    .mib-party__q{font-size:1.6rem}
    .mib-party__fields{gap:1.1rem}
    .mib-off{padding:1.1rem}
    .mib-off__body{grid-template-columns:1fr}
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

    /* ── Thumbs ──────────────────────────────────────────────────────────
       Everything tapped in this popup clears 44×44 on a phone. The big party
       stepper and the .dp-btn triggers already did; these three did not — the
       row steppers at 30, the slider arrows at 34, the close × at 38. Sizes
       only: nothing moves, and each still fits its column (the row stepper is
       the tightest at 121px inside a 137px cell). */
    .mib-pop__x{width:44px;height:44px}
    .mib-step button{width:44px;height:44px;font-size:1.25rem}
    .mib-step{gap:.35rem}
    .mib-slide__nav{width:44px;height:44px;font-size:1.5rem}

    /* The recap was costing a whole extra row. "7 guests · 4 nights · 20 Sept
       2026 → 24 Sept 2026" wraps to two lines at this width, and with the line
       allowed to wrap, margin-left:auto dropped Change onto a third line of its
       own, right-aligned and looking stray. Let the line wrap INSIDE its own
       column instead and Change keeps its place at the top right. */
    .mib-recap{flex-wrap:nowrap;gap:.6rem}
    .mib-recap__t{flex:1 1 auto;min-width:0;font-size:1.2rem}
    .mib-recap__back{flex:0 0 auto;margin-left:0}

    /* The calendar is the shared picker's, drawn on <body>, so this reaches it
       the same way everything else here does — through the class the popup puts
       on <html> while it is open, and only while it is open. Height only: the
       pop's 310px width is a number datepicker.js also clamps against, so
       widening the grid here would push it off the right edge.

       Which is why the square goes rather than a minimum being added to it. A
       day cell is aspect-ratio:1 in a repeat(7,1fr) track: constrain its HEIGHT
       and the ratio resolves the width to match, so min-height:44px silently
       made every cell 44 wide inside a 38px column — cells overlapping their
       neighbours by 6px and the row spilling out of the grid. Dropping the
       ratio and setting the height outright leaves the width where the track
       put it: 38×44 cells, seven of them, still 268px across. */
    html.mib-locked .dp-pop .bk-cell{aspect-ratio:auto;height:44px;min-width:0}
  }
</style>

<div class="mib" id="mibRoot"
     data-endpoint="/api/maya-ilai-quote.php"
     data-contact="/api/submit-contact.php"
     data-maxguests="<?= (int)$mibMaxParty ?>"
     data-rates='<?= e(json_encode($mibRates)) ?>'
     data-rules='<?= e(json_encode(['bunkMax'=>(int)$mibRules['bunkMax'],'doublePerVilla'=>max(1,(int)$mibCfg['inventory']['doublePerVilla']),'minNights'=>(int)$mibRules['minNights']])) ?>'
     data-maxnights="<?= (int)$mibMaxNights ?>">

<!-- ── The popup ──────────────────────────────────────────────────────────
     Relocated to <body> on init, so no transformed ancestor on the property
     page can turn position:fixed into position:absolute under it. -->
<div class="mib-pop" id="mibPop" hidden>
  <div class="mib-pop__scrim" data-mib-close></div>
  <div class="mib-pop__dialog" id="mibDialog" role="dialog" aria-modal="true" aria-labelledby="mibPopTitle" tabindex="-1">
    <div class="mib-pop__bar">
      <div>
        <div class="mib-pop__eyebrow">Maya Ilai · Kilifi</div>
        <h3 class="mib-pop__title" id="mibPopTitle">Build your stay</h3>
      </div>
      <button type="button" class="mib-pop__x" id="mibClose" data-mib-close aria-label="Close">&times;</button>
    </div>
    <div class="mib-pop__body" id="mibPopBody">

  <!-- ── Step 1 ─────────────────────────────────────────────────────────── -->
  <section class="mib-party" id="mibStep1">
    <h3 class="mib-party__q">How many of you are coming?</h3>
    <p class="mib-party__sub">Tell us the party and your dates — we'll show you what fits, with the price.</p>
    <div class="mib-party__fields">
      <div class="mib-field-big">
        <span class="mib-field-big__lbl" id="mibPGuestsLbl">Guests</span>
        <div class="mib-bigstep" id="mibPGuests" aria-labelledby="mibPGuestsLbl">
          <button type="button" data-dir="-1" aria-label="Fewer guests">−</button>
          <span class="mib-bigstep__n" id="mibPGuestsN" aria-live="polite">2</span>
          <button type="button" data-dir="1" aria-label="More guests">+</button>
        </div>
      </div>
    </div>

    <!-- Real dates. Nights are read off the range below, so there is exactly
         one place a stay length can come from. -->
    <div class="mib-dates">
      <div class="mib-date" role="group" aria-labelledby="mibCiLbl">
        <span class="mib-field-big__lbl" id="mibCiLbl">Check-in</span>
        <button type="button" class="dp-btn" id="mibCiBtn"
                data-dp-role="ci" data-dp-pair="mib" data-dp-target="mibCiInput"
                data-dp-placeholder="Select date">Select date</button>
        <input type="hidden" id="mibCiInput" name="check_in">
      </div>
      <div class="mib-date" role="group" aria-labelledby="mibCoLbl">
        <span class="mib-field-big__lbl" id="mibCoLbl">Check-out</span>
        <button type="button" class="dp-btn" id="mibCoBtn"
                data-dp-role="co" data-dp-pair="mib" data-dp-target="mibCoInput"
                data-dp-placeholder="Select date">Select date</button>
        <input type="hidden" id="mibCoInput" name="check_out">
      </div>
    </div>
    <p class="mib-derived" id="mibDerived" aria-live="polite">Pick your dates and we'll count the nights.</p>
    <p class="mib-alert" id="mibDateErr" role="alert" hidden></p>
    <p class="mib-minnote" id="mibMinNote1" hidden></p>

    <button type="button" class="mib-cta mib-party__go" id="mibPGo" disabled>Show what fits</button>
    <p class="mib-party__fine">Prices in USD, for the whole stay. We check these dates against the live calendar, so you only see stays we can actually hold — you are not charged now, and the property confirms and holds your dates when you send your request. Group discounts apply automatically for larger parties (min <?= (int)$mibRules['minNights'] ?> nights).</p>
  </section>

  <!-- ── Step 2 ─────────────────────────────────────────────────────────── -->
  <section id="mibStep2" hidden>
    <div class="mib-recap">
      <span class="mib-recap__t" id="mibRecap">2 guests · 3 nights</span>
      <button type="button" class="mib-recap__back" id="mibBack">Change</button>
    </div>
    <p class="mib-minnote" id="mibMinNote2" hidden></p>

    <!-- Why a short list is a short list. Without a bare bunk room to sell, some
         ordinary party sizes genuinely have only two or three stays that fit them
         (five guests have two). Rather than pad the list with worse-fitting rooms,
         say what the list is and point at the picker. -->
    <p class="mib-offers__lede">These are the stays that fit your party. For a particular mix of rooms, build your own below.</p>

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
              <!-- No nights control here. The stay length is the date range from
                   step 1 and nowhere else — a second input is how a picker and a
                   search end up quoting two different stays. -->
              <div class="mib-stay">
                <strong id="mibStayNights">—</strong>
                <span class="mib-stay__d" id="mibStayDates"></span>
              </div>
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

  <!-- ── Step 3 — the request ───────────────────────────────────────────── -->
  <section id="mibStep3" hidden>
    <div class="mib-recap">
      <!-- Not a second "Request your stay" — the popup's own bar already says
           that. This line asks the question the step is actually for. -->
      <span class="mib-recap__t">Who shall we confirm with?</span>
      <button type="button" class="mib-recap__back" id="mibFormBack">Back</button>
    </div>
    <div class="mib-card">
      <p class="sub" id="mibSummaryText"></p>
      <form id="mibForm">
        <!-- aria-hidden on the asterisk: a screen reader already announces the
             field as required from the `required` attribute, so reading "star"
             as well is noise. The legend below carries the meaning visually. -->
        <div class="mib-field"><label for="mibFName">Name <span class="mib-req" aria-hidden="true">*</span></label><input id="mibFName" name="name" required></div>
        <div class="mib-field"><label for="mibFEmail">Email <span class="mib-req" aria-hidden="true">*</span></label><input id="mibFEmail" name="email" type="email" required></div>
        <div class="mib-field"><label for="mibFPhone">Phone <span class="mib-req" aria-hidden="true">*</span></label><input id="mibFPhone" name="phone" type="tel" required></div>
        <p class="mib-req-note"><span class="mib-req" aria-hidden="true">*</span> Required</p>
        <div class="mib-field"><label for="mibFNote">Anything else? (optional)</label><textarea id="mibFNote" name="note" rows="2" placeholder="Arrival time, questions, special requests…"></textarea></div>
        <input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php if (function_exists('captcha_site_key') && captcha_site_key()): ?>
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:.4rem 0"></div>
        <?php endif; ?>
        <div class="mib-modal-actions">
          <button type="submit" class="mib-cta" style="width:auto;margin:0;flex:1 1 160px">Send request</button>
          <button type="button" class="mib-cta" id="mibCancel" style="width:auto;margin:0;flex:0 1 auto;background:#eee;color:#333">Back</button>
        </div>
        <div class="mib-msg" id="mibMsg"></div>
      </form>
    </div>
  </section>

    </div><!-- /.mib-pop__body -->
  </div><!-- /.mib-pop__dialog -->
</div><!-- /.mib-pop -->
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
  // `nights` is DERIVED from the date range in step 1 and set in one place
  // (syncDates). Nothing types it any more, so the picker and the search can
  // never be quoting two different stay lengths.
  var livingQty = 0, nights = 0, lastQuote = null, timer = null;
  var maxNights = parseInt(root.dataset.maxnights, 10) || 30;
  var minNights = parseInt(rules.minNights, 10) || 0;

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
    p.nights = Math.max(1, nights);   // the picker is only reachable with real dates
    // Send the dates so the server can price on the live availability band; the
    // picker is only reachable once a real range is chosen in step 1.
    if (dates.ci && dates.co) { p.check_in = dates.ci; p.check_out = dates.co; }
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
    totalEl.innerHTML = q.guests ? priceSpan(q.accommodation) : '$0';
    perEl.textContent = q.guests && !hasErr ? (priceSpanText(q.accommodation / q.guests / q.nights) + ' per guest / night') : 'Add rooms to price your stay';
    var disc = q.base * q.adjustment / 100;
    linesEl.innerHTML =
      row('Accommodation / night', priceSpan(q.base)) +
      (q.adjustment ? row(q.adjustmentLabel + ' (' + q.adjustment + '%)', priceSpan(disc)) : '') +
      (q.supplements ? row('Extra-guest charges / night', priceSpan(q.supplements)) : '') +
      row(q.nights + ' night' + (q.nights === 1 ? '' : 's'), priceSpan(q.nightly * q.nights)) +
      // No Eco-Resort Fee row, and the footing is ACCOMMODATION. An itemised
      // breakdown that hides a line but keeps it in the total is worse than
      // either showing or omitting the fee — the visible rows would not add up.
      row('<strong>Estimated total</strong>', '<strong>' + priceSpan(q.accommodation) + '</strong>', true);
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

  /* ── Step 1 → Step 2: the configuration search ───────────────────────────
   *
   * The guest answers party + nights; the server returns the handful of
   * configurations that actually sleep them, already priced and already proved
   * bookable (every suggestion is quoted error-free before it is returned).
   */
  var step1 = document.getElementById('mibStep1'), step2 = document.getElementById('mibStep2'),
      step3 = document.getElementById('mibStep3');
  var offersEl = document.getElementById('mibOffers'), recapEl = document.getElementById('mibRecap');
  var party = { guests: 2 };
  var offers = [];            // the suggestions currently on screen
  var searchTimer = null;
  // Stale-response guard, the same shape as quote()'s debounce above but with a
  // sequence token as well: the debounce stops a burst of requests, the token
  // stops a slow EARLIER reply from painting over a newer one when the guest
  // steps back and changes the party size.
  var searchSeq = 0;

  /* ── Dates ────────────────────────────────────────────────────────────────
   *
   * The shared picker (js/datepicker.js) owns the calendar and writes the two
   * hidden inputs; it blocks past days and any check-out on or before the
   * check-in, so those cannot be posted from the UI at all. What it does NOT do
   * is fire an event when a RANGE lands — only single mode dispatches `change` —
   * so the values are re-read after a click. The calendar is click-driven, so
   * that is the only moment they can move, and the re-read is a string compare.
   *
   * TWO listeners, because one cannot see both cases: the picker's pop calls
   * stopPropagation() on its own clicks (that is how an outside click closes
   * it), so a day cell never reaches document — the listener has to sit ON the
   * pop, after the cell handlers have run. The document listener catches the
   * other way out, dismissing the calendar by clicking away from it.
   *
   * Everything downstream reads `dates.nights`. Nights are never typed.
   */
  var ciInput = document.getElementById('mibCiInput'), coInput = document.getElementById('mibCoInput');
  var derivedEl = document.getElementById('mibDerived'), dateErrEl = document.getElementById('mibDateErr');
  var goBtn = document.getElementById('mibPGo');
  var dates = { ci: '', co: '', nights: 0, error: '' };

  function ymdUtc(s) { var p = s.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
  function isYmd(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s || ''); }
  function todayUtc() { var d = new Date(); return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()); }
  function fmtYmd(s) {
    if (!isYmd(s)) return '';
    return new Date(s + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function nightsWord(n) { return n + ' night' + (n === 1 ? '' : 's'); }

  /** Read the two hidden inputs into `dates`, with the reason it is unusable. */
  function evaluateDates() {
    var ci = (ciInput.value || '').trim(), co = (coInput.value || '').trim();
    var out = { ci: ci, co: co, nights: 0, error: '' };
    if (!isYmd(ci) || !isYmd(co)) {
      out.error = (ci || co) ? 'Choose both a check-in and a check-out date.' : '';
      return out;
    }
    if (ymdUtc(ci) < todayUtc()) { out.error = 'Check-in cannot be in the past.'; return out; }
    var n = Math.round((ymdUtc(co) - ymdUtc(ci)) / 86400000);
    if (n < 1) { out.error = 'Check-out has to be after check-in.'; return out; }
    // The endpoint prices at most 30 nights; never let a range be built that it
    // will then refuse.
    if (n > maxNights) { out.error = 'We can price up to ' + maxNights + ' nights online — please shorten your dates, or send us a note.'; return out; }
    out.nights = n;
    return out;
  }

  /** The one place the minimum-nights rule is explained to the guest. */
  function minNoteText() {
    if (!minNights || !dates.nights || dates.nights >= minNights) return '';
    return 'Group discounts need at least ' + nightsWord(minNights) + ' — your dates are '
         + nightsWord(dates.nights) + ', so the prices below are shown without one.';
  }
  function paintMinNote() {
    var txt = minNoteText();
    ['mibMinNote1', 'mibMinNote2'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = txt;
      el.hidden = !txt;
    });
  }

  function paintDates() {
    derivedEl.textContent = dates.nights
      ? nightsWord(dates.nights) + ' · ' + fmtYmd(dates.ci) + ' → ' + fmtYmd(dates.co)
      : 'Pick your dates and we\'ll count the nights.';
    dateErrEl.textContent = dates.error;
    dateErrEl.hidden = !dates.error;
    goBtn.disabled = !dates.nights;
    document.getElementById('mibStayNights').textContent = dates.nights ? nightsWord(dates.nights) : '—';
    document.getElementById('mibStayDates').textContent =
      dates.nights ? fmtYmd(dates.ci) + ' → ' + fmtYmd(dates.co) : '';
    paintMinNote();
  }

  /** Re-read the inputs; repaint and re-price only when something moved. */
  function syncDates(force) {
    if (!force && ciInput.value === dates.ci && coInput.value === dates.co) return;
    dates  = evaluateDates();
    nights = dates.nights;               // the single source of stay length
    paintDates();
    if (!step2.hidden && dates.nights) { search(); if (!bespoke.hidden) quote(); }
  }
  document.addEventListener('click', function () { syncDates(false); });

  /** Hook the picker's own popup once it exists (it is built lazily on <body>). */
  function bindDpSync() {
    var p = document.querySelector('.dp-pop');
    if (!p || p.dataset.mibSync) return;
    p.dataset.mibSync = '1';
    p.addEventListener('click', function () { syncDates(false); });
  }

  function paintParty() {
    document.getElementById('mibPGuestsN').textContent = party.guests;
    var gb = document.getElementById('mibPGuests').querySelectorAll('button');
    gb[0].disabled = party.guests <= 1;
    gb[1].disabled = party.guests >= maxGuests;
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

  /* ── The photograph slider ────────────────────────────────────────────────
   *
   * One figure per card, whatever the card is holding. Four things it rests on:
   *
   *  - ONLY THE FIRST SLIDE CARRIES A src. The rest hold data-src and are
   *    hydrated the first time they are shown, so five cards of six photographs
   *    each open as five requests, not thirty.
   *  - The arrows are REAL BUTTONS, so Enter and Space work for nothing and the
   *    popup's focus trap picks them up without being told they exist. That is
   *    also why the position is a COUNTER and not a row of dots: two tab stops
   *    per card, rather than one per photograph inside a trapped dialog.
   *  - ONE photograph renders exactly as it did before — same frame, same
   *    ratio, no chrome laid over it (.mib-slides--one). Zero photographs never
   *    reach here at all: no figure, and the card is one column.
   *  - A slide whose image fails is REMOVED, not blanked. On a local server
   *    every CDN image 404s, so that is the ORDINARY path here: the slider
   *    shrinks photograph by photograph, loses its chrome when one is left, and
   *    when the last one goes the figure goes with it.
   */
  function figureHtml(photos, label) {
    var many = photos.length > 1;
    var slides = photos.map(function (p, n) {
      return '<div class="mib-slide' + (n === 0 ? ' on' : '') + '">'
        + '<img alt="' + esc(p.alt || label) + '" decoding="async" '
        + (n === 0 ? 'src="' + esc(p.url) + '" loading="eager"'
                   : 'data-src="' + esc(p.url) + '" loading="lazy"')
        + '></div>';
    }).join('');
    return '<figure class="mib-off__fig"' + (many ? ' aria-label="Photographs of this stay"' : '') + '>'
      + '<div class="mib-slides' + (many ? '' : ' mib-slides--one') + '" data-i="0">' + slides
      + '<button type="button" class="mib-slide__nav mib-slide__nav--prev" data-mib-slide="-1" aria-label="Previous photograph">&#8249;</button>'
      + '<button type="button" class="mib-slide__nav mib-slide__nav--next" data-mib-slide="1" aria-label="Next photograph">&#8250;</button>'
      + '<p class="mib-slide__count" aria-live="polite">1 / ' + photos.length + '</p>'
      + '</div></figure>';
  }

  /** Paint one slider from its own state: clamp the index, show it, say where. */
  function syncSlides(box) {
    var slides = box.querySelectorAll('.mib-slide');
    if (!slides.length) return 0;
    var i = parseInt(box.dataset.i, 10) || 0;
    if (i < 0) i = slides.length - 1;
    if (i >= slides.length) i = 0;
    box.dataset.i = i;
    for (var n = 0; n < slides.length; n++) slides[n].classList.toggle('on', n === i);
    var img = slides[i].querySelector('img');          // hydrate on demand, once
    if (img && !img.getAttribute('src') && img.dataset.src) {
      // loading="lazy" has to come OFF at the moment we hydrate. The attribute
      // and this hydration are two deferral mechanisms for one decision, and
      // the browser's wins: a lazy image on a card scrolled out of view is not
      // fetched at all, so it can neither load nor fail, and the failure walk
      // below stalls on a slide that will never answer. We are the ones
      // deciding when a slide loads; once we have decided, it must actually go.
      // This loads no more photographs than before — only the slide being shown
      // is ever hydrated.
      img.loading = 'eager';
      img.src = img.dataset.src;
    }
    box.classList.toggle('mib-slides--one', slides.length < 2);
    var c = box.querySelector('.mib-slide__count');
    if (c) c.textContent = (i + 1) + ' / ' + slides.length;
    return slides.length;
  }

  function slideBy(box, dir) {
    box.dataset.i = (parseInt(box.dataset.i, 10) || 0) + dir;   // wraps in syncSlides
    syncSlides(box);
  }

  // Arrows: one delegated handler for every card's slider.
  offersEl.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-mib-slide]') : null;
    if (!b) return;
    var box = b.closest('.mib-slides');
    if (box) slideBy(box, parseInt(b.dataset.mibSlide, 10) || 1);
  });

  // ← / → while an arrow has focus. Only ever fires inside a slider, so it
  // cannot swallow a key the dialog or the datepicker wanted.
  offersEl.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var box = e.target.closest ? e.target.closest('.mib-slides') : null;
    if (!box || box.classList.contains('mib-slides--one')) return;
    e.preventDefault();
    slideBy(box, e.key === 'ArrowLeft' ? -1 : 1);
  });

  // Swipe. Both listeners are passive and never preventDefault: a vertical drag
  // has to stay the guest scrolling the popup past the card, so the gesture is
  // judged on release and only a decisively horizontal one counts.
  var swipe = null;
  offersEl.addEventListener('touchstart', function (e) {
    var box = e.target.closest ? e.target.closest('.mib-slides') : null;
    if (!box || box.classList.contains('mib-slides--one') || e.touches.length !== 1) { swipe = null; return; }
    swipe = { box: box, x: e.touches[0].clientX, y: e.touches[0].clientY };
  }, { passive: true });
  offersEl.addEventListener('touchend', function (e) {
    var s = swipe; swipe = null;
    var t = s && e.changedTouches && e.changedTouches[0];
    if (!t) return;
    var dx = t.clientX - s.x, dy = t.clientY - s.y;
    if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
    if (document.contains(s.box)) slideBy(s.box, dx < 0 ? 1 : -1);
  }, { passive: true });

  function offerCard(o, i) {
    var q = o.quote;
    var inc = o.units.map(function (u) {
      return '<li><span class="mib-off__u">' + (u.qty > 1 ? u.qty + ' × ' : '') + esc(u.key)
           + '<span class="mib-off__ud">' + esc(u.desc) + '</span></span>'
           + '<span class="mib-off__ug">' + u.guests + ' guest' + (u.guests === 1 ? '' : 's') + '</span></li>';
    }).join('');
    // The badge is the SERVER's call, not the slot's: the property's pick leads,
    // and the cheapest stay carries the reason it is cheap so the guest sees
    // what they would be trading away. Never re-derive either one here.
    var cls = 'mib-off'
      + (o.badge === 'pick' || o.badge === 'both' ? ' mib-off--top' : '')
      + (o.badge === 'cheapest' || o.badge === 'both' ? ' mib-off--cheap' : '');
    // The photographs are the SERVER's call too — which room a configuration is
    // of, and which of that room's images it gets, is resolved in the payload
    // (maya_ilai_offer_photos). Never guess one here. No photographs → no
    // figure, no --photo class, and the card is the single column it always was.
    var photos = (o.photos || []).filter(function (p) { return p && p.url; });
    var photo = photos.length ? figureHtml(photos, o.label) : '';
    if (photo) cls += ' mib-off--photo';
    return '<article class="' + cls + '" data-offer="' + i + '">'
      + '<div class="mib-off__body">'
      + (o.tag ? '<div class="mib-off__tag">' + esc(o.tag) + '</div>' : '')
      + '<div><h4 class="mib-off__name">' + esc(o.label) + '</h4>'
      + '<p class="mib-off__meta">For ' + q.guests + ' guest' + (q.guests === 1 ? '' : 's') + ' · ' + q.nights + ' night' + (q.nights === 1 ? '' : 's')
      + (q.capacity > q.guests ? ' · sleeps up to ' + q.capacity : '') + '</p></div>'
      // Every figure a guest sees here is ACCOMMODATION. The Eco-Resort Fee is
      // still calculated, still travels in the payload, and still reaches the
      // property on the enquiry — it is simply not quoted to the guest, who is
      // told about it by the property. So nothing on this card may be derived
      // from q.total, which includes it.
      + '<div class="mib-off__money"><div class="mib-off__total">' + priceSpan(q.accommodation) + '</div>'
      + '<div class="mib-off__per">' + priceSpan(q.nightly) + ' / night</div>'
      + (q.adjustment < 0 ? '<div class="mib-off__disc">' + esc(q.adjustmentLabel) + ' ' + q.adjustment + '%</div>' : '')
      + '</div>'
      + (o.why ? '<p class="mib-off__why">' + esc(o.why) + '</p>' : '')
      + '<ul class="mib-off__inc">' + inc + '</ul>'
      + '<button type="button" class="mib-cta mib-off__cta">Request this stay</button>'
      + '</div>'
      + photo
      + '</article>';
  }

  /* A photograph that does not load must leave the card looking deliberate, not
     broken. One slide failing takes only that SLIDE out — the slider re-counts,
     shows a neighbour, and drops its chrome if only one is left; the figure (and
     the card's second column) go only when the last photograph has gone.
     syncSlides() hydrates whatever is now on screen, so a card whose images ALL
     fail walks the list one request at a time and ends with no figure at all:
     the happy path still costs one request per card, and only a card that is
     actually broken pays for the rest. */
  function failSlide(img) {
    var fig = img.closest('.mib-off__fig');
    if (!fig) return;
    var dead = img.closest('.mib-slide');
    if (dead) dead.remove(); else img.remove();
    var box = fig.querySelector('.mib-slides');
    if (box && box.querySelector('.mib-slide')) { syncSlides(box); return; }
    var card = fig.closest('.mib-off');
    fig.remove();
    if (card) card.classList.remove('mib-off--photo');
  }

  /* Neither `error` nor `load` bubbles, so both listen in the CAPTURE phase —
     one handler apiece for the whole list rather than inline attributes per
     image. */
  offersEl.addEventListener('error', function (e) {
    if (e.target && e.target.tagName === 'IMG') failSlide(e.target);
  }, true);

  /* A 404 is not the only way a photograph fails to arrive, and on this stack it
     is not even the common one: an asset origin that has lost a file typically
     answers 200 with a placeholder — a 1×1 spacer, a blank pixel, a "missing
     image" sprite — and the local dev router does exactly that. Such a response
     fires `load`, not `error`, so the walk above never starts and the card keeps
     a reserved frame and an honest-looking "1 / 3" over nothing. Judge the
     RESULT instead of the status: anything this small is not a photograph of a
     bedroom, whatever the server called it, and it takes the same path out. The
     floor is far below any real image, so it can never discard one. */
  var MIN_PHOTO_PX = 24;
  offersEl.addEventListener('load', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.closest('.mib-off__fig')) return;
    if (img.naturalWidth >= MIN_PHOTO_PX && img.naturalHeight >= MIN_PHOTO_PX) return;
    failSlide(img);
  }, true);

  /* An honest one-line note about the live availability price. A discount is
     framed as a REASON (opening rates while the compound is quiet); scarcity is
     framed as scarcity (how many villas are left), never as a "surcharge" line
     that reads like a penalty. Silent at the reference band (no adjustment). */
  function availabilityBanner(d) {
    var a = d && d.availability;
    if (!a || !a.adjustment) return '';
    var n = a.freeVillas, villas = n + ' villa' + (n === 1 ? '' : 's');
    if (a.adjustment < 0) {
      var off = Math.round(Math.abs(a.adjustment));
      return '<div class="mib-avail mib-avail--deal"><b>' + esc(a.label) + '</b> — '
           + off + '% off while ' + villas + ' are open for these dates.</div>';
    }
    return '<div class="mib-avail mib-avail--tight">Only <b>' + villas
         + '</b> left for these dates' + (a.label ? ' — ' + esc(a.label) + ' rates apply' : '') + '.</div>';
  }

  function search() {
    clearTimeout(searchTimer);
    var seq = ++searchSeq;
    recapEl.textContent = party.guests + ' guest' + (party.guests === 1 ? '' : 's') + ' · ' + stayLine();
    offersEl.innerHTML = '<div class="mib-offers__msg">Finding what fits…</div>';
    searchTimer = setTimeout(function () {
      fetch(endpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode: 'suggest', guests: party.guests, nights: dates.nights,
                               check_in: dates.ci, check_out: dates.co, limit: 5 })
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
            // With a live calendar check (d.checked), an empty result means these
            // dates cannot seat this party — distinct from a party bigger than the
            // compound could ever sleep. Say which, so the guest knows whether to
            // change the party or the dates.
            var msg;
            if (d.checked && (d.freeVillas === 0 && d.freeStudios === 0)) {
              msg = 'We are fully booked for these dates. Try different dates, or send us a note — '
                  + 'we sometimes have movement on the calendar.';
            } else if (d.checked) {
              msg = 'We do not have space for ' + party.guests + ' guest' + (party.guests === 1 ? '' : 's')
                  + ' on these dates. Try shorter or different dates, open the full picker below, or send us a note.';
            } else {
              msg = 'That is a bigger party than the compound sleeps in one go. '
                  + 'Open the full picker below, or send us a note — we have put larger groups together before.';
            }
            offersEl.innerHTML = '<div class="mib-offers__msg">' + msg + '</div>';
            return;
          }
          offersEl.innerHTML = availabilityBanner(d) + offers.map(offerCard).join('');
        })
        .catch(function () {
          if (seq !== searchSeq) return;
          offersEl.innerHTML = '<div class="mib-offers__msg">Network error — please try again.</div>';
          offers = [];
        });
    }, 120);
  }

  /** The stay, said once, the same way everywhere it is shown or sent. */
  function stayLine() {
    return dates.nights
      ? nightsWord(dates.nights) + ' · ' + fmtYmd(dates.ci) + ' → ' + fmtYmd(dates.co)
      : nightsWord(Math.max(1, nights));
  }

  /* ── Steps ────────────────────────────────────────────────────────────────
   * Three panels, one popup. The title follows the step; the popup's own scroll
   * box is reset so a step never opens half-scrolled. */
  var stepTitles = { 1: 'Build your stay', 2: 'What fits your party', 3: 'Request your stay' };
  var popTitle = document.getElementById('mibPopTitle');
  function showStep(n) {
    step1.hidden = n !== 1; step2.hidden = n !== 2; step3.hidden = n !== 3;
    popTitle.textContent = stepTitles[n] || stepTitles[1];
    popBody.scrollTop = 0;
  }

  goBtn.addEventListener('click', function () {
    syncDates(true);
    if (!dates.nights) return;         // belt and braces — the button is disabled too
    showStep(2);
    search();
  });
  document.getElementById('mibBack').addEventListener('click', function () {
    searchSeq++;                      // abandon whatever is in flight
    showStep(1);
  });

  // Tapping an offer requests THAT stay — its own quote, not the picker's.
  offersEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.mib-off__cta');
    if (!btn) return;
    var card = btn.closest('.mib-off');
    var o = offers[parseInt(card.dataset.offer, 10)];
    if (!o) return;
    // A one-unit offer's name IS its breakdown ("2× Two-Bedroom Family Room ·
    // 2× Two-Bedroom Family Room") — say it once, and spell the mix out only
    // when there is actually a mix.
    var breakdown = o.units.map(function (u) {
      return (u.qty > 1 ? u.qty + '× ' : '') + u.key + ' (' + u.guests + ' guest' + (u.guests === 1 ? '' : 's') + ')';
    }).join(', ');
    // Carry the offer's product units through, so this becomes a real atomic
    // multi-room hold (each unit → its own hold, all-or-nothing) rather than an
    // enquiry. The picker's bespoke path passes no units and stays an enquiry.
    var bookUnits = o.units.map(function (u) { return { key: u.key, qty: u.qty, guests: u.guests }; });
    openRequest(o.quote, o.units.length === 1
      ? o.label + ' · ' + o.quote.guests + ' guest' + (o.quote.guests === 1 ? '' : 's')
      : o.label + ' · ' + breakdown, bookUnits);
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

  // Step 3 — the request. modalQuote is what the guest is actually requesting;
  // the picker's own live quote keeps updating in lastQuote behind it and must
  // not overwrite what the form is showing.
  // Callers hand over the ROOMS only; the stay and the money are appended here,
  // so the screen and the email say the dates once each and say them the same.
  var modalQuote = null, modalRooms = '', modalUnits = null;
  function openRequest(q, rooms, units) {
    modalQuote = q; modalRooms = rooms; modalUnits = (units && units.length) ? units : null;
    document.getElementById('mibSummaryText').textContent =
      rooms + ' · ' + stayLine() + ' · ' + usd(q.accommodation) + ' total';
    document.getElementById('mibMsg').className = 'mib-msg';
    showStep(3);
  }
  document.getElementById('mibRequest').addEventListener('click', function () {
    if (!lastQuote || lastQuote.errors.length || !lastQuote.guests) return;
    openRequest(lastQuote, roomsText());   // bespoke picker → enquiry (no units)
  });
  document.getElementById('mibCancel').addEventListener('click', function () { showStep(2); });
  document.getElementById('mibFormBack').addEventListener('click', function () { showStep(2); });

  /** What the bespoke picker currently holds, rooms only. */
  function roomsText() {
    var parts = [];
    rows.forEach(function (r) {
      var s = state[r.dataset.unit];
      if (s.qty) parts.push(s.qty + '× ' + r.dataset.unit + ' (' + s.g + ' guests)');
    });
    if (livingQty) parts.push(livingQty + '× Living Room + Kitchen');
    return parts.join(', ');
  }

  document.getElementById('mibForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, msg = document.getElementById('mibMsg');
    if (f.website.value) { closePop(); return; } // honeypot
    var q = modalQuote;
    if (!q) return;
    // The dates lead. Staff used to receive a room mix and a night count with no
    // dates at all, which is most of the work of actioning an enquiry; the ISO
    // pair is carried alongside the readable one so it can be parsed later.
    var dateLine = dates.nights
      ? 'Dates: ' + fmtYmd(dates.ci) + ' → ' + fmtYmd(dates.co)
        + ' (' + dates.ci + ' → ' + dates.co + ', ' + nightsWord(dates.nights) + ')'
      : 'Dates: not given (' + nightsWord(q.nights) + ')';
    var message = 'Maya Ilai booking request:\n' + dateLine + '\nRooms: ' + modalRooms +
      '\nAccommodation/night: ' + usd(q.nightly) + ' · Eco fee: ' + usd(q.eco) + ' · Estimated total: ' + usd(q.total) +
      '\nThe property will confirm and hold these dates by email.' +
      (f.note.value.trim() ? ('\n\nGuest note: ' + f.note.value.trim()) : '');
    // The real Turnstile token from the widget in this form (empty in dev, where
    // verify_captcha() bypasses). Used by BOTH the booking and the enquiry POST.
    var tkEl = f.querySelector('[name="cf-turnstile-response"]');
    var token = tkEl ? tkEl.value : '';
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true;

    // An offer (modalUnits set) becomes a REAL atomic multi-room hold; the bespoke
    // picker (no units) stays an enquiry, exactly as before.
    var isBooking = !!(modalUnits && modalUnits.length && dates.ci && dates.co);
    var endpoint2 = isBooking ? '/api/maya-ilai-book.php' : contact;
    var body = isBooking
      ? { units: modalUnits, check_in: dates.ci, check_out: dates.co,
          name: f.name.value, email: f.email.value, phone: f.phone.value,
          message: f.note.value.trim(), website: '', 'cf-turnstile-response': token }
      : { name: f.name.value, email: f.email.value, phone: f.phone.value,
          subject: 'Maya Ilai booking request' + (dates.nights ? ' · ' + dates.ci + ' → ' + dates.co : ''),
          message: message, check_in: dates.ci, check_out: dates.co, nights: dates.nights, rooms: modalRooms,
          quoted_total: q.total, quoted_currency: 'USD', 'cf-turnstile-response': token };

    fetch(endpoint2, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j && res.j.ok) {
          msg.className = 'mib-msg ok show';
          if (isBooking && res.j.mode === 'hold') {
            msg.textContent = 'Your rooms are held for 24 hours' + (res.j.access_code ? ' (ref ' + res.j.access_code + ')' : '')
              + '. We\'ll confirm by email shortly.';
          } else {
            msg.textContent = 'Request sent — the property will confirm availability by email shortly.';
          }
          setTimeout(function () { closePop(); }, 3000);
        } else {
          msg.className = 'mib-msg bad show';
          msg.textContent = (res.j && (res.j.error || (res.j.errors && Object.values(res.j.errors)[0]))) || 'Could not send. Please try again.';
          // A single-use Turnstile token is spent on a failed submit; reset the widget.
          if (window.turnstile && typeof window.turnstile.reset === 'function') { try { window.turnstile.reset(); } catch (e) {} }
        }
      })
      .catch(function () { msg.className = 'mib-msg bad show'; msg.textContent = 'Network error. Please try again.'; })
      .then(function () { btn.disabled = false; });
  });

  /* ── The popup ────────────────────────────────────────────────────────────
   *
   * ONE dialog owns the scroll lock, the focus trap and the Escape key — which
   * is exactly why the request form became step 3 instead of a second modal.
   *
   * The one other layer that can be on screen is the shared datepicker's pop.
   * It lives on <body> at z-index 9999, above this, and closes itself on
   * Escape and on any outside click. Both handlers below stand aside while it
   * is open, so the first Escape (and the first backdrop click) dismisses the
   * calendar and the second dismisses the popup — never both at once.
   */
  var pop = document.getElementById('mibPop'), dialog = document.getElementById('mibDialog');
  var popBody = document.getElementById('mibPopBody');
  // Out to <body>: position:fixed is relative to a transformed ancestor, and
  // the property page animates sections on scroll.
  if (pop.parentNode !== document.body) document.body.appendChild(pop);

  var lastTrigger = null;
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  function dpIsOpen() { var p = document.querySelector('.dp-pop'); return !!(p && !p.hidden); }
  function focusables() {
    return Array.prototype.filter.call(dialog.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth || el.offsetHeight || el.getClientRects().length;   // skips hidden steps
    });
  }

  function openPop(trigger) {
    if (trigger) lastTrigger = trigger;
    if (!pop.hidden) return;
    // The page must not scroll behind. body alone is not enough here: this page
    // sets html{overflow-x:hidden}, so body's overflow no longer propagates to
    // the viewport and <html> stays the scroller.
    var bar = window.innerWidth - document.documentElement.clientWidth;
    if (bar > 0) document.body.style.paddingRight = bar + 'px';
    document.documentElement.classList.add('mib-locked');
    document.body.classList.add('mib-locked');
    pop.hidden = false; pop.classList.add('open');
    // The picker binds each .dp-btn once and skips bound ones, so this is safe
    // to call every time and covers the script having landed late.
    if (typeof window.initDatepickers === 'function') window.initDatepickers();
    bindDpSync();
    syncDates(true);
    var f = focusables();
    (f.length ? f[0] : dialog).focus();
  }

  function closePop() {
    if (pop.hidden) return;
    pop.classList.remove('open'); pop.hidden = true;
    document.documentElement.classList.remove('mib-locked');
    document.body.classList.remove('mib-locked');
    document.body.style.paddingRight = '';
    // Back to whatever opened it. Reopening keeps the party, the dates and the
    // step exactly as they were — nothing is reset, on either trigger.
    if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
  }

  // Any trigger, anywhere on the page. The links keep their href so that with
  // no JS they still jump to the section that holds the button.
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-mib-open]') : null;
    if (!t) return;
    e.preventDefault();
    openPop(t);
  });

  pop.addEventListener('click', function (e) {
    if (!e.target.closest('[data-mib-close]')) return;   // backdrop or the × only
    if (dpIsOpen()) return;                              // let the calendar close first
    closePop();
  });

  document.addEventListener('keydown', function (e) {
    if (pop.hidden) return;
    if (e.key === 'Escape' || e.key === 'Esc') {
      if (dpIsOpen()) return;                            // the calendar closes itself
      e.preventDefault(); closePop(); return;
    }
    if (e.key !== 'Tab') return;
    var f = focusables();
    if (!f.length) { e.preventDefault(); dialog.focus(); return; }
    var first = f[0], last = f[f.length - 1], active = document.activeElement;
    if (!dialog.contains(active)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
    else if (e.shiftKey && active === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
  });

  // datepicker.js pins its calendar to the trigger and repositions on WINDOW
  // scroll — which the popup's own scroll box never fires. Nudge it, or the
  // calendar floats away from its button as the guest scrolls the popup.
  popBody.addEventListener('scroll', function () {
    if (dpIsOpen()) window.dispatchEvent(new Event('scroll'));
  }, { passive: true });

  paintParty();
  paintDates();
  syncLiving();
})();
</script>
