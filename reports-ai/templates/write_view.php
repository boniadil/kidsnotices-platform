<?php $pageTitle = $job['title']; use ReportsAI\View; ?>
<section class="head row">
  <div>
    <h1 dir="auto"><?= $e($job['title']) ?></h1>
    <p class="muted"><?= $job['kind'] === 'annual' ? 'تقرير سنوي' : 'تقرير موضوعاتي' ?> · <span class="badge <?= $e($job['status']) ?>" id="job-status" data-id="<?= (int) $job['id'] ?>" data-status="<?= $e($job['status']) ?>"><?= $e(View::statusLabel($job['status'])) ?></span></p>
  </div>
  <?php if ($job['status'] === 'ready'): ?>
  <div class="actions">
    <a class="btn primary" href="/write/download?id=<?= (int) $job['id'] ?>&format=docx">تنزيل Word</a>
    <a class="btn ghost" href="/write/download?id=<?= (int) $job['id'] ?>&format=pptx" title="يُنشأ العرض عند أول تنزيل (بضع ثوانٍ)">عرض PowerPoint</a>
    <a class="btn ghost" href="/write/download?id=<?= (int) $job['id'] ?>&format=md">Markdown</a>
  </div>
  <?php endif; ?>
</section>

<?php if ($job['status'] === 'ready'): ?>
  <article class="card doc" dir="<?= $job['language'] === 'fr' ? 'ltr' : 'rtl' ?>" lang="<?= $e($job['language']) ?>"><?= $html ?></article>
  <p class="hint">مسودة مولَّدة بالذكاء الاصطناعي: كل رقم تاريخي مرفق بمصدره (السنة · الصفحة)، و«[new data]» تعني المعطيات التي أدخلتها. راجع النص قبل اعتماده.</p>
<?php elseif ($job['status'] === 'error'): ?>
  <div class="flash error"><?= $e($job['error']) ?></div>
<?php else: ?>
  <div class="card waiting"><div class="spinner"></div><p>يجري جمع المعطيات وتحرير التقرير… قد يستغرق ذلك بضع دقائق. ستُحدَّث الصفحة تلقائياً.</p></div>
<?php endif; ?>
<form method="post" action="/write/delete" onsubmit="return confirm('حذف هذه المسودة؟')" style="margin-top:12px">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
  <button class="link danger">حذف المسودة</button>
</form>
