<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Text extraction from text-based PDFs with poppler-utils (pdfinfo + pdftotext), page by page.
 * "-layout" keeps table columns aligned on one line, which helps reading figures.
 */
final class Pdf
{
    public static function available(): bool
    {
        return self::which('pdftotext') !== null && self::which('pdfinfo') !== null;
    }

    public static function pageCount(string $file): int
    {
        $out = self::run([self::which('pdfinfo') ?? 'pdfinfo', $file]);
        if (!preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
            throw new UserError('هذا الملف ليس PDF مقروءاً.');
        }
        return (int) $m[1];
    }

    public static function pageText(string $file, int $page): string
    {
        return Text::clean(self::run([self::which('pdftotext') ?? 'pdftotext', '-layout', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page, $file, '-']));
    }

    private static function run(array $cmd): string
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot run ' . $cmd[0]);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new UserError('تعذّرت قراءة PDF: ' . trim(mb_substr($err, 0, 200)));
        }
        return (string) $out;
    }

    private static function which(string $bin): ?string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) {
            if (is_executable($dir . $bin)) {
                return $dir . $bin;
            }
        }
        return null;
    }
}
