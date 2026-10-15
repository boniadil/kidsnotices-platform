<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Minimal Markdown (headings, paragraphs, lists, tables, bold) → blocks, HTML and DOCX.
 * Only what the model is asked to produce; all text is escaped.
 */
final class Markdown
{
    /** @return list<array{type: string, level?: int, text?: string, items?: list<string>, rows?: list<list<string>>}> */
    public static function blocks(string $md): array
    {
        $blocks = [];
        $lines = preg_split("/\r?\n/", $md);
        for ($i = 0; $i < count($lines); $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/u', $line, $m)) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => trim($m[2])];
            } elseif (preg_match('/^\s*\|/', $line)) {
                $rows = [];
                for (; $i < count($lines) && preg_match('/^\s*\|/', $lines[$i]); $i++) {
                    $cells = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
                    if (!preg_match('/^[\s|:\-]+$/', $lines[$i])) {
                        $rows[] = $cells;
                    }
                }
                $i--;
                $blocks[] = ['type' => 'table', 'rows' => $rows];
            } elseif (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+/u', $line)) {
                $items = [];
                for (; $i < count($lines) && preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/u', $lines[$i], $m); $i++) {
                    $items[] = trim($m[1]);
                }
                $i--;
                $blocks[] = ['type' => 'list', 'items' => $items];
            } else {
                $para = [trim($line)];
                for ($i++; $i < count($lines) && trim($lines[$i]) !== '' && !preg_match('/^(#{1,4}\s|\s*\||\s*(?:[-*•]|\d+[.)])\s)/u', $lines[$i]); $i++) {
                    $para[] = trim($lines[$i]);
                }
                $i--;
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $para)];
            }
        }
        return $blocks;
    }

    /** Inline: escape, **bold**, and citation refs [R3:p12] → links to the source page. */
    public static function inline(string $text, ?callable $refLabel = null): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
        return preg_replace_callback('/\[((?:R\d+:p\d+)(?:\s*[,;،]\s*R\d+:p\d+)*)\]/u', function ($m) use ($refLabel) {
            preg_match_all('/R(\d+):p(\d+)/', $m[1], $refs, PREG_SET_ORDER);
            return implode('', array_map(fn ($r) => '<a class="cite" href="/source?report=' . $r[1] . '&amp;page=' . $r[2] . '" target="_blank">'
                . htmlspecialchars($refLabel ? $refLabel((int) $r[1], (int) $r[2]) : "R{$r[1]}:p{$r[2]}", ENT_QUOTES, 'UTF-8') . '</a>', $refs));
        }, $html);
    }

    public static function toHtml(string $md, ?callable $refLabel = null): string
    {
        $out = '';
        foreach (self::blocks($md) as $b) {
            switch ($b['type']) {
                case 'heading':
                    $level = min(4, $b['level'] + 1);
                    $out .= "<h$level>" . self::inline($b['text'], $refLabel) . "</h$level>\n";
                    break;
                case 'paragraph':
                    $out .= '<p dir="auto">' . self::inline($b['text'], $refLabel) . "</p>\n";
                    break;
                case 'list':
                    $out .= "<ul>\n" . implode('', array_map(fn ($it) => '<li dir="auto">' . self::inline($it, $refLabel) . "</li>\n", $b['items'])) . "</ul>\n";
                    break;
                case 'table':
                    $rows = $b['rows'];
                    $head = array_shift($rows) ?? [];
                    $out .= "<div class=\"table-wrap\"><table>\n<thead><tr>" . implode('', array_map(fn ($c) => '<th>' . self::inline($c, $refLabel) . '</th>', $head)) . "</tr></thead>\n<tbody>\n";
                    foreach ($rows as $row) {
                        $out .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . self::inline($c, $refLabel) . '</td>', $row)) . "</tr>\n";
                    }
                    $out .= "</tbody></table></div>\n";
                    break;
            }
        }
        return $out;
    }
}
