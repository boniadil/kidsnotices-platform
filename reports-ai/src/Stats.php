<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Time series of an indicator, with one value per year and its source, and the usual
 * statistics computed here (exact arithmetic, never left to the model).
 */
final class Stats
{
    public static function indicator(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM indicators WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function series(int $indicatorId, ?int $from = null, ?int $to = null, string $scope = ''): array
    {
        $indicator = self::indicator($indicatorId) ?? throw new UserError("Indicator #$indicatorId not found.");
        $stmt = Database::pdo()->prepare(
            'SELECT f.year, f.value, f.unit, f.scope, f.page_no, f.quote, f.verified, f.report_id, r.year AS report_year, r.title
             FROM facts f JOIN reports r ON r.id = f.report_id
             WHERE f.indicator_id = ? ORDER BY f.year, r.year DESC'
        );
        $stmt->execute([$indicatorId]);
        $all = $stmt->fetchAll();
        $scopes = array_values(array_unique(array_map(fn ($r) => $r['scope'], $all)));

        // One value per year: the report of that same year first, then the most recent report; verified first.
        $byYear = [];
        foreach ($all as $r) {
            if ($r['scope'] !== $scope || ($from !== null && $r['year'] < $from) || ($to !== null && $r['year'] > $to)) {
                continue;
            }
            $byYear[(int) $r['year']][] = $r;
        }
        $points = [];
        foreach ($byYear as $year => $rows) {
            usort($rows, fn ($a, $b) => [(int) ($b['report_year'] == $year), $b['verified'], $b['report_year']] <=> [(int) ($a['report_year'] == $year), $a['verified'], $a['report_year']]);
            $best = $rows[0];
            $others = array_values(array_filter($rows, fn ($o) => abs($o['value'] - $best['value']) > 1e-9));
            $points[] = [
                'year' => $year,
                'value' => (float) $best['value'],
                'source' => [
                    'ref' => Search::ref((int) $best['report_id'], (int) $best['page_no']),
                    'report' => $best['title'],
                    'report_year' => (int) $best['report_year'],
                    'page' => (int) $best['page_no'],
                    'quote' => $best['quote'],
                    'verified' => (bool) $best['verified'],
                ],
                'other_values' => array_map(fn ($o) => ['value' => (float) $o['value'], 'ref' => Search::ref((int) $o['report_id'], (int) $o['page_no']), 'report_year' => (int) $o['report_year']], $others),
            ];
        }
        usort($points, fn ($a, $b) => $a['year'] <=> $b['year']);

        return [
            'indicator' => ['id' => (int) $indicator['id'], 'name_ar' => $indicator['name_ar'], 'name_fr' => $indicator['name_fr'], 'unit' => $indicator['unit'], 'category' => $indicator['category']],
            'scope' => $scope,
            'available_scopes' => $scopes,
            'points' => $points,
            'stats' => self::compute($points),
        ];
    }

    private static function compute(array $points): array
    {
        if ($points === []) {
            return [];
        }
        $values = array_column($points, 'value');
        $years = array_column($points, 'year');
        $first = $points[0];
        $last = $points[count($points) - 1];
        $minI = array_keys($values, min($values))[0];
        $maxI = array_keys($values, max($values))[0];
        $changes = [];
        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1]['value'];
            $changes[] = [
                'from' => $points[$i - 1]['year'],
                'to' => $points[$i]['year'],
                'change' => round($points[$i]['value'] - $prev, 4),
                'change_pct' => $prev != 0 ? round(($points[$i]['value'] - $prev) / abs($prev) * 100, 2) : null,
            ];
        }
        $span = $last['year'] - $first['year'];
        return [
            'count' => count($points),
            'first' => ['year' => $first['year'], 'value' => $first['value']],
            'last' => ['year' => $last['year'], 'value' => $last['value']],
            'total_change' => round($last['value'] - $first['value'], 4),
            'total_change_pct' => $first['value'] != 0 ? round(($last['value'] - $first['value']) / abs($first['value']) * 100, 2) : null,
            'cagr_pct' => ($span > 0 && $first['value'] > 0 && $last['value'] > 0) ? round((($last['value'] / $first['value']) ** (1 / $span) - 1) * 100, 2) : null,
            'min' => ['year' => $years[$minI], 'value' => $values[$minI]],
            'max' => ['year' => $years[$maxI], 'value' => $values[$maxI]],
            'mean' => round(array_sum($values) / count($values), 4),
            'sum' => round(array_sum($values), 4),
            'missing_years' => array_values(array_diff(range($first['year'], $last['year']), $years)),
            'year_on_year' => $changes,
        ];
    }

    public static function reports(): array
    {
        return Database::pdo()->query("SELECT id, title, year, language, pages, status FROM reports ORDER BY year, id")->fetchAll();
    }
}
