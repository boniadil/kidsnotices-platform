<?php

/*
 * Fake Ollama server (native API, streaming NDJSON) behind an access gateway, for tests.
 *   php -S 127.0.0.1:9202 tests/mock_ollama.php    then RAI_LOCAL_URL=http://127.0.0.1:9202
 *
 * Validates each request like Ollama + the gateway would for this app:
 *  - "Authorization: Bearer test-key" required (401 otherwise), installed models only (404 otherwise);
 *  - stream, num_ctx and num_predict set; the first message is the system prompt;
 *  - every assistant tool call is answered by one "tool" message right after it, arguments as objects.
 * Answers come from the same script as the fake Claude server (tests/mock_script.php).
 * Every request is appended to $MOCK_LOG_DIR/requests.jsonl with "api": "ollama".
 */

declare(strict_types=1);

require __DIR__ . '/mock_script.php';

const MODELS = ['qwen3.5:4b', 'qwen2.5:3b'];

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
if (($headers['authorization'] ?? '') !== 'Bearer test-key') {
    fail(401, 'unauthorized');
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/api/tags') {
    header('Content-Type: application/json');
    echo json_encode(['models' => array_map(fn ($m) => ['name' => $m, 'model' => $m], MODELS)]);
    exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/chat') {
    fail(404, 'not found');
}
$raw = (string) file_get_contents('php://input');
$req = json_decode($raw, true) ?: fail(400, 'body must be JSON');
str_contains($raw, '"arguments":[]') && fail(400, 'tool call arguments must be a JSON object');
if ($dir = getenv('MOCK_LOG_DIR')) {
    file_put_contents("$dir/requests.jsonl", json_encode(['api' => 'ollama', 'headers' => $headers, 'body' => $req], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
if (!in_array($req['model'] ?? '', MODELS, true)) {
    fail(404, "model '" . ($req['model'] ?? '') . "' not found");
}
if (($req['stream'] ?? null) !== true || !is_int($req['options']['num_ctx'] ?? null) || !is_int($req['options']['num_predict'] ?? null)) {
    fail(400, 'stream, options.num_ctx and options.num_predict are expected');
}
if (isset($req['format'])) {
    checkSchema($req['format']);
}
foreach ($req['tools'] ?? [] as $t) {
    if (($t['type'] ?? '') !== 'function' || !isset($t['function']['name'], $t['function']['description'], $t['function']['parameters'])) {
        fail(400, 'tools: type function with name, description and parameters');
    }
}

// Ollama conversation → Anthropic shape for the shared script.
$messages = $req['messages'] ?? [];
if (($messages[0]['role'] ?? '') !== 'system') {
    fail(400, 'first message must be the system prompt');
}
$system = (string) array_shift($messages)['content'];
$conv = [];
$pending = [];
foreach ($messages as $i => $m) {
    if ($m['role'] === 'tool') {
        $id = array_shift($pending) ?? fail(400, "messages.$i: tool message without a call");
        if (end($conv)['role'] !== 'user') {
            $conv[] = ['role' => 'user', 'content' => []];
        }
        $conv[count($conv) - 1]['content'][] = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => (string) $m['content']];
        continue;
    }
    $pending && fail(400, "messages.$i: every tool call needs its tool message first");
    if ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
        $content = [['type' => 'text', 'text' => (string) $m['content']]];
        foreach ($m['tool_calls'] as $k => $call) {
            $content[] = ['type' => 'tool_use', 'id' => $pending[] = "call_{$i}_$k", 'name' => $call['function']['name'], 'input' => $call['function']['arguments']];
        }
        $conv[] = ['role' => 'assistant', 'content' => $content];
    } else {
        $conv[] = ['role' => $m['role'], 'content' => (string) $m['content']];
    }
}
$pending && fail(400, 'tool calls left unanswered');

[$blocks] = scripted($conv, $system, $req['format'] ?? null, !empty($req['tools']));
$text = implode('', array_map(fn ($b) => $b['text'], array_filter($blocks, fn ($b) => $b['type'] === 'text')));
$calls = array_map(fn ($b) => ['function' => ['name' => $b['name'], 'arguments' => $b['input']]], array_values(array_filter($blocks, fn ($b) => $b['type'] === 'tool_use')));

header('Content-Type: application/x-ndjson');
$send = fn (array $chunk) => print(json_encode(['model' => $req['model'], 'created_at' => gmdate('c')] + $chunk, JSON_UNESCAPED_UNICODE) . "\n");
foreach (mb_str_split($text, 200) as $piece) {
    $send(['message' => ['role' => 'assistant', 'content' => $piece], 'done' => false]);
}
if ($calls) {
    $send(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls], 'done' => false]);
}
$send(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 900, 'eval_count' => 150]);
