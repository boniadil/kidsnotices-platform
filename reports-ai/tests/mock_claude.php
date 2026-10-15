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
 * "__REFUSE__" in the last user message → stop_reason "refusal".
 */

declare(strict_types=1);

const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];
const CANONICAL = [
    ['عدد رخص البناء المسلمة', 'Autorisations de construire délivrées'],
    ['مشاريع التجزئات العقارية', 'Projets de lotissement'],
    ['مشاريع التجهيزات العمومية', "Projets d'équipements publics"],
    ['المساحة المغطاة (ألف متر مربع)', 'Superficie couverte (milliers de m²)'],
];

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => $status === 400 ? 'invalid_request_error' : 'api_error', 'message' => $message]]);
    exit;
}

function checkSchema(array $s, string $path = '$'): void
{
    if (($s['type'] ?? null) === 'object') {
        $props = array_keys($s['properties'] ?? []);
        $req = $s['required'] ?? [];
        sort($props);
        sort($req);
        if (($s['additionalProperties'] ?? null) !== false || $props !== $req) {
            fail(400, "$path: additionalProperties must be false and all properties required");
        }
        foreach ($s['properties'] as $k => $sub) {
            checkSchema($sub, "$path.$k");
        }
    } elseif (($s['type'] ?? null) === 'array') {
        checkSchema($s['items'] ?? [], "{$path}[]");
    }
}

function textOf(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    return implode("\n", array_map(fn ($b) => is_string($b['content'] ?? null) ? $b['content'] : ($b['text'] ?? ''), $content));
}

// ---- read + validate --------------------------------------------------------------------
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$req = json_decode((string) file_get_contents('php://input'), true) ?: fail(400, 'body must be JSON');
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

// ---- scripted answer ----------------------------------------------------------------------
$last = textOf(end($messages)['content']);
$system = is_string($req['system'] ?? null) ? $req['system'] : '';
$blocks = [];
$stop = 'end_turn';
$schema = $req['output_config']['format']['schema'] ?? null;

if (str_contains($last, '__REFUSE__')) {
    $stop = 'refusal';
} elseif ($schema && isset($schema['properties']['outline'])) {
    $isAr = preg_match('/\p{Arabic}{4,}/u', $last) === 1;
    preg_match_all('/^\s*(\.?\d\.?\s*\D.+)$/mu', $last, $h);
    $blocks[] = ['type' => 'text', 'text' => json_encode(['language' => $isAr ? 'ar' : 'fr', 'outline' => array_map('trim', $h[1]), 'summary' => $isAr ? 'ملخص تجريبي للتقرير.' : 'Résumé de test du rapport.'], JSON_UNESCAPED_UNICODE)];
} elseif ($schema && isset($schema['properties']['facts'])) {
    preg_match('/report year (\d{4})/', $last, $ym);
    $year = (int) ($ym[1] ?? 0);
    $facts = [];
    foreach (preg_split('/^=== page (\d+) ===$/m', $last, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $part) {
        if ($k % 2 === 1) {
            $page = (int) $part;
            continue;
        }
        if (!isset($page)) {
            continue;
        }
        foreach (explode("\n", $part) as $line) {
            // Table rows of the sample reports: a known label and its number on the same line.
            foreach (array_merge(...CANONICAL) as $label) {
                if (str_contains($line, $label) && preg_match('/(\d[\d ]*\d|\d)/u', str_replace($label, '', $line), $num)) {
                    $facts[] = ['indicator' => $label, 'category' => 'permits', 'value' => (float) str_replace(' ', '', $num[1]), 'unit' => '', 'year' => $year, 'scope' => '', 'page' => $page, 'quote' => trim(preg_replace('/\s+/u', ' ', $line))];
                }
            }
        }
    }
    // One invented figure: must be flagged as unverified by the app.
    if (isset($page)) {
        $facts[] = ['indicator' => 'Invented test figure', 'category' => 'other', 'value' => 987654, 'unit' => '', 'year' => $year, 'scope' => '', 'page' => $page, 'quote' => 'invented 987654'];
    }
    $blocks[] = ['type' => 'text', 'text' => json_encode(['facts' => $facts], JSON_UNESCAPED_UNICODE)];
} elseif ($schema && isset($schema['properties']['pages'])) {
    // Lam-alef repair: fix the known words of the samples; tamper with one page to test the safety check.
    $fix = ['االختبار' => 'الاختبار', 'خالل' => 'خلال', 'مجاالت' => 'مجالات', 'االستثمار' => 'الاستثمار', 'ألغراض' => 'لأغراض', 'ألنشطة' => 'لأنشطة'];
    $pages = [];
    foreach (preg_split('/^=== page (\d+) ===$/m', $last, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $part) {
        if ($k % 2 === 1) {
            $no = (int) $part;
        } elseif (isset($no)) {
            $text = strtr(rtrim(preg_replace('#</pages>\s*$#', '', $part)), $fix);
            $pages[] = ['page' => $no, 'text' => $no === 3 ? str_replace('مخالفة', 'مخالفة مزورة 1', $text) : $text];
        }
    }
    $blocks[] = ['type' => 'text', 'text' => json_encode(['pages' => $pages], JSON_UNESCAPED_UNICODE)];
} elseif ($schema && isset($schema['properties']['mappings'])) {
    preg_match('#<catalogue>\s*(.*?)\s*</catalogue>#s', $last, $c);
    preg_match('#<new_names>\s*(.*?)\s*</new_names>#s', $last, $n);
    $catalogue = json_decode($c[1] ?? '[]', true) ?: [];
    $out = [];
    foreach (json_decode($n[1] ?? '[]', true) ?: [] as $item) {
        $pair = [$item['source_name'], $item['source_name']];
        foreach (CANONICAL as $canon) {
            if (in_array($item['source_name'], $canon, true)) {
                $pair = $canon;
            }
        }
        $existing = 0;
        foreach ($catalogue as $ind) {
            if ($ind['name_ar'] === $pair[0]) {
                $existing = (int) $ind['id'];
            }
        }
        $out[] = ['source_name' => $item['source_name'], 'unit' => $item['unit'], 'existing_id' => $existing, 'name_ar' => $pair[0], 'name_fr' => $pair[1], 'canonical_unit' => '', 'category' => $item['category']];
    }
    $blocks[] = ['type' => 'text', 'text' => json_encode(['mappings' => $out], JSON_UNESCAPED_UNICODE)];
} elseif (!empty($req['tools'])) {
    // Tool dialogue: find_indicators → get_series → search_reports → final answer.
    $turns = count(array_filter($messages, fn ($m) => $m['role'] === 'assistant'));
    $calls = [
        ['find_indicators', ['query' => 'رخص البناء']],
        ['get_series', null],
        ['search_reports', ['query' => 'مخالفات التعمير']],
    ];
    if ($turns < count($calls)) {
        [$name, $input] = $calls[$turns];
        if ($name === 'get_series') {
            $found = json_decode(textOf(end($messages)['content']), true);
            $input = ['indicator_id' => (int) ($found[0]['id'] ?? 0)];
        }
        $blocks[] = ['type' => 'thinking', 'thinking' => '', 'signature' => 'mock-signature'];
        $blocks[] = ['type' => 'text', 'text' => 'أبحث في قاعدة المعطيات.'];
        $blocks[] = ['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => $name, 'input' => $input];
        $stop = 'tool_use';
    } else {
        // Find the series result in the conversation and cite it.
        $series = null;
        foreach ($messages as $m) {
            if ($m['role'] === 'user' && is_array($m['content'])) {
                $d = json_decode(textOf($m['content']), true);
                if (isset($d['points'])) {
                    $series = $d;
                }
            }
        }
        $lines = array_map(fn ($p) => "| {$p['year']} | {$p['value']} [{$p['source']['ref']}] |", $series['points'] ?? []);
        $table = "| السنة | القيمة |\n|---|---|\n" . implode("\n", $lines);
        $blocks[] = ['type' => 'text', 'text' => str_contains($system, 'You write official reports')
            ? "# تقرير تجريبي\n\n## 1. رخص البناء\n\nتطورت رخص البناء كما يلي، وبلغت السنة الجديدة 1530 رخصة [new data].\n\n$table\n\n## خلاصة\n\n- **نتيجة** أولى.\n- نتيجة ثانية."
            : "عدد رخص البناء المسلمة حسب السنوات:\n\n$table\n\nالتغير الإجمالي: {$series['stats']['total_change_pct']}%."];
    }
} else {
    $blocks[] = ['type' => 'text', 'text' => 'OK'];
}

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
