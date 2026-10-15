<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Tool loop: the model calls the knowledge-base tools until it can answer.
 * Used by the question assistant and by the report writer.
 */
final class Agent
{
    public readonly Tools $tools;

    public function __construct(private readonly string $task, private readonly int $maxTurns = 12)
    {
        $this->tools = new Tools();
    }

    /** Returns the final text. */
    public function run(string $system, array $messages, string $effort = 'medium', int $maxTokens = 32000): string
    {
        return Config::provider($this->task) === 'local'
            ? $this->runLocal($system, $messages, $maxTokens)
            : $this->runClaude($system, $messages, $effort, $maxTokens);
    }

    private function runClaude(string $system, array $messages, string $effort, int $maxTokens): string
    {
        $claude = new Claude($this->task);
        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $response = $claude->withTools($system, $messages, Tools::definitions(), $effort, $maxTokens);
            if ($response['stop_reason'] !== 'tool_use') {
                return trim(Claude::text($response));
            }
            $results = [];
            foreach ($response['content'] as $block) {
                if ($block['type'] === 'tool_use') {
                    [$content, $isError] = $this->tools->run($block['name'], json_decode((string) json_encode($block['input']), true));
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $content, 'is_error' => $isError];
                }
            }
            // The assistant turn goes back unchanged (thinking blocks included), then all results in one user turn.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        throw new UserError('احتاج السؤال إلى خطوات كثيرة. اجعله أكثر تحديداً.');
    }

    private function runLocal(string $system, array $messages, int $maxTokens): string
    {
        $ollama = new Ollama($this->task);
        $messages = Ollama::messages($system, $messages);
        $tools = Ollama::tools(Tools::definitions());
        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $reply = $ollama->chat($messages, ['tools' => $tools], $maxTokens);
            if ($reply['tool_calls'] === []) {
                return trim($reply['content']);
            }
            // The assistant turn with its calls, then one "tool" message per call, in order.
            $calls = [];
            foreach ($reply['tool_calls'] as $call) {
                $args = $call['function']['arguments'] ?? [];
                $args = is_string($args) ? json_decode($args, true) : $args;
                // Arguments go back as a JSON object, also when empty ({} not []).
                $calls[] = ['name' => (string) ($call['function']['name'] ?? ''), 'arguments' => is_array($args) ? $args : []];
            }
            $messages[] = ['role' => 'assistant', 'content' => $reply['content'], 'tool_calls' => array_map(fn ($c) => ['function' => ['name' => $c['name'], 'arguments' => (object) $c['arguments']]], $calls)];
            foreach ($calls as $c) {
                [$content] = $this->tools->run($c['name'], $c['arguments']);
                $messages[] = ['role' => 'tool', 'tool_name' => $c['name'], 'content' => $content];
            }
        }
        throw new UserError('احتاج السؤال إلى خطوات كثيرة. اجعله أكثر تحديداً.');
    }
}
