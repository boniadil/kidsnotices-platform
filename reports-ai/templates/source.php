<?php $pageTitle = 'المصدر'; ?>
<section class="head">
  <h1 dir="auto"><?= $e($page['title']) ?></h1>
  <p class="muted">سنة <?= (int) $page['year'] ?> · الصفحة <?= (int) $page['page_no'] ?> من <?= (int) $page['pages'] ?></p>
  <p class="pager">
    <?php if ($page['page_no'] > 1): ?><a href="/source?report=<?= (int) $page['id'] ?>&page=<?= (int) $page['page_no'] - 1 ?>">→ الصفحة السابقة</a><?php endif; ?>
    <?php if ($page['page_no'] < $page['pages']): ?><a href="/source?report=<?= (int) $page['id'] ?>&page=<?= (int) $page['page_no'] + 1 ?>">الصفحة التالية ←</a><?php endif; ?>
  </p>
</section>
<pre class="page-text card" dir="auto"><?= $e($page['text']) ?></pre>
