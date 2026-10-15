<?php

/*
 * End-to-end tests against the fake Claude server (tests/mock_claude.php) — no API key, no cost.
 *   php tests/run.php
 * Ingests the four fictitious sample reports (2 Arabic, 2 French), then checks search, statistics,
 * the question assistant, report writing, Word export, error handling and the web pages.
 */

declare(strict_types=1);

use ReportsAI\Assistant;
use ReportsAI\Auth;
use ReportsAI\Database;
use ReportsAI\Docx;
use ReportsAI\Ingest;
use ReportsAI\Markdown;
use ReportsAI\ReportWriter;
use ReportsAI\Search;
use ReportsAI\Stats;
use ReportsAI\Text;
use ReportsAI\UserError;
use ReportsAI\Worker;

$root = dirname(__DIR__);
require "$root/vendor/autoload.php";

$tmp = sys_get_temp_dir() . '/rai-tests-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$env = [
    'RAI_STORAGE_DIR' => $tmp,
    'ANTHROPIC_API_KEY' => 'sk-test',
    'RAI_MODEL' => 'claude-opus-5-5',
    'RAI_MODEL_EXTRACT' => 'claude-haiku-5-5',
    'RAI_MODEL_MAP' => 'claude-sonnet-5-5',
    'MOCK_LOG_DIR' => $tmp,
];

$procs = [];
$freePort = function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
};
$serve = function (string $docroot, string $router, int $port, array $extraEnv = []) use (&$procs, $tmp, &$env) {
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server-$port.log", 'w']], $p, null, $env + $extraEnv + ['PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
};
$mockPort = $freePort();
$serve("$root/tests", "$root/tests/mock_claude.php", $mockPort);
$env['ANTHROPIC_BASE_URL'] = "http://127.0.0.1:$mockPort";
foreach ($env as $k => $v) {
    putenv("$k=$v");
}

$passed = $failed = 0;
function check(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✔ ' : '  ✘ ') . $name . ($ok || $detail === null ? '' : "\n      " . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
}
function requests(): array
{
    global $tmp;
    return array_map(fn ($l) => json_decode($l, true), file("$tmp/requests.jsonl", FILE_IGNORE_NEW_LINES) ?: []);
}

try {
    echo "Text normalization\n";
    check('Arabic letters unified, article stripped', Text::normalize('والرُّخَص المسلّمة إلى الجماعة') === 'رخص مسلمه الي جماعه', Text::normalize('والرُّخَص المسلّمة إلى الجماعة'));
    check('French accents and elisions', Text::normalize("L'équipement délivrées") === 'equipement delivrees');
    check('FTS query drops stopwords, adds prefixes', Text::ftsQuery('كم عدد رخص البناء في 2021؟') === '"رخص" OR "بناء"* OR "2021"*', Text::ftsQuery('كم عدد رخص البناء في 2021؟'));
    check('bidi controls removed', Text::clean("\u{202B}نص\u{202C}") === 'نص');
    check('swapped lam-alef detected in extracted text', Text::hasSwappedLamAlef(str_repeat('يستعرض التقرير خالل سنة مجاالت االستثمار ', 30)) && !Text::hasSwappedLamAlef(str_repeat('يستعرض التقرير خلال سنة مجالات الاستثمار ', 30)));
    check('repair accepted when only lam-alef order changes', Text::isSafeLamAlefRepair('خالل سنة 2021 االختبار', 'خلال سنة 2021 الاختبار'));
    check('repair rejected when anything else changes', !Text::isSafeLamAlefRepair('خالل سنة 2021', 'خلال سنة 2022') && !Text::isSafeLamAlefRepair('خالل سنة', 'خلال عام'));

    echo "Ingestion (4 sample reports: 2 Arabic, 2 French)\n";
    foreach (glob("$root/samples/rapport-*.pdf") as $file) {
        preg_match('/(\d{4})/', basename($file), $m);
        $copy = tempnam($tmp, 'up');
        copy($file, $copy);
        Ingest::register($copy, basename($file), (int) $m[1], '');
    }
    $copy = tempnam($tmp, 'up');
    copy("$root/samples/rapport-2021-ar.pdf", $copy);
    try {
        Ingest::register($copy, 'again.pdf', 2021, '');
        check('duplicate file refused', false);
    } catch (UserError) {
        check('duplicate file refused', true);
    }
    while (Worker::runOnce()) {
    }
    $reports = Database::pdo()->query('SELECT year, status, pages, language, error FROM reports ORDER BY year')->fetchAll();
    check('all reports processed', count($reports) === 4 && array_unique(array_column($reports, 'status')) === ['ready'], $reports);
    check('pages read', array_column($reports, 'pages') === [3, 3, 3, 3], array_column($reports, 'pages'));
    check('language detected', array_column($reports, 'language') === ['ar', 'fr', 'ar', 'fr'], array_column($reports, 'language'));

    $perm = Search::indicators('رخص البناء');
    $permFr = Search::indicators('autorisations construire');
    check('same indicator found from Arabic and French words', ($perm[0]['id'] ?? 0) > 0 && ($perm[0]['id'] ?? 0) === ($permFr[0]['id'] ?? -1), [$perm[0] ?? null, $permFr[0] ?? null]);
    check('indicator unified across 4 years and 2 languages', ($perm[0]['years'] ?? []) === [2021, 2022, 2023, 2024], $perm[0] ?? null);
    $s = Stats::series($perm[0]['id']);
    check('series values', array_column($s['points'], 'value') === [1240.0, 1385.0, 1512.0, 1468.0], array_column($s['points'], 'value'));
    check('total change computed', $s['stats']['total_change_pct'] === 18.39, $s['stats']['total_change_pct']);
    check('CAGR computed', $s['stats']['cagr_pct'] === 5.79, $s['stats']['cagr_pct']);
    check('max year computed', $s['stats']['max'] === ['year' => 2023, 'value' => 1512.0], $s['stats']['max']);
    check('each value has its source page and quote', $s['points'][0]['source']['page'] === 2 && str_contains($s['points'][0]['source']['quote'], '1 240'), $s['points'][0]['source']);
    check('series filtered by years', count(Stats::series($perm[0]['id'], 2022, 2023)['points']) === 2);
    $invented = Database::pdo()->query("SELECT COUNT(*) FROM facts WHERE value = 987654 AND verified = 0")->fetchColumn();
    $verified = Database::pdo()->query("SELECT COUNT(*) FROM facts WHERE value <> 987654 AND verified = 1")->fetchColumn();
    check('figures not found in the page are flagged "to check"', (int) $invented === 4 && (int) $verified >= 12, [$invented, $verified]);
    $p1 = Database::pdo()->query("SELECT text FROM pages p JOIN reports r ON r.id = p.report_id WHERE r.year = 2021 AND p.page_no = 1")->fetchColumn();
    $p3 = Database::pdo()->query("SELECT text FROM pages p JOIN reports r ON r.id = p.report_id WHERE r.year = 2021 AND p.page_no = 3")->fetchColumn();
    check('Arabic lam-alef repaired in stored text', str_contains($p1, 'خلال سنة') && str_contains($p1, 'الاستثمار') && !str_contains($p1, 'خالل'), mb_substr($p1, 0, 160));
    check('a repair that changed other text was rejected', !str_contains($p3, 'مزورة'), mb_substr($p3, 0, 120));
    check('French reports are not sent for repair', count(array_filter(requests(), fn ($q) => isset($q['body']['output_config']['format']['schema']['properties']['pages']))) === 2);

    echo "Search\n";
    $hits = Search::passages('مخالفات التعمير');
    check('Arabic full-text search', $hits && $hits[0]['year'] % 2 === 1 && array_filter($hits, fn ($h) => str_contains($h['text'], 'مخالفة')), array_column($hits, 'ref'));
    $hits2 = Search::passages('الاستثمار');
    check('search finds words that contained a broken ligature', count($hits2) >= 2, array_column($hits2, 'ref'));
    $hits = Search::passages('infractions urbanisme', 2024, 2024);
    check('French search with year filter', $hits && array_unique(array_column($hits, 'year')) === [2024], array_column($hits, 'year'));
    check('passages carry a reference', preg_match('/^R\d+:p\d+$/', $hits[0]['ref'] ?? '') === 1);

    echo "Question assistant (tool use)\n";
    $before = count(requests());
    $r = Assistant::ask('كم عدد رخص البناء المسلمة كل سنة؟');
    $calls = array_slice(requests(), $before);
    check('tool loop ran (find → series → search → answer)', count($calls) === 4, count($calls));
    check('thinking blocks and tool results passed back correctly', true); // the mock rejects the request otherwise
    check('answer cites sources', str_contains($r['answer'], '[R') && count($r['sources']) === 4, $r['sources']);
    check('series returned for the chart', ($r['series'][0]['points'][3]['value'] ?? null) === 1468.0);
    check('fallbacks + beta header on Opus', ($calls[0]['body']['fallbacks'] ?? null) === 'default' && str_contains($calls[0]['headers']['anthropic-beta'] ?? '', 'server-side-fallback'));
    check('tools stream their input', ($calls[0]['body']['tools'][0]['eager_input_streaming'] ?? null) === true);
    $html = Markdown::toHtml($r['answer'], fn ($id, $p) => "$id/$p");
    check('answer rendered with source links', str_contains($html, '<a class="cite" href="/source?report=') && str_contains($html, '<table>'));
    $extract = array_values(array_filter(requests(), fn ($q) => isset($q['body']['output_config']['format']['schema']['properties']['facts'])));
    check('no fallbacks sent to Haiku (extraction)', $extract && !array_key_exists('fallbacks', $extract[0]['body']) && empty($extract[0]['headers']['anthropic-beta']));
    $r2 = Assistant::ask('Et en 2024 ?', [['role' => 'assistant', 'content' => 'orphan'], ['role' => 'user', 'content' => 'q1'], ['role' => 'user', 'content' => 'q1 again'], ['role' => 'assistant', 'content' => 'a1'], ['role' => 'user', 'content' => 'dangling']]);
    check('messy history repaired into alternating turns', $r2['answer'] !== '');
    try {
        Assistant::ask('__REFUSE__');
        check('refusal reported', false);
    } catch (UserError $e) {
        check('refusal reported as a clear error', str_contains($e->getMessage(), 'declined'));
    }

    echo "Report writing\n";
    $id = ReportWriter::create('annual', 'التقرير السنوي 2025', 'ar', ['year' => 2025, 'new_data' => 'عدد رخص البناء المسلمة: 1530', 'instructions' => ''], 'tester');
    Worker::runOnce();
    $job = Database::pdo()->query("SELECT * FROM generated_reports WHERE id = $id")->fetch();
    check('annual report written', $job['status'] === 'ready' && str_contains($job['markdown'], '# تقرير') && str_contains($job['markdown'], '[new data]'), [$job['status'], $job['error']]);
    $sys = array_values(array_filter(requests(), fn ($q) => str_contains((string) ($q['body']['system'] ?? ''), 'You write official reports')))[0]['body']['system'] ?? '';
    check('writer receives the structure of the latest report', str_contains($sys, 'دراسة طلبات رخص البناء') && str_contains($sys, 'مواكبة الاستثمار'), mb_substr($sys, 0, 300));
    $docx = Docx::build($job['markdown'], 'ar');
    $zipFile = "$tmp/test.docx";
    file_put_contents($zipFile, $docx);
    $zip = new ZipArchive();
    $xml = $zip->open($zipFile) === true ? $zip->getFromName('word/document.xml') : '';
    check('Word export: valid file with RTL text, table and readable citations', str_contains($xml, 'رخص البناء') && str_contains($xml, '<w:bidi') && str_contains($xml, '<w:tbl>') && str_contains($xml, 'تقرير 2021، ص'), mb_substr(strip_tags((string) $xml), 0, 200));
    $id2 = ReportWriter::create('thematic', 'Évolution des autorisations', 'fr', ['topic' => 'autorisations', 'year_from' => 2021, 'year_to' => 2024], 'tester');
    Worker::runOnce();
    check('thematic report written', Database::pdo()->query("SELECT status FROM generated_reports WHERE id = $id2")->fetchColumn() === 'ready');

    echo "Errors & deletion\n";
    $copy = tempnam($tmp, 'up');
    copy("$root/tests/fixtures/scanned.pdf", $copy);
    $sid = Ingest::register($copy, 'scanned.pdf', 2010, '');
    Worker::runOnce();
    $sc = Database::pdo()->query("SELECT status, error FROM reports WHERE id = $sid")->fetch();
    check('scanned PDF detected with a clear message', $sc['status'] === 'error' && str_contains($sc['error'], 'scanned'), $sc);
    $copy = tempnam($tmp, 'up');
    file_put_contents($copy, 'not a pdf');
    try {
        Ingest::register($copy, 'x.pdf', 2010, '');
        check('non-PDF refused', false);
    } catch (UserError) {
        check('non-PDF refused', true);
    }
    $rid = (int) Database::pdo()->query('SELECT id FROM reports WHERE year = 2024')->fetchColumn();
    Ingest::delete($rid);
    check('deleting a report removes its figures', array_column(Stats::series($perm[0]['id'])['points'], 'year') === [2021, 2022, 2023]);
    check('deleting removes its passages from search', Search::passages('infractions urbanisme', 2024, 2024) === []);

    echo "Web pages\n";
    Auth::createUser('admin', 'admin-password-1', 'admin');
    Auth::createUser('agent', 'agent-password-1', 'user');
    $webPort = $freePort();
    $serve("$root/public", "$root/public/index.php", $webPort);
    $base = "http://127.0.0.1:$webPort";
    $jar = "$tmp/cookies.txt";
    $http = function (string $method, string $path, array $data = [], array $headers = []) use ($base, $jar) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, substr($raw, 0, $size), substr($raw, $size)];
    };
    [$st, $hd] = $http('GET', '/');
    check('not logged in → redirect to login', $st === 303 && str_contains($hd, 'Location: /login'));
    [$st, , $body] = $http('GET', '/api/series?id=1');
    check('API refuses anonymous access', $st === 401);
    [, , $body] = $http('GET', '/login');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    $csrf = $m[1] ?? '';
    [$st, , $body] = $http('POST', '/login', ['csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong']);
    check('wrong password refused', $st === 200 && str_contains($body, 'غير صحيحة'));
    [$st, $hd] = $http('POST', '/login', ['csrf' => $csrf, 'username' => 'admin', 'password' => 'admin-password-1']);
    check('login', $st === 303 && str_contains($hd, 'Location: /'));
    [, , $body] = $http('GET', '/');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $m);
    $csrf = $m[1] ?? '';
    check('dashboard lists reports and counts', str_contains($body, 'قاعدة التقارير') && str_contains($body, '2023') && str_contains($body, 'إضافة تقرير سنوي'));
    [$st, , $body] = $http('GET', '/api/series?id=' . $perm[0]['id']);
    check('series API', $st === 200 && json_decode($body, true)['stats']['first']['value'] === 1240);
    [$st, $hd, $body] = $http('GET', '/stats/csv?id=' . $perm[0]['id']);
    check('CSV export for Excel (UTF-8 BOM)', $st === 200 && str_starts_with($body, "\xEF\xBB\xBF") && str_contains($body, '1385'));
    [$st, , $body] = $http('GET', "/source?report=1&page=2");
    check('source page shows the report text', $st === 200 && str_contains($body, 'رخص البناء'));
    [$st] = $http('POST', '/reports/delete', ['id' => 1]);
    $still = (int) Database::pdo()->query('SELECT COUNT(*) FROM reports WHERE id = 1')->fetchColumn();
    check('POST without CSRF token refused', $still === 1);
    [$st, , $body] = $http('POST', '/api/ask', ['question' => 'كم عدد رخص البناء؟'], ["X-CSRF-Token: $csrf"]);
    $ans = json_decode($body, true);
    check('ask API returns HTML answer with sources and chart data', $st === 200 && str_contains($ans['html'] ?? '', 'cite') && count($ans['series'] ?? []) === 1, [$st, mb_substr($body, 0, 200)]);
    [$st, $hd, $body] = $http('GET', "/write/download?id=$id&format=docx");
    check('Word download', $st === 200 && str_contains($hd, 'wordprocessingml') && str_starts_with($body, 'PK'));
    $http('POST', '/logout', ['csrf' => $csrf]);
    [, , $body] = $http('GET', '/login');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    $http('POST', '/login', ['csrf' => $m[1] ?? '', 'username' => 'agent', 'password' => 'agent-password-1']);
    [, , $body] = $http('GET', '/');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $m);
    check('non-admin does not see the upload form', !str_contains($body, 'إضافة تقرير سنوي'));
    $http('POST', '/reports/delete', ['csrf' => $m[1] ?? '', 'id' => 1]);
    check('non-admin cannot delete reports', (int) Database::pdo()->query('SELECT COUNT(*) FROM reports WHERE id = 1')->fetchColumn() === 1);
} finally {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    if (!getenv('KEEP_TMP')) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
