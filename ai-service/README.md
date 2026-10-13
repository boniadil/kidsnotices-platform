<div dir="rtl">

# 🤖 خدمة الذكاء الاصطناعي KidsNotices AI

خدمة عامة (API) مستقلة مكتوبة بـ **PHP خام** (بدون إطار عمل)، تشتغل بنماذج **Claude** من Anthropic، ويمكن أن تستعملها **أي منصة مدرسية**: لكل منصة (عميل) مفتاحها الخاص، ومدارسها، وحصصها، وفوترتها.

| الميزة | الرابط (Endpoint) | رمز الميزة | المستفيد |
|---|---|---|---|
| 💬 مساعد أولياء الأمور (الغياب، النقط، الواجبات، الأداءات، النقل…) | `POST /v1/parent/chat` | `parent_chat` | ولي الأمر |
| 🔎 بحث ذكي على شاكلة Google (جواب + روابط صفحات المنصة + بحث ذو صلة) | `POST /v1/search` | `search` | الجميع |
| ✍️ صياغة الإعلانات والرسائل بالعربية والفرنسية | `POST /v1/messages/draft` | `draft` | الإدارة / الأساتذة |
| 🌐 ترجمة عربية ↔ فرنسية | `POST /v1/messages/translate` | `translate` | الجميع |
| 📝 ملاحظات بيانات النقط لكل تلميذ | `POST /v1/teacher/report-comments` | `teacher` | الأستاذ |
| 📚 اقتراح تمارين وواجبات منزلية | `POST /v1/teacher/homework` | `teacher` | الأستاذ |
| 📊 تقرير ذكي للمدير (الغياب، المستحقات، الحوادث…) | `POST /v1/admin/insights` | `insights` | الإدارة |
| 📈 استهلاك العميل لشهر معيّن | `GET /v1/usage?month=YYYY-MM` | — | المنصة العميلة |

رمز الميزة يُستعمل لاختيار **النموذج** و**الحد الشهري** لكل ميزة، ولتقارير الاستهلاك.

---

## 🧭 كيف تشتغل؟

```
 تطبيق ولي الأمر / لوحة الإدارة (PWA – Android)
              │  (جلسة المستخدم العادية)
              ▼
   خادم المنصة العميلة  ── يتحقق من الصلاحيات ويجمع البيانات المسموح بها فقط
              │  HTTPS
              │  Authorization: Bearer kn_live_…   (مفتاح العميل)
              │  X-School-Id: <المدرسة>           (يجب أن تكون مربوطة بالمفتاح)
              │  X-End-User:  <معرّف المستعمل مُجزّأ>  (اختياري، للحد اليومي لكل مستعمل)
              ▼
   خدمة الذكاء الاصطناعي (هذا المجلد) ── ai.kidsnotices.ma
              │  إخفاء الهوية (اختياري) → Claude → إرجاع الأسماء الحقيقية
              ▼
          Claude API (Anthropic)
```

**قواعد مهمة:**
- الخدمة **لا تتصل بقاعدة بيانات المنصة**. المنصة ترسل البيانات اللازمة فقط بعد التحقق من صلاحيات المستخدم.
- لا يُوثَق بـ `X-School-Id` وحده: كل مفتاح مربوط بلائحة مدارس، وأي مدرسة غير مربوطة تُرفض (`403 school_not_allowed`).
- `ANTHROPIC_API_KEY` ومفاتيح العملاء تبقى **على الخوادم فقط** — أبداً في JavaScript أو تطبيق أندرويد.

---

## ⚙️ التثبيت

**المتطلبات:** PHP **8.1 أو أحدث** مع الامتدادات `curl` و`mbstring` و`pdo_sqlite` (أو `pdo_mysql` إن اخترت MySQL)، و Composer.

```bash
cd ai-service
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env          # ضع ANTHROPIC_API_KEY وراجع النماذج والحدود
chmod 775 storage && chown www-data:www-data storage    # قاعدة SQLite تُنشأ هنا تلقائياً
```

**إنشاء عميل (منصة) وربط مدارسه:**
```bash
php bin/kn-admin.php client:create "KidsNotices" --requests=20000 --budget=50
#   → يطبع المفتاح kn_live_… مرة واحدة فقط (يُخزَّن مُجزّأً) — ضعه في إعدادات المنصة: KN_AI_KEY
php bin/kn-admin.php school:add 1 najah-rabat
php bin/kn-admin.php school:add 1 atlas-fes
```

| الأمر | الوظيفة |
|---|---|
| `client:create "<اسم>" [--requests=N] [--budget=USD]` | عميل جديد + مفتاح (الحصة الشهرية بالطلبات و/أو بالدولار، 0 = بدون حد) |
| `client:list` | العملاء، حالة المفاتيح، المدارس، الحصص |
| `client:limits <id> --requests=N --budget=USD` | تعديل الحصص |
| `client:revoke <id>` | إلغاء المفتاح فوراً |
| `school:add <id> <school>` / `school:remove <id> <school>` | ربط/فك مدرسة |
| `usage [YYYY-MM]` | الاستهلاك والكلفة لكل عميل/مدرسة/ميزة |
| `admin:password` | توليد كلمة سر لوحة `/dashboard` |

**الحصول على مفتاح Anthropic:** [console.anthropic.com](https://console.anthropic.com) ← API Keys. حدّد سقفاً شهرياً للإنفاق من نفس اللوحة.

### الاستضافة على نطاق فرعي `ai.kidsnotices.ma`

اجعل جذر الموقع (DocumentRoot) هو المجلد **`public/`** فقط، حتى لا تكون `.env` و`vendor/` و`storage/` (التي فيها قاعدة البيانات) قابلة للوصول من الإنترنت.

**استضافة مشتركة (cPanel):**
1. ارفع مجلد `ai-service` **خارج** `public_html` (مثلاً `/home/USER/ai-service`).
2. **Domains → Create a new domain** (أو Subdomains): `ai.kidsnotices.ma` بجذر `/home/USER/ai-service/public`.
3. **Select PHP Version**: اختر 8.1 أو أحدث، وفعّل `curl` و`mbstring` و`pdo_sqlite` (أو `pdo_mysql`).
4. **Terminal** (إن وُجد): `composer install --no-dev` — وإلا نفّذه على حاسوبك وارفع مجلد `vendor/`.
5. **SSL/TLS Status**: فعّل AutoSSL للنطاق الفرعي.
6. أوامر `bin/kn-admin.php` تُنفَّذ من Terminal: `php bin/kn-admin.php …`.

**Apache:** ملف `public/.htaccess` جاهز (يتطلب `mod_rewrite`)، و`storage/.htaccess` يمنع الوصول للتخزين احتياطاً.

**Nginx:**
```nginx
server {
    server_name ai.kidsnotices.ma;
    root /var/www/ai-service/public;

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

**التحقق:** `curl https://ai.kidsnotices.ma/health` ← `{"status":"ok"}`

---

## 🔌 الدمج في منصة عميلة

انسخ `client/KidsNoticesAI.php` إلى المنصة (لا يحتاج Composer، فقط cURL):

```php
require_once 'KidsNoticesAI.php';

$ai = new KidsNoticesAI('https://ai.kidsnotices.ma', getenv('KN_AI_KEY'), $schoolId);

// مساعد ولي الأمر — مع حد يومي لكل ولي، وإخفاء الأسماء
$r = $ai->forUser($parentId)
        ->withPeople([
            ['id' => 12, 'name' => 'ياسين العلمي', 'role' => 'student'],
            ['id' => 4,  'name' => 'أحمد العلمي',  'role' => 'parent'],
        ])
        ->parentChat('مجموعة مدارس النجاح', $context, $history, 'واش ياسين غاب هاد السيمانة؟');
echo $r['answer'];   // الأسماء الحقيقية مُرجعة

$d = $ai->draftMessage('مجموعة مدارس النجاح', 'رحلة يوم الجمعة، 150 درهم، آخر أجل الأربعاء');
$t = $ai->translate('Réunion des parents samedi à 10h', 'ar');
$c = $ai->reportComments('Mathématiques', 'CE6', $students, 'fr');
$h = $ai->homework('الرياضيات', 'الأولى إعدادي', 'الكسور', count: 5);
$i = $ai->adminInsights('مجموعة مدارس النجاح', 'أكتوبر 2026', $stats);
$s = $ai->search('مجموعة مدارس النجاح', 'parent', 'الغياب', $context, $pages);
$u = $ai->usage('2026-10');   // استهلاك العميل
```

أمثلة كاملة: [`examples/parent_chat_endpoint.php`](examples/parent_chat_endpoint.php) و [`examples/teacher_and_admin.php`](examples/teacher_and_admin.php).

### الأخطاء
كل خطأ يرجع بصيغة `{"error": {"code": "...", "message": "..."}}` برسالة عامة؛ التفاصيل التقنية (ومنها نص أخطاء Anthropic) تُكتب في سجل الخادم فقط ولا تُرسل للعميل.

| الكود | HTTP | المعنى |
|---|---|---|
| `unauthorized` | 401 | مفتاح ناقص أو خاطئ أو ملغى |
| `school_not_allowed` | 403 | المدرسة غير مربوطة بهذا المفتاح |
| `invalid_input` / `invalid_json` / `invalid_school` / `invalid_end_user` | 400 | مدخلات غير صالحة (الرسالة تحدد الحقل) |
| `client_quota_exceeded` | 429 | انتهت الحصة الشهرية للعميل (طلبات أو ميزانية) |
| `feature_limit_reached` | 429 | المدرسة بلغت الحد الشهري لهذه الميزة |
| `user_daily_limit_reached` | 429 | المستعمل النهائي بلغ حده اليومي |
| `ai_refused` | 422 | النموذج رفض الطلب — إعادة الصياغة |
| `ai_truncated` | 502 | الجواب طويل جداً وانقطع |
| `ai_busy` | 503 | ضغط على مزوّد الذكاء الاصطناعي — أعد المحاولة |
| `ai_error` / `ai_unreachable` | 502 / 504 | خطأ عند المزوّد |
| `server_misconfigured` | 500 | `ANTHROPIC_API_KEY` غير مضبوط أو غير صالح |

---

## 🔐 إخفاء الهوية (pseudonymization)

عند إرسال `pseudonymize: true` مع `people: [{id, name, role}]` (أو `->withPeople()` في الـ client):

- كل اسم يُعوَّض برمز قبل الإرسال إلى Anthropic: `[S12]` تلميذ، `[P4]` ولي، `[T7]` أستاذ، `[E3]` موظف آخر، و`[N1]` لاسم عائلي مشترك بين عدة أشخاص — في `context` و`question` و`history` وكل الحقول النصية.
- **تسامح في الكتابة:** أ/إ/آ/ا، ة/ه، ى/ي، ؤ/و، الحركات والتطويل، الحروف الملتصقة (و، ف، ب، ل، ك: «لياسين»، «وبأحمد»)، الأحرف الكبيرة والصغيرة واللكنات الفرنسية، والأسماء المركبة («عبد الله»، «Ben Ali»، «آيت…») دون المساس بكلمة «الله» وحدها.
- **حذف تلقائي:** الهواتف (المغربية والدولية)، البريد الإلكتروني، رموز مسار، أرقام البطاقة، وحقول مثل `phone` و`email` و`address` و`cin` و`massar`. **تاريخ الازدياد** (`birth_date`، `date_naissance`…) يُعوَّض بـ `age` (العمر).
- تُضاف للتعليمات فقرة تشرح الرموز وتطلب كتابتها حرفياً، ثم **تُعاد الأسماء الحقيقية** في الجواب قبل إرجاعه.
- الأنماط تُبنى على دفعات (40 اسماً لكل تعبير) لتفادي حدود PCRE، وإن فشل التنظيف لأي سبب يُرفض الطلب (`privacy_error`) بدل إرسال نص غير منظف.

نصائح: أرسل في `people` كل من قد يُذكر (الولي وأبناؤه، تلاميذ القسم…)، وبالاسم كما تريد أن يظهر في الجواب. وتبقى قاعدة **الحد الأدنى من البيانات** سارية: لا ترسل ما لا يحتاجه السؤال، ولتقرير المدير أرسل أرقاماً مجمّعة. القانون المغربي **09-08** (CNDP): أخبر المؤسسات وأولياء الأمور في سياسة الخصوصية باستعمال خدمة ذكاء اصطناعي خارجية.

---

## 💰 النماذج، الحدود والكلفة

**نموذج لكل ميزة** (`.env`): `KN_AI_MODEL_<FEATURE>`، وإلا `KN_AI_MODEL`، وإلا Claude Opus 5.5. الاقتراح الافتراضي في `.env.example`:

| الميزة | النموذج المقترح | لماذا |
|---|---|---|
| `parent_chat`، `search`، `translate` | `claude-haiku-5-5` | أسئلة قصيرة وكثيرة — الأرخص بكثير |
| `draft`، `teacher` | `claude-sonnet-5-5` | جودة كتابة أعلى بكلفة معتدلة |
| `insights` | `claude-opus-5-5` | تحليل أدق، طلبات قليلة |

الأسعار العامة (دولار لكل مليون token، مدخل/مخرج): Haiku 5.5: 0.10/0.50 — Sonnet 5.5: 2/10 — Opus 5.5: 4/20. مثلاً سؤال ولي أمر على Haiku (≈3,000 مدخل + 400 مخرج) ≈ **0.0005$**. الجدول في `src/Pricing.php` — حدّثه إن تغيّرت الأسعار.

**الحماية من الرفض (fallbacks):** تُرسل فقط للنماذج التي تقبلها (Fable 5.1، Opus 5.5، Opus 5، Sonnet 5.5). Haiku 5.5 لا يقبلها، فلا تُرسل له (وإلا فشلت كل الطلبات).

**الحدود** (تُحسب من قاعدة البيانات قبل كل طلب):
| المستوى | الإعداد | الكود عند التجاوز |
|---|---|---|
| العميل — شهرياً (طلبات و/أو دولار) | `bin/kn-admin.php client:create/limits` | `client_quota_exceeded` |
| المدرسة — شهرياً لكل ميزة | `KN_AI_MONTHLY_LIMIT_<FEATURE>` | `feature_limit_reached` |
| المستعمل النهائي — يومياً (كل الميزات) | `KN_AI_DAILY_LIMIT_PER_USER` + رأس `X-End-User` | `user_daily_limit_reached` |

هكذا لا تستهلك أسئلة الأولياء الحصة التي تحتاجها تقارير المدير.

**سجل الاستهلاك** في جدول `usage_log` (SQLite افتراضياً، أو MySQL عبر `KN_AI_DB_DSN`): لكل طلب العميل، المدرسة، الميزة، المستعمل (مُجزّأ)، النموذج الفعلي، الـ tokens، و**الكلفة التقديرية**. لا يُحفظ نص الأسئلة ولا الأجوبة.

**لوحة الاستهلاك:** `https://ai.kidsnotices.ma/dashboard` (المستخدم `admin`، كلمة السر من `php bin/kn-admin.php admin:password` ← `KN_AI_ADMIN_PASSWORD_HASH`): الطلبات والكلفة لكل عميل، والتفصيل لكل مدرسة وميزة، مع اختيار الشهر. وكل عميل يرى استهلاكه عبر `GET /v1/usage`.

---

## 🔎 صفحة البحث الذكي (على شاكلة Google)

صفحة جاهزة في [`platform/search/`](platform/search/) تُضاف إلى المنصة العميلة: شعار، خانة بحث، أمثلة حسب الدور، ثم جواب ذكي + روابط صفحات المنصة (لا يظهر إلا رابط موجود فعلاً في لائحتك) + عمليات بحث ذات صلة. عربية/فرنسية، وضع ليلي، متوافقة مع الهاتف، وبحث صوتي.

| الملف | الدور |
|---|---|
| `search.php` | الصفحة (HTML + CSS + JavaScript، بدون مكتبات خارجية) |
| `search_api.php` | نقطة AJAX على خادم المنصة: الجلسة + CSRF + حد بحث كل ثانيتين، ثم الاتصال بالخدمة |
| `search_config.php` | **الملف الوحيد الذي تعدّله**: المستخدم من الجلسة، روابط الصفحات، المعطيات، والأشخاص لإخفاء الهوية |

التركيب: انسخ `platform/search/` ومعه `client/KidsNoticesAI.php`، عدّل `search_config.php`، وعرّف `KN_AI_KEY` (و`KN_AI_URL`) في بيئة خادم المنصة.
للتجربة فقط: `KN_SEARCH_DEMO=1 KN_AI_KEY=kn_live_… KN_AI_URL=http://127.0.0.1:8080 php -S 127.0.0.1:8000 -t platform/search` — **لا تفعّل وضع التجربة أبداً في الإنتاج**.

---

## 🧪 الاختبارات (بدون كلفة)

```bash
php tests/run.php
```
تشغّل خادم Claude مزيّفاً (`tests/mock_claude.php`) والخدمة على منافذ حرة، بقاعدة SQLite مؤقتة، وتختبر 41 حالة: المفاتيح والمدارس، المدخلات الخاطئة، كل نقطة نهاية، النماذج وخيار fallbacks، إخفاء الهوية (لا اسم/هاتف/بريد/مسار/تاريخ ازدياد يصل إلى Claude، والأسماء تُعاد في الجواب)، الرفض (`refusal`)، الجواب المقطوع (`max_tokens`)، عدم تسريب أخطاء المزوّد، الحدود الثلاثة، والكلفة واللوحة.

الخادم المزيّف يتحقق من صحة كل طلب كما يفعل Anthropic: تناوب الرسائل، مخططات JSON (`additionalProperties: false` وكل الحقول في `required`)، قيمة `effort`، و`fallbacks` مع رأس beta الصحيح وعلى النماذج المسموحة فقط. يمكن استعماله أيضاً يدوياً: `php -S 127.0.0.1:9101 tests/mock_claude.php` ثم `ANTHROPIC_BASE_URL=http://127.0.0.1:9101`.

---

## 📁 بنية المجلد

```
ai-service/
├── public/index.php            ← نقطة الدخول (التوجيه، المفاتيح، الحدود، إخفاء الهوية، /dashboard)
├── src/Claude.php              ← الاتصال بـ Claude (نموذج لكل ميزة، fallbacks، أخطاء عامة، تسجيل الكلفة)
├── src/Features/               ← الميزات (التعليمات/Prompts ومخططات JSON هنا)
├── src/Privacy/Pseudonymizer.php ← إخفاء الهوية وإرجاع الأسماء
├── src/Clients.php             ← مفاتيح العملاء ومدارسهم
├── src/Limits.php              ← الحدود الثلاثة
├── src/Usage.php، src/Pricing.php ← سجل الاستهلاك والكلفة التقديرية
├── src/Database.php            ← SQLite/MySQL + إنشاء الجداول تلقائياً
├── src/Dashboard.php           ← لوحة الاستهلاك
├── bin/kn-admin.php            ← إدارة العملاء والمدارس والحصص
├── client/KidsNoticesAI.php    ← الملف الذي تنسخه إلى المنصة العميلة
├── platform/search/            ← صفحة البحث الذكي
├── examples/                   ← أمثلة الدمج
├── tests/                      ← الخادم المزيّف والاختبارات
└── .env.example                ← الإعدادات
```

</div>
