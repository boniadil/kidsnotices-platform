<?php

/*
 * Smart search page (Google-style) for the KidsNotices platform.
 * Plain PHP + HTML + CSS + vanilla JS, no external dependency.
 * The browser only talks to search_api.php on your own server.
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/search_config.php';

$user = kn_search_user();
if ($user === null) {
    header('Location: /login.php'); // TODO: your login page
    exit;
}

if (empty($_SESSION['kn_search_csrf'])) {
    $_SESSION['kn_search_csrf'] = bin2hex(random_bytes(32));
}

$examples = [
    'parent' => ['ar' => ['هل غاب ابني هذا الأسبوع؟', 'ما هي واجبات الغد؟', 'كم بقي عليّ في الأداءات؟', 'ماذا في المقصف اليوم؟'],
                 'fr' => ['Mon enfant a-t-il été absent ?', 'Devoirs pour demain ?', 'Où en sont mes paiements ?', 'Menu de la cantine']],
    'teacher' => ['ar' => ['كيف أسجل الغياب؟', 'تصدير النقط إلى مسار', 'أفكار لتدريس الكسور', 'أين أنشر الواجبات؟'],
                  'fr' => ["Faire l'appel", 'Exporter les notes vers Massar', 'Idées pour enseigner les fractions', 'Publier un devoir']],
    'admin' => ['ar' => ['لائحة الأسر المتأخرة في الأداء', 'إحصائيات الغياب هذا الشهر', 'إضافة مساعد إداري', 'استرجاع نسخة احتياطية'],
                'fr' => ['Familles en retard de paiement', 'Statistiques des absences', 'Ajouter un assistant', 'Restaurer une sauvegarde']],
];
$roleExamples = $examples[$user['role']] ?? $examples['parent'];

$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($_SESSION['kn_search_csrf']) ?>">
<title>بحث KidsNotices</title>
<style>
  :root {
    --bg: #ffffff;
    --surface: #ffffff;
    --surface-2: #f3f6fb;
    --text: #1f2937;
    --muted: #5f6b7a;
    --border: #dfe3ea;
    --link: #1a4fd6;
    --link-visited: #6b3fb8;
    --url: #1d7a46;
    --shadow: 0 1px 6px rgba(32, 33, 36, .18);
    --accent: #1a4fd6;
    --answer-bg: #f2f6ff;
    --answer-border: #d5e1ff;
    --c1: #1a4fd6; --c2: #f08a24; --c3: #17a36b; --c4: #8b3fd1; --c5: #e2475e;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --bg: #17191d; --surface: #202227; --surface-2: #2a2d33; --text: #e8eaed; --muted: #a3a9b3;
      --border: #3a3e46; --link: #8ab4ff; --link-visited: #c5a3ff; --url: #6fd39b;
      --shadow: 0 1px 6px rgba(0, 0, 0, .5); --accent: #8ab4ff; --answer-bg: #1d2533; --answer-border: #2f3d57;
    }
  }
  :root[data-theme="dark"] {
    --bg: #17191d; --surface: #202227; --surface-2: #2a2d33; --text: #e8eaed; --muted: #a3a9b3;
    --border: #3a3e46; --link: #8ab4ff; --link-visited: #c5a3ff; --url: #6fd39b;
    --shadow: 0 1px 6px rgba(0, 0, 0, .5); --accent: #8ab4ff; --answer-bg: #1d2533; --answer-border: #2f3d57;
  }

  * { box-sizing: border-box; }
  [hidden] { display: none !important; }
  input[type=search]::-webkit-search-cancel-button { display: none; }
  html, body { margin: 0; }
  body {
    min-height: 100vh; display: flex; flex-direction: column;
    background: var(--bg); color: var(--text);
    font-family: "Segoe UI", Tahoma, "Noto Sans Arabic", "Noto Sans", Arial, sans-serif;
    font-size: 16px; line-height: 1.6;
  }
  button { font: inherit; color: inherit; }

  /* Top bar */
  .topbar { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding: 12px 20px; font-size: 14px; }
  .topbar a { color: var(--text); text-decoration: none; }
  .topbar a:hover { text-decoration: underline; }
  .lang-btn { border: 1px solid var(--border); background: var(--surface); border-radius: 999px; padding: 4px 14px; cursor: pointer; }
  .avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--c3); color: #fff; display: grid; place-items: center; font-weight: 600; }

  /* Search area */
  main { flex: 1; width: 100%; }
  .search-area { display: flex; flex-direction: column; align-items: center; padding: 12vh 16px 0; transition: padding .2s; }
  .logo { font-size: clamp(44px, 9vw, 80px); font-weight: 700; letter-spacing: -2px; margin: 0 0 6px; direction: ltr; user-select: none; cursor: pointer; line-height: 1.1; }
  .logo span:nth-child(5n+1) { color: var(--c1); } .logo span:nth-child(5n+2) { color: var(--c2); }
  .logo span:nth-child(5n+3) { color: var(--c3); } .logo span:nth-child(5n+4) { color: var(--c4); }
  .logo span:nth-child(5n+5) { color: var(--c5); }
  .tagline { color: var(--muted); margin: 0 0 26px; font-size: 15px; text-align: center; }

  .search-form { width: 100%; max-width: 620px; }
  .search-box {
    display: flex; align-items: center; gap: 6px; height: 50px; padding: 0 8px 0 8px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 26px;
  }
  .search-box:hover, .search-box:focus-within { box-shadow: var(--shadow); border-color: transparent; }
  .search-box input {
    flex: 1; min-width: 0; height: 100%; border: 0; outline: 0; background: transparent; color: var(--text); font: inherit; font-size: 16px; padding: 0 6px;
  }
  .icon-btn { width: 40px; height: 40px; border: 0; background: transparent; border-radius: 50%; display: grid; place-items: center; cursor: pointer; color: var(--muted); flex: none; }
  .icon-btn:hover { background: var(--surface-2); }
  .icon-btn svg { width: 22px; height: 22px; }
  .icon-btn.listening { color: var(--c5); animation: pulse 1s infinite; }
  @keyframes pulse { 50% { opacity: .4; } }
  .search-icon { color: var(--muted); display: grid; place-items: center; width: 34px; flex: none; }
  .search-icon svg { width: 20px; height: 20px; }

  .buttons { display: flex; justify-content: center; gap: 12px; margin-top: 26px; }
  .btn { background: var(--surface-2); border: 1px solid transparent; border-radius: 6px; padding: 9px 18px; cursor: pointer; font-size: 14px; }
  .btn:hover { border-color: var(--border); box-shadow: 0 1px 1px rgba(0,0,0,.1); }

  .examples { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 22px; max-width: 620px; }
  .chip { border: 1px solid var(--border); background: var(--surface); border-radius: 999px; padding: 6px 14px; font-size: 14px; cursor: pointer; }
  .chip:hover { background: var(--surface-2); }

  /* Results layout */
  body.has-results .search-area { flex-direction: row; align-items: center; gap: 24px; padding: 4px 20px 14px; border-bottom: 1px solid var(--border); }
  body.has-results .logo { font-size: 28px; letter-spacing: -1px; margin: 0; width: 160px; flex: none; }
  body.has-results .tagline, body.has-results .buttons, body.has-results .examples { display: none; }
  body.has-results .search-form { max-width: 690px; }

  .results { max-width: 690px; padding: 18px 20px 40px; margin-inline-start: 184px; }
  .answer {
    background: var(--answer-bg); border: 1px solid var(--answer-border); border-radius: 16px; padding: 16px 18px; margin-bottom: 26px;
  }
  .answer-label { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; color: var(--accent); margin-bottom: 8px; }
  .answer-label svg { width: 18px; height: 18px; }
  .answer-body p { margin: 0 0 8px; }
  .answer-body ul { margin: 0 0 8px; padding-inline-start: 22px; }
  .answer-note { color: var(--muted); font-size: 12px; margin-top: 6px; }

  .result { margin-bottom: 26px; }
  .result-site { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
  .favicon { width: 28px; height: 28px; border-radius: 50%; background: var(--surface-2); border: 1px solid var(--border); display: grid; place-items: center; color: var(--accent); flex: none; }
  .favicon svg { width: 15px; height: 15px; }
  .site-name { font-size: 14px; line-height: 1.3; }
  .site-url { font-size: 12px; color: var(--muted); direction: ltr; text-align: start; unicode-bidi: plaintext; }
  .result a.title { font-size: 20px; color: var(--link); text-decoration: none; line-height: 1.3; }
  .result a.title:hover { text-decoration: underline; }
  .result a.title:visited { color: var(--link-visited); }
  .snippet { color: var(--muted); font-size: 14px; margin: 4px 0 0; }

  .related h2 { font-size: 18px; font-weight: 500; margin: 8px 0 12px; }
  .related-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
  .related-item { display: flex; align-items: center; gap: 10px; background: var(--surface-2); border: 0; border-radius: 22px; padding: 10px 16px; cursor: pointer; text-align: start; font-size: 14px; }
  .related-item:hover { filter: brightness(.96); }
  .related-item svg { width: 16px; height: 16px; flex: none; color: var(--muted); }

  .empty, .error { color: var(--muted); padding: 10px 0; }
  .error { color: var(--c5); }

  .skeleton { background: var(--surface-2); border-radius: 8px; height: 14px; margin: 10px 0; animation: shimmer 1.2s infinite ease-in-out; }
  @keyframes shimmer { 50% { opacity: .45; } }

  footer { background: var(--surface-2); color: var(--muted); font-size: 13px; padding: 12px 20px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }

  @media (max-width: 820px) {
    body.has-results .search-area { flex-direction: column; align-items: stretch; gap: 10px; padding: 4px 16px 12px; }
    body.has-results .logo { text-align: center; width: auto; }
    .results { margin-inline-start: 0; padding: 16px; }
    .search-area { padding-top: 8vh; }
  }
</style>
</head>
<body>
  <header class="topbar">
    <button type="button" class="lang-btn" id="langBtn">Français</button>
    <a href="/" id="homeLink">الرئيسية</a>
    <div class="avatar" title="<?= $e((string) $user['name']) ?>"><?= $e(mb_substr((string) ($user['name'] ?: '؟'), 0, 1)) ?></div>
  </header>

  <main>
    <section class="search-area">
      <h1 class="logo" id="logo" aria-label="KidsNotices"><span>K</span><span>i</span><span>d</span><span>s</span><span>N</span><span>o</span><span>t</span><span>i</span><span>c</span><span>e</span><span>s</span></h1>
      <p class="tagline" id="tagline"></p>

      <form class="search-form" id="searchForm" role="search">
        <div class="search-box">
          <span class="search-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          </span>
          <input type="search" id="q" name="q" dir="auto" autocomplete="off" maxlength="500" autofocus>
          <button type="button" class="icon-btn" id="clearBtn" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
          <button type="button" class="icon-btn" id="micBtn" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
          </button>
        </div>
        <div class="buttons">
          <button type="submit" class="btn" id="searchBtn"></button>
          <button type="button" class="btn" id="luckyBtn"></button>
        </div>
      </form>

      <div class="examples" id="examples"></div>
    </section>

    <section class="results" id="results" hidden aria-live="polite"></section>
  </main>

  <footer>
    <span><?= $e($user['school_name']) ?></span>
    <span id="footerNote"></span>
  </footer>

<script>
(() => {
  const EXAMPLES = <?= json_encode($roleExamples, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const API = 'search_api.php';

  const T = {
    ar: {
      dir: 'rtl', other: 'Français', title: 'بحث KidsNotices', home: 'الرئيسية',
      placeholder: 'اسأل عن الغياب، النقط، الواجبات، الأداءات…',
      tagline: 'ابحث في منصتك المدرسية واسأل بلغتك',
      search: 'بحث KidsNotices', lucky: 'ما الجديد اليوم؟', luckyQuery: 'ما الجديد اليوم بالنسبة لي؟',
      answer: 'الجواب الذكي', note: 'جواب مولَّد بالذكاء الاصطناعي من معطيات المؤسسة، قد يحتوي على أخطاء.',
      related: 'عمليات بحث ذات صلة', noResults: 'لا توجد صفحات مطابقة.',
      clear: 'مسح', mic: 'البحث الصوتي', footer: 'البحث مدعوم بالذكاء الاصطناعي',
      errors: {
        daily_limit_reached: 'تم بلوغ الحد اليومي للبحث الذكي في مؤسستك. حاول غداً.',
        too_fast: 'مهلاً، انتظر ثانيتين بين كل بحث.', not_logged_in: 'انتهت الجلسة، المرجو تسجيل الدخول من جديد.',
        ai_busy: 'الخدمة مشغولة حالياً، حاول بعد لحظات.', ai_refused: 'لا يمكن الإجابة على هذا البحث، جرّب صياغة أخرى.',
        default: 'تعذّر البحث حالياً. حاول من جديد.'
      }
    },
    fr: {
      dir: 'ltr', other: 'العربية', title: 'Recherche KidsNotices', home: 'Accueil',
      placeholder: 'Absences, notes, devoirs, paiements…',
      tagline: 'Cherchez dans votre plateforme scolaire, posez votre question',
      search: 'Recherche KidsNotices', lucky: "Quoi de neuf aujourd'hui ?", luckyQuery: "Quoi de neuf pour moi aujourd'hui ?",
      answer: 'Réponse intelligente', note: "Réponse générée par l'IA à partir des données de l'établissement ; elle peut contenir des erreurs.",
      related: 'Recherches associées', noResults: 'Aucune page correspondante.',
      clear: 'Effacer', mic: 'Recherche vocale', footer: "Recherche propulsée par l'IA",
      errors: {
        daily_limit_reached: 'La limite quotidienne de recherche de votre établissement est atteinte. Réessayez demain.',
        too_fast: 'Patientez deux secondes entre deux recherches.', not_logged_in: 'Session expirée, reconnectez-vous.',
        ai_busy: 'Service occupé, réessayez dans un instant.', ai_refused: 'Impossible de répondre à cette recherche, reformulez-la.',
        default: 'La recherche est indisponible pour le moment. Réessayez.'
      }
    }
  };

  const $ = (id) => document.getElementById(id);
  const input = $('q'), results = $('results');
  let lang = 'ar', controller = null;
  try { lang = localStorage.getItem('kn-search-lang') === 'fr' ? 'fr' : 'ar'; } catch (_) {}

  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') node.className = v; else if (k.startsWith('on')) node.addEventListener(k.slice(2), v); else node.setAttribute(k, v);
    }
    for (const c of children) node.append(c);
    return node;
  }
  const svg = (markup) => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.innerHTML = markup; return s; };
  const ICON_SEARCH = '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>';
  const ICON_PAGE = '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>';
  const ICON_SPARK = '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>';

  function applyLang() {
    const t = T[lang];
    document.documentElement.lang = lang; document.documentElement.dir = t.dir; document.title = t.title;
    $('langBtn').textContent = t.other; $('homeLink').textContent = t.home;
    input.placeholder = t.placeholder; input.setAttribute('aria-label', t.placeholder);
    $('tagline').textContent = t.tagline; $('searchBtn').textContent = t.search; $('luckyBtn').textContent = t.lucky;
    $('clearBtn').setAttribute('aria-label', t.clear); $('micBtn').setAttribute('aria-label', t.mic);
    $('footerNote').textContent = t.footer;
    const ex = $('examples'); ex.replaceChildren(...EXAMPLES[lang].map((q) => el('button', { type: 'button', class: 'chip', onclick: () => run(q) }, q)));
  }

  // Answer text → paragraphs and "- " bullet lists, built with textContent (no HTML injection).
  function renderAnswer(text) {
    const box = el('div', { class: 'answer-body' });
    let list = null;
    for (const raw of text.split('\n')) {
      const line = raw.trim();
      if (!line) { list = null; continue; }
      const m = line.match(/^[-•*]\s+(.*)$/);
      if (m) { if (!list) { list = el('ul'); box.append(list); } list.append(el('li', { dir: 'auto' }, m[1])); }
      else { list = null; box.append(el('p', { dir: 'auto' }, line)); }
    }
    return box;
  }

  function showLoading() {
    results.hidden = false;
    results.replaceChildren(el('div', { class: 'answer' },
      el('div', { class: 'answer-label' }, svg(ICON_SPARK), T[lang].answer),
      el('div', { class: 'skeleton', style: 'width:92%' }), el('div', { class: 'skeleton', style: 'width:78%' }), el('div', { class: 'skeleton', style: 'width:60%' })),
      el('div', { class: 'skeleton', style: 'width:40%;height:18px' }), el('div', { class: 'skeleton', style: 'width:85%' }));
  }

  function showResults(data) {
    const t = T[lang];
    const nodes = [];
    if (data.answer) {
      nodes.push(el('div', { class: 'answer' },
        el('div', { class: 'answer-label' }, svg(ICON_SPARK), t.answer),
        renderAnswer(data.answer),
        el('div', { class: 'answer-note' }, t.note)));
    }
    if (data.results && data.results.length) {
      for (const r of data.results) {
        const url = new URL(r.url, location.origin);
        if (url.origin !== location.origin) continue; // only links inside the platform
        const path = url.pathname.split('/').filter(Boolean).join(' › ');
        nodes.push(el('div', { class: 'result' },
          el('div', { class: 'result-site' },
            el('div', { class: 'favicon' }, svg(ICON_PAGE)),
            el('div', {}, el('div', { class: 'site-name' }, 'KidsNotices'), el('div', { class: 'site-url' }, location.host + (path ? ' › ' + path : '')))),
          el('a', { class: 'title', href: url.href, dir: 'auto' }, r.title),
          el('p', { class: 'snippet', dir: 'auto' }, r.snippet || '')));
      }
    } else if (!data.answer) {
      nodes.push(el('p', { class: 'empty' }, t.noResults));
    }
    if (data.related && data.related.length) {
      nodes.push(el('div', { class: 'related' }, el('h2', {}, t.related),
        el('div', { class: 'related-list' }, ...data.related.map((q) => el('button', { type: 'button', class: 'related-item', onclick: () => run(q) }, svg(ICON_SEARCH), el('span', { dir: 'auto' }, q))))));
    }
    results.replaceChildren(...nodes);
  }

  function showError(code) {
    const errs = T[lang].errors;
    results.replaceChildren(el('p', { class: 'error' }, errs[code] || errs.default));
  }

  async function run(query, push = true) {
    query = (query || '').trim();
    if (!query) return;
    input.value = query; $('clearBtn').hidden = false;
    document.body.classList.add('has-results');
    if (push) history.pushState({ q: query }, '', '?q=' + encodeURIComponent(query));
    document.title = query + ' - ' + T[lang].title;
    if (controller) controller.abort();
    controller = new AbortController();
    showLoading();
    try {
      const res = await fetch(API, {
        method: 'POST', signal: controller.signal, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ q: query })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) return showError(data.error);
      showResults(data);
      window.scrollTo(0, 0);
    } catch (err) {
      if (err.name !== 'AbortError') showError();
    }
  }

  function goHome() {
    if (controller) controller.abort();
    document.body.classList.remove('has-results');
    results.hidden = true; results.replaceChildren();
    input.value = ''; $('clearBtn').hidden = true;
    history.pushState({}, '', location.pathname);
    document.title = T[lang].title; input.focus();
  }

  $('searchForm').addEventListener('submit', (ev) => { ev.preventDefault(); run(input.value); });
  $('luckyBtn').addEventListener('click', () => run(T[lang].luckyQuery));
  $('logo').addEventListener('click', goHome);
  $('clearBtn').addEventListener('click', () => { input.value = ''; $('clearBtn').hidden = true; input.focus(); });
  input.addEventListener('input', () => { $('clearBtn').hidden = input.value === ''; });
  $('langBtn').addEventListener('click', () => {
    lang = lang === 'ar' ? 'fr' : 'ar';
    try { localStorage.setItem('kn-search-lang', lang); } catch (_) {}
    applyLang();
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === '/' && document.activeElement !== input) { ev.preventDefault(); input.focus(); input.select(); }
  });
  window.addEventListener('popstate', () => {
    const q = new URLSearchParams(location.search).get('q');
    if (q) run(q, false); else goHome();
  });

  // Voice search (Chrome / Android / Edge).
  const Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (Speech) {
    const mic = $('micBtn'); mic.hidden = false;
    mic.addEventListener('click', () => {
      const rec = new Speech();
      rec.lang = lang === 'fr' ? 'fr-FR' : 'ar-MA';
      rec.interimResults = false;
      rec.onstart = () => mic.classList.add('listening');
      rec.onend = () => mic.classList.remove('listening');
      rec.onresult = (ev) => run(ev.results[0][0].transcript);
      rec.start();
    });
  }

  applyLang();
  const initial = new URLSearchParams(location.search).get('q');
  if (initial) run(initial, false);
})();
</script>
</body>
</html>
