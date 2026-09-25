<?php
/**
 * Admin: AI gaps — guest questions the concierge couldn't answer well.
 *
 * Reads concierge_log (READ-ONLY) and lists the turns includes/ai-gaps.php flags:
 * failed, said it didn't know, answered a price/date question without checking,
 * or the guest asked the same thing again. Grouped by topic so the owner can add
 * the missing facts (AI settings → Knowledge, property copy, or a new lookup).
 * Owner + manager. Never shows who asked (no client_ip is selected).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/ai-gaps.php';
require_login();
require_manager();

$RANGES  = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
$TOPICS  = ai_gap_topics();
$REASONS = ai_gap_reasons();
$REASON_BADGE = ['failed' => 'badge--red', 'unsure' => 'badge--orange', 'no_lookup' => 'badge--purple', 'repeat' => 'badge--blue'];

$days   = (int)($_GET['days'] ?? 30);
if (!isset($RANGES[$days])) $days = 30;
$topic  = (string)($_GET['topic'] ?? '');
if ($topic !== '' && !isset($TOPICS[$topic])) $topic = '';
$reason = (string)($_GET['reason'] ?? '');
if ($reason !== '' && !isset($REASONS[$reason])) $reason = '';
$pg = paginate_params(20);

$supported = concierge_log_supported();
$fetch     = $supported ? ai_gaps_fetch($days) : ['rows' => [], 'total' => 0, 'capped' => false];
$allGaps   = ai_gap_classify($fetch['rows']);
$topicCnt  = ai_gap_topic_counts($allGaps);

// Filters apply to the list only; the summary always describes the whole window.
$gaps = array_values(array_filter($allGaps, function (array $g) use ($topic, $reason, $pg): bool {
    if ($topic !== '' && $g['topic'] !== $topic) return false;
    if ($reason !== '' && $g['reason'] !== $reason) return false;
    if ($pg['q'] !== '') {
        $hay = mb_strtolower($g['question'] . ' ' . $g['answer']);
        if (!str_contains($hay, mb_strtolower($pg['q']))) return false;
    }
    return true;
}));
$meta = paginate_meta(count($gaps), $pg['page'], $pg['per']);
$page = array_slice($gaps, $meta['offset'], $meta['per']);

$asked   = (int)$fetch['total'];
$flagged = count($allGaps);
$share   = $asked > 0 ? (int)round($flagged * 100 / $asked) : 0;

/** Link to this page with the current filters, overriding some. */
$gapUrl = function (array $over) use ($days, $topic, $reason): string {
    $q = array_merge(['days' => $days, 'topic' => $topic, 'reason' => $reason], $over);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return '/admin/ai-gaps.php' . ($q ? '?' . http_build_query($q) : '');
};

// ── Swappable body (list + pager), reused for AJAX and the full page ─────────
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$page): ?>
      <?php dt_empty($pg['q'] !== '' || $topic !== '' || $reason !== ''
          ? 'No flagged questions match these filters.'
          : ($asked ? 'Nothing flagged in this period. The concierge answered every question with confidence.' : 'No guest questions in this period yet.')); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table aig-table">
        <thead><tr><th>When</th><th>Guest asked</th><th>Why it's flagged</th><th>What the AI said</th></tr></thead>
        <tbody>
        <?php foreach ($page as $g): ?>
          <tr>
            <td class="aig-when"><?= e(date('d M, H:i', strtotime((string)$g['created_at']))) ?></td>
            <td class="aig-q">
              <?= e($g['question']) ?>
              <div class="aig-topic"><?= e($TOPICS[$g['topic']]['label'] ?? 'Other') ?></div>
            </td>
            <td><span class="badge <?= e($REASON_BADGE[$g['reason']] ?? 'badge--grey') ?>"><?= e($REASONS[$g['reason']] ?? $g['reason']) ?></span></td>
            <td class="aig-a">
              <?php $ans = trim((string)$g['answer']); ?>
              <?php if ($ans === ''): ?>
                <span class="aig-none">No answer</span>
              <?php elseif (mb_strlen($ans) <= 180): ?>
                <?= nl2br(e($ans)) ?>
              <?php else: ?>
                <details><summary><?= e(mb_substr($ans, 0, 170)) ?>… <span class="aig-more">more</span></summary><?= nl2br(e($ans)) ?></details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }

$pageTitle  = 'AI gaps';
$activeMenu = 'ai_gaps';
include __DIR__ . '/_layout.php';
?>
<style>
.aig-intro { color: var(--muted); font-size: 13.5px; max-width: 760px; margin: -8px 0 18px; }
.aig-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 18px; }
.aig-chips .chip b { font-variant-numeric: tabular-nums; margin-left: 4px; }
.aig-fix { background: #f3f8f9; border: 1px solid #d6e6ea; border-radius: var(--radius); padding: 14px 18px; margin: 0 0 20px; font-size: 13.5px; }
.aig-fix h2 { font-size: 14px; margin: 0 0 8px; }
.aig-fix ul { margin: 0; padding-left: 18px; display: grid; gap: 4px; }
.aig-table td { vertical-align: top; }
.aig-when { white-space: nowrap; color: var(--muted); font-size: 12px; }
.aig-q { font-size: 13.5px; font-weight: 600; min-width: 200px; max-width: 320px; word-break: break-word; }
.aig-topic { font-size: 11.5px; font-weight: 500; color: var(--muted); margin-top: 3px; }
.aig-a { font-size: 13px; color: #334155; max-width: 420px; word-break: break-word; }
.aig-a summary { cursor: pointer; list-style: none; }
.aig-a summary::-webkit-details-marker { display: none; }
.aig-more { color: var(--brand); font-weight: 600; }
.aig-none { color: var(--muted); font-style: italic; }
.aig-kpis { margin-bottom: 18px; }
@media (max-width: 700px) { .aig-kpis { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
  <h1>AI gaps</h1>
  <span style="color:var(--muted);font-size:13px"><?= e($RANGES[$days]) ?></span>
</div>
<p class="aig-intro">Guest questions the website concierge couldn't answer well. Each one is a fact the AI is missing. Add it, and the next guest gets an answer.</p>

<?php if (!$supported): ?>
<div class="alert alert--info">The concierge log isn't set up on this database yet. Apply <code>db/migrations/add_concierge_log.sql</code> (Admin → Migrate), and flagged questions will appear here once guests use the concierge.</div>
<?php else: ?>

<div class="kpi-grid aig-kpis">
  <div class="kpi-card"><div class="kpi-card__label">Questions asked</div><div class="kpi-card__value"><?= number_format($asked) ?></div><div class="kpi-card__sub"><?= e(mb_strtolower($RANGES[$days])) ?><?= $fetch['capped'] ? ' · newest ' . number_format(AI_GAPS_MAX_ROWS) . ' only' : '' ?></div></div>
  <div class="kpi-card"><div class="kpi-card__label">Flagged</div><div class="kpi-card__value" style="color:#b45309"><?= number_format($flagged) ?></div><div class="kpi-card__sub">answered poorly or not at all</div></div>
  <div class="kpi-card"><div class="kpi-card__label">Answered well</div><div class="kpi-card__value"><?= $asked ? (100 - $share) . '%' : '—' ?></div><div class="kpi-card__sub">of all guest questions</div></div>
</div>

<?php if ($topicCnt): ?>
<div class="aig-chips" aria-label="Flagged questions by topic">
  <a class="chip<?= $topic === '' ? ' is-active' : '' ?>" href="<?= e($gapUrl(['topic' => ''])) ?>">All topics <b><?= $flagged ?></b></a>
  <?php foreach ($topicCnt as $k => $n): ?>
  <a class="chip<?= $topic === $k ? ' is-active' : '' ?>" href="<?= e($gapUrl(['topic' => $k])) ?>"><?= e($TOPICS[$k]['label'] ?? $k) ?> <b><?= (int)$n ?></b></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="aig-fix">
  <h2>How to fill a gap</h2>
  <ul>
    <li><strong>A general fact</strong> (transfers, pets, check-in times, Wi-Fi):
      <?php if (is_owner()): ?>add it in <a href="/admin/ai-settings.php">AI settings → Knowledge</a>. It takes effect straight away.<?php else: ?>ask the owner to add it in AI settings → Knowledge.<?php endif; ?></li>
    <li><strong>A property or room detail:</strong> add it to that property's or room's description in admin. The AI picks it up within a day.</li>
    <li><strong>Anything priced or date-dependent:</strong> tell the developer. The AI needs a new lookup so it never guesses a number.</li>
  </ul>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/ai-gaps.php" class="filters">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field">
        <span>Period</span>
        <select name="days" class="eselect" aria-label="Period" onchange="this.form.submit()">
          <?php foreach ($RANGES as $d => $lbl): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <span>Topic</span>
        <select name="topic" class="eselect" aria-label="Topic" onchange="this.form.submit()">
          <option value="">All topics</option>
          <?php foreach ($TOPICS as $k => $t): ?><option value="<?= e($k) ?>" <?= $topic === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <span>Reason</span>
        <select name="reason" class="eselect" aria-label="Reason" onchange="this.form.submit()">
          <option value="">All reasons</option>
          <?php foreach ($REASONS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $reason === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($topic !== '' || $reason !== ''): ?>
      <a href="<?= e($gapUrl(['topic' => '', 'reason' => ''])) ?>" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search questions or answers…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
