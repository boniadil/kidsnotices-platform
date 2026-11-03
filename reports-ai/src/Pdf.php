<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Text extraction from PDFs with pdftotext (poppler-utils), in one call for the whole file: pages
 * are separated by form feeds. "-layout" keeps table columns aligned on one line, which helps
 * reading figures. On Windows, poppler can sit in RAI_POPPLER_DIR.
 *
 * Scanned pages (no text layer) are read with OCR when Tesseract is installed: the page is rendered
 * with pdftoppm, then read by tesseract in Arabic and French (RAI_OCR_LANG). RAI_OCR=off disables it.
 */
final class Pdf
{
    /** A page with fewer visible characters than this has no usable text layer. */
    private const MIN_CHARS = 25;

    /** Called after each OCR'd page (the file-reading service uses it to keep the tunnel alive). */
    public static ?\Closure $progress = null;

    public static function available(): bool
    {
        return self::bin('pdftotext') !== null;
    }

    /** OCR possible: pdftoppm and tesseract installed, not disabled. */
    public static function ocrAvailable(): bool
    {
        return Config::get('RAI_OCR', 'auto') !== 'off' && self::bin('pdftoppm') !== null && self::tesseract() !== null;
    }

    /** @return list<string> text of each page, in order */
    public static function pages(string $file): array
    {
        $bin = self::bin('pdftotext') ?? throw new UserError('أداة pdftotext (poppler-utils) غير مثبتة على هذا الخادم.');
        [$code, $out, $err] = self::run([$bin, '-layout', '-enc', 'UTF-8', $file, '-']);
        if ($code !== 0) {
            throw new UserError('تعذّرت قراءة PDF: ' . trim(mb_substr($err, 0, 200)));
        }
        $pages = explode("\f", $out);
        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages); // a form feed ends every page
        }
        $pages = array_map([Text::class, 'clean'], $pages);

        if (self::ocrAvailable()) {
            foreach ($pages as $i => $text) {
                if (mb_strlen(preg_replace('/\s+/u', '', $text)) < self::MIN_CHARS) {
                    $ocr = self::ocr($file, $i + 1);
                    if (mb_strlen(preg_replace('/\s+/u', '', $ocr)) > mb_strlen(preg_replace('/\s+/u', '', $text))) {
                        $pages[$i] = $ocr;
                    }
                    if (self::$progress !== null) {
                        (self::$progress)();
                    }
                }
            }
        }
        return $pages;
    }

    /** One page: rendered at 300 dpi in grey, then read by tesseract. Empty string on failure. */
    private static function ocr(string $file, int $page): string
    {
        $dir = sys_get_temp_dir() . '/rai-ocr-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700);
        try {
            [$code] = self::run([self::bin('pdftoppm'), '-r', '300', '-gray', '-png', '-f', (string) $page, '-l', (string) $page, $file, "$dir/p"]);
            $image = glob("$dir/p*.png")[0] ?? null;
            if ($code !== 0 || $image === null) {
                return '';
            }
            [$code, $out, $err] = self::run([self::tesseract(), $image, 'stdout', '-l', Config::get('RAI_OCR_LANG', 'ara+fra'), '--psm', '3']);
            if ($code !== 0) {
                error_log("[reports-ai] OCR page $page: " . mb_substr($err, 0, 200));
                return '';
            }
            return Text::clean($out);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private static function run(array $cmd): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot run ' . $cmd[0]);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    private static function bin(string $name): ?string
    {
        $dir = Config::get('RAI_POPPLER_DIR');
        return Config::binary($name, $dir !== null ? [$dir, "$dir/bin", "$dir/Library/bin"] : []);
    }

    private static function tesseract(): ?string
    {
        $dir = Config::get('RAI_TESSERACT_DIR');
        return Config::binary('tesseract', array_filter([$dir, 'C:\\Program Files\\Tesseract-OCR', 'C:\\Program Files (x86)\\Tesseract-OCR']));
    }
}
