<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Minimal .xlsx writer (no library): sheets of text and numbers, a few fixed styles, column widths,
 * right-to-left sheets, frozen panes, auto-filters and native line charts.
 */
final class XlsxWriter
{
    // Cell styles (index in styles.xml cellXfs)
    public const NORMAL = 0;
    public const HEADER = 1;  // bold white on blue
    public const INTEGER = 2; // #,##0
    public const DECIMAL = 3; // #,##0.00
    public const PERCENT1 = 4; // 0.0%
    public const PERCENT2 = 5; // 0.00%
    public const TITLE = 6;   // bold 14

    /** @var list<array{name: string, rtl: bool, cells: array<int, array<int, array{0: mixed, 1: int}>>, widths: array<int, float>, freeze: ?string, filter: ?string, charts: list<array>}> */
    private array $sheets = [];

    public function __construct(private readonly string $title = '', private readonly string $creator = '')
    {
    }

    /** Returns the sheet index. Names are cut to 31 characters without []:*?/\ */
    public function addSheet(string $name, bool $rtl = true): int
    {
        $name = mb_substr(trim(preg_replace('#[\[\]:*?/\\\\]#u', ' ', $name)), 0, 31) ?: 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = ['name' => $name, 'rtl' => $rtl, 'cells' => [], 'widths' => [], 'freeze' => null, 'filter' => null, 'charts' => []];
        return count($this->sheets) - 1;
    }

    public function name(int $sheet): string
    {
        return $this->sheets[$sheet]['name'];
    }

    /** Writes values from a cell to the right ("A5"); null leaves the cell empty. */
    public function row(int $sheet, string $start, array $values, int $style = self::NORMAL): void
    {
        [$col, $row] = self::cell($start);
        foreach (array_values($values) as $i => $v) {
            if ($v !== null && $v !== '') {
                $this->sheets[$sheet]['cells'][$row][$col + $i] = [$v, $style];
            } elseif ($style !== self::NORMAL) {
                $this->sheets[$sheet]['cells'][$row][$col + $i] = ['', $style];
            }
        }
    }

    /** Applies a style to existing cells of a range ("B5:B9"). */
    public function style(int $sheet, string $range, int $style): void
    {
        [[$c1, $r1], [$c2, $r2]] = self::range($range);
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                if (isset($this->sheets[$sheet]['cells'][$r][$c])) {
                    $this->sheets[$sheet]['cells'][$r][$c][1] = $style;
                }
            }
        }
    }

    /** @param array<string, float> $widths column letter => width (characters) */
    public function widths(int $sheet, array $widths): void
    {
        foreach ($widths as $letter => $w) {
            $this->sheets[$sheet]['widths'][self::cell($letter . '1')[0]] = $w;
        }
    }

    /** Freezes rows above and columns left of a cell ("E2"). */
    public function freeze(int $sheet, string $cell): void
    {
        $this->sheets[$sheet]['freeze'] = $cell;
    }

    public function autoFilter(int $sheet, string $range): void
    {
        $this->sheets[$sheet]['filter'] = $range;
    }

    /**
     * Line chart of a column of values against a column of years, placed between two cells.
     *
     * @param array{title: string, nameCell: string, cats: string, vals: string, from: string, to: string} $o
     */
    public function lineChart(int $sheet, array $o): void
    {
        $this->sheets[$sheet]['charts'][] = $o;
    }

    public function bytes(): string
    {
        $files = [];
        $overrides = [
            '/xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            '/xl/styles.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml',
        ];
        $sheetsXml = '';
        $defined = '';
        $wbRels = [];
        $chartNo = 0;
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $sheetsXml .= '<sheet name="' . Ooxml::esc($s['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels[] = ["rId$n", 'worksheet', "worksheets/sheet$n.xml"];
            $overrides["/xl/worksheets/sheet$n.xml"] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
            if ($s['filter'] !== null) {
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . Ooxml::esc(Ooxml::ref($s['name'], $s['filter'])) . '</definedName>';
            }

            $drawing = '';
            if ($s['charts'] !== []) {
                $anchors = '';
                $drawingRels = [];
                foreach ($s['charts'] as $k => $c) {
                    $chartNo++;
                    $files["xl/charts/chart$chartNo.xml"] = Ooxml::lineChart([
                        'title' => $c['title'],
                        'name' => (string) $this->value($i, $c['nameCell']),
                        'nameRef' => Ooxml::ref($s['name'], $c['nameCell']),
                        'catRef' => Ooxml::ref($s['name'], $c['cats']),
                        'valRef' => Ooxml::ref($s['name'], $c['vals']),
                        'cats' => $this->values($i, $c['cats']),
                        'vals' => array_map('floatval', $this->values($i, $c['vals'])),
                        'legend' => true,
                    ]);
                    $overrides["/xl/charts/chart$chartNo.xml"] = 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml';
                    $drawingRels[] = ['rId' . ($k + 1), 'chart', "../charts/chart$chartNo.xml"];
                    [$fc, $fr] = self::cell($c['from']);
                    [$tc, $tr] = self::cell($c['to']);
                    $anchors .= '<xdr:twoCellAnchor editAs="oneCell">'
                        . "<xdr:from><xdr:col>$fc</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>" . ($fr - 1) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                        . "<xdr:to><xdr:col>$tc</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>" . ($tr - 1) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                        . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . ($k + 2) . '" name="Chart ' . ($k + 1) . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
                        . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="' . Ooxml::NS_C . '">'
                        . '<c:chart xmlns:c="' . Ooxml::NS_C . '" r:id="rId' . ($k + 1) . '"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
                }
                $files["xl/drawings/drawing$n.xml"] = Ooxml::XML . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="' . Ooxml::NS_A . '" xmlns:r="' . Ooxml::NS_REL . '">' . $anchors . '</xdr:wsDr>';
                $files["xl/drawings/_rels/drawing$n.xml.rels"] = Ooxml::rels($drawingRels);
                $files["xl/worksheets/_rels/sheet$n.xml.rels"] = Ooxml::rels([['rId1', 'drawing', "../drawings/drawing$n.xml"]]);
                $overrides["/xl/drawings/drawing$n.xml"] = 'application/vnd.openxmlformats-officedocument.drawing+xml';
                $drawing = '<drawing r:id="rId1"/>';
            }
            $files["xl/worksheets/sheet$n.xml"] = $this->sheetXml($s, $i === 0, $drawing);
        }
        $wbRels[] = ['rId' . (count($this->sheets) + 1), 'styles', 'styles.xml'];

        $files['xl/workbook.xml'] = Ooxml::XML . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . Ooxml::NS_REL . '">'
            . '<workbookPr/><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheetsXml . '</sheets>'
            . ($defined !== '' ? "<definedNames>$defined</definedNames>" : '') . '<calcPr calcId="191029"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = Ooxml::rels($wbRels);
        $files['xl/styles.xml'] = self::stylesXml();
        $files['[Content_Types].xml'] = Ooxml::contentTypes([], $overrides);
        $files['_rels/.rels'] = Ooxml::rootRels('xl/workbook.xml');
        return Ooxml::zip($files + Ooxml::props($this->title, $this->creator, 'Reports AI'));
    }

    private function sheetXml(array $s, bool $selected, string $drawing): string
    {
        ksort($s['cells']);
        $data = '';
        $maxCol = 0;
        $maxRow = 1;
        foreach ($s['cells'] as $r => $cells) {
            ksort($cells);
            $data .= '<row r="' . $r . '">';
            foreach ($cells as $c => [$v, $style]) {
                $ref = self::letter($c) . $r;
                $st = $style !== self::NORMAL ? ' s="' . $style . '"' : '';
                if (is_int($v) || is_float($v)) {
                    $data .= "<c r=\"$ref\"$st><v>" . Ooxml::num($v) . '</v></c>';
                } else {
                    $data .= "<c r=\"$ref\"$st t=\"inlineStr\"><is><t xml:space=\"preserve\">" . Ooxml::esc((string) $v) . '</t></is></c>';
                }
                $maxCol = max($maxCol, $c);
            }
            $data .= '</row>';
            $maxRow = max($maxRow, $r);
        }

        $pane = '';
        if ($s['freeze'] !== null) {
            [$fc, $fr] = self::cell($s['freeze']);
            $x = $fc;
            $y = $fr - 1;
            $active = $x > 0 && $y > 0 ? 'bottomRight' : ($y > 0 ? 'bottomLeft' : 'topRight');
            $pane = '<pane' . ($x > 0 ? " xSplit=\"$x\"" : '') . ($y > 0 ? " ySplit=\"$y\"" : '') . ' topLeftCell="' . $s['freeze'] . "\" activePane=\"$active\" state=\"frozen\"/><selection pane=\"$active\"/>";
        }
        $cols = '';
        ksort($s['widths']);
        foreach ($s['widths'] as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }

        return Ooxml::XML . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . Ooxml::NS_REL . '">'
            . '<dimension ref="A1:' . self::letter($maxCol) . $maxRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"' . ($s['rtl'] ? ' rightToLeft="1"' : '') . ($selected ? ' tabSelected="1"' : '') . '>' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? "<cols>$cols</cols>" : '')
            . "<sheetData>$data</sheetData>"
            . ($s['filter'] !== null ? '<autoFilter ref="' . $s['filter'] . '"/>' : '')
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . $drawing . '</worksheet>';
    }

    private static function stylesXml(): string
    {
        return Ooxml::XML . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F5FA8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function value(int $sheet, string $cell): mixed
    {
        [$c, $r] = self::cell($cell);
        return $this->sheets[$sheet]['cells'][$r][$c][0] ?? '';
    }

    private function values(int $sheet, string $range): array
    {
        [[$c1, $r1], [$c2, $r2]] = self::range($range);
        $out = [];
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                $out[] = $this->sheets[$sheet]['cells'][$r][$c][0] ?? '';
            }
        }
        return $out;
    }

    /** "B5" → [1, 5] (zero-based column, row number) */
    private static function cell(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }
        return [$n - 1, (int) $m[2]];
    }

    private static function range(string $range): array
    {
        [$a, $b] = explode(':', $range) + [1 => $range];
        return [self::cell($a), self::cell($b)];
    }

    /** zero-based column → letters */
    public static function letter(int $col): string
    {
        $s = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }
}
