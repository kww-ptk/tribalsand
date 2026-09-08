#!/usr/bin/env php
<?php
/**
 * Rebuild the AI assistant's descriptive-content embeddings (Phase 2 RAG).
 *
 * Reads the app's editable prose (venue about/stay copy, room descriptions +
 * FAQs, tours, sustainability), chunks it, embeds each chunk, and upserts into
 * content_embeddings. It is a DERIVED cache — safe to run anytime.
 *
 * Run after applying db/migrations/add_content_embeddings.sql, on each database:
 *   php bin/reindex-content.php            # apply
 *   php bin/reindex-content.php --dry-run  # report what would change, embed nothing
 *
 * Idempotent and cheap to re-run: a chunk whose text is unchanged (same
 * content_hash) is not re-embedded, and documents/chunks that disappeared are
 * pruned. Re-run it whenever property/room/tour copy is edited (a manual step
 * for now; on-save/scheduled reindex is a documented follow-up).
 *
 * Local Windows dev note: PHP cURL needs a CA bundle for the HTTPS embed call —
 *   php -d curl.cainfo=<bundle> -d openssl.cafile=<bundle> bin/reindex-content.php
 * (Git Bash ships one at C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt).
 * Prod Linux has a system store, so no flag is needed.
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/assistant-rag.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!rag_table_exists()) {
    fwrite(STDERR, "content_embeddings is missing — apply db/migrations/add_content_embeddings.sql first.\n");
    exit(1);
}
if (!ai_embed_supported()) {
    fwrite(STDERR, "No embeddings key configured — set OPENAI_API_KEY (or AI_EMBED_KEY).\n");
    exit(1);
}

fwrite(STDOUT, ($dryRun ? "DRY RUN — no writes\n" : "Reindexing content embeddings…\n"));
$t0 = microtime(true);

$stats = rag_reindex($dryRun, function (string $line) { fwrite(STDOUT, $line . "\n"); });

$secs = number_format(microtime(true) - $t0, 1);
fwrite(STDOUT, sprintf(
    "\n%s: %d document(s), %d chunk(s) — %d (re)embedded, %d unchanged, %d pruned, %d error(s). [%ss]\n",
    $dryRun ? 'Would apply' : 'Done',
    $stats['documents'], $stats['chunks'], $stats['embedded'], $stats['unchanged'], $stats['pruned'], $stats['errors'], $secs
));

exit($stats['errors'] > 0 ? 1 : 0);
