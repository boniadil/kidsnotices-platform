<?php

declare(strict_types=1);

namespace ReportsAI;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel exports (right-to-left sheets, real numbers, frozen headers):
 *  - one indicator: yearly values with change, source and quote, a statistics sheet and a line chart;
 *  - all indicators: a matrix indicator × year, plus every value with its source.
 */
final class Excel
{
    public static function series(array $s): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('السلسلة');
        $sheet->setRightToLeft(true);
        $sheet->fromArray([[$s['indicator']['name_ar']], [$s['indicator']['name_fr'] . ($s['indicator']['unit'] !== '' ? ' — ' . $s['indicator']['unit'] : '') . ($s['scope'] !== '' ? ' — ' . $s['scope'] : '')]], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $header = ['السنة', 'القيمة', 'التغير %', 'التقرير', 'الموضع', 'متحقق منه', 'النص الأصلي'];
        $sheet->fromArray($header, null, 'A4');
        $row = 5;
        foreach ($s['points'] as $i => $p) {
            $change = $i > 0 ? ($s['stats']['year_on_year'][$i - 1]['change_pct'] ?? null) : null;
            $sheet->fromArray([$p['year'], $p['value'], $change === null ? null : $change / 100, $p['source']['report'], $p['source']['label'], $p['source']['verified'] ? 'نعم' : 'للتحقق', $p['source']['quote']], null, "A$row", true);
            $row++;
        }
        $last = $row - 1;
        self::styleTable($sheet, 'A4:G4', $last);
        $sheet->getStyle("B5:B$last")->getNumberFormat()->setFormatCode(self::numberFormat(array_column($s['points'], 'value')));
        $sheet->getStyle("C5:C$last")->getNumberFormat()->setFormatCode('0.0%');
        foreach (['A' => 10, 'B' => 14, 'C' => 11, 'D' => 34, 'E' => 18, 'F' => 12, 'G' => 70] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        if (count($s['points']) >= 2) {
            $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'السلسلة'!\$B\$4", null, 1)];
            $cats = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'السلسلة'!\$A\$5:\$A\$$last", null, $last - 4)];
            $vals = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'السلسلة'!\$B\$5:\$B\$$last", null, $last - 4)];
            $series = new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, range(0, 0), $labels, $cats, $vals);
            $chart = new Chart('chart', new Title($s['indicator']['name_ar']), new Legend(Legend::POSITION_BOTTOM, null, false), new PlotArea(null, [$series]));
            $chart->setTopLeftPosition('I4');
            $chart->setBottomRightPosition('Q22');
            $sheet->addChart($chart);
        }

        $st = $s['stats'];
        if ($st) {
            $stats = $book->createSheet()->setTitle('الإحصائيات');
            $stats->setRightToLeft(true);
            $stats->fromArray([
                ['المؤشر', 'القيمة'],
                ['أول سنة', $st['first']['year']], ['أول قيمة', $st['first']['value']],
                ['آخر سنة', $st['last']['year']], ['آخر قيمة', $st['last']['value']],
                ['التغير الإجمالي', $st['total_change']], ['التغير الإجمالي %', $st['total_change_pct'] === null ? null : $st['total_change_pct'] / 100],
                ['معدل النمو السنوي المركب', $st['cagr_pct'] === null ? null : $st['cagr_pct'] / 100],
                ['الأعلى', $st['max']['value'] . ' (' . $st['max']['year'] . ')'], ['الأدنى', $st['min']['value'] . ' (' . $st['min']['year'] . ')'],
                ['المتوسط', $st['mean']], ['المجموع', $st['sum']],
                ['سنوات بدون معطى', implode('، ', $st['missing_years']) ?: '—'],
            ], null, 'A1', true);
            self::styleTable($stats, 'A1:B1', 13);
            $stats->getStyle('B7:B8')->getNumberFormat()->setFormatCode('0.00%');
            $stats->getColumnDimension('A')->setWidth(28);
            $stats->getColumnDimension('B')->setWidth(22);
        }
        $book->setActiveSheetIndex(0);
        return self::write($book, true);
    }

    public static function allIndicators(): string
    {
        $pdo = Database::pdo();
        $indicators = $pdo->query('SELECT i.id FROM indicators i WHERE EXISTS (SELECT 1 FROM facts f WHERE f.indicator_id = i.id) ORDER BY i.category, i.name_ar')->fetchAll(\PDO::FETCH_COLUMN);
        $years = array_map('intval', $pdo->query('SELECT DISTINCT year FROM facts ORDER BY year')->fetchAll(\PDO::FETCH_COLUMN));
        $categories = Config::domain()['categories'];

        $book = new Spreadsheet();
        $matrix = $book->getActiveSheet()->setTitle('المؤشرات حسب السنوات');
        $matrix->setRightToLeft(true);
        $matrix->fromArray(array_merge(['المؤشر', 'Indicateur', 'الوحدة', 'الصنف'], $years), null, 'A1');
        $sources = $book->createSheet()->setTitle('القيم والمصادر');
        $sources->setRightToLeft(true);
        $sources->fromArray(['المؤشر', 'Indicateur', 'الوحدة', 'السنة', 'القيمة', 'التقرير', 'الموضع', 'متحقق منه', 'النص الأصلي'], null, 'A1');

        $r = 2;
        $sr = 2;
        foreach ($indicators as $id) {
            $s = Stats::series((int) $id);
            $byYear = array_column($s['points'], 'value', 'year');
            $ind = $s['indicator'];
            $matrix->fromArray(array_merge([$ind['name_ar'], $ind['name_fr'], $ind['unit'], $categories[$ind['category']]['ar'] ?? $ind['category']], array_map(fn ($y) => $byYear[$y] ?? null, $years)), null, "A$r", true);
            $r++;
            foreach ($s['points'] as $p) {
                $sources->fromArray([$ind['name_ar'], $ind['name_fr'], $ind['unit'], $p['year'], $p['value'], $p['source']['report'], $p['source']['label'], $p['source']['verified'] ? 'نعم' : 'للتحقق', $p['source']['quote']], null, "A$sr", true);
                $sr++;
            }
        }
        $lastCol = Coordinate::stringFromColumnIndex(4 + max(1, count($years)));
        self::styleTable($matrix, "A1:{$lastCol}1", $r - 1);
        $matrix->freezePane('E2');
        foreach (['A' => 42, 'B' => 42, 'C' => 12, 'D' => 26] as $col => $w) {
            $matrix->getColumnDimension($col)->setWidth($w);
        }
        self::styleTable($sources, 'A1:I1', $sr - 1);
        $sources->freezePane('A2');
        foreach (['A' => 40, 'B' => 40, 'C' => 10, 'D' => 8, 'E' => 14, 'F' => 32, 'G' => 18, 'H' => 10, 'I' => 70] as $col => $w) {
            $sources->getColumnDimension($col)->setWidth($w);
        }
        return self::write($book, false);
    }

    /** Thousands separator; decimals only when some value has them. */
    private static function numberFormat(array $values): string
    {
        foreach ($values as $v) {
            if (floor((float) $v) != (float) $v) {
                return '#,##0.00';
            }
        }
        return '#,##0';
    }

    private static function styleTable(Worksheet $sheet, string $headerRange, int $lastRow): void
    {
        $style = $sheet->getStyle($headerRange);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F5FA8');
        $sheet->setAutoFilter(preg_replace('/\d+$/', (string) max($lastRow, (int) preg_replace('/\D/', '', explode(':', $headerRange)[0])), $headerRange));
    }

    private static function write(Spreadsheet $book, bool $charts): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rai') . '.xlsx';
        $writer = new Xlsx($book);
        $writer->setIncludeCharts($charts);
        $writer->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $book->disconnectWorksheets();
        return $bytes;
    }
}
