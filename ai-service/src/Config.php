<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Reads settings from environment variables, optionally loaded from a .env file.
 */
final class Config
{
    /** Feature keys used for per-feature models, limits and usage reports. */
    public const FEATURES = ['parent_chat', 'search', 'draft', 'translate', 'teacher', 'insights'];

    /** Default monthly request limit per school, per feature (0 = unlimited). */
    private const DEFAULT_MONTHLY_LIMITS = [
        'parent_chat' => 3000,
        'search' => 3000,
        'draft' => 500,
        'translate' => 1000,
        'teacher' => 1000,
        'insights' => 100,
    ];

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

    /** Model for a feature: KN_AI_MODEL_<FEATURE>, else KN_AI_MODEL, else Claude Opus 5.5. */
    public static function modelFor(string $feature): string
    {
        return self::get('KN_AI_MODEL_' . strtoupper($feature))
            ?? self::get('KN_AI_MODEL', 'claude-opus-5-5');
    }

    /** Monthly request limit per school for a feature: KN_AI_MONTHLY_LIMIT_<FEATURE> (0 = unlimited). */
    public static function monthlyLimit(string $feature): int
    {
        return (int) self::get('KN_AI_MONTHLY_LIMIT_' . strtoupper($feature), (string) (self::DEFAULT_MONTHLY_LIMITS[$feature] ?? 0));
    }

    /** Daily request limit per end user (X-End-User header), all features together (0 = unlimited). */
    public static function dailyLimitPerUser(): int
    {
        return (int) self::get('KN_AI_DAILY_LIMIT_PER_USER', '30');
    }

    public static function storageDir(): string
    {
        return self::get('KN_AI_STORAGE_DIR', dirname(__DIR__) . '/storage');
    }
}
