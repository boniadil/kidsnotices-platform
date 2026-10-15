<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Sends a task to the provider chosen for it (Config::provider): Claude or the local model.
 */
final class Ai
{
    /** Structured output (JSON schema) → decoded array. */
    public static function json(string $task, string $system, array $messages, array $schema, string $effort = 'medium', int $maxTokens = 32000): array
    {
        return Config::provider($task) === 'local'
            ? (new Ollama($task))->json($system, $messages, $schema, $maxTokens)
            : (new Claude($task))->json($system, $messages, $schema, $effort, $maxTokens);
    }
}
