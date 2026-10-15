<?php

declare(strict_types=1);

namespace ReportsAI;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Word export of a generated report (right-to-left for Arabic). References [R3:p12] become
 * readable citations: (تقرير 2021، ص 12) / (rapport 2021, p. 12).
 */
final class Docx
{
    /** [R3:p12] → (تقرير 2021، ص 12) / (rapport 2021, ص 12) — the unit label comes from the source file. */
    public static function citations(string $s, bool $rtl): string
    {
        return preg_replace_callback('/\[((?:R\d+:p\d+)(?:\s*[,;،]\s*R\d+:p\d+)*)\]/u', function ($m) use ($rtl) {
            preg_match_all('/R(\d+):p(\d+)/', $m[1], $refs, PREG_SET_ORDER);
            $parts = array_map(function ($r) use ($rtl) {
                [$year, $unit] = explode(' · ', Search::refLabel((int) $r[1], (int) $r[2]), 2) + [1 => ''];
                return ($rtl ? 'تقرير ' : 'rapport ') . $year . ($rtl ? '، ' : ', ') . $unit;
            }, $refs);
            return '(' . implode($rtl ? '؛ ' : '; ', $parts) . ')';
        }, $s);
    }

    public static function build(string $markdown, string $language): string
    {
        $rtl = $language === 'ar';
        $font = $rtl ? 'Traditional Arabic' : 'Calibri';
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord();
        $word->setDefaultFontName($font);
        $word->setDefaultFontSize(13);
        $para = ['bidi' => $rtl, 'alignment' => $rtl ? Jc::END : Jc::START, 'spaceAfter' => 120];
        $text = ['rtl' => $rtl, 'name' => $font];
        foreach ([1 => 20, 2 => 16, 3 => 14, 4 => 13] as $level => $size) {
            $word->addTitleStyle($level, $text + ['size' => $size, 'bold' => true, 'color' => '1A3D7C'], $para + ['spaceBefore' => 240]);
        }

        $cite = fn (string $s) => self::citations($s, $rtl);
        $addRuns = function ($container, string $s) use ($cite, $text) {
            foreach (preg_split('/(\*\*.+?\*\*)/u', $cite($s), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                $bold = str_starts_with($part, '**') && str_ends_with($part, '**');
                $container->addText($bold ? substr($part, 2, -2) : $part, $text + ['bold' => $bold]);
            }
        };

        $section = $word->addSection(['marginLeft' => 1200, 'marginRight' => 1200]);
        foreach (Markdown::blocks($markdown) as $b) {
            switch ($b['type']) {
                case 'heading':
                    $section->addTitle($cite(str_replace('**', '', $b['text'])), min(4, $b['level']));
                    break;
                case 'paragraph':
                    $addRuns($section->addTextRun($para), $b['text']);
                    break;
                case 'list':
                    foreach ($b['items'] as $item) {
                        $addRuns($section->addListItemRun(0, null, $para), $item);
                    }
                    break;
                case 'table':
                    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80, 'bidiVisual' => $rtl]);
                    foreach ($b['rows'] as $r => $row) {
                        $table->addRow();
                        foreach ($row as $cell) {
                            $c = $table->addCell(9000 / max(1, count($row)), $r === 0 ? ['bgColor' => 'E8EEF9'] : []);
                            $run = $c->addTextRun($para + ['spaceAfter' => 0]);
                            $addRuns($run, $r === 0 ? "**$cell**" : $cell);
                        }
                    }
                    $section->addTextBreak();
                    break;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rai') . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
