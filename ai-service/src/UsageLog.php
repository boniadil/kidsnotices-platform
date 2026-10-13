<?php

declare(strict_types=1);

namespace KidsNotices\AI;

use Anthropic\Beta\Messages\BetaMessage;

/**
 * Appends one JSON line per AI call to storage/usage-YYYY-MM.log
 * so you can see the cost per school and per feature.
 */
final class UsageLog
{
    public static function record(string $schoolId, string $feature, BetaMessage $response): void
    {
        $usage = $response->usage;
        $line = json_encode([
            'time' => date('c'),
            'school_id' => $schoolId,
            'feature' => $feature,
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
            'cache_write_tokens' => $usage->cacheCreationInputTokens ?? 0,
        ], JSON_UNESCAPED_UNICODE);

        @file_put_contents(
            Config::storageDir() . '/usage-' . date('Y-m') . '.log',
            $line . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}
