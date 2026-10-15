<?php

// Landing page content — French (served at "/fr").
return [
    'lang' => 'fr',
    'dir' => 'ltr',
    'path' => '/fr',
    'locale' => 'fr_MA',
    'title' => 'KidsNotices AI — Assistant IA pour les écoles au Maroc',
    'description' => "IA pour écoles privées au Maroc : réponses aux parents 24h/24 en darija, arabe et français, annonces bilingues, aide aux enseignants. Données protégées.",
    'og_alt' => "KidsNotices AI — l'assistant intelligent des écoles au Maroc",
    'skip' => 'Aller au contenu',
    'nav' => ['features' => 'Fonctionnalités', 'privacy' => 'Confidentialité', 'platforms' => 'Plateformes', 'faq' => 'FAQ', 'cta' => 'Essayer KidsNotices'],
    'hero' => [
        'badge' => 'Nouveau dans KidsNotices',
        'h1' => "L'assistant intelligent de votre école, en arabe, darija et français",
        'lead' => "KidsNotices AI répond aux parents à toute heure sur les absences, les notes, les devoirs et les paiements, rédige vos annonces en deux langues en quelques secondes et libère des heures de travail répétitif pour les enseignants et la direction — sans que les noms des élèves quittent votre école.",
        'primary' => 'Essayer la plateforme gratuitement',
        'secondary' => 'Voir les fonctionnalités',
        'note' => 'Conçu pour les écoles et groupes scolaires privés au Maroc.',
    ],
    'demo' => [
        'label' => 'Exemple illustratif',
        'q' => 'Bonjour, mon fils a-t-il été absent aujourd\'hui ?',
        'a' => 'Oui, Yassine était absent ce matin à la première séance, et l\'absence n\'est pas encore justifiée. Vous pouvez la justifier depuis la page « Absences » de l\'application.',
        'q2' => 'Et quels sont ses devoirs pour demain ?',
        'a2' => 'Exercices sur les fractions en mathématiques, page 42.',
    ],
    'benefits' => [
        'title' => 'Pourquoi KidsNotices AI ?',
        'items' => [
            ['clock', 'Des réponses immédiates, jour et nuit', "Les parents obtiennent une réponse en quelques secondes, à partir des données de l'école, au lieu d'attendre au téléphone."],
            ['phone', 'Moins d\'appels répétitifs', "Les mêmes questions du quotidien (« absent ? », « devoirs ? », « payé ? ») sont traitées automatiquement ; l'administration se concentre sur l'essentiel."],
            ['globe', 'La langue des familles marocaines', 'Comprend la darija, l\'arabe et le français, et répond à chaque parent dans la langue de sa question.'],
            ['shield', 'La vie privée des élèves d\'abord', "Les noms sont remplacés par des codes avant d'atteindre l'IA ; téléphones et codes Massar sont supprimés automatiquement."],
        ],
    ],
    'features' => [
        'title' => 'Tout ce dont votre école a besoin, en un seul service',
        'intro' => 'Six outils intelligents intégrés aux espaces parents, enseignants et direction.',
        'items' => [
            ['chat', 'Assistant parents', 'Répond sur les absences, notes, devoirs, paiements, transport et cantine — uniquement à partir des données des enfants du parent, et dit clairement quand il ne sait pas.', 'Parents'],
            ['search', 'Recherche intelligente', 'Une barre de recherche à la Google : une réponse directe, puis les bons liens dans la plateforme, avec recherche vocale.', 'Tous'],
            ['pen', 'Annonces bilingues en quelques secondes', 'Notez vos idées en deux lignes et obtenez une annonce soignée en arabe et en français, avec toutes les dates et montants.', 'Direction et surveillance'],
            ['translate', 'Traduction arabe ↔ français', "Une traduction précise avec le vocabulaire de l'école marocaine : Massar, contrôle continu, devoirs surveillés…", 'Tous'],
            ['book', 'Assistant enseignants', 'Appréciations de bulletin pour chaque élève, variées et encourageantes, et propositions d\'exercices selon la leçon et le niveau.', 'Enseignants'],
            ['chart', 'Rapport intelligent pour la direction', 'Des chiffres d\'absences, d\'impayés et d\'incidents à une synthèse claire, avec alertes et recommandations concrètes.', 'Direction'],
        ],
    ],
    'how' => [
        'title' => 'Comment ça marche ?',
        'steps' => [
            ["L'utilisateur pose sa question", 'Un parent, un enseignant ou le directeur écrit ou dicte sa question, dans la plateforme qu\'il utilise chaque jour.'],
            ['La plateforme n\'envoie que les données autorisées', 'Après vérification de ses droits, avec noms masqués et téléphones, e-mails et codes Massar supprimés.'],
            ['Une réponse claire en quelques secondes', 'Les vrais noms sont rétablis dans la réponse, affichée avec le bon lien dans la plateforme.'],
        ],
    ],
    'privacy' => [
        'title' => 'Les données des élèves restent protégées',
        'intro' => 'Le service applique le principe de minimisation des données pour aider les écoles à respecter la loi 09-08.',
        'items' => [
            'Pseudonymisation : chaque nom est remplacé par un code (ex. [S12]) avant l\'envoi, et rétabli seulement dans la réponse.',
            'Suppression automatique des téléphones, e-mails, codes Massar et numéros de CIN ; la date de naissance devient un âge.',
            'Chaque utilisateur ne voit que ce qu\'il a le droit de voir : un parent ne voit que ses enfants.',
            'Le texte des questions et des réponses n\'est pas conservé — seulement le nombre de requêtes et leur coût.',
        ],
    ],
    'control' => [
        'title' => "Maîtrise totale de l'usage et des coûts",
        'items' => [
            ['gauge', 'Limites par fonctionnalité et par utilisateur', 'Une limite mensuelle par école et par outil, une limite quotidienne par utilisateur : pas de surprise sur la facture.'],
            ['layers', 'Le bon modèle pour chaque tâche', 'Un modèle rapide et économique pour les questions du quotidien, un modèle plus puissant pour les rapports.'],
            ['table', 'Un tableau de consommation clair', 'Nombre de requêtes et coût estimé par école et par fonctionnalité, mois par mois.'],
        ],
    ],
    'platforms' => [
        'title' => 'Vous éditez une plateforme scolaire ? Utilisez le service via API',
        'text' => "KidsNotices AI est un service indépendant compatible avec toute plateforme de gestion scolaire : une clé par plateforme, des écoles rattachées à la clé, des quotas mensuels et des rapports de consommation pour la facturation.",
        'items' => ['API JSON simple', 'Client PHP prêt à copier, sans Composer', 'Pseudonymisation intégrée', 'Page de recherche intelligente prête à intégrer'],
    ],
    'faq' => [
        'title' => 'Questions fréquentes',
        'items' => [
            ["L'assistant comprend-il la darija ?", "Oui. Il comprend la darija, l'arabe standard et le français, et répond dans la langue du parent (en arabe simple lorsque le message est en darija)."],
            ['Peut-il inventer des notes ou des dates ?', "L'assistant est configuré pour répondre uniquement à partir des données de l'école qui lui sont transmises. Si l'information n'y est pas, il le dit et oriente le parent vers l'administration. Chaque réponse indique qu'elle est générée par l'IA."],
            ['Les données des élèves sont-elles en sécurité ?', "Les noms sont remplacés par des codes avant d'atteindre l'IA, les téléphones, e-mails et codes Massar sont supprimés, et le texte des échanges n'est pas conservé."],
            ['Remplace-t-il le personnel administratif ?', "Non. Il prend en charge les questions répétitives et la rédaction de routine, pour que la direction et les enseignants se consacrent aux élèves et aux situations qui demandent une intervention humaine."],
            ['Une autre plateforme que KidsNotices peut-elle l\'utiliser ?', 'Oui. Le service est disponible via API pour toute plateforme scolaire, avec une clé dédiée, des écoles rattachées et des quotas mensuels.'],
        ],
    ],
    'cta' => [
        'title' => 'Des réponses immédiates pour les parents, plus de temps pour votre équipe',
        'text' => "Découvrez KidsNotices avec une école de démonstration complète, ou écrivez-nous sur WhatsApp pour activer l'assistant intelligent dans votre établissement.",
        'button' => 'Essayer KidsNotices',
    ],
    'contact' => [
        'whatsapp' => 'Écrire sur WhatsApp',
        'call' => 'Nous appeler',
        'phone_label' => 'Téléphone et WhatsApp',
        'message' => "Bonjour, je souhaite des informations sur KidsNotices AI pour mon école.",
        'float' => 'Écrivez-nous sur WhatsApp',
    ],
    'footer' => [
        'tagline' => 'Plateforme SaaS de gestion des établissements scolaires au Maroc.',
        'site' => 'Site principal',
        'disclaimer' => "Les réponses sont générées par l'IA et peuvent contenir des erreurs ; l'information officielle reste celle de l'administration de l'établissement.",
    ],
];
