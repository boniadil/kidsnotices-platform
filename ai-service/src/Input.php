<?php

declare(strict_types=1);

namespace KidsNotices\AI;

/**
 * Small validation helpers for request bodies coming from the platform.
 */
final class Input
{
    public static function string(array $body, string $key, int $maxLength = 5000, ?string $default = null): string
    {
        $value = $body[$key] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new HttpError(400, 'invalid_input', "Field '$key' is required and must be a non-empty string.");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new HttpError(400, 'invalid_input', "Field '$key' is too long (max $maxLength characters).");
        }
        return trim($value);
    }

    public static function optionalString(array $body, string $key, int $maxLength = 5000): ?string
    {
        if (!isset($body[$key]) || $body[$key] === '') {
            return null;
        }
        return self::string($body, $key, $maxLength);
    }

    /** @param list<string> $allowed */
    public static function oneOf(array $body, string $key, array $allowed, ?string $default = null): string
    {
        $value = $body[$key] ?? $default;
        if (!in_array($value, $allowed, true)) {
            throw new HttpError(400, 'invalid_input', "Field '$key' must be one of: " . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    public static function int(array $body, string $key, int $min, int $max, int $default): int
    {
        $value = $body[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new HttpError(400, 'invalid_input', "Field '$key' must be an integer between $min and $max.");
        }
        return $value;
    }

    public static function array(array $body, string $key, int $maxItems = 100, bool $required = true): array
    {
        $value = $body[$key] ?? null;
        if ($value === null && !$required) {
            return [];
        }
        if (!is_array($value)) {
            throw new HttpError(400, 'invalid_input', "Field '$key' must be an array/object.");
        }
        if (count($value) > $maxItems) {
            throw new HttpError(400, 'invalid_input', "Field '$key' has too many items (max $maxItems).");
        }
        return $value;
    }

    /** Language of the generated text: Arabic or French. */
    public static function language(array $body, string $key = 'language'): string
    {
        return self::oneOf($body, $key, ['ar', 'fr'], 'ar');
    }

    public static function languageName(string $code): string
    {
        return $code === 'fr' ? 'French' : 'Modern Standard Arabic';
    }

    /** Pretty JSON for embedding school data in a prompt. */
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
