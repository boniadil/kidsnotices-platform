<?php
/**
 * Landing page template. Rendered by site/build.php once per language.
 *
 * @var array  $c        content of the current language (site/content/<lang>.php)
 * @var array  $all      contents of all languages, keyed by language code
 * @var string $base     public base URL, without trailing slash
 * @var string $mainSite URL of the main KidsNotices website
 * @var string $phone    phone / WhatsApp number, international format without spaces
 * @var string $phoneDisplay the same number formatted for display
 */

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$url = fn (array $lang) => $base . $lang['path'];
$canonical = $url($c);
$ogImage = $base . '/og-' . $c['lang'] . '.png';
$langNames = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];
$whatsapp = 'https://wa.me/' . ltrim($phone, '+') . '?text=' . rawurlencode($c['contact']['message']);
$tel = 'tel:' . $phone;

$icons = [
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
    'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    'chat' => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'pen' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13 7 4 4"/>',
    'translate' => '<path d="M4 5h8M8 3v2M6 5c0 4 2 7 6 8M10 5c-1 4-3 7-6 8"/><path d="m13 21 4-9 4 9M14.5 18h5"/>',
    'book' => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M8 7h7"/>',
    'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
    'gauge' => '<path d="M4 15a8 8 0 1 1 16 0"/><path d="m12 15 4-5"/>',
    'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
    'table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10"/>',
    'check' => '<path d="m5 12 5 5 9-10"/>',
    'call' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
];
$waIcon = '<svg class="wa-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3z"/></svg>';
$icon = fn (string $name) => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $mainSite . '#organization',
            'name' => 'KidsNotices',
            'url' => $mainSite,
            'logo' => $base . '/icon-512.png',
            'areaServed' => ['@type' => 'Country', 'name' => 'Morocco'],
            'telephone' => $phone,
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $phone,
                'contactType' => 'sales',
                'areaServed' => 'MA',
                'availableLanguage' => ['Arabic', 'French', 'English'],
            ],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $base . '/#website',
            'url' => $base . '/',
            'name' => 'KidsNotices AI',
            'inLanguage' => ['ar', 'fr', 'en'],
            'publisher' => ['@id' => $mainSite . '#organization'],
        ],
        [
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $c['title'],
            'description' => $c['description'],
            'inLanguage' => $c['lang'],
            'isPartOf' => ['@id' => $base . '/#website'],
            'primaryImageOfPage' => $ogImage,
        ],
        [
            '@type' => 'SoftwareApplication',
            'name' => 'KidsNotices AI',
            'applicationCategory' => 'EducationalApplication',
            'operatingSystem' => 'Web, Android',
            'url' => $canonical,
            'description' => $c['description'],
            'inLanguage' => ['ar', 'fr'],
            'featureList' => array_map(fn ($f) => $f[1], $c['features']['items']),
            'provider' => ['@id' => $mainSite . '#organization'],
            'audience' => ['@type' => 'EducationalAudience', 'educationalRole' => 'school'],
        ],
        [
            '@type' => 'FAQPage',
            'inLanguage' => $c['lang'],
            'mainEntity' => array_map(fn ($q) => [
                '@type' => 'Question',
                'name' => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
            ], $c['faq']['items']),
        ],
    ],
];
?>
<!doctype html>
<html lang="<?= $e($c['lang']) ?>" dir="<?= $e($c['dir']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($c['title']) ?></title>
<meta name="description" content="<?= $e($c['description']) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= $e($canonical) ?>">
<?php foreach ($all as $lang): ?>
<link rel="alternate" hreflang="<?= $e($lang['lang']) ?>" href="<?= $e($url($lang)) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= $e($url($all['ar'])) ?>">
<meta name="theme-color" content="#1a4fd6">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/icon-192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<meta property="og:type" content="website">
<meta property="og:site_name" content="KidsNotices AI">
<meta property="og:title" content="<?= $e($c['title']) ?>">
<meta property="og:description" content="<?= $e($c['description']) ?>">
<meta property="og:url" content="<?= $e($canonical) ?>">
<meta property="og:image" content="<?= $e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= $e($c['og_alt']) ?>">
<meta property="og:locale" content="<?= $e($c['locale']) ?>">
<?php foreach ($all as $lang): if ($lang['lang'] !== $c['lang']): ?>
<meta property="og:locale:alternate" content="<?= $e($lang['locale']) ?>">
<?php endif; endforeach; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($c['title']) ?>">
<meta name="twitter:description" content="<?= $e($c['description']) ?>">
<meta name="twitter:image" content="<?= $e($ogImage) ?>">

<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<style>
  :root {
    --bg: #ffffff; --bg-soft: #f4f7fd; --card: #ffffff; --text: #18202c; --muted: #566173; --border: #e1e6ef;
    --accent: #1a4fd6; --accent-text: #ffffff; --accent-soft: #e8efff;
    --c1: #1a4fd6; --c2: #f08a24; --c3: #17a36b; --c4: #8b3fd1; --c5: #e2475e;
    --radius: 16px; --shadow: 0 10px 30px rgba(20, 40, 90, .08);
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #12151b; --bg-soft: #181c24; --card: #1d222b; --text: #e8ebf1; --muted: #a2abba; --border: #2d3440;
      --accent: #8ab4ff; --accent-text: #0d1424; --accent-soft: #1f2b44; --shadow: 0 10px 30px rgba(0, 0, 0, .35); }
  }
  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 17px/1.7 "Segoe UI", Tahoma, "Noto Sans Arabic", "Noto Sans", Arial, sans-serif; }
  a { color: var(--accent); }
  .wrap { width: 100%; max-width: 1120px; margin: 0 auto; padding: 0 20px; }
  .skip { position: absolute; inset-inline-start: -999px; top: 8px; background: var(--accent); color: var(--accent-text); padding: 8px 14px; border-radius: 8px; }
  .skip:focus { inset-inline-start: 8px; z-index: 10; }
  .icon { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }

  header { position: sticky; top: 0; z-index: 5; background: color-mix(in srgb, var(--bg) 88%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); }
  .bar { display: flex; align-items: center; gap: 20px; min-height: 64px; }
  .logo { font-weight: 800; font-size: 22px; text-decoration: none; letter-spacing: -.5px; direction: ltr; white-space: nowrap; }
  .logo span:nth-child(5n+1) { color: var(--c1); } .logo span:nth-child(5n+2) { color: var(--c2); } .logo span:nth-child(5n+3) { color: var(--c3); }
  .logo span:nth-child(5n+4) { color: var(--c4); } .logo span:nth-child(5n+5) { color: var(--c5); } .logo b { color: var(--text); margin-inline-start: 4px; }
  nav.main { display: flex; gap: 18px; margin-inline-start: auto; font-size: 15px; }
  nav.main a { color: var(--text); text-decoration: none; } nav.main a:hover { color: var(--accent); }
  .langs { display: flex; gap: 6px; font-size: 14px; }
  .langs a { padding: 3px 10px; border: 1px solid var(--border); border-radius: 999px; color: var(--text); text-decoration: none; }
  .langs a[aria-current] { background: var(--accent-soft); border-color: transparent; font-weight: 600; }

  .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border-radius: 999px; font-weight: 600; text-decoration: none; border: 1px solid transparent; }
  .btn.primary { background: var(--accent); color: var(--accent-text); }
  .btn.primary:hover { filter: brightness(1.08); }
  .btn.ghost { border-color: var(--border); color: var(--text); background: var(--card); }

  .hero { padding: 72px 0 56px; background: radial-gradient(1000px 400px at 50% -10%, var(--accent-soft), transparent); }
  .hero-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 48px; align-items: center; }
  .badge { display: inline-block; background: var(--accent-soft); color: var(--accent); font-weight: 600; font-size: 14px; padding: 4px 12px; border-radius: 999px; }
  h1 { font-size: clamp(32px, 5vw, 50px); line-height: 1.2; margin: 16px 0; letter-spacing: -.5px; }
  .lead { font-size: 19px; color: var(--muted); margin: 0 0 28px; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; }
  .note { color: var(--muted); font-size: 14px; margin-top: 14px; }

  .demo { background: var(--card); border: 1px solid var(--border); border-radius: 22px; box-shadow: var(--shadow); padding: 18px; }
  .demo-label { font-size: 12px; color: var(--muted); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .demo-label::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--c3); }
  .msg { max-width: 88%; padding: 10px 14px; border-radius: 16px; margin: 8px 0; font-size: 15px; line-height: 1.6; }
  .msg.q { background: var(--accent); color: var(--accent-text); margin-inline-start: auto; border-end-end-radius: 4px; }
  .msg.a { background: var(--bg-soft); border-end-start-radius: 4px; }

  section { padding: 72px 0; }
  section.soft { background: var(--bg-soft); }
  h2 { font-size: clamp(26px, 3.4vw, 36px); line-height: 1.3; margin: 0 0 12px; }
  .intro { color: var(--muted); margin: 0 0 32px; max-width: 720px; }
  .grid { display: grid; gap: 18px; }
  .g4 { grid-template-columns: repeat(4, 1fr); } .g3 { grid-template-columns: repeat(3, 1fr); }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; }
  .card h3 { font-size: 18px; margin: 12px 0 6px; line-height: 1.4; }
  .card p { margin: 0; color: var(--muted); font-size: 15.5px; }
  .card .ico { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent); }
  .who { display: inline-block; margin-top: 12px; font-size: 13px; color: var(--accent); font-weight: 600; }

  .steps { counter-reset: step; }
  .step { position: relative; padding-top: 52px; }
  .step::before { counter-increment: step; content: counter(step); position: absolute; top: 18px; inset-inline-start: 22px; width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: var(--accent-text); display: grid; place-items: center; font-weight: 700; font-size: 15px; }

  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start; }
  ul.checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
  ul.checks li { display: flex; gap: 12px; align-items: flex-start; }
  ul.checks .icon { color: var(--c3); margin-top: 3px; }

  details { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 4px 18px; margin-bottom: 10px; }
  summary { cursor: pointer; font-weight: 600; padding: 12px 0; }
  details p { margin: 0 0 14px; color: var(--muted); }

  .final { text-align: center; background: linear-gradient(135deg, var(--c1), var(--c4)); color: #fff; border-radius: 28px; padding: 56px 24px; }
  .final h2 { color: #fff; } .final p { opacity: .9; margin: 0 auto 26px; max-width: 620px; }
  .final .btn { background: #fff; color: #1a2b5c; }

  .btn.wa { background: #1f9d55; color: #fff; }
  .btn.wa:hover { filter: brightness(1.08); }
  .wa-ico { width: 20px; height: 20px; fill: currentColor; flex: none; }
  .num { direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
  .float-wa { position: fixed; bottom: 18px; inset-inline-end: 18px; z-index: 6; width: 56px; height: 56px; border-radius: 50%; background: #1f9d55; color: #fff; display: grid; place-items: center; box-shadow: 0 6px 20px rgba(0,0,0,.25); }
  .float-wa .wa-ico { width: 30px; height: 30px; }
  .final .actions { justify-content: center; }
  .final .btn.ghost-light { background: transparent; color: #fff; border-color: rgba(255,255,255,.6); }
  footer { border-top: 1px solid var(--border); padding: 32px 0 40px; color: var(--muted); font-size: 14px; }
  .foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; }
  .foot p { margin: 4px 0; }

  @media (max-width: 920px) {
    .hero-grid, .two { grid-template-columns: 1fr; }
    .g4 { grid-template-columns: repeat(2, 1fr); } .g3 { grid-template-columns: 1fr; }
    nav.main { display: none; }
    .langs { margin-inline-start: auto; }
  }
  @media (max-width: 560px) {
    body { font-size: 16px; }
    .wrap { padding: 0 16px; }
    .g4 { grid-template-columns: 1fr; }
    .hero { padding: 44px 0 40px; } section { padding: 52px 0; }
    .logo b { display: none; }
    .btn { width: 100%; justify-content: center; }
  }
</style>
</head>
<body>
<a class="skip" href="#content"><?= $e($c['skip']) ?></a>
<header>
  <div class="wrap bar">
    <a class="logo" href="<?= $e($c['path']) ?>" aria-label="KidsNotices AI"><span>K</span><span>i</span><span>d</span><span>s</span><span>N</span><span>o</span><span>t</span><span>i</span><span>c</span><span>e</span><span>s</span><b>AI</b></a>
    <nav class="main" aria-label="<?= $e($c['nav']['features']) ?>">
      <a href="#features"><?= $e($c['nav']['features']) ?></a>
      <a href="#privacy"><?= $e($c['nav']['privacy']) ?></a>
      <a href="#platforms"><?= $e($c['nav']['platforms']) ?></a>
      <a href="#faq"><?= $e($c['nav']['faq']) ?></a>
    </nav>
    <div class="langs">
      <?php foreach ($all as $lang): ?>
      <a href="<?= $e($lang['path']) ?>" hreflang="<?= $e($lang['lang']) ?>" lang="<?= $e($lang['lang']) ?>"<?= $lang['lang'] === $c['lang'] ? ' aria-current="page"' : '' ?>><?= $e($langNames[$lang['lang']]) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<main id="content">
  <div class="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="badge"><?= $e($c['hero']['badge']) ?></span>
        <h1><?= $e($c['hero']['h1']) ?></h1>
        <p class="lead"><?= $e($c['hero']['lead']) ?></p>
        <div class="actions">
          <a class="btn primary" href="<?= $e($mainSite) ?>"><?= $e($c['hero']['primary']) ?></a>
          <a class="btn wa" href="<?= $e($whatsapp) ?>" rel="noopener" target="_blank"><?= $waIcon ?><?= $e($c['contact']['whatsapp']) ?></a>
          <a class="btn ghost" href="#features"><?= $e($c['hero']['secondary']) ?></a>
        </div>
        <p class="note"><?= $e($c['hero']['note']) ?></p>
      </div>
      <figure class="demo" aria-label="<?= $e($c['demo']['label']) ?>" style="margin:0">
        <figcaption class="demo-label"><?= $e($c['demo']['label']) ?></figcaption>
        <p class="msg q"><?= $e($c['demo']['q']) ?></p>
        <p class="msg a"><?= $e($c['demo']['a']) ?></p>
        <p class="msg q"><?= $e($c['demo']['q2']) ?></p>
        <p class="msg a"><?= $e($c['demo']['a2']) ?></p>
      </figure>
    </div>
  </div>

  <section aria-labelledby="benefits-title">
    <div class="wrap">
      <h2 id="benefits-title"><?= $e($c['benefits']['title']) ?></h2>
      <div class="grid g4" style="margin-top:28px">
        <?php foreach ($c['benefits']['items'] as [$ic, $title, $text]): ?>
        <article class="card"><div class="ico"><?= $icon($ic) ?></div><h3><?= $e($title) ?></h3><p><?= $e($text) ?></p></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="features" class="soft" aria-labelledby="features-title">
    <div class="wrap">
      <h2 id="features-title"><?= $e($c['features']['title']) ?></h2>
      <p class="intro"><?= $e($c['features']['intro']) ?></p>
      <div class="grid g3">
        <?php foreach ($c['features']['items'] as [$ic, $title, $text, $who]): ?>
        <article class="card"><div class="ico"><?= $icon($ic) ?></div><h3><?= $e($title) ?></h3><p><?= $e($text) ?></p><span class="who"><?= $e($who) ?></span></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section aria-labelledby="how-title">
    <div class="wrap">
      <h2 id="how-title"><?= $e($c['how']['title']) ?></h2>
      <ol class="grid g3 steps" style="list-style:none;padding:0;margin:28px 0 0">
        <?php foreach ($c['how']['steps'] as [$title, $text]): ?>
        <li class="card step"><h3><?= $e($title) ?></h3><p><?= $e($text) ?></p></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section id="privacy" class="soft" aria-labelledby="privacy-title">
    <div class="wrap two">
      <div>
        <h2 id="privacy-title"><?= $e($c['privacy']['title']) ?></h2>
        <p class="intro"><?= $e($c['privacy']['intro']) ?></p>
      </div>
      <ul class="checks">
        <?php foreach ($c['privacy']['items'] as $item): ?>
        <li><?= $icon('check') ?><span><?= $e($item) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section aria-labelledby="control-title">
    <div class="wrap">
      <h2 id="control-title"><?= $e($c['control']['title']) ?></h2>
      <div class="grid g3" style="margin-top:28px">
        <?php foreach ($c['control']['items'] as [$ic, $title, $text]): ?>
        <article class="card"><div class="ico"><?= $icon($ic) ?></div><h3><?= $e($title) ?></h3><p><?= $e($text) ?></p></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="platforms" class="soft" aria-labelledby="platforms-title">
    <div class="wrap two">
      <div>
        <h2 id="platforms-title"><?= $e($c['platforms']['title']) ?></h2>
        <p class="intro"><?= $e($c['platforms']['text']) ?></p>
      </div>
      <ul class="checks">
        <?php foreach ($c['platforms']['items'] as $item): ?>
        <li><?= $icon('check') ?><span><?= $e($item) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section id="faq" aria-labelledby="faq-title">
    <div class="wrap" style="max-width:860px">
      <h2 id="faq-title"><?= $e($c['faq']['title']) ?></h2>
      <div style="margin-top:24px">
        <?php foreach ($c['faq']['items'] as [$q, $a]): ?>
        <details><summary><?= $e($q) ?></summary><p><?= $e($a) ?></p></details>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section aria-labelledby="cta-title" style="padding-top:0">
    <div class="wrap">
      <div class="final">
        <h2 id="cta-title"><?= $e($c['cta']['title']) ?></h2>
        <p><?= $e($c['cta']['text']) ?></p>
        <div class="actions">
          <a class="btn" href="<?= $e($mainSite) ?>"><?= $e($c['cta']['button']) ?></a>
          <a class="btn wa" href="<?= $e($whatsapp) ?>" rel="noopener" target="_blank"><?= $waIcon ?><?= $e($c['contact']['whatsapp']) ?></a>
          <a class="btn ghost-light" href="<?= $e($tel) ?>"><?= $icon('call') ?><span class="num"><?= $e($phoneDisplay) ?></span></a>
        </div>
      </div>
    </div>
  </section>
</main>

<footer>
  <div class="wrap foot">
    <div>
      <p><strong>KidsNotices</strong> — <?= $e($c['footer']['tagline']) ?></p>
      <p><?= $e($c['footer']['disclaimer']) ?></p>
    </div>
    <div>
      <p><a href="<?= $e($mainSite) ?>"><?= $e($c['footer']['site']) ?>: kidsnotices.ma</a></p>
      <p><?= $e($c['contact']['phone_label']) ?>: <a class="num" href="<?= $e($tel) ?>"><?= $e($phoneDisplay) ?></a> · <a href="<?= $e($whatsapp) ?>" rel="noopener" target="_blank">WhatsApp</a></p>
      <p class="langs" style="margin-top:8px">
        <?php foreach ($all as $lang): ?>
        <a href="<?= $e($lang['path']) ?>" hreflang="<?= $e($lang['lang']) ?>" lang="<?= $e($lang['lang']) ?>"><?= $e($langNames[$lang['lang']]) ?></a>
        <?php endforeach; ?>
      </p>
    </div>
  </div>
</footer>
<a class="float-wa" href="<?= $e($whatsapp) ?>" rel="noopener" target="_blank" aria-label="<?= $e($c['contact']['float']) ?>" title="<?= $e($c['contact']['float']) ?>"><?= $waIcon ?></a>
</body>
</html>
