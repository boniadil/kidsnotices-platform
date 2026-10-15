<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Word export of a generated report (right-to-left for Arabic), written without any library.
 * References [R3:p12] become readable citations: (تقرير 2021، ص 12) / (rapport 2021, p. 12).
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

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function build(string $markdown, string $language, string $title = ''): string
    {
        $rtl = $language === 'ar';
        $cite = fn (string $s) => self::citations($s, $rtl);
        $runs = function (string $s, bool $allBold = false) use ($cite): string {
            $out = '';
            foreach (preg_split('/(\*\*.+?\*\*)/u', $cite($s), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                $bold = $allBold || (str_starts_with($part, '**') && str_ends_with($part, '**') && mb_strlen($part) > 4);
                $text = str_starts_with($part, '**') && str_ends_with($part, '**') ? substr($part, 2, -2) : $part;
                $out .= '<w:r>' . ($bold ? '<w:rPr><w:b/><w:bCs/></w:rPr>' : '') . '<w:t xml:space="preserve">' . Ooxml::esc($text) . '</w:t></w:r>';
            }
            return $out;
        };

        $body = '';
        foreach (Markdown::blocks($markdown) as $b) {
            switch ($b['type']) {
                case 'heading':
                    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading' . min(4, $b['level']) . '"/></w:pPr>' . $runs(str_replace('**', '', $b['text'])) . '</w:p>';
                    break;
                case 'paragraph':
                    $body .= '<w:p>' . $runs($b['text']) . '</w:p>';
                    break;
                case 'list':
                    foreach ($b['items'] as $item) {
                        $body .= '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr><w:spacing w:after="60"/></w:pPr>' . $runs($item) . '</w:p>';
                    }
                    break;
                case 'table':
                    $cols = max(array_map('count', $b['rows']) ?: [1]);
                    $width = intdiv(9400, $cols);
                    $border = fn (string $side) => "<w:$side w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"999999\"/>";
                    $body .= '<w:tbl><w:tblPr>' . ($rtl ? '<w:bidiVisual/>' : '') . '<w:tblW w:w="5000" w:type="pct"/>'
                        . '<w:tblBorders>' . implode('', array_map($border, ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders>'
                        . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                        . '<w:tblGrid>' . str_repeat("<w:gridCol w:w=\"$width\"/>", $cols) . '</w:tblGrid>';
                    foreach ($b['rows'] as $r => $row) {
                        $body .= '<w:tr>' . ($r === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
                        foreach (array_pad($row, $cols, '') as $cell) {
                            $body .= "<w:tc><w:tcPr><w:tcW w:w=\"$width\" w:type=\"dxa\"/>" . ($r === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="E8EEF9"/>' : '') . '</w:tcPr>'
                                . '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . $runs((string) $cell, $r === 0) . '</w:p></w:tc>';
                        }
                        $body .= '</w:tr>';
                    }
                    $body .= '</w:tbl><w:p/>';
                    break;
            }
        }
        $sect = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1300" w:right="1200" w:bottom="1300" w:left="1200" w:header="708" w:footer="708" w:gutter="0"/>' . ($rtl ? '<w:bidi/>' : '') . '</w:sectPr>';

        $files = [
            '[Content_Types].xml' => Ooxml::contentTypes([], [
                '/word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
                '/word/styles.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
                '/word/numbering.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
                '/word/settings.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
            ]),
            '_rels/.rels' => Ooxml::rootRels('word/document.xml'),
            'word/_rels/document.xml.rels' => Ooxml::rels([['rId1', 'styles', 'styles.xml'], ['rId2', 'numbering', 'numbering.xml'], ['rId3', 'settings', 'settings.xml']]),
            'word/document.xml' => Ooxml::XML . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . Ooxml::NS_REL . '"><w:body>' . $body . $sect . '</w:body></w:document>',
            'word/styles.xml' => self::styles($rtl),
            'word/numbering.xml' => Ooxml::XML . '<w:numbering xmlns:w="' . self::W . '"><w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="singleLevel"/>'
                . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/>'
                . '<w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/></w:rPr></w:lvl></w:abstractNum>'
                . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>',
            'word/settings.xml' => Ooxml::XML . '<w:settings xmlns:w="' . self::W . '"><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>',
        ];
        return Ooxml::zip($files + Ooxml::props($title, Config::domain()['organization'][$rtl ? 'ar' : 'fr'] ?? '', 'Reports AI'));
    }

    private static function styles(bool $rtl): string
    {
        $font = $rtl ? 'Traditional Arabic' : 'Calibri';
        $heading = function (int $level, int $size) {
            return '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading ' . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
                . '<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
                . '<w:rPr><w:b/><w:bCs/><w:color w:val="1A3D7C"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:style>';
        };
        return Ooxml::XML . '<w:styles xmlns:w="' . self::W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:eastAsia="' . $font . '" w:cs="' . $font . '"/>'
            . '<w:sz w:val="26"/><w:szCs w:val="26"/><w:lang w:val="fr-FR" w:bidi="ar-MA"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/>'
            . ($rtl ? '<w:pPr><w:bidi/></w:pPr><w:rPr><w:rtl/></w:rPr>' : '') . '</w:style>'
            . $heading(1, 40) . $heading(2, 32) . $heading(3, 28) . $heading(4, 26)
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/>'
            . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '</w:styles>';
    }
}
