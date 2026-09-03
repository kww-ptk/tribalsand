<?php
/**
 * Shared "watch the film" video feature section.
 *
 * Click-to-load YouTube facade: nothing loads from YouTube on page view except the
 * poster thumbnail — the iframe is only injected when the guest presses play.
 * Captions are disabled on the embed (cc_load_policy=0).
 *
 * Config (set before include, all optional except $vf_video_id):
 *   $vf_video_id    YouTube video id
 *   $vf_eyebrow     small uppercase label above the heading
 *   $vf_heading     heading — RAW HTML (carries <em>), page-authored, never user/DB input
 *   $vf_sub         intro paragraph (plain text)
 *   $vf_caption     small uppercase caption under the frame
 *   $vf_title       accessible title for the player
 *   $vf_poster_alt  alt text for the poster image
 *   $vf_class       extra class on the section (e.g. vfeat--rule-top)
 */

$vf_video_id   = $vf_video_id   ?? '';
$vf_eyebrow    = $vf_eyebrow    ?? 'Watch The Film';
$vf_heading    = $vf_heading    ?? 'Sustainability is at the heart of <em>everything we do</em>';
$vf_sub        = $vf_sub        ?? '';
$vf_caption    = $vf_caption    ?? '';
$vf_title      = $vf_title      ?? 'Tribal Sand film';
$vf_poster_alt = $vf_poster_alt ?? $vf_title;
$vf_class      = $vf_class      ?? '';

if ($vf_video_id === '') return;

/* CSS + JS ship once per page even if the partial is included more than once. */
$vf_assets_done = !empty($GLOBALS['__vf_assets_done']);
$GLOBALS['__vf_assets_done'] = true;
$GLOBALS['__vf_seq'] = ($GLOBALS['__vf_seq'] ?? 0) + 1;
$vf_uid = 'vfeat' . $GLOBALS['__vf_seq'];
?>
<?php if (!$vf_assets_done): ?>
<style>
/* ── VIDEO FEATURE (includes/video-feature.php) ── */
.vfeat{background:#fff;padding:6rem 6vw;position:relative;overflow:hidden;}
.vfeat--rule-top{border-top:1px solid var(--border);}
.vfeat--rule-bottom{border-bottom:1px solid var(--border);}
.vfeat-inner{position:relative;z-index:2;max-width:1000px;margin:0 auto;text-align:center;}
.vfeat-eyebrow{
  font-family:'Jost',sans-serif;font-size:.58rem;
  letter-spacing:.32em;text-transform:uppercase;
  color:var(--sand);margin-bottom:1.2rem;
}
.vfeat h2{
  font-family:'Cormorant Garamond',serif;
  font-size:clamp(1.8rem,3.4vw,3rem);font-weight:300;
  color:var(--teal-d);line-height:1.15;margin-bottom:1.2rem;
}
.vfeat h2 em{font-style:italic;color:var(--sand);}
.vfeat-sub{
  font-family:'Jost',sans-serif;font-size:.9rem;
  color:var(--mid);line-height:1.9;letter-spacing:.03em;
  max-width:620px;margin:0 auto 3rem;
}
.vfeat-frame{
  position:relative;width:100%;aspect-ratio:16/9;
  background:var(--teal-d);
  border:1px solid var(--border);
  overflow:hidden;
}
.vfeat-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0;}
.vfeat-poster{
  position:absolute;inset:0;width:100%;height:100%;
  padding:0;margin:0;border:0;background:none;cursor:pointer;display:block;
}
.vfeat-poster img{
  position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
  transition:transform .6s cubic-bezier(.4,0,.2,1);
}
.vfeat-poster::before{
  content:'';position:absolute;inset:0;z-index:1;
  background:linear-gradient(to top,rgba(16,47,58,.72) 0%,rgba(16,47,58,.28) 55%,rgba(16,47,58,.42) 100%);
  transition:opacity .4s;
}
.vfeat-poster:hover img{transform:scale(1.04);}
.vfeat-poster:hover::before{opacity:.82;}
.vfeat-play{
  position:absolute;z-index:2;top:50%;left:50%;transform:translate(-50%,-50%);
  display:flex;flex-direction:column;align-items:center;gap:1rem;pointer-events:none;
}
.vfeat-play-circle{
  width:82px;height:82px;border-radius:50%;
  border:1px solid rgba(184,150,90,.55);
  background:rgba(16,47,58,.35);
  backdrop-filter:blur(2px);
  display:flex;align-items:center;justify-content:center;
  transition:background .28s,border-color .28s,transform .28s;
}
.vfeat-play-circle i{font-size:1.5rem;color:var(--sand);margin-left:5px;transition:color .28s;}
.vfeat-poster:hover .vfeat-play-circle{background:var(--sand);border-color:var(--sand);transform:scale(1.06);}
.vfeat-poster:hover .vfeat-play-circle i{color:var(--teal-d);}
.vfeat-play-label{
  font-family:'Jost',sans-serif;font-size:.62rem;
  letter-spacing:.28em;text-transform:uppercase;color:rgba(255,255,255,.9);
}
.vfeat-caption{
  font-family:'Jost',sans-serif;font-size:.68rem;
  letter-spacing:.2em;text-transform:uppercase;
  color:var(--sand);margin-top:1.6rem;
}
@media(max-width:700px){
  .vfeat{padding:4rem 6vw;}
  .vfeat-sub{margin-bottom:2.2rem;}
  .vfeat-play-circle{width:62px;height:62px;}
  .vfeat-play-circle i{font-size:1.15rem;}
}
</style>
<script>
(function(){
  document.addEventListener('click',function(ev){
    var btn = ev.target.closest ? ev.target.closest('.vfeat-poster') : null;
    if(!btn) return;
    var frame = btn.closest('.vfeat-frame');
    if(!frame) return;
    var iframe = document.createElement('iframe');
    /* cc_load_policy=0 = captions off · iv_load_policy=3 = no annotations */
    iframe.src = 'https://www.youtube-nocookie.com/embed/' + btn.dataset.video +
                 '?autoplay=1&rel=0&modestbranding=1&playsinline=1&cc_load_policy=0&iv_load_policy=3';
    iframe.title = btn.dataset.videoTitle || 'Video';
    iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
    iframe.allowFullscreen = true;
    frame.appendChild(iframe);
    btn.remove();
  });
})();
</script>
<?php endif; ?>
<section class="vfeat <?= e($vf_class) ?>" aria-labelledby="<?= e($vf_uid) ?>-h">
  <div class="vfeat-inner">
    <?php if ($vf_eyebrow !== ''): ?><p class="vfeat-eyebrow"><?= e($vf_eyebrow) ?></p><?php endif; ?>
    <h2 id="<?= e($vf_uid) ?>-h"><?= $vf_heading ?></h2>
    <?php if ($vf_sub !== ''): ?><p class="vfeat-sub"><?= e($vf_sub) ?></p><?php endif; ?>

    <div class="vfeat-frame">
      <button type="button" class="vfeat-poster"
              data-video="<?= e($vf_video_id) ?>"
              data-video-title="<?= e($vf_title) ?>"
              aria-label="Play video: <?= e($vf_title) ?>">
        <img src="https://i.ytimg.com/vi/<?= e($vf_video_id) ?>/maxresdefault.jpg"
             alt="<?= e($vf_poster_alt) ?>"
             loading="lazy" decoding="async" width="1280" height="720"
             onerror="this.onerror=null;this.src='https://i.ytimg.com/vi/<?= e($vf_video_id) ?>/hqdefault.jpg';">
        <span class="vfeat-play">
          <span class="vfeat-play-circle"><i class="fas fa-play"></i></span>
          <span class="vfeat-play-label">Play Film</span>
        </span>
      </button>
    </div>

    <?php if ($vf_caption !== ''): ?><p class="vfeat-caption"><?= e($vf_caption) ?></p><?php endif; ?>
  </div>
</section>
