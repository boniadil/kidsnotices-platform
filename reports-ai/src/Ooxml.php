<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Shared pieces of Office Open XML files (.docx, .xlsx, .pptx), written without any library:
 * zip packaging, XML escaping, package metadata and the native line chart used by Excel and PowerPoint.
 */
final class Ooxml
{
    public const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';
    public const REL_PACKAGE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    public const XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    /** Text for XML: escaped, without characters XML does not allow. */
    public static function esc(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @param array<string, string> $files path in the package => content */
    public static function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rai');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip');
        }
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** @param list<array{0: string, 1: string, 2?: string}> $rels [id, type (last part of the URL), target, (mode)] */
    public static function rels(array $rels): string
    {
        $out = self::XML . '<Relationships xmlns="' . self::REL_PACKAGE . '">';
        foreach ($rels as [$id, $type, $target]) {
            $url = str_starts_with($type, 'http') ? $type : self::NS_REL . '/' . $type;
            $out .= '<Relationship Id="' . $id . '" Type="' . $url . '" Target="' . self::esc($target) . '"/>';
        }
        return $out . '</Relationships>';
    }

    /**
     * @param array<string, string> $defaults extension => content type
     * @param array<string, string> $overrides part name => content type
     */
    public static function contentTypes(array $defaults, array $overrides): string
    {
        $out = self::XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach ($defaults as $ext => $type) {
            $out .= '<Default Extension="' . $ext . '" ContentType="' . $type . '"/>';
        }
        foreach ($overrides + ['/docProps/core.xml' => 'application/vnd.openxmlformats-package.core-properties+xml', '/docProps/app.xml' => 'application/vnd.openxmlformats-officedocument.extended-properties+xml'] as $part => $type) {
            $out .= '<Override PartName="' . $part . '" ContentType="' . $type . '"/>';
        }
        return $out . '</Types>';
    }

    /** docProps/core.xml and docProps/app.xml */
    public static function props(string $title, string $creator, string $application): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return [
            'docProps/core.xml' => self::XML . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>' . self::esc($title) . '</dc:title><dc:creator>' . self::esc($creator) . '</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>',
            'docProps/app.xml' => self::XML . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::esc($application) . '</Application></Properties>',
        ];
    }

    /** Package-level relationships (_rels/.rels) to the main part and the properties. */
    public static function rootRels(string $mainPart): string
    {
        return self::rels([
            ['rId1', 'officeDocument', $mainPart],
            ['rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ['rId3', 'extended-properties', 'docProps/app.xml'],
        ]);
    }

    /** Excel formula reference: 'Sheet name'!$A$1:$A$9 */
    public static function ref(string $sheet, string $range): string
    {
        return "'" . str_replace("'", "''", $sheet) . "'!" . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $range);
    }

    /**
     * A line chart part (chartN.xml), with the values cached so every viewer draws it.
     *
     * @param array{title: string, name: string, nameRef: string, catRef: string, valRef: string, cats: list<int|string>,
     *              vals: list<float>, xTitle?: string, yTitle?: string, showValues?: bool, legend?: bool, numberFormat?: string, dataRel?: string} $o
     */
    public static function lineChart(array $o): string
    {
        $rich = fn (string $text, int $size, bool $bold) => '<c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="' . $size . '" b="' . (int) $bold . '"/></a:pPr><a:r><a:rPr lang="ar-MA" sz="' . $size . '" b="' . (int) $bold . '"/><a:t>' . self::esc($text) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/>';
        $points = function (array $values, bool $numbers) {
            $out = '<c:ptCount val="' . count($values) . '"/>';
            foreach (array_values($values) as $i => $v) {
                $out .= '<c:pt idx="' . $i . '"><c:v>' . self::esc($numbers ? self::num((float) $v) : (string) $v) . '</c:v></c:pt>';
            }
            return $out;
        };
        $axisTitle = fn (?string $t) => $t !== null && $t !== '' ? '<c:title>' . $rich($t, 1000, false) . '</c:title>' : '';
        $format = self::esc($o['numberFormat'] ?? '#,##0');
        $labels = ($o['showValues'] ?? false)
            ? '<c:dLbls><c:numFmt formatCode="' . $format . '" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr><c:dLblPos val="t"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>'
            : '';

        return self::XML . '<c:chartSpace xmlns:c="' . self::NS_C . '" xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_REL . '">'
            . '<c:roundedCorners val="0"/><c:chart>'
            . '<c:title>' . $rich($o['title'], 1400, true) . '</c:title><c:autoTitleDeleted val="0"/>'
            . '<c:plotArea><c:layout/><c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'
            . '<c:ser><c:idx val="0"/><c:order val="0"/>'
            . '<c:tx><c:strRef><c:f>' . self::esc($o['nameRef']) . '</c:f><c:strCache>' . $points([$o['name']], false) . '</c:strCache></c:strRef></c:tx>'
            . '<c:spPr><a:ln w="28575" cap="rnd"><a:solidFill><a:srgbClr val="1F5FA8"/></a:solidFill><a:round/></a:ln></c:spPr>'
            . '<c:marker><c:symbol val="circle"/><c:size val="6"/><c:spPr><a:solidFill><a:srgbClr val="1F5FA8"/></a:solidFill></c:spPr></c:marker>'
            . $labels
            . '<c:cat><c:numRef><c:f>' . self::esc($o['catRef']) . '</c:f><c:numCache><c:formatCode>General</c:formatCode>' . $points($o['cats'], true) . '</c:numCache></c:numRef></c:cat>'
            . '<c:val><c:numRef><c:f>' . self::esc($o['valRef']) . '</c:f><c:numCache><c:formatCode>General</c:formatCode>' . $points($o['vals'], true) . '</c:numCache></c:numRef></c:val>'
            . '<c:smooth val="0"/></c:ser>'
            . '<c:marker val="1"/><c:axId val="50010"/><c:axId val="50020"/></c:lineChart>'
            . '<c:catAx><c:axId val="50010"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/>' . $axisTitle($o['xTitle'] ?? null)
            . '<c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="50020"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
            . '<c:valAx><c:axId val="50020"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/>'
            . '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="D9D9D9"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>' . $axisTitle($o['yTitle'] ?? null)
            . '<c:numFmt formatCode="' . $format . '" sourceLinked="0"/><c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="50010"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx></c:plotArea>'
            . (($o['legend'] ?? true) ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' : '')
            . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>'
            . (isset($o['dataRel']) ? '<c:externalData r:id="' . $o['dataRel'] . '"><c:autoUpdate val="0"/></c:externalData>' : '')
            . '</c:chartSpace>';
    }

    /** Number as written in XML values (no locale, no exponent for ordinary values). */
    public static function num(float|int $v): string
    {
        return is_int($v) || (floor($v) === $v && abs($v) < 1e15) ? (string) (int) $v : (string) $v;
    }
}
