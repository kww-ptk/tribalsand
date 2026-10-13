<?php
/**
 * Staff establishment — every team member's employing COMPANY and PAYROLL NUMBER,
 * imported from the companies' staff lists (Oct 2026: MK Holidays, Tribal Sand
 * Holidays, Retreats Zuri, Tribal Sand Limited).
 *
 * The rules that matter:
 *  • A person is keyed by (company, payroll no.). Numbers repeat across
 *    companies, so the number alone means nothing. MK writes the same number two
 *    ways (MKH32 / MKH032): hr_est_payroll_key() compares without leading zeros,
 *    the stored value stays exactly as the company wrote it.
 *  • Names are matched on the SET of words, never the order: the lists say
 *    "OKAKA Henry Ouma", the directory says "HENRY OUMA OKAKA". A list row is
 *    matched automatically only when every word of the shorter name is found in
 *    exactly ONE existing person (≥ 2 words), and no other row claims that
 *    person. Anything else waits for a human decision — two "CHARO … KAZUNGU"
 *    gardeners share two words, and a guess would merge two people.
 *  • Nothing is written without a preview (hr_est_preview()), and the import is
 *    idempotent: a second run matches everyone by payroll number and changes
 *    nothing.
 *  • Company scope: a manager given a company (hr_company_managers) sees that
 *    company's staff even when they have no property — Tribal Sand Limited
 *    (kite school + transfers) has none.
 *
 * Every read is pre-migration-safe via hr_establishment_supported().
 * Test: php tests/staff_establishment_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hr.php';

/** add_hr_staff_establishment.sql applied? (catalog lookup — safe inside a transaction) */
function hr_establishment_supported(): bool { return hr_staff_company_supported(); }

// ── Pure: names ─────────────────────────────────────────────────────────────

/** Words that are never part of a person's name in the HR files. */
const HR_EST_NOISE_WORDS = ['TRAINEE', 'MR', 'MRS', 'MS', 'MISS'];

/**
 * "PALMA Lisa  Wanjira" → ['PALMA','LISA','WANJIRA'] (letters only, upper-case,
 * noise dropped). The roster tags names with where they work or a nickname —
 * "ALOICE CHARO - M. ILAI", "LIKAMBA NANA -MI", "(ALICE) MNYAZI JOSHUA",
 * "JAMES BAYA - TRAINEE" — so a bracketed part and everything after " -" go.
 */
function hr_est_name_tokens(string $name): array {
    $name = (string) preg_replace(['/\([^)]*\)/', '/\s-.*$/'], [' ', ''], $name);
    $s = strtoupper((string) preg_replace('/[^A-Za-z]+/', ' ', $name));
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
        if ($w === '' || in_array($w, HR_EST_NOISE_WORDS, true)) continue;
        $out[] = $w;
    }
    return $out;
}

/**
 * Two name words are the same person-word: identical; an initial ("C") and a
 * word it starts ("CHONDO"); a letter added at the end (JUMA / JUMAA, IDD / IDDI);
 * or one typo apart when both are 5+ letters
 * (SHUKRANI / SHUKURANI, SITIRINGA / SITIRINGAH), two typos when both are 7+
 * (JENNIFER / JENIFFER), or two letters swapped (LEYIAN / LEIYAN).
 */
function hr_est_word_eq(string $a, string $b): bool {
    if ($a === $b) return true;
    $la = strlen($a); $lb = strlen($b);
    if ($la === 1 && $lb > 1) return $b[0] === $a;
    if ($lb === 1 && $la > 1) return $a[0] === $b;
    // One letter added at the end of a short word (JUMA / JUMAA, IDD / IDDI, KEA / KEAH).
    if (min($la, $lb) >= 3 && abs($la - $lb) === 1 && ($la < $lb ? str_starts_with($b, $a) : str_starts_with($a, $b))) return true;
    if (min($la, $lb) === 4 && abs($la - $lb) === 1 && levenshtein($a, $b) === 1) return true;
    if ($la < 5 || $lb < 5) return false;
    if (levenshtein($a, $b) <= ($la >= 7 && $lb >= 7 ? 2 : 1)) return true;   // JENNIFER / JENIFFER
    // Two letters swapped (LEYIAN / LEIYAN): same letters, same length, ≤ 2 edits.
    $sa = str_split($a); $sb = str_split($b); sort($sa); sort($sb);
    return $sa === $sb && levenshtein($a, $b) <= 2;
}

/**
 * How two names relate: ['overlap' => words in common, 'contained' => every word
 * of the shorter name is in the longer one and that is at least 2 words]. Each
 * word is used once, exact matches first, so "JUDY JUDY" can't match one JUDY twice.
 */
function hr_est_name_score(array $ta, array $tb): array {
    $short = count($ta) <= count($tb) ? $ta : $tb;
    $long  = count($ta) <= count($tb) ? $tb : $ta;
    $used  = [];
    $m     = 0;
    foreach ([true, false] as $exactPass) {
        foreach ($short as $i => $w) {
            if (isset($used['s' . $i])) continue;
            foreach ($long as $j => $v) {
                if (isset($used['l' . $j])) continue;
                if ($exactPass ? $w === $v : hr_est_word_eq($w, $v)) {
                    $used['s' . $i] = $used['l' . $j] = true; $m++; break;
                }
            }
        }
    }
    return ['overlap' => $m, 'contained' => $m >= 2 && $m === count($short)];
}

/**
 * Split a list name into surname + first names. The company lists write the
 * SURNAME in capitals first ("OKAKA Henry Ouma"); a few rows capitalise two
 * words ("AUGUSTUS MUMO Mulwa") and those read first-names-first, surname last.
 * An all-capitals name ("RAMADHAN MZONJE MWACHIZI", Tribal Sand Limited) is in
 * natural order. 'display' is the directory style: "HENRY OUMA OKAKA".
 * 'unsure' = the surname was guessed from a two-capital-word row.
 */
function hr_est_parse_list_name(string $raw): array {
    $words = array_values(array_filter(preg_split('/\s+/', trim($raw)) ?: [], fn($w) => $w !== ''));
    if (!$words) return ['surname' => '', 'first_names' => '', 'display' => '', 'unsure' => false];
    $isCaps = fn(string $w) => preg_match('/[A-Z]/', $w) && strtoupper($w) === $w;
    $lead = 0;
    while ($lead < count($words) && $isCaps($words[$lead])) $lead++;
    $title = fn(array $ws) => implode(' ', array_map(fn($w) => ucfirst(strtolower($w)), $ws));
    if ($lead === count($words) || $lead === 0) {                 // all caps (or none): natural order
        $sur = $words[count($words) - 1];
        $first = array_slice($words, 0, -1);
        $unsure = false;
    } elseif ($lead === 1) {                                      // SURNAME First Middle
        $sur = $words[0];
        $first = array_slice($words, 1);
        $unsure = false;
    } else {                                                      // FIRST MIDDLE Surname
        $sur = implode(' ', array_slice($words, $lead));
        $first = array_slice($words, 0, $lead);
        $unsure = true;
    }
    $surname = $title(preg_split('/\s+/', $sur) ?: []);
    $firsts  = $title($first);
    return [
        'surname'     => $surname,
        'first_names' => $firsts,
        'display'     => strtoupper(trim($firsts . ' ' . $surname)),
        'unsure'      => $unsure,
    ];
}

/**
 * The directory name to keep for a matched person: the list's full name when the
 * directory only had part of it ("LENICE" → "LENICE AUMA NYONGINDI", "ALOICE
 * CHARO - M. ILAI" → "ALOICE CHARO FONDO"), else the directory's own spelling —
 * never a different person's name, and never fewer words than before.
 */
function hr_est_better_name(string $current, array $parsed): string {
    $ct = hr_est_name_tokens($current);
    $lt = hr_est_name_tokens((string)$parsed['display']);
    if ($parsed['display'] === '' || count($lt) <= count($ct)) return $current;
    $sc = hr_est_name_score($ct, $lt);
    return $sc['overlap'] === count($ct) ? (string)$parsed['display'] : $current;
}

/** Payroll number as compared (never stored): upper-case, leading zeros of the number part dropped. MKH032 ≡ MKH32, 03 ≡ 3. */
function hr_est_payroll_key(string $no): string {
    $s = strtoupper((string) preg_replace('/\s+/', '', $no));
    if ($s === '') return '';
    return (string) preg_replace_callback('/\d+/', fn($m) => ltrim($m[0], '0') === '' ? '0' : ltrim($m[0], '0'), $s);
}

// ── Pure: where a list "unit" works ─────────────────────────────────────────

/**
 * One unit word as the lists write it → [venue slugs to try, tidy label].
 * An empty slug list = a unit with no property (office, restaurant, plots,
 * kite school, transfers) — the label is kept on the person.
 */
function hr_est_unit_part(string $part): array {
    $k = strtoupper(trim((string) preg_replace('/\s+/', ' ', $part)));
    return match (true) {
        $k === 'TRIBAL DUNES'                      => [['tribal-dunes', 'maya-kobe'], 'Tribal Dunes'],
        $k === 'MAYA KOBE' || $k === 'MK'          => [['maya-kobe'], 'Maya Kobe'],
        $k === 'MAYA ILAI'                         => [['maya_ilai'], 'Maya Ilai'],
        str_starts_with($k, 'MY AMANI')            => [['my-amani'], 'My Amani'],
        str_starts_with($k, 'ENKARE')              => [['enkare-bofa'], 'Enkare Bofa'],
        str_starts_with($k, 'SANDBOX')             => [['sandbox'], 'Sandbox'],
        str_starts_with($k, 'ZURI')                => [['zuri'], 'Zuri'],
        $k === 'TRIBAL TABLE'                      => [[], 'Tribal Table'],
        str_contains($k, 'OFFICE')                 => [[], 'Watamu office'],
        (bool) preg_match('/PLOT\s*(\d+)/', $k, $m) => [[], 'Watamu - Plot ' . $m[1]],
        str_contains($k, 'KITE')                   => [[], 'Kite school'],
        str_contains($k, 'TRANSFER')               => [[], 'Transfers'],
        default                                    => [[], ucwords(strtolower(trim($part)))],
    };
}

/**
 * Where a list row works: home venue, extra venues, unit label. "A / B / C"
 * means several places: the first one with a property is home, the rest are
 * "also works at". Tribal Dunes covers three properties (Maya Kobe, Maya Ilai,
 * Off Duty): a person already at one of those KEEPS it. Pure: $slugToId maps
 * venue slug → id, $current is the person's present home venue.
 */
function hr_est_unit_plan(string $unit, array $slugToId, ?int $current): array {
    $parts = array_values(array_filter(array_map('trim', explode('/', $unit)), fn($p) => $p !== ''));
    $tdIds = array_values(array_filter(array_map(fn($s) => $slugToId[$s] ?? null, ['tribal-dunes', 'maya-kobe', 'maya_ilai'])));
    $venues = []; $labels = [];
    foreach ($parts as $p) {
        [$slugs, $label] = hr_est_unit_part($p);
        $labels[] = $label;
        if (!$slugs) continue;
        $vid = null;
        if ($label === 'Tribal Dunes' && $current !== null && in_array($current, $tdIds, true)) $vid = $current;
        foreach ($slugs as $s) { if ($vid === null && isset($slugToId[$s])) $vid = (int) $slugToId[$s]; }
        if ($vid !== null && !in_array($vid, $venues, true)) $venues[] = $vid;
    }
    return [
        'venue_id'  => $venues[0] ?? null,
        'extra_ids' => array_slice($venues, 1),
        'unit_label'=> implode(' / ', array_unique($labels)),
    ];
}

// ── Pure: reading a list ────────────────────────────────────────────────────

/**
 * A staff list sheet (rows of string cells) → ['title' => first-row title, 'rows' => [...]].
 * Finds the header row by its "payroll"/"staff no" and "name" cells, then reads
 * payroll no. · name · position (Department / Description column) · unit
 * (the column headed "unit", else the last non-empty column after position).
 * Rows without a payroll number or name are skipped. Throws when no header.
 */
function hr_est_parse_sheet(array $rows): array {
    $rows = array_values($rows);
    $cell = fn(array $r, int $i) => trim((string) preg_replace('/\s+/', ' ', (string) (is_array($r[$i] ?? null) ? ($r[$i]['v'] ?? '') : ($r[$i] ?? ''))));
    $title = '';
    foreach ($rows as $r) { foreach ($r as $i => $_) { if (($t = $cell($r, $i)) !== '') { $title = $t; break 2; } } }
    $hdr = null; $cols = [];
    foreach ($rows as $n => $r) {
        $map = [];
        foreach ($r as $i => $_) {
            $h = strtolower($cell($r, $i));
            if ($h === '') continue;
            if (!isset($map['no'])   && (str_contains($h, 'payroll') || preg_match('/^(staff|emp\w*)\s*no/', $h))) $map['no'] = $i;
            elseif (!isset($map['name']) && str_contains($h, 'name')) $map['name'] = $i;
            elseif (!isset($map['pos'])  && preg_match('/depart|description|position|job|title/', $h)) $map['pos'] = $i;
            elseif (!isset($map['unit']) && preg_match('/unit|property|location|site/', $h)) $map['unit'] = $i;
        }
        if (isset($map['no'], $map['name'])) { $hdr = $n; $cols = $map; break; }
    }
    if ($hdr === null) throw new RuntimeException('No header row found — the list needs a "Payroll number" (or "Staff no.") column and a "Name" column.');
    $out = [];
    foreach (array_slice($rows, $hdr + 1) as $r) {
        $no   = $cell($r, $cols['no']);
        $name = $cell($r, $cols['name']);
        if ($no === '' || $name === '') continue;
        if (preg_match('/^\d+\.0+$/', $no)) $no = (string)(int)$no;   // a number cell read back as "3.0"
        $pos = isset($cols['pos']) ? $cell($r, $cols['pos']) : '';
        // An unlabelled position column sits right after the name (Zuri's list).
        if ($pos === '' && !isset($cols['pos'])) $pos = $cell($r, $cols['name'] + 1);
        $unit = '';
        if (isset($cols['unit'])) $unit = $cell($r, $cols['unit']);
        else {
            $start = max($cols['name'], $cols['pos'] ?? $cols['name'] + 1) + 1;
            for ($i = count($r) - 1; $i >= $start; $i--) {
                $v = $cell($r, $i);
                if ($v !== '' && !preg_match('/^\d+(\.\d+)?$/', $v)) { $unit = $v; break; }
            }
        }
        $out[] = ['payroll_no' => $no, 'name' => $name, 'position' => $pos, 'unit' => $unit];
    }
    return ['title' => $title, 'rows' => $out];
}

// ── Pure: matching a list against the directory ─────────────────────────────

/**
 * Match list rows to existing people. $existing: rows with id, full_name,
 * company_id, payroll_no, status. Returns one entry per row:
 *   ['status' => 'payroll'|'name'|'new'|'decide', 'staff_id' => ?int,
 *    'candidates' => [[id, overlap, contained, note, weak?]], 'why' => string]
 * Weak candidates (one word in common) are only offered — never applied unasked.
 * 'payroll' = already filed under this company + number (a re-run);
 * 'name'    = one clear name match; 'new' = nobody similar;
 * 'decide'  = a person must choose (several possible, or the only candidate
 *             already belongs to another company/number, or two rows want them).
 */
function hr_est_match(array $rows, array $existing, int $companyId): array {
    $byKey = [];
    foreach ($existing as $e) {
        if ((int)($e['company_id'] ?? 0) === $companyId && ($k = hr_est_payroll_key((string)($e['payroll_no'] ?? ''))) !== '') {
            $byKey[$k] = (int)$e['id'];
        }
    }
    $tok = []; $freq = [];
    foreach ($existing as $e) {
        $tok[(int)$e['id']] = hr_est_name_tokens((string)$e['full_name']);
        foreach (array_unique($tok[(int)$e['id']]) as $w) $freq[$w] = ($freq[$w] ?? 0) + 1;
    }
    $res = [];
    foreach ($rows as $i => $r) {
        $key = hr_est_payroll_key((string)$r['payroll_no']);
        if ($key !== '' && isset($byKey[$key])) {
            $res[$i] = ['status' => 'payroll', 'staff_id' => $byKey[$key], 'candidates' => [], 'why' => 'Already filed under this payroll number.'];
            continue;
        }
        $lt = hr_est_name_tokens((string)$r['name']);
        $cands = []; $weak = [];
        foreach ($existing as $e) {
            $et = $tok[(int)$e['id']];
            $sc = hr_est_name_score($lt, $et);
            if ($sc['overlap'] < 1) continue;
            $other = (int)($e['company_id'] ?? 0) > 0 && trim((string)($e['payroll_no'] ?? '')) !== '';
            $c = ['id' => (int)$e['id'], 'overlap' => $sc['overlap'], 'contained' => $sc['contained'],
                  'note' => $other ? 'already filed under another company / number' : ''];
            if ($sc['overlap'] >= 2) { $cands[] = $c; continue; }
            // One word in common: only a POSSIBLE match, offered for a person to pick and never
            // applied on its own — a directory entry that is just a first name ("LENICE"), or a
            // shared word that is rare in the directory (a common one like CHARO means nothing).
            $shared = array_values(array_filter($et, fn($w) => strlen($w) >= 4 && (bool) array_filter($lt, fn($v) => strlen($v) >= 4 && hr_est_word_eq($v, $w))));
            if ((count($et) === 1 && $shared) || ($shared && ($freq[$shared[0]] ?? 0) === 1)) {
                $c['note'] = $c['note'] !== '' ? $c['note'] : 'only one name in common';
                $c['weak'] = true;
                $c['single'] = count($et) === 1;
                $weak[] = $c;
            }
        }
        usort($cands, fn($a, $b) => [$b['contained'], $b['overlap']] <=> [$a['contained'], $a['overlap']]);
        $full = array_values(array_filter($cands, fn($c) => $c['contained']));
        $weak = array_slice($weak, 0, 3);
        if (!$cands && array_filter($weak, fn($c) => $c['single'])) {
            $res[$i] = ['status' => 'decide', 'staff_id' => null, 'candidates' => $weak,
                        'why' => 'The directory has someone listed by one name only — the same person?'];
        } elseif (!$cands) {
            $res[$i] = ['status' => 'new', 'staff_id' => null, 'candidates' => $weak,
                        'why' => $weak ? 'Nobody with this name yet — one possible match is offered.' : 'Nobody with this name yet.'];
        } elseif (count($full) === 1 && $full[0]['note'] === ''
                  && (count($cands) === 1 || $cands[1]['overlap'] < $full[0]['overlap'])) {
            $res[$i] = ['status' => 'name', 'staff_id' => $full[0]['id'], 'candidates' => $cands, 'why' => 'Same name.'];
        } else {
            $res[$i] = ['status' => 'decide', 'staff_id' => null, 'candidates' => array_merge($cands, $weak),
                        'why' => $full && $full[0]['note'] !== '' ? 'The matching person is ' . $full[0]['note'] . '.' : 'More than one possible person, or only part of the name matches.'];
        }
    }
    // Two rows must never land on one person.
    $claims = [];
    foreach ($res as $i => $m) if ($m['staff_id'] !== null) $claims[$m['staff_id']][] = $i;
    foreach ($claims as $sid => $idx) {
        if (count($idx) < 2) continue;
        foreach ($idx as $i) {
            if ($res[$i]['status'] === 'payroll') continue;
            $res[$i]['status'] = 'decide'; $res[$i]['staff_id'] = null; $res[$i]['claimed'] = $sid;
            $res[$i]['why'] = 'Another row in this list matches the same person.';
            if (!$res[$i]['candidates']) $res[$i]['candidates'] = [['id' => $sid, 'overlap' => 0, 'contained' => false, 'note' => '']];
        }
    }
    return $res;
}

/**
 * The decision that applies to a row: posted choice, else the automatic one.
 * Returns ['do' => 'update'|'create'|'skip', 'staff_id' => ?int]. A posted
 * "match:<id>" is honoured only for one of that row's candidates (or the
 * automatic match) — never an arbitrary id. 'decide' rows with no choice skip.
 */
function hr_est_decision(array $match, string $posted): array {
    if ($posted === 'skip') return ['do' => 'skip', 'staff_id' => null];
    if ($posted === 'new')  return ['do' => 'create', 'staff_id' => null];
    if (preg_match('/^match:(\d+)$/', $posted, $m)) {
        $id = (int)$m[1];
        $ok = $id === $match['staff_id'] || in_array($id, array_column($match['candidates'], 'id'), true);
        return $ok ? ['do' => 'update', 'staff_id' => $id] : ['do' => 'skip', 'staff_id' => null];
    }
    return match ($match['status']) {
        'payroll', 'name' => ['do' => 'update', 'staff_id' => $match['staff_id']],
        'new'             => ['do' => 'create', 'staff_id' => null],
        default           => ['do' => 'skip',   'staff_id' => null],
    };
}

// ── DB: companies + scope ───────────────────────────────────────────────────

/** Active companies [id => name] for pickers (empty before add_companies). */
function hr_company_options(): array {
    if (!hr_establishment_supported()) return [];
    try {
        $out = [];
        foreach (db_query('SELECT id, name FROM companies WHERE is_active ORDER BY name')->fetchAll() as $c) $out[(int)$c['id']] = (string)$c['name'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Find a company by name (case/space-insensitive, "Ltd" ≡ "Limited", "TribalSand" ≡ "Tribal Sand"). */
function hr_company_find_by_name(string $name): ?int {
    $norm = fn(string $s) => (string) preg_replace(['/\bLTD\b\.?/', '/[^A-Z0-9]+/'], ['LIMITED', ''], strtoupper($s));
    $want = $norm($name);
    if ($want === '') return null;
    foreach (db_query('SELECT id, name, legal_name FROM companies')->fetchAll() as $c) {
        if ($norm((string)$c['name']) === $want || $norm((string)$c['legal_name']) === $want) return (int)$c['id'];
    }
    return null;
}

/** The companies whose staff an account manages (empty = none, or pre-migration). */
function hr_company_manager_ids(int $adminId): array {
    if ($adminId <= 0 || !hr_establishment_supported()) return [];
    try {
        return array_map('intval', array_column(
            db_query('SELECT company_id FROM hr_company_managers WHERE admin_user_id = :a ORDER BY company_id', [':a' => $adminId])->fetchAll(),
            'company_id'));
    } catch (Throwable $e) { return []; }
}

/** The signed-in account's company grants — what HR pages pass beside admin_venue_ids(). Owner/HR = null (they see everyone anyway). */
function hr_admin_company_ids(): ?array {
    if (admin_venue_ids() === null) return null;
    return hr_company_manager_ids((int)($_SESSION['admin_id'] ?? 0));
}

/** Replace an account's company grants (owner action). Only real companies are kept. */
function hr_set_company_managers(int $adminId, array $companyIds): void {
    if ($adminId <= 0 || !hr_establishment_supported()) return;
    $valid = array_keys(hr_company_options());
    db_query('DELETE FROM hr_company_managers WHERE admin_user_id = :a', [':a' => $adminId]);
    foreach (array_unique(array_map('intval', $companyIds)) as $cid) {
        if (!in_array($cid, $valid, true)) continue;
        db_query('INSERT INTO hr_company_managers (admin_user_id, company_id) VALUES (:a, :c) ON CONFLICT DO NOTHING',
            [':a' => $adminId, ':c' => $cid]);
    }
}

// ── DB: preview + apply ─────────────────────────────────────────────────────

/** Venue slug → id for every venue (published or not — staff work at hidden ones too). */
function hr_est_slug_map(): array {
    $m = [];
    foreach (db_query('SELECT id, slug FROM venues')->fetchAll() as $v) $m[(string)$v['slug']] = (int)$v['id'];
    return $m;
}

/** Everyone in the directory (active and not), for matching. */
function hr_est_existing(): array {
    return db_query('SELECT id, full_name, company_id, payroll_no, status, venue_id, position, unit_label FROM hr_staff ORDER BY id')->fetchAll();
}

/**
 * What an import of $rows into $companyId would do — writes nothing.
 * Returns ['rows' => [row + parsed name + match + plan], 'counts' => [...],
 *          'missing' => existing ACTIVE people of this company not on the list].
 */
function hr_est_preview(int $companyId, array $rows): array {
    $existing = hr_est_existing();
    $byId = [];
    foreach ($existing as $e) $byId[(int)$e['id']] = $e;
    $slugs = hr_est_slug_map();
    $vnames = [];
    foreach (db_query('SELECT id, name FROM venues')->fetchAll() as $v) $vnames[(int)$v['id']] = (string)$v['name'];
    $matches = hr_est_match($rows, $existing, $companyId);
    $out = []; $counts = ['payroll' => 0, 'name' => 0, 'new' => 0, 'decide' => 0];
    $seen = [];
    foreach ($rows as $i => $r) {
        $m = $matches[$i];
        $counts[$m['status']]++;
        $cur = $m['staff_id'] !== null ? $byId[$m['staff_id']] : null;
        if ($m['staff_id'] !== null) $seen[$m['staff_id']] = true;
        foreach ($m['candidates'] as &$c) {
            $e = $byId[$c['id']] ?? null;
            $where = $e ? ($e['venue_id'] !== null ? ($vnames[(int)$e['venue_id']] ?? '') : (string)($e['unit_label'] ?? '')) : '';
            $c['name'] = (string)($e['full_name'] ?? ('#' . $c['id'])) . ($where !== '' ? ' · ' . $where : '') . ($e && ($e['position'] ?? '') !== '' ? ' · ' . $e['position'] : '');
        }
        unset($c);
        $out[$i] = $r + [
            'parsed'  => hr_est_parse_list_name((string)$r['name']),
            'match'   => $m,
            'current' => $cur,
            'new_name'=> $cur ? hr_est_better_name((string)$cur['full_name'], hr_est_parse_list_name((string)$r['name'])) : null,
            'plan'    => hr_est_unit_plan((string)$r['unit'], $slugs, $cur && $cur['venue_id'] !== null ? (int)$cur['venue_id'] : null),
        ];
    }
    $missing = array_values(array_filter($existing, fn($e) => (int)($e['company_id'] ?? 0) === $companyId
        && ($e['status'] ?? '') === 'active' && !isset($seen[(int)$e['id']])));
    return ['rows' => $out, 'counts' => $counts, 'missing' => $missing];
}

/**
 * Apply an import in ONE transaction. $decisions: row index → posted choice
 * ('' = automatic, 'new', 'skip', 'match:<id>'). Updates matched people (company,
 * payroll no., surname / first names, position, department, home venue when the
 * list names one, extra venues ADDED — never removed — and back to active);
 * creates new ones. Returns ['updated','created','skipped'] counts.
 * Re-validates everything from the database — the preview is never trusted.
 */
function hr_est_apply(int $companyId, array $rows, array $decisions, ?int $adminId): array {
    if (!hr_establishment_supported()) throw new RuntimeException('Run the add_hr_staff_establishment migration first.');
    if (!db_query('SELECT 1 FROM companies WHERE id = :c', [':c' => $companyId])->fetchColumn()) throw new RuntimeException('That company no longer exists.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        db_query('LOCK TABLE hr_staff IN SHARE ROW EXCLUSIVE MODE');   // two imports at once would both "create" the same person
        $pv = hr_est_preview($companyId, $rows);
        $n = ['updated' => 0, 'created' => 0, 'skipped' => 0];
        $taken = [];
        $order = (int) db_query('SELECT COALESCE(MAX(sort_order), 0) FROM hr_staff')->fetchColumn();
        foreach ($pv['rows'] as $i => $r) {
            $d = hr_est_decision($r['match'], (string)($decisions[$i] ?? ''));
            if ($d['do'] === 'update' && isset($taken[$d['staff_id']])) $d = ['do' => 'skip', 'staff_id' => null];
            if ($d['do'] === 'skip') { $n['skipped']++; continue; }
            $pos  = trim((string)$r['position']);
            $plan = $r['plan'];
            if ($d['do'] === 'update') {
                $sid = (int)$d['staff_id'];
                $taken[$sid] = true;
                $cur = fetch_hr_staff_row($sid);
                if (!$cur) { $n['skipped']++; continue; }
                // A payroll number already used by SOMEONE ELSE in this company would break the unique key.
                $clash = db_query('SELECT id FROM hr_staff WHERE company_id = :c AND UPPER(payroll_no) = UPPER(:p) AND id <> :id',
                    [':c' => $companyId, ':p' => $r['payroll_no'], ':id' => $sid])->fetchColumn();
                if ($clash) { $n['skipped']++; continue; }
                $venue = $plan['venue_id'] ?? ($cur['venue_id'] !== null ? (int)$cur['venue_id'] : null);
                db_query(
                    "UPDATE hr_staff SET full_name = :n, company_id = :c, payroll_no = :p, surname = :s, first_names = :f,
                            position = COALESCE(NULLIF(:pos, ''), position),
                            department = CASE WHEN :pos2 <> '' THEN :dept ELSE department END,
                            venue_id = :v, unit_label = :u, status = 'active'
                      WHERE id = :id",
                    [':n' => hr_est_better_name((string)$cur['full_name'], $r['parsed']),
                     ':c' => $companyId, ':p' => $r['payroll_no'], ':s' => $r['parsed']['surname'], ':f' => $r['parsed']['first_names'],
                     ':pos' => $pos, ':pos2' => $pos, ':dept' => hr_department($pos), ':v' => $venue, ':u' => $plan['unit_label'], ':id' => $sid]
                );
                $extras = array_values(array_unique(array_merge(hr_staff_venue_ids($sid), $plan['extra_ids'])));
                hr_set_staff_venues($sid, $extras, $venue, array_values(hr_est_slug_map()));
                $n['updated']++;
            } else {
                $order += 10;
                db_query(
                    "INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order,
                                           company_id, payroll_no, surname, first_names)
                     VALUES (:n, :pos, :dept, :v, :u, '', 'active', :so, :c, :p, :s, :f)",
                    [':n' => $r['parsed']['display'] ?: strtoupper((string)$r['name']), ':pos' => $pos, ':dept' => hr_department($pos),
                     ':v' => $plan['venue_id'], ':u' => $plan['unit_label'], ':so' => $order, ':c' => $companyId,
                     ':p' => $r['payroll_no'], ':s' => $r['parsed']['surname'], ':f' => $r['parsed']['first_names']]
                );
                $sid = (int) $pdo->lastInsertId();
                hr_set_staff_venues($sid, $plan['extra_ids'], $plan['venue_id'], array_values(hr_est_slug_map()));
                $n['created']++;
            }
        }
        audit_log('hr.establishment_import', 'company', $companyId,
            "{$n['updated']} updated, {$n['created']} added, {$n['skipped']} skipped");
        if ($own) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** The October 2026 lists shipped with the code (db/seeds/hr_establishment_2026_10.json). */
function hr_est_bundled_lists(): array {
    $f = __DIR__ . '/../db/seeds/hr_establishment_2026_10.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($j['companies'] ?? null) ? $j['companies'] : [];
}

/**
 * Make sure a company row exists for a bundled list (by name, else create it
 * with the list's code). Returns the company id. Owner/HR action only.
 */
function hr_est_ensure_company(string $name, string $code): int {
    if (($id = hr_company_find_by_name($name)) !== null) return $id;
    require_once __DIR__ . '/companies.php';
    [$d, $errors] = company_clean(['name' => $name, 'legal_name' => $name, 'code' => $code]);
    if ($errors) throw new RuntimeException(implode(' ', $errors));
    return company_save(null, $d);
}
