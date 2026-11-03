<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Estimated cost of a call, in US dollars, from Anthropic's public per-token prices.
 * Prices change: check https://www.anthropic.com/pricing and update this table when needed.
 */
final class Pricing
{
    /** USD per million tokens: [input, output, cache write (5 min), cache read]. */
    private const PRICES = [
        'claude-fable-5-1' => [10.00, 50.00, 12.50, 0.25],
        'claude-opus-5-5' => [4.00, 20.00, 5.00, 0.20],
        'claude-opus-5' => [5.00, 25.00, 6.25, 0.50],
        'claude-opus-4-8' => [5.00, 25.00, 6.25, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.20],
        'claude-sonnet-5' => [2.00, 10.00, 2.50, 0.20],
        'claude-haiku-5-5' => [0.10, 0.50, 0.125, 0.01],
    ];

    /** @param array<string, int|null> $usage the API's usage object */
    public static function cost(string $model, array $usage): float
    {
        [$in, $out, $write, $read] = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];
        return round((
            ($usage['input_tokens'] ?? 0) * $in
            + ($usage['output_tokens'] ?? 0) * $out
            + ($usage['cache_creation_input_tokens'] ?? 0) * $write
            + ($usage['cache_read_input_tokens'] ?? 0) * $read
        ) / 1_000_000, 6);
    }
}
