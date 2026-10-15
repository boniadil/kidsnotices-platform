<?php

/*
 * File-reading service, to run on your own computer when the hosting cannot run pdftotext or
 * LibreOffice. The hosting sends a file (POST /extract), this service returns its text, page by
 * page (or slide by slide), as JSON. Nothing is stored here: the file is deleted right after reading.
 *
 *   php -S 127.0.0.1:11436 extractor/server.php     (start.ps1 / start.sh do this and open a tunnel)
 *
 * Every request needs "Authorization: Bearer <key>" (RAI_EXTRACT_KEY, or extractor/key.txt).
 *   GET  /health   → {"ok": true, "pdf": bool, "office": bool}
 *   POST /extract  body = the file, header X-Filename = its name (URL-encoded)
 *                  → {"units": [{"label": "ص 1", "text": "…"}, …]}   or 422 {"error": "…"}
 */

declare(strict_types=1);

use ReportsAI\Config;
use ReportsAI\Documents;
use ReportsAI\Pdf;
use ReportsAI\UserError;

require dirname(__DIR__) . '/src/autoload.php';

$reply = function (int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$keyFile = __DIR__ . '/key.txt';
$key = Config::get('RAI_EXTRACT_KEY') ?? (is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '');
if (strlen($key) < 20) {
    $reply(500, ['error' => 'No access key configured (RAI_EXTRACT_KEY or extractor/key.txt, 20+ characters).']);
}
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
if (!hash_equals("Bearer $key", (string) ($headers['authorization'] ?? ''))) {
    $reply(401, ['error' => 'unauthorized']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    $reply(200, ['ok' => true, 'pdf' => Pdf::available(), 'office' => Documents::soffice() !== null]);
}
if ($path !== '/extract' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(404, ['error' => 'not found']);
}

$name = basename(str_replace('\\', '/', rawurldecode((string) ($headers['x-filename'] ?? ''))));
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, Documents::EXTENSIONS, true)) {
    $reply(422, ['error' => 'صيغة غير مدعومة.']);
}
$maxBytes = (int) Config::get('RAI_MAX_UPLOAD_MB', '60') * 1024 * 1024;
$tmp = tempnam(sys_get_temp_dir(), 'rai-x');
register_shutdown_function(fn () => @unlink($tmp)); // runs after every reply (exit skips "finally")
try {
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $size = stream_copy_to_stream($in, $out, $maxBytes + 1);
    fclose($in);
    fclose($out);
    if ($size === 0 || $size > $maxBytes) {
        $reply(422, ['error' => $size === 0 ? 'الملف فارغ.' : 'الملف أكبر من الحد المسموح.']);
    }
    Documents::assertContent($tmp, $name);
    $units = Documents::units($tmp, $name, allowRemote: false);
    error_log(sprintf('[extractor] %s: %d units, %d KB', $name, count($units), intdiv($size, 1024)));
    $reply(200, ['units' => array_values($units)]);
} catch (UserError $e) {
    $reply(422, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[extractor] ' . $e);
    $reply(500, ['error' => 'internal error']);
}
