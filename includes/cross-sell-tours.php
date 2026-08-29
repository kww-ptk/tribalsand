<?php
/**
 * Cross-sell tours section — "Pair your stay with an adventure"
 * Included on room/villa booking pages. Queries 3 published tours from DB.
 * Requires db.php to be loaded before this include.
 */
if (!function_exists('db_query')) require_once __DIR__ . '/db.php';

try {
    $cross_tours = db_query(
        'SELECT t.*,
                (SELECT filename FROM tour_images WHERE tour_id = t.id AND is_hero = TRUE LIMIT 1) AS hero_img
         FROM tours t
         WHERE t.is_published = TRUE
         ORDER BY t.sort_order ASC
         LIMIT 3'
    )->fetchAll();
} catch (Throwable $e) {
    $cross_tours = [];
}

if (empty($cross_tours)) return; // Hide section if no tours in DB
?>

<section class="ts-cross-tours">
  <div class="ts-cross-tours__inner">
    <p class="ts-cross-tours__eyebrow">Excursions &amp; Safari</p>
    <h2 class="ts-cross-tours__title">Pair Your Stay with an Adventure</h2>
    <p class="ts-cross-tours__sub">Every stay can include a safari, ocean excursion or cultural experience — arranged and guided by our team.</p>
    <div class="ts-cross-tours__grid">
      <?php foreach ($cross_tours as $tour):
        $img = !empty($tour['hero_img']) ? storage_url($tour['hero_img']) : '/images/Maya-Kobe-1-hero.webp';
      ?>
      <a class="ts-tour-card" href="/activities#tour-<?= e(rawurlencode($tour['slug'])) ?>">
        <div class="ts-tour-card__img-wrap">
          <img src="<?= e($img) ?>" alt="<?= e($tour['name']) ?>" loading="lazy">
          <?php if (!empty($tour['tag_label'])): ?>
          <span class="ts-tour-card__tag"><?= e($tour['tag_label']) ?></span>
          <?php endif; ?>
        </div>
        <div class="ts-tour-card__body">
          <h3 class="ts-tour-card__name"><?= e($tour['name']) ?></h3>
          <?php if (!empty($tour['duration'])): ?>
          <p class="ts-tour-card__duration">⏱ <?= e($tour['duration']) ?></p>
          <?php endif; ?>
          <p class="ts-tour-card__desc"><?= e(mb_strimwidth($tour['short_desc'] ?? '', 0, 110, '…')) ?></p>
          <span class="ts-tour-card__cta">Enquire →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.ts-cross-tours {
  background: #102F3A;
  padding: 5rem 5vw;
}
.ts-cross-tours__inner {
  max-width: 1100px;
  margin: 0 auto;
  text-align: center;
}
.ts-cross-tours__eyebrow {
  font-family: 'Jost', sans-serif;
  font-size: .58rem;
  letter-spacing: .28em;
  text-transform: uppercase;
  color: rgba(184,150,90,.7);
  margin-bottom: .9rem;
}
.ts-cross-tours__title {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(1.6rem, 3vw, 2.4rem);
  font-weight: 300;
  color: #fff;
  margin-bottom: .75rem;
  line-height: 1.2;
}
.ts-cross-tours__sub {
  font-family: 'Jost', sans-serif;
  font-size: .83rem;
  color: rgba(255,255,255,.55);
  max-width: 560px;
  margin: 0 auto 3rem;
  line-height: 1.7;
}
.ts-cross-tours__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.25rem;
  text-align: left;
}
.ts-tour-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(184,150,90,.15);
  text-decoration: none;
  display: flex;
  flex-direction: column;
  transition: border-color .25s, transform .25s;
}
.ts-tour-card:hover {
  border-color: rgba(184,150,90,.45);
  transform: translateY(-3px);
}
.ts-tour-card__img-wrap {
  position: relative;
  aspect-ratio: 16/9;
  overflow: hidden;
}
.ts-tour-card__img-wrap img {
  width: 100%; height: 100%;
  object-fit: cover;
  display: block;
  transition: transform .4s ease;
}
.ts-tour-card:hover .ts-tour-card__img-wrap img {
  transform: scale(1.04);
}
.ts-tour-card__tag {
  position: absolute;
  top: .75rem; left: .75rem;
  background: rgba(184,150,90,.9);
  color: #fff;
  font-family: 'Jost', sans-serif;
  font-size: .58rem;
  letter-spacing: .16em;
  text-transform: uppercase;
  padding: .25rem .6rem;
}
.ts-tour-card__body {
  padding: 1.4rem 1.5rem 1.6rem;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: .4rem;
}
.ts-tour-card__name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.15rem;
  font-weight: 400;
  color: #fff;
  line-height: 1.3;
}
.ts-tour-card__duration {
  font-family: 'Jost', sans-serif;
  font-size: .68rem;
  color: rgba(184,150,90,.8);
  letter-spacing: .04em;
}
.ts-tour-card__desc {
  font-family: 'Jost', sans-serif;
  font-size: .78rem;
  color: rgba(255,255,255,.5);
  line-height: 1.65;
  flex: 1;
}
.ts-tour-card__cta {
  font-family: 'Jost', sans-serif;
  font-size: .7rem;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: #D4B07A;
  margin-top: .4rem;
}
@media (max-width: 860px) {
  .ts-cross-tours__grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
  .ts-cross-tours__grid { grid-template-columns: 1fr; }
  .ts-cross-tours { padding: 3.5rem 5vw; }
}
</style>
