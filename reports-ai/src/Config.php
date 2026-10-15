<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Settings from environment variables (optionally loaded from .env) and the sector file.
 */
final class Config
{
    private static ?array $domain = null;

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
            if (getenv($key) === false) {
                putenv("$key=" . trim($value, "\"'"));
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function storageDir(): string
    {
        return self::get('RAI_STORAGE_DIR', self::root() . '/storage');
    }

    /** Model for a task (extract, map, ask, report): RAI_MODEL_<TASK>, else RAI_MODEL, else Claude Opus 5.5. */
    public static function model(string $task): string
    {
        return self::get('RAI_MODEL_' . strtoupper($task)) ?? self::get('RAI_MODEL', 'claude-opus-5-5');
    }

    /** Who answers a task: "anthropic" (Claude, default) or "local" (Ollama). RAI_PROVIDER_<TASK>, else RAI_PROVIDER. */
    public static function provider(string $task): string
    {
        $value = strtolower(self::get('RAI_PROVIDER_' . strtoupper($task)) ?? self::get('RAI_PROVIDER', 'anthropic'));
        return in_array($value, ['local', 'ollama'], true) ? 'local' : 'anthropic';
    }

    /** Local model for a task: RAI_LOCAL_MODEL_<TASK>, else RAI_LOCAL_MODEL (a name from "ollama list"). */
    public static function localModel(string $task): string
    {
        return self::get('RAI_LOCAL_MODEL_' . strtoupper($task)) ?? self::get('RAI_LOCAL_MODEL')
            ?? throw new UserError('النموذج المحلي غير مضبوط (RAI_LOCAL_MODEL).');
    }

    /**
     * Full path of a program (pdftotext, soffice…): in $extraDirs, then PATH, then the usual places.
     * Works on Linux, macOS and Windows; returns null when it is not installed (or not reachable,
     * e.g. outside open_basedir on shared hosting).
     */
    public static function binary(string $name, array $extraDirs = []): ?string
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $dirs = array_merge($extraDirs, explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']);
        foreach (array_filter($dirs) as $dir) {
            $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . ($windows ? '.exe' : '');
            if (@is_file($file) && ($windows || @is_executable($file))) {
                return $file;
            }
        }
        return null;
    }

    public static function domain(): array
    {
        if (self::$domain === null) {
            $file = self::get('RAI_DOMAIN_FILE', 'config/domain.php');
            self::$domain = require (str_starts_with($file, '/') ? $file : self::root() . '/' . $file);
        }
        return self::$domain;
    }

    /** Short sector description for prompts. */
    public static function domainBrief(): string
    {
        $d = self::domain();
        $categories = implode("\n", array_map(fn ($k, $v) => "- $k: {$v['ar']} / {$v['fr']}", array_keys($d['categories']), $d['categories']));
        return "Organisation: {$d['organization']['ar']} / {$d['organization']['fr']}\n"
            . "Sector: {$d['sector']['ar']} / {$d['sector']['fr']}\n"
            . "Categories of figures:\n$categories\n"
            . "Sector vocabulary (Arabic = French):\n{$d['glossary']}";
    }
}
