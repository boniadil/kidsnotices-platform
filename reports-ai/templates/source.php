<?php $pageTitle = 'المصدر'; ?>
<section class="head">
  <h1 dir="auto"><?= $e($page['title']) ?></h1>
  <p class="muted">سنة <?= (int) $page['year'] ?> · <?= $e($page['label'] ?: 'ص ' . $page['page_no']) ?> (<?= (int) $page['page_no'] ?> / <?= (int) $page['pages'] ?>)</p>
  <p class="pager">
    <?php if ($page['page_no'] > 1): ?><a href="/source?report=<?= (int) $page['id'] ?>&page=<?= (int) $page['page_no'] - 1 ?>">→ السابق</a><?php endif; ?>
    <?php if ($page['page_no'] < $page['pages']): ?><a href="/source?report=<?= (int) $page['id'] ?>&page=<?= (int) $page['page_no'] + 1 ?>">التالي ←</a><?php endif; ?>
  </p>
</section>
<pre class="page-text card" dir="auto"><?= $e($page['text']) ?></pre>
