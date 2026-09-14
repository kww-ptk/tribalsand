<?php
/**
 * Admin-only AI content reindexer (RAG / Phase 2).
 * Runs bin/reindex-content.php's work from the browser: rebuilds the
 * `content_embeddings` cache from the live editable prose (venue about/stay copy,
 * room descriptions + FAQs, tours, sustainability) so the assistant/concierge can
 * DESCRIBE properties and rooms. Owner-only, CSRF-guarded.
 *
 * Prod runs behind a private RDS, so this browser trigger is the practical way to
 * reindex (the CLI needs VPC access). It is idempotent and commits per chunk, so
 * if the request is cut short (load-balancer idle timeout on a long first run),
 * just run it again — unchanged chunks are skipped and it finishes the rest.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assistant-rag.php';   // rag_reindex(), rag_table_exists(), ai_embed_supported()
require_login();
require_owner();

$pageTitle  = 'AI Reindex';
$activeMenu = '';

@set_time_limit(0);   // embedding many chunks over HTTP can take a while

$tableOk = rag_table_exists();
$keyOk   = ai_embed_supported();

$output = '';
$ok     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    verify_csrf();
    $dryRun = ($_POST['run'] === 'dry');

    if (!$tableOk) {
        $ok = false;
        $output = "content_embeddings is missing — run db/migrations/add_content_embeddings.sql from Migrations first.";
    } elseif (!$keyOk) {
        $ok = false;
        $output = "No embeddings key configured — set OPENAI_API_KEY (or AI_EMBED_KEY) in the environment.";
    } else {
        $lines = [];
        try {
            $stats = rag_reindex($dryRun, function (string $line) use (&$lines) { $lines[] = $line; });
            $lines[] = sprintf(
                "\n%s: %d document(s), %d chunk(s) — %d (re)embedded, %d unchanged, %d pruned, %d error(s).",
                $dryRun ? 'Would apply' : 'Done',
                $stats['documents'], $stats['chunks'], $stats['embedded'],
                $stats['unchanged'], $stats['pruned'], $stats['errors']
            );
            $ok = ($stats['errors'] === 0);
        } catch (\Throwable $e) {
            $ok = false;
            $lines[] = 'Reindex failed: ' . $e->getMessage();
        }
        $output = implode("\n", $lines);
    }
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>AI Content Reindex</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>

<?php if ($output): ?>
<div class="alert alert--<?= $ok ? 'success' : 'error' ?>" style="white-space:pre-wrap;font-family:monospace;font-size:13px">
<?= e($output) ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">Rebuild descriptive knowledge</span>
    <span class="text-muted" style="font-size:12px">venue &amp; room copy, FAQs, tours, sustainability</span>
  </div>
  <div class="card__body">
    <p class="text-muted" style="font-size:13px;margin-top:0">
      This rebuilds the embeddings the AI concierge/assistant uses to <strong>describe</strong> properties
      and rooms (amenities, area, policies, FAQs). Run it after editing property/room copy — prices and
      availability are always live and never come from here.
    </p>

    <p style="font-size:13px">
      Status:
      <strong style="color:<?= $tableOk ? 'var(--success,#137547)' : 'var(--danger,#b91c1c)' ?>">
        embeddings table <?= $tableOk ? 'present' : 'MISSING' ?>
      </strong>
      ·
      <strong style="color:<?= $keyOk ? 'var(--success,#137547)' : 'var(--danger,#b91c1c)' ?>">
        embeddings key <?= $keyOk ? 'configured' : 'NOT set' ?>
      </strong>
    </p>
    <?php if (!$tableOk): ?>
    <p class="text-muted" style="font-size:13px">
      Apply <code>add_content_embeddings.sql</code> from <a href="/admin/migrate.php">Migrations</a> first.
    </p>
    <?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:14px">
      <form method="POST" onsubmit="return confirm('Dry run: report what would change, embed nothing. Continue?')">
        <?= csrf_field() ?>
        <input type="hidden" name="run" value="dry">
        <button type="submit" class="btn-outline btn-sm" <?= ($tableOk && $keyOk) ? '' : 'disabled' ?>>Dry run</button>
      </form>
      <form method="POST" onsubmit="return confirm('Reindex now against the live database. This embeds changed content via the OpenAI API and can take a minute. It is safe to re-run if it is interrupted. Continue?')">
        <?= csrf_field() ?>
        <input type="hidden" name="run" value="apply">
        <button type="submit" class="btn-primary btn-sm" <?= ($tableOk && $keyOk) ? '' : 'disabled' ?>>Reindex now</button>
      </form>
    </div>
  </div>
</div>

<p class="text-muted" style="margin-top:24px;font-size:13px">
  Idempotent: unchanged content is skipped and each chunk is saved as it is embedded, so if the request is
  cut short on a long first run, just click <strong>Reindex now</strong> again to finish.
</p>

<?php include __DIR__ . '/_layout_end.php'; ?>
