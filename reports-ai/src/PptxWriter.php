<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Minimal .pptx writer (no library). Slides are built from shape XML (see the shape helpers),
 * with an optional background color, speaker notes and native charts (with their data embedded
 * as a workbook, so PowerPoint can edit them). Sizes are in pixels of a 960 × 540 slide (16:9).
 */
final class PptxWriter
{
    public const W = 960;
    public const H = 540;
    private const EMU = 9525; // per pixel
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    /** @var list<array{shapes: string, bg: ?string, notes: string, charts: list<array{xml: string, xlsx: string}>, rtl: bool}> */
    private array $slides = [];
    private int $nextId = 2;

    public function __construct(private readonly string $title = '', private readonly string $creator = '')
    {
    }

    /**
     * @param string $shapes shapes XML from the helpers below
     * @param list<array{xml: string, xlsx: string}> $charts chart parts referenced as rId2, rId3… in the order given
     */
    public function addSlide(string $shapes, ?string $background = null, string $notes = '', array $charts = [], bool $rtl = false): void
    {
        $this->slides[] = ['shapes' => $shapes, 'bg' => $background, 'notes' => $notes, 'charts' => $charts, 'rtl' => $rtl];
    }

    // ---- shapes ---------------------------------------------------------------------------------

    /**
     * Text box. Each paragraph: ['text' => string, 'size' => pt, 'bold' => bool, 'color' => 'RRGGBB', 'bullet' => bool].
     *
     * @param list<array> $paragraphs
     */
    public function text(int $x, int $y, int $w, int $h, array $paragraphs, bool $rtl, ?string $fill = null, string $anchor = 't'): string
    {
        $id = $this->nextId++;
        $paras = implode('', array_map(fn ($p) => self::paragraph($p, $rtl), $paragraphs)) ?: '<a:p><a:endParaRPr lang="ar-MA"/></a:p>';
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Text ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>' . self::xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . ($fill !== null ? '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>' : '<a:noFill/>') . '</p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="91440" tIns="45720" rIns="91440" bIns="45720" rtlCol="0" anchor="' . $anchor . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>' . $paras . '</p:txBody></p:sp>';
    }

    /**
     * Table: first row is the header (bold white on blue).
     *
     * @param list<list<string>> $rows
     */
    public function table(int $x, int $y, int $w, array $rows, bool $rtl, int $rowHeight = 32): string
    {
        $id = $this->nextId++;
        $cols = max(array_map('count', $rows) ?: [1]);
        $colW = intdiv($w * self::EMU, $cols);
        $line = fn (string $side) => "<a:$side w=\"6350\"><a:solidFill><a:srgbClr val=\"BFC7D5\"/></a:solidFill></a:$side>";
        $xml = '<a:tbl><a:tblPr firstRow="1" bandRow="1"/><a:tblGrid>' . str_repeat("<a:gridCol w=\"$colW\"/>", $cols) . '</a:tblGrid>';
        foreach ($rows as $r => $cells) {
            $xml .= '<a:tr h="' . ($rowHeight * self::EMU) . '">';
            foreach (array_pad($cells, $cols, '') as $cell) {
                $p = self::paragraph(['text' => $cell, 'size' => 13, 'bold' => $r === 0, 'color' => $r === 0 ? 'FFFFFF' : '1D2533'], $rtl);
                $xml .= '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/>' . $p . '</a:txBody><a:tcPr marL="76200" marR="76200" marT="38100" marB="38100" anchor="ctr">'
                    . $line('lnL') . $line('lnR') . $line('lnT') . $line('lnB')
                    . '<a:solidFill><a:srgbClr val="' . ($r === 0 ? '1F5FA8' : ($r % 2 === 0 ? 'EEF2F8' : 'FFFFFF')) . '"/></a:solidFill></a:tcPr></a:tc>';
            }
            $xml .= '</a:tr>';
        }
        $xml .= '</a:tbl>';
        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Table ' . $id . '"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            . '<p:xfrm><a:off x="' . ($x * self::EMU) . '" y="' . ($y * self::EMU) . '"/><a:ext cx="' . ($colW * $cols) . '" cy="' . (count($rows) * $rowHeight * self::EMU) . '"/></p:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">' . $xml . '</a:graphicData></a:graphic></p:graphicFrame>';
    }

    /** Frame showing the chart part referenced by $rid (see addSlide). */
    public function chartFrame(int $x, int $y, int $w, int $h, string $rid): string
    {
        $id = $this->nextId++;
        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Chart ' . $id . '"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr>'
            . '<p:xfrm><a:off x="' . ($x * self::EMU) . '" y="' . ($y * self::EMU) . '"/><a:ext cx="' . ($w * self::EMU) . '" cy="' . ($h * self::EMU) . '"/></p:xfrm>'
            . '<a:graphic><a:graphicData uri="' . Ooxml::NS_C . '"><c:chart xmlns:c="' . Ooxml::NS_C . '" r:id="' . $rid . '"/></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /**
     * A line chart part with its data workbook.
     *
     * @param array<int|string, float> $points year => value
     * @return array{xml: string, xlsx: string}
     */
    public static function lineChart(string $title, string $seriesName, array $points, string $xTitle, string $yTitle): array
    {
        $book = new XlsxWriter($title);
        $sheet = $book->addSheet('Sheet1', false);
        $book->row($sheet, 'A1', [$xTitle, $seriesName]);
        $r = 2;
        foreach ($points as $year => $value) {
            $book->row($sheet, "A$r", [(int) $year, $value]);
            $r++;
        }
        $last = $r - 1;
        return [
            'xml' => Ooxml::lineChart([
                'title' => $title, 'name' => $seriesName, 'nameRef' => 'Sheet1!$B$1', 'catRef' => "Sheet1!\$A\$2:\$A\$$last", 'valRef' => "Sheet1!\$B\$2:\$B\$$last",
                'cats' => array_map('intval', array_keys($points)), 'vals' => array_values($points),
                'xTitle' => $xTitle, 'yTitle' => $yTitle, 'showValues' => true, 'legend' => false, 'dataRel' => 'rId1',
                'numberFormat' => array_filter($points, fn ($v) => floor($v) != $v) ? '#,##0.0' : '#,##0',
            ]),
            'xlsx' => $book->bytes(),
        ];
    }

    // ---- package --------------------------------------------------------------------------------

    public function bytes(): string
    {
        $ct = [
            '/ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            '/ppt/slideMasters/slideMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml',
            '/ppt/slideLayouts/slideLayout1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml',
            '/ppt/notesMasters/notesMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml',
            '/ppt/theme/theme1.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
            '/ppt/theme/theme2.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
            '/ppt/presProps.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presProps+xml',
            '/ppt/viewProps.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml',
            '/ppt/tableStyles.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml',
        ];
        $ns = 'xmlns:a="' . Ooxml::NS_A . '" xmlns:r="' . Ooxml::NS_REL . '" xmlns:p="' . self::NS_P . '"';
        $group = '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
        $files = [];
        $presRels = [['rId1', 'slideMaster', 'slideMasters/slideMaster1.xml']];
        $sldIds = '';
        $chartNo = 0;
        foreach ($this->slides as $i => $s) {
            $n = $i + 1;
            $rid = 'rId' . ($n + 1);
            $presRels[] = [$rid, 'slide', "slides/slide$n.xml"];
            $sldIds .= '<p:sldId id="' . (255 + $n) . '" r:id="' . $rid . '"/>';
            $ct["/ppt/slides/slide$n.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';

            $rels = [['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml']];
            foreach ($s['charts'] as $k => $chart) {
                $chartNo++;
                $rels[] = ['rId' . ($k + 2), 'chart', "../charts/chart$chartNo.xml"];
                $files["ppt/charts/chart$chartNo.xml"] = $chart['xml'];
                $files["ppt/charts/_rels/chart$chartNo.xml.rels"] = Ooxml::rels([['rId1', 'package', "../embeddings/Microsoft_Excel_Worksheet$chartNo.xlsx"]]);
                $files["ppt/embeddings/Microsoft_Excel_Worksheet$chartNo.xlsx"] = $chart['xlsx'];
                $ct["/ppt/charts/chart$chartNo.xml"] = 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml';
            }
            if (trim($s['notes']) !== '') {
                $rels[] = ['rId' . (count($rels) + 1), 'notesSlide', "../notesSlides/notesSlide$n.xml"];
                $ct["/ppt/notesSlides/notesSlide$n.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml';
                $files["ppt/notesSlides/notesSlide$n.xml"] = Ooxml::XML . "<p:notes $ns><p:cSld><p:spTree>$group"
                    . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Notes"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>'
                    . '<p:spPr><a:xfrm><a:off x="685800" y="4400550"/><a:ext cx="5486400" cy="3600450"/></a:xfrm></p:spPr>'
                    . '<p:txBody><a:bodyPr/><a:lstStyle/>' . implode('', array_map(fn ($l) => self::paragraph(['text' => $l, 'size' => 12], $s['rtl']), explode("\n", trim($s['notes'])))) . '</p:txBody></p:sp>'
                    . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
                $files["ppt/notesSlides/_rels/notesSlide$n.xml.rels"] = Ooxml::rels([['rId1', 'notesMaster', '../notesMasters/notesMaster1.xml'], ['rId2', 'slide', "../slides/slide$n.xml"]]);
            }
            $bg = $s['bg'] !== null ? '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . $s['bg'] . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>' : '';
            $files["ppt/slides/slide$n.xml"] = Ooxml::XML . "<p:sld $ns><p:cSld>$bg<p:spTree>$group{$s['shapes']}</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>";
            $files["ppt/slides/_rels/slide$n.xml.rels"] = Ooxml::rels($rels);
        }
        $k = count($this->slides) + 2;
        $presRels[] = ['rId' . $k, 'notesMaster', 'notesMasters/notesMaster1.xml'];
        $presRels[] = ['rId' . ($k + 1), 'theme', 'theme/theme1.xml'];
        $presRels[] = ['rId' . ($k + 2), 'presProps', 'presProps.xml'];
        $presRels[] = ['rId' . ($k + 3), 'viewProps', 'viewProps.xml'];
        $presRels[] = ['rId' . ($k + 4), 'tableStyles', 'tableStyles.xml'];

        $level = fn (int $size) => '<a:lvl1pPr><a:defRPr sz="' . $size . '"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="+mn-lt"/><a:ea typeface="+mn-ea"/><a:cs typeface="+mn-cs"/></a:defRPr></a:lvl1pPr>';
        $files += [
            '[Content_Types].xml' => Ooxml::contentTypes(['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $ct),
            '_rels/.rels' => Ooxml::rootRels('ppt/presentation.xml'),
            'ppt/presentation.xml' => Ooxml::XML . "<p:presentation $ns saveSubsetFonts=\"1\">"
                . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
                . '<p:notesMasterIdLst><p:notesMasterId r:id="rId' . $k . '"/></p:notesMasterIdLst>'
                . "<p:sldIdLst>$sldIds</p:sldIdLst>"
                . '<p:sldSz cx="' . (self::W * self::EMU) . '" cy="' . (self::H * self::EMU) . '"/><p:notesSz cx="6858000" cy="9144000"/>'
                . '<p:defaultTextStyle>' . $level(1800) . '</p:defaultTextStyle></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => Ooxml::rels($presRels),
            'ppt/slideMasters/slideMaster1.xml' => Ooxml::XML . "<p:sldMaster $ns><p:cSld><p:bg><p:bgRef idx=\"1001\"><a:schemeClr val=\"bg1\"/></p:bgRef></p:bg><p:spTree>$group</p:spTree></p:cSld>"
                . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
                . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
                . '<p:txStyles><p:titleStyle>' . $level(4400) . '</p:titleStyle><p:bodyStyle>' . $level(2400) . '</p:bodyStyle><p:otherStyle>' . $level(1800) . '</p:otherStyle></p:txStyles></p:sldMaster>',
            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => Ooxml::rels([['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml'], ['rId2', 'theme', '../theme/theme1.xml']]),
            'ppt/slideLayouts/slideLayout1.xml' => Ooxml::XML . "<p:sldLayout $ns type=\"blank\" preserve=\"1\"><p:cSld name=\"Blank\"><p:spTree>$group</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>",
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => Ooxml::rels([['rId1', 'slideMaster', '../slideMasters/slideMaster1.xml']]),
            'ppt/notesMasters/notesMaster1.xml' => Ooxml::XML . "<p:notesMaster $ns><p:cSld><p:bg><p:bgRef idx=\"1001\"><a:schemeClr val=\"bg1\"/></p:bgRef></p:bg><p:spTree>$group"
                . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Notes Placeholder"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="685800" y="4400550"/><a:ext cx="5486400" cy="3600450"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
                . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="ar-MA"/></a:p></p:txBody></p:sp></p:spTree></p:cSld>'
                . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
                . '<p:notesStyle>' . $level(1200) . '</p:notesStyle></p:notesMaster>',
            'ppt/notesMasters/_rels/notesMaster1.xml.rels' => Ooxml::rels([['rId1', 'theme', '../theme/theme2.xml']]),
            'ppt/theme/theme1.xml' => self::theme(),
            'ppt/theme/theme2.xml' => self::theme(),
            'ppt/presProps.xml' => Ooxml::XML . "<p:presentationPr $ns/>",
            'ppt/viewProps.xml' => Ooxml::XML . "<p:viewPr $ns><p:gridSpacing cx=\"76200\" cy=\"76200\"/></p:viewPr>",
            'ppt/tableStyles.xml' => Ooxml::XML . '<a:tblStyleLst xmlns:a="' . Ooxml::NS_A . '" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>',
        ];
        return Ooxml::zip($files + Ooxml::props($this->title, $this->creator, 'Reports AI'));
    }

    // ---- helpers --------------------------------------------------------------------------------

    private static function xfrm(int $x, int $y, int $w, int $h): string
    {
        return '<a:xfrm><a:off x="' . ($x * self::EMU) . '" y="' . ($y * self::EMU) . '"/><a:ext cx="' . ($w * self::EMU) . '" cy="' . ($h * self::EMU) . '"/></a:xfrm>';
    }

    private static function paragraph(array $p, bool $rtl): string
    {
        $bullet = $p['bullet'] ?? false;
        $margin = $bullet ? ($rtl ? ' marR="228600"' : ' marL="228600"') . ' indent="-228600"' : '';
        $pPr = '<a:pPr algn="' . ($rtl ? 'r' : 'l') . '"' . ($rtl ? ' rtl="1"' : '') . $margin . '>'
            . (isset($p['spacing']) ? '<a:lnSpc><a:spcPct val="' . ($p['spacing'] * 1000) . '"/></a:lnSpc>' : '')
            . (isset($p['after']) ? '<a:spcAft><a:spcPts val="' . ($p['after'] * 100) . '"/></a:spcAft>' : '')
            . ($bullet ? '<a:buFont typeface="Arial"/><a:buChar char="•"/>' : '<a:buNone/>') . '</a:pPr>';
        $rPr = '<a:rPr lang="' . ($rtl ? 'ar-MA' : 'fr-FR') . '" sz="' . (($p['size'] ?? 18) * 100) . '"' . (($p['bold'] ?? false) ? ' b="1"' : '') . ' dirty="0">'
            . '<a:solidFill><a:srgbClr val="' . ($p['color'] ?? '1D2533') . '"/></a:solidFill><a:latin typeface="Calibri"/><a:cs typeface="Arial"/></a:rPr>';
        return '<a:p>' . $pPr . '<a:r>' . $rPr . '<a:t>' . Ooxml::esc((string) $p['text']) . '</a:t></a:r></a:p>';
    }

    private static function theme(): string
    {
        $colors = ['dk1' => '<a:sysClr val="windowText" lastClr="000000"/>', 'lt1' => '<a:sysClr val="window" lastClr="FFFFFF"/>', 'dk2' => '<a:srgbClr val="1A3D7C"/>', 'lt2' => '<a:srgbClr val="EEF2F8"/>',
            'accent1' => '<a:srgbClr val="1F5FA8"/>', 'accent2' => '<a:srgbClr val="E07B39"/>', 'accent3' => '<a:srgbClr val="5B9A68"/>', 'accent4' => '<a:srgbClr val="C9A227"/>',
            'accent5' => '<a:srgbClr val="7E57C2"/>', 'accent6' => '<a:srgbClr val="2BA6A0"/>', 'hlink' => '<a:srgbClr val="1F5FA8"/>', 'folHlink' => '<a:srgbClr val="7E57C2"/>'];
        $scheme = '';
        foreach ($colors as $name => $c) {
            $scheme .= "<a:$name>$c</a:$name>";
        }
        $fonts = '<a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface="Arial"/>';
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = fn (int $w) => '<a:ln w="' . $w . '" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>';
        return Ooxml::XML . '<a:theme xmlns:a="' . Ooxml::NS_A . '" name="Reports AI"><a:themeElements>'
            . '<a:clrScheme name="Reports AI">' . $scheme . '</a:clrScheme>'
            . '<a:fontScheme name="Reports AI"><a:majorFont>' . $fonts . '</a:majorFont><a:minorFont>' . $fonts . '</a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="Reports AI"><a:fillStyleLst>' . str_repeat($fill, 3) . '</a:fillStyleLst>'
            . '<a:lnStyleLst>' . $line(6350) . $line(12700) . $line(19050) . '</a:lnStyleLst>'
            . '<a:effectStyleLst>' . str_repeat('<a:effectStyle><a:effectLst/></a:effectStyle>', 3) . '</a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . str_repeat($fill, 3) . '</a:bgFillStyleLst></a:fmtScheme>'
            . '</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}
