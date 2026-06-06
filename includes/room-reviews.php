<?php
/**
 * Guest reviews section — included on room/villa booking pages.
 * Shows 3 testimonials. Replace $reviews array with real DB data when available.
 *
 * Optional: set $reviews_title before including to customise the heading.
 */
$reviews_title = $reviews_title ?? 'What Our Guests Say';

$reviews = $reviews ?? [
    [
        'quote'   => '"Stunning place, great staff — friendly and attentive. The view from the villa is absolutely breathtaking. We will definitely be back."',
        'name'    => 'Mr & Mrs B. Vyas',
        'country' => 'United Kingdom',
        'date'    => 'August 2023',
    ],
    [
        'quote'   => '"One of the most magical experiences of our lives. Waking up to the Indian Ocean every morning was something we will never forget. Tribal Sand exceeded every expectation."',
        'name'    => 'Sophie & James M.',
        'country' => 'Germany',
        'date'    => 'January 2024',
    ],
    [
        'quote'   => '"The team went above and beyond from the moment we arrived. The food, the service, the setting — absolutely world class. Kenya\'s best-kept secret."',
        'name'    => 'David R.',
        'country' => 'South Africa',
        'date'    => 'March 2024',
    ],
];
?>

<section class="ts-reviews">
  <div class="ts-reviews__inner">
    <p class="ts-reviews__eyebrow">Verified Guest Reviews</p>
    <h2 class="ts-reviews__title"><?= htmlspecialchars($reviews_title) ?></h2>
    <div class="ts-reviews__grid">
      <?php foreach ($reviews as $r): ?>
      <div class="ts-reviews__card">
        <div class="ts-reviews__stars" aria-label="5 stars">★★★★★</div>
        <blockquote class="ts-reviews__quote"><?= htmlspecialchars($r['quote']) ?></blockquote>
        <footer class="ts-reviews__meta">
          <strong class="ts-reviews__name"><?= htmlspecialchars($r['name']) ?></strong>
          <span class="ts-reviews__detail"><?= htmlspecialchars($r['country']) ?> · <?= htmlspecialchars($r['date']) ?></span>
        </footer>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.ts-reviews {
  background: #FAF8F4;
  padding: 5rem 5vw;
  border-top: 1px solid rgba(184,150,90,.12);
}
.ts-reviews__inner {
  max-width: 1100px;
  margin: 0 auto;
  text-align: center;
}
.ts-reviews__eyebrow {
  font-family: 'Jost', sans-serif;
  font-size: .58rem;
  letter-spacing: .28em;
  text-transform: uppercase;
  color: rgba(184,150,90,.8);
  margin-bottom: .9rem;
}
.ts-reviews__title {
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(1.6rem, 3vw, 2.4rem);
  font-weight: 300;
  color: #141412;
  margin-bottom: 3rem;
  line-height: 1.2;
}
.ts-reviews__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
  text-align: left;
}
.ts-reviews__card {
  background: #fff;
  border: 1px solid rgba(184,150,90,.14);
  padding: 2rem 1.8rem 1.6rem;
  display: flex;
  flex-direction: column;
  gap: .8rem;
}
.ts-reviews__stars {
  color: #B8965A;
  font-size: .9rem;
  letter-spacing: .1em;
}
.ts-reviews__quote {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.05rem;
  font-weight: 300;
  font-style: italic;
  color: #141412;
  line-height: 1.65;
  flex: 1;
  margin: 0;
}
.ts-reviews__meta {
  display: flex;
  flex-direction: column;
  gap: .2rem;
  padding-top: .8rem;
  border-top: 1px solid rgba(184,150,90,.12);
}
.ts-reviews__name {
  font-family: 'Jost', sans-serif;
  font-size: .76rem;
  font-weight: 500;
  color: #141412;
  letter-spacing: .04em;
}
.ts-reviews__detail {
  font-family: 'Jost', sans-serif;
  font-size: .68rem;
  color: #A89880;
  letter-spacing: .04em;
}
@media (max-width: 860px) {
  .ts-reviews__grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
  .ts-reviews__grid { grid-template-columns: 1fr; }
  .ts-reviews { padding: 3.5rem 5vw; }
}
</style>
