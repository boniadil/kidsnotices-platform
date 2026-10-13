<?php

/*
 * Smart search — AJAX endpoint called by search.php (same origin, user's normal session).
 * It checks the session, gathers the data this user may see, and calls the AI service
 * server-to-server, so the AI secret never reaches the browser.
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/search_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kn_search_fail(int $status, string $code): never
{
    http_response_code($status);
    echo json_encode(['error' => $code]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kn_search_fail(405, 'method_not_allowed');
}

$user = kn_search_user();
if ($user === null) {
    kn_search_fail(401, 'not_logged_in');
}

// CSRF: the page sends the token stored in the session.
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['kn_search_csrf']) || !hash_equals($_SESSION['kn_search_csrf'], $token)) {
    kn_search_fail(403, 'bad_csrf');
}

$query = trim((string) (json_decode((string) file_get_contents('php://input'), true)['q'] ?? ''));
if ($query === '' || mb_strlen($query) > 500) {
    kn_search_fail(400, 'invalid_query');
}

// Light per-user throttle: one search every 2 seconds.
$now = microtime(true);
if (($now - ($_SESSION['kn_search_last'] ?? 0)) < 2) {
    kn_search_fail(429, 'too_fast');
}
$_SESSION['kn_search_last'] = $now;
session_write_close(); // don't block the user's other requests while the AI answers

try {
    $ai = (new KidsNoticesAI(KN_AI_URL, KN_AI_KEY_VALUE, $user['school_id'], timeoutSeconds: 60))->forUser($user['id']);
    $people = kn_search_people($user);
    if ($people !== []) {
        $ai = $ai->withPeople($people);
    }
    $result = $ai->search(
        $user['school_name'],
        $user['role'],
        $query,
        kn_search_context($user),
        kn_search_pages($user['role']),
        $user['name'],
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (KidsNoticesAIException $e) {
    error_log('[kn-search] ' . $e->errorCode . ': ' . $e->getMessage());
    kn_search_fail($e->httpStatus === 429 ? 429 : 502, $e->errorCode);
}
