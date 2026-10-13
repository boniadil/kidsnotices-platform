<?php

declare(strict_types=1);

/*
 * KidsNotices AI service — single entry point.
 *
 * Only the KidsNotices server calls this service (never the browser or the Android app directly):
 *   Authorization: Bearer <KN_AI_SECRET>
 *   X-School-Id:   <tenant id of the school making the request>
 *   Content-Type:  application/json
 */

use KidsNotices\AI\Config;
use KidsNotices\AI\Features\AdminInsights;
use KidsNotices\AI\Features\MessageWriter;
use KidsNotices\AI\Features\ParentAssistant;
use KidsNotices\AI\Features\SmartSearch;
use KidsNotices\AI\Features\TeacherAssistant;
use KidsNotices\AI\HttpError;
use KidsNotices\AI\RateLimiter;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::loadEnvFile(dirname(__DIR__) . '/.env');

const MAX_BODY_BYTES = 256 * 1024;

$routes = [
    'POST /v1/parent/chat' => [ParentAssistant::class, 'handle'],
    'POST /v1/messages/draft' => [MessageWriter::class, 'draft'],
    'POST /v1/messages/translate' => [MessageWriter::class, 'translate'],
    'POST /v1/teacher/report-comments' => [TeacherAssistant::class, 'reportComments'],
    'POST /v1/teacher/homework' => [TeacherAssistant::class, 'homework'],
    'POST /v1/admin/insights' => [AdminInsights::class, 'handle'],
    'POST /v1/search' => [SmartSearch::class, 'handle'],
];

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok', 'model' => Config::model()]);
    }

    $handler = $routes["$method $path"] ?? null;
    if ($handler === null) {
        throw new HttpError(404, 'not_found', "Unknown endpoint: $method $path");
    }

    // Authentication: shared secret between the KidsNotices server and this service.
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!hash_equals('Bearer ' . Config::secret(), $auth)) {
        throw new HttpError(401, 'unauthorized', 'Missing or invalid Authorization header.');
    }

    $schoolId = $_SERVER['HTTP_X_SCHOOL_ID'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $schoolId)) {
        throw new HttpError(400, 'invalid_school', 'Header X-School-Id is required (letters, digits, - and _ only).');
    }

    $raw = file_get_contents('php://input', length: MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        throw new HttpError(413, 'body_too_large', 'Request body is too large.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new HttpError(400, 'invalid_json', 'Request body must be a JSON object.');
    }

    RateLimiter::hit($schoolId);

    respond(200, $handler($schoolId, $body));
} catch (HttpError $e) {
    respond($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
} catch (\Throwable $e) {
    error_log('[kidsnotices-ai] ' . $e);
    respond(500, ['error' => ['code' => 'internal_error', 'message' => 'Unexpected error.']]);
}
