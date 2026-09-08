<?php
declare(strict_types=1);
/**
 * Assistant provider-loop tests — the OpenAI (Chat Completions) backend.
 * Run: php tests/assistant_loop_openai.php
 *
 * Separate file from assistant_loop.php because parse_env() memoizes on first
 * call, so a single process can only exercise one AI_PROVIDER. The model is
 * MOCKED (ai_openai_request pre-defined before ai.php via its function_exists
 * guard); we assert the OpenAI wire-shaping — tools as `function` objects, the
 * tool_calls round-trip, JSON-string argument parsing, and role:tool results.
 */

putenv('AI_PROVIDER=openai');  $_ENV['AI_PROVIDER'] = 'openai';
putenv('AI_API_KEY=test-key'); $_ENV['AI_API_KEY']  = 'test-key';
putenv('AI_MODEL=gpt-4o-mini');$_ENV['AI_MODEL']    = 'gpt-4o-mini';

$GLOBALS['__oturns'] = [];
$GLOBALS['__oseen']  = [];
function ai_openai_request(array $payload): array {
    $GLOBALS['__oseen'][] = $payload;
    return array_shift($GLOBALS['__oturns']) ?? ['ok' => false, 'error' => 'no more canned turns'];
}

require_once __DIR__ . '/../includes/ai.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

check('provider is openai',              ai_provider() === 'openai');
check('model default is gpt-4o-mini',    ai_model() === 'gpt-4o-mini');

$tools = [['name' => 'check_availability', 'description' => 'x',
           'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []]]];

// One tool round-trip → final answer.
$GLOBALS['__oturns'] = [
    ['ok' => true, 'data' => ['choices' => [['finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'check_availability',
                'arguments' => '{"check_in":"2099-06-15","check_out":"2099-06-18","guests":4}']]],
    ]]]]],
    ['ok' => true, 'data' => ['choices' => [['finish_reason' => 'stop', 'message' => [
        'role' => 'assistant', 'content' => 'Zuri is free — 90,000 KES total.']]]]],
];
$ran = [];
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'free for 4?']], $tools,
    function (string $n, array $a) use (&$ran): array { $ran[] = [$n, $a]; return ['properties' => []]; });

check('ok + final answer',               ($r['ok'] ?? false) === true && str_contains($r['answer'] ?? '', 'Zuri is free'));
check('two turns consumed',              count($GLOBALS['__oseen']) === 2);
check('args JSON-string parsed',         ($ran[0][1]['guests'] ?? 0) === 4);
check('tool_calls trail recorded',       ($r['tool_calls'][0]['name'] ?? '') === 'check_availability');
check('tools sent as function objects',  ($GLOBALS['__oseen'][0]['tools'][0]['type'] ?? '') === 'function'
    && ($GLOBALS['__oseen'][0]['tools'][0]['function']['name'] ?? '') === 'check_availability');
check('tool_choice auto',                ($GLOBALS['__oseen'][0]['tool_choice'] ?? '') === 'auto');
check('system is first message',         ($GLOBALS['__oseen'][0]['messages'][0]['role'] ?? '') === 'system');
$last = end($GLOBALS['__oseen'][1]['messages']);
check('role:tool result posted back',    ($last['role'] ?? '') === 'tool' && ($last['tool_call_id'] ?? '') === 'call_1');

// Upstream failure passes through as a soft error.
$GLOBALS['__oturns'] = [['ok' => false, 'error' => 'The assistant service is temporarily unavailable.']];
$GLOBALS['__oseen']  = [];
$r = chat_with_tools('sys', [['role' => 'user', 'text' => 'hi']], $tools, fn($n, $a) => []);
check('upstream error → ok:false',       ($r['ok'] ?? true) === false && str_contains($r['error'] ?? '', 'unavailable'));

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
