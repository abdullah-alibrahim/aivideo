# AI Reels Studio & Video Production Platform

منصة ويب لتركيب وتحرير فيديوهات قصيرة (Reels / TikTok / Shorts)، مبنية بـ **Laravel 13** ومحرك Python يستخدم MoviePy لمعالجة المقاطع وبطاقات النص مع دعم العربية والإنجليزية. يعتمد توليد الأفكار والنصوص على Gemini فقط، ولا يوجد توليد محلي بديل.

---

## الميزات والمواصفات الرئيسية

1. **معمارية احترافية ونظيفة**:
   - واجهة مستخدم متطورة (Blade + Tailwind CSS) مع مشغل فيديو مدمج ومكتبة للمقاطع المنتجة.
   - محرك بايثون منفصل (`python_engine/`) مزود بدعم MoviePy 2 و OpenAI v1.0+.
   - قاعدة بيانات SQLite مدمجة جاهزة مع Migrations لحفظ الفيديوهات والإحصائيات والسيناريوهات المولدة.
2. **اقتراح النصوص**:
   - طلب اقتراحات محتوى من مزود خارجي عند إدخال مفتاح OpenAI أو Gemini؛ وقد تُرسل مدخلات المستخدم إلى ذلك المزود.
   - يجب ضبط مفتاح Gemini صالح قبل التوليد؛ عند غياب المفتاح أو فشل كل المحاولات تتوقف العملية ولا يتم إنشاء نص محلي بديل.
3. **مشغل فيديو عمودي حديث (9:16)** مع أزرار التحميل والعرض والإدارة.
4. **تراكب بصري زجاجي مع دعم اللغة العربية** باستخدام Pillow، مع حركة للنصوص والخلفية.

> **حدود التوليد الحالية:** المحرك لا ينشئ لقطات فيديو جديدة من وصف نصي؛ بل يركّب المقطع المرفوع أو الخلفيات المتاحة مع بطاقات النص والحركة والصوت الاختياري. ولا يحسب درجة انتشار أو أداء حقيقية.

## هيكلية المشروع

```text
aivideo/
├── laravel-app/               # تطبيق الويب المتكامل
├── python_engine/             # محرك معالجة وريندر الفيديو
├── venv/                      # البيئة الافتراضية للبايثون
├── scripts/                   # النسخ والاستعادة
├── .github/workflows/ci.yml   # الاختبارات الآلية
├── install.command / install.cmd # تثبيت وتشغيل بنقرة واحدة
├── setup.sh / setup.ps1          # إعداد الاعتماديات لأول مرة
├── start.sh / start.ps1          # تشغيل المنصة
└── README.md
```

## التشغيل السريع على macOS / Linux

```bash
./setup.sh
./start.sh
```

ثم افتح `http://127.0.0.1:8000`.

### نسخة macOS كتطبيق

توجد نسخة جاهزة باسم **AI Reels Studio.app** داخل مجلد `outputs/`. اسحب التطبيق إلى
مجلد `Applications` ثم افتحه. في أول تشغيل ينسخ ملفات المشروع إلى
`~/AI Reels Studio`، يثبت الاعتماديات، ثم يفتح Terminal ويشغل الخادم والـQueue.
يمكن أيضاً استخدام ملف `AI Reels Studio.app.zip` إذا كان النقل أو المشاركة أسهل.

يشغّل `start.sh` خادم Laravel وعامل طابور (`queue:work`) معاً. الرندر لا يحدث داخل طلب الويب:
يُعاد الرد فوراً بحالة `queued`، ويتابع عامل الطابور العمل ويعرض تقدّماً حقيقياً في الواجهة.

## التشغيل السريع على Windows

ثبّت PHP 8.3+ مع إضافات `mbstring`, `fileinfo`, و`PDO SQLite`، ثم Composer، Node.js 20+،
وPython 3.11+، وأضفها إلى `PATH`. افتح PowerShell داخل مجلد المشروع ونفّذ:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\setup.ps1
.\start.ps1
```

للتثبيت المباشر الأسهل، انقر مرتين على `install.cmd`؛ سيقوم بتثبيت الاعتماديات
وتوليد المفتاح وتجهيز قاعدة البيانات ثم تشغيل الموقع تلقائياً. يستخدم المشروع تلقائياً
`venv\\Scripts\\python.exe` على Windows و`venv/bin/python3` على macOS/Linux؛ لا تعدّل
الكود لتغيير الفواصل. إذا كان Python في مسار مخصص، ضع المسار الكامل في `.env`:

```env
AIVIDEO_PYTHON_BIN=C:\\path\\to\\aivideo\\venv\\Scripts\\python.exe
```

في كلا النظامين افتح `http://127.0.0.1:8000`. أوقف الخادم بـ `Ctrl+C`؛ وسيتم إيقاف
عامل الطابور والـScheduler معه.

على macOS يمكن النقر مرتين على `install.command`. إذا منع macOS التشغيل، نفّذ مرة واحدة:

```bash
chmod +x install.command setup.sh start.sh
```

بعد ذلك افتح `install.command` مرة أخرى؛ سيُفتح Terminal ويبدأ الموقع بعد اكتمال التثبيت.

من شريط التنقل افتح **Dashboard / التحليلات** لعرض الإنتاج اليومي، حالات الرندر، اللغات، المجالات، القوالب، متوسط المدة، وآخر الريلز والمجموعات.

من خطوة تقييم الفيديو في الـ Wizard استخدم **Fact-check / فحص الحقائق** لتحليل الادعاءات وتحديد ما يحتاج مصدرًا خارجيًا. هذه مراجعة أولية بالذكاء الاصطناعي وليست تحققًا صحفيًا أو تصفحًا للمصادر؛ راجع يدويًا أي محتوى إخباري أو صحي أو مالي أو قانوني قبل النشر.

للتحقق من جاهزية البيئة (Python، FFmpeg، الخط العربي، التخزين، الطابور، المفاتيح):

```bash
cd laravel-app
php artisan studio:doctor
```

## Hardening before network exposure

الوضع الافتراضي محلي فقط. قبل التعريض خارج `127.0.0.1` استخدم HTTPS واضبط:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
STUDIO_AUTH_ENABLED=true
STUDIO_AUTH_USERNAME=admin
STUDIO_AUTH_PASSWORD=use-a-long-random-password
STUDIO_MAX_VIDEO_MB=100
STUDIO_MAX_MUSIC_MB=20
STUDIO_MAX_CONCURRENT_RENDERS=1
STUDIO_MIN_FREE_SPACE_MB=1024
STUDIO_STALE_AFTER_MINUTES=30
```

عند التفعيل تحمي Basic Authentication إدارة المفاتيح وواجهات AI والتوليد والسجلات والحذف وبث الفيديو. احفظ `APP_KEY` ومفاتيح المزود وكلمة المرور في مدير أسرار أو مخزن مشفر، ولا تضع `.env` أو النسخ الاحتياطية أو الوسائط المولدة في Git.

التوليد محدد المعدل ومحمٍ بقفل يمنع الرندرات المتزامنة. استعد المهام التي انقطعت:

```bash
cd laravel-app
php artisan reels:recover-stale
```

للنشر الأكبر، انقل الرندر إلى Queue Worker محدود بدلاً من تشغيله داخل طلب الويب. يدعم Laravel PostgreSQL وRedis مباشرة؛ غيّر `DB_CONNECTION=pgsql` و`QUEUE_CONNECTION=redis` في `.env` بعد تجهيز الخدمتين. توجد أمثلة جاهزة في `deploy/supervisor/aivideo.conf.example` و`deploy/nginx/aivideo.conf.example`.

قبل النشر نفّذ:

```bash
./scripts/deploy-check.sh
```

يتحقق السكربت من الاعتماديات، يشغل migrations و`studio:doctor`، يبني أصول الواجهة، ويرفض وضع production إذا كان debug مفعّلًا.

## Fresh installation (manual)

```bash
# macOS/Linux: PHP 8.3+ with mbstring, PDO SQLite, and fileinfo
cd laravel-app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm ci
npm run build
cd ..

# Python 3.11+ and FFmpeg
python3 -m venv venv
./venv/bin/pip install -r requirements.lock.txt
cd laravel-app && npm run build && cd ..
```

في Windows استبدل أوامر Python السابقة بـ:

```powershell
py -3 -m venv venv
.\venv\Scripts\python.exe -m pip install -r requirements.lock.txt
```

يكتشف Laravel المسار المناسب تلقائياً من نظام التشغيل، ويمكن تجاوزه عبر
`AIVIDEO_PYTHON_BIN`. يعتمد المحرك على `imageio-ffmpeg` من ملف المتطلبات؛ إذا كان
التثبيت المحلي يحتاج FFmpeg نظامياً، ثبته وأضفه إلى `PATH` (على macOS عبر Homebrew،
وعلى Windows عبر توزيعة FFmpeg موثوقة).

المحرك يحمل خط **Noto Kufi Arabic** المرخّص (OFL) داخل `python_engine/fonts/` ويستخدمه افتراضياً،
لذا لا يحتاج خادم Linux إلى تثبيت خط إضافي. لتغيير الخط:

```env
# يجب أن يدعم الخط Arabic Presentation Forms وإلا سيرجع المحرك للخط المضمّن.
AIVIDEO_ARABIC_FONT=/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf
```

يتحقق المحرك من دعم الخط للعربية قبل استخدامه، ويفشل برسالة واضحة عند غياب أي خط صالح.

## Production cache

```bash
cd laravel-app
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
npm ci && npm run build
```

الواجهات تستخدم أصول Vite المحلية (Tailwind + الخطوط + الأيقونات)، ولا تعتمد على أي CDN.
يجب إعادة `npm run build` بعد أي تعديل على الواجهة.

## Maintenance commands

```bash
php artisan studio:doctor                 # preflight checks
php artisan reels:recover-stale           # mark interrupted renders as failed
php artisan reels:prune --days=30         # delete old failed renders and orphan media
php artisan reels:prune --days=30 --dry-run
```

## Backup and restore

أنشئ نسخة متسقة أثناء وضع الصيانة:

```bash
./scripts/backup.sh /secure/backups/aivideo-$(date +%Y%m%d)
```

واستعد بعد إيقاف المرور والتحقق من النسخة:

```bash
./scripts/restore.sh /secure/backups/aivideo-YYYYMMDD
```

تشمل النسخة `database.sqlite` وملفات الفيديو والصوت المدخلة ومقاطع reels المولدة والصور المصغرة والترجمات والمعاينات. لا يضع السكربت `APP_KEY` داخل الأرشيف؛ خزنه منفصلاً بشكل آمن، لأن فقدانه قد يمنع فك البيانات المشفرة.

## CI and dependency reproducibility

يشغّل GitHub Actions اختبارات Laravel وPython وPint وبناء أصول الواجهة عند كل push وPull Request.
التزم بملفات `composer.lock` و`requirements.lock.txt` ولا تستخدم تثبيتاً عائماً في الإنتاج.

## Security notes

- المفاتيح في `.env` محلية فقط. دوّرها قبل مشاركة المشروع أو نشره، وانقلها إلى
  شاشة «إدارة المفاتيح» حيث تُخزَّن مشفّرة بـ`APP_KEY`.
- `APP_DEBUG=true` و`STUDIO_AUTH_ENABLED=false` مناسبان للتشغيل المحلي فقط.
- أضيفت رؤوس أمان افتراضية (CSP، `X-Frame-Options`، `nosniff`، Referrer-Policy) عبر
  `SecurityHeaders`، مع تنقيح مفاتيح `AQ.` في سجلات الرندر.
