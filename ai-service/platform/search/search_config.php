<?php

/*
 * Smart search — settings to adapt to your platform.
 *
 * Copy the whole folder platform/search/ into your KidsNotices platform, together with
 * client/KidsNoticesAI.php, then edit the three parts below:
 *   1. kn_search_user()     → read the logged-in user from YOUR session
 *   2. kn_search_pages()    → the real URLs of your pages
 *   3. kn_search_context()  → the data each role may see, from YOUR database
 */

declare(strict_types=1);

// The client: copy client/KidsNoticesAI.php next to this file (falls back to the repo copy).
require_once is_file(__DIR__ . '/KidsNoticesAI.php') ? __DIR__ . '/KidsNoticesAI.php' : dirname(__DIR__, 2) . '/client/KidsNoticesAI.php';

// Address of the AI service and the shared secret (same value as KN_AI_SECRET in ai-service/.env).
define('KN_AI_URL', getenv('KN_AI_URL') ?: 'https://ai.kidsnotices.ma');
define('KN_AI_SECRET_VALUE', (string) getenv('KN_AI_SECRET'));

// Demo mode: a fake logged-in parent with sample data, to try the page before wiring your database.
// Set KN_SEARCH_DEMO=1 on a test server only — NEVER in production.
define('KN_SEARCH_DEMO', getenv('KN_SEARCH_DEMO') === '1');

/**
 * 1) The logged-in user, or null if not logged in.
 * @return array{id: string, school_id: string, school_name: string, role: string, name: ?string}|null
 */
function kn_search_user(): ?array
{
    if (KN_SEARCH_DEMO) {
        return ['id' => 'demo-parent', 'school_id' => 'najah-rabat', 'school_name' => 'مجموعة مدارس النجاح', 'role' => 'parent', 'name' => 'أحمد'];
    }

    // TODO: replace with your platform's session keys.
    if (empty($_SESSION['user_id']) || empty($_SESSION['school_id'])) {
        return null;
    }
    return [
        'id' => (string) $_SESSION['user_id'],
        'school_id' => (string) $_SESSION['school_id'],
        'school_name' => (string) ($_SESSION['school_name'] ?? ''),
        'role' => (string) $_SESSION['role'],          // parent | teacher | admin | guard | assistant | driver | student
        'name' => $_SESSION['first_name'] ?? null,
    ];
}

/**
 * 2) Pages the search can link to, per role. Only list pages this role is allowed to open.
 * TODO: replace the URLs with your real ones.
 */
function kn_search_pages(string $role): array
{
    $common = [
        ['title' => 'الإعلانات / Annonces', 'url' => '/announcements.php', 'description' => 'School announcements and news'],
        ['title' => 'استعمال الزمن / Emploi du temps', 'url' => '/timetable.php', 'description' => 'Weekly timetable per class'],
        ['title' => 'المقصف / Cantine', 'url' => '/canteen.php', 'description' => 'Weekly canteen menu and meal orders'],
        ['title' => 'المراسلة / Messagerie', 'url' => '/messages.php', 'description' => 'Messages with the administration and teachers'],
    ];

    $byRole = [
        'parent' => [
            ['title' => 'الغياب / Absences', 'url' => '/parent/absences.php', 'description' => "Child's absences and lateness, justify an absence"],
            ['title' => 'النقط / Notes', 'url' => '/parent/grades.php', 'description' => "Child's grades, averages and report cards"],
            ['title' => 'الدروس والواجبات / Devoirs', 'url' => '/parent/homework.php', 'description' => 'Lessons and homework with due dates'],
            ['title' => 'الأداءات / Paiements', 'url' => '/parent/payments.php', 'description' => 'Monthly fees, receipts and payment status'],
            ['title' => 'تتبع الحافلة / Suivi du bus', 'url' => '/parent/bus.php', 'description' => 'Live school bus position and trip events'],
            ['title' => 'طلبات المغادرة / Sorties', 'url' => '/parent/exit-requests.php', 'description' => 'Early exit requests and pick-up authorisations (QR)'],
            ['title' => 'الملف الصحي / Dossier médical', 'url' => '/parent/health.php', 'description' => 'Health record and daily medication'],
        ],
        'teacher' => [
            ['title' => 'تسجيل الغياب / Appel', 'url' => '/teacher/attendance.php', 'description' => 'Take attendance for a class session'],
            ['title' => 'إدخال النقط / Saisie des notes', 'url' => '/teacher/grades.php', 'description' => 'Enter grades, Massar import/export'],
            ['title' => 'الدروس والواجبات / Cahier de textes', 'url' => '/teacher/homework.php', 'description' => 'Publish lessons and homework'],
            ['title' => 'أقسامي / Mes classes', 'url' => '/teacher/classes.php', 'description' => 'Class lists and students'],
        ],
        'admin' => [
            ['title' => 'التلاميذ / Élèves', 'url' => '/admin/students.php', 'description' => 'Student records, enrolment, classes'],
            ['title' => 'الأساتذة / Enseignants', 'url' => '/admin/teachers.php', 'description' => 'Teachers, sessions and hourly pay'],
            ['title' => 'التدبير المالي / Finances', 'url' => '/admin/finance.php', 'description' => 'Monthly fees, discounts, unpaid list'],
            ['title' => 'الغياب / Absences', 'url' => '/admin/absences.php', 'description' => 'Attendance statistics for students and teachers'],
            ['title' => 'الإحصاء السنوي / Recensement', 'url' => '/admin/census.php', 'description' => 'Annual official school census data'],
            ['title' => 'النقل المدرسي / Transport', 'url' => '/admin/transport.php', 'description' => 'Buses, drivers, routes'],
            ['title' => 'المساعدون والصلاحيات / Permissions', 'url' => '/admin/assistants.php', 'description' => 'Administrative assistants and their permissions'],
            ['title' => 'النسخ الاحتياطية / Sauvegardes', 'url' => '/admin/backups.php', 'description' => 'Daily backups and restore'],
        ],
        'guard' => [
            ['title' => 'الغياب والتأخر / Absences', 'url' => '/guard/absences.php', 'description' => 'Daily absences and lateness'],
            ['title' => 'طلبات المغادرة / Sorties', 'url' => '/guard/exits.php', 'description' => 'Exit requests and QR pick-up checks'],
            ['title' => 'السلوك / Discipline', 'url' => '/guard/behaviour.php', 'description' => 'Behaviour records'],
            ['title' => 'الصحة / Santé', 'url' => '/guard/health.php', 'description' => 'Medication log and emergencies'],
        ],
    ];

    return array_merge($byRole[$role] ?? [], $common);
}

/**
 * 3) The data this user may see, kept small (recent data, first names, no national ids or phones).
 * TODO: replace the demo data with queries on your database, filtered by school_id AND by user.
 */
function kn_search_context(array $user): array
{
    if (KN_SEARCH_DEMO) {
        return [
            'children' => [[
                'first_name' => 'ياسين',
                'class' => 'CE4-A',
                'absences_last_30_days' => [['date' => date('Y-m-d', strtotime('-2 days')), 'session' => 'matin', 'justified' => false]],
                'recent_grades' => [
                    ['subject' => 'الرياضيات', 'exam' => 'الفرض 1', 'grade' => 8.5, 'max' => 10],
                    ['subject' => 'Français', 'exam' => 'Contrôle 1', 'grade' => 7, 'max' => 10],
                ],
                'upcoming_homework' => [['subject' => 'الرياضيات', 'title' => 'تمارين الكسور ص 42', 'due' => date('Y-m-d', strtotime('+1 day'))]],
                'payments' => [['month' => date('Y-m'), 'amount_mad' => 1800, 'status' => 'unpaid']],
            ]],
            'canteen_menu_today' => 'دجاج بالخضر، أرز، فاكهة',
            'latest_announcements' => [['title' => 'اجتماع أولياء الأمور', 'date' => date('Y-m-d', strtotime('+5 days'))]],
        ];
    }

    // Example for a parent (adapt table/column names):
    // global $pdo;
    // $stmt = $pdo->prepare('SELECT id, first_name, class_name FROM students WHERE parent_id = ? AND school_id = ?');
    // $stmt->execute([$user['id'], $user['school_id']]);
    // ... same queries as ai-service/examples/parent_chat_endpoint.php
    return [];
}
