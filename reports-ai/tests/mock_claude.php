<?php

/*
 * Fake Anthropic Messages API (streaming) for tests — no cost, no network.
 *   php -S 127.0.0.1:9201 tests/mock_claude.php    then ANTHROPIC_BASE_URL=http://127.0.0.1:9201
 *
 * Validates each request like the real API would for this app:
 *  - messages alternate user/assistant, start with user; every tool_use is answered by a tool_result
 *    with the same id in the next user turn; thinking blocks are passed back first and unchanged;
 *  - structured-output schemas: additionalProperties=false and every property listed in "required";
 *  - "fallbacks" only with the right beta header and only on models that accept it;
 *  - no sampling parameters, no disabled thinking.
 * Scripted answers: describe / facts / mappings (structured outputs) and a tool-use dialogue.
 * Every request is appended to $MOCK_LOG_DIR/requests.jsonl.
 * "__REFUSE__" in the last user message → stop_reason "refusal"; "__BUSY_ONCE__" → one 429 then normal answers;
 * "__BAD_KEY__" → 401; "__STREAM_ERROR__" → an "overloaded" error event in the middle of the stream.
 */

declare(strict_types=1);

require __DIR__ . '/mock_script.php';

const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => $status === 400 ? 'invalid_request_error' : 'api_error', 'message' => $message]]);
    exit;
}

// ---- read + validate --------------------------------------------------------------------
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string) file_get_contents('php://input');
$req = json_decode($raw, true) ?: fail(400, 'body must be JSON');
str_contains($raw, '"input":[]') && fail(400, 'tool_use input must be a JSON object');
if ($dir = getenv('MOCK_LOG_DIR')) {
    file_put_contents("$dir/requests.jsonl", json_encode(['headers' => $headers, 'body' => $req], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
if (empty($headers['x-api-key'])) {
    fail(401, 'missing api key');
}
$model = (string) ($req['model'] ?? '');
if (!str_starts_with($model, 'claude-') || !is_int($req['max_tokens'] ?? null)) {
    fail(400, 'model and max_tokens are required');
}
foreach (['temperature', 'top_p', 'top_k'] as $p) {
    isset($req[$p]) && fail(400, "$p is not supported");
}
if (isset($req['thinking']) && ($req['thinking']['type'] ?? '') !== 'adaptive') {
    fail(400, 'thinking: only adaptive');
}
if (array_key_exists('fallbacks', $req)) {
    if ($req['fallbacks'] !== 'default' || !str_contains($headers['anthropic-beta'] ?? '', 'server-side-fallback-2026-07-01') || !in_array($model, FALLBACK_MODELS, true)) {
        fail(400, "fallbacks not accepted for $model / missing beta header");
    }
}
if (isset($req['output_config']['format'])) {
    checkSchema($req['output_config']['format']['schema'] ?? []);
}
foreach ($req['tools'] ?? [] as $t) {
    if (!isset($t['name'], $t['description'], $t['input_schema'])) {
        fail(400, 'tools: name, description and input_schema are required');
    }
}
$messages = $req['messages'] ?? [];
$pendingToolIds = [];
foreach ($messages as $i => $m) {
    if (($m['role'] ?? '') !== ($i % 2 === 0 ? 'user' : 'assistant')) {
        fail(400, "messages.$i: roles must alternate, starting with user");
    }
    if (is_array($m['content'])) {
        if ($m['role'] === 'assistant') {
            $types = array_column($m['content'], 'type');
            if (in_array('thinking', $types, true) && $types[0] !== 'thinking') {
                fail(400, "messages.$i: thinking blocks must come first");
            }
            foreach ($m['content'] as $b) {
                if ($b['type'] === 'thinking' && ($b['signature'] ?? '') !== 'mock-signature') {
                    fail(400, "messages.$i: thinking block was modified");
                }
                if ($b['type'] === 'tool_use') {
                    $pendingToolIds[] = $b['id'];
                }
            }
        } else {
            $ids = array_column(array_filter($m['content'], fn ($b) => $b['type'] === 'tool_result'), 'tool_use_id');
            sort($ids);
            $expected = $pendingToolIds;
            sort($expected);
            if ($ids !== $expected) {
                fail(400, "messages.$i: every tool_use needs its tool_result in the next user turn");
            }
            $pendingToolIds = [];
        }
    } elseif ($pendingToolIds) {
        fail(400, "messages.$i: tool results missing");
    }
}
if ($pendingToolIds || end($messages)['role'] !== 'user') {
    fail(400, 'last message must be a user turn answering all tool calls');
}

// ---- error scenarios (for the client's retries) -------------------------------------------
$lastText = textOf(end($messages)['content']);
if (str_contains($lastText, '__BUSY_ONCE__') && ($dir = getenv('MOCK_LOG_DIR')) && !is_file("$dir/busy-once")) {
    touch("$dir/busy-once");
    header('retry-after: 0');
    fail(429, 'rate limited');
}
if (str_contains($lastText, '__BAD_KEY__')) {
    fail(401, 'invalid x-api-key');
}
if (str_contains($lastText, '__STREAM_ERROR__')) {
    header('Content-Type: text/event-stream');
    header('retry-after: 0');
    echo "event: message_start\ndata: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_x', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]]) . "\n\n";
    echo "event: error\ndata: " . json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]) . "\n\n";
    exit;
}

// ---- scripted answer ----------------------------------------------------------------------
[$blocks, $stop] = scripted($messages, is_string($req['system'] ?? null) ? $req['system'] : '', $req['output_config']['format']['schema'] ?? null, !empty($req['tools']));

// ---- stream it (SSE) ----------------------------------------------------------------------
header('Content-Type: text/event-stream');
$send = function (string $event, array $data) {
    echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
};
$send('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_mock_' . bin2hex(random_bytes(4)), 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 1, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]]);
foreach ($blocks as $i => $b) {
    if ($b['type'] === 'text') {
        $send('content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']]);
        foreach (mb_str_split($b['text'], 200) as $piece) {
            $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $piece]]);
        }
    } elseif ($b['type'] === 'thinking') {
        $send('content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]);
        $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'signature_delta', 'signature' => $b['signature']]]);
    } else {
        $send('content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => new stdClass()]]);
        $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($b['input'], JSON_UNESCAPED_UNICODE)]]);
    }
    $send('content_block_stop', ['type' => 'content_block_stop', 'index' => $i]);
}
$send('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 200]]);
$send('message_stop', ['type' => 'message_stop']);
