<?php

declare(strict_types=1);

/*
 * Reports AI — single entry point (web pages + JSON API used by the pages).
 */

use ReportsAI\Assistant;
use ReportsAI\Auth;
use ReportsAI\Config;
use ReportsAI\Database;
use ReportsAI\Docx;
use ReportsAI\Ingest;
use ReportsAI\Markdown;
use ReportsAI\ReportWriter;
use ReportsAI\Search;
use ReportsAI\Stats;
use ReportsAI\UserError;
use ReportsAI\View;
use ReportsAI\Worker;

// PHP built-in server: serve real files (CSS, JS) directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) && ($_SERVER['REQUEST_URI'] ?? '/') !== '/') {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');
Auth::start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$isApi = str_starts_with($path, '/api/');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

try {
    if ($path === '/login') {
        if ($method === 'POST') {
            Auth::checkCsrf();
            if (Auth::login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
                View::redirect('/');
            }
            View::render('login', ['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة.']);
        }
        View::render('login', []);
    }

    $user = Auth::user();
    if ($user === null) {
        $isApi ? View::json(['error' => 'not_logged_in'], 401) : View::redirect('/login');
    }
    if ($method === 'POST') {
        Auth::checkCsrf();
    }
    $int = fn (string $k) => isset($_REQUEST[$k]) && is_numeric($_REQUEST[$k]) ? (int) $_REQUEST[$k] : null;
    $str = fn (string $k) => isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) ? trim($_REQUEST[$k]) : '';
    $adminOnly = fn () => Auth::isAdmin() ?: throw new UserError('هذه العملية مخصصة للمسؤول.');

    switch ("$method $path") {
        case 'POST /logout':
            Auth::logout();
            View::redirect('/login');

        // ---- Knowledge base ------------------------------------------------------------
        case 'GET /':
            $pdo = Database::pdo();
            View::render('dashboard', [
                'reports' => $pdo->query('SELECT * FROM reports ORDER BY year DESC, id DESC')->fetchAll(),
                'counts' => $pdo->query("SELECT (SELECT COUNT(*) FROM reports WHERE status = 'ready') AS reports,
                                                (SELECT MIN(year) FROM reports WHERE status = 'ready') AS year_min,
                                                (SELECT MAX(year) FROM reports WHERE status = 'ready') AS year_max,
                                                (SELECT COUNT(*) FROM indicators) AS indicators,
                                                (SELECT COUNT(*) FROM facts) AS facts")->fetch(),
                'cost' => Auth::isAdmin() ? $pdo->query("SELECT task, COUNT(*) AS calls, SUM(cost_usd) AS cost FROM usage_log WHERE created_at >= date('now', 'start of month') GROUP BY task")->fetchAll() : [],
            ]);

        case 'POST /upload':
            $adminOnly();
            $file = $_FILES['pdf'] ?? null;
            $max = (int) Config::get('RAI_MAX_UPLOAD_MB', '60') * 1024 * 1024;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new UserError('لم يتم رفع الملف. تحقق من حجمه (الحد: ' . ($max >> 20) . ' ميغابايت).');
            }
            $year = $int('year');
            if ($year === null || $year < 1950 || $year > 2100) {
                throw new UserError('أدخل سنة التقرير.');
            }
            if ($file['size'] > $max) {
                throw new UserError('الملف أكبر من الحد المسموح.');
            }
            Ingest::register($file['tmp_name'], $file['name'], $year, $str('title'));
            Worker::kick();
            View::flash('تمت إضافة التقرير، وتجري معالجته الآن.');
            View::redirect('/');

        case 'POST /reports/retry':
            $adminOnly();
            Database::pdo()->prepare("UPDATE reports SET status = 'queued', progress = 0, error = NULL WHERE id = ?")->execute([$int('id')]);
            Worker::kick();
            View::redirect('/');

        case 'POST /reports/delete':
            $adminOnly();
            Ingest::delete((int) $int('id'));
            View::flash('تم حذف التقرير ومعطياته.');
            View::redirect('/');

        case 'GET /api/status':
            View::json(Database::pdo()->query('SELECT id, status, progress, error, pages, language FROM reports')->fetchAll());

        case 'GET /source':
            $stmt = Database::pdo()->prepare('SELECT r.id, r.title, r.year, r.pages, p.page_no, p.text FROM reports r JOIN pages p ON p.report_id = r.id WHERE r.id = ? AND p.page_no = ?');
            $stmt->execute([$int('report'), $int('page')]);
            View::render('source', ['page' => $stmt->fetch() ?: throw new UserError('الصفحة غير موجودة.'), 'highlight' => $str('q')]);

        // ---- Questions -------------------------------------------------------------------
        case 'GET /ask':
            View::render('ask', ['history' => $_SESSION['rai_history'] ?? []]);

        case 'POST /api/ask':
            set_time_limit(300);
            $question = $str('question');
            if ($question === '' || mb_strlen($question) > 2000) {
                throw new UserError('اكتب سؤالك (2000 حرف كحد أقصى).');
            }
            $history = $_SESSION['rai_history'] ?? [];
            session_write_close(); // do not block other pages during the answer
            $result = Assistant::ask($question, array_map(fn ($t) => ['role' => $t['role'], 'content' => $t['content']], $history));
            $html = Markdown::toHtml($result['answer'], [View::class, 'refLabel']);
            Auth::start();
            $history[] = ['role' => 'user', 'content' => $question];
            $history[] = ['role' => 'assistant', 'content' => $result['answer'], 'html' => $html, 'sources' => $result['sources'], 'series' => View::chartSeries($result['series'])];
            $_SESSION['rai_history'] = array_slice($history, -20);
            View::json(['html' => $html, 'sources' => $result['sources'], 'series' => View::chartSeries($result['series'])]);

        case 'POST /api/ask/reset':
            unset($_SESSION['rai_history']);
            View::json(['ok' => true]);

        // ---- Statistics ------------------------------------------------------------------
        case 'GET /stats':
            View::render('stats', [
                'categories' => Config::domain()['categories'],
                'byCategory' => Database::pdo()->query('SELECT i.category, COUNT(DISTINCT i.id) AS n FROM indicators i GROUP BY i.category')->fetchAll(\PDO::FETCH_KEY_PAIR),
            ]);

        case 'GET /api/indicators':
            $q = $str('q');
            $category = $str('category');
            if ($q !== '') {
                View::json(Search::indicators($q, 40));
            }
            $stmt = Database::pdo()->prepare("SELECT i.id, i.name_ar, i.name_fr, i.unit, i.category, GROUP_CONCAT(DISTINCT f.year) AS years
                                              FROM indicators i LEFT JOIN facts f ON f.indicator_id = i.id
                                              WHERE (? = '' OR i.category = ?) GROUP BY i.id ORDER BY COUNT(DISTINCT f.year) DESC, i.name_ar LIMIT 200");
            $stmt->execute([$category, $category]);
            View::json(array_map(function ($r) {
                $years = array_map('intval', array_filter(explode(',', (string) $r['years'])));
                sort($years);
                return ['id' => (int) $r['id'], 'name_ar' => $r['name_ar'], 'name_fr' => $r['name_fr'], 'unit' => $r['unit'], 'category' => $r['category'], 'years' => $years];
            }, $stmt->fetchAll()));

        case 'GET /api/series':
            View::json(Stats::series((int) $int('id'), $int('from'), $int('to'), $str('scope')));

        case 'GET /stats/csv':
            $s = Stats::series((int) $int('id'), $int('from'), $int('to'), $str('scope'));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="indicator-' . $s['indicator']['id'] . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            fputcsv($out, ['year', 'value', 'unit', 'indicator_ar', 'indicator_fr', 'scope', 'report', 'page', 'verified', 'quote']);
            foreach ($s['points'] as $p) {
                fputcsv($out, [$p['year'], $p['value'], $s['indicator']['unit'], $s['indicator']['name_ar'], $s['indicator']['name_fr'], $s['scope'], $p['source']['report'], $p['source']['page'], $p['source']['verified'] ? 1 : 0, $p['source']['quote']]);
            }
            exit;

        // ---- Report writing --------------------------------------------------------------
        case 'GET /write':
            View::render('write', [
                'jobs' => Database::pdo()->query('SELECT id, kind, title, language, status, error, created_by, created_at FROM generated_reports ORDER BY id DESC LIMIT 50')->fetchAll(),
                'lastYear' => (int) Database::pdo()->query("SELECT MAX(year) FROM reports WHERE status = 'ready'")->fetchColumn(),
                'firstYear' => (int) Database::pdo()->query("SELECT MIN(year) FROM reports WHERE status = 'ready'")->fetchColumn(),
            ]);

        case 'POST /write':
            $kind = $str('kind');
            $title = $str('title');
            if ($title === '' || mb_strlen($title) > 200) {
                throw new UserError('أدخل عنوان التقرير.');
            }
            $params = $kind === 'annual'
                ? ['year' => $int('year'), 'new_data' => mb_substr($str('new_data'), 0, 20000), 'instructions' => mb_substr($str('instructions'), 0, 3000)]
                : ['topic' => mb_substr($str('topic'), 0, 500), 'year_from' => $int('year_from'), 'year_to' => $int('year_to'), 'instructions' => mb_substr($str('instructions'), 0, 3000)];
            $id = ReportWriter::create($kind, $title, $str('language'), $params, $user['username']);
            Worker::kick();
            View::redirect('/write/view?id=' . $id);

        case 'GET /write/view':
            $stmt = Database::pdo()->prepare('SELECT * FROM generated_reports WHERE id = ?');
            $stmt->execute([$int('id')]);
            $job = $stmt->fetch() ?: throw new UserError('التقرير غير موجود.');
            View::render('write_view', ['job' => $job, 'html' => $job['markdown'] ? Markdown::toHtml($job['markdown'], [View::class, 'refLabel']) : '']);

        case 'GET /api/write':
            $stmt = Database::pdo()->prepare('SELECT status, error FROM generated_reports WHERE id = ?');
            $stmt->execute([$int('id')]);
            View::json($stmt->fetch() ?: ['status' => 'missing']);

        case 'GET /write/download':
            $stmt = Database::pdo()->prepare("SELECT * FROM generated_reports WHERE id = ? AND status = 'ready'");
            $stmt->execute([$int('id')]);
            $job = $stmt->fetch() ?: throw new UserError('التقرير غير جاهز.');
            $name = 'report-' . $job['id'];
            if ($str('format') === 'md') {
                header('Content-Type: text/markdown; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"$name.md\"");
                echo $job['markdown'];
                exit;
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header("Content-Disposition: attachment; filename=\"$name.docx\"");
            echo Docx::build($job['markdown'], $job['language']);
            exit;

        case 'POST /write/delete':
            Database::pdo()->prepare('DELETE FROM generated_reports WHERE id = ?')->execute([$int('id')]);
            View::redirect('/write');

        default:
            http_response_code(404);
            $isApi ? View::json(['error' => 'not_found'], 404) : View::render('message', ['title' => 'الصفحة غير موجودة', 'text' => '']);
    }
} catch (UserError $e) {
    if ($isApi) {
        View::json(['error' => $e->getMessage()], 400);
    }
    if ($method === 'POST' && Auth::user() !== null) {
        View::flash($e->getMessage(), 'error');
        View::redirect(parse_url((string) ($_SERVER['HTTP_REFERER'] ?? '/'), PHP_URL_PATH) ?: '/'); // same-site path only
    }
    http_response_code(400);
    View::render('message', ['title' => 'تعذّر تنفيذ العملية', 'text' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[reports-ai] ' . $e);
    http_response_code(500);
    $isApi ? View::json(['error' => 'Unexpected error.'], 500) : View::render('message', ['title' => 'خطأ غير متوقع', 'text' => 'راجع سجل الخادم.']);
}
