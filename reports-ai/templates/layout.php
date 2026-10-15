<?php /** @var string $content @var ?array $user @var ?array $flash @var callable $e @var array $domain @var string $csrf */ ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrf) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= $e(($pageTitle ?? '') !== '' ? $pageTitle . ' — ' : '') ?>مساعد التقارير</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='4' fill='%231f5fa8'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php if ($user): ?>
<header class="top">
  <div class="wrap bar">
    <a class="brand" href="/"><span class="dot"></span>مساعد التقارير<small><?= $e($domain['organization']['ar']) ?> · <?= $e($domain['sector']['ar']) ?></small></a>
    <nav>
      <?php $here = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
      <a href="/" <?= $here === '/' ? 'aria-current="page"' : '' ?>>قاعدة التقارير</a>
      <a href="/ask" <?= $here === '/ask' ? 'aria-current="page"' : '' ?>>اسأل</a>
      <a href="/stats" <?= $here === '/stats' ? 'aria-current="page"' : '' ?>>الإحصائيات</a>
      <a href="/write" <?= str_starts_with((string) $here, '/write') ? 'aria-current="page"' : '' ?>>تحرير تقرير</a>
    </nav>
    <form method="post" action="/logout" class="user">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <span><?= $e($user['username']) ?><?= $user['role'] === 'admin' ? ' · مسؤول' : '' ?></span>
      <button class="link">خروج</button>
    </form>
  </div>
</header>
<?php endif; ?>
<main class="wrap">
  <?php if ($flash): ?><div class="flash <?= $e($flash['type']) ?>"><?= $e($flash['message']) ?></div><?php endif; ?>
  <?= $content ?>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
