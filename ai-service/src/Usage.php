<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * One row per AI call in the usage_log table (tokens + estimated cost),
 * used for limits, billing and the dashboard. Question/answer text is never stored.
 */
final class Usage
{
    public static function record(RequestContext $ctx, array $response): void
    {
        $now = time();
        $usage = $response['usage'] ?? [];
        Database::pdo()->prepare(
            'INSERT INTO usage_log (created_at, day, month, client_id, school_id, feature, end_user, model, stop_reason,
                                    input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            gmdate('Y-m-d H:i:s', $now),
            gmdate('Y-m-d', $now),
            gmdate('Y-m', $now),
            $ctx->clientId,
            $ctx->schoolId,
            $ctx->feature,
            $ctx->endUser,
            $response['model'],
            $response['stop_reason'],
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
            Pricing::cost($response['model'], $usage),
        ]);
    }

    /** Aggregated usage for a month, optionally for one client. */
    public static function report(string $month, ?int $clientId = null): array
    {
        $sql = 'SELECT u.client_id, c.name AS client_name, u.school_id, u.feature,
                       COUNT(*) AS requests, SUM(u.input_tokens) AS input_tokens, SUM(u.output_tokens) AS output_tokens,
                       SUM(u.cache_read_tokens) AS cache_read_tokens, SUM(u.cost_usd) AS cost_usd
                FROM usage_log u LEFT JOIN clients c ON c.id = u.client_id
                WHERE u.month = ?' . ($clientId !== null ? ' AND u.client_id = ?' : '') . '
                GROUP BY u.client_id, c.name, u.school_id, u.feature
                ORDER BY c.name, u.school_id, u.feature';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($clientId !== null ? [$month, $clientId] : [$month]);
        return array_map(fn ($r) => [
            'client_id' => (int) $r['client_id'],
            'client_name' => $r['client_name'],
            'school_id' => $r['school_id'],
            'feature' => $r['feature'],
            'requests' => (int) $r['requests'],
            'input_tokens' => (int) $r['input_tokens'],
            'output_tokens' => (int) $r['output_tokens'],
            'cache_read_tokens' => (int) $r['cache_read_tokens'],
            'cost_usd' => round((float) $r['cost_usd'], 4),
        ], $stmt->fetchAll());
    }
}
