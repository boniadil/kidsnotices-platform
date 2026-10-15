<?php

/*
 * Sector settings. The platform is generic: copy this file, adapt it to another sector
 * (health, education, water…) and point RAI_DOMAIN_FILE to it.
 */
return [
    'organization' => ['ar' => 'الوكالة الحضرية', 'fr' => 'Agence urbaine'],
    'sector' => ['ar' => 'التعمير وإعداد التراب', 'fr' => "Urbanisme et aménagement du territoire"],

    // Categories used to classify every extracted figure.
    'categories' => [
        'planning' => ['ar' => 'التخطيط ووثائق التعمير', 'fr' => "Planification et documents d'urbanisme"],
        'permits' => ['ar' => 'رخص البناء والتجزئات', 'fr' => 'Autorisations et lotissements'],
        'control' => ['ar' => 'مراقبة المخالفات', 'fr' => 'Contrôle des infractions'],
        'investment' => ['ar' => 'الاستثمار', 'fr' => 'Investissement'],
        'land' => ['ar' => 'العقار والأراضي', 'fr' => 'Foncier'],
        'housing' => ['ar' => 'السكن', 'fr' => 'Habitat'],
        'resources' => ['ar' => 'الموارد البشرية والمالية', 'fr' => 'Ressources humaines et financières'],
        'other' => ['ar' => 'أخرى', 'fr' => 'Autres'],
    ],

    // Vocabulary of the sector (Arabic ↔ French), given to the model so that the same
    // indicator is recognised whatever the language or the year of the report.
    'glossary' => <<<TXT
رخصة البناء = autorisation de construire / permis de construire
رخصة السكن، شهادة المطابقة = permis d'habiter, certificat de conformité
التجزئة العقارية = lotissement
المجموعات السكنية = groupes d'habitation
تقسيم العقارات = morcellement
تصميم التهيئة = plan d'aménagement (PA)
مخطط توجيه التهيئة العمرانية = schéma directeur d'aménagement urbain (SDAU)
تصميم النمو = plan de développement
وثائق التعمير = documents d'urbanisme
المصادقة على وثيقة التعمير = homologation d'un document d'urbanisme
مخالفات التعمير = infractions en matière d'urbanisme
الشباك الوحيد للتعمير = guichet unique d'urbanisme
المشاريع الكبرى / الاستثمار = grands projets / investissement
المساحة المغطاة = superficie couverte (SHON)
الجماعات الترابية = collectivités territoriales
TXT,
];
