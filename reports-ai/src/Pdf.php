<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Text extraction from text-based PDFs with pdftotext (poppler-utils), in one call for the whole
 * file: pages are separated by form feeds. "-layout" keeps table columns aligned on one line,
 * which helps reading figures. On Windows, poppler can sit in RAI_POPPLER_DIR.
 */
final class Pdf
{
    public static function available(): bool
    {
        return self::bin() !== null;
    }

    /** @return list<string> text of each page, in order */
    public static function pages(string $file): array
    {
        $bin = self::bin() ?? throw new UserError('أداة pdftotext (poppler-utils) غير مثبتة على هذا الخادم.');
        $proc = proc_open([$bin, '-layout', '-enc', 'UTF-8', $file, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot run pdftotext');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new UserError('تعذّرت قراءة PDF: ' . trim(mb_substr($err, 0, 200)));
        }
        $pages = explode("\f", $out);
        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages); // a form feed ends every page
        }
        return array_map([Text::class, 'clean'], $pages);
    }

    private static function bin(): ?string
    {
        $dir = Config::get('RAI_POPPLER_DIR');
        return Config::binary('pdftotext', $dir !== null ? [$dir, "$dir/bin", "$dir/Library/bin"] : []);
    }
}
