<?php

declare(strict_types=1);

/*
 * KidsNotices AI service — single entry point.
 *
 * Only a client platform's server calls this service (never a browser or a mobile app directly):
 *   Authorization: Bearer <client API key>      (created with: php bin/kn-admin.php client:create)
 *   X-School-Id:   <school id, must be linked to the key>
 *   X-End-User:    <hashed id of the end user, optional, for per-user daily limits>
 *   Content-Type:  application/json
 */

use KidsNotices\AI\Clients;
use KidsNotices\AI\Config;
use KidsNotices\AI\Dashboard;
use KidsNotices\AI\Features\AdminInsights;
use KidsNotices\AI\Features\MessageWriter;
use KidsNotices\AI\Features\ParentAssistant;
use KidsNotices\AI\Features\SmartSearch;
use KidsNotices\AI\Features\TeacherAssistant;
use KidsNotices\AI\HttpError;
use KidsNotices\AI\Input;
use KidsNotices\AI\Limits;
use KidsNotices\AI\Privacy\Pseudonymizer;
use KidsNotices\AI\RequestContext;
use KidsNotices\AI\Usage;

// PHP built-in server: let it serve real files (robots.txt, sitemap.xml, images) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) && ($_SERVER['REQUEST_URI'] ?? '/') !== '/') {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::loadEnvFile(dirname(__DIR__) . '/.env');

const MAX_BODY_BYTES = 256 * 1024;

// endpoint => [handler, feature key used for model choice, limits and usage reports]
$routes = [
    'POST /v1/parent/chat' => [[ParentAssistant::class, 'handle'], 'parent_chat'],
    'POST /v1/search' => [[SmartSearch::class, 'handle'], 'search'],
    'POST /v1/messages/draft' => [[MessageWriter::class, 'draft'], 'draft'],
    'POST /v1/messages/translate' => [[MessageWriter::class, 'translate'], 'translate'],
    'POST /v1/teacher/report-comments' => [[TeacherAssistant::class, 'reportComments'], 'teacher'],
    'POST /v1/teacher/homework' => [[TeacherAssistant::class, 'homework'], 'teacher'],
    'POST /v1/admin/insights' => [[AdminInsights::class, 'handle'], 'insights'],
];

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

    // Public landing page (built by site/build.php): / (Arabic), /fr, /en.
    $pages = ['/' => 'ar', '/fr' => 'fr', '/en' => 'en'];
    if (($method === 'GET' || $method === 'HEAD') && isset($pages[$path])) {
        $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($rawPath !== $path && $path !== '/') {
            header('Location: ' . $path, true, 301); // one URL per page: /fr/ → /fr
            exit;
        }
        $file = dirname(__DIR__) . '/site/dist/' . $pages[$path] . '.html';
        if (is_file($file)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Language: ' . $pages[$path]);
            header('Cache-Control: public, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            if ($method === 'GET') {
                readfile($file);
            }
            exit;
        }
    }

    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok']);
    }
    if ($method === 'GET' && $path === '/dashboard') {
        Dashboard::render();
        exit;
    }

    $schoolId = $_SERVER['HTTP_X_SCHOOL_ID'] ?? '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // A client's own usage for a month: GET /v1/usage?month=YYYY-MM (X-School-Id = any of its schools).
    if ($method === 'GET' && $path === '/v1/usage') {
        Clients::assertSchoolId($schoolId);
        $client = Clients::authenticate($authorization, $schoolId);
        $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : gmdate('Y-m');
        respond(200, [
            'month' => $month,
            'monthly_request_limit' => (int) $client['monthly_request_limit'],
            'monthly_budget_usd' => (float) $client['monthly_budget_usd'],
            'rows' => array_map(fn ($r) => array_diff_key($r, ['client_id' => 1, 'client_name' => 1]), Usage::report($month, (int) $client['id'])),
        ]);
    }

    [$handler, $feature] = $routes["$method $path"] ?? [null, null];
    if ($handler === null) {
        throw new HttpError(404, 'not_found', "Unknown endpoint: $method $path");
    }

    Clients::assertSchoolId($schoolId);
    $client = Clients::authenticate($authorization, $schoolId);

    $endUser = $_SERVER['HTTP_X_END_USER'] ?? null;
    if ($endUser !== null && !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $endUser)) {
        throw new HttpError(400, 'invalid_end_user', 'X-End-User must be a hashed id (8-128 letters, digits, - or _).');
    }

    $raw = file_get_contents('php://input', length: MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        throw new HttpError(413, 'body_too_large', 'Request body is too large.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new HttpError(400, 'invalid_json', 'Request body must be a JSON object.');
    }

    // Optional pseudonymization: names → codes, contacts and identifiers removed; names restored in the answer.
    $privacy = null;
    if (($body['pseudonymize'] ?? false) === true) {
        $privacy = Pseudonymizer::fromPeople(Input::array($body, 'people', 2000, required: false));
        $body = $privacy->scrubRequest($body);
    }

    $ctx = new RequestContext(
        clientId: (int) $client['id'],
        schoolId: $schoolId,
        feature: $feature,
        endUser: $endUser === null ? null : hash('sha256', $client['id'] . ':' . $endUser),
        privacy: $privacy,
    );

    Limits::check($ctx, $client);

    $result = $handler($ctx, $body);
    respond(200, $privacy ? $privacy->restore($result) : $result);
} catch (HttpError $e) {
    respond($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
} catch (\Throwable $e) {
    error_log('[kidsnotices-ai] ' . $e);
    respond(500, ['error' => ['code' => 'internal_error', 'message' => 'Unexpected error.']]);
}
