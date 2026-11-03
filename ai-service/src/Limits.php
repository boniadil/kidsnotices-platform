<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Checked before every AI call, from the usage_log table:
 *  - client: monthly request quota and monthly budget (set per client with bin/kn-admin.php),
 *  - school: monthly request limit per feature (KN_AI_MONTHLY_LIMIT_<FEATURE>),
 *  - end user: daily request limit, all features together (KN_AI_DAILY_LIMIT_PER_USER).
 */
final class Limits
{
    public static function check(RequestContext $ctx, array $client): void
    {
        $pdo = Database::pdo();
        $month = gmdate('Y-m');

        $requestLimit = (int) $client['monthly_request_limit'];
        $budget = (float) $client['monthly_budget_usd'];
        if ($requestLimit > 0 || $budget > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS cost FROM usage_log WHERE client_id = ? AND month = ?');
            $stmt->execute([$ctx->clientId, $month]);
            $row = $stmt->fetch();
            if (($requestLimit > 0 && (int) $row['n'] >= $requestLimit) || ($budget > 0 && (float) $row['cost'] >= $budget)) {
                throw new HttpError(429, 'client_quota_exceeded', 'Your monthly quota for the AI service is used up.');
            }
        }

        $featureLimit = Config::monthlyLimit($ctx->feature);
        if ($featureLimit > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM usage_log WHERE client_id = ? AND school_id = ? AND feature = ? AND month = ?');
            $stmt->execute([$ctx->clientId, $ctx->schoolId, $ctx->feature, $month]);
            if ((int) $stmt->fetchColumn() >= $featureLimit) {
                throw new HttpError(429, 'feature_limit_reached', "This school has reached its monthly limit for '{$ctx->feature}'.");
            }
        }

        $userLimit = Config::dailyLimitPerUser();
        if ($ctx->endUser !== null && $userLimit > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM usage_log WHERE end_user = ? AND day = ?');
            $stmt->execute([$ctx->endUser, gmdate('Y-m-d')]);
            if ((int) $stmt->fetchColumn() >= $userLimit) {
                throw new HttpError(429, 'user_daily_limit_reached', 'This user has reached their daily limit.');
            }
        }
    }
}
