<?php
declare(strict_types=1);
// Passport photo reading — pure clean-up of the AI's reply, ICAO MRZ check digits,
// and the vision request's wire shape (the model call is stubbed — no network).
// Run: php tests/checkin_passport_read_logic.php

// Stub the provider call BEFORE includes/ai.php defines the real one.
$GLOBALS['__stub_payload'] = null;
$GLOBALS['__stub_reply']   = null;
function ai_claude_request(array $payload): array {
    $GLOBALS['__stub_payload'] = $payload;
    return $GLOBALS['__stub_reply'] ?? ['ok' => false, 'error' => 'stub'];
}
function ai_openai_request(array $payload): array {
    $GLOBALS['__stub_payload'] = $payload;
    return $GLOBALS['__stub_reply'] ?? ['ok' => false, 'error' => 'stub'];
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/checkin.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── ICAO 9303 check digits (specimen passport "UTOPIA", L898902C3) ──────────
check('check digit: L898902C3 → 6',  checkin_mrz_check_digit('L898902C3') === 6);
check('check digit: 740812 → 2',     checkin_mrz_check_digit('740812') === 2);
check('check digit: 120415 → 9',     checkin_mrz_check_digit('120415') === 9);
check('check digit: filler < = 0',   checkin_mrz_check_digit('<<<') === 0);

$specimen = 'L898902C36UTO7408122F1204159ZE184226B<<<<<10';
$m = checkin_mrz_line2($specimen);
check('mrz: parses 44-char line',     is_array($m));
check('mrz: number',                  ($m['number'] ?? '') === 'L898902C3');
check('mrz: number check ok',         ($m['number_ok'] ?? false) === true);
check('mrz: nationality code',        ($m['nat'] ?? '') === 'UTO');
check('mrz: expiry as Y-m-d',         ($m['expiry'] ?? '') === '2012-04-15');
check('mrz: expiry check ok',         ($m['expiry_ok'] ?? false) === true);
check('mrz: spaces tolerated',        is_array(checkin_mrz_line2(' L898902C36 UTO7408122F1204159ZE184226B<<<<<10 ')));
check('mrz: wrong length → null',     checkin_mrz_line2('L898902C36UTO') === null);
check('mrz: bad char → null',         checkin_mrz_line2(str_replace('B', '#', $specimen)) === null);
$bad = checkin_mrz_line2('L898902C35UTO7408122F1204159ZE184226B<<<<<10');   // 5 ≠ 6
check('mrz: bad number digit flagged', ($bad['number_ok'] ?? true) === false);

// ── Nationality names ───────────────────────────────────────────────────────
check('nat: KEN → Kenya',             checkin_nationality_name('KEN') === 'Kenya');
check('nat: D (German MRZ) → Germany', checkin_nationality_name('D<<') === 'Germany');
check('nat: gbr lower-case',          checkin_nationality_name('gbr') === 'United Kingdom');
check('nat: unknown → empty',         checkin_nationality_name('XYZ') === '');

// ── Clean-up of the AI reply ────────────────────────────────────────────────
$today = '2026-10-09';
$r = checkin_passport_from_ai([
    'is_passport' => true, 'given_names' => 'ANNA MARIA', 'surname' => 'ROSSI',
    'passport_number' => 'ya 1234567', 'nationality' => 'Italy', 'nationality_code' => 'ITA',
    'date_of_expiry' => '2031-05-20', 'mrz_line2' => '',
], $today);
check('ai: name title-cased',         ($r['fields']['passport_name'] ?? '') === 'Anna Maria Rossi');
check('ai: number upper alnum',       ($r['fields']['passport_number'] ?? '') === 'YA1234567');
check('ai: nationality kept',         ($r['fields']['nationality'] ?? '') === 'Italy');
check('ai: expiry kept',              ($r['fields']['passport_expiry'] ?? '') === '2031-05-20');
check('ai: no warnings',              $r['warnings'] === []);

$r = checkin_passport_from_ai(['is_passport' => true, 'surname' => "O'NEILL", 'given_names' => 'SEAN',
    'nationality' => '', 'nationality_code' => 'IRL', 'date_of_expiry' => '2031-02-30'], $today);
check('ai: apostrophe name',          ($r['fields']['passport_name'] ?? '') === "Sean O'Neill");
check('ai: nationality from code',    ($r['fields']['nationality'] ?? '') === 'Ireland');
check('ai: impossible date dropped',  !isset($r['fields']['passport_expiry']));
check('ai: missing number not set',   !isset($r['fields']['passport_number']));

// MRZ agrees → number kept; MRZ expiry wins
$r = checkin_passport_from_ai(['is_passport' => true, 'passport_number' => 'L898902C3',
    'date_of_expiry' => '2012-04-16', 'mrz_line2' => $specimen], '2010-01-01');
check('mrz: agreeing number kept',    ($r['fields']['passport_number'] ?? '') === 'L898902C3');
check('mrz: verified expiry wins',    ($r['fields']['passport_expiry'] ?? '') === '2012-04-15');

// MRZ disagrees (AI misread a character) → the check-digit-verified MRZ number wins
$r = checkin_passport_from_ai(['is_passport' => true, 'passport_number' => 'L898902O3',
    'mrz_line2' => $specimen], '2010-01-01');
check('mrz: verified number overrides AI', ($r['fields']['passport_number'] ?? '') === 'L898902C3');

// MRZ check digit fails → number dropped, guest types it
$r = checkin_passport_from_ai(['is_passport' => true, 'passport_number' => 'L898902C3',
    'mrz_line2' => 'L898902C35UTO7408122F1204159ZE184226B<<<<<10'], '2010-01-01');
check('mrz: failed check drops number', !isset($r['fields']['passport_number']));

// Expired passport → warning, field still filled
$r = checkin_passport_from_ai(['is_passport' => true, 'date_of_expiry' => '2025-01-01'], $today);
check('expired: warning',             in_array('This passport has expired.', $r['warnings'], true));
check('expired: date still filled',   ($r['fields']['passport_expiry'] ?? '') === '2025-01-01');

// Not a passport → nothing filled
$r = checkin_passport_from_ai(['is_passport' => false, 'surname' => 'X', 'passport_number' => '12345'], $today);
check('not a passport: no fields',    $r['fields'] === []);
check('not a passport: warning',      count($r['warnings']) === 1);

// Junk number lengths are refused
$r = checkin_passport_from_ai(['is_passport' => true, 'passport_number' => '12'], $today);
check('short number refused',         !isset($r['fields']['passport_number']));

// ── JSON pulled out of model text ───────────────────────────────────────────
check('json: fenced block',           ai_json_from_text("```json\n{\"a\":1}\n```") === ['a' => 1]);
check('json: prose around object',    ai_json_from_text('Here: {"a":"b"} done') === ['a' => 'b']);
check('json: garbage → null',         ai_json_from_text('no object here') === null);
check('json: array is not an object', ai_json_from_text('[1,2]') === null);

// ── Vision request wire shape (pure builders — always run) ──────────────────
$p   = ai_vision_claude_payload('IMGBYTES', 'image/jpeg', 'Read it', 'claude-opus-5');
$blk = $p['messages'][0]['content'] ?? [];
check('claude: one user message',     count($p['messages'] ?? []) === 1 && ($p['messages'][0]['role'] ?? '') === 'user');
check('claude: image block first',    ($blk[0]['type'] ?? '') === 'image');
check('claude: base64 data',          ($blk[0]['source']['data'] ?? '') === base64_encode('IMGBYTES'));
check('claude: media type',           ($blk[0]['source']['media_type'] ?? '') === 'image/jpeg');
check('claude: instruction text',     ($blk[1]['text'] ?? '') === 'Read it');
check('claude: no tools sent',        !isset($p['tools']));
check('claude: low effort (opus)',    ($p['output_config']['effort'] ?? '') === 'low');
check('claude: no effort on haiku',   !isset(ai_vision_claude_payload('X', 'image/png', 'R', 'claude-haiku-5-5')['output_config']));

$p   = ai_vision_openai_payload('IMGBYTES', 'image/png', 'Read it', 'gpt-4o-mini');
$blk = $p['messages'][0]['content'] ?? [];
check('openai: data URL image',       ($blk[1]['image_url']['url'] ?? '') === 'data:image/png;base64,' . base64_encode('IMGBYTES'));
check('openai: json mode',            ($p['response_format']['type'] ?? '') === 'json_object');

check('reply text: claude blocks',    ai_vision_reply_text('claude', ['content' => [['type' => 'text', 'text' => 'a'], ['type' => 'thinking'], ['type' => 'text', 'text' => 'b']]]) === 'ab');
check('reply text: openai choice',    ai_vision_reply_text('openai', ['choices' => [['message' => ['content' => '{"x":1}']]]]) === '{"x":1}');

// ── End to end through the stubbed request (only when a key is configured) ──
if (ai_assistant_supported() && in_array(ai_provider(), ['claude', 'openai'], true)) {
    $GLOBALS['__stub_reply'] = ['ok' => true, 'data' => ai_provider() === 'claude'
        ? ['content' => [['type' => 'text', 'text' => "```json\n{\"is_passport\":true,\"surname\":\"ROSSI\"}\n```"]]]
        : ['choices' => [['message' => ['content' => '{"is_passport":true,"surname":"ROSSI"}']]]]];
    $out = ai_read_image_json('IMGBYTES', 'image/jpeg', 'Read it');
    check('vision: decoded reply',        ($out['surname'] ?? '') === 'ROSSI');
    check('vision: request was sent',     is_array($GLOBALS['__stub_payload']));
    $GLOBALS['__stub_reply'] = ['ok' => false, 'error' => 'down'];
    check('vision: failure → null',       ai_read_image_json('X', 'image/png', 'Read it') === null);
} else {
    echo "SKIP  vision end-to-end (no AI key in .env)\n";
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
