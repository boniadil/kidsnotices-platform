<?php

declare(strict_types=1);

namespace KidsNotices\AI;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Thin wrapper around the Anthropic PHP SDK used by every feature.
 *
 * - The model is chosen per feature (KN_AI_MODEL_<FEATURE>, see Config::modelFor()).
 * - Server-side refusal fallbacks ("default" mode) are sent only to models that accept them;
 *   other models (e.g. Claude Haiku 5.5) would reject every request.
 * - Every call is recorded in usage_log with its estimated cost.
 * - Anthropic's raw error texts are logged, never returned to the caller.
 */
final class Claude
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private Client $client;

    public function __construct(private readonly RequestContext $ctx, ?Client $client = null)
    {
        $this->client = $client ?? new Client(
            apiKey: Config::get('ANTHROPIC_API_KEY'),
            baseUrl: Config::get('ANTHROPIC_BASE_URL'),
        );
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

    private function send(string $system, array $messages, array $outputConfig, int $maxTokens, bool $cache): BetaMessage
    {
        $model = Config::modelFor($this->ctx->feature);
        $fallbacks = in_array($model, self::FALLBACK_MODELS, true);
        if ($this->ctx->privacy !== null) {
            $system .= "\n" . $this->ctx->privacy->systemNote();
        }

        try {
            $response = $this->client->beta->messages->create(
                model: $model,
                maxTokens: $maxTokens,
                system: $system,
                messages: $messages,
                outputConfig: $outputConfig,
                // Caches the conversation prefix; useful for multi-turn chats only.
                cacheControl: $cache ? ['type' => 'ephemeral'] : null,
                fallbacks: $fallbacks ? 'default' : null,
                betas: $fallbacks ? [self::FALLBACK_BETA] : null,
                metadata: ['userID' => hash('sha256', $this->ctx->clientId . ':' . $this->ctx->schoolId)],
            );
        } catch (RateLimitException $e) {
            self::log($e);
            throw new HttpError(503, 'ai_busy', 'The AI service is busy. Please retry in a moment.');
        } catch (AuthenticationException | PermissionDeniedException $e) {
            self::log($e);
            throw new HttpError(500, 'server_misconfigured', 'The AI service is not configured correctly.');
        } catch (APIStatusException $e) {
            self::log($e);
            throw new HttpError(502, 'ai_error', 'The AI provider returned an error. Please retry later.');
        } catch (APIConnectionException $e) {
            self::log($e);
            throw new HttpError(504, 'ai_unreachable', 'The AI provider could not be reached. Please retry later.');
        }

        Usage::record($this->ctx, $response);

        // Always check stop_reason before reading content.
        if ($response->stopReason === 'refusal') {
            throw new HttpError(422, 'ai_refused', 'The AI declined this request. Please rephrase it.');
        }
        if ($response->stopReason === 'max_tokens') {
            throw new HttpError(502, 'ai_truncated', 'The answer was too long and got cut off. Try a smaller request.');
        }
        return $response;
    }

    private function collectText(BetaMessage $response): string
    {
        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return $text;
    }

    private static function log(\Throwable $e): void
    {
        error_log('[kidsnotices-ai] ' . $e::class . ': ' . $e->getMessage());
    }
}
