<?php

/*
 * End-to-end tests against the fake Claude server (tests/mock_claude.php) — no API key, no cost.
 *   php tests/run.php            all tasks on Claude
 *   php tests/run.php --local    all tasks on a local Ollama model (tests/mock_ollama.php), slides on Claude
 * Ingests the four fictitious sample reports (2 Arabic, 2 French), then checks search, statistics,
 * the question assistant, report writing, Word export, error handling and the web pages.
 */

declare(strict_types=1);

use ReportsAI\Assistant;
use ReportsAI\Auth;
use ReportsAI\Database;
use ReportsAI\Docx;
use ReportsAI\Excel;
use ReportsAI\Slides;
use ReportsAI\Ingest;
use ReportsAI\Markdown;
use ReportsAI\ReportWriter;
use ReportsAI\Search;
use ReportsAI\Stats;
use ReportsAI\Text;
use ReportsAI\UserError;
use ReportsAI\Worker;

$root = dirname(__DIR__);
require "$root/src/autoload.php";

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

$local = in_array('--local', $argv, true);
if ($local) {
    $env += ['RAI_PROVIDER' => 'local', 'RAI_PROVIDER_SLIDES' => 'anthropic', 'RAI_LOCAL_MODEL' => 'qwen3.5:4b', 'RAI_LOCAL_MODEL_EXTRACT' => 'qwen2.5:3b', 'RAI_LOCAL_KEY' => 'test-key'];
}

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
if ($local) {
    $ollamaPort = $freePort();
    $serve("$root/tests", "$root/tests/mock_ollama.php", $ollamaPort);
    $env['RAI_LOCAL_URL'] = "http://127.0.0.1:$ollamaPort";
}
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
/** Structured-output schema of a logged request (Claude or Ollama). */
function schemaOf(array $q): ?array
{
    return $q['body']['output_config']['format']['schema'] ?? $q['body']['format'] ?? null;
}
function systemOf(array $q): string
{
    return (string) ($q['body']['system'] ?? (($q['body']['messages'][0]['role'] ?? '') === 'system' ? $q['body']['messages'][0]['content'] : ''));
}
echo $local ? "Mode: local model (Ollama), slides on Claude\n" : "Mode: Claude\n";

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
    check('French reports are not sent for repair', count(array_filter(requests(), fn ($q) => isset(schemaOf($q)['properties']['pages']))) === 2);

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
    check('tool loop ran (list → find → series → search → answer)', count($calls) === 5, count($calls));
    check('thinking blocks and tool results passed back correctly', true); // the mock rejects the request otherwise
    check('answer cites sources', str_contains($r['answer'], '[R') && count($r['sources']) === 4, $r['sources']);
    check('series returned for the chart', ($r['series'][0]['points'][3]['value'] ?? null) === 1468.0);
    if ($local) {
        check('local model: tool turns sent in Ollama format', array_unique(array_column($calls, 'api')) === ['ollama'] && ($calls[4]['body']['messages'][4]['tool_calls'][0]['function']['name'] ?? '') === 'find_indicators' && ($calls[4]['body']['messages'][5]['role'] ?? '') === 'tool');
        check('local model: gateway key, streaming and context size sent', ($calls[0]['headers']['authorization'] ?? '') === 'Bearer test-key' && $calls[0]['body']['stream'] === true && $calls[0]['body']['options']['num_ctx'] === 16384 && !array_key_exists('think', $calls[0]['body']));
    } else {
        check('fallbacks + beta header on Opus', ($calls[0]['body']['fallbacks'] ?? null) === 'default' && str_contains($calls[0]['headers']['anthropic-beta'] ?? '', 'server-side-fallback'));
        check('tools stream their input', ($calls[0]['body']['tools'][0]['eager_input_streaming'] ?? null) === true);
    }
    $html = Markdown::toHtml($r['answer'], fn ($id, $p) => "$id/$p");
    check('answer rendered with source links', str_contains($html, '<a class="cite" href="/source?report=') && str_contains($html, '<table>'));
    $extract = array_values(array_filter(requests(), fn ($q) => isset(schemaOf($q)['properties']['facts'])));
    if ($local) {
        check('local model per task (extraction on qwen2.5:3b)', $extract && $extract[0]['body']['model'] === 'qwen2.5:3b' && ($calls[0]['body']['model'] ?? '') === 'qwen3.5:4b');
    } else {
        check('no fallbacks sent to Haiku (extraction)', $extract && !array_key_exists('fallbacks', $extract[0]['body']) && empty($extract[0]['headers']['anthropic-beta']));
    }
    $r2 = Assistant::ask('Et en 2024 ?', [['role' => 'assistant', 'content' => 'orphan'], ['role' => 'user', 'content' => 'q1'], ['role' => 'user', 'content' => 'q1 again'], ['role' => 'assistant', 'content' => 'a1'], ['role' => 'user', 'content' => 'dangling']]);
    check('messy history repaired into alternating turns', $r2['answer'] !== '');
    if (!$local) {
        $before = count(requests());
        $r3 = Assistant::ask('__BUSY_ONCE__ كم عدد رخص البناء؟');
        check('API busy (429): retried, then answered', $r3['answer'] !== '' && count(requests()) - $before === 6, count(requests()) - $before);
        $error = function (string $q): string {
            try {
                Assistant::ask($q);
                return '';
            } catch (UserError $e) {
                return $e->getMessage();
            }
        };
        $before = count(requests());
        check('error inside the stream: retried twice, then a clear message', str_contains($error('__STREAM_ERROR__'), 'مشغولة') && count(requests()) - $before === 3);
        $before = count(requests());
        check('wrong API key: clear message, no retry', str_contains($error('__BAD_KEY__'), 'ANTHROPIC_API_KEY') && count(requests()) - $before === 1);
        try {
            Assistant::ask('__REFUSE__');
            check('refusal reported', false);
        } catch (UserError $e) {
            check('refusal reported as a clear error', str_contains($e->getMessage(), 'رفض'));
        }
    }

    echo "Report writing\n";
    $id = ReportWriter::create('annual', 'التقرير السنوي 2025', 'ar', ['year' => 2025, 'new_data' => 'عدد رخص البناء المسلمة: 1530', 'instructions' => ''], 'tester');
    Worker::runOnce();
    $job = Database::pdo()->query("SELECT * FROM generated_reports WHERE id = $id")->fetch();
    check('annual report written', $job['status'] === 'ready' && str_contains($job['markdown'], '# تقرير') && str_contains($job['markdown'], '[new data]'), [$job['status'], $job['error']]);
    $sys = array_values(array_filter(requests(), fn ($q) => str_contains(systemOf($q), 'You write official reports')))[0] ?? [];
    $sys = $sys ? systemOf($sys) : '';
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
    check('scanned PDF detected with a clear message', $sc['status'] === 'error' && str_contains($sc['error'], 'ممسوح'), $sc);
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

    echo "Word, Excel, PowerPoint\n";
    // Fixtures made by other programs (PhpOffice libraries, LibreOffice): tests/fixtures/.
    foreach (['rapport-2019.docx' => 2019, 'stats-2020.xlsx' => 2020, 'bilan-2018.pptx' => 2018] as $name => $year) {
        $copy = "$tmp/upload-$name";
        copy("$root/tests/fixtures/$name", $copy);
        Ingest::register($copy, $name, $year, '');
    }
    $hasSoffice = is_executable('/usr/bin/soffice');
    if ($hasSoffice) {
        // Legacy .doc: made from a .docx with LibreOffice, read back through the same conversion.
        file_put_contents("$tmp/old.docx", Docx::build('عدد رخص البناء المسلمة 870', 'ar'));
        exec('cd ' . escapeshellarg($tmp) . ' && soffice --headless -env:UserInstallation=file://' . $tmp . '/lo --convert-to doc old.docx > /dev/null 2>&1');
        copy("$tmp/old.doc", "$tmp/old-copy.doc"); // register() moves the file into storage
        Ingest::register("$tmp/old.doc", 'rapport-2017.doc', 2017, '');
    }
    $repairsBefore = count(array_filter(requests(), fn ($q) => isset(schemaOf($q)['properties']['pages'])));
    while (Worker::runOnce()) {
    }
    $office = Database::pdo()->query("SELECT year, kind, status, pages, error FROM reports WHERE kind <> 'pdf' ORDER BY year")->fetchAll();
    check('Word, Excel, PowerPoint' . ($hasSoffice ? ' and legacy .doc' : '') . ' files processed', count($office) === ($hasSoffice ? 4 : 3) && array_unique(array_column($office, 'status')) === ['ready'], $office);
    $labels = Database::pdo()->query("SELECT r.kind, p.label FROM pages p JOIN reports r ON r.id = p.report_id WHERE r.kind <> 'pdf' ORDER BY r.year, p.page_no")->fetchAll(\PDO::FETCH_NUM);
    $labelsOf = fn ($kind) => array_values(array_map(fn ($l) => $l[1], array_filter($labels, fn ($l) => $l[0] === $kind && !str_starts_with($l[1], 'جزء'))));
    check('Word split at its page breaks (ص 1, ص 2)', in_array('ص 2', $labelsOf('word'), true), $labels);
    check('Excel read sheet by sheet', $labelsOf('excel') === ['ورقة «Permis»'], $labelsOf('excel'));
    check('PowerPoint read slide by slide', $labelsOf('powerpoint') === ['شريحة 1', 'شريحة 2'], $labelsOf('powerpoint'));
    $sheetText = Database::pdo()->query("SELECT text FROM pages p JOIN reports r ON r.id = p.report_id WHERE r.kind = 'excel'")->fetchColumn();
    check('Excel cells kept as rows "a | b"', str_contains($sheetText, 'Autorisations de construire délivrées | 1180'), $sheetText);
    $s = Stats::series($perm[0]['id']);
    $years = array_column($s['points'], 'value', 'year');
    check('figures from Office files join the same indicator', ($years[2018] ?? null) === 990.0 && ($years[2019] ?? null) === 1100.0 && ($years[2020] ?? null) === 1180.0 && (!$hasSoffice || ($years[2017] ?? null) === 870.0), $years);
    $src2020 = array_values(array_filter($s['points'], fn ($p) => $p['year'] === 2020))[0]['source'] ?? [];
    check('sources name the sheet / slide', ($src2020['label'] ?? '') === 'ورقة «Permis»' && str_contains(Search::refLabel((int) explode(':', substr($src2020['ref'], 1))[0], 1), 'ورقة'), $src2020);
    check('Office text is not sent for lam-alef repair', count(array_filter(requests(), fn ($q) => isset(schemaOf($q)['properties']['pages']))) === $repairsBefore);
    $sheetUnits = fn (string $ext) => array_values(\ReportsAI\Documents::units("$root/tests/fixtures/stats-2020.$ext", "stats.$ext"));
    $expected = [['label' => 'ورقة «Permis»', 'text' => "[Sheet: Permis]\nIndicateur | Valeur\nAutorisations de construire délivrées | 1180\nProjets de lotissement | 17\nTotal | 1197"]];
    check('Excel .xlsx, .ods and old .xls read alike (formula results included, empty sheet skipped)', $sheetUnits('xlsx') === $expected && $sheetUnits('ods') === $expected && $sheetUnits('xls') === $expected, [$sheetUnits('xlsx'), $sheetUnits('ods'), $sheetUnits('xls')]);
    file_put_contents("$tmp/win.csv", iconv('UTF-8', 'WINDOWS-1256', "Indicateur;Valeur\n\"Projets; lotissement\";12\nمشاريع;3\n"));
    check('CSV in Windows Arabic encoding, quoted separators', array_values(\ReportsAI\Documents::units("$tmp/win.csv", 'win.csv'))[0]['text'] === "[Sheet: CSV]\nIndicateur | Valeur\nProjets; lotissement | 12\nمشاريع | 3");
    $copy = "$tmp/bad.xlsx";
    file_put_contents($copy, '%PDF-1.4 not a workbook');
    try {
        Ingest::register($copy, 'bad.xlsx', 2010, '');
        check('content that does not match the extension is refused', false);
    } catch (UserError) {
        check('content that does not match the extension is refused', true);
    }
    try {
        $copy = "$tmp/x.exe";
        file_put_contents($copy, 'MZ');
        Ingest::register($copy, 'x.exe', 2010, '');
        check('unsupported type refused', false);
    } catch (UserError) {
        check('unsupported type refused', true);
    }

    echo "Exports\n";
    $xlsx = Excel::series(Stats::series($perm[0]['id']));
    file_put_contents("$tmp/series.xlsx", $xlsx);
    $z = new ZipArchive();
    $z->open("$tmp/series.xlsx");
    $sheetXml = (string) $z->getFromName('xl/worksheets/sheet1.xml');
    check('Excel export: right-to-left sheet, values and a native chart', preg_match('/rightToLeft="(1|true)"/', $sheetXml) === 1 && $z->locateName('xl/charts/chart1.xml') !== false && preg_match('#<v>1240(\.0)?</v>#', $sheetXml) === 1);
    file_put_contents("$tmp/all.xlsx", Excel::allIndicators());
    $all = \ReportsAI\Sheets::read("$tmp/all.xlsx", 'xlsx');
    $head = $all[0]['rows'][0];
    $permRow = array_values(array_filter($all[0]['rows'], fn ($r) => $r[0] === 'عدد رخص البناء المسلمة'))[0] ?? [];
    check('Excel export of all indicators: one column per year', in_array('2018', $head, true) && in_array('2023', $head, true) && in_array('1512', $permRow, true) && in_array('990', $permRow, true), [$head, $permRow]);
    check('Excel export: values with sources sheet', count($all[1]['rows']) > 20);
    $job = Database::pdo()->query("SELECT * FROM generated_reports WHERE id = $id")->fetch();
    $pptx = Slides::build($job);
    file_put_contents("$tmp/deck.pptx", $pptx);
    $z = new ZipArchive();
    $z->open("$tmp/deck.pptx");
    $slideFiles = array_filter(array_map(fn ($i) => $z->getNameIndex($i), range(0, $z->numFiles - 1)), fn ($n) => preg_match('#^ppt/slides/slide\d+\.xml$#', $n));
    $s2xml = (string) $z->getFromName('ppt/slides/slide2.xml');
    $plan = json_decode(Database::pdo()->query("SELECT slides_json FROM generated_reports WHERE id = $id")->fetchColumn(), true);
    check('PowerPoint export: slides, right-to-left text, native chart', count($slideFiles) >= 3 && str_contains($s2xml, 'rtl="1"') && array_filter(range(0, $z->numFiles - 1), fn ($i) => str_starts_with($z->getNameIndex($i), 'ppt/charts/')), [count($slideFiles)]);
    check('chart with a number not in the report is dropped', end($plan['slides'])['chart']['points'] === []);
    $calls = count(requests());
    Slides::build(Database::pdo()->query("SELECT * FROM generated_reports WHERE id = $id")->fetch());
    check('slide plan reused on the next download (no new AI call)', count(requests()) === $calls);

    if ($local) {
        echo "Local model\n";
        $slideReq = array_values(array_filter(requests(), fn ($q) => isset(schemaOf($q)['properties']['slides'])));
        check('mixed setup: slides planned by Claude, the rest by the local model', count($slideReq) === 1 && !isset($slideReq[0]['api']) && str_starts_with($slideReq[0]['body']['model'], 'claude-'));
        $use = Database::pdo()->query("SELECT model, COUNT(*) AS n, SUM(cost_usd) AS cost, SUM(input_tokens) AS tin FROM usage_log WHERE model LIKE 'local:%' GROUP BY model ORDER BY model")->fetchAll();
        check('local calls logged per model, at no cost', array_column($use, 'model') === ['local:qwen2.5:3b', 'local:qwen3.5:4b'] && (float) array_sum(array_column($use, 'cost')) === 0.0 && $use[0]['tin'] > 0, $use);
        $localError = function (array $vars, callable $call): string {
            $saved = array_map(fn ($k) => getenv($k), array_combine(array_keys($vars), array_keys($vars)));
            foreach ($vars as $k => $v) {
                putenv("$k=$v");
            }
            try {
                $call();
                return '';
            } catch (UserError $e) {
                return $e->getMessage();
            } finally {
                foreach ($saved as $k => $v) {
                    putenv($v === false ? $k : "$k=$v");
                }
            }
        };
        $ask = fn () => Assistant::ask('كم عدد رخص البناء؟');
        check('wrong gateway key → clear message', str_contains($localError(['RAI_LOCAL_KEY' => 'wrong'], $ask), 'RAI_LOCAL_KEY'));
        check('model not installed → clear message', str_contains($localError(['RAI_LOCAL_MODEL' => 'llama9:70b'], $ask), 'ollama list'));
        check('computer or tunnel off → clear message', str_contains($localError(['RAI_LOCAL_URL' => 'http://127.0.0.1:' . $freePort()], $ask), 'القناة'));
        check('text too long for the context → clear message, nothing sent', str_contains($localError(['RAI_LOCAL_CONTEXT' => '2048'], $ask), 'RAI_LOCAL_CONTEXT'));
        check('thinking switch sent only when set', $localError(['RAI_LOCAL_THINK' => 'false'], $ask) === '' && (array_slice(requests(), -1)[0]['body']['think'] ?? null) === false);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/local-check.php") . ' 2>&1', $out, $code);
        check('bin/local-check.php confirms the connection', $code === 0 && str_contains(implode("\n", $out), 'qwen3.5:4b'), $out);
    }

    echo "File-reading service on your computer (extractor/)\n";
    $extractPort = $freePort();
    $serve("$root/extractor", "$root/extractor/server.php", $extractPort, ['RAI_EXTRACT_KEY' => 'test-extract-key-0123456789']);
    $remoteEnv = ['RAI_EXTRACT_URL' => "http://127.0.0.1:$extractPort", 'RAI_EXTRACT_KEY' => 'test-extract-key-0123456789', 'RAI_EXTRACT_REMOTE' => 'always'];
    $withEnv = function (array $vars, callable $call) {
        $saved = array_map(fn ($k) => getenv($k), array_combine(array_keys($vars), array_keys($vars)));
        foreach ($vars as $k => $v) {
            putenv("$k=$v");
        }
        try {
            return $call();
        } catch (UserError $e) {
            return $e->getMessage();
        } finally {
            foreach ($saved as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }
    };
    $sample = "$root/samples/rapport-2023-ar.pdf";
    $remoteUnits = $withEnv($remoteEnv, fn () => \ReportsAI\Documents::units($sample, 'تقرير.pdf'));
    check('PDF read by the service gives the same pages as here', $remoteUnits === \ReportsAI\Documents::units($sample, 'x.pdf', allowRemote: false), is_array($remoteUnits) ? count($remoteUnits) : $remoteUnits);
    if ($hasSoffice) {
        $doc = $withEnv($remoteEnv, fn () => \ReportsAI\Documents::units("$tmp/old-copy.doc", 'old.doc'));
        check('old .doc read by the service', is_array($doc) && str_contains($doc[1]['text'] ?? '', '870'), $doc);
    }
    // Whole pipeline: a PDF read on "your computer", then repaired, indexed and analysed here.
    copy("$root/samples/rapport-2021-ar.pdf", "$tmp/remote.pdf");
    file_put_contents("$tmp/remote.pdf", "\n% copy\n", FILE_APPEND); // another file for the duplicate check
    $rid = $withEnv($remoteEnv, function () use ($tmp) {
        $id = Ingest::register("$tmp/remote.pdf", 'remote.pdf', 2012, '');
        Worker::runOnce();
        return $id;
    });
    $rr = Database::pdo()->query("SELECT status, pages, error FROM reports WHERE id = $rid")->fetch();
    $rp = Database::pdo()->query("SELECT text FROM pages WHERE report_id = $rid AND page_no = 1")->fetchColumn();
    check('report read by the service is processed like the others (lam-alef repaired)', $rr['status'] === 'ready' && (int) $rr['pages'] === 3 && str_contains((string) $rp, 'خلال سنة'), $rr);
    Ingest::delete($rid);
    check('wrong key for the service → clear message', str_contains((string) $withEnv(['RAI_EXTRACT_KEY' => 'wrong-key-0123456789xx'] + $remoteEnv, fn () => \ReportsAI\Documents::units($sample, 'a.pdf')), 'RAI_EXTRACT_KEY'));
    check('service not running → clear message', str_contains((string) $withEnv(['RAI_EXTRACT_URL' => 'http://127.0.0.1:' . $freePort()] + $remoteEnv, fn () => \ReportsAI\Documents::units($sample, 'a.pdf')), 'نافذتها'));
    $served = fn () => substr_count((string) file_get_contents("$tmp/server-$extractPort.log"), '[extractor]');
    $before = $served();
    $here = \ReportsAI\Documents::units($sample, 'a.pdf');
    check('without RAI_EXTRACT_URL nothing is sent (pdftotext used here)', count($here) === 3 && $served() === $before && $before >= 3, [$before, $served()]);
    $out = $withEnv($remoteEnv, function () use ($root) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/local-check.php") . ' 2>&1', $lines);
        return implode("\n", $lines);
    });
    check('bin/local-check.php sees the service', str_contains($out, '✔ تعمل'), $out);

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
    check('series API', $st === 200 && in_array(1240, array_column(json_decode($body, true)['points'], 'value')));
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
    [$st, $hd, $body] = $http('GET', "/write/download?id=$id&format=pptx");
    check('PowerPoint download', $st === 200 && str_contains($hd, 'presentationml') && str_starts_with($body, 'PK'));
    [$st, $hd, $body] = $http('GET', '/stats/xlsx?id=' . $perm[0]['id']);
    check('Excel download (one indicator)', $st === 200 && str_contains($hd, 'spreadsheetml') && str_starts_with($body, 'PK'));
    [$st, $hd, $body] = $http('GET', '/stats/xlsx-all');
    check('Excel download (all indicators)', $st === 200 && str_contains($hd, 'spreadsheetml'));
    // Upload through the page (multipart), then the background worker started by the page processes it.
    file_put_contents("$tmp/web.csv", "Indicateur;Valeur\nProjets de lotissement;12\n");
    $ch = curl_init("$base/upload");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'year' => '2016', 'title' => '', 'file' => new CURLFile("$tmp/web.csv", 'text/csv', 'stats-2016.csv')]]);
    curl_exec($ch);
    curl_close($ch);
    for ($i = 0, $st16 = null; $i < 60 && $st16 !== 'ready' && $st16 !== 'error'; $i++) {
        usleep(250_000);
        $st16 = Database::pdo()->query("SELECT status FROM reports WHERE year = 2016")->fetchColumn();
    }
    check('CSV uploaded from the page and processed in the background', $st16 === 'ready', $st16);
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
