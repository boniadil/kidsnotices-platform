<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Full-text search (SQLite FTS5, BM25 ranking) over report passages and indicator names.
 */
final class Search
{
    /** @return list<array{ref: string, report_id: int, year: int, title: string, page: int, text: string}> */
    public static function passages(string $query, ?int $yearFrom = null, ?int $yearTo = null, int $limit = 8): array
    {
        $match = Text::ftsQuery($query);
        if ($match === null) {
            return [];
        }
        $sql = "SELECT c.report_id, c.page_no, c.text, r.year, r.title, COALESCE(NULLIF(p.label, ''), 'ص ' || c.page_no) AS label
                FROM chunks_fts f JOIN chunks c ON c.id = f.chunk_id JOIN reports r ON r.id = c.report_id
                LEFT JOIN pages p ON p.report_id = c.report_id AND p.page_no = c.page_no
                WHERE chunks_fts MATCH ?";
        $params = [$match];
        if ($yearFrom !== null) {
            $sql .= ' AND r.year >= ?';
            $params[] = $yearFrom;
        }
        if ($yearTo !== null) {
            $sql .= ' AND r.year <= ?';
            $params[] = $yearTo;
        }
        $sql .= ' ORDER BY bm25(chunks_fts) LIMIT ' . max(1, min(20, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => [
            'ref' => self::ref((int) $r['report_id'], (int) $r['page_no']),
            'report_id' => (int) $r['report_id'],
            'year' => (int) $r['year'],
            'title' => $r['title'],
            'page' => (int) $r['page_no'],
            'label' => $r['label'],
            'text' => $r['text'],
        ], $stmt->fetchAll());
    }

    /** @return list<array> indicators with their available years */
    public static function indicators(string $query, int $limit = 15): array
    {
        $pdo = Database::pdo();
        $match = Text::ftsQuery($query);
        $ids = [];
        if ($match !== null) {
            $stmt = $pdo->prepare('SELECT indicator_id FROM indicators_fts WHERE indicators_fts MATCH ? ORDER BY bm25(indicators_fts) LIMIT ' . max(1, min(50, $limit)));
            $stmt->execute([$match]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $rows = $pdo->query("SELECT i.id, i.name_ar, i.name_fr, i.unit, i.category,
                                    GROUP_CONCAT(DISTINCT f.year) AS years, COUNT(DISTINCT f.scope) AS scopes
                             FROM indicators i LEFT JOIN facts f ON f.indicator_id = i.id
                             WHERE i.id IN ($in) GROUP BY i.id")->fetchAll();
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $r = $byId[$id];
            $years = array_map('intval', array_filter(explode(',', (string) $r['years'])));
            sort($years);
            $out[] = ['id' => $id, 'name_ar' => $r['name_ar'], 'name_fr' => $r['name_fr'], 'unit' => $r['unit'], 'category' => $r['category'], 'years' => $years, 'has_scopes' => (int) $r['scopes'] > 1];
        }
        return $out;
    }

    public static function ref(int $reportId, int $page): string
    {
        return "R{$reportId}:p{$page}";
    }

    /** "2021 · ص 12", "2015 · ورقة «Permis»", "2019 · شريحة 4" — for citations. */
    public static function refLabel(int $reportId, int $page): string
    {
        static $cache = [];
        if (!isset($cache["$reportId:$page"])) {
            $stmt = Database::pdo()->prepare("SELECT r.year, COALESCE(NULLIF(p.label, ''), 'ص ' || ?) FROM reports r LEFT JOIN pages p ON p.report_id = r.id AND p.page_no = ? WHERE r.id = ?");
            $stmt->execute([$page, $page, $reportId]);
            $row = $stmt->fetch(\PDO::FETCH_NUM);
            $cache["$reportId:$page"] = $row ? "{$row[0]} · {$row[1]}" : "? · $page";
        }
        return $cache["$reportId:$page"];
    }
}
