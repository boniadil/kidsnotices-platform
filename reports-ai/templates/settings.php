<?php
/** @var array $values @var array $saved @var array $hosting */
use ReportsAI\Config;
use ReportsAI\Settings;

$pageTitle = 'الإعدادات';
$tasks = [
    'EXTRACT' => ['استخراج الأرقام والملخص', 'مرة واحدة لكل تقرير'],
    'MAP' => ['توحيد أسماء المؤشرات', 'مرة واحدة لكل تقرير'],
    'REPAIR' => ['تصحيح «لا» في نص PDF', 'عند الحاجة فقط'],
    'ASK' => ['الإجابة عن الأسئلة', 'يستعمل أدوات البحث'],
    'REPORT' => ['تحرير التقارير الجديدة', 'أطول نص يُكتب'],
    'SLIDES' => ['عروض PowerPoint', 'مرة لكل تقرير محرَّر'],
];
$source = function (string $key) use ($saved, $values): string {
    if (isset($saved[$key])) {
        return 'محفوظ هنا · ' . substr($saved[$key]['updated_at'], 0, 16);
    }
    return $values[$key]['env'] !== null ? 'من ملف الإعدادات' : 'غير مضبوط';
};
$val = fn (string $key) => $values[$key]['value'] ?? '';
$yes = fn (bool $b) => $b ? '<span class="badge ready">نعم</span>' : '<span class="badge error">لا</span>';
?>
<section class="head">
  <h1>الإعدادات</h1>
  <p class="muted">ما يُحفظ هنا يتقدّم على ملف <code dir="ltr">.env</code>. اترك الحقل فارغاً للرجوع إلى قيمة <code dir="ltr">.env</code>.</p>
</section>

<form method="post" action="/settings" class="settings">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

  <section class="card">
    <h2>من يجيب عن كل مهمة</h2>
    <p class="hint">Claude أدق بكثير، خصوصاً للأسئلة والتحرير بالعربية. النموذج المحلي مجاني لكنه أضعف، ويناسب المهام البسيطة (تصحيح «لا»، الملخصات) إن كان صغيراً.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>المهمة</th><th>يجيب</th><th class="current">الحالي</th></tr></thead>
      <tbody>
      <tr>
        <td><strong>كل المهام (افتراضي)</strong></td>
        <td><select name="RAI_PROVIDER">
          <option value="">كما في ملف الإعدادات</option>
          <option value="anthropic" <?= ($saved['RAI_PROVIDER']['value'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Claude</option>
          <option value="local" <?= ($saved['RAI_PROVIDER']['value'] ?? '') === 'local' ? 'selected' : '' ?>>النموذج المحلي</option>
        </select></td>
        <td class="hint current"><?= Config::provider('default') === 'local' ? 'النموذج المحلي' : 'Claude' ?> · <?= $e($source('RAI_PROVIDER')) ?></td>
      </tr>
      <?php foreach ($tasks as $task => [$label, $note]): $key = "RAI_PROVIDER_$task"; $current = Config::provider(strtolower($task)); ?>
      <tr>
        <td><?= $e($label) ?><div class="hint"><?= $e($note) ?></div></td>
        <td><select name="<?= $key ?>">
          <option value="">مثل الافتراضي</option>
          <option value="anthropic" <?= ($saved[$key]['value'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Claude</option>
          <option value="local" <?= ($saved[$key]['value'] ?? '') === 'local' ? 'selected' : '' ?>>النموذج المحلي</option>
        </select></td>
        <td class="hint current" dir="auto"><?= $current === 'local' ? 'محلي: ' . $e(Config::get('RAI_LOCAL_MODEL_' . $task) ?? Config::get('RAI_LOCAL_MODEL') ?? '—') : 'Claude: ' . $e(Config::model(strtolower($task))) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="card">
    <h2>النموذج المحلي (Ollama على حاسوبك)</h2>
    <div class="form">
      <label>العنوان (رابط القناة، أو http://127.0.0.1:11434 إن كانت المنصة على نفس الحاسوب)
        <input name="RAI_LOCAL_URL" dir="ltr" value="<?= $e($saved['RAI_LOCAL_URL']['value'] ?? '') ?>" placeholder="<?= $e($values['RAI_LOCAL_URL']['env'] ?? 'https://xxxx.trycloudflare.com') ?>">
        <span class="hint"><?= $e($source('RAI_LOCAL_URL')) ?></span>
      </label>
      <div class="grid2">
        <label>اسم النموذج (من ollama list)
          <input name="RAI_LOCAL_MODEL" dir="ltr" value="<?= $e($saved['RAI_LOCAL_MODEL']['value'] ?? '') ?>" placeholder="<?= $e($values['RAI_LOCAL_MODEL']['env'] ?? 'qwen3.5:4b') ?>">
          <span class="hint"><?= $e($source('RAI_LOCAL_MODEL')) ?></span>
        </label>
        <label>مفتاح البوابة
          <input name="RAI_LOCAL_KEY" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $e(Settings::display('RAI_LOCAL_KEY', $val('RAI_LOCAL_KEY')) ?: 'بدون مفتاح') ?>">
          <span class="hint"><?= $e($source('RAI_LOCAL_KEY')) ?> · اتركه فارغاً للإبقاء عليه
            <?php if (isset($saved['RAI_LOCAL_KEY'])): ?><label class="inline"><input type="checkbox" name="clear_RAI_LOCAL_KEY" value="1"> مسح</label><?php endif; ?></span>
        </label>
      </div>
      <div class="test-row">
        <button type="button" class="btn ghost" data-test="local">اختبار الاتصال</button>
        <span class="test-result" data-result="local"></span>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>خدمة قراءة الملفات على حاسوبك</h2>
    <p class="hint">لملفات PDF (والممسوحة ضوئياً) و‎.doc و‎.ppt عندما لا يستطيع هذا الخادم قراءتها. شغّلها بـ <code>extractor\start-windows.bat</code>؛ بعد حفظ المفتاح هنا، تسجّل عنوانها الجديد وحدها عند كل تشغيل.</p>
    <p class="hint">هذا الخادم يقرأ وحده: PDF <?= $yes($hosting['pdf']) ?> · PDF الممسوحة (OCR) <?= $yes($hosting['ocr']) ?> · <code dir="ltr">.doc/.ppt</code> <?= $yes($hosting['office']) ?></p>
    <div class="form">
      <label>العنوان
        <input name="RAI_EXTRACT_URL" dir="ltr" value="<?= $e($saved['RAI_EXTRACT_URL']['value'] ?? '') ?>" placeholder="<?= $e($values['RAI_EXTRACT_URL']['env'] ?? 'https://xxxx.trycloudflare.com') ?>">
        <span class="hint"><?= $e($source('RAI_EXTRACT_URL')) ?></span>
      </label>
      <div class="grid2">
        <label>المفتاح (يظهر في نافذة الخدمة)
          <input name="RAI_EXTRACT_KEY" type="password" dir="ltr" autocomplete="new-password" placeholder="<?= $e(Settings::display('RAI_EXTRACT_KEY', $val('RAI_EXTRACT_KEY')) ?: 'غير مضبوط') ?>">
          <span class="hint"><?= $e($source('RAI_EXTRACT_KEY')) ?> · اتركه فارغاً للإبقاء عليه
            <?php if (isset($saved['RAI_EXTRACT_KEY'])): ?><label class="inline"><input type="checkbox" name="clear_RAI_EXTRACT_KEY" value="1"> مسح</label><?php endif; ?></span>
        </label>
        <label>متى تُستعمل
          <select name="RAI_EXTRACT_REMOTE">
            <option value="">كما في ملف الإعدادات (<?= $e($values['RAI_EXTRACT_REMOTE']['env'] ?? 'auto') ?>)</option>
            <option value="auto" <?= ($saved['RAI_EXTRACT_REMOTE']['value'] ?? '') === 'auto' ? 'selected' : '' ?>>فقط إن لم يستطع هذا الخادم القراءة</option>
            <option value="always" <?= ($saved['RAI_EXTRACT_REMOTE']['value'] ?? '') === 'always' ? 'selected' : '' ?>>دائماً (مثلاً للتعرف الضوئي على حاسوبك)</option>
          </select>
        </label>
      </div>
      <div class="test-row">
        <button type="button" class="btn ghost" data-test="extractor">اختبار الاتصال</button>
        <span class="test-result" data-result="extractor"></span>
      </div>
    </div>
  </section>

  <button class="btn primary">حفظ الإعدادات</button>
</form>
