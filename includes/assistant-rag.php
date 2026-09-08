<?php
declare(strict_types=1);
/**
 * Assistant RAG layer (Phase 2) — the DESCRIPTIVE half of the assistant.
 *
 * Phase 1 answers exact, live facts (availability, prices) by calling helpers.
 * This layer answers prose questions — "what's the villa like?", "cancellation
 * policy?", "what's there to do nearby?" — by embedding the app's own editable
 * copy and retrieving the closest chunks with pgvector similarity search. The
 * model then phrases an answer grounded in those chunks (and says "I don't have
 * that" when nothing relevant comes back, FR5).
 *
 * Design rules (consistent with the tool layer and the codebase):
 *   · READ-ONLY at query time. rag_search() only SELECTs. Only the reindex CLI
 *     (bin/reindex-content.php) writes, and it writes a derived cache that can be
 *     rebuilt from the live DB at any time.
 *   · NEVER a source of prices. This table holds prose; the tool layer owns every
 *     number. The two never cross — an embedded paragraph can't quote a stay.
 *   · Degrade gracefully. rag_supported() is false without pgvector + an
 *     embeddings key, and every surface checks it, so a deploy missing either
 *     simply omits the descriptive layer instead of erroring (NFR4).
 *   · Scope by venue. Retrieval filters to the account's venues (global rows,
 *     venue_id IS NULL, are always visible), mirroring the tool layer's scoping.
 */
require_once __DIR__ . '/db.php';   // db_query(), venue_content_supported(), venue_stay_supported()
require_once __DIR__ . '/ai.php';   // ai_embed(), ai_embed_supported(), AI_EMBED_DIM

/** Weak matches below this cosine similarity are dropped so the model can honestly say "I don't have that". */
const RAG_MIN_SCORE   = 0.20;
/** Max characters per embedded chunk (prose here is short; keeps a chunk to one topic). */
const RAG_CHUNK_CHARS = 1200;

/** True only when the embeddings table exists AND an embeddings key is configured. Memoized. */
function rag_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    if (!ai_embed_supported()) return $c = false;
    try { return $c = (bool) db_query("SELECT to_regclass('public.content_embeddings')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Just the table check (no key requirement) — used by the reindex CLI, which needs the key anyway. */
function rag_table_exists(): bool {
    try { return (bool) db_query("SELECT to_regclass('public.content_embeddings')")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** Normalise DB prose for embedding: strip tags/markdown emphasis, decode entities, collapse whitespace. */
function rag_clean(string $s): string {
    $s = str_replace(['*', '#', '`', '_'], '', $s);          // light markdown the copy uses
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t]+/', ' ', $s);                  // collapse runs of spaces
    $s = preg_replace('/\n{3,}/', "\n\n", (string)$s);       // cap blank runs
    return trim((string)$s);
}

/**
 * Split text into embed-sized chunks on paragraph boundaries, hard-splitting any
 * single paragraph that is longer than the limit (on sentence breaks first).
 */
function rag_chunk(string $text, int $max = RAG_CHUNK_CHARS): array {
    $text = rag_clean($text);
    if ($text === '') return [];
    $paras = preg_split('/\n{2,}|\n/', $text) ?: [$text];

    $chunks = [];
    $buf = '';
    $flush = function () use (&$buf, &$chunks) {
        $b = trim($buf);
        if ($b !== '') $chunks[] = $b;
        $buf = '';
    };
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (mb_strlen($p) > $max) {
            // Oversized paragraph: flush what we have, then slice it on sentences.
            $flush();
            foreach (rag_split_long($p, $max) as $piece) $chunks[] = $piece;
            continue;
        }
        if ($buf !== '' && mb_strlen($buf) + 1 + mb_strlen($p) > $max) $flush();
        $buf = $buf === '' ? $p : $buf . "\n" . $p;
    }
    $flush();
    return $chunks;
}

/** Hard-split one over-long paragraph into <= $max pieces, preferring sentence ends. */
function rag_split_long(string $p, int $max): array {
    $out = [];
    $sentences = preg_split('/(?<=[.!?])\s+/', $p) ?: [$p];
    $buf = '';
    foreach ($sentences as $s) {
        if (mb_strlen($s) > $max) {
            if (trim($buf) !== '') { $out[] = trim($buf); $buf = ''; }
            // A single monster sentence — slice by length.
            for ($i = 0, $n = mb_strlen($s); $i < $n; $i += $max) $out[] = trim(mb_substr($s, $i, $max));
            continue;
        }
        if ($buf !== '' && mb_strlen($buf) + 1 + mb_strlen($s) > $max) { $out[] = trim($buf); $buf = ''; }
        $buf = $buf === '' ? $s : $buf . ' ' . $s;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return array_values(array_filter($out, fn($x) => $x !== ''));
}

/** Format a float vector as a pgvector text literal, e.g. "[0.1,0.2,…]". */
function rag_vector_literal(array $vec): string {
    // json_encode yields the exact "[a,b,c]" form pgvector parses, with
    // shortest round-trippable floats (PHP serialize_precision = -1).
    return json_encode(array_map('floatval', $vec), JSON_UNESCAPED_SLASHES);
}

/**
 * Gather the descriptive documents to embed, from the live DB. Each entry:
 *   ['source','source_id','venue_id'(?int),'title','text'].
 * A "document" is later chunked. venue_id scopes retrieval; NULL = global.
 */
function rag_gather_documents(): array {
    $docs = [];

    // ── Properties: about copy + stay info ────────────────────────────────────
    try {
        $venues = db_query('SELECT id, slug, name, tagline, about_heading, about_body, location,
                                   address, stay_wifi, stay_checkout, stay_house_rules, stay_area_guide
                              FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (Throwable $e) { $venues = []; }
    foreach ($venues as $v) {
        $parts = [];
        $head = trim((string)($v['about_heading'] ?? ''));
        $body = trim((string)($v['about_body'] ?? ''));
        if ($head || $body) $parts[] = "About {$v['name']}. " . trim($head . ($head && $body ? '. ' : '') . $body);
        if (trim((string)($v['tagline'] ?? '')) !== '')          $parts[] = 'Tagline: ' . $v['tagline'];
        if (trim((string)($v['location'] ?? '')) !== '')         $parts[] = 'Location: ' . $v['location'];
        if (trim((string)($v['address'] ?? '')) !== '')          $parts[] = 'Address: ' . $v['address'];
        if (trim((string)($v['stay_wifi'] ?? '')) !== '')        $parts[] = 'Wi-Fi: ' . $v['stay_wifi'];
        if (trim((string)($v['stay_checkout'] ?? '')) !== '')    $parts[] = 'Check-out & check-in: ' . $v['stay_checkout'];
        if (trim((string)($v['stay_house_rules'] ?? '')) !== '') $parts[] = 'House rules: ' . $v['stay_house_rules'];
        if (trim((string)($v['stay_area_guide'] ?? '')) !== '')  $parts[] = 'Area guide: ' . $v['stay_area_guide'];
        $text = rag_clean(implode("\n\n", $parts));
        if ($text === '') continue;
        $docs[] = ['source' => 'venue', 'source_id' => (string)$v['slug'], 'venue_id' => (int)$v['id'],
                   'title' => (string)$v['name'], 'text' => $text];
    }

    // ── Rooms: descriptions, features, FAQs ───────────────────────────────────
    try {
        $rooms = db_query('SELECT r.slug, r.name, r.short_desc, r.long_desc, r.features_json, r.faqs_json,
                                  r.venue_id, v.name AS venue_name
                             FROM rooms r JOIN venues v ON v.id = r.venue_id
                            WHERE r.is_published = TRUE ORDER BY r.venue_id, r.sort_order')->fetchAll();
    } catch (Throwable $e) { $rooms = []; }
    foreach ($rooms as $r) {
        $parts = ["{$r['name']} at {$r['venue_name']}."];
        if (trim((string)($r['short_desc'] ?? '')) !== '') $parts[] = $r['short_desc'];
        if (trim((string)($r['long_desc'] ?? '')) !== '')  $parts[] = $r['long_desc'];
        foreach ([['features_json', 'Features'], ['faqs_json', 'FAQ']] as [$col, $label]) {
            $list = json_decode((string)($r[$col] ?? '[]'), true);
            if (!is_array($list) || !$list) continue;
            if ($label === 'Features') {
                $items = array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', $list));
                if ($items) $parts[] = 'Features: ' . implode(', ', $items) . '.';
            } else {
                foreach ($list as $qa) {
                    $q = trim((string)($qa['q'] ?? '')); $a = trim((string)($qa['a'] ?? ''));
                    if ($q !== '' && $a !== '') $parts[] = "Q: {$q}\nA: {$a}";
                }
            }
        }
        $text = rag_clean(implode("\n\n", $parts));
        if ($text === '') continue;
        $docs[] = ['source' => 'room', 'source_id' => (string)$r['slug'], 'venue_id' => (int)$r['venue_id'],
                   'title' => $r['name'] . ' — ' . $r['venue_name'], 'text' => $text];
    }

    // ── Tours / activities (global; not venue-scoped) ─────────────────────────
    if (rag_table_present('tours')) {
        try {
            $tours = db_query('SELECT slug, name, short_desc, long_desc, highlights_json
                                 FROM tours WHERE is_published = TRUE ORDER BY sort_order')->fetchAll();
        } catch (Throwable $e) { $tours = []; }
        foreach ($tours as $t) {
            $parts = ["{$t['name']} (activity)."];
            if (trim((string)($t['short_desc'] ?? '')) !== '') $parts[] = $t['short_desc'];
            if (trim((string)($t['long_desc'] ?? '')) !== '')  $parts[] = $t['long_desc'];
            $hl = json_decode((string)($t['highlights_json'] ?? '[]'), true);
            if (is_array($hl)) {
                $items = array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', $hl));
                if ($items) $parts[] = 'Highlights: ' . implode(', ', $items) . '.';
            }
            $text = rag_clean(implode("\n\n", $parts));
            if ($text === '') continue;
            $docs[] = ['source' => 'tour', 'source_id' => (string)$t['slug'], 'venue_id' => null,
                       'title' => (string)$t['name'], 'text' => $text];
        }
    }

    // ── Sustainability initiatives (global prose; figures stay in the live page) ─
    if (function_exists('sustainability_supported') && sustainability_supported()) {
        try {
            $rows = db_query('SELECT label, note FROM sustainability_metrics WHERE is_published = TRUE ORDER BY sort_order')->fetchAll();
        } catch (Throwable $e) { $rows = []; }
        $lines = [];
        foreach ($rows as $m) {
            $label = trim((string)($m['label'] ?? '')); $note = trim((string)($m['note'] ?? ''));
            if ($label !== '') $lines[] = $note !== '' ? "{$label}: {$note}" : $label;
        }
        $text = rag_clean(implode("\n", $lines));
        if ($text !== '') {
            $docs[] = ['source' => 'page', 'source_id' => 'sustainability', 'venue_id' => null,
                       'title' => 'Sustainability', 'text' => $text];
        }
    }

    return $docs;
}

/** True if a table exists (used to skip optional sources pre-migration). */
function rag_table_present(string $name): bool {
    try { return (bool) db_query("SELECT to_regclass(:t)", [':t' => 'public.' . $name])->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/**
 * Rebuild the embeddings cache from the live DB. Idempotent and cheap to re-run:
 * a chunk whose text is unchanged (same content_hash) is NOT re-embedded, and
 * documents/chunks that disappeared are pruned. Returns a stats array.
 *
 * @param bool          $dryRun  Compute what would change, embed/write nothing.
 * @param callable|null $log     fn(string $line) for progress output (CLI).
 */
function rag_reindex(bool $dryRun = false, ?callable $log = null): array {
    $say = $log ?? function (string $l) {};
    $stats = ['documents' => 0, 'chunks' => 0, 'embedded' => 0, 'unchanged' => 0, 'pruned' => 0, 'errors' => 0];

    if (!rag_table_exists()) { $say('content_embeddings table missing — run the migration first.'); $stats['errors']++; return $stats; }
    if (!ai_embed_supported()) { $say('No embeddings key configured — set OPENAI_API_KEY.'); $stats['errors']++; return $stats; }

    $docs = rag_gather_documents();
    $seen = [];   // "source\0source_id" => chunk count kept

    foreach ($docs as $doc) {
        $chunks = rag_chunk($doc['text']);
        if (!$chunks) continue;
        $stats['documents']++;
        $seen[$doc['source'] . "\0" . $doc['source_id']] = count($chunks);

        // Existing hashes for this document.
        $existing = [];
        foreach (db_query('SELECT chunk_index, content_hash FROM content_embeddings WHERE source = :s AND source_id = :i',
                          [':s' => $doc['source'], ':i' => $doc['source_id']])->fetchAll() as $r) {
            $existing[(int)$r['chunk_index']] = (string)$r['content_hash'];
        }

        // Which chunks actually changed?
        $toEmbed = [];   // chunk_index => text
        foreach ($chunks as $idx => $text) {
            $stats['chunks']++;
            if (($existing[$idx] ?? null) === hash('sha256', $text)) { $stats['unchanged']++; continue; }
            $toEmbed[$idx] = $text;
        }

        if ($toEmbed && !$dryRun) {
            // Embed the changed chunks (in batches) and upsert.
            $idxs = array_keys($toEmbed);
            $texts = array_values($toEmbed);
            $vectors = [];
            foreach (array_chunk($texts, AI_EMBED_BATCH) as $batch) {
                $res = ai_embed($batch);
                if (!($res['ok'] ?? false)) {
                    $say("  ! embed failed for {$doc['source']}/{$doc['source_id']}: " . ($res['error'] ?? '?'));
                    $stats['errors']++;
                    $vectors = null; break;
                }
                foreach ($res['vectors'] as $v) $vectors[] = $v;
            }
            if ($vectors !== null) {
                foreach ($idxs as $pos => $idx) {
                    rag_upsert_chunk($doc, (int)$idx, $texts[$pos], $vectors[$pos]);
                    $stats['embedded']++;
                }
            }
        } elseif ($toEmbed) {
            $stats['embedded'] += count($toEmbed);   // dry-run: count what we'd embed
        }

        // Prune any chunks past the current length of this document.
        if (!$dryRun) {
            $del = db_query('DELETE FROM content_embeddings WHERE source = :s AND source_id = :i AND chunk_index >= :n',
                            [':s' => $doc['source'], ':i' => $doc['source_id'], ':n' => count($chunks)]);
            $stats['pruned'] += $del->rowCount();
        }

        $say(sprintf('  %-6s %-24s %d chunk(s)%s', $doc['source'], $doc['source_id'], count($chunks),
                     $toEmbed ? ' — ' . count($toEmbed) . ' (re)embedded' : ' — unchanged'));
    }

    // Prune documents that no longer exist at all.
    foreach (db_query('SELECT DISTINCT source, source_id FROM content_embeddings')->fetchAll() as $r) {
        if (isset($seen[$r['source'] . "\0" . $r['source_id']])) continue;
        if ($dryRun) { $stats['pruned']++; continue; }
        $del = db_query('DELETE FROM content_embeddings WHERE source = :s AND source_id = :i',
                        [':s' => $r['source'], ':i' => $r['source_id']]);
        $stats['pruned'] += $del->rowCount();
        $say("  - pruned removed document {$r['source']}/{$r['source_id']}");
    }

    return $stats;
}

/** Upsert one chunk row. Private to reindex. */
function rag_upsert_chunk(array $doc, int $idx, string $text, array $vector): void {
    db_query(
        'INSERT INTO content_embeddings
            (source, source_id, venue_id, title, chunk_index, chunk_text, content_hash, embedding, updated_at)
         VALUES (:s, :i, :v, :t, :ci, :txt, :h, :emb::vector, now())
         ON CONFLICT (source, source_id, chunk_index) DO UPDATE SET
            venue_id     = EXCLUDED.venue_id,
            title        = EXCLUDED.title,
            chunk_text   = EXCLUDED.chunk_text,
            content_hash = EXCLUDED.content_hash,
            embedding    = EXCLUDED.embedding,
            updated_at   = now()',
        [
            ':s'   => $doc['source'],
            ':i'   => $doc['source_id'],
            ':v'   => $doc['venue_id'] !== null ? (int)$doc['venue_id'] : null,
            ':t'   => mb_substr((string)$doc['title'], 0, 255),
            ':ci'  => $idx,
            ':txt' => $text,
            ':h'   => hash('sha256', $text),
            ':emb' => rag_vector_literal($vector),
        ]
    );
}

/**
 * Similarity search over the embedded prose. Read-only. Returns the closest
 * chunks (above RAG_MIN_SCORE), scoped to the account's venues.
 *
 * @param string     $query       Natural-language question.
 * @param int        $limit       Max chunks to return.
 * @param array|null $venueScope  null = all venues (owner); array of venue ids = restrict.
 * @return array ['results'=>[['source','title','text','score'],…]] | ['error'=>…].
 */
function rag_search(string $query, int $limit = 5, ?array $venueScope = null): array {
    if (!rag_supported()) return ['error' => 'Descriptive content search is not available on this environment.'];
    $query = trim($query);
    if ($query === '') return ['error' => 'A search query is required.'];

    $emb = ai_embed($query);
    if (!($emb['ok'] ?? false)) return ['error' => $emb['error'] ?? 'Could not embed the query.'];
    $qvec = rag_vector_literal($emb['vectors'][0]);

    // Venue scope: global rows (venue_id IS NULL) are always visible; scoped
    // accounts additionally see their own venues. Ids are ints from
    // admin_venue_ids(), cast defensively before inlining.
    $scopeSql = 'TRUE';
    if (is_array($venueScope)) {
        $ids = array_values(array_unique(array_map('intval', $venueScope)));
        $scopeSql = $ids ? '(venue_id IS NULL OR venue_id IN (' . implode(',', $ids) . '))' : '(venue_id IS NULL)';
    }
    $limit = max(1, min(10, $limit));

    $rows = db_query(
        "SELECT source, source_id, title, chunk_text,
                1 - (embedding <=> :q::vector) AS score
           FROM content_embeddings
          WHERE {$scopeSql}
          ORDER BY embedding <=> :q::vector
          LIMIT {$limit}",
        [':q' => $qvec]
    )->fetchAll();

    $results = [];
    foreach ($rows as $r) {
        $score = (float)$r['score'];
        if ($score < RAG_MIN_SCORE) continue;
        $results[] = [
            'source' => (string)$r['source'],
            'title'  => (string)$r['title'],
            'text'   => (string)$r['chunk_text'],
            'score'  => round($score, 3),
        ];
    }

    if (!$results) return ['results' => [], 'note' => 'No matching information was found in the property/activity content.'];
    return ['results' => $results];
}
