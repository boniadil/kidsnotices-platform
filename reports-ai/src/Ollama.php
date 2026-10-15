<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * A model running on your own machine with Ollama (native /api/chat), directly or behind an
 * access gateway and a tunnel (e.g. Cloudflare Tunnel). No cost per call; quality depends on the model.
 * - responses are streamed (NDJSON) so a tunnel never sees an idle connection on long answers,
 * - structured outputs through Ollama's "format" (JSON schema), tools through "tools",
 * - the context size is sent explicitly (Ollama's default is small and cuts long prompts silently),
 * - raw errors are logged, users get a short message in Arabic.
 */
final class Ollama
{
    private string $model;
    private string $url;

    public function __construct(private readonly string $task)
    {
        $this->model = Config::localModel($task);
        $this->url = rtrim(Config::get('RAI_LOCAL_URL', 'http://127.0.0.1:11434'), '/');
    }

    /** Structured output (JSON schema) → decoded array. */
    public function json(string $system, array $messages, array $schema, int $maxTokens): array
    {
        // Small models follow the format better when the schema is also in the prompt.
        $system .= "\n\nReply with a single JSON object that follows this JSON schema:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE);
        $reply = $this->chat(self::messages($system, $messages), ['format' => $schema], $maxTokens);
        $text = trim($reply['content']);
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/su', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data) || array_diff($schema['required'] ?? [], array_keys($data)) !== []) {
            error_log("[reports-ai] local model {$this->model}: unreadable JSON: " . mb_substr($text, 0, 300));
            throw new UserError('أعاد النموذج المحلي جواباً غير مقروء. حاول من جديد أو استعمل نموذجاً أكبر.');
        }
        return $data;
    }

    /**
     * One chat turn. Returns the assistant message: content and tool calls (Ollama format).
     *
     * @return array{content: string, tool_calls: list<array>}
     */
    public function chat(array $messages, array $extra, int $maxTokens): array
    {
        $context = (int) Config::get('RAI_LOCAL_CONTEXT', '16384');
        // Rough size check (≈3 characters per token for Arabic/French): fail clearly instead of truncating.
        $chars = mb_strlen(json_encode($messages, JSON_UNESCAPED_UNICODE)) + mb_strlen(json_encode($extra['tools'] ?? [], JSON_UNESCAPED_UNICODE));
        if ($chars / 3 > $context * 0.85) {
            throw new UserError('النص أطول من سياق النموذج المحلي. ارفع RAI_LOCAL_CONTEXT أو استعمل Claude لهذه المهمة.');
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
            'options' => ['num_ctx' => $context, 'num_predict' => min($maxTokens, $context)],
        ] + $extra;
        $think = Config::get('RAI_LOCAL_THINK');
        if ($think !== null) {
            // Only when set: models without a thinking mode reject the parameter.
            $body['think'] = in_array(strtolower($think), ['1', 'true', 'yes', 'on'], true);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/x-ndjson'];
        if (($key = Config::get('RAI_LOCAL_KEY')) !== null) {
            $name = Config::get('RAI_LOCAL_KEY_HEADER', 'Authorization');
            $headers[] = strcasecmp($name, 'Authorization') === 0 ? "Authorization: Bearer $key" : "$name: $key";
        }

        $buffer = '';
        $content = '';
        $calls = [];
        $done = null;
        $error = null;
        $line = function (string $raw) use (&$content, &$calls, &$done, &$error): void {
            $chunk = json_decode(trim($raw), true);
            if (!is_array($chunk)) {
                return;
            }
            if (isset($chunk['error'])) {
                $error = (string) $chunk['error'];
                return;
            }
            $content .= $chunk['message']['content'] ?? '';
            foreach ($chunk['message']['tool_calls'] ?? [] as $call) {
                $calls[] = $call;
            }
            if (!empty($chunk['done'])) {
                $done = $chunk;
            }
        };
        $ch = curl_init($this->url . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => (int) Config::get('RAI_LOCAL_TIMEOUT', '900'),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, $line): int {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if (trim($buffer) !== '') {
            $line($buffer);
        }

        if ($ok === false || $status !== 200 || $error !== null || $done === null) {
            error_log("[reports-ai] local model {$this->model} ({$this->url}): HTTP $status curl $errno $curlError " . ($error ?? mb_substr($buffer, 0, 200)));
            throw new UserError(match (true) {
                $errno === CURLE_OPERATION_TIMEDOUT => 'النموذج المحلي بطيء جداً ولم يُكمل الجواب. جرّب طلباً أقصر أو ارفع RAI_LOCAL_TIMEOUT.',
                $ok === false, in_array($status, [502, 503, 504, 520, 521, 522, 523, 524, 530], true) => 'تعذّر الاتصال بالنموذج المحلي. تأكد أن الحاسوب مشغّل وأن Ollama والقناة (tunnel) يعملان، وأن RAI_LOCAL_URL صحيح.',
                $status === 401 || $status === 403 => 'رفضت بوابة النموذج المحلي مفتاح الدخول. تحقق من RAI_LOCAL_KEY.',
                $status === 404 => "النموذج المحلي «{$this->model}» غير موجود. تحقق من RAI_LOCAL_MODEL (الأمر ollama list).",
                default => 'أرجع النموذج المحلي خطأً. حاول لاحقاً.',
            });
        }

        Database::pdo()->prepare('INSERT INTO usage_log (created_at, task, model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd) VALUES (?, ?, ?, ?, ?, 0, 0, 0)')
            ->execute([Database::now(), $this->task, 'local:' . $this->model, (int) ($done['prompt_eval_count'] ?? 0), (int) ($done['eval_count'] ?? 0)]);

        if (($done['done_reason'] ?? '') === 'length') {
            throw new UserError('الجواب طويل جداً وانقطع. جرّب طلباً أضيق أو ارفع RAI_LOCAL_CONTEXT.');
        }
        return ['content' => $content, 'tool_calls' => $calls];
    }

    /** System prompt + text turns in Ollama format. */
    public static function messages(string $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $out[] = ['role' => $m['role'], 'content' => is_string($m['content']) ? $m['content'] : json_encode($m['content'], JSON_UNESCAPED_UNICODE)];
        }
        return $out;
    }

    /** Tool definitions (Tools::definitions) in Ollama format. */
    public static function tools(array $definitions): array
    {
        return array_map(fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema']]], $definitions);
    }
}
