<?php
declare(strict_types=1);
/**
 * AI provider adapter — the ONE file that knows which LLM vendor we call.
 *
 * Everything else (the tool layer in assistant-tools.php, the endpoint in
 * api/assistant.php) is provider-agnostic and talks to this file through a
 * single entry point, chat_with_tools(). Swapping vendor = editing this file.
 *
 * Phase 1 ships the Claude (Anthropic Messages API) backend via raw cURL — the
 * same style every other outbound integration here uses (ghl.php, mail.php,
 * storage.php); this project has no composer/SDK layer, so a raw HTTP call is
 * the consistent choice. openai/gemini are left as explicit "not implemented"
 * branches so the seams are obvious when someone wires a second provider.
 *
 * Guardrails this file enforces:
 *   · READ-ONLY — it never writes anything; it only calls the caller's tool
 *     runner, whose tools are all lookups (see assistant-tools.php).
 *   · Bounded — the agentic loop is capped (AI_MAX_ITERATIONS) so a model that
 *     keeps asking for tools can never run up an unbounded bill.
 *   · Degrade gracefully — with no API key configured the feature reports
 *     unsupported (ai_assistant_supported()) instead of erroring.
 */
require_once __DIR__ . '/db.php'; // parse_env()

const AI_DEFAULT_MODEL         = 'claude-opus-5';
const AI_OPENAI_DEFAULT_MODEL  = 'gpt-4o-mini';   // tool-calling capable, cheap; override with AI_MODEL
const AI_MAX_ITERATIONS  = 6;      // hard cap on tool-call round-trips per request
const AI_MAX_TOKENS      = 2048;   // room for (low-effort) thinking + a short answer; tool results carry the data
const AI_EFFORT          = 'low';  // this is a simple lookup — all correctness is in PHP, so keep thinking cheap
const AI_HTTP_TIMEOUT    = 45;     // seconds per model call

/** The configured provider (lower-case), defaulting to Claude. */
function ai_provider(): string {
    $p = strtolower(trim((string)(parse_env()['AI_PROVIDER'] ?? 'claude')));
    return $p !== '' ? $p : 'claude';
}

/** The configured model id, or the sensible per-provider default. */
function ai_model(): string {
    $m = trim((string)(parse_env()['AI_MODEL'] ?? ''));
    if ($m !== '') return $m;
    return match (ai_provider()) {
        'openai' => AI_OPENAI_DEFAULT_MODEL,
        default  => AI_DEFAULT_MODEL,
    };
}

/** The provider API key, from AI_API_KEY (or the vendor-native var as a fallback). */
function ai_api_key(): string {
    $env = parse_env();
    $k = trim((string)($env['AI_API_KEY'] ?? ''));
    if ($k !== '') return $k;
    // Vendor-native fallbacks so an existing key name still works.
    return match (ai_provider()) {
        'claude' => trim((string)($env['ANTHROPIC_API_KEY'] ?? '')),
        'openai' => trim((string)($env['OPENAI_API_KEY'] ?? '')),
        'gemini' => trim((string)($env['GEMINI_API_KEY'] ?? '')),
        default  => '',
    };
}

/**
 * Is the assistant usable? True only when a provider key is configured. Every
 * surface (nav link, admin page, endpoint) checks this first so a deploy with
 * no key set simply hides the feature rather than 500-ing (NFR4).
 */
function ai_assistant_supported(): bool {
    return ai_api_key() !== '';
}

/**
 * Run an agentic tool-use loop and return the model's final answer.
 *
 * @param string   $system      System prompt (instructions + guardrails).
 * @param array    $messages    Conversation so far: [['role'=>'user'|'assistant','text'=>string], …].
 * @param array    $tools       Tool definitions (assistant_tool_definitions()).
 * @param callable $runTool     fn(string $name, array $args): array — executes a tool, returns JSON-able result.
 * @return array  On success: ['ok'=>true,'answer'=>string,'tool_calls'=>[['name'=>..,'args'=>..,'result'=>..],…],'iterations'=>int].
 *                On failure : ['ok'=>false,'error'=>string].
 */
function chat_with_tools(string $system, array $messages, array $tools, callable $runTool): array {
    if (!ai_assistant_supported()) {
        return ['ok' => false, 'error' => 'The assistant is not configured (no AI key set).'];
    }
    return match (ai_provider()) {
        'claude' => ai_claude_loop($system, $messages, $tools, $runTool),
        'openai' => ai_openai_loop($system, $messages, $tools, $runTool),
        default  => ['ok' => false, 'error' => 'AI provider "' . ai_provider() . '" is not implemented yet. Supported: claude, openai.'],
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// Claude (Anthropic Messages API) backend
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The Anthropic tool-use loop. We resend the full running `messages` array each
 * turn (the API is stateless), appending the assistant's raw content blocks and
 * our tool_result blocks verbatim so any thinking blocks are preserved intact.
 */
function ai_claude_loop(string $system, array $messages, array $tools, callable $runTool): array {
    // Build the initial wire messages from the plain-text history.
    $wire = [];
    foreach ($messages as $m) {
        $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;
        $wire[] = ['role' => $role, 'content' => $text];
    }
    // The API requires the conversation to open on a user turn — drop any
    // leading assistant history (e.g. if a client replayed a greeting first).
    while ($wire && $wire[0]['role'] !== 'user') array_shift($wire);
    if (!$wire) return ['ok' => false, 'error' => 'Nothing to ask.'];

    $claudeTools = array_map(fn(array $t) => [
        'name'         => $t['name'],
        'description'  => $t['description'],
        'input_schema' => $t['input_schema'],
    ], $tools);

    $toolCalls = [];
    for ($i = 0; $i < AI_MAX_ITERATIONS; $i++) {
        $model = ai_model();
        $payload = [
            'model'      => $model,
            'max_tokens' => AI_MAX_TOKENS,
            'system'     => $system,
            'tools'      => $claudeTools,
            'messages'   => $wire,
        ];
        // `effort` is a cheap-thinking knob on the Opus/Sonnet-5/Fable families
        // but 400s on Haiku/older — only send it when the model supports it, so
        // an AI_MODEL swap to a cheaper model doesn't break the whole feature.
        if (stripos($model, 'haiku') === false) {
            $payload['output_config'] = ['effort' => AI_EFFORT];
        }
        $resp = ai_claude_request($payload);
        if (!($resp['ok'] ?? false)) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'The assistant service is unavailable.'];
        }
        $body    = $resp['data'];
        $content = $body['content'] ?? [];
        $stop    = $body['stop_reason'] ?? '';

        // Record the assistant's own turn verbatim (keeps thinking + tool_use blocks intact for replay).
        $wire[] = ['role' => 'assistant', 'content' => $content];

        if ($stop !== 'tool_use') {
            // Final answer — collect the text blocks.
            $answer = '';
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') $answer .= $block['text'];
            }
            $answer = trim($answer);
            if ($stop === 'refusal') {
                $answer = $answer !== '' ? $answer : 'I’m not able to help with that request.';
            }
            return ['ok' => true, 'answer' => $answer, 'tool_calls' => $toolCalls, 'iterations' => $i + 1];
        }

        // The model asked for one or more tools. Run each, gather ALL results
        // into a single user turn (splitting them trains the model to stop
        // calling tools in parallel).
        $results = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            $name = (string)($block['name'] ?? '');
            $args = is_array($block['input'] ?? null) ? $block['input'] : [];
            $out  = $runTool($name, $args);           // read-only wrapper; never throws (it returns ['error'=>…])
            $toolCalls[] = ['name' => $name, 'args' => $args, 'result' => $out];
            $results[] = [
                'type'        => 'tool_result',
                'tool_use_id' => (string)($block['id'] ?? ''),
                'content'     => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'is_error'    => isset($out['error']),
            ];
        }
        if (!$results) {
            // stop_reason said tool_use but we found none — bail rather than loop.
            return ['ok' => false, 'error' => 'The assistant returned an unexpected response.'];
        }
        $wire[] = ['role' => 'user', 'content' => $results];
    }

    return ['ok' => false, 'error' => 'The assistant took too many steps to answer. Please narrow the question.'];
}

/**
 * One POST to the Anthropic Messages API. Returns ['ok'=>bool,'data'=>array,'error'=>?string].
 * Guarded by function_exists so a test harness can pre-define a stub and drive
 * ai_claude_loop() end-to-end without the network (NFR8 — the model is mocked,
 * the loop/wire-shaping is asserted).
 */
if (!function_exists('ai_claude_request')) {
function ai_claude_request(array $payload): array {
    $key = ai_api_key();
    $ch  = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_HTTP_TIMEOUT,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);

    if ($err) return ['ok' => false, 'error' => 'Could not reach the assistant service.'];
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'The assistant service returned an unreadable response.'];
    if ($code >= 300) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        error_log('[ai] Anthropic API error ' . $code . ': ' . $msg);
        // Don't leak the raw provider message to the UI; keep it in the log.
        return ['ok' => false, 'error' => 'The assistant service is temporarily unavailable.'];
    }
    return ['ok' => true, 'data' => $data];
}
}

// ─────────────────────────────────────────────────────────────────────────────
// OpenAI (Chat Completions API) backend
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The OpenAI tool-use loop. Same contract as ai_claude_loop(); only the wire
 * format differs (system is a message, tools are `function` objects, tool
 * results are `role:tool` messages keyed by tool_call_id, args arrive as a JSON
 * string). We append the assistant message verbatim each turn.
 */
function ai_openai_loop(string $system, array $messages, array $tools, callable $runTool): array {
    $wire = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $m) {
        $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;
        $wire[] = ['role' => $role, 'content' => $text];
    }
    if (count($wire) < 2) return ['ok' => false, 'error' => 'Nothing to ask.'];

    $openaiTools = array_map(fn(array $t) => [
        'type'     => 'function',
        'function' => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $t['input_schema'],
        ],
    ], $tools);

    $toolCalls = [];
    for ($i = 0; $i < AI_MAX_ITERATIONS; $i++) {
        $resp = ai_openai_request([
            'model'       => ai_model(),
            'max_tokens'  => AI_MAX_TOKENS,
            'messages'    => $wire,
            'tools'       => $openaiTools,
            'tool_choice' => 'auto',
        ]);
        if (!($resp['ok'] ?? false)) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'The assistant service is unavailable.'];
        }
        $choice  = $resp['data']['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $calls   = $message['tool_calls'] ?? [];

        // Append the assistant turn verbatim (content may be null when it only calls tools).
        $wire[] = $message;

        if (!$calls) {
            $answer = trim((string)($message['content'] ?? ''));
            return ['ok' => true, 'answer' => $answer, 'tool_calls' => $toolCalls, 'iterations' => $i + 1];
        }

        foreach ($calls as $call) {
            $name = (string)($call['function']['name'] ?? '');
            $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
            if (!is_array($args)) $args = [];
            $out  = $runTool($name, $args);
            $toolCalls[] = ['name' => $name, 'args' => $args, 'result' => $out];
            $wire[] = [
                'role'         => 'tool',
                'tool_call_id' => (string)($call['id'] ?? ''),
                'content'      => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    return ['ok' => false, 'error' => 'The assistant took too many steps to answer. Please narrow the question.'];
}

/** One POST to the OpenAI Chat Completions API. Same guard as the Claude one (NFR8). */
if (!function_exists('ai_openai_request')) {
function ai_openai_request(array $payload): array {
    $key = ai_api_key();
    $ch  = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_HTTP_TIMEOUT,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);

    if ($err) return ['ok' => false, 'error' => 'Could not reach the assistant service.'];
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'The assistant service returned an unreadable response.'];
    if ($code >= 300) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        error_log('[ai] OpenAI API error ' . $code . ': ' . $msg);
        return ['ok' => false, 'error' => 'The assistant service is temporarily unavailable.'];
    }
    return ['ok' => true, 'data' => $data];
}
}
