<?php $pageTitle = 'الدخول'; ?>
<div class="login">
  <h1>مساعد التقارير</h1>
  <p class="muted"><?= $e($domain['organization']['ar']) ?> — <?= $e($domain['sector']['ar']) ?></p>
  <?php if (!empty($error)): ?><div class="flash error"><?= $e($error) ?></div><?php endif; ?>
  <form method="post" action="/login" class="card form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>اسم المستخدم<input name="username" autocomplete="username" required autofocus></label>
    <label>كلمة المرور<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary">دخول</button>
  </form>
</div>
