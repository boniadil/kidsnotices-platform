<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Reads every supported document into "units" (the equivalent of pages), each with a label used
 * in citations: PDF and Word → page («ص 3»), Excel → sheet («ورقة: Permis»), PowerPoint → slide («شريحة 4»).
 *
 *  PDF            pdftotext, page by page
 *  Word  .docx    paragraphs and tables; pages split at the page breaks Word stored in the file
 *  Excel .xlsx .xls .ods .csv   one unit per sheet (long sheets split by rows); cells joined with " | " (Sheets, Xls)
 *  PowerPoint .pptx   one unit per slide, in presentation order (text boxes and tables)
 *  .doc .ppt .odt .odp   converted first with LibreOffice (soffice), when installed
 *  PDF and old formats can also be read by the service on your computer (extractor/, RAI_EXTRACT_URL)
 */
final class Documents
{
    public const EXTENSIONS = ['pdf', 'docx', 'doc', 'odt', 'xlsx', 'xls', 'ods', 'csv', 'pptx', 'ppt', 'odp'];
    private const ROWS_PER_UNIT = 150;
    private const MAX_ROWS = 30000;

    /** pdf | word | excel | powerpoint, from the file extension. */
    public static function kind(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'pdf',
            'docx', 'doc', 'odt' => 'word',
            'xlsx', 'xls', 'ods', 'csv' => 'excel',
            'pptx', 'ppt', 'odp' => 'powerpoint',
            default => throw new UserError('صيغة غير مدعومة. الصيغ المقبولة: ' . implode('، ', self::EXTENSIONS) . '.'),
        };
    }

    /** Checks that the content matches the extension (magic bytes). */
    public static function assertContent(string $file, string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($file, length: 8);
        $ok = match (true) {
            $ext === 'pdf' => str_starts_with($head, '%PDF-'),
            in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true) => str_starts_with($head, "PK\x03\x04"),
            in_array($ext, ['doc', 'xls', 'ppt'], true) => str_starts_with($head, "\xD0\xCF\x11\xE0"),
            $ext === 'csv' => true,
            default => false,
        };
        if (!$ok) {
            throw new UserError('محتوى الملف لا يطابق امتداده (.' . $ext . ').');
        }
    }

    /**
     * @param bool $allowRemote false inside the file-reading service itself (extractor/server.php)
     * @return array<int, array{label: string, text: string}> unit number (1..n) => label + text
     */
    public static function units(string $file, string $filename, bool $allowRemote = true): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($allowRemote && self::useRemote($ext)) {
            $units = self::remote($file, $filename);
        } else {
            $converted = null;
            if (in_array($ext, ['doc', 'odt', 'ppt', 'odp'], true)) {
                [$file, $ext] = $converted = self::convert($file, $ext);
            }
            try {
                $units = match ($ext) {
                    'pdf' => self::pdf($file),
                    'docx' => self::docx($file),
                    'xlsx', 'xls', 'ods', 'csv' => self::spreadsheet($file, $ext),
                    'pptx' => self::pptx($file),
                };
            } finally {
                if ($converted !== null) {
                    @unlink($converted[0]); // the converted copy is not kept
                }
            }
        }
        $units = array_values(array_filter($units, fn ($u) => trim($u['text']) !== ''));
        return array_combine(range(1, max(1, count($units))), $units ?: [['label' => '1', 'text' => '']]);
    }

    /** LibreOffice, for .doc .ppt .odt .odp */
    public static function soffice(): ?string
    {
        return Config::binary('soffice', ['/Applications/LibreOffice.app/Contents/MacOS', 'C:\\Program Files\\LibreOffice\\program', 'C:\\Program Files (x86)\\LibreOffice\\program'])
            ?? Config::binary('libreoffice');
    }

    private static function pdf(string $file): array
    {
        if (!Pdf::available()) {
            throw new UserError('أداة pdftotext (poppler-utils) غير مثبتة على الخادم. ثبّتها، أو شغّل خدمة قراءة الملفات على حاسوبك (RAI_EXTRACT_URL).');
        }
        $pages = Pdf::pages($file);
        return array_map(fn ($text, $i) => ['label' => 'ص ' . ($i + 1), 'text' => $text], $pages, array_keys($pages));
    }

    // ---- file-reading service on another computer (extractor/) -------------------------------

    /**
     * PDFs and old Office files need programs (pdftotext, LibreOffice) that shared hosting often lacks.
     * With RAI_EXTRACT_URL they are read by the service running on your computer: when the program is
     * missing here (RAI_EXTRACT_REMOTE=auto, default) or always (RAI_EXTRACT_REMOTE=always).
     */
    private static function useRemote(string $ext): bool
    {
        if (Config::get('RAI_EXTRACT_URL') === null || !in_array($ext, ['pdf', 'doc', 'odt', 'ppt', 'odp'], true)) {
            return false;
        }
        if (Config::get('RAI_EXTRACT_REMOTE', 'auto') === 'always') {
            return true;
        }
        return $ext === 'pdf' ? !Pdf::available() : self::soffice() === null;
    }

    private static function remote(string $file, string $filename): array
    {
        $headers = ['Content-Type: application/octet-stream', 'X-Filename: ' . rawurlencode(basename($filename))];
        if (($key = Config::get('RAI_EXTRACT_KEY')) !== null) {
            $headers[] = "Authorization: Bearer $key";
        }
        $ch = curl_init(rtrim((string) Config::get('RAI_EXTRACT_URL'), '/') . '/extract');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) file_get_contents($file),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => (int) Config::get('RAI_EXTRACT_TIMEOUT', '600'),
        ]);
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status === 200 && is_array($data['units'] ?? null)) {
            $units = [];
            foreach ($data['units'] as $u) {
                if (is_string($u['label'] ?? null) && is_string($u['text'] ?? null)) {
                    $units[] = ['label' => $u['label'], 'text' => Text::clean($u['text'])];
                }
            }
            return $units;
        }
        error_log("[reports-ai] file-reading service: HTTP $status $curlError " . mb_substr((string) $raw, 0, 200));
        throw new UserError(match (true) {
            $status === 422 && is_string($data['error'] ?? null) => $data['error'], // the file itself (scanned, damaged…)
            $status === 401 || $status === 403 => 'رفضت خدمة قراءة الملفات على حاسوبك مفتاح الدخول. تحقق من RAI_EXTRACT_KEY.',
            $status === 0 || $status >= 500 => 'تعذّر الاتصال بخدمة قراءة الملفات على حاسوبك. تأكد أن نافذتها مفتوحة وأن RAI_EXTRACT_URL صحيح، ثم اضغط «إعادة».',
            default => 'أرجعت خدمة قراءة الملفات على حاسوبك خطأً. حاول من جديد.',
        });
    }

    // ---- Word ---------------------------------------------------------------------------------

    private static function docx(string $file): array
    {
        $xml = self::zipEntry($file, 'word/document.xml');
        $doc = self::dom($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xp->query('//w:body')->item(0);

        $pages = [''];
        $explicitBreaks = 0;
        foreach ($body?->childNodes ?? [] as $node) {
            if ($node->localName === 'p') {
                // A page break before or inside this paragraph starts a new page.
                $breaks = $xp->query('.//w:br[@w:type="page"] | .//w:lastRenderedPageBreak | ./w:pPr/w:pageBreakBefore', $node)->length;
                if ($breaks > 0 && trim($pages[count($pages) - 1]) !== '') {
                    $pages[] = '';
                    $explicitBreaks++;
                }
                $pages[count($pages) - 1] .= self::wordParagraph($xp, $node) . "\n";
            } elseif ($node->localName === 'tbl') {
                foreach ($xp->query('./w:tr', $node) as $tr) {
                    $cells = [];
                    foreach ($xp->query('./w:tc', $tr) as $tc) {
                        $cells[] = trim(implode(' ', array_map(fn ($p) => self::wordParagraph($xp, $p), iterator_to_array($xp->query('.//w:p', $tc)))));
                    }
                    $pages[count($pages) - 1] .= implode(' | ', $cells) . "\n";
                }
                $pages[count($pages) - 1] .= "\n";
            }
        }

        if ($explicitBreaks === 0) {
            // No page information in the file: split into parts of similar size.
            $parts = self::split($pages[0], 3500);
            return array_map(fn ($t, $i) => ['label' => 'جزء ' . ($i + 1), 'text' => $t], $parts, array_keys($parts));
        }
        return array_map(fn ($t, $i) => ['label' => 'ص ' . ($i + 1), 'text' => Text::clean($t)], $pages, array_keys($pages));
    }

    private static function wordParagraph(\DOMXPath $xp, \DOMNode $p): string
    {
        $text = '';
        foreach ($xp->query('.//w:t | .//w:tab | .//w:br[not(@w:type="page")]', $p) as $n) {
            $text .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }
        return trim($text);
    }

    // ---- Excel --------------------------------------------------------------------------------

    private static function spreadsheet(string $file, string $ext): array
    {
        $units = [];
        foreach (Sheets::read($file, $ext, self::MAX_ROWS) as $sheet) {
            $rows = [];
            foreach ($sheet['rows'] as $row) {
                $row = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', $c)), $row);
                while ($row && $row[count($row) - 1] === '') {
                    array_pop($row);
                }
                if ($row !== []) {
                    $rows[] = implode(' | ', $row);
                }
            }
            if ($rows === []) {
                continue;
            }
            $name = $sheet['name'];
            // Long sheets are split; the header row is repeated so every part keeps column meanings.
            $header = $rows[0];
            foreach (array_chunk(array_slice($rows, 1), self::ROWS_PER_UNIT) ?: [[]] as $i => $chunk) {
                $label = count($rows) > self::ROWS_PER_UNIT + 1
                    ? "ورقة «{$name}» (" . ($i * self::ROWS_PER_UNIT + 2) . '–' . ($i * self::ROWS_PER_UNIT + count($chunk) + 1) . ')'
                    : "ورقة «{$name}»";
                $units[] = ['label' => $label, 'text' => "[Sheet: {$name}]\n" . $header . "\n" . implode("\n", $chunk)];
            }
        }
        return $units;
    }

    // ---- PowerPoint ---------------------------------------------------------------------------

    private static function pptx(string $file): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new UserError('تعذّر فتح ملف PowerPoint هذا.');
        }
        // Slide order comes from presentation.xml (file names are not always in order).
        $pres = self::dom((string) $zip->getFromName('ppt/presentation.xml'));
        $rels = self::dom((string) $zip->getFromName('ppt/_rels/presentation.xml.rels'));
        $targets = [];
        foreach ($rels->getElementsByTagName('Relationship') as $r) {
            $targets[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
        $paths = [];
        foreach ($pres->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId') as $s) {
            $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if (isset($targets[$rid])) {
                $paths[] = 'ppt/' . ltrim(preg_replace('#^\.\./#', '', $targets[$rid]), '/');
            }
        }

        $units = [];
        foreach ($paths as $i => $path) {
            $slide = self::dom((string) $zip->getFromName($path));
            $xp = new \DOMXPath($slide);
            $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $lines = [];
            foreach ($xp->query('//a:tbl') as $tbl) {
                foreach ($xp->query('./a:tr', $tbl) as $tr) {
                    $lines[] = implode(' | ', array_map(fn ($tc) => trim(preg_replace('/\s+/u', ' ', $tc->textContent)), iterator_to_array($xp->query('./a:tc', $tr))));
                }
                $tbl->parentNode->removeChild($tbl); // its paragraphs are not read twice
            }
            $text = [];
            foreach ($xp->query('//a:p') as $p) {
                $t = trim(implode('', array_map(fn ($n) => $n->textContent, iterator_to_array($xp->query('.//a:t', $p)))));
                if ($t !== '') {
                    $text[] = $t;
                }
            }
            $units[] = ['label' => 'شريحة ' . ($i + 1), 'text' => implode("\n", array_merge($text, $lines))];
        }
        $zip->close();
        return $units;
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} converted file and its new extension */
    private static function convert(string $file, string $ext): array
    {
        $soffice = self::soffice();
        $target = in_array($ext, ['doc', 'odt'], true) ? 'docx' : 'pptx';
        if ($soffice === null) {
            throw new UserError("لقراءة ملفات .$ext ثبّت LibreOffice على الخادم، أو احفظ الملف بصيغة .$target.");
        }
        $dir = sys_get_temp_dir() . '/rai-convert-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $src = "$dir/source.$ext";
        copy($file, $src);
        $profile = 'file:///' . ltrim(str_replace('\\', '/', $dir), '/') . '/profile';
        $proc = proc_open([$soffice, '--headless', '--norestore', "-env:UserInstallation=$profile", '--convert-to', $target, '--outdir', $dir, $src], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);
        $out = "$dir/source.$target";
        if (!is_file($out)) {
            throw new UserError("تعذّر تحويل ملف .$ext. احفظه بصيغة .$target وأعد المحاولة.");
        }
        $base = tempnam(sys_get_temp_dir(), 'rai');
        @unlink($base); // only its unique name is used
        $final = "$base.$target";
        rename($out, $final);
        self::removeDir($dir);
        return [$final, $target];
    }

    private static function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private static function zipEntry(string $file, string $entry): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true || ($stat = $zip->statName($entry)) === false || $stat['size'] > 80 * 1024 * 1024 || ($xml = $zip->getFromName($entry)) === false) {
            throw new UserError('تعذّر فتح الملف (تالف أو ليس ملف Word).');
        }
        $zip->close();
        return $xml;
    }

    private static function dom(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        if ($xml === '' || !$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new UserError('تعذّرت قراءة الملف (محتوى غير صالح).');
        }
        return $doc;
    }

    /** @return list<string> */
    private static function split(string $text, int $size): array
    {
        $parts = [];
        $current = '';
        foreach (preg_split("/\n/u", $text) as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) > $size) {
                $parts[] = Text::clean($current);
                $current = '';
            }
            $current .= $line . "\n";
        }
        if (trim($current) !== '') {
            $parts[] = Text::clean($current);
        }
        return $parts ?: [''];
    }
}
