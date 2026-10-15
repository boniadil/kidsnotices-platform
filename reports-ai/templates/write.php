<?php $pageTitle = 'تحرير تقرير'; use ReportsAI\View; ?>
<section class="head">
  <h1>تحرير تقرير جديد</h1>
  <p class="muted">مسودة بنفس هيكلة وأسلوب تقاريركم السابقة، مع مقارنات تاريخية ومصادر لكل رقم. راجعها وعدّلها قبل الاعتماد.</p>
</section>

<form method="post" action="/write" class="card form" id="write-form">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <div class="seg">
    <label><input type="radio" name="kind" value="annual" checked> تقرير سنوي جديد</label>
    <label><input type="radio" name="kind" value="thematic"> تقرير موضوعاتي (حول موضوع عبر فترة)</label>
  </div>
  <div class="grid2">
    <label>العنوان<input name="title" required maxlength="200" value="التقرير السنوي لأنشطة <?= $e($domain['organization']['ar']) ?> برسم سنة <?= $lastYear ? $lastYear + 1 : date('Y') ?>"></label>
    <label>اللغة<select name="language"><option value="ar">العربية</option><option value="fr">الفرنسية</option></select></label>
  </div>
  <div data-kind="annual">
    <label>السنة<input type="number" name="year" min="1950" max="2100" value="<?= $lastYear ? $lastYear + 1 : date('Y') ?>"></label>
    <label>معطيات السنة الجديدة
      <textarea name="new_data" rows="8" dir="auto" placeholder="الصق هنا أرقام السنة الجديدة (نص أو جدول منسوخ من Excel)، مثلاً:&#10;عدد رخص البناء المسلمة: 1530&#10;مشاريع التجزئات: 24&#10;المخالفات المرصودة: 230&#10;الاستثمارات: 2500 مليون درهم"></textarea>
    </label>
  </div>
  <div data-kind="thematic" hidden>
    <label>الموضوع<input name="topic" maxlength="500" placeholder="تطور رخص البناء والتجزئات العقارية"></label>
    <div class="grid2">
      <label>من سنة<input type="number" name="year_from" value="<?= $firstYear ?: '' ?>"></label>
      <label>إلى سنة<input type="number" name="year_to" value="<?= $lastYear ?: '' ?>"></label>
    </div>
  </div>
  <label>تعليمات إضافية (اختياري)<textarea name="instructions" rows="2" dir="auto" placeholder="مثلاً: ركّز على العالم القروي، أضف فقرة عن رقمنة المساطر…"></textarea></label>
  <button class="btn primary">ابدأ التحرير</button>
</form>

<section class="card">
  <h2>التقارير المحرَّرة</h2>
  <?php if (!$jobs): ?><p class="muted">لا شيء بعد.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>العنوان</th><th>النوع</th><th>اللغة</th><th>الحالة</th><th>بواسطة</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td dir="auto"><a href="/write/view?id=<?= (int) $j['id'] ?>"><?= $e($j['title']) ?></a></td>
        <td><?= $j['kind'] === 'annual' ? 'سنوي' : 'موضوعاتي' ?></td>
        <td><?= $j['language'] === 'fr' ? 'الفرنسية' : 'العربية' ?></td>
        <td><span class="badge <?= $e($j['status']) ?>"><?= $e(View::statusLabel($j['status'])) ?></span></td>
        <td><?= $e($j['created_by']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
