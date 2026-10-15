<?php $pageTitle = 'اسأل'; ?>
<section class="head row">
  <div>
    <h1>اسأل عن التقارير</h1>
    <p class="muted">أرقام، تطور عبر السنوات، مقارنات، أو أي معلومة من التقارير — مع المصدر (السنة والصفحة).</p>
  </div>
  <button class="btn ghost" id="reset">محادثة جديدة</button>
</section>

<div id="chat" class="chat">
  <?php foreach ($history as $t): ?>
    <?php if ($t['role'] === 'user'): ?>
      <div class="msg user" dir="auto"><?= $e($t['content']) ?></div>
    <?php else: ?>
      <div class="msg bot" data-series="<?= $e(json_encode($t['series'] ?? [], JSON_UNESCAPED_UNICODE)) ?>"><div class="answer"><?= $t['html'] ?? '' ?></div></div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$history): ?>
  <div class="examples">
    <button class="chip">كم عدد رخص البناء المسلمة كل سنة؟</button>
    <button class="chip">ما تطور مخالفات التعمير بين أول وآخر تقرير؟</button>
    <button class="chip">Quel est le volume d'investissement par année ?</button>
    <button class="chip">في أي سنة تمت المصادقة على أكبر عدد من وثائق التعمير؟</button>
  </div>
  <?php endif; ?>
</div>

<form id="ask" class="askbar">
  <textarea name="question" rows="2" maxlength="2000" placeholder="اكتب سؤالك بالعربية أو الفرنسية…" required dir="auto"></textarea>
  <button class="btn primary">إرسال</button>
</form>
<p class="hint">الأجوبة مولَّدة بالذكاء الاصطناعي من التقارير المضافة فقط؛ راجع المصادر قبل اعتماد أي رقم.</p>
