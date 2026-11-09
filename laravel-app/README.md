# AI Reels Studio

هذا المجلد يحتوي تطبيق Laravel لواجهة AI Reels Studio. التطبيق يدير الـ Wizard، مفاتيح مزودي الذكاء الاصطناعي، طابور الرندر، مكتبة الريلز، والبث الجزئي للفيديو. محرك الرندر موجود في `../python_engine/` ويستخدم Python وMoviePy وFFmpeg.

## المتطلبات

- PHP 8.3 أو أحدث مع `mbstring` و`pdo_sqlite` و`fileinfo`.
- Composer.
- Node.js 22 أو أحدث وnpm.
- Python 3.11 أو أحدث وFFmpeg أو `imageio-ffmpeg`.
- مفتاح Gemini للتوليد المدعوم بالذكاء الاصطناعي. يمكن تشغيل الوضع اليدوي دون Gemini.
- مفتاح ElevenLabs وVoice ID اختياريان للتعليق الصوتي.

## التثبيت المحلي

من جذر المشروع:

```bash
composer install --working-dir=laravel-app
cp laravel-app/.env.example laravel-app/.env
php laravel-app/artisan key:generate
php laravel-app/artisan migrate --force
npm ci --prefix laravel-app
npm run build --prefix laravel-app
python3 -m venv venv
./venv/bin/pip install -r requirements.lock.txt
```

ضع مفاتيح المزودين في شاشة Provider Key Manager أو في `.env` المحلي. لا تحفظ `.env` أو مفاتيح API في Git.

## التشغيل

```bash
./start.sh
```

يفتح التطبيق على `http://127.0.0.1:8000` ويشغل Laravel وQueue worker وScheduler. طلب إنشاء الريل يعيد الحالة `queued` فورًا، ثم يتابع العامل الرندر ويحدث التقدم.

للتأكد من البيئة:

```bash
cd laravel-app
php artisan studio:doctor
```

يختبر الأمر PHP، قاعدة البيانات، Python، FFmpeg، الخط العربي، صلاحيات التخزين، الطابور، مساحة القرص، ومؤشرات أمان الإنتاج.

## أوامر الصيانة

```bash
php artisan reels:recover-stale
php artisan reels:prune --days=30
php artisan reels:prune --days=30 --dry-run
php artisan config:clear
```

`reels:prune` يحذف الرندرات الفاشلة القديمة والملفات اليتيمة مع الحفاظ على الملفات المشار إليها من قاعدة البيانات.

## الإعدادات المهمة

```env
APP_ENV=local
APP_DEBUG=true
QUEUE_CONNECTION=database
STUDIO_MAX_VIDEO_MB=100
STUDIO_MAX_MUSIC_MB=20
STUDIO_MAX_CONCURRENT_RENDERS=1
STUDIO_RENDER_TIMEOUT_SECONDS=900
```

`STUDIO_MAX_CONCURRENT_RENDERS` يحدد عدد أقفال الرندر المتاحة فعليًا. القيمة الافتراضية `1` تمنع الضغط على جهاز محلي واحد.

## قبل التعريض للشبكة

استخدم HTTPS، وغيّر الإعدادات التالية على الأقل:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
STUDIO_AUTH_ENABLED=true
STUDIO_AUTH_USERNAME=admin
STUDIO_AUTH_PASSWORD=use-a-long-random-password
```

شغّل `studio:doctor` بعد ضبطها. في الإنتاج لا تعتمد على `start.sh` لإدارة الخدمات؛ استخدم Supervisor أو systemd لعامل Queue وScheduler، واستبدل SQLite وdatabase queue بـ PostgreSQL/MySQL وRedis عند زيادة عدد المستخدمين أو حجم الرندر.

## التخزين والنسخ الاحتياطي

تُخزّن الملفات المدخلة في `storage/app/input_videos` و`storage/app/bg_music`، بينما تُخزّن المخرجات والصور المصغرة والترجمات في التخزين العام. أنشئ نسخة متسقة أثناء وضع الصيانة:

```bash
../scripts/backup.sh /secure/backups/aivideo-$(date +%Y%m%d)
../scripts/restore.sh /secure/backups/aivideo-YYYYMMDD
```

تشمل النسخة قاعدة البيانات، الملفات المدخلة، الريلز، الصور المصغرة، الترجمات، والمعاينات عند وجودها. لا تشمل `APP_KEY`؛ خزّنه منفصلًا في مدير أسرار.

## الاختبارات

```bash
php artisan test --compact
../venv/bin/python3 -m unittest ../python_engine/test_smart_reels.py -v
vendor/bin/pint --dirty --format agent
npm run build
```

تغطي اختبارات Laravel مسارات الرفع والتوليد والطابور والمصادقة والبث والحذف، بينما تغطي اختبارات Python حركات النص، RTL، الصوت، الانتقالات، البطاقات، Gemini، والملفات الناتجة.

## حدود المنتج

المحرك يركّب الفيديو أو الخلفيات مع بطاقات نص وحركة وصوت اختياري؛ لا يولد لقطات فيديو جديدة من وصف نصي ولا يحسب انتشارًا حقيقيًا. نتائج Gemini تحتاج مراجعة بشرية قبل النشر، خصوصًا للمحتوى المالي أو الطبي.
