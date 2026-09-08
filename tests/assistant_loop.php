<?php
declare(strict_types=1);
/**
 * Assistant provider-loop tests — the agentic tool-use loop in includes/ai.php.
 * Run: php tests/assistant_loop.php
 *
 * The model is MOCKED (no network, no key, no DB): we pre-define ai_claude_request()
 * before including ai.php (it is guarded by function_exists for exactly this), feed
 * canned turns, and assert the loop's wire-shaping — the tool_use round-trip, the
 * verbatim assistant replay, arg parsing, the final answer, the tool_calls trail,
 * and the iteration cap. This is the piece that can't be exercised live in CI.
 */

putenv('AI_API_KEY=test-key');           // makes ai_assistant_supported() true
$_ENV['AI_API_KEY'] = 'test-key';
putenv('AI_PROVIDER=claude');            // pin to the backend we stub below (env wins over any .env)
$_ENV['AI_PROVIDER'] = 'claude';
putenv('AI_MODEL=claude-opus-5');        // keep the effort-capable default the assertions expect
$_ENV['AI_MODEL'] = 'claude-opus-5';

$GLOBALS['__turns'] = [];                 // queue of canned responses ai_claude_request() returns
$GLOBALS['__seen']  = [];                 // payloads the loop sent

// Pre-defined stub — ai.php's real one is skipped by its function_exists guard.
function ai_claude_request(array $payload): array {
    $GLOBALS['__seen'][] = $payload;
    return array_shift($GLOBALS['__turns']) ?? ['ok' => false, 'error' => 'no more canned turns'];
}

require_once __DIR__ . '/../includes/ai.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function reset_turns(array $turns): void { $GLOBALS['__turns'] = $turns; $GLOBALS['__seen'] = []; }

$tools = [['name' => 'check_availability', 'description' => 'x',
           'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]];

// ── 1) One tool round-trip → final answer ────────────────────────────────────
reset_turns([
    ['ok' => true, 'data' => ['stop_reason' => 'tool_use', 'content' => [
        ['type' => 'text', 'text' => 'Checking.'],
        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'check_availability',
         'input' => ['check_in' => '2099-06-15', 'check_out' => '2099-06-18', 'guests' => 4]],
    ]]],
    ['ok' => true, 'data' => ['stop_reason' => 'end_turn', 'content' => [
        ['type' => 'text', 'text' => 'Zuri is free — $900 total.']]]],
]);
$ran = [];
$run = function (string $n, array $a) use (&$ran): array { $ran[] = [$n, $a]; return ['properties' => []]; };
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'free for 4?']], $tools, $run);

check('ok',                              ($r['ok'] ?? false) === true);
check('final answer surfaced',           str_contains($r['answer'] ?? '', 'Zuri is free'));
check('two model turns consumed',        count($GLOBALS['__seen']) === 2);
check('tool ran once with parsed args',  count($ran) === 1 && ($ran[0][1]['guests'] ?? 0) === 4);
check('tool_calls trail recorded',       ($r['tool_calls'][0]['name'] ?? '') === 'check_availability');
check('iterations == 2',                 ($r['iterations'] ?? 0) === 2);
check('effort sent (opus default)',      isset($GLOBALS['__seen'][0]['output_config']['effort']));
$second = $GLOBALS['__seen'][1]['messages'];
$last = end($second);
check('tool_result posted back',         ($last['role'] ?? '') === 'user'
    && ($last['content'][0]['type'] ?? '') === 'tool_result'
    && ($last['content'][0]['tool_use_id'] ?? '') === 'tu_1'
    && ($last['content'][0]['is_error'] ?? true) === false);
check('assistant turn replayed verbatim', ($second[count($second) - 2]['content'][1]['type'] ?? '') === 'tool_use');

// ── 2) Tool error is flagged to the model (is_error) ─────────────────────────
reset_turns([
    ['ok' => true, 'data' => ['stop_reason' => 'tool_use', 'content' => [
        ['type' => 'tool_use', 'id' => 'tu_e', 'name' => 'check_availability', 'input' => []]]]],
    ['ok' => true, 'data' => ['stop_reason' => 'end_turn', 'content' => [
        ['type' => 'text', 'text' => 'What dates?']]]],
]);
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'is it free?']], $tools,
    fn($n, $a) => ['error' => 'dates required', 'need' => 'dates']);
$last = end($GLOBALS['__seen'][1]['messages']);
check('tool error → is_error true',      ($last['content'][0]['is_error'] ?? false) === true);
check('model can recover after error',   str_contains($r['answer'] ?? '', 'dates'));

// ── 3) Refusal stop_reason → soft, never empty ───────────────────────────────
reset_turns([['ok' => true, 'data' => ['stop_reason' => 'refusal', 'content' => []]]]);
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'do something off-topic']], $tools, fn($n, $a) => []);
check('refusal → ok with a message',     ($r['ok'] ?? false) === true && trim($r['answer'] ?? '') !== '');

// ── 4) Iteration cap: model never stops calling tools ────────────────────────
$loop = [];
for ($i = 0; $i < AI_MAX_ITERATIONS + 2; $i++) {
    $loop[] = ['ok' => true, 'data' => ['stop_reason' => 'tool_use', 'content' => [
        ['type' => 'tool_use', 'id' => 'tu_' . $i, 'name' => 'check_availability', 'input' => []]]]];
}
reset_turns($loop);
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'loop forever']], $tools, fn($n, $a) => ['ok' => 1]);
check('iteration cap → ok:false error',  ($r['ok'] ?? true) === false && isset($r['error']));
check('cap honoured (≤ max calls)',      count($GLOBALS['__seen']) === AI_MAX_ITERATIONS);

// ── 5) Upstream failure is passed through as a soft error ────────────────────
reset_turns([['ok' => false, 'error' => 'The assistant service is temporarily unavailable.']]);
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'hi']], $tools, fn($n, $a) => []);
check('upstream error → ok:false',       ($r['ok'] ?? true) === false && str_contains($r['error'] ?? '', 'unavailable'));

// ── 6) Leading assistant history is dropped (API needs a user-first turn) ────
reset_turns([['ok' => true, 'data' => ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'hello']]]]]);
chat_with_tools('sys', [['role' => 'assistant', 'text' => 'Hi!'], ['role' => 'user', 'text' => 'still there?']], $tools, fn($n, $a) => []);
check('opens on a user turn',            ($GLOBALS['__seen'][0]['messages'][0]['role'] ?? '') === 'user');

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
