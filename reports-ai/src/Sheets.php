<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Reads spreadsheets without any library: .xlsx (ZipArchive + XMLReader), .ods (OpenDocument),
 * .csv, and old .xls (Excel 97–2003, see Xls). Formula cells give their last computed value;
 * dates are written as YYYY-MM-DD, percentages as "12.5%".
 *
 * @phpstan-type Sheet array{name: string, rows: list<list<string>>}
 */
final class Sheets
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @return list<array{name: string, rows: list<list<string>>}> */
    public static function read(string $file, string $ext, int $maxRows = PHP_INT_MAX): array
    {
        return match ($ext) {
            'xlsx' => self::xlsx($file, $maxRows),
            'ods' => self::ods($file, $maxRows),
            'csv' => [['name' => 'CSV', 'rows' => self::csv($file, $maxRows)]],
            'xls' => Xls::read($file, $maxRows),
        };
    }

    /** Number as text: no trailing ".0", no exponent for ordinary values. */
    public static function number(float $v): string
    {
        if (floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    }

    /** Excel serial date (1900 or 1904 system) → YYYY-MM-DD [HH:MM]. */
    public static function date(float $serial, bool $date1904 = false): string
    {
        $base = $date1904 ? 24107 : 25569; // days between the epoch of the system and 1970-01-01
        $ts = (int) round(($serial - $base) * 86400);
        return gmdate(fmod($serial, 1.0) > 0.00001 ? 'Y-m-d H:i' : 'Y-m-d', $ts);
    }

    /** Is this number format a date? (built-in id or a code with d/m/y outside quotes and brackets) */
    public static function isDateFormat(int $id, string $code): bool
    {
        if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58)) {
            return true;
        }
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);
        return $code !== '' && preg_match('/[dmyhs]/i', $code) === 1 && preg_match('/[0#?]/', $code) === 0;
    }

    public static function isPercentFormat(int $id, string $code): bool
    {
        return $id === 9 || $id === 10 || str_contains(preg_replace('/"[^"]*"/', '', $code), '%');
    }

    // ---- xlsx -------------------------------------------------------------------------------

    private static function xlsx(string $file, int $maxRows): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new UserError('تعذّر فتح ملف Excel هذا.');
        }
        $workbook = self::dom(self::entry($zip, 'xl/workbook.xml'));
        $date1904 = false;
        foreach ($workbook->getElementsByTagNameNS(self::MAIN, 'workbookPr') as $pr) {
            $date1904 = in_array($pr->getAttribute('date1904'), ['1', 'true'], true);
        }
        $targets = [];
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        foreach ($rels ? self::dom($rels)->getElementsByTagName('Relationship') : [] as $r) {
            $t = $r->getAttribute('Target');
            $targets[$r->getAttribute('Id')] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . preg_replace('#^\./#', '', $t);
        }
        $strings = self::sharedStrings($zip);
        [$xfFormats, $formatCodes] = self::styles($zip);

        $sheets = [];
        $total = 0;
        foreach ($workbook->getElementsByTagNameNS(self::MAIN, 'sheet') as $s) {
            $path = $targets[$s->getAttributeNS(self::REL, 'id')] ?? null;
            $xml = $path !== null ? $zip->getFromName($path) : false;
            if ($xml === false) {
                continue;
            }
            $rows = [];
            $reader = new \XMLReader();
            $reader->XML($xml, null, LIBXML_NONET | LIBXML_PARSEHUGE);
            $row = null;
            $cell = null;
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    switch ($reader->localName) {
                        case 'row':
                            $row = [];
                            if ($reader->isEmptyElement) {
                                $row = null;
                            }
                            break;
                        case 'c':
                            $cell = ['ref' => (string) $reader->getAttribute('r'), 't' => (string) $reader->getAttribute('t'), 's' => (int) $reader->getAttribute('s'), 'v' => '', 'is' => ''];
                            if ($reader->isEmptyElement) {
                                $cell = null;
                            }
                            break;
                        case 'v':
                            if ($cell !== null) {
                                $cell['v'] = $reader->readString();
                            }
                            break;
                        case 't':
                            if ($cell !== null && $cell['t'] === 'inlineStr') {
                                $cell['is'] .= $reader->readString();
                            }
                            break;
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                    if ($reader->localName === 'c' && $cell !== null && $row !== null) {
                        $col = $cell['ref'] !== '' ? self::column($cell['ref']) : count($row);
                        $row = array_pad($row, $col, '');
                        $row[$col] = self::xlsxValue($cell, $strings, $xfFormats, $formatCodes, $date1904);
                        $cell = null;
                    } elseif ($reader->localName === 'row' && $row !== null) {
                        $rows[] = $row;
                        $row = null;
                        if (++$total > $maxRows) {
                            throw new UserError("هذا الملف يتجاوز $maxRows صف. احتفظ بأوراق الملخص فقط.");
                        }
                    }
                }
            }
            $reader->close();
            $sheets[] = ['name' => $s->getAttribute('name'), 'rows' => array_map(fn ($r) => array_values($r), $rows)];
        }
        $zip->close();
        return $sheets;
    }

    private static function xlsxValue(array $c, array $strings, array $xfFormats, array $formatCodes, bool $date1904): string
    {
        switch ($c['t']) {
            case 's':
                return $strings[(int) $c['v']] ?? '';
            case 'inlineStr':
                return $c['is'];
            case 'str':
            case 'e':
                return $c['v'];
            case 'b':
                return $c['v'] === '1' ? 'TRUE' : 'FALSE';
        }
        if ($c['v'] === '' || !is_numeric($c['v'])) {
            return $c['v'];
        }
        $v = (float) $c['v'];
        $fmt = $xfFormats[$c['s']] ?? 0;
        $code = $formatCodes[$fmt] ?? '';
        if (self::isDateFormat($fmt, $code)) {
            return self::date($v, $date1904);
        }
        return self::isPercentFormat($fmt, $code) ? self::number(round($v * 100, 10)) . '%' : self::number($v);
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $strings = [];
        $reader = new \XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_PARSEHUGE);
        $current = null;
        $skip = 0; // inside <rPh> (phonetic hints): not part of the text
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    $current = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $current = null;
                    }
                } elseif ($reader->localName === 'rPh' && !$reader->isEmptyElement) {
                    $skip++;
                } elseif ($reader->localName === 't' && $current !== null && $skip === 0) {
                    $current .= $reader->readString();
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'rPh') {
                    $skip--;
                } elseif ($reader->localName === 'si' && $current !== null) {
                    $strings[] = $current;
                    $current = null;
                }
            }
        }
        $reader->close();
        return $strings;
    }

    /** @return array{0: list<int>, 1: array<int, string>} number format id per cell style, custom format codes */
    private static function styles(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [[], []];
        }
        $doc = self::dom($xml);
        $codes = [];
        foreach ($doc->getElementsByTagNameNS(self::MAIN, 'numFmt') as $f) {
            $codes[(int) $f->getAttribute('numFmtId')] = $f->getAttribute('formatCode');
        }
        $xfs = [];
        foreach ($doc->getElementsByTagNameNS(self::MAIN, 'cellXfs') as $list) {
            foreach ($list->childNodes as $xf) {
                if ($xf instanceof \DOMElement && $xf->localName === 'xf') {
                    $xfs[] = (int) $xf->getAttribute('numFmtId');
                }
            }
        }
        return [$xfs, $codes];
    }

    /** "AB12" → 27 (zero-based column index) */
    private static function column(string $ref): int
    {
        $n = 0;
        foreach (str_split(strtoupper(preg_replace('/\d+$/', '', $ref))) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    // ---- ods --------------------------------------------------------------------------------

    private static function ods(string $file, int $maxRows): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new UserError('تعذّر فتح ملف ODS هذا.');
        }
        $xml = self::entry($zip, 'content.xml');
        $zip->close();
        $T = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
        $O = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
        $X = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
        $doc = self::dom($xml);
        $sheets = [];
        $total = 0;
        foreach ($doc->getElementsByTagNameNS($T, 'table') as $table) {
            $rows = [];
            foreach ((new \DOMXPath($doc))->query('.//*[local-name()="table-row"]', $table) as $tr) {
                $row = [];
                $pendingEmpty = 0; // repeated empty cells count only if a value follows
                foreach ($tr->childNodes as $tc) {
                    if (!$tc instanceof \DOMElement || !in_array($tc->localName, ['table-cell', 'covered-table-cell'], true)) {
                        continue;
                    }
                    $repeat = max(1, (int) ($tc->getAttributeNS($T, 'number-columns-repeated') ?: 1));
                    $value = self::odsValue($tc, $O, $X);
                    if ($value === '') {
                        $pendingEmpty += $repeat;
                        continue;
                    }
                    $row = array_merge($row, array_fill(0, $pendingEmpty, ''), array_fill(0, min($repeat, 1000), $value));
                    $pendingEmpty = 0;
                }
                if ($row === []) {
                    continue; // empty rows (often repeated thousands of times) are skipped
                }
                $times = min(max(1, (int) ($tr->getAttributeNS($T, 'number-rows-repeated') ?: 1)), 1000);
                for ($i = 0; $i < $times; $i++) {
                    $rows[] = $row;
                    if (++$total > $maxRows) {
                        throw new UserError("هذا الملف يتجاوز $maxRows صف. احتفظ بأوراق الملخص فقط.");
                    }
                }
            }
            $sheets[] = ['name' => $table->getAttributeNS($T, 'name'), 'rows' => $rows];
        }
        return $sheets;
    }

    private static function odsValue(\DOMElement $tc, string $O, string $X): string
    {
        $type = $tc->getAttributeNS($O, 'value-type');
        switch ($type) {
            case 'float':
            case 'currency':
                return self::number((float) $tc->getAttributeNS($O, 'value'));
            case 'percentage':
                return self::number(round((float) $tc->getAttributeNS($O, 'value') * 100, 10)) . '%';
            case 'date':
                return str_replace('T', ' ', substr($tc->getAttributeNS($O, 'date-value'), 0, 16));
            case 'boolean':
                return $tc->getAttributeNS($O, 'boolean-value') === 'true' ? 'TRUE' : 'FALSE';
        }
        $lines = [];
        foreach ($tc->getElementsByTagNameNS($X, 'p') as $p) {
            $lines[] = trim($p->textContent);
        }
        return trim(implode(' ', $lines));
    }

    // ---- csv --------------------------------------------------------------------------------

    /** @return list<list<string>> */
    private static function csv(string $file, int $maxRows): array
    {
        $data = (string) file_get_contents($file);
        $data = preg_replace('/^\xEF\xBB\xBF/', '', $data);
        if (!mb_check_encoding($data, 'UTF-8')) {
            // Windows Arabic encoding (it also covers French accented letters).
            $converted = function_exists('iconv') ? @iconv('WINDOWS-1256', 'UTF-8//IGNORE', $data) : false;
            $data = $converted !== false ? $converted : mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($data, "\n");
        $counts = [',' => substr_count((string) $first, ','), ';' => substr_count((string) $first, ';'), "\t" => substr_count((string) $first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $data);
        rewind($h);
        $rows = [];
        while (($row = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map('strval', $row);
            if (count($rows) > $maxRows) {
                throw new UserError("هذا الملف يتجاوز $maxRows صف. احتفظ بأوراق الملخص فقط.");
            }
        }
        fclose($h);
        return $rows;
    }

    // ---- helpers ----------------------------------------------------------------------------

    private static function entry(\ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > 200 * 1024 * 1024 || ($xml = $zip->getFromName($name)) === false) {
            throw new UserError('تعذّر فتح الملف (تالف أو ليس جدولاً).');
        }
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
}
