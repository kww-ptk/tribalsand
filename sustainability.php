<?php require_once 'includes/schema.php'; ?>
<?php
require_once 'includes/page-content.php';
require_once 'includes/sustainability.php';

/* Live figures — DB-driven, owner-editable, accruing. Pre-migration these are the
   numbers the page shipped with, so a missing migration renders the same page. */
$M         = sus_metrics();
$mSolar    = $M['solar_mwh']       ?? null;
$mCo2      = $M['co2_tonnes']      ?? null;
$mBeachTot = $M['beach_kg_total']  ?? null;
$mBeachWk  = $M['beach_kg_weekly'] ?? null;
$mDesal    = $M['desal_pct']       ?? null;

$solarNum = sus_metric_number($mSolar);
$co2Num   = sus_metric_number($mCo2);
$beachTot = sus_metric_number($mBeachTot);

/* Equivalences quoted alongside the CO2 figure. The tree factor is the one the
   home page already publishes (21.88 T ≈ 1,503 trees); the car factor is the
   ~0.17 kg CO2/km used for an average passenger vehicle. */
$treeEq = (int) round($co2Num * 68.7);
$kmEq   = (int) round($co2Num * 1000 / 0.17);

/* Next round milestone, for the progress bars. */
$nextMilestone = function (float $v, float $step): float {
    $n = ceil(($v + 0.0001) / $step) * $step;
    return $n > 0 ? $n : $step;
};

$page_title  = 'Sustainable Hotels Kenya · Eco Luxury at Tribal Sand';
$page_desc   = 'Discover how Tribal Sand powers Kenya\'s North Coast with solar energy, desalinated water and ocean conservation. '
             . sus_metric_display($mSolar) . ' MWh generated · ' . sus_metric_display($mCo2) . 'T CO₂ avoided.';
$page_url    = 'https://tribalsand.com/sustainability.php';
$page_image  = page_image('sustainability', 'og_image') ?: asset_url('images/maya_illai/Best1.jpg');

$page_schema  = ts_schema_org();
$page_schema .= ts_schema_breadcrumb([
    ['name' => 'Home',           'url' => 'https://tribalsand.com/'],
    ['name' => 'Sustainability', 'url' => 'https://tribalsand.com/sustainability.php'],
]);
$page_schema .= ts_schema_faq([
    [
        'q' => 'Is Tribal Sand eco friendly?',
        'a' => 'Yes. All Tribal Sand properties run on solar energy, use desalinated water, conduct weekly beach cleanups and support turtle conservation and coral restoration programmes.',
    ],
    [
        'q' => 'What makes Tribal Sand sustainable?',
        'a' => 'We generate ' . sus_metric_display($mSolar) . ' MWh of solar power, avoiding ' . sus_metric_display($mCo2)
              . ' tonnes of CO₂. We collect approximately ' . sus_metric_display($mBeachWk) . 'kg of beach trash weekly, restore coral reef and protect nesting sea turtles.',
    ],
    [
        'q' => 'Does Tribal Sand use renewable energy?',
        'a' => 'Yes. Our properties — especially Tribal Dunes in Kilifi — are 100% solar powered with battery storage, eliminating diesel dependency.',
    ],
    [
        'q' => 'What is the water source at Tribal Dunes?',
        'a' => 'Tribal Dunes uses 100% desalinated ocean water, making it completely independent of the municipal water supply.',
    ],
]);

require_once 'includes/head.php';
?>
<style>
/* ── SUSTAINABILITY PAGE ── */

/* HERO */
.sus-hero{
  position:relative;min-height:62vh;
  background:var(--teal-d);
  display:flex;align-items:flex-end;
  padding:0 6vw;
  overflow:hidden;
}
.sus-hero-img{
  position:absolute;inset:0;width:100%;height:100%;
  object-fit:cover;z-index:0;
}
.sus-hero::after{
  content:'';position:absolute;inset:0;z-index:1;
  background:
    linear-gradient(to top,rgba(16,47,58,.96) 0%,rgba(16,47,58,.80) 34%,rgba(16,47,58,.46) 70%,rgba(16,47,58,.32) 100%),
    radial-gradient(ellipse at 70% 50%,rgba(30,92,107,.35) 0%,transparent 70%);
  pointer-events:none;
}
.sus-hero-content{position:relative;z-index:2;max-width:720px;padding:5rem 0 4.5rem;}
.sus-hero .sus-eyebrow-sm{color:var(--sand);}
.sus-hero-sub{text-shadow:0 1px 12px rgba(16,47,58,.5);}
.sus-eyebrow-sm{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-bottom:1.2rem;
}
.sus-hero h1{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.2rem,4.5vw,3.8rem);font-weight:300;
  color:#fff;line-height:1.08;margin-bottom:1.4rem;
}
.sus-hero h1 em{font-style:italic;color:var(--sand);}
.sus-hero-sub{
  font-family:'Jost',sans-serif;font-size:.82rem;
  color:rgba(255,255,255,.85);line-height:1.9;letter-spacing:.04em;
}

/* STATS STRIP */
.sus-stats{background:var(--teal);padding:3.5rem 6vw;}
.sus-stats-grid{
  display:grid;grid-template-columns:repeat(4,1fr);
  gap:2rem;max-width:1200px;margin:0 auto;text-align:center;
}
.sus-stat-num{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2rem,4vw,3.2rem);font-weight:300;
  color:var(--sand);line-height:1;margin-bottom:.5rem;
}
.sus-stat-label{
  font-family:'Jost',sans-serif;font-size:.7rem;
  letter-spacing:.14em;text-transform:uppercase;
  color:rgba(255,255,255,.82);
}
.sus-stat-note{
  font-family:'Jost',sans-serif;font-size:.62rem;
  color:rgba(255,255,255,.5);margin-top:.4rem;letter-spacing:.04em;
}
.sus-stat-num .sus-unit{font-size:.5em;margin-left:.28em;letter-spacing:.04em;}

/* Live badge — the pulsing dot that marks a DB-driven figure */
.sus-live{
  display:inline-flex;align-items:center;gap:.45rem;
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.24em;text-transform:uppercase;
  color:rgba(184,150,90,.9);margin-bottom:.7rem;
}
.sus-live-dot{
  width:6px;height:6px;border-radius:50%;background:var(--sand);
  box-shadow:0 0 0 0 rgba(184,150,90,.6);
  animation:susPulse 2.4s infinite;
}
@keyframes susPulse{
  0%{box-shadow:0 0 0 0 rgba(184,150,90,.5);}
  70%{box-shadow:0 0 0 8px rgba(184,150,90,0);}
  100%{box-shadow:0 0 0 0 rgba(184,150,90,0);}
}
@media(prefers-reduced-motion:reduce){.sus-live-dot{animation:none;}}

/* SECTIONS */
.sus-section-wrap{padding:6rem 6vw;}
.sus-section-wrap.bg-off{background:var(--off);}
.sus-section-wrap.bg-white{background:#fff;}
.sus-section-inner{
  display:grid;grid-template-columns:1fr 1fr;
  gap:5rem;align-items:center;
  max-width:1200px;margin:0 auto;
}
.sus-section-inner.reverse{direction:rtl;}
.sus-section-inner.reverse > *{direction:ltr;}
.sus-eyebrow{
  font-family:'Jost',sans-serif;font-size:.56rem;
  letter-spacing:.3em;text-transform:uppercase;
  color:var(--sand);margin-bottom:1rem;
}
.sus-section-inner h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.8rem);font-weight:400;
  color:var(--teal-d);line-height:1.1;margin-bottom:1.4rem;
}
.sus-section-inner h2 em{font-style:italic;color:var(--sand);}
.sus-section-inner p{
  font-family:'Jost',sans-serif;font-size:.94rem;
  color:var(--mid);line-height:1.88;margin-bottom:1rem;
}
.sus-icon-box{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:var(--teal-d);
  min-height:280px;
  border:1px solid rgba(184,150,90,.1);
}
.sus-icon-box .sus-icon-fa{font-size:5rem;color:var(--sand);margin-bottom:1rem;}
.sus-icon-box-label{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.22em;text-transform:uppercase;
  color:rgba(184,150,90,.75);
}

/* LIVE DATA CARD — the interactive panel that replaces the static icon box */
.sus-live-card{
  background:var(--teal-d);
  border:1px solid rgba(184,150,90,.18);
  padding:2.2rem 2rem;
  display:flex;flex-direction:column;
  min-height:280px;
}
.sus-live-card.is-teal{background:var(--teal);}

/* tabs */
.sus-tabs{display:flex;gap:.4rem;margin-bottom:1.8rem;flex-wrap:wrap;}
.sus-tab{
  font-family:'Jost',sans-serif;font-size:.6rem;
  letter-spacing:.16em;text-transform:uppercase;
  padding:.55rem 1rem;cursor:pointer;
  color:rgba(255,255,255,.6);
  background:none;border:1px solid rgba(184,150,90,.22);
  transition:color .22s,border-color .22s,background .22s;
}
.sus-tab:hover{color:#fff;border-color:rgba(184,150,90,.5);}
.sus-tab[aria-selected="true"]{
  background:rgba(184,150,90,.14);
  border-color:var(--sand);color:var(--sand);
}
.sus-tab:focus-visible{outline:2px solid var(--sand);outline-offset:2px;}

.sus-panel{display:none;flex:1;flex-direction:column;justify-content:center;}
.sus-panel.is-on{display:flex;}
.sus-panel-num{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.6rem,5vw,4rem);font-weight:300;
  color:var(--sand);line-height:1;
}
.sus-panel-num .sus-unit{font-size:.36em;margin-left:.3em;letter-spacing:.06em;}
.sus-panel-lbl{
  font-family:'Jost',sans-serif;font-size:.7rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:rgba(255,255,255,.85);margin-top:.8rem;
}
.sus-panel-note{
  font-family:'Jost',sans-serif;font-size:.78rem;
  color:rgba(255,255,255,.62);line-height:1.7;margin-top:.7rem;
}

/* milestone bar */
.sus-bar-wrap{margin-top:1.6rem;}
.sus-bar{
  height:3px;background:rgba(255,255,255,.12);
  overflow:hidden;position:relative;
}
.sus-bar-fill{
  height:100%;width:0;background:var(--sand);
  transition:width 1.6s cubic-bezier(.2,.6,.2,1);
}
.sus-bar-lbl{
  display:flex;justify-content:space-between;gap:1rem;
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:rgba(255,255,255,.5);margin-top:.7rem;
}
@media(prefers-reduced-motion:reduce){.sus-bar-fill{transition:none;}}

/* beach ring */
.sus-ring-wrap{display:flex;align-items:center;gap:1.8rem;flex-wrap:wrap;}
.sus-ring{flex-shrink:0;transform:rotate(-90deg);}
.sus-ring-track{fill:none;stroke:rgba(255,255,255,.12);stroke-width:5;}
.sus-ring-fill{
  fill:none;stroke:var(--sand);stroke-width:5;stroke-linecap:round;
  transition:stroke-dashoffset 1.8s cubic-bezier(.2,.6,.2,1);
}
@media(prefers-reduced-motion:reduce){.sus-ring-fill{transition:none;}}
.sus-ring-meta{min-width:0;}
.sus-ring-num{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(2.2rem,4vw,3.2rem);font-weight:300;
  color:var(--sand);line-height:1;
}
.sus-ring-num .sus-unit{font-size:.4em;margin-left:.3em;}
.sus-ring-lbl{
  font-family:'Jost',sans-serif;font-size:.66rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:rgba(255,255,255,.85);margin-top:.7rem;
}
.sus-ring-note{
  font-family:'Jost',sans-serif;font-size:.74rem;
  color:rgba(255,255,255,.6);line-height:1.7;margin-top:.5rem;
}
.sus-rate-row{
  display:flex;gap:1.6rem;margin-top:1.8rem;padding-top:1.4rem;
  border-top:1px solid rgba(184,150,90,.16);flex-wrap:wrap;
}
.sus-rate-n{
  font-family:'Cormorant Garamond',serif;font-size:1.5rem;
  font-weight:300;color:#fff;line-height:1;
}
.sus-rate-l{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:rgba(255,255,255,.55);margin-top:.4rem;
}

/* FARM TO TABLE — the two greenhouses */
.sus-farm-card{
  background:var(--teal-d);
  border:1px solid rgba(184,150,90,.18);
  padding:2rem;
  display:flex;flex-direction:column;gap:1.2rem;
}
.sus-farm-house{
  display:flex;gap:1.1rem;align-items:flex-start;
  padding-bottom:1.2rem;
  border-bottom:1px solid rgba(184,150,90,.16);
}
.sus-farm-house:last-of-type{border-bottom:0;padding-bottom:0;}
.sus-farm-ico{
  flex-shrink:0;width:44px;height:44px;
  border:1px solid rgba(184,150,90,.35);
  display:flex;align-items:center;justify-content:center;
  color:var(--sand);font-size:1.1rem;
}
.sus-farm-name{
  font-family:'Cormorant Garamond',serif;font-size:1.3rem;
  font-weight:400;color:#fff;line-height:1.2;
}
.sus-farm-name em{font-style:italic;color:var(--sand);}
.sus-farm-where{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.2em;text-transform:uppercase;
  color:rgba(184,150,90,.8);margin-top:.35rem;
}
.sus-farm-desc{
  font-family:'Jost',sans-serif;font-size:.8rem;
  color:rgba(255,255,255,.68);line-height:1.75;margin-top:.6rem;
}
.sus-farm-foot{
  display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;
  padding-top:1.2rem;border-top:1px solid rgba(184,150,90,.16);
}
.sus-farm-stat-n{
  font-family:'Cormorant Garamond',serif;font-size:1.6rem;
  font-weight:300;color:var(--sand);line-height:1;
}
.sus-farm-stat-l{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.16em;text-transform:uppercase;
  color:rgba(255,255,255,.55);margin-top:.4rem;
}

/* SUB CARDS */
.sus-sub-cards{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:1.2rem;margin-top:2rem;
}
.sus-sub-card{
  background:#fff;border:1px solid var(--border);padding:1.4rem;
}
.sus-sub-card-title{
  font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:500;
  color:var(--teal-d);margin-bottom:.5rem;
}
.sus-sub-card-desc{
  font-family:'Jost',sans-serif;font-size:.76rem;
  color:var(--mid);line-height:1.7;
}

/* FAQ */
.sus-faq{background:var(--teal-d);padding:6rem 6vw;}
.sus-faq-inner{max-width:860px;margin:0 auto;}
.sus-faq h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3vw,2.6rem);font-weight:400;
  color:#fff;margin-bottom:.75rem;
}
.sus-faq-intro{
  font-family:'Jost',sans-serif;font-size:.86rem;
  color:rgba(255,255,255,.85);margin-bottom:3rem;
}
.sus-faq-item{border-bottom:1px solid rgba(184,150,90,.14);}
.sus-faq-trigger{
  width:100%;background:none;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;
  padding:1.4rem 0;gap:1rem;text-align:left;
}
.sus-faq-q{
  font-family:'Jost',sans-serif;font-size:.92rem;
  font-weight:500;color:rgba(255,255,255,.9);
  letter-spacing:.02em;line-height:1.5;
}
.sus-faq-icon{
  flex-shrink:0;width:28px;height:28px;
  border:1px solid rgba(184,150,90,.35);
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;color:var(--sand);
  transition:transform .28s,background .2s;
  font-style:normal;
}
.sus-faq-item.open .sus-faq-icon{
  transform:rotate(45deg);
  background:rgba(184,150,90,.12);
}
.sus-faq-panel{
  font-family:'Jost',sans-serif;font-size:.88rem;
  color:rgba(255,255,255,.85);line-height:1.88;
  max-height:0;overflow:hidden;
  transition:max-height .36s cubic-bezier(.4,0,.2,1),padding .28s;
}
.sus-faq-item.open .sus-faq-panel{max-height:400px;padding-bottom:1.6rem;}

/* CTA */
.sus-cta{
  background:var(--off);padding:5.5rem 6vw;
  text-align:center;border-top:1px solid var(--border);
}
.sus-cta p{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.5rem,2.8vw,2.2rem);font-weight:300;
  color:var(--teal-d);margin-bottom:2rem;font-style:italic;
}
.sus-btn{
  display:inline-block;font-family:'Jost',sans-serif;font-size:.72rem;
  letter-spacing:.2em;text-transform:uppercase;
  padding:.9rem 2.4rem;background:var(--teal-d);color:#fff;
  border:1px solid var(--teal-d);transition:background .22s,border-color .22s;
}
.sus-btn:hover{background:var(--teal);border-color:var(--teal);}

/* RESPONSIVE */
@media(max-width:900px){
  .sus-section-inner{grid-template-columns:1fr;gap:2.5rem;}
  .sus-section-inner.reverse{direction:ltr;}
  .sus-icon-box{min-height:180px;}
  .sus-sub-cards{grid-template-columns:1fr;}
  .sus-hero{min-height:52vh;}
  .sus-live-card{padding:1.8rem 1.4rem;min-height:0;}
  .sus-ring-wrap{gap:1.2rem;}
}
@media(max-width:700px){
  /* The stats are the point of this strip — give them room to read on a phone
     rather than shrinking with the viewport. */
  .sus-stats{padding:3rem 6vw;}
  .sus-stats-grid{
    grid-template-columns:1fr 1fr;
    gap:2.2rem 1.4rem;text-align:left;
  }
  .sus-stats-grid > div{
    padding-top:1.2rem;
    border-top:1px solid rgba(184,150,90,.22);
  }
  .sus-live{font-size:.54rem;letter-spacing:.2em;margin-bottom:.55rem;}
  .sus-stat-num{font-size:2.5rem;margin-bottom:.55rem;}
  .sus-stat-num .sus-unit{font-size:.42em;margin-left:.22em;}
  .sus-stat-label{
    font-size:.76rem;letter-spacing:.06em;line-height:1.45;
    color:rgba(255,255,255,.92);
  }
  .sus-stat-note{font-size:.72rem;color:rgba(255,255,255,.66);}
}
@media(max-width:420px){
  .sus-stats-grid{grid-template-columns:1fr;gap:1.6rem;}
  .sus-stat-num{font-size:2.8rem;}
  .sus-stat-label{font-size:.84rem;}
  .sus-farm-card{padding:1.5rem 1.2rem;}
  .sus-farm-foot{gap:.8rem;}
}
</style>

<body>
<?php include 'includes/header.php'; ?>

<!-- HERO -->
<section class="sus-hero">
  <?php $susHero = page_image('sustainability', 'hero_image'); if ($susHero): ?>
  <img class="sus-hero-img" src="<?= e($susHero) ?>"
       alt="Tribal Sand — solar-powered, ocean-fed beachfront hospitality on Kenya's North Coast"
       width="1920" height="1080" fetchpriority="high" decoding="async">
  <?php endif; ?>
  <div class="sus-hero-content">
    <p class="sus-eyebrow-sm"><?= page_text('sustainability','hero_eyebrow') ?></p>
    <h1><?= page_html('sustainability','hero_title') ?></h1>
    <p class="sus-hero-sub"><?= page_html('sustainability','hero_sub') ?></p>
  </div>
</section>

<!-- STATS STRIP -->
<section class="sus-stats">
  <div class="sus-stats-grid">
    <?php foreach ([
        [$mSolar,    'Solar Energy Generated'],
        [$mCo2,      'CO₂ Emissions Avoided'],
        [$mBeachWk,  'Beach Waste Collected Weekly'],
        [$mDesal,    'Desalinated Water at Tribal Dunes'],
    ] as [$metric, $label]):
        if (!$metric) continue;
        $updated = sus_metric_updated_label($metric); ?>
    <div>
      <div class="sus-live"><span class="sus-live-dot"></span><span>Live</span></div>
      <div class="sus-stat-num">
        <span data-countup="<?= e((string) sus_metric_number($metric)) ?>"
              data-decimals="<?= (int) $metric['decimals'] ?>"><?= e(sus_metric_display($metric)) ?></span><?php
        if (sus_metric_unit($metric) !== ''): ?><span class="sus-unit"><?= e(sus_metric_unit($metric)) ?></span><?php endif; ?>
      </div>
      <div class="sus-stat-label"><?= e($label) ?></div>
      <?php if ($updated !== ''): ?><div class="sus-stat-note"><?= e($updated) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- VIDEO -->
<?php
$vf_video_id   = 'XVCGDVbm3oo';
$vf_heading    = 'Sustainability is at the heart of <em>everything we do</em>';
$vf_sub        = "From solar arrays that power every villa to the reef we replant and the beaches we walk clean each week — see what living lightly on Kenya's North Coast actually looks like.";
$vf_caption    = "Tribal Sand · Kenya's North Coast";
$vf_title      = 'TribalSand | Sustainable Luxury on the Kenyan Coast';
$vf_poster_alt = "Tribal Sand sustainability film — solar power, ocean water and reef conservation on Kenya's North Coast";
$vf_class      = 'vfeat--rule-bottom';
include 'includes/video-feature.php';
?>

<!-- SECTION 1: SOLAR POWER -->
<?php
/* Milestone each figure is climbing toward — drives the bar under every tab. */
$solarGoal = $nextMilestone($solarNum, 10);
$co2Goal   = $nextMilestone($co2Num,   10);
$treeGoal  = $nextMilestone((float) $treeEq, 500);
?>
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow"><?= page_text('sustainability','solar_eyebrow') ?></p>
      <h2><?= page_html('sustainability','solar_title') ?></h2>
      <p>Our properties run on photovoltaic solar panels with battery storage. At Tribal Dunes, we have achieved full energy independence — eliminating diesel generators and powering everything from kitchen to air conditioning with the African sun.</p>
      <p>Our system has generated <strong><?= e(sus_metric_display($mSolar)) ?> MWh</strong> of clean electricity, avoiding <strong><?= e(sus_metric_display($mCo2)) ?> tonnes</strong> of CO&#8322; that would otherwise have entered the atmosphere. This makes us one of Africa's leading <strong>solar powered hotel</strong> operations — a model for <strong>sustainable hotel Kenya</strong>.</p>
    </div>

    <div class="sus-live-card" data-live-card>
      <div class="sus-live"><span class="sus-live-dot"></span><span>Live from Tribal Dunes</span></div>

      <div class="sus-tabs" role="tablist" aria-label="Solar performance">
        <button class="sus-tab" role="tab" aria-selected="true"  aria-controls="sus-p-energy" id="sus-t-energy" type="button">Energy</button>
        <button class="sus-tab" role="tab" aria-selected="false" aria-controls="sus-p-co2"    id="sus-t-co2"    type="button" tabindex="-1">CO&#8322; avoided</button>
        <button class="sus-tab" role="tab" aria-selected="false" aria-controls="sus-p-eq"     id="sus-t-eq"     type="button" tabindex="-1">Equivalent</button>
      </div>

      <div class="sus-panel is-on" id="sus-p-energy" role="tabpanel" aria-labelledby="sus-t-energy">
        <div class="sus-panel-num">
          <span data-countup="<?= e((string) $solarNum) ?>" data-decimals="<?= (int) ($mSolar['decimals'] ?? 2) ?>"><?= e(sus_metric_display($mSolar)) ?></span><span class="sus-unit">MWh</span>
        </div>
        <div class="sus-panel-lbl">Clean electricity generated</div>
        <div class="sus-panel-note">Photovoltaic array with battery storage. No diesel, no grid draw — the kitchen, the pumps and the air conditioning all run on it.</div>
        <div class="sus-bar-wrap">
          <div class="sus-bar"><div class="sus-bar-fill" data-fill="<?= e((string) round($solarNum / $solarGoal * 100, 1)) ?>"></div></div>
          <div class="sus-bar-lbl"><span>Toward <?= e(number_format($solarGoal)) ?> MWh</span><span><?= e((string) round($solarNum / $solarGoal * 100)) ?>%</span></div>
        </div>
      </div>

      <div class="sus-panel" id="sus-p-co2" role="tabpanel" aria-labelledby="sus-t-co2" hidden>
        <div class="sus-panel-num">
          <span data-countup="<?= e((string) $co2Num) ?>" data-decimals="<?= (int) ($mCo2['decimals'] ?? 2) ?>"><?= e(sus_metric_display($mCo2)) ?></span><span class="sus-unit">tonnes</span>
        </div>
        <div class="sus-panel-lbl">CO&#8322; kept out of the air</div>
        <div class="sus-panel-note">What the same power would have cost the atmosphere had it come from a diesel generator.</div>
        <div class="sus-bar-wrap">
          <div class="sus-bar"><div class="sus-bar-fill" data-fill="<?= e((string) round($co2Num / $co2Goal * 100, 1)) ?>"></div></div>
          <div class="sus-bar-lbl"><span>Toward <?= e(number_format($co2Goal)) ?> tonnes</span><span><?= e((string) round($co2Num / $co2Goal * 100)) ?>%</span></div>
        </div>
      </div>

      <div class="sus-panel" id="sus-p-eq" role="tabpanel" aria-labelledby="sus-t-eq" hidden>
        <div class="sus-panel-num">
          <span data-countup="<?= e((string) $treeEq) ?>" data-decimals="0"><?= e(number_format($treeEq)) ?></span><span class="sus-unit">trees</span>
        </div>
        <div class="sus-panel-lbl">Planted, in CO&#8322; terms</div>
        <div class="sus-panel-note">The same saving as <strong><?= e(number_format($kmEq)) ?> km</strong> not driven in an average car.</div>
        <div class="sus-bar-wrap">
          <div class="sus-bar"><div class="sus-bar-fill" data-fill="<?= e((string) round($treeEq / $treeGoal * 100, 1)) ?>"></div></div>
          <div class="sus-bar-lbl"><span>Toward <?= e(number_format($treeGoal)) ?> trees</span><span><?= e((string) round($treeEq / $treeGoal * 100)) ?>%</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SECTION 2: WATER -->
<div class="sus-section-wrap bg-off">
  <div class="sus-section-inner reverse">
    <div>
      <p class="sus-eyebrow">Water</p>
      <h2>Every Drop from <em>the Ocean</em></h2>
      <p>Tribal Dunes in Kilifi draws 100% of its water from the Indian Ocean through a reverse osmosis desalination system. This means no municipal water dependency, no borehole depletion, and no plastic bottles — just clean, mineral-rich water produced on-site.</p>
      <p>This commitment to water independence is a cornerstone of our <strong>eco friendly accommodation Kenya</strong> vision.</p>
    </div>
    <div class="sus-icon-box" style="background:var(--teal);">
      <i class="fas fa-water sus-icon-fa"></i>
      <span class="sus-icon-box-label">Desalinated Ocean Water</span>
    </div>
  </div>
</div>

<!-- SECTION 3: OCEAN CONSERVATION -->
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow"><?= page_text('sustainability','beach_eyebrow') ?></p>
      <h2><?= page_html('sustainability','beach_title') ?></h2>
      <p>Our team collects approximately <strong><?= e(sus_metric_display($mBeachWk)) ?>kg</strong> of beach waste every week along Bofa Beach and the Watamu coastline. We support active coral restoration projects, transplanting fragments to help rebuild Kenya's degraded reef systems.</p>
      <p>At Vipingo, My Amani participates in turtle monitoring and nest protection — a programme that has helped dozens of green and hawksbill turtles reach the ocean safely.</p>
      <div class="sus-sub-cards">
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Beach Cleanups</div>
          <div class="sus-sub-card-desc"><?= e(sus_metric_display($mBeachWk)) ?>kg/week removed from Kenya's coastline</div>
        </div>
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Coral Restoration</div>
          <div class="sus-sub-card-desc">Fragments replanted to rebuild reef ecosystems</div>
        </div>
        <div class="sus-sub-card">
          <div class="sus-sub-card-title">Turtle Conservation</div>
          <div class="sus-sub-card-desc">Nest monitoring at Vipingo beach</div>
        </div>
      </div>
    </div>
    <?php
    /* Progress toward the next full tonne of waste off the beach. */
    $tonneGoal = $nextMilestone($beachTot, 1000);
    $ringPct   = max(0.0, min(1.0, $beachTot / $tonneGoal));
    $circ      = 2 * M_PI * 52;                 // r=52 in the SVG below
    $weeksKept = $mBeachWk && sus_metric_number($mBeachWk) > 0
               ? (int) round($beachTot / sus_metric_number($mBeachWk)) : 0;
    ?>
    <div class="sus-live-card" data-live-card>
      <div class="sus-live"><span class="sus-live-dot"></span><span>Live · Bofa &amp; Watamu</span></div>
      <div class="sus-ring-wrap">
        <svg class="sus-ring" width="124" height="124" viewBox="0 0 124 124" role="img"
             aria-label="<?= e(round($ringPct * 100)) ?>% of the way to <?= e(number_format($tonneGoal)) ?>kg">
          <circle class="sus-ring-track" cx="62" cy="62" r="52"></circle>
          <circle class="sus-ring-fill"  cx="62" cy="62" r="52"
                  stroke-dasharray="<?= e((string) round($circ, 2)) ?>"
                  stroke-dashoffset="<?= e((string) round($circ, 2)) ?>"
                  data-ring="<?= e((string) round($circ * (1 - $ringPct), 2)) ?>"></circle>
        </svg>
        <div class="sus-ring-meta">
          <div class="sus-ring-num">
            <span data-countup="<?= e((string) $beachTot) ?>" data-decimals="0"><?= e(sus_metric_display($mBeachTot)) ?></span><span class="sus-unit">kg</span>
          </div>
          <div class="sus-ring-lbl">Waste off the beach</div>
          <div class="sus-ring-note"><?= e(round($ringPct * 100)) ?>% of the way to <?= e(number_format($tonneGoal)) ?>kg — every piece carried off by hand.</div>
        </div>
      </div>
      <div class="sus-rate-row">
        <div>
          <div class="sus-rate-n"><?= e(sus_metric_display($mBeachWk)) ?>kg</div>
          <div class="sus-rate-l">Every week</div>
        </div>
        <?php if ($weeksKept > 0): ?>
        <div>
          <div class="sus-rate-n"><?= e(number_format($weeksKept)) ?></div>
          <div class="sus-rate-l">Weeks of cleanups</div>
        </div>
        <?php endif; ?>
        <div>
          <div class="sus-rate-n">2</div>
          <div class="sus-rate-l">Coastlines covered</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SECTION 4: COMMUNITY -->
<div class="sus-section-wrap bg-off">
  <div class="sus-section-inner reverse">
    <div>
      <p class="sus-eyebrow">Community</p>
      <h2>Supporting Local <em>Kenya</em></h2>
      <p>Over 80% of our team are from local coastal communities. We source fresh produce from local farmers and fishermen, fund school initiatives, and run skills training programmes for young Kenyans in hospitality, marine conservation and renewable energy.</p>
      <p>True <strong>eco luxury Kenya coast</strong> means investing in the people who make the coast what it is.</p>
    </div>
    <div class="sus-icon-box" style="background:var(--teal);">
      <i class="fas fa-hands-helping sus-icon-fa"></i>
      <span class="sus-icon-box-label">80% Local Team</span>
    </div>
  </div>
</div>

<!-- SECTION 5: FARM TO TABLE -->
<div class="sus-section-wrap bg-white">
  <div class="sus-section-inner">
    <div>
      <p class="sus-eyebrow"><?= page_text('sustainability','farm_eyebrow') ?></p>
      <h2><?= page_html('sustainability','farm_title') ?></h2>
      <p>Two greenhouses grow the produce our kitchens cook with — one at Tribal Dunes in Kilifi, one at Zuri in Watamu. Salad leaves, herbs, tomatoes and peppers are picked in the morning and on the plate the same day, which is about as short as a supply chain gets.</p>
      <p>Both are run by growers from the surrounding villages, on permanent contracts rather than seasonal work. Food that used to arrive by road from Mombasa or Nairobi now walks across the property, and the money that paid for that journey stays on this coast instead.</p>
    </div>

    <div class="sus-farm-card">
      <div class="sus-farm-house">
        <span class="sus-farm-ico"><i class="fas fa-seedling"></i></span>
        <div>
          <div class="sus-farm-name">Tribal Dunes <em>Greenhouse</em></div>
          <div class="sus-farm-where">Kilifi · Bofa Beach</div>
          <div class="sus-farm-desc">Feeds Tribal Table and Somewhere Café. Irrigated with the same desalinated ocean water that supplies the property, so it draws nothing from Kilifi's freshwater.</div>
        </div>
      </div>

      <div class="sus-farm-house">
        <span class="sus-farm-ico"><i class="fas fa-leaf"></i></span>
        <div>
          <div class="sus-farm-name">Zuri <em>Greenhouse</em></div>
          <div class="sus-farm-where">Watamu</div>
          <div class="sus-farm-desc">Supplies the Zuri restaurant kitchen. Kitchen waste from the restaurant is composted back into its beds — the loop closes on site.</div>
        </div>
      </div>

      <div class="sus-farm-foot">
        <div>
          <div class="sus-farm-stat-n">2</div>
          <div class="sus-farm-stat-l">Greenhouses</div>
        </div>
        <div>
          <div class="sus-farm-stat-n">3</div>
          <div class="sus-farm-stat-l">Kitchens supplied</div>
        </div>
        <div>
          <div class="sus-farm-stat-n">100%</div>
          <div class="sus-farm-stat-l">Locally staffed</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SECTION 6: ZERO PLASTIC -->
<div class="sus-section-wrap bg-off">
  <div class="sus-section-inner reverse">
    <div>
      <p class="sus-eyebrow">Materials</p>
      <h2>No Single-Use <em>Plastic</em></h2>
      <p>Single-use plastics are banned across all Tribal Sand properties. Guests use refillable glass bottles, bamboo toiletry dispensers and organic linen. Our packaging is biodegradable, and kitchen waste is composted on-site.</p>
      <p>This is the standard we hold ourselves to as Kenya's most responsible <strong>eco friendly accommodation</strong> provider.</p>
    </div>
    <div class="sus-icon-box">
      <i class="fas fa-leaf sus-icon-fa"></i>
      <span class="sus-icon-box-label">Zero Single-Use Plastic</span>
    </div>
  </div>
</div>

<!-- FAQ ACCORDION -->
<section class="sus-faq">
  <div class="sus-faq-inner">
    <h2>Common Questions</h2>
    <p class="sus-faq-intro">Everything you want to know about our sustainability practices.</p>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">Is Tribal Sand eco friendly?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Yes. All Tribal Sand properties run on solar energy, use desalinated water, conduct weekly beach cleanups and support turtle conservation and coral restoration programmes.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">What makes Tribal Sand sustainable?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">We generate 27.59 MWh of solar power annually, avoiding 21.88 tonnes of CO&#8322;. We collect approximately 30kg of beach trash weekly, restore coral reef and protect nesting sea turtles.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">Does Tribal Sand use renewable energy?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Yes. Our properties — especially Tribal Dunes in Kilifi — are 100% solar powered with battery storage, eliminating diesel dependency.</div>
    </div>

    <div class="sus-faq-item">
      <button class="sus-faq-trigger" aria-expanded="false">
        <span class="sus-faq-q">What is the water source at Tribal Dunes?</span>
        <span class="sus-faq-icon">+</span>
      </button>
      <div class="sus-faq-panel">Tribal Dunes uses 100% desalinated ocean water, making it completely independent of the municipal water supply.</div>
    </div>

  </div>
</section>

<!-- CTA STRIP -->
<section class="sus-cta">
  <p>"Stay where sustainability is not a compromise."</p>
  <a href="/#properties" class="sus-btn">Explore Our Properties</a>
</section>

<script>
/* Live figures: count up when they scroll into view, fill the bars and the ring.
   prefers-reduced-motion gets the final value immediately, no animation. */
(function(){
  var STILL = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function fmt(n, dp){
    return n.toLocaleString('en-US', {minimumFractionDigits:dp, maximumFractionDigits:dp});
  }

  function countUp(el){
    var target = parseFloat(el.dataset.countup);
    var dp     = parseInt(el.dataset.decimals || '0', 10);
    if (isNaN(target)) return;
    // A hidden tab pauses rAF. Animating there would swap the server-rendered
    // figure for a 0 that only corrects itself when the tab is looked at, so
    // skip straight to the real number.
    if (STILL || document.hidden){ el.textContent = fmt(target, dp); return; }
    var dur = 1400, t0 = null;
    function step(ts){
      // The first frame only starts the clock — never paint a 0 over the value
      // the server already rendered.
      if (t0 === null){ t0 = ts; requestAnimationFrame(step); return; }
      var p = Math.min(1, (ts - t0) / dur);
      var eased = 1 - Math.pow(1 - p, 3);          // easeOutCubic
      el.textContent = fmt(target * eased, dp);
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = fmt(target, dp);
    }
    requestAnimationFrame(step);
  }

  function reveal(root){
    root.querySelectorAll('[data-countup]').forEach(countUp);
    root.querySelectorAll('.sus-bar-fill').forEach(function(b){
      b.style.width = Math.min(100, parseFloat(b.dataset.fill) || 0) + '%';
    });
    root.querySelectorAll('.sus-ring-fill').forEach(function(r){
      r.style.strokeDashoffset = r.dataset.ring;
    });
  }

  var blocks = document.querySelectorAll('.sus-stats, .sus-live-card');
  if (!('IntersectionObserver' in window)){
    blocks.forEach(reveal);
  } else {
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(en){
        if (!en.isIntersecting) return;
        reveal(en.target);
        io.unobserve(en.target);
      });
    }, {threshold:.25});
    blocks.forEach(function(b){ io.observe(b); });
  }

  /* Solar card tabs — click plus arrow-key roving focus. */
  document.querySelectorAll('[data-live-card] [role="tablist"]').forEach(function(list){
    var tabs = [].slice.call(list.querySelectorAll('[role="tab"]'));
    var card = list.closest('[data-live-card]');

    function select(tab){
      tabs.forEach(function(t){
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = card.querySelector('#' + t.getAttribute('aria-controls'));
        if (!panel) return;
        panel.classList.toggle('is-on', on);
        panel.hidden = !on;
        if (on) reveal(panel);
      });
    }

    tabs.forEach(function(tab, i){
      tab.addEventListener('click', function(){ select(tab); });
      tab.addEventListener('keydown', function(e){
        var next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
        if (next === null) return;
        e.preventDefault();
        var t = tabs[(next + tabs.length) % tabs.length];
        t.focus(); select(t);
      });
    });
  });
})();

(function(){
  document.querySelectorAll('.sus-faq-trigger').forEach(function(btn){
    btn.addEventListener('click',function(){
      var item = btn.closest('.sus-faq-item');
      var isOpen = item.classList.contains('open');
      document.querySelectorAll('.sus-faq-item.open').forEach(function(el){
        el.classList.remove('open');
        el.querySelector('.sus-faq-trigger').setAttribute('aria-expanded','false');
      });
      if(!isOpen){
        item.classList.add('open');
        btn.setAttribute('aria-expanded','true');
      }
    });
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
