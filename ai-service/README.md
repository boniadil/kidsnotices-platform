<div dir="rtl">

# 🤖 خدمة الذكاء الاصطناعي لمنصة KidsNotices

خدمة مستقلة مكتوبة بـ **PHP خام** (بدون إطار عمل)، تشتغل بنموذج **Claude** من شركة Anthropic، وتقدّم للمنصة أربع ميزات:

| الميزة | الرابط (Endpoint) | المستفيد |
|---|---|---|
| 💬 مساعد أولياء الأمور (أسئلة عن الغياب، النقط، الواجبات، الأداءات، النقل…) | `POST /v1/parent/chat` | ولي الأمر |
| ✍️ صياغة الإعلانات والرسائل بالعربية والفرنسية | `POST /v1/messages/draft` | الإدارة / الحراسة / الأساتذة |
| 🌐 ترجمة عربية ↔ فرنسية | `POST /v1/messages/translate` | الجميع |
| 📝 ملاحظات بيانات النقط لكل تلميذ | `POST /v1/teacher/report-comments` | الأستاذ |
| 📚 اقتراح تمارين وواجبات منزلية | `POST /v1/teacher/homework` | الأستاذ |
| 📊 تقرير ذكي للمدير (الغياب، المستحقات، الحوادث…) | `POST /v1/admin/insights` | الإدارة |
| 🔎 بحث ذكي على شاكلة Google (جواب مباشر + روابط صفحات المنصة + عمليات بحث ذات صلة) | `POST /v1/search` | الجميع |

---

## 🧭 كيف تشتغل؟

```
 تطبيق ولي الأمر / لوحة الإدارة (PWA – Android)
              │  (جلسة المستخدم العادية)
              ▼
   خادم منصة KidsNotices  ── يتحقق من الصلاحيات ويجمع البيانات المسموح بها فقط
              │  HTTPS + Authorization: Bearer KN_AI_SECRET + X-School-Id
              ▼
   خدمة الذكاء الاصطناعي (هذا المجلد)  ── ai.kidsnotices.ma
              │  ANTHROPIC_API_KEY
              ▼
          Claude API (Anthropic)
```

**قواعد مهمة:**
- الخدمة **لا تتصل بقاعدة بياناتك**. منصتك هي التي ترسل لها البيانات اللازمة فقط، بعد التحقق من صلاحيات المستخدم. هكذا يبقى عزل المؤسسات (Multi-Tenant) تحت سيطرتك.
- مفتاح `ANTHROPIC_API_KEY` والسر `KN_AI_SECRET` يبقيان **على الخادم فقط** — لا تضعهما أبداً في JavaScript أو تطبيق أندرويد.

---

## ⚙️ التثبيت (على خادمك)

**المتطلبات:** PHP 8.1 أو أحدث (مع `curl` و`mbstring`)، و Composer.

```bash
# 1) انسخ المجلد إلى الخادم ثم:
cd ai-service
composer install --no-dev --optimize-autoloader

# 2) الإعدادات
cp .env.example .env
nano .env        # ضع ANTHROPIC_API_KEY و KN_AI_SECRET

# 3) مجلد التخزين يجب أن يكون قابلاً للكتابة
chmod 775 storage && chown www-data:www-data storage
```

**الحصول على مفتاح Anthropic:** أنشئ حساباً على [console.anthropic.com](https://console.anthropic.com)، أضف وسيلة أداء، ثم أنشئ مفتاح API. يُنصح بتحديد سقف شهري للإنفاق من نفس اللوحة.

**توليد السر المشترك:**
```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```
ضع نفس القيمة في `.env` الخاص بالخدمة وفي إعدادات منصتك.

### الاستضافة على نطاق فرعي `ai.kidsnotices.ma`

اجعل جذر الموقع (DocumentRoot) هو المجلد **`public/`** فقط (وليس `ai-service/`)، حتى لا يكون `.env` و`vendor/` و`storage/` قابلة للوصول من الإنترنت.

**Apache:** ملف `public/.htaccess` جاهز (يتطلب `mod_rewrite`).

**Nginx:**
```nginx
server {
    server_name ai.kidsnotices.ma;
    root /var/www/ai-service/public;

    # اسمح فقط لخادم المنصة (ضع عنوان IP الخاص به)
    allow 1.2.3.4;
    deny all;

    location / {
        try_files $uri /index.php$is_args$args;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 180s;
    }
}
```
فعّل HTTPS (مثلاً بـ `certbot --nginx -d ai.kidsnotices.ma`).

> 💡 إذا كانت المنصة والخدمة على نفس الخادم، يمكنك تشغيل الخدمة على عنوان داخلي فقط (مثل `127.0.0.1:8080`) بدون نطاق عام.

**التحقق:**
```bash
curl https://ai.kidsnotices.ma/health
# {"status":"ok","model":"claude-opus-5-5"}
```

---

## 🔌 الدمج في منصتك

1. انسخ الملف `client/KidsNoticesAI.php` إلى مشروع المنصة (لا يحتاج Composer، فقط cURL).
2. استدعِه من كود الخادم:

```php
require_once 'KidsNoticesAI.php';

$ai = new KidsNoticesAI('https://ai.kidsnotices.ma', getenv('KN_AI_SECRET'), $schoolId);

// مساعد ولي الأمر
$r = $ai->parentChat('مجموعة مدارس النجاح', $context, $history, 'واش ولدي غاب هاد السيمانة؟');
echo $r['answer'];

// إعلان بالعربية والفرنسية
$d = $ai->draftMessage('مجموعة مدارس النجاح', 'رحلة يوم الجمعة، 150 درهم، آخر أجل الأربعاء');
echo $d['ar']['title'], $d['ar']['body'], $d['fr']['title'], $d['fr']['body'];

// ترجمة
echo $ai->translate('Réunion des parents samedi à 10h', 'ar')['translation'];

// ملاحظات النقط
$c = $ai->reportComments('Mathématiques', 'CE6', $students, 'fr');

// تمارين
$h = $ai->homework('الرياضيات', 'الأولى إعدادي', 'الكسور', count: 5);

// تقرير المدير
$i = $ai->adminInsights('مجموعة مدارس النجاح', 'أكتوبر 2026', $stats);
```

أمثلة كاملة:
- [`examples/parent_chat_endpoint.php`](examples/parent_chat_endpoint.php) — نقطة AJAX في فضاء ولي الأمر: تتحقق من الجلسة، تجمع بيانات أبنائه فقط من قاعدة البيانات، وتحفظ تاريخ المحادثة.
- [`examples/teacher_and_admin.php`](examples/teacher_and_admin.php) — باقي الميزات.

### الأخطاء
كل خطأ يرجع بصيغة: `{"error": {"code": "...", "message": "..."}}`، وفي الـ client يُرمى `KidsNoticesAIException` مع `->errorCode`:

| الكود | HTTP | المعنى |
|---|---|---|
| `unauthorized` | 401 | السر `KN_AI_SECRET` خاطئ |
| `invalid_input` | 400 | حقل ناقص أو غير صالح (الرسالة تحدد الحقل) |
| `daily_limit_reached` | 429 | المؤسسة تجاوزت حدها اليومي (`KN_AI_DAILY_LIMIT_PER_SCHOOL`) |
| `ai_busy` | 503 | ضغط على Anthropic API — أعد المحاولة بعد لحظات |
| `ai_refused` | 422 | النموذج رفض الطلب — اطلب من المستخدم إعادة الصياغة |
| `server_misconfigured` | 500 | مفتاح Anthropic أو السر غير مضبوط |

---

## 🔎 صفحة البحث الذكي (على شاكلة Google)

صفحة جاهزة في [`platform/search/`](platform/search/) تضيفها إلى منصتك: شعار KidsNotices، خانة بحث، أمثلة أسئلة حسب الدور، ثم صفحة نتائج فيها:
- **جواب ذكي** مباشر من معطيات المستخدم (أو معرفة تربوية عامة للأسئلة العامة)،
- **روابط صفحات المنصة** المناسبة (لا يظهر إلا رابط موجود فعلاً في لائحتك — أي رابط مختلق يُحذف)،
- **عمليات بحث ذات صلة**،
- واجهة عربية/فرنسية، وضع ليلي، متوافقة مع الهاتف، وبحث صوتي (على Chrome/Android).

| الملف | الدور |
|---|---|
| `search.php` | الصفحة (HTML + CSS + JavaScript، بدون أي مكتبة خارجية) |
| `search_api.php` | نقطة AJAX على خادمك: تتحقق من الجلسة وCSRF، ثم تتصل بخدمة الذكاء الاصطناعي |
| `search_config.php` | **الملف الوحيد الذي تعدّله**: المستخدم من الجلسة، روابط صفحاتك، والمعطيات التي يراها كل دور |

**التركيب:**
1. انسخ `platform/search/` إلى منصتك (مثلاً `/search/`) وانسخ معه `client/KidsNoticesAI.php` في نفس المجلد.
2. في `search_config.php`:
   - `kn_search_user()`: ضع مفاتيح الجلسة الخاصة بمنصتك (المعرف، المؤسسة، الدور، الاسم).
   - `kn_search_pages()`: ضع الروابط الحقيقية لصفحاتك لكل دور.
   - `kn_search_context()`: استعلامات قاعدة البيانات (نفس مبدأ `examples/parent_chat_endpoint.php`).
3. عرّف `KN_AI_SECRET` (و`KN_AI_URL` إن لم يكن `https://ai.kidsnotices.ma`) في بيئة خادم المنصة.
4. أضف رابطاً للصفحة في القائمة، مثلاً: `<a href="/search/search.php">🔎 بحث</a>`.

**للتجربة قبل الربط بقاعدة البيانات:** شغّل على خادم تجريبي فقط:
```bash
KN_SEARCH_DEMO=1 KN_AI_SECRET=... KN_AI_URL=http://127.0.0.1:8080 php -S 127.0.0.1:8000 -t platform/search
# ثم افتح http://127.0.0.1:8000/search.php
```
وضع التجربة يُدخلك كولي أمر وهمي ببيانات نموذجية — **لا تفعّله أبداً في الإنتاج**.

---

## 💰 التكلفة

الخدمة تستعمل افتراضياً **Claude Opus 5.5** (حوالي 4$ لكل مليون token مُدخل و20$ لكل مليون token مُخرج).
تقدير تقريبي: سؤال ولي أمر مع بيانات طفل واحد ≈ 3,000 token مدخل + 400 مخرج ≈ **0.02$ (حوالي 0.2 درهم)**. الرسائل الموالية في نفس المحادثة أرخص بفضل التخزين المؤقت (prompt caching).

- كل استدعاء يُسجَّل في `storage/usage-YYYY-MM.log` (المؤسسة، الميزة، عدد الـ tokens) — يمكنك استعماله لفوترة المدارس أو تتبع الاستهلاك.
- `KN_AI_DAILY_LIMIT_PER_SCHOOL` يحمي من الاستهلاك المفرط لمؤسسة واحدة.
- لتخفيض التكلفة يمكنك تغيير `KN_AI_MODEL` إلى `claude-sonnet-5-5` (2$/10$) أو `claude-haiku-5-5` (0.10$/0.50$) — جرّب الجودة على أسئلة حقيقية قبل اعتماد ذلك.

---

## 🔐 حماية المعطيات الشخصية (مهم للمدارس)

بيانات التلاميذ قاصرين، والقانون المغربي **09-08** (اللجنة الوطنية CNDP) يفرض حمايتها:

- **أرسل الحد الأدنى فقط:** الاسم الشخصي بدل الاسم الكامل، معرف داخلي بدل رقم مسار أو البطاقة الوطنية، لا أرقام هواتف ولا عناوين.
- **لا ترسل المعطيات الصحية** إلا إذا كانت ضرورية فعلاً لسؤال ولي الأمر عن طفله.
- **لتقرير المدير أرسل إحصائيات مجمّعة** (عدد الغيابات لكل قسم…) وليس سجلات كل تلميذ.
- أخبر المؤسسات وأولياء الأمور في شروط الاستعمال وسياسة الخصوصية أن المنصة تستعمل خدمة ذكاء اصطناعي خارجية (Anthropic)، وصرّح بذلك لدى CNDP عند الاقتضاء.
- حسب الشروط التجارية لـ Anthropic، بيانات API لا تُستعمل لتدريب النماذج افتراضياً — راجع [سياسة Anthropic](https://www.anthropic.com/legal/commercial-terms) للتأكد من الشروط الحالية.
- الخدمة لا تحفظ نص الأسئلة أو الأجوبة، فقط عدد الـ tokens.

---

## 🛡️ ما تفعله الخدمة تلقائياً

- **رفض النموذج (refusal):** مفعّل `fallbacks: "default"` — إذا رفض نظام الحماية الطلب، تعيد Anthropic المحاولة تلقائياً بنموذج بديل. إذا رفض الجميع، تُرجع الخدمة `ai_refused`.
- **أجوبة منظمة:** الإعلانات والملاحظات والتمارين والتقارير ترجع دائماً بـ JSON صالح (structured outputs)، جاهزة للعرض في واجهتك.
- **حماية من حقن التعليمات:** البيانات المرسلة توضع داخل وسوم منفصلة، والنموذج مُوجَّه لاعتبارها بيانات لا أوامر.
- **مساعد ولي الأمر:** يجيب فقط من البيانات المرسلة، يعترف حين لا يعرف، يفهم الدارجة، ويوجّه للإدارة في الأمور الصحية والمستعجلة.

---

## 📁 بنية المجلد

```
ai-service/
├── public/index.php          ← نقطة الدخول الوحيدة (التوجيه، المصادقة، الحدود)
├── public/.htaccess          ← إعداد Apache
├── src/Claude.php            ← الاتصال بـ Claude API (fallbacks، الأخطاء، التسجيل)
├── src/Features/             ← الميزات الأربع (التعليمات/Prompts هنا)
├── src/RateLimiter.php       ← الحد اليومي لكل مؤسسة
├── src/UsageLog.php          ← سجل الاستهلاك
├── client/KidsNoticesAI.php  ← الملف الذي تنسخه إلى منصتك
├── examples/                 ← أمثلة الدمج
├── platform/search/          ← صفحة البحث الذكي (تُنسخ إلى منصتك)
└── .env.example              ← الإعدادات
```

لتعديل أسلوب الأجوبة (اللهجة، الطول، القواعد)، عدّل نص التعليمات `$system` في ملف الميزة المعنية داخل `src/Features/`.

</div>
