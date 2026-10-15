<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Claude Messages API over plain curl (no SDK, no Composer), used by every feature.
 *
 * - The model is chosen per feature (KN_AI_MODEL_<FEATURE>, see Config::modelFor()).
 * - Server-side refusal fallbacks ("default" mode) are sent only to models that accept them;
 *   other models (e.g. Claude Haiku 5.5) would reject every request.
 * - Every call is recorded in usage_log with its estimated cost.
 * - Busy or failing API calls are retried twice (honouring retry-after).
 * - Anthropic's raw error texts are logged, never returned to the caller.
 */
final class Claude
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private const VERSION = '2023-06-01';
    private const RETRIES = 2;

    public function __construct(private readonly RequestContext $ctx)
    {
    }

    /**
     * Free-text answer.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function text(string $system, array $messages, string $effort = 'low', int $maxTokens = 4000, bool $cache = false): string
    {
        $response = $this->send($system, $messages, ['effort' => $effort], $maxTokens, $cache);
        return trim($this->collectText($response));
    }

    /**
     * Answer constrained to a JSON schema (structured outputs). Returns the decoded array.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function json(string $system, array $messages, array $schema, string $effort = 'medium', int $maxTokens = 16000): array
    {
        $response = $this->send(
            $system,
            $messages,
            ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            $maxTokens,
            false,
        );
        $data = json_decode($this->collectText($response), true);
        if (!is_array($data)) {
            throw new HttpError(502, 'invalid_model_output', 'The AI returned an unreadable answer. Please retry.');
        }
        return $data;
    }

    private function send(string $system, array $messages, array $outputConfig, int $maxTokens, bool $cache): array
    {
        $model = Config::modelFor($this->ctx->feature);
        $fallbacks = in_array($model, self::FALLBACK_MODELS, true);
        if ($this->ctx->privacy !== null) {
            $system .= "\n" . $this->ctx->privacy->systemNote();
        }
        $body = array_filter([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'output_config' => $outputConfig,
            // Caches the conversation prefix; useful for multi-turn chats only.
            'cache_control' => $cache ? ['type' => 'ephemeral'] : null,
            'fallbacks' => $fallbacks ? 'default' : null,
            'metadata' => ['user_id' => hash('sha256', $this->ctx->clientId . ':' . $this->ctx->schoolId)],
        ], fn ($v) => $v !== null);
        $headers = ['content-type: application/json', 'x-api-key: ' . Config::get('ANTHROPIC_API_KEY', ''), 'anthropic-version: ' . self::VERSION];
        if ($fallbacks) {
            $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
        }

        for ($attempt = 0; ; $attempt++) {
            [$status, $raw, $curlError, $retryAfter] = self::post(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
            $response = $status === 200 ? json_decode($raw, true) : null;
            if (is_array($response) && isset($response['content'])) {
                break;
            }
            $error = json_decode($raw, true)['error'] ?? [];
            error_log("[kidsnotices-ai] Claude API ($model): HTTP $status $curlError " . ($error['type'] ?? '') . ': ' . ($error['message'] ?? mb_substr($raw, 0, 200)));
            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if ($retryable && $attempt < self::RETRIES) {
                sleep(min(20, $retryAfter ?? 2 ** ($attempt + 1)));
                continue;
            }
            throw match (true) {
                $status === 429 || $status === 529 => new HttpError(503, 'ai_busy', 'The AI service is busy. Please retry in a moment.'),
                $status === 401 || $status === 403 => new HttpError(500, 'server_misconfigured', 'The AI service is not configured correctly.'),
                $status === 0 => new HttpError(504, 'ai_unreachable', 'The AI provider could not be reached. Please retry later.'),
                default => new HttpError(502, 'ai_error', 'The AI provider returned an error. Please retry later.'),
            };
        }

        Usage::record($this->ctx, $response);

        // Always check stop_reason before reading content.
        if ($response['stop_reason'] === 'refusal') {
            throw new HttpError(422, 'ai_refused', 'The AI declined this request. Please rephrase it.');
        }
        if ($response['stop_reason'] === 'max_tokens') {
            throw new HttpError(502, 'ai_truncated', 'The answer was too long and got cut off. Try a smaller request.');
        }
        return $response;
    }

    /** @return array{0: int, 1: string, 2: string, 3: ?int} HTTP status (0 = no connection), body, curl error, retry-after */
    private static function post(string $body, array $headers): array
    {
        $retryAfter = null;
        $ch = curl_init(rtrim(Config::get('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/') . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) Config::get('KN_AI_API_TIMEOUT', '300'),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                    $retryAfter = (int) $m[1];
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$status, (string) $raw, $error, $retryAfter];
    }

    private function collectText(array $response): string
    {
        $text = '';
        foreach ($response['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return $text;
    }
}
