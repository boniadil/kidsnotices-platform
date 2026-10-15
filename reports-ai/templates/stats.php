<?php $pageTitle = 'الإحصائيات'; ?>
<section class="head">
  <h1>الإحصائيات عبر السنوات</h1>
  <p class="muted">كل مؤشر موحَّد بين السنوات واللغتين، مع مصدر كل قيمة. صدّر أي سلسلة إلى Excel (CSV).</p>
</section>

<div class="stats-layout">
  <aside class="card">
    <input id="ind-search" type="search" placeholder="ابحث عن مؤشر: رخص، مخالفات، investissement…" dir="auto">
    <div class="cats">
      <button class="chip active" data-cat="">الكل</button>
      <?php foreach ($categories as $key => $c): if (!empty($byCategory[$key])): ?>
      <button class="chip" data-cat="<?= $e($key) ?>"><?= $e($c['ar']) ?> <small><?= (int) $byCategory[$key] ?></small></button>
      <?php endif; endforeach; ?>
    </div>
    <ul id="ind-list" class="ind-list"></ul>
  </aside>
  <section class="card" id="series-panel">
    <p class="muted">اختر مؤشراً من القائمة.</p>
  </section>
</div>
