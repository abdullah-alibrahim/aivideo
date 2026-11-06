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
├── start.sh                   # سكربت تشغيل المنصة
└── README.md
```

## التشغيل السريع

```bash
chmod +x start.sh
./start.sh
```

ثم افتح `http://127.0.0.1:8000`.

يشغّل `start.sh` خادم Laravel وعامل طابور (`queue:work`) معاً. الرندر لا يحدث داخل طلب الويب:
يُعاد الرد فوراً بحالة `queued`، ويتابع عامل الطابور العمل ويعرض تقدّماً حقيقياً في الواجهة.

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
STUDIO_STALE_AFTER_MINUTES=30
```

عند التفعيل تحمي Basic Authentication إدارة المفاتيح وواجهات AI والتوليد والسجلات والحذف وبث الفيديو. احفظ `APP_KEY` ومفاتيح المزود وكلمة المرور في مدير أسرار أو مخزن مشفر، ولا تضع `.env` أو النسخ الاحتياطية أو الوسائط المولدة في Git.

التوليد محدد المعدل ومحمٍ بقفل يمنع الرندرات المتزامنة. استعد المهام التي انقطعت:

```bash
cd laravel-app
php artisan reels:recover-stale
```

للنشر الأكبر، انقل الرندر إلى Queue Worker محدود بدلاً من تشغيله داخل طلب الويب.

## Fresh installation

```bash
# PHP 8.3+ with mbstring, PDO SQLite, and fileinfo
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

المتحكم يتوقع المحرك في `../venv/bin/python3`. ثبت FFmpeg وتحقق من توفره لـ MoviePy.

للعربية على Linux ثبت خطاً مرخصاً مثل Noto Sans Arabic واضبط:

```env
AIVIDEO_ARABIC_FONT=/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf
```

يفشل المحرك برسالة واضحة عند غياب خط عربي صالح بدلاً من استخدام خط Pillow الصغير.

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

تشمل النسخة `database.sqlite` وملفات الفيديو والصوت المدخلة ومقاطع reels المولدة. لا يضع السكربت `APP_KEY` داخل الأرشيف؛ خزنه منفصلاً بشكل آمن، لأن فقدانه قد يمنع فك البيانات المشفرة.

## CI and dependency reproducibility

يشغّل GitHub Actions اختبارات Laravel وPython وPint وبناء أصول الواجهة عند كل push وPull Request.
التزم بملفات `composer.lock` و`requirements.lock.txt` ولا تستخدم تثبيتاً عائماً في الإنتاج.

## Security notes

- المفاتيح في `.env` محلية فقط. دوّرها قبل مشاركة المشروع أو نشره، وانقلها إلى
  شاشة «إدارة المفاتيح» حيث تُخزَّن مشفّرة بـ`APP_KEY`.
- `APP_DEBUG=true` و`STUDIO_AUTH_ENABLED=false` مناسبان للتشغيل المحلي فقط.
- أضيفت رؤوس أمان افتراضية (CSP، `X-Frame-Options`، `nosniff`، Referrer-Policy) عبر
  `SecurityHeaders`، مع تنقيح مفاتيح `AQ.` في سجلات الرندر.
