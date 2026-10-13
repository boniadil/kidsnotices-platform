<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * File-based daily request quota per school, so one tenant cannot run up the bill.
 */
final class RateLimiter
{
    public static function hit(string $schoolId): void
    {
        $limit = Config::dailyLimit();
        if ($limit <= 0) {
            return;
        }

        $dir = Config::storageDir() . '/quota';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Y-m-d') . '_' . $schoolId . '.count';

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return; // Never block the platform because of a storage problem.
        }
        try {
            flock($handle, LOCK_EX);
            $count = (int) stream_get_contents($handle);
            if ($count >= $limit) {
                throw new HttpError(429, 'daily_limit_reached', 'This school has reached its daily AI request limit.');
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($count + 1));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
