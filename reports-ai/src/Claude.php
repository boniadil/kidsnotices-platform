<?php

declare(strict_types=1);

namespace ReportsAI;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;

/**
 * Thin wrapper around the Anthropic PHP SDK.
 * - model chosen per task (Config::model), refusal fallbacks only for models that accept them,
 * - streaming for long outputs, usage + estimated cost recorded in usage_log,
 * - Anthropic's raw error text logged, never shown to users.
 */
final class Claude
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    /** USD per million tokens: [input, output, cache write, cache read]. Check anthropic.com/pricing. */
    private const PRICES = [
        'claude-fable-5-1' => [10.00, 50.00, 12.50, 0.25],
        'claude-opus-5-5' => [4.00, 20.00, 5.00, 0.20],
        'claude-opus-5' => [5.00, 25.00, 6.25, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.20],
        'claude-haiku-5-5' => [0.10, 0.50, 0.125, 0.01],
    ];

    private Client $client;
    private string $model;

    public function __construct(private readonly string $task, ?Client $client = null)
    {
        $this->model = Config::model($task);
        $this->client = $client ?? new Client(
            apiKey: Config::get('ANTHROPIC_API_KEY'),
            baseUrl: Config::get('ANTHROPIC_BASE_URL'),
        );
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
     * @param list<array> $tools
     */
    public function withTools(string|array $system, array $messages, array $tools, string $effort = 'medium', int $maxTokens = 32000): BetaMessage
    {
        return $this->call($system, $messages, ['effort' => $effort], $maxTokens, $tools);
    }

    public static function text(BetaMessage $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return $text;
    }

    private function call(string|array $system, array $messages, array $outputConfig, int $maxTokens, ?array $tools = null): BetaMessage
    {
        $fallbacks = in_array($this->model, self::FALLBACK_MODELS, true);
        $params = [
            'model' => $this->model,
            'maxTokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'outputConfig' => $outputConfig,
            'tools' => $tools,
            'fallbacks' => $fallbacks ? 'default' : null,
            'betas' => $fallbacks ? [self::FALLBACK_BETA] : null,
        ];

        try {
            // Streaming avoids HTTP timeouts on long answers (reports, large extractions).
            $accumulator = MessageAccumulator::forBetaMessages();
            foreach ($this->client->beta->messages->createStream(...$params) as $event) {
                $accumulator->accumulate($event);
            }
            $response = $accumulator->message();
        } catch (RateLimitException $e) {
            self::log($e);
            throw new UserError('خدمة الذكاء الاصطناعي مشغولة حالياً. حاول بعد لحظات.');
        } catch (AuthenticationException | PermissionDeniedException $e) {
            self::log($e);
            throw new UserError('خدمة الذكاء الاصطناعي غير مضبوطة (تحقق من ANTHROPIC_API_KEY).');
        } catch (APIStatusException $e) {
            self::log($e);
            throw new UserError('أرجع مزوّد الذكاء الاصطناعي خطأً. حاول لاحقاً.');
        } catch (APIConnectionException $e) {
            self::log($e);
            throw new UserError('تعذّر الاتصال بمزوّد الذكاء الاصطناعي. حاول لاحقاً.');
        }

        $this->recordUsage($response);

        if ($response->stopReason === 'refusal') {
            throw new UserError('رفض الذكاء الاصطناعي هذا الطلب. أعد صياغته.');
        }
        if ($response->stopReason === 'max_tokens') {
            throw new UserError('الجواب طويل جداً وانقطع. جرّب طلباً أضيق.');
        }
        return $response;
    }

    private function recordUsage(BetaMessage $response): void
    {
        $u = $response->usage;
        [$in, $out, $write, $read] = self::PRICES[$response->model] ?? self::PRICES['claude-opus-5-5'];
        $cost = ($u->inputTokens * $in + $u->outputTokens * $out + ($u->cacheCreationInputTokens ?? 0) * $write + ($u->cacheReadInputTokens ?? 0) * $read) / 1_000_000;
        Database::pdo()->prepare('INSERT INTO usage_log (created_at, task, model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Database::now(), $this->task, $response->model, $u->inputTokens, $u->outputTokens, $u->cacheReadInputTokens ?? 0, $u->cacheCreationInputTokens ?? 0, round($cost, 6)]);
    }

    private static function log(\Throwable $e): void
    {
        error_log('[reports-ai] ' . $e::class . ': ' . $e->getMessage());
    }
}
