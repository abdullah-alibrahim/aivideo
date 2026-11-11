@echo off
chcp 65001 >nul
setlocal

echo ==================================================
echo 🚀 AI Reel Studio - Windows Local Setup & Launcher
echo ==================================================

:: Check if PHP is installed
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo [خطأ] PHP غير مثبت أو غير موجود في مسار النظام (Environment Variables).
    echo يرجى تثبيت PHP 8.3 أو أحدث وإضافته إلى PATH.
    pause
    exit /b 1
)

:: Check if Python is installed
where python >nul 2>&1
if %errorlevel% neq 0 (
    echo [خطأ] Python غير مثبت أو غير موجود في مسار النظام (Environment Variables).
    echo يرجى تثبيت Python 3.11 أو أحدث وإضافته إلى PATH.
    pause
    exit /b 1
)

:: Check if Composer is installed
where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo [تحذير] Composer غير موجود في PATH. سنحاول البحث عنه أو يرجى تثبيته.
)

echo [1/5] التحقق من بيئة البايثون (Python Virtual Environment)...
if not exist "venv" (
    echo جاري إنشاء بيئة افتراضية جديدة للبايثون...
    python -m venv venv
    call venv\Scripts\python -m pip install --upgrade pip
    call venv\Scripts\pip install -r requirements.lock.txt
) else (
    echo بيئة البايثون موجودة مسبقاً.
)

echo [2/5] التحقق من حزم Laravel وتجهيز قاعدة البيانات...
cd laravel-app
if not exist "vendor" (
    echo جاري تثبيت حزم PHP عبر Composer...
    composer install --no-dev --optimize-autoloader
)

if not exist ".env" (
    echo إنشاء ملف الإعدادات .env...
    copy .env.example .env
    php artisan key:generate
)

php artisan migrate --force

echo [3/5] التحقق من بناء الواجهة (Frontend Assets)...
if not exist "public\build\manifest.json" (
    echo جاري تثبيت وبناء الأصول عبر NPM...
    where npm >nul 2>&1
    if %errorlevel% neq 0 (
        echo [خطأ] Node.js / NPM غير مثبت. يرجى تثبيته لتوليد الواجهة.
        cd ..
        pause
        exit /b 1
    )
    call npm install --no-audit --no-fund
    call npm run build
)

php artisan storage:link >nul 2>&1

echo [4/5] تشغيل طابور المعالجة (Queue Worker) في خلفية النظام...
start /b php artisan queue:work --tries=2 --timeout=1200 --sleep=1 > ..\storage_worker.log 2>&1

echo [5/5] تشغيل خادم التطبيق المحلي...
echo ==================================================
echo ✅ المنصة تعمل الآن محلياً!
echo 🌐 افتح الرابط التالي في المتصفح: http://127.0.0.1:8000
echo لإيقاف السيرفر، اغلق هذه النافذة أو اضغط Ctrl+C.
echo ==================================================

php artisan serve --host=127.0.0.1 --port=8000
cd ..
endlocal
