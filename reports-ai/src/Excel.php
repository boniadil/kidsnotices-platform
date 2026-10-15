<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Excel exports (right-to-left sheets, real numbers, frozen headers):
 *  - one indicator: yearly values with change, source and quote, a statistics sheet and a line chart;
 *  - all indicators: a matrix indicator × year, plus every value with its source.
 */
final class Excel
{
    public static function series(array $s): string
    {
        $x = new XlsxWriter($s['indicator']['name_ar']);
        $sheet = $x->addSheet('السلسلة');
        $x->row($sheet, 'A1', [$s['indicator']['name_ar']], XlsxWriter::TITLE);
        $x->row($sheet, 'A2', [$s['indicator']['name_fr'] . ($s['indicator']['unit'] !== '' ? ' — ' . $s['indicator']['unit'] : '') . ($s['scope'] !== '' ? ' — ' . $s['scope'] : '')]);

        $x->row($sheet, 'A4', ['السنة', 'القيمة', 'التغير %', 'التقرير', 'الموضع', 'متحقق منه', 'النص الأصلي'], XlsxWriter::HEADER);
        $row = 5;
        foreach ($s['points'] as $i => $p) {
            $change = $i > 0 ? ($s['stats']['year_on_year'][$i - 1]['change_pct'] ?? null) : null;
            $x->row($sheet, "A$row", [$p['year'], $p['value'], $change === null ? null : $change / 100, $p['source']['report'], $p['source']['label'], $p['source']['verified'] ? 'نعم' : 'للتحقق', $p['source']['quote']]);
            $row++;
        }
        $last = max(5, $row - 1);
        $x->autoFilter($sheet, "A4:G$last");
        $x->style($sheet, "B5:B$last", self::numberFormat(array_column($s['points'], 'value')));
        $x->style($sheet, "C5:C$last", XlsxWriter::PERCENT1);
        $x->widths($sheet, ['A' => 10, 'B' => 14, 'C' => 11, 'D' => 34, 'E' => 18, 'F' => 12, 'G' => 70]);
        if (count($s['points']) >= 2) {
            $x->lineChart($sheet, ['title' => $s['indicator']['name_ar'], 'nameCell' => 'B4', 'cats' => "A5:A$last", 'vals' => "B5:B$last", 'from' => 'I4', 'to' => 'Q22']);
        }

        $st = $s['stats'];
        if ($st) {
            $stats = $x->addSheet('الإحصائيات');
            $x->row($stats, 'A1', ['المؤشر', 'القيمة'], XlsxWriter::HEADER);
            $lines = [
                ['أول سنة', $st['first']['year']], ['أول قيمة', $st['first']['value']],
                ['آخر سنة', $st['last']['year']], ['آخر قيمة', $st['last']['value']],
                ['التغير الإجمالي', $st['total_change']], ['التغير الإجمالي %', $st['total_change_pct'] === null ? null : $st['total_change_pct'] / 100],
                ['معدل النمو السنوي المركب', $st['cagr_pct'] === null ? null : $st['cagr_pct'] / 100],
                ['الأعلى', $st['max']['value'] . ' (' . $st['max']['year'] . ')'], ['الأدنى', $st['min']['value'] . ' (' . $st['min']['year'] . ')'],
                ['المتوسط', $st['mean']], ['المجموع', $st['sum']],
                ['سنوات بدون معطى', implode('، ', $st['missing_years']) ?: '—'],
            ];
            foreach ($lines as $k => $line) {
                $x->row($stats, 'A' . ($k + 2), $line);
            }
            $x->autoFilter($stats, 'A1:B13');
            $x->style($stats, 'B7:B8', XlsxWriter::PERCENT2);
            $x->widths($stats, ['A' => 28, 'B' => 22]);
        }
        return $x->bytes();
    }

    public static function allIndicators(): string
    {
        $pdo = Database::pdo();
        $indicators = $pdo->query('SELECT i.id FROM indicators i WHERE EXISTS (SELECT 1 FROM facts f WHERE f.indicator_id = i.id) ORDER BY i.category, i.name_ar')->fetchAll(\PDO::FETCH_COLUMN);
        $years = array_map('intval', $pdo->query('SELECT DISTINCT year FROM facts ORDER BY year')->fetchAll(\PDO::FETCH_COLUMN));
        $categories = Config::domain()['categories'];

        $x = new XlsxWriter('المؤشرات حسب السنوات');
        $matrix = $x->addSheet('المؤشرات حسب السنوات');
        $x->row($matrix, 'A1', array_merge(['المؤشر', 'Indicateur', 'الوحدة', 'الصنف'], $years), XlsxWriter::HEADER);
        $sources = $x->addSheet('القيم والمصادر');
        $x->row($sources, 'A1', ['المؤشر', 'Indicateur', 'الوحدة', 'السنة', 'القيمة', 'التقرير', 'الموضع', 'متحقق منه', 'النص الأصلي'], XlsxWriter::HEADER);

        $r = 2;
        $sr = 2;
        foreach ($indicators as $id) {
            $s = Stats::series((int) $id);
            $byYear = array_column($s['points'], 'value', 'year');
            $ind = $s['indicator'];
            $x->row($matrix, "A$r", array_merge([$ind['name_ar'], $ind['name_fr'], $ind['unit'], $categories[$ind['category']]['ar'] ?? $ind['category']], array_map(fn ($y) => $byYear[$y] ?? null, $years)));
            $r++;
            foreach ($s['points'] as $p) {
                $x->row($sources, "A$sr", [$ind['name_ar'], $ind['name_fr'], $ind['unit'], $p['year'], $p['value'], $p['source']['report'], $p['source']['label'], $p['source']['verified'] ? 'نعم' : 'للتحقق', $p['source']['quote']]);
                $sr++;
            }
        }
        $lastCol = XlsxWriter::letter(3 + max(1, count($years)));
        $x->autoFilter($matrix, "A1:{$lastCol}" . max(2, $r - 1));
        $x->freeze($matrix, 'E2');
        $x->widths($matrix, ['A' => 42, 'B' => 42, 'C' => 12, 'D' => 26]);
        $x->autoFilter($sources, 'A1:I' . max(2, $sr - 1));
        $x->freeze($sources, 'A2');
        $x->widths($sources, ['A' => 40, 'B' => 40, 'C' => 10, 'D' => 8, 'E' => 14, 'F' => 32, 'G' => 18, 'H' => 10, 'I' => 70]);
        return $x->bytes();
    }

    /** Thousands separator; decimals only when some value has them. */
    private static function numberFormat(array $values): int
    {
        foreach ($values as $v) {
            if (floor((float) $v) != (float) $v) {
                return XlsxWriter::DECIMAL;
            }
        }
        return XlsxWriter::INTEGER;
    }
}
