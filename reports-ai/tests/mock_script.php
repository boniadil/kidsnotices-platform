<?php

/*
 * Scripted answers shared by the fake Claude and Ollama servers, from a conversation in the
 * Anthropic shape (tool calls as tool_use / tool_result blocks).
 */

declare(strict_types=1);

const CANONICAL = [
    ['عدد رخص البناء المسلمة', 'Autorisations de construire délivrées'],
    ['مشاريع التجزئات العقارية', 'Projets de lotissement'],
    ['مشاريع التجهيزات العمومية', "Projets d'équipements publics"],
    ['المساحة المغطاة (ألف متر مربع)', 'Superficie couverte (milliers de m²)'],
];

function checkSchema(array $s, string $path = '$'): void
{
    if (($s['type'] ?? null) === 'object') {
        $props = array_keys($s['properties'] ?? []);
        $req = $s['required'] ?? [];
        sort($props);
        sort($req);
        if (($s['additionalProperties'] ?? null) !== false || $props !== $req) {
            fail(400, "$path: additionalProperties must be false and all properties required");
        }
        foreach ($s['properties'] as $k => $sub) {
            checkSchema($sub, "$path.$k");
        }
    } elseif (($s['type'] ?? null) === 'array') {
        checkSchema($s['items'] ?? [], "{$path}[]");
    }
}

function textOf(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    return implode("\n", array_map(fn ($b) => is_string($b['content'] ?? null) ? $b['content'] : ($b['text'] ?? ''), $content));
}

/** @return array{0: list<array>, 1: string} content blocks and stop reason */
function scripted(array $messages, string $system, ?array $schema, bool $withTools): array
{
    $last = textOf(end($messages)['content']);
    $blocks = [];
    $stop = 'end_turn';


    if (str_contains($last, '__REFUSE__')) {
        $stop = 'refusal';
    } elseif ($schema && isset($schema['properties']['outline'])) {
        $isAr = preg_match('/\p{Arabic}{4,}/u', $last) === 1;
        preg_match_all('/^\s*(\.?\d\.?\s*\D.+)$/mu', $last, $h);
        $blocks[] = ['type' => 'text', 'text' => json_encode(['language' => $isAr ? 'ar' : 'fr', 'outline' => array_map('trim', $h[1]), 'summary' => $isAr ? 'ملخص تجريبي للتقرير.' : 'Résumé de test du rapport.'], JSON_UNESCAPED_UNICODE)];
    } elseif ($schema && isset($schema['properties']['facts'])) {
        preg_match('/report year (\d{4})/', $last, $ym);
        $year = (int) ($ym[1] ?? 0);
        $facts = [];
        foreach (preg_split('/^=== page (\d+) ===$/m', $last, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $part) {
            if ($k % 2 === 1) {
                $page = (int) $part;
                continue;
            }
            if (!isset($page)) {
                continue;
            }
            foreach (explode("\n", $part) as $line) {
                // Table rows of the sample reports: a known label and its number on the same line.
                foreach (array_merge(...CANONICAL) as $label) {
                    if (str_contains($line, $label) && preg_match('/(\d[\d ]*\d|\d)/u', str_replace($label, '', $line), $num)) {
                        $facts[] = ['indicator' => $label, 'category' => 'permits', 'value' => (float) str_replace(' ', '', $num[1]), 'unit' => '', 'year' => $year, 'scope' => '', 'page' => $page, 'quote' => trim(preg_replace('/\s+/u', ' ', $line))];
                    }
                }
            }
        }
        // One invented figure: must be flagged as unverified by the app.
        if (isset($page)) {
            $facts[] = ['indicator' => 'Invented test figure', 'category' => 'other', 'value' => 987654, 'unit' => '', 'year' => $year, 'scope' => '', 'page' => $page, 'quote' => 'invented 987654'];
        }
        $blocks[] = ['type' => 'text', 'text' => json_encode(['facts' => $facts], JSON_UNESCAPED_UNICODE)];
    } elseif ($schema && isset($schema['properties']['pages'])) {
        // Lam-alef repair: fix the known words of the samples; tamper with one page to test the safety check.
        $fix = ['االختبار' => 'الاختبار', 'خالل' => 'خلال', 'مجاالت' => 'مجالات', 'االستثمار' => 'الاستثمار', 'ألغراض' => 'لأغراض', 'ألنشطة' => 'لأنشطة'];
        $pages = [];
        foreach (preg_split('/^=== page (\d+) ===$/m', $last, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $part) {
            if ($k % 2 === 1) {
                $no = (int) $part;
            } elseif (isset($no)) {
                $text = strtr(rtrim(preg_replace('#</pages>\s*$#', '', $part)), $fix);
                $pages[] = ['page' => $no, 'text' => $no === 3 ? str_replace('مخالفة', 'مخالفة مزورة 1', $text) : $text];
            }
        }
        $blocks[] = ['type' => 'text', 'text' => json_encode(['pages' => $pages], JSON_UNESCAPED_UNICODE)];
    } elseif ($schema && isset($schema['properties']['slides'])) {
        // Slide plan from the report markdown: title slide, one slide per "## " section, a chart from the table.
        preg_match('#<report>\s*(.*?)\s*</report>#s', $last, $r);
        $md = $r[1] ?? '';
        $empty = ['table' => ['headers' => [], 'rows' => []], 'chart' => ['title' => '', 'unit' => '', 'points' => []], 'notes' => ''];
        preg_match('/^# (.+)$/m', $md, $t);
        $slides = [['title' => $t[1] ?? 'Report', 'bullets' => ['2025']] + $empty];
        foreach (preg_split('/^## /m', $md) as $k => $section) {
            if ($k === 0) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', explode("\n", $section))));
            $bullets = array_values(array_filter(array_slice($lines, 1), fn ($l) => $l[0] !== '|'));
            preg_match_all('/^\|\s*(\d{4})\s*\|\s*([\d.]+)/m', $section, $rows, PREG_SET_ORDER);
            $slide = ['title' => $lines[0], 'bullets' => array_map(fn ($b) => ltrim($b, '- '), $bullets)] + $empty;
            if ($rows) {
                $slide['chart'] = ['title' => $lines[0], 'unit' => '', 'points' => array_map(fn ($m) => ['year' => (int) $m[1], 'value' => (float) $m[2]], $rows)];
                $slide['table'] = ['headers' => ['السنة', 'القيمة'], 'rows' => array_map(fn ($m) => [$m[1], $m[2]], $rows)];
                $slide['notes'] = 'المصدر [R1:p2]';
            }
            $slides[] = $slide;
        }
        // A chart with a number that is not in the report: the app must drop it.
        $slides[] = ['title' => 'Invented', 'bullets' => ['x']] + array_merge($empty, ['chart' => ['title' => 'x', 'unit' => '', 'points' => [['year' => 2020, 'value' => 999999], ['year' => 2021, 'value' => 1]]]]);
        $blocks[] = ['type' => 'text', 'text' => json_encode(['slides' => $slides], JSON_UNESCAPED_UNICODE)];
    } elseif ($schema && isset($schema['properties']['mappings'])) {
        preg_match('#<catalogue>\s*(.*?)\s*</catalogue>#s', $last, $c);
        preg_match('#<new_names>\s*(.*?)\s*</new_names>#s', $last, $n);
        $catalogue = json_decode($c[1] ?? '[]', true) ?: [];
        $out = [];
        foreach (json_decode($n[1] ?? '[]', true) ?: [] as $item) {
            $pair = [$item['source_name'], $item['source_name']];
            foreach (CANONICAL as $canon) {
                if (in_array($item['source_name'], $canon, true)) {
                    $pair = $canon;
                }
            }
            $existing = 0;
            foreach ($catalogue as $ind) {
                if ($ind['name_ar'] === $pair[0]) {
                    $existing = (int) $ind['id'];
                }
            }
            $out[] = ['source_name' => $item['source_name'], 'unit' => $item['unit'], 'existing_id' => $existing, 'name_ar' => $pair[0], 'name_fr' => $pair[1], 'canonical_unit' => '', 'category' => $item['category']];
        }
        $blocks[] = ['type' => 'text', 'text' => json_encode(['mappings' => $out], JSON_UNESCAPED_UNICODE)];
    } elseif ($withTools) {
        // Tool dialogue: list_reports → find_indicators → get_series → search_reports → final answer.
        $turns = count(array_filter($messages, fn ($m) => $m['role'] === 'assistant'));
        $calls = [
            ['list_reports', new stdClass()],
            ['find_indicators', ['query' => 'رخص البناء']],
            ['get_series', null],
            ['search_reports', ['query' => 'مخالفات التعمير']],
        ];
        if ($turns < count($calls)) {
            [$name, $input] = $calls[$turns];
            if ($name === 'get_series') {
                $found = json_decode(textOf(end($messages)['content']), true);
                $input = ['indicator_id' => (int) ($found[0]['id'] ?? 0)];
            }
            $blocks[] = ['type' => 'thinking', 'thinking' => '', 'signature' => 'mock-signature'];
            $blocks[] = ['type' => 'text', 'text' => 'أبحث في قاعدة المعطيات.'];
            $blocks[] = ['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => $name, 'input' => $input];
            $stop = 'tool_use';
        } else {
            // Find the series result in the conversation and cite it.
            $series = null;
            foreach ($messages as $m) {
                if ($m['role'] === 'user' && is_array($m['content'])) {
                    $d = json_decode(textOf($m['content']), true);
                    if (isset($d['points'])) {
                        $series = $d;
                    }
                }
            }
            $lines = array_map(fn ($p) => "| {$p['year']} | {$p['value']} [{$p['source']['ref']}] |", $series['points'] ?? []);
            $table = "| السنة | القيمة |\n|---|---|\n" . implode("\n", $lines);
            $blocks[] = ['type' => 'text', 'text' => str_contains($system, 'You write official reports')
                ? "# تقرير تجريبي\n\n## 1. رخص البناء\n\nتطورت رخص البناء كما يلي، وبلغت السنة الجديدة 1530 رخصة [new data].\n\n$table\n\n## خلاصة\n\n- **نتيجة** أولى.\n- نتيجة ثانية."
                : "عدد رخص البناء المسلمة حسب السنوات:\n\n$table\n\nالتغير الإجمالي: {$series['stats']['total_change_pct']}%."];
        }
    } else {
        $blocks[] = ['type' => 'text', 'text' => 'OK'];
    }

    return [$blocks, $stop];
}
