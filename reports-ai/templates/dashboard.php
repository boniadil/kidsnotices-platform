<?php
$pageTitle = 'قاعدة التقارير';
use ReportsAI\Auth;
use ReportsAI\View;
$busy = array_filter($reports, fn ($r) => !in_array($r['status'], ['ready', 'error'], true));
?>
<section class="head">
  <h1>قاعدة التقارير</h1>
  <p class="muted">التقارير السنوية المضافة، والأرقام المستخرجة منها تلقائياً.</p>
</section>

<div class="tiles">
  <div class="tile"><span>التقارير الجاهزة</span><b><?= (int) $counts['reports'] ?></b></div>
  <div class="tile"><span>الفترة المغطاة</span><b><?= $counts['year_min'] ? $e($counts['year_min'] . ' – ' . $counts['year_max']) : '—' ?></b></div>
  <div class="tile"><span>المؤشرات الموحّدة</span><b><?= (int) $counts['indicators'] ?></b></div>
  <div class="tile"><span>القيم الإحصائية</span><b><?= number_format((int) $counts['facts']) ?></b></div>
</div>

<?php if (Auth::isAdmin()): ?>
<section class="card">
  <h2>إضافة تقرير سنوي</h2>
  <form method="post" action="/upload" enctype="multipart/form-data" class="form inline">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label class="grow">ملف التقرير<input type="file" name="file" accept=".pdf,.docx,.doc,.odt,.xlsx,.xls,.ods,.csv,.pptx,.ppt,.odp" required></label>
    <label>السنة<input type="number" name="year" min="1950" max="2100" required placeholder="2024"></label>
    <label class="grow">العنوان (اختياري)<input name="title" placeholder="التقرير السنوي لأنشطة الوكالة 2024"></label>
    <button class="btn primary">إضافة ومعالجة</button>
  </form>
  <p class="hint">الصيغ المقبولة: <b>PDF</b> (نصي)، <b>Word</b> (‎.docx ‎.doc)، <b>Excel</b> (‎.xlsx ‎.xls ‎.csv)، <b>PowerPoint</b> (‎.pptx ‎.ppt). تُقرأ كل صفحة أو ورقة أو شريحة، ثم يستخرج الذكاء الاصطناعي الأرقام ويربطها بالمؤشرات الموجودة من السنوات الأخرى. تستغرق المعالجة بضع دقائق لكل ملف.</p>
</section>
<?php endif; ?>

<section class="card">
  <h2>التقارير (<?= count($reports) ?>)</h2>
  <?php if (!$reports): ?>
    <p class="muted">لا توجد تقارير بعد.<?= Auth::isAdmin() ? ' أضف أول تقرير من الأعلى.' : ' اطلب من المسؤول إضافتها.' ?></p>
  <?php else: ?>
  <div class="table-wrap"><table id="reports" data-busy="<?= $busy ? '1' : '0' ?>">
    <thead><tr><th>السنة</th><th>العنوان</th><th class="hide-sm">اللغة</th><th class="hide-sm">الأجزاء</th><th>الحالة</th><?php if (Auth::isAdmin()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
      <tr data-id="<?= (int) $r['id'] ?>">
        <td class="num"><?= (int) $r['year'] ?></td>
        <td dir="auto"><span class="ftype <?= $e($r['kind']) ?>"><?= $e(['pdf' => 'PDF', 'word' => 'Word', 'excel' => 'Excel', 'powerpoint' => 'PPT'][$r['kind']] ?? $r['kind']) ?></span> <?= $e($r['title']) ?><?php if ($r['summary']): ?><details><summary>ملخص</summary><p dir="auto"><?= $e($r['summary']) ?></p></details><?php endif; ?></td>
        <td class="hide-sm"><?= $e(['ar' => 'العربية', 'fr' => 'الفرنسية', 'mixed' => 'مزدوجة'][$r['language']] ?? '—') ?></td>
        <td class="num hide-sm"><?= (int) $r['pages'] ?: '—' ?><?= $r['kind'] === 'excel' ? ' <small class="muted">أوراق</small>' : ($r['kind'] === 'powerpoint' ? ' <small class="muted">شرائح</small>' : '') ?></td>
        <td class="status">
          <span class="badge <?= $e($r['status']) ?>"><?= $e(View::statusLabel($r['status'])) ?></span>
          <?php if (!in_array($r['status'], ['ready', 'error'], true)): ?><progress max="100" value="<?= (int) $r['progress'] ?>"></progress><?php endif; ?>
          <?php if ($r['error']): ?><div class="err"><?= $e($r['error']) ?></div><?php endif; ?>
        </td>
        <?php if (Auth::isAdmin()): ?>
        <td><div class="actions">
          <?php if ($r['status'] === 'error'): ?>
          <form method="post" action="/reports/retry"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="link">إعادة</button></form>
          <?php endif; ?>
          <form method="post" action="/reports/delete" onsubmit="return confirm('حذف هذا التقرير وكل الأرقام المستخرجة منه؟')"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="link danger">حذف</button></form>
        </div></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($cost): ?>
<section class="card">
  <h2>كلفة الذكاء الاصطناعي هذا الشهر (تقديرية)</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>المهمة</th><th>الطلبات</th><th>الكلفة</th></tr></thead>
    <tbody>
    <?php foreach ($cost as $c): ?>
      <tr><td><?= $e(['extract' => 'استخراج الأرقام', 'map' => 'توحيد المؤشرات', 'ask' => 'الأسئلة', 'report' => 'تحرير التقارير', 'repair' => 'تصحيح النص العربي', 'slides' => 'عروض PowerPoint'][$c['task']] ?? $c['task']) ?></td><td class="num"><?= (int) $c['calls'] ?></td><td class="num">$<?= number_format((float) $c['cost'], 3) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
