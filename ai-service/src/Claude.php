<?php

declare(strict_types=1);

namespace KidsNotices\AI;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Thin wrapper around the Anthropic PHP SDK used by every feature.
 *
 * - Server-side refusal fallbacks are enabled ("default" mode), so a request
 *   declined by a safety classifier is retried on Anthropic's recommended model.
 * - Every call records token usage per school (for cost tracking / billing).
 */
final class Claude
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private Client $client;

    public function __construct(
        private readonly string $schoolId,
        private readonly string $feature,
        ?Client $client = null,
    ) {
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
        try {
            $response = $this->client->beta->messages->create(
                model: Config::model(),
                maxTokens: $maxTokens,
                system: $system,
                messages: $messages,
                outputConfig: $outputConfig,
                // Caches the conversation prefix; useful for multi-turn chats only.
                cacheControl: $cache ? ['type' => 'ephemeral'] : null,
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
                metadata: ['userID' => hash('sha256', 'school:' . $this->schoolId)],
            );
        } catch (RateLimitException) {
            throw new HttpError(503, 'ai_busy', 'The AI service is busy. Please retry in a moment.');
        } catch (AuthenticationException | PermissionDeniedException) {
            throw new HttpError(500, 'server_misconfigured', 'ANTHROPIC_API_KEY is missing or invalid.');
        } catch (BadRequestException $e) {
            throw new HttpError(502, 'ai_bad_request', 'Anthropic API rejected the request: ' . $e->getMessage());
        } catch (APIStatusException $e) {
            throw new HttpError(502, 'ai_error', 'Anthropic API error: ' . $e->getMessage());
        } catch (APIConnectionException) {
            throw new HttpError(504, 'ai_unreachable', 'Could not reach the Anthropic API.');
        }

        UsageLog::record($this->schoolId, $this->feature, $response);

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
}
