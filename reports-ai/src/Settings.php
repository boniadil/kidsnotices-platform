<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Settings changed from the admin page (/settings), stored in the database. They win over .env, so
 * the tunnel addresses (which change at every start) can be updated without editing files.
 * Only the keys below can be saved; an empty value removes the setting (back to .env).
 */
final class Settings
{
    /** key => secret (never shown back in the page) */
    public const KEYS = [
        'RAI_PROVIDER' => false,
        'RAI_PROVIDER_EXTRACT' => false,
        'RAI_PROVIDER_MAP' => false,
        'RAI_PROVIDER_REPAIR' => false,
        'RAI_PROVIDER_ASK' => false,
        'RAI_PROVIDER_REPORT' => false,
        'RAI_PROVIDER_SLIDES' => false,
        'RAI_LOCAL_URL' => false,
        'RAI_LOCAL_MODEL' => false,
        'RAI_LOCAL_KEY' => true,
        'RAI_EXTRACT_URL' => false,
        'RAI_EXTRACT_KEY' => true,
        'RAI_EXTRACT_REMOTE' => false,
    ];

    /** Off where there is no database to read (the file-reading service on your computer). */
    public static bool $enabled = true;

    /** @var array<string, string>|null */
    private static ?array $cache = null;
    private static float $loadedAt = 0;

    public static function get(string $key): ?string
    {
        if (!self::$enabled) {
            return null;
        }
        // Reloaded every few seconds: a long-running worker sees changes made in the page.
        if (self::$cache === null || microtime(true) - self::$loadedAt > 5) {
            try {
                self::$cache = Database::pdo()->query('SELECT key, value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
            } catch (\Throwable) {
                self::$cache = [];
            }
            self::$loadedAt = microtime(true);
        }
        return self::$cache[$key] ?? null;
    }

    /** Forget the values read (after a change made by another process). */
    public static function reload(): void
    {
        self::$cache = null;
    }

    /** @return array<string, array{value: string, updated_at: string}> */
    public static function saved(): array
    {
        $rows = Database::pdo()->query('SELECT key, value, updated_at FROM settings')->fetchAll();
        return array_column(array_map(fn ($r) => ['key' => $r['key'], 'value' => $r['value'], 'updated_at' => $r['updated_at']], $rows), null, 'key');
    }

    /** @param array<string, string|null> $values empty or null = remove */
    public static function set(array $values): void
    {
        $pdo = Database::pdo();
        $save = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
        $remove = $pdo->prepare('DELETE FROM settings WHERE key = ?');
        foreach ($values as $key => $value) {
            if (!isset(self::KEYS[$key])) {
                throw new \InvalidArgumentException("Unknown setting $key");
            }
            $value = trim((string) $value);
            $value === '' ? $remove->execute([$key]) : $save->execute([$key, $value, Database::now()]);
        }
        self::$cache = null;
    }

    /** "••••Ab12" for a secret, the value itself otherwise. */
    public static function display(string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return self::KEYS[$key] ?? false ? '••••' . substr($value, -4) : $value;
    }
}
