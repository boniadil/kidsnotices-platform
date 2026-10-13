<?php

/*
 * Fake Anthropic Messages API for tests — no cost, no network.
 *   php -S 127.0.0.1:9101 tests/mock_claude.php      then  ANTHROPIC_BASE_URL=http://127.0.0.1:9101
 *
 * Validates every request like the real API would for this service:
 *   - messages: non-empty, start with "user", alternate user/assistant, non-empty content
 *   - structured outputs: every object has additionalProperties=false and lists all properties in "required"
 *   - effort value; no disabled thinking / budget_tokens / sampling parameters
 *   - fallbacks: only "default", only on models that accept it, and only with the matching beta header
 * Writes the last request to $MOCK_LOG_DIR/last_request.json (headers + body) for assertions.
 *
 * Markers in the last user message trigger special answers:
 *   __REFUSE__ → stop_reason "refusal"      __TRUNCATE__ → stop_reason "max_tokens"
 *   __RATELIMIT__ → HTTP 429                __APIERR__ → HTTP 500 with a detailed internal message
 * Text answers echo the codes ([S12], [P4]…) found in the request, so name restoration can be tested.
 */

declare(strict_types=1);

const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

function fail(int $status, string $type, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
    exit;
}

function checkSchema(array $schema, string $path = '$'): void
{
    if (($schema['type'] ?? null) === 'object') {
        if (($schema['additionalProperties'] ?? null) !== false) {
            fail(400, 'invalid_request_error', "$path: additionalProperties must be false");
        }
        $props = array_keys($schema['properties'] ?? []);
        $required = $schema['required'] ?? [];
        sort($props);
        sort($required);
        if ($props !== $required) {
            fail(400, 'invalid_request_error', "$path: required must list every property");
        }
        foreach ($schema['properties'] as $name => $sub) {
            checkSchema($sub, "$path.$name");
        }
    } elseif (($schema['type'] ?? null) === 'array') {
        checkSchema($schema['items'] ?? [], "{$path}[]");
    }
}

function fake(array $schema, string $code): mixed
{
    return match ($schema['type'] ?? 'string') {
        'object' => array_map(fn ($p) => fake($p, $code), $schema['properties'] ?? []),
        'array' => [fake($schema['items'], $code)],
        default => isset($schema['enum']) ? $schema['enum'][0] : trim('نص ' . $code),
    };
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) {
    fail(400, 'invalid_request_error', 'body must be JSON');
}
if ($dir = getenv('MOCK_LOG_DIR')) {
    file_put_contents("$dir/last_request.json", json_encode(['headers' => $headers, 'body' => $req], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- validation ---
if (empty($headers['x-api-key']) && empty($headers['authorization'])) {
    fail(401, 'authentication_error', 'missing api key');
}
$model = $req['model'] ?? '';
if (!is_string($model) || !str_starts_with($model, 'claude-')) {
    fail(400, 'invalid_request_error', 'model: invalid');
}
if (!is_int($req['max_tokens'] ?? null) || $req['max_tokens'] < 1) {
    fail(400, 'invalid_request_error', 'max_tokens: required');
}
$messages = $req['messages'] ?? [];
if (!$messages) {
    fail(400, 'invalid_request_error', 'messages: at least one message is required');
}
foreach ($messages as $i => $m) {
    $expected = $i % 2 === 0 ? 'user' : 'assistant';
    if (($m['role'] ?? null) !== $expected) {
        fail(400, 'invalid_request_error', "messages.$i.role: expected $expected");
    }
    if (!is_string($m['content'] ?? null) || trim($m['content']) === '') {
        fail(400, 'invalid_request_error', "messages.$i.content: must be non-empty");
    }
}
if (end($messages)['role'] !== 'user') {
    fail(400, 'invalid_request_error', 'last message must be from the user (prefill is not supported)');
}
foreach (['temperature', 'top_p', 'top_k'] as $param) {
    if (isset($req[$param])) {
        fail(400, 'invalid_request_error', "$param is not supported on this model");
    }
}
if (isset($req['thinking']) && ($req['thinking']['type'] ?? '') !== 'adaptive') {
    fail(400, 'invalid_request_error', 'thinking: only adaptive is supported');
}
$effort = $req['output_config']['effort'] ?? null;
if ($effort !== null && !in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) {
    fail(400, 'invalid_request_error', 'output_config.effort: invalid');
}
if (isset($req['output_config']['format'])) {
    $format = $req['output_config']['format'];
    if (($format['type'] ?? null) !== 'json_schema' || !is_array($format['schema'] ?? null)) {
        fail(400, 'invalid_request_error', 'output_config.format: expected json_schema');
    }
    checkSchema($format['schema']);
}
$beta = $headers['anthropic-beta'] ?? '';
if (array_key_exists('fallbacks', $req)) {
    if ($req['fallbacks'] !== 'default') {
        fail(400, 'invalid_request_error', 'fallbacks: expected "default"');
    }
    if (!str_contains($beta, 'server-side-fallback-2026-07-01')) {
        fail(400, 'invalid_request_error', 'fallbacks requires the server-side-fallback-2026-07-01 beta');
    }
    if (!in_array($model, FALLBACK_MODELS, true)) {
        fail(400, 'invalid_request_error', "fallbacks: not supported for model $model");
    }
}

// --- answer ---
$all = json_encode([$req['system'] ?? '', $messages], JSON_UNESCAPED_UNICODE);
$last = end($messages)['content'];
if (str_contains($last, '__RATELIMIT__')) {
    fail(429, 'rate_limit_error', 'Number of requests has exceeded your rate limit');
}
if (str_contains($last, '__APIERR__')) {
    fail(500, 'api_error', 'internal detail: shard 7 overloaded, org org_SECRET123');
}
preg_match_all('/\[[SPTEN][A-Za-z0-9_-]+\]/', $last, $codes);
$codes = array_values(array_unique($codes[0]));

$stop = 'end_turn';
$content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']];
if (str_contains($last, '__REFUSE__')) {
    $stop = 'refusal';
    $content = [];
} elseif (str_contains($last, '__TRUNCATE__')) {
    $stop = 'max_tokens';
    $content[] = ['type' => 'text', 'text' => '{"answer": "cut'];
} elseif (isset($req['output_config']['format'])) {
    $schema = $req['output_config']['format']['schema'];
    $data = fake($schema, $codes[0] ?? '');
    // Search: point to the first pages offered, plus one invented link that the service must drop.
    if (isset($schema['properties']['related']) && preg_match('#<platform_pages>(.*)</platform_pages>#s', (string) ($req['system'] ?? ''), $m)) {
        $pages = json_decode(trim($m[1]), true) ?: [];
        $data['results'] = array_map(fn ($p) => ['title' => 'x', 'url' => $p['url'], 'snippet' => 'نص'], array_slice($pages, 0, 2));
        $data['results'][] = ['title' => 'invented', 'url' => 'https://evil.example/x', 'snippet' => 'x'];
    }
    $content[] = ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)];
} else {
    $content[] = ['type' => 'text', 'text' => 'جواب تجريبي ' . implode(' ', $codes)];
}

header('Content-Type: application/json');
echo json_encode([
    'id' => 'msg_mock_' . bin2hex(random_bytes(4)),
    'type' => 'message',
    'role' => 'assistant',
    'model' => $model,
    'content' => $content,
    'stop_reason' => $stop,
    'stop_sequence' => null,
    'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
], JSON_UNESCAPED_UNICODE);
