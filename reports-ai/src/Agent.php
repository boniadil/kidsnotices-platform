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
        $claude = new Claude($this->task);
        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $response = $claude->withTools($system, $messages, Tools::definitions(), $effort, $maxTokens);
            if ($response->stopReason !== 'tool_use') {
                return trim(Claude::text($response));
            }
            $results = [];
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    [$content, $isError] = $this->tools->run($block->name, $block->input);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $content, 'isError' => $isError];
                }
            }
            // The assistant turn goes back unchanged (thinking blocks included), then all results in one user turn.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        throw new UserError('احتاج السؤال إلى خطوات كثيرة. اجعله أكثر تحديداً.');
    }
}
