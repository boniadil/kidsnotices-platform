<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Reads settings from environment variables, optionally loaded from a .env file.
 */
final class Config
{
    public static function loadEnvFile(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            // Real environment variables win over the .env file.
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function model(): string
    {
        return self::get('KN_AI_MODEL', 'claude-opus-5-5');
    }

    public static function secret(): string
    {
        $secret = self::get('KN_AI_SECRET', '');
        if ($secret === '' || $secret === 'change-me') {
            throw new HttpError(500, 'server_misconfigured', 'KN_AI_SECRET is not set.');
        }
        return $secret;
    }

    public static function dailyLimit(): int
    {
        return (int) self::get('KN_AI_DAILY_LIMIT_PER_SCHOOL', '500');
    }

    public static function storageDir(): string
    {
        return self::get('KN_AI_STORAGE_DIR', dirname(__DIR__) . '/storage');
    }
}
