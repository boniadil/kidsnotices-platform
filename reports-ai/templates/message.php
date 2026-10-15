<?php $pageTitle = $title; ?>
<div class="card" style="margin-top:32px">
  <h1><?= $e($title) ?></h1>
  <?php if ($text !== ''): ?><p><?= $e($text) ?></p><?php endif; ?>
  <p><a href="/">العودة إلى الصفحة الرئيسية</a></p>
</div>
