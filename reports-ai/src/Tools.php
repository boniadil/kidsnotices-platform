<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Tools the model can call to read the knowledge base. Every result is JSON, and every
 * passage or figure carries a reference like "R3:p12" (report 3, page 12) used for citations.
 */
final class Tools
{
    /** @var array<string, array> references seen in tool results, for the sources list */
    public array $sources = [];

    /** @var list<array> series returned to the model, for tables/charts in the UI */
    public array $series = [];

    public static function definitions(): array
    {
        $tools = [
            [
                'name' => 'list_reports',
                'description' => 'List the annual reports in the knowledge base (id, title, year, language, pages). Call it first when you need to know which years are covered.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name' => 'find_indicators',
                'description' => 'Find statistical indicators (figures extracted from the reports and unified across years and languages) whose name matches the query. Returns id, Arabic and French names, unit, category and the years with data. Query in Arabic or French; try synonyms if nothing is found.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Words describing the indicator, e.g. "رخص البناء" or "autorisations de construire"']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_series',
                'description' => 'Get the yearly values of one indicator with the source of each value (report, page, verbatim quote), and computed statistics: first/last, total change and %, compound annual growth rate, min, max, mean, sum, year-on-year changes, missing years. Use these computed numbers instead of calculating yourself.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'indicator_id' => ['type' => 'integer'],
                        'year_from' => ['type' => 'integer'],
                        'year_to' => ['type' => 'integer'],
                        'scope' => ['type' => 'string', 'description' => 'Sub-territory or sub-group; empty for the whole administration (default)'],
                    ],
                    'required' => ['indicator_id'],
                ],
            ],
            [
                'name' => 'search_reports',
                'description' => 'Full-text search in the text of all reports (Arabic and French). Returns the best passages with year, page and reference. Use it for qualitative questions (projects, events, explanations, context) and to check figures not found as indicators.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'year_from' => ['type' => 'integer'],
                        'year_to' => ['type' => 'integer'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
        // Tool inputs stream as they are generated; inputs are validated in run().
        return array_map(fn ($t) => $t + ['eagerInputStreaming' => true], $tools);
    }

    /** Executes a tool call. Returns [content, isError]. */
    public function run(string $name, mixed $input): array
    {
        try {
            $input = is_array($input) ? $input : [];
            $int = fn (string $k) => isset($input[$k]) && is_numeric($input[$k]) ? (int) $input[$k] : null;
            $str = fn (string $k) => isset($input[$k]) && is_string($input[$k]) ? trim($input[$k]) : '';

            $result = match ($name) {
                'list_reports' => Stats::reports(),
                'find_indicators' => $str('query') !== '' ? Search::indicators($str('query')) : throw new UserError('query is required'),
                'get_series' => $this->series($int('indicator_id') ?? throw new UserError('indicator_id is required'), $int('year_from'), $int('year_to'), $str('scope')),
                'search_reports' => $this->passages($str('query') !== '' ? $str('query') : throw new UserError('query is required'), $int('year_from'), $int('year_to')),
                default => throw new UserError("Unknown tool $name"),
            };
            return [json_encode($result === [] ? ['result' => 'nothing found'] : $result, JSON_UNESCAPED_UNICODE), false];
        } catch (UserError $e) {
            return ['Error: ' . $e->getMessage(), true];
        }
    }

    private function series(int $id, ?int $from, ?int $to, string $scope): array
    {
        $series = Stats::series($id, $from, $to, $scope);
        foreach ($series['points'] as $p) {
            $this->sources[$p['source']['ref']] = ['ref' => $p['source']['ref'], 'report' => $p['source']['report'], 'year' => $p['source']['report_year'], 'page' => $p['source']['page'], 'quote' => $p['source']['quote']];
        }
        $this->series[] = $series;
        return $series;
    }

    private function passages(string $query, ?int $from, ?int $to): array
    {
        $passages = Search::passages($query, $from, $to);
        foreach ($passages as $p) {
            $this->sources[$p['ref']] = ['ref' => $p['ref'], 'report' => $p['title'], 'year' => $p['year'], 'page' => $p['page'], 'quote' => mb_substr($p['text'], 0, 240)];
        }
        return $passages;
    }

    /** References actually cited in a text, in order of appearance, with their details. */
    public function cited(string $text): array
    {
        preg_match_all('/R(\d+):p(\d+)/', $text, $m);
        $out = [];
        foreach (array_unique($m[0]) as $ref) {
            if (isset($this->sources[$ref])) {
                $out[] = $this->sources[$ref];
            } elseif (preg_match('/R(\d+):p(\d+)/', $ref, $r)) {
                $stmt = Database::pdo()->prepare('SELECT title, year FROM reports WHERE id = ?');
                $stmt->execute([(int) $r[1]]);
                if ($rep = $stmt->fetch()) {
                    $out[] = ['ref' => $ref, 'report' => $rep['title'], 'year' => (int) $rep['year'], 'page' => (int) $r[2], 'quote' => ''];
                }
            }
        }
        return $out;
    }
}
