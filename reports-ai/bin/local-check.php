<?php

/*
 * Checks what runs on your own computer: the file-reading service (extractor/, RAI_EXTRACT_URL)
 * and the local model (Ollama, directly or through a gateway and a tunnel).
 *   php bin/local-check.php
 * Shows which tasks use the local model, lists the installed models and sends one test message per model.
 */

declare(strict_types=1);

use ReportsAI\Config;
use ReportsAI\Ollama;
use ReportsAI\Pdf;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');

$url = rtrim(Config::get('RAI_LOCAL_URL', 'http://127.0.0.1:11434'), '/');
$models = [];
foreach (['extract', 'map', 'repair', 'ask', 'report', 'slides'] as $task) {
    $local = Config::provider($task) === 'local';
    $model = $local ? (Config::get('RAI_LOCAL_MODEL_' . strtoupper($task)) ?? Config::get('RAI_LOCAL_MODEL') ?? '?') : Config::model($task);
    printf("  %-8s → %s\n", $task, $local ? "محلي: $model" : "Claude: $model");
    if ($local) {
        $models[$model][] = $task;
    }
}

// File reading: pdftotext here, or the file-reading service on your computer (extractor/).
$failed = false;
if (($extract = Config::get('RAI_EXTRACT_URL')) !== null) {
    echo "\nخدمة قراءة الملفات على حاسوبك: $extract …\n";
    $ch = curl_init(rtrim($extract, '/') . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . Config::get('RAI_EXTRACT_KEY', '')], CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30]);
    $health = json_decode((string) curl_exec($ch), true);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status === 200 && !empty($health['ok'])) {
        echo '✔ تعمل. PDF: ' . ($health['pdf'] ? 'نعم' : 'لا (pdftotext غير موجود على حاسوبك)') . '، ‎.doc/.ppt: ' . ($health['office'] ? 'نعم' : 'لا (LibreOffice غير مثبت)') . "\n";
    } else {
        fwrite(STDERR, "✘ لا تجيب (HTTP $status). " . ($status === 401 ? 'تحقق من RAI_EXTRACT_KEY.' : 'تأكد أن نافذتها مفتوحة وأن RAI_EXTRACT_URL هو العنوان المعروض فيها.') . "\n");
        $failed = true;
    }
} else {
    echo "\nقراءة PDF على هذا الخادم: " . (Pdf::available() ? '✔ pdftotext موجود' : '✘ pdftotext غير موجود — شغّل خدمة قراءة الملفات على حاسوبك (extractor/)') . "\n";
}

if ($models === []) {
    echo "\nلا توجد مهمة مضبوطة على النموذج المحلي (RAI_PROVIDER=local).\n";
    exit($failed ? 1 : 0);
}

echo "\nالاتصال بـ $url …\n";
$headers = [];
if (($key = Config::get('RAI_LOCAL_KEY')) !== null) {
    $name = Config::get('RAI_LOCAL_KEY_HEADER', 'Authorization');
    $headers[] = strcasecmp($name, 'Authorization') === 0 ? "Authorization: Bearer $key" : "$name: $key";
}
$ch = curl_init("$url/api/tags");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($body === false || $status !== 200) {
    fwrite(STDERR, "✘ فشل الاتصال (HTTP $status $error). " . ($status === 401 || $status === 403 ? 'تحقق من RAI_LOCAL_KEY.' : 'تأكد أن الحاسوب وOllama والقناة تعمل، وأن RAI_LOCAL_URL صحيح.') . "\n");
    exit(1);
}
$installed = array_column(json_decode((string) $body, true)['models'] ?? [], 'name');
echo '✔ متصل. النماذج المثبتة: ' . implode('، ', $installed) . "\n";

foreach ($models as $model => $tasks) {
    if (!in_array($model, $installed, true)) {
        fwrite(STDERR, "✘ النموذج «{$model}» غير مثبت (ollama pull {$model}).\n");
        $failed = true;
        continue;
    }
    $start = microtime(true);
    try {
        $reply = (new Ollama($tasks[0]))->chat(Ollama::messages('Reply with one short sentence.', [['role' => 'user', 'content' => 'قل: مرحباً']]), [], 200);
        printf("✔ %s أجاب في %.1f ث: %s\n", $model, microtime(true) - $start, mb_substr(trim($reply['content']), 0, 80));
    } catch (\Throwable $e) {
        fwrite(STDERR, "✘ $model: {$e->getMessage()}\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
