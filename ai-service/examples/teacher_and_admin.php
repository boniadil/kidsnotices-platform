<?php

/*
 * EXAMPLE — calling the other AI features from the KidsNotices platform.
 * Run from the command line once the service is up:
 *   KN_AI_URL=https://ai.kidsnotices.ma KN_AI_SECRET=... php examples/teacher_and_admin.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/client/KidsNoticesAI.php';

$ai = new KidsNoticesAI(getenv('KN_AI_URL') ?: 'http://127.0.0.1:8080', (string) getenv('KN_AI_SECRET'), 'najah-rabat');

// 1) Announcement in Arabic + French from a few notes.
$draft = $ai->draftMessage(
    schoolName: 'مجموعة مدارس النجاح',
    notes: 'رحلة مدرسية إلى حديقة الحيوانات بتمارة يوم الجمعة 24 أكتوبر، المغادرة 8:30، الثمن 150 درهم، آخر أجل للتسجيل الأربعاء',
    kind: 'announcement',
    audience: 'parents',
);
echo "AR: {$draft['ar']['title']}\n{$draft['ar']['body']}\n\nFR: {$draft['fr']['title']}\n{$draft['fr']['body']}\n\n";

// 2) Translation.
echo $ai->translate('Les parents sont invités à la réunion du conseil de classe samedi à 10h.', 'ar')['translation'] . "\n\n";

// 3) Report-card comments (first names + internal ids only).
$comments = $ai->reportComments('Mathématiques', 'CE6', [
    ['id' => '101', 'first_name' => 'Yassine', 'grades' => [8.5, 9, 9.5], 'average' => 9],
    ['id' => '102', 'first_name' => 'Salma', 'grades' => [4, 5.5, 6], 'average' => 5.2, 'note' => 'distraite en classe'],
], language: 'fr', term: 'Semestre 1');
foreach ($comments['comments'] as $c) {
    echo "#{$c['id']}: {$c['comment']}\n";
}
echo "\n";

// 4) Homework.
$homework = $ai->homework('الرياضيات', 'الأولى إعدادي', 'جمع وطرح الكسور', count: 3, difficulty: 'easy');
foreach ($homework['exercises'] as $i => $ex) {
    echo ($i + 1) . ". {$ex['statement']}\n   تلميح: {$ex['hint']}\n   الجواب: {$ex['answer']}\n";
}
echo "\n";

// 5) Management report from aggregated figures.
$report = $ai->adminInsights('مجموعة مدارس النجاح', 'أكتوبر 2026', [
    'students_total' => 412,
    'absences_per_class' => ['CE1-A' => 18, 'CE1-B' => 6, 'CE4-A' => 41, '1AC-A' => 12],
    'unjustified_absence_rate_percent' => 37,
    'monthly_fees' => ['expected_mad' => 330000, 'collected_mad' => 268500, 'families_late' => 29],
    'bus_delays_over_10_min' => 7,
    'behaviour_incidents' => 5,
], focus: 'الغياب والمستحقات غير المؤداة');
echo $report['summary'] . "\n";
foreach ($report['alerts'] as $alert) {
    echo "[{$alert['severity']}] {$alert['title']}: {$alert['detail']}\n";
}
