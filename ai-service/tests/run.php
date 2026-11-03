<?php

/*
 * End-to-end tests of the AI service against the fake Claude server (tests/mock_claude.php).
 * No API key, no cost:   php tests/run.php
 *
 * Starts the mock and the service with PHP's built-in server on free ports, a temporary SQLite
 * database, and small limits; then calls every endpoint and checks answers, errors and usage.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/client/KidsNoticesAI.php';

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kn-ai-tests-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

$env = [
    'PATH' => getenv('PATH'),
    'ANTHROPIC_API_KEY' => 'sk-test',
    'KN_AI_STORAGE_DIR' => $tmp,
    'KN_AI_DB_DSN' => "sqlite:$tmp/test.sqlite",
    'KN_AI_MODEL' => 'claude-opus-5-5',
    'KN_AI_MODEL_TRANSLATE' => 'claude-haiku-5-5',
    'KN_AI_MONTHLY_LIMIT_INSIGHTS' => '2',
    'KN_AI_DAILY_LIMIT_PER_USER' => '2',
    'KN_AI_ADMIN_PASSWORD_HASH' => password_hash('test-password-123', PASSWORD_DEFAULT),
    'MOCK_LOG_DIR' => $tmp,
];

// --- helpers ---------------------------------------------------------------
$processes = [];
function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}
function serve(string $docroot, string $router, array $env, int $port): void
{
    global $processes;
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot, $router];
    $processes[] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $env['KN_AI_STORAGE_DIR'] . "/server-$port.log", 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            return;
        }
        usleep(100_000);
    }
    throw new RuntimeException("Server on port $port did not start");
}
function admin(array $args, array $env): string
{
    global $root;
    $cmd = array_merge([PHP_BINARY, "$root/bin/kn-admin.php"], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
}
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true), $raw];
}
function lastRequest(): array
{
    global $tmp;
    return json_decode((string) file_get_contents("$tmp/last_request.json"), true);
}
function attempt(callable $fn): array
{
    try {
        return ['ok' => true, 'data' => $fn()];
    } catch (KidsNoticesAIException $e) {
        return ['ok' => false, 'status' => $e->httpStatus, 'code' => $e->errorCode, 'message' => $e->getMessage()];
    }
}

$passed = 0;
$failed = 0;
function check(string $name, bool $condition, mixed $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✔ $name\n";
    } else {
        $failed++;
        echo "  ✘ $name\n" . ($detail !== null ? '      ' . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n" : '');
    }
}

// --- setup -----------------------------------------------------------------
$mockPort = freePort();
$env['ANTHROPIC_BASE_URL'] = "http://127.0.0.1:$mockPort";
serve("$root/tests", "$root/tests/mock_claude.php", $env, $mockPort);
$port = freePort();
serve("$root/public", "$root/public/index.php", $env, $port);
$base = "http://127.0.0.1:$port";

preg_match('/kn_live_[a-f0-9]{48}/', admin(['client:create', 'Plateforme test'], $env), $m);
$key = $m[0] ?? '';
admin(['school:add', '1', 'school-a'], $env);
admin(['school:add', '1', 'school-b'], $env);
preg_match('/kn_live_[a-f0-9]{48}/', admin(['client:create', 'Client quota', '--requests=1'], $env), $m);
$quotaKey = $m[0] ?? '';
admin(['school:add', '2', 'school-q'], $env);
preg_match('/kn_live_[a-f0-9]{48}/', admin(['client:create', 'Client revoked'], $env), $m);
$revokedKey = $m[0] ?? '';
admin(['school:add', '3', 'school-a'], $env);
admin(['client:revoke', '3'], $env);

$ai = new KidsNoticesAI($base, $key, 'school-a', 30);

try {
    echo "Auth & routing\n";
    check('health', http('GET', "$base/health")[0] === 200);
    check('client keys created', $key !== '' && $quotaKey !== '' && $revokedKey !== '');
    [$s, $b] = http('POST', "$base/v1/messages/translate", ['X-School-Id: school-a'], '{}');
    check('no key → 401', $s === 401 && $b['error']['code'] === 'unauthorized', $b);
    $r = attempt(fn () => (new KidsNoticesAI($base, 'kn_live_' . str_repeat('0', 48), 'school-a'))->translate('x', 'fr'));
    check('unknown key → 401', !$r['ok'] && $r['status'] === 401, $r);
    $r = attempt(fn () => (new KidsNoticesAI($base, $revokedKey, 'school-a'))->translate('x', 'fr'));
    check('revoked key → 401', !$r['ok'] && $r['status'] === 401, $r);
    $r = attempt(fn () => (new KidsNoticesAI($base, $key, 'school-z'))->translate('x', 'fr'));
    check('school not linked to key → 403', !$r['ok'] && $r['code'] === 'school_not_allowed', $r);
    check('unknown endpoint → 404', http('POST', "$base/v1/nope", ["Authorization: Bearer $key", 'X-School-Id: school-a'], '{}')[0] === 404);

    echo "Input validation\n";
    $r = attempt(fn () => $ai->translate('x', 'en'));
    check('bad enum → 400 invalid_input', !$r['ok'] && $r['code'] === 'invalid_input', $r);
    [$s, $b] = http('POST', "$base/v1/messages/translate", ["Authorization: Bearer $key", 'X-School-Id: school-a'], 'not json');
    check('bad JSON → 400', $s === 400 && $b['error']['code'] === 'invalid_json', $b);
    $r = attempt(fn () => $ai->parentChat('S', [], [['role' => 'assistant', 'content' => 'x']], 'q'));
    check('history must start with user → 400', !$r['ok'] && $r['code'] === 'invalid_input', $r);

    echo "Models & fallbacks\n";
    $r = attempt(fn () => $ai->translate('Réunion samedi', 'ar'));
    $req = lastRequest();
    check('translate OK on Haiku', $r['ok'] && $req['body']['model'] === 'claude-haiku-5-5', $r);
    check('Haiku: no fallbacks, no beta header', !array_key_exists('fallbacks', $req['body']) && empty($req['headers']['anthropic-beta']), $req['body']);
    $r = attempt(fn () => $ai->draftMessage('مدرسة', 'رحلة يوم الجمعة'));
    $req = lastRequest();
    check('draft OK on Opus 5.5', $r['ok'] && isset($r['data']['ar']['title'], $r['data']['fr']['body']), $r);
    check('Opus: fallbacks "default" + beta header', ($req['body']['fallbacks'] ?? null) === 'default' && str_contains($req['headers']['anthropic-beta'] ?? '', 'server-side-fallback-2026-07-01'));

    echo "Every endpoint\n";
    $r = attempt(fn () => $ai->reportComments('Maths', 'CE6', [['id' => '1', 'first_name' => 'Sara', 'grades' => [8, 9]]], 'fr'));
    check('report comments', $r['ok'] && isset($r['data']['comments'][0]['comment']), $r);
    $r = attempt(fn () => $ai->homework('الرياضيات', '1AC', 'الكسور', null, 3));
    check('homework', $r['ok'] && isset($r['data']['exercises'][0]['statement']), $r);
    $r = attempt(fn () => $ai->adminInsights('مدرسة', 'أكتوبر', ['absences' => 12]));
    check('admin insights', $r['ok'] && isset($r['data']['summary'], $r['data']['alerts']), $r);
    $r = attempt(fn () => $ai->search('مدرسة', 'parent', 'الغياب', [], [['title' => 'الغياب', 'url' => '/parent/absences.php'], ['title' => 'النقط', 'url' => '/parent/grades.php']]));
    $urls = array_column($r['data']['results'] ?? [], 'url');
    check('search keeps only provided links', $r['ok'] && $urls === ['/parent/absences.php', '/parent/grades.php'], $r);

    echo "Pseudonymization\n";
    $people = [
        ['id' => 12, 'name' => 'ياسين العلمي', 'role' => 'student'],
        ['id' => 4, 'name' => 'أحمد العلمي', 'role' => 'parent'],
        ['id' => 7, 'name' => 'Sara Ben Ali', 'role' => 'teacher'],
    ];
    $context = ['children' => [[
        'first_name' => 'ياسين', 'birth_date' => '2016-05-01', 'phoneNumber' => '0612345678', 'code_massar' => 'J130012345',
        'teacher' => 'Sara Ben Ali', 'note' => 'اتصل بالأب على 06 61 22 33 44 أو ahmed.alami@gmail.com', 'absences' => [['date' => '2026-10-12']],
    ]]];
    $r = attempt(fn () => $ai->withPeople($people)->parentChat('مدرسة النجاح', $context, [], 'واش لياسين غاب؟ احمد كيسول. الأستاذة SARA؟', 'أحمد العلمي'));
    $sent = json_encode(lastRequest()['body'], JSON_UNESCAPED_UNICODE);
    $leaks = array_filter(['ياسين', 'احمد', 'أحمد', 'العلمي', 'Sara', 'SARA', '0612345678', '61 22 33 44', 'ahmed.alami', 'J130012345', '2016-05-01'], fn ($s) => str_contains($sent, $s));
    check('no name / phone / email / Massar / birth date sent to Claude', $leaks === [], array_values($leaks));
    check('codes and age sent instead', str_contains($sent, '[S12]') && str_contains($sent, '[P4]') && str_contains($sent, '[T7]') && preg_match('/"age": \d+/', (string) lastRequest()['body']['system']));
    check('system prompt explains the codes', str_contains((string) lastRequest()['body']['system'], '[S…] a student'));
    $answer = $r['data']['answer'] ?? '';
    check('real names restored in the answer', $r['ok'] && str_contains($answer, 'ياسين العلمي') && str_contains($answer, 'أحمد العلمي') && !str_contains($answer, '[S12]'), $r);
    $r = attempt(fn () => $ai->withPeople([['id' => 'x', 'name' => '']])->translate('x', 'fr'));
    check('invalid people → 400', !$r['ok'] && $r['code'] === 'invalid_input', $r);

    echo "Model answers & provider errors\n";
    $r = attempt(fn () => $ai->translate('__REFUSE__', 'fr'));
    check('refusal → 422 ai_refused', !$r['ok'] && $r['status'] === 422 && $r['code'] === 'ai_refused', $r);
    $r = attempt(fn () => $ai->translate('__TRUNCATE__', 'fr'));
    check('max_tokens → 502 ai_truncated', !$r['ok'] && $r['code'] === 'ai_truncated', $r);
    $r = attempt(fn () => $ai->translate('__APIERR__', 'fr'));
    check('provider error → generic 502', !$r['ok'] && $r['status'] === 502 && $r['code'] === 'ai_error', $r);
    check('provider error text not leaked', !str_contains($r['message'] ?? '', 'shard') && !str_contains($r['message'] ?? '', 'SECRET'), $r);
    $r = attempt(fn () => $ai->translate('__RATELIMIT__', 'fr'));
    check('provider 429 → 503 ai_busy', !$r['ok'] && $r['code'] === 'ai_busy', $r);

    echo "Limits\n";
    $r = attempt(fn () => $ai->adminInsights('مدرسة', 'أكتوبر', ['absences' => 12]));
    check('insights: 2nd call of the month OK', $r['ok'], $r);
    $r = attempt(fn () => $ai->adminInsights('مدرسة', 'أكتوبر', ['absences' => 12]));
    check('insights: 3rd call → feature_limit_reached', !$r['ok'] && $r['code'] === 'feature_limit_reached', $r);
    $r = attempt(fn () => (new KidsNoticesAI($base, $key, 'school-b'))->adminInsights('مدرسة', 'أكتوبر', ['absences' => 1]));
    check('feature limit is per school', $r['ok'], $r);
    check('other features still work', attempt(fn () => $ai->translate('ok', 'fr'))['ok']);
    $parent = $ai->forUser('parent-42');
    $r1 = attempt(fn () => $parent->translate('a', 'fr'));
    $r2 = attempt(fn () => $parent->translate('b', 'fr'));
    $r3 = attempt(fn () => $parent->translate('c', 'fr'));
    check('end user: 3rd call of the day → user_daily_limit_reached', $r1['ok'] && $r2['ok'] && !$r3['ok'] && $r3['code'] === 'user_daily_limit_reached', [$r1, $r2, $r3]);
    check('other end user unaffected', attempt(fn () => $ai->forUser('parent-43')->translate('d', 'fr'))['ok']);
    $q = new KidsNoticesAI($base, $quotaKey, 'school-q');
    $r1 = attempt(fn () => $q->translate('a', 'fr'));
    $r2 = attempt(fn () => $q->translate('b', 'fr'));
    check('client monthly quota → client_quota_exceeded', $r1['ok'] && !$r2['ok'] && $r2['code'] === 'client_quota_exceeded', [$r1, $r2]);

    echo "Usage & dashboard\n";
    $r = attempt(fn () => $ai->usage());
    $rows = $r['data']['rows'] ?? [];
    $draft = array_values(array_filter($rows, fn ($x) => $x['feature'] === 'draft'))[0] ?? null;
    check('usage endpoint lists rows per school/feature', $r['ok'] && count($rows) >= 5, $r);
    check('estimated cost (Opus 5.5: 1000 in + 200 out = $0.008)', $draft !== null && abs($draft['cost_usd'] - 0.008) < 1e-6, $draft);
    $translate = array_values(array_filter($rows, fn ($x) => $x['feature'] === 'translate' && $x['school_id'] === 'school-a'))[0] ?? null;
    check('estimated cost on Haiku 5.5 is per-request $0.0002', $translate !== null && abs($translate['cost_usd'] / $translate['requests'] - 0.0002) < 1e-6, $translate);
    check('usage of another client not visible', !in_array('school-q', array_column($rows, 'school_id'), true));
    check('dashboard requires a password', http('GET', "$base/dashboard")[0] === 401);
    [$s, , $html] = http('GET', "$base/dashboard", ['Authorization: Basic ' . base64_encode('admin:test-password-123')]);
    check('dashboard with password', $s === 200 && str_contains($html, 'Plateforme test') && str_contains($html, 'school-a'));
} finally {
    foreach ($processes as $p) {
        proc_terminate($p);
    }
    if (!getenv("KEEP_TMP")) { array_map("unlink", glob("$tmp/*") ?: []); }
    @rmdir($tmp);
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
