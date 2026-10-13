<?php

// Landing page content — English (served at "/en").
return [
    'lang' => 'en',
    'dir' => 'ltr',
    'path' => '/en',
    'locale' => 'en_US',
    'title' => 'KidsNotices AI — AI Assistant for Schools in Morocco',
    'description' => 'AI for private schools in Morocco: 24/7 answers for parents in Darija, Arabic and French, bilingual announcements, help for teachers. Student data protected.',
    'og_alt' => 'KidsNotices AI — the AI assistant for schools in Morocco',
    'skip' => 'Skip to content',
    'nav' => ['features' => 'Features', 'privacy' => 'Privacy', 'platforms' => 'Platforms', 'faq' => 'FAQ', 'cta' => 'Try KidsNotices'],
    'hero' => [
        'badge' => 'New in KidsNotices',
        'h1' => 'The AI assistant for your school — in Arabic, Darija and French',
        'lead' => 'KidsNotices AI answers parents at any hour about absences, grades, homework and payments, drafts your announcements in two languages in seconds, and saves teachers and staff hours of repetitive work — without student names ever leaving your school.',
        'primary' => 'Try the platform for free',
        'secondary' => 'See the features',
        'note' => 'Built for private schools and school groups in Morocco.',
    ],
    'demo' => [
        'label' => 'Illustrative example',
        'q' => 'Hi, was my son absent today?',
        'a' => 'Yes, Yassine missed the first session this morning, and the absence is not justified yet. You can justify it from the “Absences” page in the app.',
        'q2' => 'And what homework does he have for tomorrow?',
        'a2' => 'Fraction exercises in maths, page 42.',
    ],
    'benefits' => [
        'title' => 'Why KidsNotices AI?',
        'items' => [
            ['clock', 'Instant answers, day and night', 'Parents get an answer in seconds, straight from the school’s own data, instead of waiting on the phone.'],
            ['phone', 'Fewer repetitive calls', 'The same everyday questions (“absent?”, “homework?”, “paid?”) are handled automatically, so staff can focus on what matters.'],
            ['globe', 'The language Moroccan families speak', 'Understands Darija, Arabic and French, and answers each parent in the language they wrote in.'],
            ['shield', 'Student privacy first', 'Names are replaced with codes before reaching the AI; phone numbers and Massar codes are removed automatically.'],
        ],
    ],
    'features' => [
        'title' => 'Everything your school needs, in one service',
        'intro' => 'Six smart tools built into the parent, teacher and admin spaces.',
        'items' => [
            ['chat', 'Parent assistant', 'Answers questions about absences, grades, homework, payments, transport and the canteen — only from the parent’s own children’s data — and says clearly when it doesn’t know.', 'Parents'],
            ['search', 'Smart search', 'A Google-style search box: a direct answer, then the right links inside the platform, with voice search.', 'Everyone'],
            ['pen', 'Bilingual announcements in seconds', 'Jot your ideas in two lines and get a polished announcement in Arabic and French that keeps every date and amount.', 'Admin & supervisors'],
            ['translate', 'Arabic ↔ French translation', 'Accurate translation using Moroccan school vocabulary: Massar, continuous assessment, tests…', 'Everyone'],
            ['book', 'Teacher assistant', 'Varied, encouraging report-card comments for each student, and exercise ideas matched to the lesson and level.', 'Teachers'],
            ['chart', 'Smart report for the principal', 'Turns absence, unpaid-fee and incident figures into a clear summary with alerts and practical recommendations.', 'Administration'],
        ],
    ],
    'how' => [
        'title' => 'How does it work?',
        'steps' => [
            ['The user asks a question', 'A parent, teacher or principal types or speaks their question, inside the platform they already use every day.'],
            ['The platform sends only authorised data', 'After checking the user’s permissions, with names masked and phone numbers, emails and Massar codes removed.'],
            ['A clear answer in seconds', 'Real names are restored in the answer, shown with the right link in the platform.'],
        ],
    ],
    'privacy' => [
        'title' => 'Student data stays protected',
        'intro' => 'The service follows data minimisation to help schools comply with Moroccan law 09-08.',
        'items' => [
            'Pseudonymisation: every name is replaced with a code (e.g. [S12]) before sending, and restored only in the answer.',
            'Automatic removal of phone numbers, emails, Massar codes and ID numbers; birth dates become ages.',
            'Each user only sees what they are entitled to: a parent sees only their own children.',
            'The text of questions and answers is not stored — only the number of requests and their cost.',
        ],
    ],
    'control' => [
        'title' => 'Full control over usage and costs',
        'items' => [
            ['gauge', 'Limits per feature and per user', 'A monthly limit per school and per tool, and a daily limit per user: no surprises on the bill.'],
            ['layers', 'The right model for each task', 'A fast, low-cost model for everyday questions and a more powerful one for reports.'],
            ['table', 'A clear usage dashboard', 'Requests and estimated cost per school and per feature, month by month.'],
        ],
    ],
    'platforms' => [
        'title' => 'Building a school platform? Use the service through the API',
        'text' => 'KidsNotices AI is a standalone service that works with any school management platform: one key per platform, schools linked to the key, monthly quotas and usage reports for billing.',
        'items' => ['Simple JSON API', 'Drop-in PHP client, no Composer needed', 'Built-in pseudonymisation', 'Ready-to-embed smart search page'],
    ],
    'faq' => [
        'title' => 'Frequently asked questions',
        'items' => [
            ['Does the assistant understand Moroccan Darija?', 'Yes. It understands Darija, Modern Standard Arabic and French, and answers in the parent’s language (in simple Arabic when the message is in Darija).'],
            ['Can it make up grades or dates?', 'The assistant is set up to answer only from the school data it receives. If the information isn’t there, it says so and points the parent to the administration. Every answer is labelled as AI-generated.'],
            ['Is student data safe?', 'Names are replaced with codes before reaching the AI, phone numbers, emails and Massar codes are removed, and conversation text is not stored.'],
            ['Does it replace school staff?', 'No. It handles repetitive questions and routine writing, so staff and teachers can focus on students and on situations that need a human.'],
            ['Can a platform other than KidsNotices use it?', 'Yes. The service is available through an API for any school platform, with its own key, linked schools and monthly quotas.'],
        ],
    ],
    'cta' => [
        'title' => 'Instant answers for parents, more time for your team',
        'text' => 'Explore KidsNotices with a complete demo school, or message us on WhatsApp to switch on the AI assistant for your school.',
        'button' => 'Try KidsNotices',
    ],
    'contact' => [
        'whatsapp' => 'Message us on WhatsApp',
        'call' => 'Call us',
        'phone_label' => 'Phone & WhatsApp',
        'message' => "Hello, I'd like information about KidsNotices AI for my school.",
        'float' => 'Message us on WhatsApp',
    ],
    'footer' => [
        'tagline' => 'SaaS platform for managing schools in Morocco.',
        'site' => 'Main website',
        'disclaimer' => 'Answers are AI-generated and may contain mistakes; official information remains with the school administration.',
    ],
];
