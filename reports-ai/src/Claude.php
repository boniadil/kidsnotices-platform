<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Claude Messages API over plain curl (no SDK, no Composer).
 * - model chosen per task (Config::model), refusal fallbacks only for models that accept them,
 * - streamed responses (server-sent events) so long answers never hit an HTTP timeout,
 * - retries when the API is busy, usage + estimated cost recorded in usage_log,
 * - Anthropic's raw error text logged, never shown to users.
 *
 * A message is returned as an array: model, stop_reason, content (blocks), usage. Content blocks can
 * be sent back as they are (thinking blocks keep their signature, tool inputs stay JSON objects).
 */
final class Claude
{
    private const VERSION = '2023-06-01';
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];
    private const RETRIES = 2;

    /** USD per million tokens: [input, output, cache write, cache read]. Check anthropic.com/pricing. */
    private const PRICES = [
        'claude-fable-5-1' => [10.00, 50.00, 12.50, 0.25],
        'claude-opus-5-5' => [4.00, 20.00, 5.00, 0.20],
        'claude-opus-5' => [5.00, 25.00, 6.25, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.20],
        'claude-haiku-5-5' => [0.10, 0.50, 0.125, 0.01],
    ];

    private string $model;

    public function __construct(private readonly string $task)
    {
        $this->model = Config::model($task);
    }

    /** Structured output (JSON schema) → decoded array. */
    public function json(string|array $system, array $messages, array $schema, string $effort = 'medium', int $maxTokens = 32000): array
    {
        $response = $this->call($system, $messages, ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]], $maxTokens);
        $data = json_decode(self::text($response), true);
        if (!is_array($data)) {
            throw new UserError('أعاد الذكاء الاصطناعي جواباً غير مقروء. حاول من جديد.');
        }
        return $data;
    }

    /**
     * One turn with tools. Returns the full message (the caller runs the tool loop).
     *
     * @param list<array> $tools tool definitions (name, description, input_schema)
     */
    public function withTools(string|array $system, array $messages, array $tools, string $effort = 'medium', int $maxTokens = 32000): array
    {
        return $this->call($system, $messages, ['effort' => $effort], $maxTokens, $tools);
    }

    public static function text(array $message): string
    {
        $text = '';
        foreach ($message['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return $text;
    }

    private function call(string|array $system, array $messages, array $outputConfig, int $maxTokens, ?array $tools = null): array
    {
        $key = Config::get('ANTHROPIC_API_KEY') ?? throw new UserError('خدمة الذكاء الاصطناعي غير مضبوطة (تحقق من ANTHROPIC_API_KEY).');
        $fallbacks = in_array($this->model, self::FALLBACK_MODELS, true);
        $body = array_filter([
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'output_config' => $outputConfig,
            'tools' => $tools,
            'fallbacks' => $fallbacks ? 'default' : null,
            'stream' => true,
        ], fn ($v) => $v !== null);
        $headers = ['content-type: application/json', 'accept: text/event-stream', "x-api-key: $key", 'anthropic-version: ' . self::VERSION];
        if ($fallbacks) {
            $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
        }

        for ($attempt = 0; ; $attempt++) {
            [$response, $status, $error, $retryAfter] = $this->stream(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            if ($response !== null) {
                break;
            }
            error_log("[reports-ai] Claude API ({$this->model}): HTTP $status $error");
            $busy = $status === 429 || $status === 529 || str_contains($error, 'overloaded');
            if (($busy || $status === 0 || $status >= 500) && $attempt < self::RETRIES) {
                sleep(min(20, $retryAfter ?? 2 ** ($attempt + 1)));
                continue;
            }
            throw new UserError(match (true) {
                $busy => 'خدمة الذكاء الاصطناعي مشغولة حالياً. حاول بعد لحظات.',
                $status === 401 || $status === 403 => 'خدمة الذكاء الاصطناعي غير مضبوطة (تحقق من ANTHROPIC_API_KEY).',
                $status === 0 => 'تعذّر الاتصال بمزوّد الذكاء الاصطناعي. حاول لاحقاً.',
                default => 'أرجع مزوّد الذكاء الاصطناعي خطأً. حاول لاحقاً.',
            });
        }

        $this->recordUsage($response);
        if ($response['stop_reason'] === 'refusal') {
            throw new UserError('رفض الذكاء الاصطناعي هذا الطلب. أعد صياغته.');
        }
        if ($response['stop_reason'] === 'max_tokens') {
            throw new UserError('الجواب طويل جداً وانقطع. جرّب طلباً أضيق.');
        }
        return $response;
    }

    /**
     * Sends the request and rebuilds the message from the event stream.
     *
     * @return array{0: ?array, 1: int, 2: string, 3: ?int} message (null on failure), HTTP status, error text, retry-after seconds
     */
    private function stream(string $body, array $headers): array
    {
        $message = null;
        $blocks = [];
        $json = [];
        $done = false;
        $error = '';
        $raw = '';
        $buffer = '';
        $retryAfter = null;
        $event = function (string $data) use (&$message, &$blocks, &$json, &$done, &$error): void {
            $d = json_decode($data, true);
            if (!is_array($d)) {
                return;
            }
            $i = $d['index'] ?? 0;
            switch ($d['type'] ?? '') {
                case 'message_start':
                    $message = $d['message'];
                    $blocks = [];
                    $json = [];
                    break;
                case 'content_block_start':
                    $blocks[$i] = $d['content_block'];
                    if (in_array($blocks[$i]['type'], ['tool_use', 'server_tool_use'], true)) {
                        $json[$i] = '';
                    }
                    break;
                case 'content_block_delta':
                    $delta = $d['delta'];
                    match ($delta['type']) {
                        'text_delta' => $blocks[$i]['text'] = ($blocks[$i]['text'] ?? '') . $delta['text'],
                        'thinking_delta' => $blocks[$i]['thinking'] = ($blocks[$i]['thinking'] ?? '') . $delta['thinking'],
                        'signature_delta' => $blocks[$i]['signature'] = $delta['signature'],
                        'input_json_delta' => $json[$i] = ($json[$i] ?? '') . $delta['partial_json'],
                        'citations_delta' => $blocks[$i]['citations'][] = $delta['citation'],
                        default => null,
                    };
                    break;
                case 'content_block_stop':
                    if (isset($json[$i])) {
                        // Kept as a JSON object so it goes back unchanged ({} stays {}).
                        $blocks[$i]['input'] = $json[$i] === '' ? new \stdClass() : (json_decode($json[$i]) ?? new \stdClass());
                    }
                    break;
                case 'message_delta':
                    if ($message !== null) {
                        $message['stop_reason'] = $d['delta']['stop_reason'] ?? $message['stop_reason'];
                        $message['usage'] = array_merge($message['usage'] ?? [], array_filter($d['usage'] ?? [], fn ($v) => $v !== null));
                    }
                    break;
                case 'message_stop':
                    $done = true;
                    break;
                case 'error':
                    $error = ($d['error']['type'] ?? '') . ': ' . ($d['error']['message'] ?? '');
                    break;
            }
        };

        $ch = curl_init(rtrim(Config::get('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/') . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => (int) Config::get('RAI_API_TIMEOUT', '900'),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                    $retryAfter = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, &$raw, $event): int {
                if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) {
                    $raw .= $data; // error body (JSON)
                    return strlen($data);
                }
                $buffer .= str_replace("\r\n", "\n", $data);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $chunk = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $lines = array_filter(explode("\n", $chunk), fn ($l) => str_starts_with($l, 'data:'));
                    if ($lines) {
                        $event(implode("\n", array_map(fn ($l) => ltrim(substr($l, 5)), $lines)));
                    }
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            return [null, 0, $curlError, null];
        }
        if ($status !== 200) {
            $e = json_decode($raw, true)['error'] ?? [];
            return [null, $status, ($e['type'] ?? '') . ': ' . ($e['message'] ?? mb_substr($raw, 0, 200)), $retryAfter];
        }
        if ($error !== '' || !$done || $message === null) {
            return [null, str_contains($error, 'overloaded') ? 529 : 500, $error ?: 'stream ended early', $retryAfter];
        }
        ksort($blocks);
        $message['content'] = array_values($blocks);
        return [$message, 200, '', null];
    }

    private function recordUsage(array $response): void
    {
        $u = $response['usage'] ?? [];
        [$in, $out, $write, $read] = self::PRICES[$response['model'] ?? ''] ?? self::PRICES['claude-opus-5-5'];
        $tokens = fn (string $k) => (int) ($u[$k] ?? 0);
        $cost = ($tokens('input_tokens') * $in + $tokens('output_tokens') * $out + $tokens('cache_creation_input_tokens') * $write + $tokens('cache_read_input_tokens') * $read) / 1_000_000;
        Database::pdo()->prepare('INSERT INTO usage_log (created_at, task, model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Database::now(), $this->task, $response['model'] ?? $this->model, $tokens('input_tokens'), $tokens('output_tokens'), $tokens('cache_read_input_tokens'), $tokens('cache_creation_input_tokens'), round($cost, 6)]);
    }
}
