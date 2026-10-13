<?php

/*
 * EXAMPLE — to adapt inside the KidsNotices platform (not part of the AI service).
 *
 * An AJAX endpoint of the parent space: the PWA / Android app calls THIS file
 * (with the parent's normal session), and this file calls the AI service server-to-server.
 * The secret KN_AI_SECRET therefore never leaves your server.
 *
 * Table and column names below are placeholders: replace them with yours.
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/KidsNoticesAI.php';   // copied from ai-service/client/
require_once __DIR__ . '/db.php';             // your existing PDO connection: $pdo

header('Content-Type: application/json; charset=utf-8');

// 1) The parent must be logged in — reuse your platform's existing check.
$parentId = $_SESSION['parent_id'] ?? null;
$schoolId = $_SESSION['school_id'] ?? null;
if (!$parentId || !$schoolId) {
    http_response_code(401);
    exit(json_encode(['error' => 'not_logged_in']));
}

$question = trim((string) (json_decode(file_get_contents('php://input'), true)['question'] ?? ''));
if ($question === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'empty_question']));
}

// 2) Build the context with ONLY this parent's children, from the current school (tenant).
//    Keep it small: recent data only, first names, no national ids / phone numbers.
$children = $pdo->prepare('SELECT id, first_name, class_name FROM students WHERE parent_id = ? AND school_id = ?');
$children->execute([$parentId, $schoolId]);

$context = ['children' => []];
foreach ($children->fetchAll(PDO::FETCH_ASSOC) as $child) {
    $absences = $pdo->prepare('SELECT date, session, justified FROM absences WHERE student_id = ? AND date >= CURDATE() - INTERVAL 30 DAY');
    $absences->execute([$child['id']]);

    $grades = $pdo->prepare('SELECT subject, exam_label, grade, max_grade, date FROM grades WHERE student_id = ? ORDER BY date DESC LIMIT 30');
    $grades->execute([$child['id']]);

    $homework = $pdo->prepare('SELECT subject, title, due_date FROM homework WHERE class_name = ? AND school_id = ? AND due_date >= CURDATE()');
    $homework->execute([$child['class_name'], $schoolId]);

    $payments = $pdo->prepare('SELECT month, amount, status FROM payments WHERE student_id = ? ORDER BY month DESC LIMIT 6');
    $payments->execute([$child['id']]);

    $context['children'][] = [
        'first_name' => $child['first_name'],
        'class' => $child['class_name'],
        'absences_last_30_days' => $absences->fetchAll(PDO::FETCH_ASSOC),
        'recent_grades' => $grades->fetchAll(PDO::FETCH_ASSOC),
        'upcoming_homework' => $homework->fetchAll(PDO::FETCH_ASSOC),
        'payments_last_6_months' => $payments->fetchAll(PDO::FETCH_ASSOC),
    ];
}
// Add whatever else is useful: canteen menu of the week, latest announcements, bus status...

// 3) Conversation history kept in the PHP session (last 10 exchanges).
$history = $_SESSION['ai_history'] ?? [];

try {
    $ai = new KidsNoticesAI('https://ai.kidsnotices.ma', getenv('KN_AI_SECRET'), (string) $schoolId);
    $result = $ai->parentChat($_SESSION['school_name'], $context, $history, $question, $_SESSION['parent_name'] ?? null);

    $history[] = ['role' => 'user', 'content' => $question];
    $history[] = ['role' => 'assistant', 'content' => $result['answer']];
    $_SESSION['ai_history'] = array_slice($history, -20);

    echo json_encode(['answer' => $result['answer']], JSON_UNESCAPED_UNICODE);
} catch (KidsNoticesAIException $e) {
    $messages = [
        'daily_limit_reached' => 'تم بلوغ الحد اليومي للمساعد الذكي. حاول غداً.',
        'ai_busy' => 'المساعد مشغول حالياً، حاول بعد لحظات.',
        'ai_refused' => 'لم يتمكن المساعد من الإجابة على هذا السؤال. المرجو إعادة صياغته.',
    ];
    http_response_code($e->httpStatus >= 400 ? $e->httpStatus : 502);
    echo json_encode(['error' => $messages[$e->errorCode] ?? 'المساعد غير متاح حالياً.'], JSON_UNESCAPED_UNICODE);
}
