<?php
declare(strict_types=1);
/**
 * Assistant RAG-layer tests (Phase 2). Run: php tests/assistant_rag.php
 *
 * The embeddings HTTP call is MOCKED with a deterministic stub (defined before
 * ai.php loads, so the function_exists guard keeps the real one out) — the same
 * NFR8 pattern the loop tests use. Pure logic (chunking, cleaning, tool wiring)
 * is asserted directly; the DB round-trip (upsert + vector search + scoping +
 * relevance floor) runs against the live table inside a transaction that is
 * always rolled back, so it writes nothing permanent.
 */

// ── Deterministic embedding stub (must be defined BEFORE ai.php is required) ──
/** A stable unit vector per text: same text → same vector, different text → different. */
function _test_embed_vector(string $text): array {
    mt_srand(crc32($text));
    $v = [];
    for ($i = 0; $i < 1536; $i++) $v[] = (mt_rand(0, 2000000) / 1000000.0) - 1.0;
    $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
    return array_map(fn($x) => $x / $norm, $v);
}
if (!function_exists('ai_embed_request')) {
    function ai_embed_request(array $payload): array {
        $inputs = $payload['input'] ?? [];
        if (!is_array($inputs)) $inputs = [$inputs];
        $data = [];
        foreach (array_values($inputs) as $i => $t) {
            $data[] = ['index' => $i, 'embedding' => _test_embed_vector((string)$t)];
        }
        return ['ok' => true, 'data' => ['data' => $data]];
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-rag.php';
require_once __DIR__ . '/../includes/assistant-tools.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic: cleaning ─────────────────────────────────────────────────────
check('clean strips tags + markdown',   rag_clean('<b>Hi</b> *there* `now`') === 'Hi there now');
check('clean collapses whitespace',      rag_clean("a   b\n\n\n\nc") === "a b\n\nc");
check('clean of empty is empty',         rag_clean('   ') === '');

// ── Pure logic: chunking ─────────────────────────────────────────────────────
check('chunk empty → []',                rag_chunk('') === []);
check('chunk short → single',            count(rag_chunk('A calm beachfront villa.')) === 1);
$multi = rag_chunk("para one here\n\npara two here\n\npara three here", 20);
check('chunk splits on size',            count($multi) >= 2);
check('every chunk within limit',        !array_filter($multi, fn($c) => mb_strlen($c) > 20));
$long = rag_chunk(str_repeat('word ', 200), 100);   // one 1000-char paragraph
check('oversized paragraph hard-splits', count($long) >= 9 && !array_filter($long, fn($c) => mb_strlen($c) > 100));

// ── Pure logic: vector literal ───────────────────────────────────────────────
check('vector literal format',           rag_vector_literal([1.0, 2.5, -0.25]) === '[1,2.5,-0.25]');

// ── Tool wiring ──────────────────────────────────────────────────────────────
$defs3 = assistant_tool_definitions(false);
$defs4 = assistant_tool_definitions(true);
check('no-rag → 3 tools',                count($defs3) === 3);
check('with-rag → 4 tools',              count($defs4) === 4);
$names4 = array_map(fn($t) => $t['name'], $defs4);
check('rag tool present when enabled',   in_array('search_property_info', $names4, true));
check('rag tool absent when disabled',   !in_array('search_property_info', array_map(fn($t) => $t['name'], $defs3), true));
check('rag tool has object schema',      ($defs4[3]['input_schema']['type'] ?? '') === 'object');
check('rag tool requires query',         ($defs4[3]['input_schema']['required'] ?? []) === ['query']);

$sysRag  = assistant_system_prompt(null, true);
$sysNone = assistant_system_prompt(null, false);
check('prompt mentions rag tool when on',   str_contains($sysRag, 'search_property_info'));
check('prompt omits rag tool when off',     !str_contains($sysNone, 'search_property_info'));

// Empty query is rejected without embedding.
$empty = assistant_tool_search_info(['query' => '  '], null);
check('search_info: empty query → error',   isset($empty['error']) && ($empty['need'] ?? '') === 'query');

check('ai_embed_supported returns bool',    is_bool(ai_embed_supported()));
$emb = ai_embed('hello world');
check('ai_embed (stub) returns 1 vector',   ($emb['ok'] ?? false) && count($emb['vectors']) === 1 && count($emb['vectors'][0]) === 1536);
$embBatch = ai_embed(['one', 'two', 'three']);
check('ai_embed batch preserves order',     ($embBatch['ok'] ?? false) && count($embBatch['vectors']) === 3
                                            && $embBatch['vectors'][0] === _test_embed_vector('one'));
check('ai_embed rejects empty string',      (ai_embed('')['ok'] ?? true) === false);

// ── DB round-trip: upsert + search + scope + floor (rolled back) ─────────────
if (!rag_table_exists()) {
    echo "\nSKIP  RAG DB round-trip (content_embeddings table missing — run add_content_embeddings.sql)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    $vids = db_query('SELECT id FROM venues ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    echo "\nSKIP  RAG DB round-trip (database unavailable: " . $e->getMessage() . ")\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
if (count($vids) < 2) {
    echo "\nSKIP  RAG DB round-trip (need 2 venues seeded)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
[$vScoped, $vOther] = [(int)$vids[0], (int)$vids[1]];

$qtext = 'ztest unique marker phrase about kite surfing at dawn';
$vec   = _test_embed_vector($qtext);
$neg   = array_map(fn($x) => -$x, $vec);   // cosine -1 → score 0 → below floor

db()->beginTransaction();
try {
    rag_upsert_chunk(['source' => 'ztest', 'source_id' => 'z-global', 'venue_id' => null,       'title' => 'Global marker'],  0, 'GLOBAL '  . $qtext, $vec);
    rag_upsert_chunk(['source' => 'ztest', 'source_id' => 'z-scoped', 'venue_id' => $vOther,     'title' => 'Scoped marker'],  0, 'SCOPED '  . $qtext, $vec);
    rag_upsert_chunk(['source' => 'ztest', 'source_id' => 'z-neg',    'venue_id' => null,       'title' => 'Opposite marker'],0, 'OPPOSITE ' . $qtext, $neg);

    // Owner (null scope): global + scoped present, opposite filtered by floor.
    $rAll = rag_search($qtext, 10, null);
    $idsAll = array_map(fn($r) => $r['title'], $rAll['results'] ?? []);
    check('search: exact match returned',        in_array('Global marker', $idsAll, true));
    // z-global and z-scoped share the query vector (distance 0), so either may tie
    // for the top slot — assert the exact match's OWN score is ~1 wherever it ranks.
    $globalScore = 0.0;
    foreach ($rAll['results'] ?? [] as $r) { if ($r['title'] === 'Global marker') $globalScore = $r['score']; }
    check('search: exact match scores ~1',       $globalScore > 0.99);
    check('search: opposite vector floored out', !in_array('Opposite marker', $idsAll, true));
    check('search: scoped row visible to owner', in_array('Scoped marker', $idsAll, true));

    // Scoped account: sees global rows + its own venue, NOT $vOther's scoped row.
    $rScoped  = rag_search($qtext, 10, [$vScoped]);
    $idsScoped = array_map(fn($r) => $r['title'], $rScoped['results'] ?? []);
    check('search: scope keeps global row',      in_array('Global marker', $idsScoped, true));
    check('search: scope hides other venue row', !in_array('Scoped marker', $idsScoped, true));

    // Empty scope: only global rows.
    $rEmpty = rag_search($qtext, 10, []);
    $idsEmpty = array_map(fn($r) => $r['title'], $rEmpty['results'] ?? []);
    check('search: empty scope keeps global',    in_array('Global marker', $idsEmpty, true));
    check('search: empty scope hides scoped',    !in_array('Scoped marker', $idsEmpty, true));

    // Idempotent upsert: re-writing the same chunk keeps one row (no dup on conflict).
    rag_upsert_chunk(['source' => 'ztest', 'source_id' => 'z-global', 'venue_id' => null, 'title' => 'Global marker v2'], 0, 'GLOBAL ' . $qtext, $vec);
    $cnt = (int)db_query("SELECT count(*) FROM content_embeddings WHERE source='ztest' AND source_id='z-global'")->fetchColumn();
    check('upsert: conflict updates, no dup',    $cnt === 1);
} finally {
    db()->rollBack();
}

// Confirm the transaction rolled back — no ztest rows leaked.
$leaked = (int)db_query("SELECT count(*) FROM content_embeddings WHERE source='ztest'")->fetchColumn();
check('round-trip left no rows behind',      $leaked === 0);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
