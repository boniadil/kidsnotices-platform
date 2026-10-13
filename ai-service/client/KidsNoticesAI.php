<?php

declare(strict_types=1);

/**
 * Drop-in client to copy into the KidsNotices platform (plain PHP + cURL, no Composer needed).
 *
 * Usage:
 *   require_once __DIR__ . '/KidsNoticesAI.php';
 *   $ai = new KidsNoticesAI('https://ai.kidsnotices.ma', getenv('KN_AI_KEY'), $schoolId);
 *
 *   $result = $ai
 *       ->forUser($parentId)                  // per-user daily limit (the id is hashed before sending)
 *       ->withPeople([                        // optional: names replaced by codes before reaching the AI
 *           ['id' => 12, 'name' => 'ياسين العلمي', 'role' => 'student'],
 *           ['id' => 4,  'name' => 'أحمد العلمي',  'role' => 'parent'],
 *       ])
 *       ->parentChat('مدرسة النجاح', $context, $history, $question);
 *   echo $result['answer'];                  // real names are already restored
 *
 * Every method returns the decoded JSON answer, or throws KidsNoticesAIException
 * whose ->errorCode can be shown/translated in the UI (e.g. "feature_limit_reached").
 */
final class KidsNoticesAI
{
    private ?string $endUser = null;
    private ?array $people = null;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $schoolId,
        private int $timeoutSeconds = 120,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Copy of the client acting for an end user (parent, teacher…): enables the per-user daily limit. */
    public function forUser(string|int $userId): self
    {
        $copy = clone $this;
        $copy->endUser = hash('sha256', $this->schoolId . ':' . $userId);
        return $copy;
    }

    /**
     * Copy of the client that pseudonymizes requests: the service replaces these people's names
     * with codes, removes phones/emails/Massar codes/birth dates, and restores the names in the answer.
     * $people = [['id' => ..., 'name' => ..., 'role' => 'student'|'parent'|'teacher'|'staff'], ...]
     */
    public function withPeople(array $people): self
    {
        $copy = clone $this;
        $copy->people = $people;
        return $copy;
    }

    /** This client's usage for a month (YYYY-MM, default current). */
    public function usage(?string $month = null): array
    {
        return $this->request('GET', '/v1/usage' . ($month ? '?month=' . rawurlencode($month) : ''));
    }

    /** Parent assistant. $context = only the data this parent may see. */
    public function parentChat(string $schoolName, array $context, array $history, string $question, ?string $parentName = null): array
    {
        return $this->post('/v1/parent/chat', [
            'school_name' => $schoolName,
            'parent_name' => $parentName,
            'context' => $context,
            'history' => $history,
            'question' => $question,
        ]);
    }

    /** Draft an announcement/message in Arabic and/or French. Returns ['ar' => [title, body], 'fr' => [...]]. */
    public function draftMessage(string $schoolName, string $notes, string $kind = 'announcement', string $audience = 'parents', string $tone = 'formal', array $languages = ['ar', 'fr']): array
    {
        return $this->post('/v1/messages/draft', compact('notes', 'kind', 'audience', 'tone', 'languages') + ['school_name' => $schoolName]);
    }

    /** Translate a text to 'ar' or 'fr'. Returns ['translation' => ...]. */
    public function translate(string $text, string $to): array
    {
        return $this->post('/v1/messages/translate', ['text' => $text, 'to' => $to]);
    }

    /** Report-card comments. $students = [['id' => '12', 'first_name' => 'Yassine', 'grades' => [14, 16], 'average' => 15], ...] */
    public function reportComments(string $subject, string $level, array $students, string $language = 'ar', ?string $term = null): array
    {
        return $this->post('/v1/teacher/report-comments', compact('subject', 'level', 'students', 'language', 'term'));
    }

    /** Homework exercises for a lesson. */
    public function homework(string $subject, string $level, string $lessonTitle, ?string $lessonSummary = null, int $count = 5, string $difficulty = 'mixed', string $language = 'ar'): array
    {
        return $this->post('/v1/teacher/homework', [
            'subject' => $subject,
            'level' => $level,
            'lesson_title' => $lessonTitle,
            'lesson_summary' => $lessonSummary,
            'count' => $count,
            'difficulty' => $difficulty,
            'language' => $language,
        ]);
    }

    /** Management report from aggregated figures. */
    public function adminInsights(string $schoolName, string $period, array $data, string $language = 'ar', ?string $focus = null): array
    {
        return $this->post('/v1/admin/insights', [
            'school_name' => $schoolName,
            'period' => $period,
            'data' => $data,
            'language' => $language,
            'focus' => $focus,
        ]);
    }

    /**
     * Google-style search. $pages = [['title' => ..., 'url' => ..., 'description' => ...], ...] the user can open.
     * Returns ['answer' => ..., 'results' => [[title, url, snippet], ...], 'related' => [...]].
     */
    public function search(string $schoolName, string $role, string $query, array $context = [], array $pages = [], ?string $userName = null): array
    {
        return $this->post('/v1/search', [
            'school_name' => $schoolName,
            'role' => $role,
            'user_name' => $userName,
            'query' => $query,
            'context' => $context,
            'pages' => $pages,
        ]);
    }

    private function post(string $path, array $payload): array
    {
        $payload = array_filter($payload, fn ($v) => $v !== null);
        if ($this->people !== null) {
            $payload['pseudonymize'] = true;
            $payload['people'] = $this->people;
        }
        return $this->request('POST', $path, $payload);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-School-Id: ' . $this->schoolId,
        ];
        if ($this->endUser !== null) {
            $headers[] = 'X-End-User: ' . $this->endUser;
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new KidsNoticesAIException('ai_unreachable', 'AI service unreachable: ' . $curlError, 0);
        }
        $data = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) ? ($data['error'] ?? []) : [];
            throw new KidsNoticesAIException($error['code'] ?? 'ai_error', $error['message'] ?? "HTTP $status", $status);
        }
        return $data;
    }
}

final class KidsNoticesAIException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}
