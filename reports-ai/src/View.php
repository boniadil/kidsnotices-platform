<?php

declare(strict_types=1);

namespace ReportsAI;

/**
 * Rendering helpers for the web pages (templates/*.php inside templates/layout.php).
 */
final class View
{
    public static function render(string $template, array $data): never
    {
        $flash = $_SESSION['rai_flash'] ?? null;
        unset($_SESSION['rai_flash']);
        $e = [self::class, 'e'];
        $user = Auth::user();
        $csrf = $user !== null || $template === 'login' ? Auth::csrf() : '';
        $domain = Config::domain();
        extract($data, EXTR_SKIP);
        header('Content-Type: text/html; charset=utf-8');
        ob_start();
        require Config::root() . "/templates/$template.php";
        $content = ob_get_clean();
        require Config::root() . '/templates/layout.php';
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function download(string $bytes, string $filename, string $type): never
    {
        header("Content-Type: $type");
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['rai_flash'] = ['message' => $message, 'type' => $type];
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** "2021 · ص 12" / "2015 · ورقة «Permis»" for a citation reference. */
    public static function refLabel(int $reportId, int $page): string
    {
        return Search::refLabel($reportId, $page);
    }

    /** Compact series for the charts in the browser. */
    public static function chartSeries(array $series): array
    {
        $out = [];
        foreach ($series as $s) {
            if (count($s['points']) < 2) {
                continue;
            }
            $key = $s['indicator']['id'] . '|' . $s['scope'];
            $out[$key] = [
                'name' => $s['indicator']['name_ar'] . ($s['scope'] !== '' ? ' — ' . $s['scope'] : ''),
                'unit' => $s['indicator']['unit'],
                'points' => array_map(fn ($p) => ['year' => $p['year'], 'value' => $p['value']], $s['points']),
            ];
        }
        return array_values($out);
    }

    public static function statusLabel(string $status): string
    {
        return [
            'queued' => 'في الانتظار',
            'extracting_text' => 'قراءة النص',
            'describing' => 'تحليل الهيكلة',
            'extracting_facts' => 'استخراج الأرقام',
            'unifying' => 'توحيد المؤشرات',
            'ready' => 'جاهز',
            'writing' => 'قيد التحرير',
            'error' => 'خطأ',
        ][$status] ?? $status;
    }
}
